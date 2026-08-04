<?php
declare(strict_types=1);

/**
 * Growth Agent — Fase 2 instrumentation schema + logging helper.
 *
 * Four tables, self-created on first use via cms_ensure_table() (same lazy
 * pattern as sitemap-service.php's cms_sitemap_ensure_schema()):
 *
 *   growth_agent_jobs        one row per generation attempt (manual click
 *                            today, scheduled Growth Agent runs later).
 *                            Statuses drive the stat cards on
 *                            pages/growth-agent.php: ready, running,
 *                            succeeded, failed, manual_action.
 *   growth_agent_feedback    human approve/edit/reject signal against a
 *                            job — this is what lets a past job be reused
 *                            as a few-shot example (see
 *                            services/GrowthAgentPromptBuilder.php).
 *   growth_agent_style_rules living style guide, manually curated for now
 *                            (source='auto_extracted' is reserved for a
 *                            later phase — nothing writes it yet).
 *   growth_agent_performance traffic/ranking signal per page. Schema only
 *                            — nothing ingests into it yet, since there's
 *                            no GA/Search Console integration in this repo.
 *                            Kept here so the column shape is settled
 *                            ahead of that follow-up work.
 *
 * No FK constraints, matching this codebase's existing convention
 * (article_tag_map etc. use plain indexed columns, not CONSTRAINT ...
 * FOREIGN KEY) — app-level integrity, not DB-enforced.
 */
function cms_growth_agent_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'growth_agent_jobs',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         job_type VARCHAR(50) NOT NULL COMMENT 'e.g. seo_meta, article_draft',
         agent_key VARCHAR(50) NOT NULL COMMENT 'matches ai_agent_settings.agent_key',
         page_id INT UNSIGNED DEFAULT NULL COMMENT 'pages.page_id — null if not saved yet',
         status ENUM('ready','running','succeeded','failed','manual_action') NOT NULL DEFAULT 'running',
         input_brief TEXT DEFAULT NULL COMMENT 'JSON snapshot of what was sent to the agent',
         output_json TEXT DEFAULT NULL COMMENT 'JSON snapshot of the parsed result',
         model_used VARCHAR(100) DEFAULT NULL,
         tokens_in INT UNSIGNED DEFAULT NULL,
         tokens_out INT UNSIGNED DEFAULT NULL,
         latency_ms INT UNSIGNED DEFAULT NULL,
         error_message TEXT DEFAULT NULL,
         created_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id, null = system',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         KEY idx_gaj_status (status),
         KEY idx_gaj_page (page_id),
         KEY idx_gaj_agent_key (agent_key)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_feedback',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         job_id INT UNSIGNED NOT NULL,
         action ENUM('approved_as_is','approved_with_edits','rejected') NOT NULL,
         notes TEXT DEFAULT NULL,
         reviewed_by INT UNSIGNED DEFAULT NULL COMMENT 'admins.admin_id',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_gaf_job (job_id)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_style_rules',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         rule_text TEXT NOT NULL,
         source ENUM('manual','auto_extracted') NOT NULL DEFAULT 'manual',
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         created_by INT UNSIGNED DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_gasr_active (is_active)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_performance',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         page_id INT UNSIGNED NOT NULL,
         metric_date DATE NOT NULL,
         pageviews INT UNSIGNED NOT NULL DEFAULT 0,
         impressions INT UNSIGNED NOT NULL DEFAULT 0,
         avg_ranking_position DECIMAL(6,2) DEFAULT NULL,
         clicks INT UNSIGNED NOT NULL DEFAULT 0,
         ctr DECIMAL(6,4) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_gap_page_date (page_id, metric_date)"
    );
    // impressions (28 Jul 2026, Feedback Loop gap #4) — the original
    // schema-only table predates any real writer and never had impressions,
    // needed both to compute CTR properly here and to weight avg_ranking_position
    // by impressions when combining multiple queries for the same page/day
    // (see cms_growth_agent_snapshot_performance()). Additive column, safe
    // on any pre-existing installs of this table.
    cms_ensure_column($pdo, 'growth_agent_performance', 'impressions', 'INT UNSIGNED NOT NULL DEFAULT 0 AFTER `pageviews`');

    // Agent Memory (ROADMAP.md gap #3, GROWTH_AGENT_SEO_ROADMAP.md §
    // Growth memory, closed 28 Jul 2026) — completes the half-finished
    // port noted in gsc-api.php ("out of scope for this port"):
    // gsc_settings.memory_thresholds_json/last_memory_detection_at and
    // cms_gsc_default_memory_thresholds()/cms_gsc_get_memory_thresholds()
    // already existed unused; this table is what they were always meant
    // to feed. dedupe_key (same md5-hash convention as
    // gsc_opportunities.dedupe_key) rather than a UNIQUE key across the
    // nullable matched_page_id/query_text columns directly — MySQL never
    // enforces uniqueness across a combination where a column is NULL in
    // both rows being compared, which would silently defeat dedup for the
    // query-scope rows (matched_page_id always NULL there).
    // ADVISORY ONLY: nothing in this table is ever read by anything that
    // creates/approves/executes a growth_agent_jobs row — only
    // GrowthAgentPromptBuilder::buildMemoryContext() reads 'active' rows,
    // and only to add plain text to a prompt. See
    // cms_growth_agent_detect_memory_patterns() for the (deterministic,
    // no AI) detection logic and cms_growth_agent_mark_memory_stale() for
    // the one manual action this feature has (there is no approve/execute
    // — memory is not an action queue).
    cms_ensure_table(
        $pdo,
        'growth_agent_memory',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         pattern_type ENUM('winning_pattern','content_gap') NOT NULL,
         scope_type ENUM('page','query') NOT NULL,
         matched_page_id INT UNSIGNED DEFAULT NULL,
         query_text VARCHAR(255) DEFAULT NULL,
         status ENUM('pending_review','active','stale') NOT NULL DEFAULT 'pending_review',
         evidence_json TEXT DEFAULT NULL,
         distinct_weeks_seen INT UNSIGNED NOT NULL DEFAULT 0,
         dedupe_key CHAR(32) NOT NULL,
         first_detected_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         last_confirmed_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_gam_dedupe (dedupe_key),
         KEY idx_gam_status (status),
         KEY idx_gam_page (matched_page_id)"
    );

    cms_growth_agent_ensure_legacy_status($pdo);
}

/**
 * Widens growth_agent_jobs.status and growth_agent_feedback.action to add
 * 'closed_as_legacy' (27 Jul 2026, "Close as Legacy" review action) —
 * distinct from 'rejected'/'failed' (which mean "this was bad") and from
 * 'approved_as_is'/'succeeded' (which mean "this was good"): legacy means
 * neither judgment applies, the underlying signal (e.g. stale GSC data) is
 * just no longer relevant. cms_ensure_column() can't widen an existing
 * ENUM, only add a missing column — so this checks the live column
 * definition via information_schema first and only ALTERs when the new
 * value isn't already in it, mirroring the exact same widen-safe pattern
 * this project's sibling codebase used for its own 2-tier->3-tier
 * priority migration.
 */
function cms_growth_agent_ensure_legacy_status(PDO $pdo): void
{
    $statusType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_agent_jobs' AND COLUMN_NAME = 'status'"
    )->fetchColumn();
    if ($statusType !== '' && !str_contains($statusType, "'closed_as_legacy'")) {
        $pdo->exec("ALTER TABLE `growth_agent_jobs` MODIFY COLUMN `status` ENUM('ready','running','succeeded','failed','manual_action','closed_as_legacy') NOT NULL DEFAULT 'running'");
    }

    $actionType = (string) $pdo->query(
        "SELECT COLUMN_TYPE FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'growth_agent_feedback' AND COLUMN_NAME = 'action'"
    )->fetchColumn();
    if ($actionType !== '' && !str_contains($actionType, "'closed_as_legacy'")) {
        $pdo->exec("ALTER TABLE `growth_agent_feedback` MODIFY COLUMN `action` ENUM('approved_as_is','approved_with_edits','rejected','closed_as_legacy') NOT NULL");
    }
}

/**
 * SEO Intelligence (Topic Cluster + Content Conflict Detection) — separate
 * lazy schema from cms_growth_agent_ensure_schema(), same
 * cms_ensure_table() pattern, called explicitly from
 * pages/seo-intelligence.php and pages/content-conflict-detection.php
 * rather than folded into the main schema function, since this feature is
 * its own self-contained addition.
 *
 * Both tables are full-recompute, not incremental: every "Generate" click
 * deletes all existing rows for that table and inserts a fresh batch (see
 * cms_growth_agent_generate_topic_clusters() /
 * cms_growth_agent_generate_content_conflicts()) — same spirit as the
 * existing "Hitung Ulang Opportunities" recompute.
 */
function cms_growth_agent_seo_intel_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'growth_agent_topic_clusters',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         cluster_name VARCHAR(255) NOT NULL,
         pillar_page_id INT UNSIGNED DEFAULT NULL COMMENT 'pages.page_id, app-level FK',
         supporting_page_ids TEXT NOT NULL COMMENT 'JSON array of page_id',
         status ENUM('needs_more_content','good_coverage') NOT NULL DEFAULT 'needs_more_content',
         missing_content_json TEXT DEFAULT NULL COMMENT 'JSON array of {topic: string}',
         generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         model_used VARCHAR(100) DEFAULT NULL,
         KEY idx_gatc_status (status)"
    );

    cms_ensure_table(
        $pdo,
        'growth_agent_content_conflicts',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         page_a_id INT UNSIGNED NOT NULL,
         page_b_id INT UNSIGNED NOT NULL,
         risk ENUM('low','medium','high') NOT NULL DEFAULT 'low',
         issue_text TEXT NOT NULL,
         recommendation_text TEXT NOT NULL,
         status ENUM('open','proposal_requested','dismissed') NOT NULL DEFAULT 'open',
         generated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         model_used VARCHAR(100) DEFAULT NULL,
         KEY idx_gacc_status (status)"
    );
}

/**
 * Resolves a list of AI-provided slugs back to page_id, using ONLY the
 * slug=>page_id map built from the exact candidate list sent in the prompt
 * — never trusts a page_id the AI might return directly, since that's an
 * easy hallucination surface. Unknown slugs are silently dropped.
 *
 * @param array<string, int> $slugToPageId
 * @param list<mixed> $slugs
 * @return list<int>
 */
function cms_growth_agent_seo_intel_resolve_slugs(array $slugToPageId, array $slugs): array
{
    $resolved = [];
    foreach ($slugs as $slug) {
        $slug = trim((string) $slug);
        if ($slug !== '' && isset($slugToPageId[$slug])) {
            $resolved[] = $slugToPageId[$slug];
        }
    }
    return $resolved;
}

/**
 * Topic Cluster generation — full recompute triggered by the "Generate
 * Cluster" button on pages/seo-intelligence.php. Sends title + slug +
 * meta_description (falling back to excerpt) for the 50 most recent
 * published articles — NOT full content, too expensive for 50 articles in
 * one call — and asks the AI to group them into topic clusters, pick a
 * pillar per cluster, flag clusters that need more supporting content, and
 * suggest missing subtopics.
 *
 * Same "generate + log" pattern as cms_growth_agent_generate_content_optimization()
 * (cms_ai_resolve_agent + cms_ai_call_provider + cms_ai_extract_json), just
 * writing into growth_agent_topic_clusters instead of growth_agent_jobs —
 * this is a data table, not an action queue, since clustering itself isn't
 * something to approve/reject.
 *
 * On parse/AI failure, existing rows are left untouched so the UI keeps
 * showing the last successful generate instead of going blank.
 *
 * @return array{ok:bool, clusters_created:int, error:string}
 */
function cms_growth_agent_generate_topic_clusters(PDO $pdo): array
{
    try {
        cms_growth_agent_seo_intel_ensure_schema($pdo);
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    try {
        $stmt = $pdo->query(
            "SELECT page_id, title, slug, meta_description, excerpt
               FROM pages
              WHERE status = 'published'
              ORDER BY created_at DESC
              LIMIT 50"
        );
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    if ($pages === []) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => 'Tidak ada artikel published untuk dianalisis.'];
    }

    $slugToPageId = [];
    $promptLines = [];
    foreach ($pages as $page) {
        $slug = (string) $page['slug'];
        $slugToPageId[$slug] = (int) $page['page_id'];
        $desc = trim((string) ($page['meta_description'] ?? ''));
        if ($desc === '') {
            $desc = trim((string) ($page['excerpt'] ?? ''));
        }
        $promptLines[] = "- slug: {$slug} | title: {$page['title']} | description: {$desc}";
    }

    $defaultSystemPrompt =
        'You are the Growth Agent SEO strategist for Sagagoal, a livescore & sports news website. ' .
        'You are given a list of published articles (slug, title, short description). Group them into ' .
        'topic clusters based on topical similarity / shared search intent. For each cluster, pick the ' .
        'single most comprehensive/representative article as the "pillar" and the rest as "supporting". ' .
        'Mark a cluster status "needs_more_content" if it has fewer than 3 supporting articles, otherwise ' .
        '"good_coverage". For every "needs_more_content" cluster, suggest 3-5 specific subtopics not yet ' .
        'covered by any article in that cluster. Only reference slugs from the given list — never invent a ' .
        'slug. Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
        'this shape: {"clusters": [{"cluster_name": "...", "pillar_slug": "...", ' .
        '"supporting_slugs": ["...", "..."], "status": "needs_more_content", "missing_topics": ["...", "..."]}]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $agent['error']];
    }

    $userPrompt = "Articles (max 50, most recent published first):\n" . implode("\n", $promptLines);

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $agent['system_prompt'], max($agent['max_tokens'], 1500), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
    if (!$result['success'] || !is_array($parsed) || !is_array($parsed['clusters'] ?? null)) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        return ['ok' => false, 'clusters_created' => 0, 'error' => $errorMessage];
    }

    $rows = [];
    foreach ($parsed['clusters'] as $cluster) {
        if (!is_array($cluster)) {
            continue;
        }
        $clusterName = trim((string) ($cluster['cluster_name'] ?? ''));
        if ($clusterName === '') {
            continue;
        }
        $pillarSlug = trim((string) ($cluster['pillar_slug'] ?? ''));
        $pillarPageId = $pillarSlug !== '' && isset($slugToPageId[$pillarSlug]) ? $slugToPageId[$pillarSlug] : null;
        $supportingIds = cms_growth_agent_seo_intel_resolve_slugs($slugToPageId, is_array($cluster['supporting_slugs'] ?? null) ? $cluster['supporting_slugs'] : []);
        $status = (string) ($cluster['status'] ?? 'needs_more_content');
        $status = in_array($status, ['needs_more_content', 'good_coverage'], true) ? $status : 'needs_more_content';
        $missingTopics = [];
        foreach (is_array($cluster['missing_topics'] ?? null) ? $cluster['missing_topics'] : [] as $topic) {
            $topic = trim((string) $topic);
            if ($topic !== '') {
                $missingTopics[] = ['topic' => $topic];
            }
        }

        $rows[] = [
            'cluster_name' => $clusterName,
            'pillar_page_id' => $pillarPageId,
            'supporting_page_ids' => json_encode($supportingIds, JSON_UNESCAPED_UNICODE),
            'status' => $status,
            'missing_content_json' => $missingTopics !== [] ? json_encode($missingTopics, JSON_UNESCAPED_UNICODE) : null,
            'model_used' => $agent['model'],
        ];
    }

    if ($rows === []) {
        return ['ok' => false, 'clusters_created' => 0, 'error' => 'AI tidak menghasilkan cluster yang valid.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM growth_agent_topic_clusters');
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_topic_clusters
                (cluster_name, pillar_page_id, supporting_page_ids, status, missing_content_json, generated_at, model_used)
             VALUES
                (:cluster_name, :pillar_page_id, :supporting_page_ids, :status, :missing_content_json, NOW(), :model_used)'
        );
        foreach ($rows as $row) {
            $ins->execute($row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'clusters_created' => 0, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'clusters_created' => count($rows), 'error' => ''];
}

/**
 * Content Conflict Detection — full recompute triggered by the "Generate"
 * button on pages/content-conflict-detection.php. Same 50-article
 * title+description candidate set as
 * cms_growth_agent_generate_topic_clusters() (kept identical on purpose so
 * both features are analyzing the same snapshot), asks the AI to find
 * PAIRS of articles whose search intent is too similar / at risk of
 * cannibalizing each other.
 *
 * This is a distinct, AI-driven sibling to
 * cms_growth_agent_log_cannibalization_review() — that one is pure SQL
 * against real GSC click/impression data (a query IS already splitting
 * traffic across pages), this one is a content-similarity heuristic over
 * article metadata (a query MIGHT end up splitting traffic once both
 * articles rank). Different evidence, different table, both still land on
 * the same "Recommendation only" guardrail: neither ever merges/redirects
 * anything automatically.
 *
 * @return array{ok:bool, conflicts_created:int, error:string}
 */
function cms_growth_agent_generate_content_conflicts(PDO $pdo): array
{
    try {
        cms_growth_agent_seo_intel_ensure_schema($pdo);
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    try {
        $stmt = $pdo->query(
            "SELECT page_id, title, slug, meta_description, excerpt
               FROM pages
              WHERE status = 'published'
              ORDER BY created_at DESC
              LIMIT 50"
        );
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    if ($pages === []) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => 'Tidak ada artikel published untuk dianalisis.'];
    }

    $slugToPageId = [];
    $promptLines = [];
    foreach ($pages as $page) {
        $slug = (string) $page['slug'];
        $slugToPageId[$slug] = (int) $page['page_id'];
        $desc = trim((string) ($page['meta_description'] ?? ''));
        if ($desc === '') {
            $desc = trim((string) ($page['excerpt'] ?? ''));
        }
        $promptLines[] = "- slug: {$slug} | title: {$page['title']} | description: {$desc}";
    }

    $defaultSystemPrompt =
        'You are the Growth Agent SEO strategist for Sagagoal, a livescore & sports news website. ' .
        'You are given a list of published articles (slug, title, short description). Find PAIRS of ' .
        'articles whose search intent is too similar and at risk of cannibalizing each other in Google ' .
        'Search (competing for the same queries). For each pair, give a risk level (low/medium/high), a ' .
        'short issue description, and a free-text recommendation (e.g. differentiate intent, merge ' .
        'candidate, distinguish angle). Only reference slugs from the given list — never invent a slug. ' .
        'Only report pairs with a real, specific overlap — do not pad the list. Respond with ONLY a raw ' .
        'JSON object, no markdown, no code fences, no commentary, in exactly this shape: ' .
        '{"conflicts": [{"slug_a": "...", "slug_b": "...", "risk": "low", "issue": "...", "recommendation": "..."}]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $agent['error']];
    }

    $userPrompt = "Articles (max 50, most recent published first):\n" . implode("\n", $promptLines);

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $agent['system_prompt'], max($agent['max_tokens'], 1500), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
    if (!$result['success'] || !is_array($parsed) || !is_array($parsed['conflicts'] ?? null)) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $errorMessage];
    }

    $rows = [];
    foreach ($parsed['conflicts'] as $conflict) {
        if (!is_array($conflict)) {
            continue;
        }
        $slugA = trim((string) ($conflict['slug_a'] ?? ''));
        $slugB = trim((string) ($conflict['slug_b'] ?? ''));
        if ($slugA === '' || $slugB === '' || !isset($slugToPageId[$slugA], $slugToPageId[$slugB])) {
            continue;
        }
        $pageAId = $slugToPageId[$slugA];
        $pageBId = $slugToPageId[$slugB];
        if ($pageAId === $pageBId) {
            continue;
        }
        $issue = trim((string) ($conflict['issue'] ?? ''));
        $recommendation = trim((string) ($conflict['recommendation'] ?? ''));
        if ($issue === '' || $recommendation === '') {
            continue;
        }
        $risk = (string) ($conflict['risk'] ?? 'low');
        $risk = in_array($risk, ['low', 'medium', 'high'], true) ? $risk : 'low';

        $rows[] = [
            'page_a_id' => $pageAId,
            'page_b_id' => $pageBId,
            'risk' => $risk,
            'issue_text' => $issue,
            'recommendation_text' => $recommendation,
            'model_used' => $agent['model'],
        ];
    }

    if ($rows === []) {
        return ['ok' => false, 'conflicts_created' => 0, 'error' => 'AI tidak menemukan konflik konten yang valid.'];
    }

    try {
        $pdo->beginTransaction();
        $pdo->exec('DELETE FROM growth_agent_content_conflicts');
        $ins = $pdo->prepare(
            'INSERT INTO growth_agent_content_conflicts
                (page_a_id, page_b_id, risk, issue_text, recommendation_text, status, generated_at, model_used)
             VALUES
                (:page_a_id, :page_b_id, :risk, :issue_text, :recommendation_text, \'open\', NOW(), :model_used)'
        );
        foreach ($rows as $row) {
            $ins->execute($row);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'conflicts_created' => 0, 'error' => $e->getMessage()];
    }

    return ['ok' => true, 'conflicts_created' => count($rows), 'error' => ''];
}

/**
 * ── SEO-G0 Gate (GROWTH_AGENT_V2_PROPOSAL.md Fase A item 3, 4 Agu 2026) ──
 *
 * Deterministic topic-overlap pre-check run before either of the two
 * new-article proposal paths logs its job: cms_growth_agent_generate_
 * article_idea() (job_type 'gsc_article_idea', triggered from the
 * "Peluang Terprioritas" panel on growth-agent.php) and
 * cms_growth_agent_request_topic_gap_article() (job_type
 * 'topic_gap_article', triggered from seo-intelligence.php). Both call
 * cms_growth_agent_seo_g0_gate() below and merge its result into their own
 * input_brief under the 'seo_g0_gate' key — no new table/column, per the
 * doc's § 1b "Action Queue is the only queue" rule.
 *
 * Design decisions locked in by the proposal doc, not to be changed here:
 *   - Advisory only, never blocking. The proposal is ALWAYS created
 *     regardless of what the gate finds; a warning just rides along on
 *     the same row for the operator to see during their normal
 *     approve/reject review. No separate override control — there is
 *     nothing to override since nothing is blocked.
 *   - Deterministic only. All three checks are plain SQL + string/token
 *     comparison, never an AI call — same "must be consistent and
 *     auditable, not vary run to run" reasoning as the Opportunity Engine.
 *   - Never throws. A gate failure must never prevent the proposal it's
 *     attached to from being created (see cms_growth_agent_seo_g0_gate()'s
 *     own docblock for how each sub-check degrades independently).
 */

/**
 * Tokenizer + stopword filter for the gate's topic-similarity checks — not
 * a general NLP tool, tuned specifically for short Indonesian sports-news
 * phrases (GSC queries, article titles, topic-gap labels). Strips
 * punctuation, lowercases, and removes both standard Indonesian function
 * words AND terms that are generic on THIS site specifically ("jadwal",
 * "hasil", "live", "streaming", "vs", "skor", "berita", ...). The
 * sports-generic half matters as much as the stopword half: without it,
 * almost every headline on a livescore site shares 3-4 of those words
 * regardless of actual topic, which would flag nearly any two articles as
 * "similar" and train operators to ignore the warning entirely.
 *
 * @return string[] unique, order-independent token set (empty array if
 *                   nothing meaningful survives filtering)
 */
function cms_growth_agent_g0_tokenize(string $text): array
{
    static $stopwords = null;
    if ($stopwords === null) {
        $stopwords = array_flip([
            // Indonesian function/structural words.
            'yang', 'dan', 'di', 'ke', 'dari', 'untuk', 'dengan', 'pada', 'itu', 'ini', 'akan', 'atau',
            'juga', 'saja', 'adalah', 'sudah', 'belum', 'tidak', 'ada', 'dalam', 'oleh', 'para', 'bisa',
            'dapat', 'tersebut', 'seperti', 'karena', 'jika', 'kalau', 'saat', 'ketika', 'setelah',
            'sebelum', 'terhadap', 'antara', 'hingga', 'sampai', 'lebih', 'sangat', 'banyak', 'semua',
            'satu', 'dua', 'tiga', 'empat', 'lima', 'apa', 'siapa', 'kapan', 'dimana', 'mengapa',
            'bagaimana', 'tanpa', 'atas', 'bawah', 'antar', 'per', 'nya', 'pun', 'tak', 'gak', 'ga',
            'yg', 'dgn', 'utk', 'dr', 'pd', 'si', 'sang', 'tentang', 'soal', 'seputar', 'mengenai',
            'bagi', 'agar', 'supaya', 'maupun', 'namun', 'tetapi', 'serta', 'usai',
            // Generic on a livescore/sports-news site — present in most
            // headlines regardless of topic, so keeping them would make
            // nearly any two articles register as "similar".
            'jadwal', 'hasil', 'live', 'streaming', 'vs', 'skor', 'score', 'berita', 'terbaru', 'update',
            'video', 'highlight', 'highlights', 'prediksi', 'link', 'nonton', 'gratis', 'malam', 'hari',
            'wib', 'babak', 'pertandingan', 'main', 'bermain', 'laga', 'duel', 'partai', 'leg',
            'matchday', 'preview', 'recap', 'ringkasan', 'terkini', 'terkait', 'klasemen', 'statistik',
            'info', 'cara', 'h2h',
            // Generic transfer-window/match-report vocabulary (added after
            // Internal Linking Agent testing surfaced it, 4 Agu 2026) — words
            // like "transfer"/"musim"/"panas"/"trofi"/"juara" recur across
            // almost every transfer or match-result article regardless of
            // which players/clubs/tournament are actually involved, so
            // without these two problems showed up: (1) topic-overlap
            // matches on nothing but this generic vocabulary between
            // otherwise-unrelated articles, (2) anchor phrases built from
            // title fragments that happened to include one of these words
            // at the edge, reading as awkward/ungrammatical link text (e.g.
            // "pulang tanpa trofi di" — a real anchor this produced before
            // 'trofi'/'pulang' were added here).
            'bursa', 'transfer', 'musim', 'panas', 'dingin', 'resmi', 'rp', 'triliun', 'juta', 'banderol',
            'nilai', 'kontrak', 'gelar', 'trofi', 'turnamen', 'juara', 'pulang', 'datang', 'tampil',
            'sebagai', 'menjadi', 'menjadikannya', 'terbesar', 'jendela', 'winger', 'striker', 'gelandang',
            'bek', 'kiper', 'pemain', 'klub',
            // Common Indonesian intensifiers/adverbs (added 4 Agu 2026
            // after "paling" — a bare intensifier with zero topical
            // meaning — was proposed and briefly applied as a live anchor
            // in production). NOTE: this list is NOT the actual fix for
            // that class of bug — see cms_growth_agent_il_candidate_
            // phrases()'s corpus-document-frequency + mid-sentence-
            // capitalization gating for the real, self-adjusting defense.
            // This is just incidental cleanup so today's known offenders
            // don't even reach that machinery.
            'paling', 'sangat', 'lebih', 'sekali', 'bakal', 'makin', 'banget', 'cukup', 'agak',
            'terlalu', 'amat', 'begitu', 'terus', 'masih', 'selalu', 'kembali', 'kian',
        ]);
    }

    $normalized = mb_strtolower($text);
    $normalized = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $normalized) ?? '';
    $rawTokens = preg_split('/\s+/', trim($normalized)) ?: [];

    $tokens = [];
    foreach ($rawTokens as $token) {
        if ($token === '' || mb_strlen($token) < 3 || isset($stopwords[$token])) {
            continue;
        }
        $tokens[$token] = true;
    }

    return array_keys($tokens);
}

/**
 * Overlap coefficient (|A∩B| / min(|A|,|B|)) between two token sets — used
 * instead of Jaccard (|A∩B| / |A∪B|) because the two sides being compared
 * are usually very different lengths: a GSC query or missing-topic label
 * is typically 2-5 meaningful words, an article title 6-12. Jaccard would
 * get dragged down by the longer side's unrelated words even when every
 * meaningful word of the SHORT side is fully contained in the long one —
 * exactly the "this query is already covered by that article" case this
 * gate exists to catch. Overlap coefficient measures "how much of the
 * smaller set is contained in the larger one", which matches that intent
 * directly.
 *
 * @param string[] $a
 * @param string[] $b
 * @return array{coefficient: float, intersection: string[]}
 */
function cms_growth_agent_g0_overlap(array $a, array $b): array
{
    if ($a === [] || $b === []) {
        return ['coefficient' => 0.0, 'intersection' => []];
    }
    $intersection = array_values(array_intersect($a, $b));
    $minSize = min(count($a), count($b));

    return [
        'coefficient' => $minSize > 0 ? count($intersection) / $minSize : 0.0,
        'intersection' => $intersection,
    ];
}

/**
 * Reads the gate's similarity_threshold/min_overlap_tokens, nested under
 * opportunity_thresholds_json's 'seo_g0_gate' key (see
 * cms_gsc_default_opportunity_thresholds() in gsc-api.php) — same
 * array_replace_recursive-over-defaults pattern as every other threshold
 * getter in this codebase, so an admin can retune it from the DB without a
 * migration. Never throws.
 *
 * @return array{similarity_threshold: float, min_overlap_tokens: int}
 */
function cms_growth_agent_g0_gate_thresholds(PDO $pdo): array
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $defaults = cms_gsc_default_opportunity_thresholds()['seo_g0_gate'];
        $configured = cms_gsc_get_opportunity_thresholds($pdo)['seo_g0_gate'] ?? [];

        return array_replace_recursive($defaults, is_array($configured) ? $configured : []);
    } catch (Throwable $e) {
        return ['similarity_threshold' => 0.6, 'min_overlap_tokens' => 2];
    }
}

/**
 * The gate itself. Runs three independent, deterministic checks against
 * $topicText (the raw GSC query for 'gsc_article_idea', or the raw
 * missing-topic label for 'topic_gap_article') and returns every match as
 * a warning — never blocks, never throws (each sub-check is wrapped
 * separately so one failing SQL query still lets the other two run, and a
 * top-level catch guarantees an empty result rather than a fatal error no
 * matter what goes wrong).
 *
 *   A. Another pending proposal (growth_agent_jobs, job_type IN
 *      ('gsc_article_idea','topic_gap_article'), status IN
 *      ('manual_action','ready','running')) already covers a similar
 *      topic — compared against that job's OWN input_brief.query /
 *      .missing_topic (the two job types store the proposed topic under
 *      different keys, see cms_growth_agent_generate_article_idea() and
 *      cms_growth_agent_request_topic_gap_article()).
 *   B. A published article's title already covers a similar topic.
 *   C. The topic overlaps an OPEN growth_agent_content_conflicts row, or
 *      an OPEN gsc_opportunities row with recommended_action =
 *      'cannibalization_review'.
 *
 * Each warning carries enough for an operator to act without re-deriving
 * anything: which check, what it matched (type + id + a human label), and
 * the computed similarity score.
 *
 * @return array{warnings: array<int, array<string, mixed>>}
 */
function cms_growth_agent_seo_g0_gate(PDO $pdo, string $jobType, string $topicText): array
{
    $warnings = [];

    try {
        $thresholds = cms_growth_agent_g0_gate_thresholds($pdo);
        $simThreshold = (float) $thresholds['similarity_threshold'];
        $minOverlap = (int) $thresholds['min_overlap_tokens'];

        $topicTokens = cms_growth_agent_g0_tokenize($topicText);
        if ($topicTokens === []) {
            return ['warnings' => []];
        }

        $isMatch = static function (array $candidateTokens) use ($topicTokens, $simThreshold, $minOverlap): ?array {
            $overlap = cms_growth_agent_g0_overlap($topicTokens, $candidateTokens);
            if ($overlap['coefficient'] >= $simThreshold && count($overlap['intersection']) >= $minOverlap) {
                return $overlap;
            }
            return null;
        };

        // ── A. Duplicate pending proposal ───────────────────────────────
        try {
            $pendingStmt = $pdo->query(
                "SELECT id, job_type, status, input_brief FROM growth_agent_jobs
                  WHERE job_type IN ('gsc_article_idea', 'topic_gap_article')
                    AND status IN ('manual_action', 'ready', 'running')"
            );
            foreach ($pendingStmt->fetchAll() as $row) {
                $brief = json_decode((string) $row['input_brief'], true);
                if (!is_array($brief)) {
                    continue;
                }
                $candidateText = $row['job_type'] === 'gsc_article_idea'
                    ? (string) ($brief['query'] ?? '')
                    : (string) ($brief['missing_topic'] ?? '');
                if ($candidateText === '') {
                    continue;
                }
                $match = $isMatch(cms_growth_agent_g0_tokenize($candidateText));
                if ($match !== null) {
                    $warnings[] = [
                        'check' => 'duplicate_pending',
                        'similarity' => round($match['coefficient'], 2),
                        'ref_type' => 'job',
                        'ref_id' => (int) $row['id'],
                        'ref_label' => $candidateText,
                        'message' => 'Sudah ada usulan lain (job #' . (int) $row['id'] . ', status ' . $row['status']
                            . ') dengan topik serupa: "' . $candidateText . '".',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Check A degrades independently — B/C still run.
        }

        // ── B. Already covered by a published article ───────────────────
        try {
            $pagesStmt = $pdo->query("SELECT page_id, title FROM pages WHERE status = 'published'");
            foreach ($pagesStmt->fetchAll() as $row) {
                $match = $isMatch(cms_growth_agent_g0_tokenize((string) $row['title']));
                if ($match !== null) {
                    $warnings[] = [
                        'check' => 'published_coverage',
                        'similarity' => round($match['coefficient'], 2),
                        'ref_type' => 'page',
                        'ref_id' => (int) $row['page_id'],
                        'ref_label' => (string) $row['title'],
                        'message' => 'Sudah ada artikel published yang mirip: "' . $row['title']
                            . '" (page_id ' . (int) $row['page_id'] . ').',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Check B degrades independently.
        }

        // ── C1. Open content conflicts ───────────────────────────────────
        try {
            $conflictStmt = $pdo->query(
                "SELECT c.id, c.issue_text, a.page_id AS page_a_id, a.title AS page_a_title,
                        b.page_id AS page_b_id, b.title AS page_b_title
                   FROM growth_agent_content_conflicts c
                   JOIN pages a ON a.page_id = c.page_a_id
                   JOIN pages b ON b.page_id = c.page_b_id
                  WHERE c.status = 'open'"
            );
            foreach ($conflictStmt->fetchAll() as $row) {
                foreach (['page_a_title', 'page_b_title'] as $titleKey) {
                    $match = $isMatch(cms_growth_agent_g0_tokenize((string) $row[$titleKey]));
                    if ($match !== null) {
                        $warnings[] = [
                            'check' => 'conflict_flagged',
                            'similarity' => round($match['coefficient'], 2),
                            'ref_type' => 'conflict',
                            'ref_id' => (int) $row['id'],
                            'ref_label' => (string) $row[$titleKey],
                            'message' => 'Topik ini bersinggungan dengan content conflict #' . (int) $row['id']
                                . ' yang masih terbuka (melibatkan "' . $row[$titleKey] . '"): ' . $row['issue_text'],
                        ];
                        break; // one warning per conflict row is enough
                    }
                }
            }
        } catch (Throwable $e) {
            // Check C1 degrades independently.
        }

        // ── C2. Open cannibalization-review opportunities ────────────────
        try {
            $oppStmt = $pdo->query(
                "SELECT id, query_text FROM gsc_opportunities
                  WHERE recommended_action = 'cannibalization_review' AND status = 'open'"
            );
            foreach ($oppStmt->fetchAll() as $row) {
                $queryText = (string) ($row['query_text'] ?? '');
                if ($queryText === '') {
                    continue;
                }
                $match = $isMatch(cms_growth_agent_g0_tokenize($queryText));
                if ($match !== null) {
                    $warnings[] = [
                        'check' => 'conflict_flagged',
                        'similarity' => round($match['coefficient'], 2),
                        'ref_type' => 'opportunity',
                        'ref_id' => (int) $row['id'],
                        'ref_label' => $queryText,
                        'message' => 'Topik ini bersinggungan dengan opportunity cannibalization-review #'
                            . (int) $row['id'] . ' yang masih terbuka (query: "' . $queryText . '").',
                    ];
                }
            }
        } catch (Throwable $e) {
            // Check C2 degrades independently.
        }
    } catch (Throwable $e) {
        return ['warnings' => []];
    }

    return ['warnings' => $warnings];
}

/**
 * ── Internal Linking Agent (GROWTH_AGENT_V2_PROPOSAL.md Fase B item 1,
 *    4 Agu 2026) ──
 *
 * Scans published articles for pairs (A -> B) that are topically related
 * (reusing the SEO-G0 Gate's own tokenizer/overlap metric —
 * cms_growth_agent_g0_tokenize()/cms_growth_agent_g0_overlap() — same
 * reasoning applies: needs Indonesian stopword + site-generic-term
 * filtering or nearly every article pair would register as "related")
 * where A's content doesn't yet link to B, and proposes adding one link.
 *
 * Detection is 100% deterministic (plain token-overlap + DOM text search),
 * same "must be consistent/auditable, no AI, no per-run cost" reasoning as
 * the Opportunity Engine and the SEO-G0 Gate.
 *
 * Logs one job_type='internal_link_suggestion' row per proposed pair,
 * status='manual_action' — same Action Queue as everything else (§ 1b).
 * The scan itself NEVER touches `pages.content`; only approving the
 * resulting job on internal-link-review.php does, and even then only
 * after re-deriving the insertion fresh against the article's CURRENT
 * content (not a stale scan-time snapshot) and taking a full snapshot of
 * the old content into that same job's output_json first — this CMS has
 * no article revision history at all, so that snapshot is the only way
 * back if a link insertion goes wrong.
 */

/**
 * Reads similarity_threshold/min_overlap_tokens/max_suggestions_per_article/
 * articles_scanned_per_run, nested under opportunity_thresholds_json's
 * 'internal_linking' key (see cms_gsc_default_opportunity_thresholds() in
 * gsc-api.php) — same array_replace_recursive-over-defaults pattern as
 * cms_growth_agent_g0_gate_thresholds(). Never throws.
 *
 * @return array{similarity_threshold: float, min_overlap_tokens: int, max_suggestions_per_article: int, articles_scanned_per_run: int}
 */
function cms_growth_agent_il_thresholds(PDO $pdo): array
{
    $fallback = [
        'similarity_threshold' => 0.5, 'min_overlap_tokens' => 2,
        'max_suggestions_per_article' => 3, 'articles_scanned_per_run' => 10,
        'single_word_max_df_ratio' => 0.2, 'min_corpus_size_for_single_word' => 10,
    ];
    try {
        require_once __DIR__ . '/gsc-api.php';
        $defaults = cms_gsc_default_opportunity_thresholds()['internal_linking'] ?? $fallback;
        $configured = cms_gsc_get_opportunity_thresholds($pdo)['internal_linking'] ?? [];

        return array_replace_recursive($defaults, is_array($configured) ? $configured : []);
    } catch (Throwable $e) {
        return $fallback;
    }
}

/**
 * Corpus-wide token document-frequency, computed across every published
 * article's title + plain-text content — this is the structural fix (not
 * the stopword list) for single-word anchors like "paling" slipping
 * through: a word that appears in a large fraction of ALL published
 * articles is generic BY DEFINITION regardless of what the word actually
 * is, and this self-adjusts as the site's article corpus grows, unlike a
 * fixed manual list that can never enumerate every generic Indonesian
 * adverb/connector in advance. See cms_growth_agent_il_candidate_phrases()
 * for how this is used (gated behind a minimum corpus size — see that
 * function's own note on why).
 *
 * Never throws. Computed fresh per scan/apply call (not cached/persisted —
 * no new table, and cheap at this site's current article volume; revisit
 * if the corpus grows into the thousands).
 *
 * @return array{size: int, df: array<string, int>}
 */
function cms_growth_agent_il_corpus_stats(PDO $pdo): array
{
    try {
        $rows = $pdo->query("SELECT title, content FROM pages WHERE status = 'published'")->fetchAll();
    } catch (Throwable $e) {
        return ['size' => 0, 'df' => []];
    }

    $df = [];
    foreach ($rows as $row) {
        try {
            $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $row['content'])) ?? '');
            $tokens = array_unique(array_merge(
                cms_growth_agent_g0_tokenize($plainText),
                cms_growth_agent_g0_tokenize((string) $row['title'])
            ));
        } catch (Throwable $e) {
            continue;
        }
        foreach ($tokens as $token) {
            $df[$token] = ($df[$token] ?? 0) + 1;
        }
    }

    return ['size' => count($rows), 'df' => $df];
}

/**
 * Whether $word shows up ANYWHERE in $sourcePlainText capitalized AND in
 * the middle of a sentence — a much stronger proper-noun signal than
 * capitalization in a title, since most article titles on this site are
 * Title Case (every word capitalized), so title casing alone says nothing
 * about whether a specific word is actually a proper noun. A word
 * appearing capitalized mid-sentence in real body prose (where only
 * proper nouns and sentence-starts are normally capitalized in Indonesian)
 * is a real, independent signal.
 *
 * "Mid-sentence" here means: the nearest non-whitespace character before
 * the match, after trimming trailing whitespace, exists and is not a
 * sentence-terminating '.', '!', or '?' — i.e. not the first word of the
 * text and not the first word after a previous sentence ended.
 *
 * Never throws.
 */
function cms_growth_agent_il_is_proper_noun_candidate(string $word, string $sourcePlainText): bool
{
    $word = trim($word);
    if ($word === '' || $sourcePlainText === '') {
        return false;
    }

    try {
        $pattern = '/(?<![\p{L}\p{N}])' . preg_quote($word, '/') . '(?![\p{L}\p{N}])/u';
        if (!preg_match_all($pattern, $sourcePlainText, $matches, PREG_OFFSET_CAPTURE)) {
            return false;
        }

        foreach ($matches[0] as [$matchText, $offset]) {
            if ($matchText === '' || preg_match('/^\p{Lu}/u', $matchText) !== 1) {
                continue; // this particular occurrence isn't capitalized
            }
            $before = rtrim(substr($sourcePlainText, 0, (int) $offset));
            if ($before === '') {
                continue; // very first word of the text — not a signal
            }
            $prevChar = mb_substr($before, -1);
            if (in_array($prevChar, ['.', '!', '?'], true)) {
                continue; // first word of a new sentence — not a signal
            }
            return true; // genuine mid-sentence capitalized occurrence
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Generates candidate anchor phrases from a target article's title, longest
 * first (up to 6 words) — every multi-word candidate is guaranteed to
 * contain at least 2 non-stopword/non-generic tokens (reuses
 * cms_growth_agent_g0_tokenize() as the filter, trimmed off both edges
 * first), so a phrase built mostly of filler words is never proposed.
 * Longest-first means the most specific/natural phrase wins if multiple
 * candidates would match — "Piala Dunia 2026" over just "Piala".
 *
 * Single-word candidates are the historically riskier case (a real
 * production incident: the bare intensifier "paling" was proposed and
 * briefly applied as an anchor — see this file's top note on the Internal
 * Linking Agent). A single word is only ever offered as a candidate if it
 * passes BOTH:
 *   1. Corpus document frequency at or below 'single_word_max_df_ratio' —
 *      see cms_growth_agent_il_corpus_stats(). Skipped entirely (no
 *      single-word candidates at all) when the corpus is smaller than
 *      'min_corpus_size_for_single_word', since document-frequency ratios
 *      from a handful of articles are too noisy to trust.
 *   2. Mid-sentence capitalized evidence in the SOURCE article's own body
 *      text — see cms_growth_agent_il_is_proper_noun_candidate().
 *
 * @param array{size: int, df: array<string, int>} $corpusStats
 * @param array<string, mixed> $thresholds
 * @return string[] ordered longest (most words) first
 */
function cms_growth_agent_il_candidate_phrases(string $title, array $corpusStats, string $sourcePlainText, array $thresholds): array
{
    $words = preg_split('/\s+/u', trim($title)) ?: [];
    $words = array_values(array_filter($words, static fn ($w): bool => $w !== ''));
    $n = count($words);
    if ($n === 0) {
        return [];
    }

    // A window can contain a meaningful word in the middle but a stopword
    // at either edge (e.g. a 4-word window ending in "di" or "trofi") —
    // that reads as an awkward, ungrammatical anchor even though the
    // phrase as a whole "has a meaningful token". Trim stopword words off
    // both ends before accepting a candidate, so anchors always start and
    // end on a real word.
    $isStopword = static fn (string $word): bool => cms_growth_agent_g0_tokenize($word) === [];
    // Titles carry their own punctuation attached to words ("2026,",
    // "Dimulai!", "Leste:") — trimming only whole stopword words off the
    // edges still leaves an edge word like "2026," dangling into the
    // anchor as literal punctuation once matched against source text that
    // also happens to have a comma there (a real anchor this produced:
    // "Piala Dunia 2026," with a trailing comma). Strip leading/trailing
    // punctuation from the edge words themselves, on top of the stopword
    // trim, so an anchor never starts or ends on stray punctuation.
    $stripEdgePunct = static fn (string $word): string => trim($word, "\"'“”‘’()[]{}«»,.;:!?…-");

    $phrases = [];
    $seen = [];
    $maxWords = min($n, 6);
    for ($len = $maxWords; $len >= 2; $len--) {
        for ($start = 0; $start + $len <= $n; $start++) {
            $slice = array_slice($words, $start, $len);
            while ($slice !== []) {
                $clean = $stripEdgePunct($slice[0]);
                if ($clean === '' || $isStopword($clean)) {
                    array_shift($slice);
                    continue;
                }
                $slice[0] = $clean;
                break;
            }
            while ($slice !== []) {
                $lastIdx = count($slice) - 1;
                $clean = $stripEdgePunct($slice[$lastIdx]);
                if ($clean === '' || $isStopword($clean)) {
                    array_pop($slice);
                    continue;
                }
                $slice[$lastIdx] = $clean;
                break;
            }
            if (count($slice) < 2) {
                continue;
            }
            $phrase = implode(' ', $slice);
            // Requires >=2 MEANINGFUL tokens, not just >=2 words — a
            // 2-word phrase like "yang penting" (if "yang" survived
            // mid-phrase rather than at an edge) still only carries one
            // real topical token and reads as vague, not identifying.
            if (isset($seen[$phrase]) || count(cms_growth_agent_g0_tokenize($phrase)) < 2) {
                continue;
            }
            $seen[$phrase] = true;
            $phrases[] = $phrase;
        }
    }

    $corpusSize = (int) ($corpusStats['size'] ?? 0);
    $minCorpusSize = (int) ($thresholds['min_corpus_size_for_single_word'] ?? 10);
    $maxDfRatio = (float) ($thresholds['single_word_max_df_ratio'] ?? 0.2);
    if ($corpusSize >= $minCorpusSize) {
        foreach ($words as $rawWord) {
            $word = $stripEdgePunct($rawWord);
            if (mb_strlen($word) < 5 || $isStopword($word) || isset($seen[$word])) {
                continue;
            }
            $tokenized = cms_growth_agent_g0_tokenize($word);
            if ($tokenized === []) {
                continue;
            }
            $df = (int) ($corpusStats['df'][$tokenized[0]] ?? 0);
            if (($df / $corpusSize) > $maxDfRatio) {
                continue; // too generic across the corpus — the "paling" case
            }
            if (!cms_growth_agent_il_is_proper_noun_candidate($word, $sourcePlainText)) {
                continue; // no independent evidence this reads as a proper noun
            }
            $seen[$word] = true;
            $phrases[] = $word;
        }
    }

    // Stopword/punctuation trimming means how many REAL words survive a
    // window varies unpredictably by starting position — a 6-word window
    // that trims down to 3 words can end up earlier in $phrases than a
    // different 6-word window (a few positions later) that trims down to
    // 4, simply because it was generated first. Sort by actual surviving
    // word count (descending) so "longest first" is genuinely true of the
    // final list, not just of the raw windows that produced it — usort()
    // is stable since PHP 8.0, so candidates with equal word counts keep
    // their original relative order. Single-word candidates (0 spaces)
    // naturally sort last without special-casing.
    usort($phrases, static fn (string $a, string $b): int => substr_count($b, ' ') <=> substr_count($a, ' '));

    return $phrases;
}

/**
 * Whether $html already contains a link to the article at $targetSlug —
 * checked via DOMDocument (not a raw string search) so an href that merely
 * happens to contain the slug as a text coincidence elsewhere doesn't
 * false-positive... though in practice this is a straightforward
 * substring check against real <a href> values, which is safe precisely
 * because DOMDocument guarantees we're only ever looking at genuine href
 * attribute values, never arbitrary text. Never throws — a parse failure
 * is treated as "not linked" (the insertion step re-parses the same HTML
 * anyway and will itself abort safely if the HTML can't be trusted).
 */
function cms_growth_agent_il_already_linked(string $html, string $targetSlug): bool
{
    if (trim($html) === '' || $targetSlug === '') {
        return false;
    }
    try {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="wpm-il-check">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        if (!$loaded) {
            return false;
        }
        $needle = 'artikel/' . rawurlencode($targetSlug);
        $xpath = new DOMXPath($dom);
        foreach ($xpath->query('//a[@href]') as $anchor) {
            $href = $anchor->getAttribute('href');
            if ($href !== '' && (str_contains($href, $needle) || str_contains($href, $targetSlug))) {
                return true;
            }
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Re-parses $newHtml and confirms it's safe to persist: parses without a
 * FATAL libxml error, has exactly $expectedAnchorCount total <a> tags (the
 * original count + 1 — never more, never fewer), and has zero nested
 * anchors (<a> inside another <a>). Belt-and-suspenders on top of
 * cms_growth_agent_il_insert_link()'s own careful DOM surgery — if this
 * returns false, the caller must discard the result entirely rather than
 * save it, per the "abort rather than risk a broken article" rule.
 */
function cms_growth_agent_il_verify_safe(string $newHtml, int $expectedAnchorCount): bool
{
    try {
        libxml_use_internal_errors(true);
        $check = new DOMDocument('1.0', 'UTF-8');
        $loaded = $check->loadHTML(
            '<?xml encoding="UTF-8"><div id="wpm-il-verify">' . $newHtml . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $errors = libxml_get_errors();
        libxml_clear_errors();
        if (!$loaded) {
            return false;
        }
        foreach ($errors as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                return false;
            }
        }
        $xpath = new DOMXPath($check);
        if ($xpath->query('//a//a')->length > 0) {
            return false;
        }
        return $xpath->query('//a')->length === $expectedAnchorCount;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * The DOM-safe link insertion itself — the core of this whole feature's
 * safety story. Never uses str_replace()/raw regex-on-HTML (which could
 * insert into an attribute value, inside <script>/<style>, or nest inside
 * an existing <a>). Instead:
 *
 *   1. Parses $html with DOMDocument (UTF-8 explicitly declared via the
 *      "<?xml encoding=...>" prefix trick — WITHOUT this, DOMDocument
 *      silently mis-decodes UTF-8 as Latin-1 and mangles every non-ASCII
 *      character, the classic PHP DOMDocument/UTF-8 trap).
 *   2. Selects ONLY text() nodes that are NOT already inside <a>, <script>,
 *      or <style> (an XPath ancestor:: check — attribute VALUES are never
 *      even visible to this query, since DOMAttr nodes aren't text()
 *      nodes, so "inside an attribute" is structurally impossible to hit
 *      here at all, not just filtered out).
 *   3. Tries each candidate anchor phrase (longest first, see
 *      cms_growth_agent_il_candidate_phrases()), and within a phrase,
 *      each eligible text node in document order — stops at the FIRST
 *      whole-phrase (word-boundary-safe, Unicode-aware) match found,
 *      never inserts more than once.
 *   4. Splits that one text node into before/anchor/after DOM nodes
 *      (byte-offset split from PREG_OFFSET_CAPTURE with the 'u' modifier
 *      is safe here — those offsets always land on UTF-8 character
 *      boundaries) and inserts a real <a> element via createElement/
 *      createTextNode, which handles HTML-entity escaping correctly on
 *      its own.
 *   5. Re-serializes ONLY the wrapper's children (never the wrapper
 *      itself) via saveHTML(), then hands the result to
 *      cms_growth_agent_il_verify_safe() as a final safety re-check.
 *
 * Returns null (never partially applies) if: the HTML can't be parsed
 * safely, no candidate phrase has any safe occurrence, or the post-
 * insertion safety re-check fails for any reason.
 *
 * @return array{html: string, anchor_text: string, context: string}|null
 */
function cms_growth_agent_il_insert_link(string $html, string $targetTitle, string $targetHref, array $corpusStats, array $thresholds): ?array
{
    if (trim($html) === '' || trim($targetTitle) === '' || trim($targetHref) === '') {
        return null;
    }

    $sourcePlainText = trim(preg_replace('/\s+/u', ' ', strip_tags($html)) ?? '');
    $phrases = cms_growth_agent_il_candidate_phrases($targetTitle, $corpusStats, $sourcePlainText, $thresholds);
    if ($phrases === []) {
        return null;
    }

    try {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $loaded = $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="wpm-il-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        $parseErrors = libxml_get_errors();
        libxml_clear_errors();
        if (!$loaded) {
            return null;
        }
        foreach ($parseErrors as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                return null; // source HTML too broken to safely round-trip
            }
        }

        $root = $dom->getElementById('wpm-il-root');
        if ($root === null) {
            return null;
        }

        $xpath = new DOMXPath($dom);
        $originalAnchorCount = $xpath->query('//a')->length;

        foreach ($phrases as $phrase) {
            $pattern = '/(?<![\p{L}\p{N}])(' . preg_quote($phrase, '/') . ')(?![\p{L}\p{N}])/ui';
            $textNodes = $xpath->query('.//text()[not(ancestor::a) and not(ancestor::script) and not(ancestor::style)]', $root);

            foreach ($textNodes as $node) {
                $nodeText = $node->nodeValue;
                if ($nodeText === null || trim($nodeText) === '') {
                    continue;
                }
                if (!preg_match($pattern, $nodeText, $m, PREG_OFFSET_CAPTURE)) {
                    continue;
                }

                $matchText = $m[1][0];
                $offset = $m[1][1];
                $before = substr($nodeText, 0, $offset);
                $after = substr($nodeText, $offset + strlen($matchText));

                $parent = $node->parentNode;
                if ($parent === null) {
                    continue;
                }

                $anchor = $dom->createElement('a');
                $anchor->setAttribute('href', $targetHref);
                $anchor->appendChild($dom->createTextNode($matchText));

                if ($before !== '') {
                    $parent->insertBefore($dom->createTextNode($before), $node);
                }
                $parent->insertBefore($anchor, $node);
                if ($after !== '') {
                    $parent->insertBefore($dom->createTextNode($after), $node);
                }
                $parent->removeChild($node);

                $context = trim(preg_replace('/\s+/u', ' ', (string) $parent->textContent) ?? '');
                if (mb_strlen($context) > 220) {
                    $pos = mb_stripos($context, $matchText);
                    $start = max(0, ($pos === false ? 0 : $pos) - 80);
                    $context = ($start > 0 ? '…' : '') . mb_substr($context, $start, 220) . '…';
                }

                $newHtml = '';
                foreach ($root->childNodes as $child) {
                    $newHtml .= $dom->saveHTML($child);
                }

                if (!cms_growth_agent_il_verify_safe($newHtml, $originalAnchorCount + 1)) {
                    return null; // abort entirely rather than risk a broken save
                }

                return ['html' => $newHtml, 'anchor_text' => $matchText, 'context' => $context];
            }
        }

        return null; // no safe occurrence found for any candidate phrase
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * The scan itself — triggered manually (button on growth-agent.php, see
 * that page's own comment on why it lives there and not
 * seo-intelligence.php). For up to $articlesLimit published articles
 * (least-recently-updated first, same convention as
 * cms_growth_agent_scan_seo_recommendations()), compares against every
 * OTHER published article by topic-token overlap, and for each relevant,
 * not-yet-linked, not-yet-proposed pair where a safe anchor insertion
 * point actually exists, logs one manual_action job. Caps proposals per
 * source article (max_suggestions_per_article) so one heavily-connected
 * article doesn't dominate a scan with a wall of suggestions.
 *
 * Never modifies `pages.content` — that only happens in
 * cms_growth_agent_apply_internal_link(), on explicit operator approval.
 * Never throws.
 *
 * @return array{scanned: int, created: int, errors: int}
 */
function cms_growth_agent_scan_internal_links(PDO $pdo): array
{
    $stats = ['scanned' => 0, 'created' => 0, 'errors' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);
        $thresholds = cms_growth_agent_il_thresholds($pdo);
        $simThreshold = (float) $thresholds['similarity_threshold'];
        $minOverlap = (int) $thresholds['min_overlap_tokens'];
        $maxPerArticle = max(1, (int) $thresholds['max_suggestions_per_article']);
        $articlesLimit = max(1, min(30, (int) $thresholds['articles_scanned_per_run']));

        $allPages = $pdo->query("SELECT page_id, title, slug, content FROM pages WHERE status = 'published'")->fetchAll();
        if (count($allPages) < 2) {
            return $stats;
        }

        // Computed once per scan (not per candidate pair) — corpus size at
        // this site's current volume makes recomputing per-pair wasteful.
        $corpusStats = cms_growth_agent_il_corpus_stats($pdo);

        $sourceStmt = $pdo->prepare(
            "SELECT page_id, title, slug, content FROM pages WHERE status = 'published' ORDER BY updated_at ASC LIMIT " . $articlesLimit
        );
        $sourceStmt->execute();
        $sources = $sourceStmt->fetchAll();

        // Existing pending/applied pairs — decoded in PHP rather than a
        // JSON_EXTRACT() SQL condition, same convention as the SEO-G0
        // Gate's duplicate-pending check (this codebase's established
        // pattern for scanning growth_agent_jobs.input_brief).
        $existingPairs = [];
        $jobRows = $pdo->query(
            "SELECT input_brief FROM growth_agent_jobs
              WHERE job_type = 'internal_link_suggestion' AND status IN ('manual_action', 'succeeded')"
        )->fetchAll();
        foreach ($jobRows as $row) {
            $brief = json_decode((string) $row['input_brief'], true);
            if (is_array($brief) && isset($brief['source_page_id'], $brief['target_page_id'])) {
                $existingPairs[$brief['source_page_id'] . ':' . $brief['target_page_id']] = true;
            }
        }
    } catch (Throwable $e) {
        return $stats;
    }

    foreach ($sources as $source) {
        $stats['scanned']++;
        $sourceId = (int) $source['page_id'];

        try {
            $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $source['content'])) ?? '');
            $sourceTokens = cms_growth_agent_g0_tokenize($plainText);
        } catch (Throwable $e) {
            continue;
        }
        if ($sourceTokens === []) {
            continue;
        }

        $suggestionsForThisArticle = 0;
        foreach ($allPages as $target) {
            if ($suggestionsForThisArticle >= $maxPerArticle) {
                break;
            }
            $targetId = (int) $target['page_id'];
            if ($targetId === $sourceId) {
                continue;
            }
            if (isset($existingPairs[$sourceId . ':' . $targetId])) {
                continue;
            }

            try {
                $targetTokens = cms_growth_agent_g0_tokenize((string) $target['title']);
                if ($targetTokens === []) {
                    continue;
                }
                $overlap = cms_growth_agent_g0_overlap($targetTokens, $sourceTokens);
                if ($overlap['coefficient'] < $simThreshold || count($overlap['intersection']) < $minOverlap) {
                    continue;
                }

                if (cms_growth_agent_il_already_linked((string) $source['content'], (string) $target['slug'])) {
                    continue;
                }

                $targetHref = 'artikel/' . rawurlencode((string) $target['slug']);
                $insertResult = cms_growth_agent_il_insert_link((string) $source['content'], (string) $target['title'], $targetHref, $corpusStats, $thresholds);
                if ($insertResult === null) {
                    continue; // no safe anchor point — skip, do not force it
                }

                $inputBrief = [
                    'source_page_id' => $sourceId,
                    'source_title' => (string) $source['title'],
                    'target_page_id' => $targetId,
                    'target_title' => (string) $target['title'],
                    'target_slug' => (string) $target['slug'],
                    'anchor_text' => $insertResult['anchor_text'],
                    'context' => $insertResult['context'],
                    'similarity' => round($overlap['coefficient'], 2),
                ];

                $jobId = cms_growth_agent_log_job(
                    $pdo, 'internal_link_suggestion', 'growth_agent', $sourceId, 'manual_action',
                    $inputBrief, null, null, null, null, null, '', 'medium'
                );
                if ($jobId > 0) {
                    $stats['created']++;
                    $suggestionsForThisArticle++;
                    $existingPairs[$sourceId . ':' . $targetId] = true;
                } else {
                    $stats['errors']++;
                }
            } catch (Throwable $e) {
                $stats['errors']++;
            }
        }
    }

    return $stats;
}

/**
 * Approve half of the Internal Linking Agent flow — the ONLY place
 * `pages.content` is ever written by this feature, called exclusively
 * from internal-link-review.php's "Apply" action (never generic
 * Approve/Reject: writing to `pages` needs the dedicated snapshot-first
 * handling below, same reasoning as why 'seo_recommendation' has its own
 * review page instead of the generic buttons).
 *
 * Re-derives the insertion fresh against the article's CURRENT content
 * (never trusts the scan-time input_brief.context as still accurate — the
 * article may have been edited since) via
 * cms_growth_agent_il_insert_link(), and if (and only if) that succeeds:
 * snapshots the OLD content in full into this job's own output_json
 * (mandatory — see this file's own top note on there being no revision
 * history at all), then overwrites `pages.content` (and ONLY content —
 * `pages.status` is never touched, published stays published).
 *
 * Never throws. Returns ['ok' => bool, 'error' => string].
 */
function cms_growth_agent_apply_internal_link(PDO $pdo, int $jobId): array
{
    try {
        $jobStmt = $pdo->prepare(
            "SELECT id, status, page_id, input_brief FROM growth_agent_jobs
              WHERE id = :id AND job_type = 'internal_link_suggestion' LIMIT 1"
        );
        $jobStmt->execute(['id' => $jobId]);
        $job = $jobStmt->fetch();
        if (!$job) {
            return ['ok' => false, 'error' => 'Job usulan link tidak ditemukan.'];
        }
        if ($job['status'] !== 'manual_action') {
            return ['ok' => false, 'error' => 'Usulan ini sudah pernah diproses sebelumnya.'];
        }

        $brief = json_decode((string) $job['input_brief'], true);
        if (!is_array($brief)) {
            return ['ok' => false, 'error' => 'Data usulan (input_brief) rusak.'];
        }
        $sourceId = (int) ($brief['source_page_id'] ?? 0);
        $targetSlug = trim((string) ($brief['target_slug'] ?? ''));
        $targetTitle = trim((string) ($brief['target_title'] ?? ''));
        if ($sourceId <= 0 || $targetSlug === '' || $targetTitle === '') {
            return ['ok' => false, 'error' => 'Data usulan tidak lengkap.'];
        }

        $pageStmt = $pdo->prepare('SELECT page_id, content FROM pages WHERE page_id = :id LIMIT 1');
        $pageStmt->execute(['id' => $sourceId]);
        $page = $pageStmt->fetch();
        if (!$page) {
            return ['ok' => false, 'error' => 'Artikel sumber tidak ditemukan — mungkin sudah dihapus.'];
        }

        $currentContent = (string) $page['content'];
        if (cms_growth_agent_il_already_linked($currentContent, $targetSlug)) {
            return ['ok' => false, 'error' => 'Artikel ini sudah punya link ke artikel tujuan — tidak ada perubahan yang diterapkan.'];
        }

        $targetHref = 'artikel/' . rawurlencode($targetSlug);
        $corpusStats = cms_growth_agent_il_corpus_stats($pdo);
        $thresholds = cms_growth_agent_il_thresholds($pdo);
        $result = cms_growth_agent_il_insert_link($currentContent, $targetTitle, $targetHref, $corpusStats, $thresholds);
        if ($result === null) {
            return ['ok' => false, 'error' => 'Tidak ditemukan tempat penyisipan yang aman di konten SAAT INI — kemungkinan artikel sudah diedit sejak usulan ini dibuat. Tidak ada perubahan diterapkan.'];
        }

        $currentAdminId = (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null;

        $pdo->beginTransaction();
        try {
            // Mandatory content snapshot — see this section's own top note:
            // this CMS has no revision history, so this is the only way an
            // operator can recover the previous wording if this insertion
            // turns out to be wrong after the fact.
            $snapshot = [
                'page_id' => $sourceId,
                'previous_content' => $currentContent,
                'previous_content_length' => mb_strlen($currentContent),
                'applied_at' => date(DATE_ATOM),
                'anchor_text' => $result['anchor_text'],
                'target_page_id' => (int) ($brief['target_page_id'] ?? 0),
                'target_href' => $targetHref,
            ];

            $pdo->prepare('UPDATE pages SET content = :content, updated_at = NOW() WHERE page_id = :id')
                ->execute(['content' => $result['html'], 'id' => $sourceId]);

            $pdo->prepare(
                'INSERT INTO growth_agent_feedback (job_id, action, reviewed_by, created_at) VALUES (:job_id, :action, :reviewed_by, NOW())'
            )->execute(['job_id' => $jobId, 'action' => 'approved_as_is', 'reviewed_by' => $currentAdminId]);

            $pdo->prepare(
                "UPDATE growth_agent_jobs SET status = 'succeeded', output_json = :output, updated_at = NOW() WHERE id = :id"
            )->execute(['output' => json_encode($snapshot, JSON_UNESCAPED_UNICODE), 'id' => $jobId]);

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Logs a 'topic_gap_article' manual_action job — clicking "Generate Saran
 * Artikel" on a missing topic never calls the AI itself, it only queues a
 * review row (same "click just surfaces a job, approve does the real
 * work" split as cms_growth_agent_log_cannibalization_review()). The
 * actual draft is only created if/when this job gets approved — see
 * cms_growth_agent_create_article_draft_from_topic_gap().
 *
 * Runs the SEO-G0 Gate against $missingTopic before logging — see the
 * gate's own docblock above cms_growth_agent_seo_g0_gate(). Advisory only:
 * the job is logged unconditionally, warnings (if any) just ride along in
 * input_brief.seo_g0_gate for the operator to see during review.
 */
function cms_growth_agent_request_topic_gap_article(PDO $pdo, int $clusterId, string $missingTopic): int
{
    $gateResult = cms_growth_agent_seo_g0_gate($pdo, 'topic_gap_article', $missingTopic);

    $inputBrief = [
        'cluster_id' => $clusterId,
        'missing_topic' => $missingTopic,
        'seo_g0_gate' => $gateResult,
    ];

    return cms_growth_agent_log_job(
        $pdo, 'topic_gap_article', 'growth_agent', null, 'manual_action', $inputBrief, null,
        null, null, null, null, '', 'medium'
    );
}

/**
 * Logs a 'content_conflict_proposal' manual_action job for one
 * growth_agent_content_conflicts row, and flips that row's status to
 * 'proposal_requested' so content-conflict-detection.php can grey out the
 * button instead of letting it be queued twice. No AI call here either —
 * approving this job never merges/redirects anything (guardrail:
 * "Recommendation only" — see cms_growth_agent_ensure... note in
 * gsc-api.php for the same principle applied to cannibalization), it only
 * marks the conflict as human-reviewed.
 */
function cms_growth_agent_request_conflict_proposal(PDO $pdo, int $conflictId): int
{
    $inputBrief = [
        'conflict_id' => $conflictId,
    ];

    $jobId = cms_growth_agent_log_job(
        $pdo, 'content_conflict_proposal', 'growth_agent', null, 'manual_action', $inputBrief, null,
        null, null, null, null, '', 'medium'
    );

    if ($jobId > 0) {
        try {
            $pdo->prepare("UPDATE growth_agent_content_conflicts SET status = 'proposal_requested' WHERE id = :id")
                ->execute(['id' => $conflictId]);
        } catch (Throwable $e) {
            // Best-effort — the job itself is already logged either way.
        }
    }

    return $jobId;
}

/**
 * Content Agent Adapter for 'topic_gap_article' — approving this job type
 * creates a draft article from a topic-cluster's missing subtopic, exactly
 * the same "Approve IS the execution step" exception as
 * cms_growth_agent_create_article_draft_from_idea() (gsc_article_idea).
 * Title is the missing topic itself, content is a single placeholder
 * paragraph the operator fleshes out manually — there's no outline to
 * build from here (unlike the GSC article-idea flow), just one topic
 * string. Always produces a 'draft', never 'published'.
 *
 * Never throws — matches this file's own convention.
 */
function cms_growth_agent_create_article_draft_from_topic_gap(PDO $pdo, array $job, ?int $authorId): array
{
    try {
        $inputBrief = json_decode((string) ($job['input_brief'] ?? ''), true);
        $title = is_array($inputBrief) ? trim((string) ($inputBrief['missing_topic'] ?? '')) : '';
        if ($title === '') {
            return ['ok' => false, 'page_id' => 0, 'error' => 'Job input tidak berisi missing_topic yang valid.'];
        }

        require_once __DIR__ . '/functions.php';
        require_once __DIR__ . '/sitemap-service.php';

        $slugBase = cms_slugify($title);
        if ($slugBase === '') {
            $slugBase = 'topic-gap-' . (int) $job['id'];
        }
        $slug = $slugBase;
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM pages WHERE slug = :slug');
        for ($suffix = 2; ; $suffix++) {
            $dupCheck->execute(['slug' => $slug]);
            if ((int) $dupCheck->fetchColumn() === 0) {
                break;
            }
            $slug = $slugBase . '-' . $suffix;
        }

        $contentHtml = '<p><em>Draft dibuat otomatis oleh Growth Agent dari topik yang belum tercover di sebuah topic cluster — lengkapi konten di bawah sebelum publish.</em></p><p>[Tulis konten untuk topik ini]</p>';

        $payload = [
            'title'     => $title,
            'slug'      => $slug,
            'content'   => $contentHtml,
            'status'    => 'draft',
            'author_id' => $authorId,
        ];

        $insert = $pdo->prepare(
            'INSERT INTO pages (title, slug, content, status, author_id, created_at, updated_at)
             VALUES (:title, :slug, :content, :status, :author_id, NOW(), NOW())'
        );
        $insert->execute($payload);
        $pageId = (int) $pdo->lastInsertId();

        try {
            cms_sitemap_ensure_schema($pdo);
            cms_sitemap_on_article_save($pdo, [], $payload + [
                'page_id'       => $pageId,
                'noindex'       => 0,
                'canonical_url' => null,
                'published_at'  => null,
            ]);
        } catch (Throwable $e) {
            error_log('[cms_growth_agent_create_article_draft_from_topic_gap] Sitemap upsert failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'page_id' => $pageId, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'page_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Insert one growth_agent_jobs row. Never throws — a logging failure must
 * never break the actual generate response, matching cms_ai_log()'s own
 * philosophy in ai-helpers.php. Returns the new job id, or 0 on failure.
 *
 * $priority (added 27 Jul 2026, ported alongside the GSC/Prioritized
 * Opportunities feature — see gsc-api.php) defaults to 'medium' so EVERY
 * job type always carries a value, never null/skipped — a job spawned from
 * a scored opportunity passes that opportunity's derived priority through;
 * a job with nothing to score (e.g. a plain "Scan for SEO improvements"
 * click) just gets the neutral default. Invalid values silently fall back
 * to 'medium' rather than rejecting the whole log call.
 *
 * @param array<string, mixed>      $inputBrief  JSON-encoded verbatim.
 * @param array<string, mixed>|null $outputData  JSON-encoded verbatim, null if the job failed before producing output.
 */
function cms_growth_agent_log_job(
    PDO $pdo,
    string $jobType,
    string $agentKey,
    ?int $pageId,
    string $status,
    array $inputBrief,
    ?array $outputData,
    ?string $modelUsed,
    ?int $tokensIn,
    ?int $tokensOut,
    ?int $latencyMs,
    string $errorMessage = '',
    string $priority = 'medium'
): int {
    try {
        cms_growth_agent_ensure_schema($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO growth_agent_jobs
                (job_type, agent_key, page_id, status, priority, input_brief, output_json, model_used, tokens_in, tokens_out, latency_ms, error_message, created_by, created_at, updated_at)
             VALUES
                (:job_type, :agent_key, :page_id, :status, :priority, :input_brief, :output_json, :model_used, :tokens_in, :tokens_out, :latency_ms, :error_message, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            'job_type'      => $jobType,
            'agent_key'     => $agentKey,
            'page_id'       => $pageId,
            'status'        => $status,
            'priority'      => in_array($priority, ['low', 'medium', 'high'], true) ? $priority : 'medium',
            'input_brief'   => json_encode($inputBrief, JSON_UNESCAPED_UNICODE),
            'output_json'   => $outputData !== null ? json_encode($outputData, JSON_UNESCAPED_UNICODE) : null,
            'model_used'    => $modelUsed,
            'tokens_in'     => $tokensIn,
            'tokens_out'    => $tokensOut,
            'latency_ms'    => $latencyMs,
            'error_message' => $errorMessage !== '' ? $errorMessage : null,
            'created_by'    => (int) ($_SESSION['cms_admin_id'] ?? 0) ?: null,
        ]);

        return (int) $pdo->lastInsertId();
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_log_job] Failed logging job: ' . $e->getMessage());
        return 0;
    }
}

/**
 * "Apply SEO Recommendation" — the scan half of the review/apply flow.
 *
 * Triggered by the manual "Scan for SEO improvements" button on
 * pages/growth-agent.php (not automatic/scheduled — see the flow diagram
 * the operator approved: Scan -> Resolve Target -> SEO child action ->
 * Review & Apply). For up to $limit published articles that have never
 * been scanned (or already scanned+actioned) before, asks the seo_agent
 * to review the CURRENT meta_title/meta_description and suggest an
 * improvement, then logs one growth_agent_jobs row per article with
 * status='manual_action' — job_type='seo_recommendation' reuses the exact
 * same jobs table as seo_meta/article_draft/faq, it just has a distinct
 * review UI (pages/seo-recommendation-review.php) instead of the generic
 * Approve/Reject buttons, because "approve" here must actually write the
 * new values into the pages table, not just mark a job succeeded.
 *
 * Never throws — a scan failure must not break the Growth Agent page.
 *
 * @return array{scanned:int, created:int, errors:int}
 */
function cms_growth_agent_scan_seo_recommendations(PDO $pdo, int $limit = 5): array
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        $limit = max(1, min(20, $limit));

        $stmt = $pdo->prepare(
            "SELECT page_id, title, slug, excerpt, content, meta_title, meta_description
               FROM pages
              WHERE status = 'published'
                AND page_id NOT IN (
                    SELECT page_id FROM growth_agent_jobs
                     WHERE job_type = 'seo_recommendation' AND page_id IS NOT NULL
                       AND status IN ('manual_action', 'succeeded')
                )
              ORDER BY updated_at ASC
              LIMIT " . $limit
        );
        $stmt->execute();
        $pages = $stmt->fetchAll();
    } catch (Throwable $e) {
        return ['scanned' => 0, 'created' => 0, 'errors' => 0];
    }

    return cms_growth_agent_run_seo_recommendation_scan($pdo, $pages);
}

/**
 * Shared engine behind cms_growth_agent_scan_seo_recommendations() (date-
 * based candidate selection) and the on-demand "Generate" dispatch from a
 * Prioritized Opportunity row (see gsc-api.php / pages/growth-agent.php's
 * `generate_from_opportunity` action) — both just select candidate pages
 * differently, then hand them to this function to actually call the AI,
 * parse the result, and log one job_type='seo_recommendation' row per
 * page. Extracted (27 Jul 2026, ported alongside GSC/Prioritized
 * Opportunities) so the two candidate-selection strategies never drift out
 * of sync on the generate/parse/log logic itself.
 *
 * @param list<array<string, mixed>> $pages
 * @param array<int, string> $priorityMap page_id => 'low'|'medium'|'high', defaults to 'medium' when a page isn't in the map
 * @return array{scanned:int, created:int, errors:int, job_ids:list<int>}
 */
function cms_growth_agent_run_seo_recommendation_scan(PDO $pdo, array $pages, array $priorityMap = []): array
{
    // job_ids: every job actually logged (success or failure), in order —
    // the on-demand opportunity dispatch (called with a single-page array)
    // needs the new job's id to link gsc_opportunities.linked_job_id. The
    // original bulk/date-based caller ignores this key.
    $stats = ['scanned' => 0, 'created' => 0, 'errors' => 0, 'job_ids' => []];

    if ($pages === []) {
        return $stats;
    }

    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return $stats;
    }

    $defaultSystemPrompt =
        'You are "Agent SEO" reviewing the EXISTING meta_title and meta_description of a published ' .
        'Sagagoal article (a livescore & sports news site). Given the article title, slug, excerpt, ' .
        'content, and its current meta_title/meta_description, suggest an improved meta_title (max 60 ' .
        'characters) and meta_description (max 155 characters) that is more compelling and better ' .
        'optimized for search, in the same language as the content (default Bahasa Indonesia). If the ' .
        'current metadata is already strong, a small refinement is fine — do not change things just to ' .
        'change them. Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, ' .
        'in exactly this shape: {"recommended_meta_title": "...", "recommended_meta_description": "..."}';

    $agent = cms_ai_resolve_agent($pdo, 'seo_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return $stats;
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('seo_agent', 'seo_recommendation'));
    } catch (Throwable $e) {
        // Ignore — scan proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    foreach ($pages as $page) {
        $stats['scanned']++;
        $pageId = (int) $page['page_id'];
        $priority = $priorityMap[$pageId] ?? 'medium';

        $currentMetaTitle = (string) ($page['meta_title'] ?? '');
        $currentMetaDescription = (string) ($page['meta_description'] ?? '');

        $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
            "Current meta_title: {$currentMetaTitle}\nCurrent meta_description: {$currentMetaDescription}\n" .
            "Content:\n" . mb_substr((string) $page['content'], 0, 6000);

        $inputBrief = [
            'title' => (string) $page['title'],
            'slug' => (string) $page['slug'],
            'current_meta_title' => $currentMetaTitle,
            'current_meta_description' => $currentMetaDescription,
        ];
        if (isset($page['total_impressions'])) {
            $inputBrief['gsc_impressions'] = (int) $page['total_impressions'];
            $inputBrief['gsc_clicks'] = (int) ($page['total_clicks'] ?? 0);
        }

        try {
            $result = cms_ai_call_provider(
                $agent['provider'], $agent['api_key'], $agent['model'],
                $userPrompt, $systemPrompt, max($agent['max_tokens'], 300), $agent['temperature']
            );
        } catch (Throwable $e) {
            $stats['errors']++;
            continue;
        }

        $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
        $retried = false;

        if ($result['success'] && (!is_array($parsed) || !isset($parsed['recommended_meta_title'], $parsed['recommended_meta_description']))) {
            $retried = true;
            $correctivePrompt = $userPrompt .
                "\n\n---\nYour previous reply could not be parsed. Reply with ONLY a raw JSON object, " .
                'no markdown, no code fences, no commentary, in exactly this shape: ' .
                '{"recommended_meta_title": "...", "recommended_meta_description": "..."}';
            $result = cms_ai_call_provider(
                $agent['provider'], $agent['api_key'], $agent['model'],
                $correctivePrompt, $systemPrompt, max($agent['max_tokens'], 300), $agent['temperature']
            );
            $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;
        }

        $usage = is_array($result['raw'] ?? null) ? ($result['raw']['usage'] ?? []) : [];
        $tokensIn  = $agent['provider'] === 'openai' ? (int) ($usage['prompt_tokens'] ?? 0) : (int) ($usage['input_tokens'] ?? 0);
        $tokensOut = $agent['provider'] === 'openai' ? (int) ($usage['completion_tokens'] ?? 0) : (int) ($usage['output_tokens'] ?? 0);

        if (!$result['success'] || !is_array($parsed) || !isset($parsed['recommended_meta_title'], $parsed['recommended_meta_description'])) {
            $stats['errors']++;
            $stats['job_ids'][] = cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                ($result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']))
                    . ($retried ? ' (after 1 retry)' : ''),
                $priority
            );
            continue;
        }

        $recommendedMetaTitle = mb_substr(trim((string) $parsed['recommended_meta_title']), 0, 255);
        $recommendedMetaDescription = mb_substr(trim((string) $parsed['recommended_meta_description']), 0, 255);

        if ($recommendedMetaTitle === '' || $recommendedMetaDescription === '') {
            $stats['errors']++;
            $stats['job_ids'][] = cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                'AI returned an empty recommendation' . ($retried ? ' (after 1 retry)' : ''),
                $priority
            );
            continue;
        }

        $output = [
            'current_meta_title' => $currentMetaTitle,
            'current_meta_description' => $currentMetaDescription,
            'recommended_meta_title' => $recommendedMetaTitle,
            'recommended_meta_description' => $recommendedMetaDescription,
        ];

        $stats['job_ids'][] = cms_growth_agent_log_job(
            $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'manual_action', $inputBrief, $output,
            $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
            '', $priority
        );
        $stats['created']++;
    }

    return $stats;
}

/**
 * Type 2 ("Page-one"/striking-distance category, and — since ROADMAP.md
 * gap #5, 28 Jul 2026 — "Content Decay" too) — existing article, content
 * optimization candidate. Generates ONE job for ONE page, called on-demand
 * when the operator clicks "Generate" on a Prioritized Opportunities row —
 * candidate selection/scoring already happened in
 * cms_gsc_compute_opportunities() (gsc-api.php), this function only does
 * the AI call + log. Does NOT write anywhere itself — produces suggested
 * content additions the human copy/pastes in manually, so it uses the
 * generic Approve/Reject flow on pages/growth-agent.php (status goes
 * straight to succeeded/failed, no 'manual_action' apply step, same as
 * article_draft/faq generation).
 *
 * $page['is_decay'] (+ prev_clicks/cur_clicks/prev_impressions/
 * cur_impressions/pct_change_clicks/comparison_window_days) switches to a
 * distinct system prompt: a declining article needs "what changed / is
 * this stale" framing (refresh existing content, check outdated info),
 * not "hasn't broken into page one yet" framing (add more depth) — same
 * evidence-based reason as gsc-api.php's cms_gsc_build_opportunity_reason()
 * distinguishing the two categories' wording.
 *
 * @param array{page_id:int|string, title:string, slug:string, excerpt:string, content:string, avg_position:float|int, impressions:int, top_queries:string, is_decay?:bool, prev_clicks?:int, cur_clicks?:int, prev_impressions?:int, cur_impressions?:int, pct_change_clicks?:float, comparison_window_days?:int} $page
 * @return array{ok:bool, job_id:int, error:string}
 */
function cms_growth_agent_generate_content_optimization(PDO $pdo, array $page, string $priority = 'medium'): array
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $pageId = (int) $page['page_id'];
    $impressions = (int) ($page['impressions'] ?? 0);
    $avgPosition = (float) ($page['avg_position'] ?? 0);
    $topQueries = (string) ($page['top_queries'] ?? '');
    $isDecay = !empty($page['is_decay']);

    $defaultSystemPrompt = $isDecay
        ? ('You are the Growth Agent content strategist for Sagagoal, a livescore & sports news website ' .
            '(football, basketball/NBA, Formula 1). You are given an existing PUBLISHED article that USED ' .
            'TO perform well in Google Search but has recently DECLINED — clicks/impressions dropped ' .
            'significantly versus a comparable earlier period. This is NOT the same situation as an article ' .
            'that simply never broke into page one — this one already worked before, so the priority is ' .
            'figuring out what might have gone stale, not just adding more depth. Given the article title, ' .
            'slug, excerpt, content, and the decline evidence (previous vs current clicks/impressions), ' .
            'suggest concrete refresh actions — content/statistics that may be outdated and need updating, ' .
            'whether the article still matches current search intent for its queries, sections that may ' .
            'need rewriting for freshness (e.g. "musim panas 2026" dates, transfer rumors, standings that ' .
            'have since changed). Do not suggest changing the meta title/description (a separate tool ' .
            'handles that). Respond in the same language as the article content (default Bahasa Indonesia). ' .
            'Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
            'this shape: {"suggested_sections": ["...", "..."], "summary": "..."}')
        : ('You are the Growth Agent content strategist for Sagagoal, a livescore & sports news website ' .
            '(football, basketball/NBA, Formula 1). You are given an existing PUBLISHED article that already ' .
            'ranks close to page one for certain search queries but has not broken into the top 10 yet ' .
            '("striking distance"). Given the article title, slug, excerpt, content, its average ranking ' .
            'position, and the queries it ranks for, suggest concrete content improvements — additional ' .
            'sections or subheadings to add, points to expand, related sub-topics to cover — that would ' .
            'plausibly help it rank higher for those specific queries. Do not suggest changing the meta ' .
            'title/description (a separate tool handles that). Respond in the same language as the article ' .
            'content (default Bahasa Indonesia). Respond with ONLY a raw JSON object, no markdown, no code ' .
            'fences, no commentary, in exactly this shape: {"suggested_sections": ["...", "..."], "summary": "..."}');

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'job_id' => 0, 'error' => $agent['error']];
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'gsc_content_optimization'));
    } catch (Throwable $e) {
        // Ignore — generation proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    $inputBrief = [
        'title' => (string) $page['title'],
        'slug' => (string) $page['slug'],
        'avg_position' => round($avgPosition, 1),
        'gsc_impressions' => $impressions,
        'top_queries' => $topQueries,
    ];

    if ($isDecay) {
        $prevClicks = (int) ($page['prev_clicks'] ?? 0);
        $curClicks = (int) ($page['cur_clicks'] ?? 0);
        $prevImpressions = (int) ($page['prev_impressions'] ?? 0);
        $curImpressions = (int) ($page['cur_impressions'] ?? 0);
        $declinePct = round(abs((float) ($page['pct_change_clicks'] ?? 0)) * 100, 1);
        $windowDays = (int) ($page['comparison_window_days'] ?? 28);

        $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
            "Performance decline over the last {$windowDays} days vs the {$windowDays} days before that:\n" .
            "  Clicks: {$prevClicks} -> {$curClicks} ({$declinePct}% decline)\n" .
            "  Impressions: {$prevImpressions} -> {$curImpressions}\n" .
            "Ranks for these queries: {$topQueries}\n" .
            "Content:\n" . mb_substr((string) $page['content'], 0, 6000);

        $inputBrief['decay_prev_clicks'] = $prevClicks;
        $inputBrief['decay_cur_clicks'] = $curClicks;
        $inputBrief['decay_prev_impressions'] = $prevImpressions;
        $inputBrief['decay_cur_impressions'] = $curImpressions;
        $inputBrief['decay_pct_change_clicks'] = round((float) ($page['pct_change_clicks'] ?? 0), 4);
        $inputBrief['comparison_window_days'] = $windowDays;
    } else {
        $userPrompt = "Title: {$page['title']}\nSlug: {$page['slug']}\nExcerpt: {$page['excerpt']}\n" .
            "Average ranking position: " . round($avgPosition, 1) . "\n" .
            "Total impressions (recent window): {$impressions}\n" .
            "Ranks for these queries: {$topQueries}\n" .
            "Content:\n" . mb_substr((string) $page['content'], 0, 6000);
    }

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $systemPrompt, max($agent['max_tokens'], 400), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;

    if (!$result['success'] || !is_array($parsed) || !isset($parsed['suggested_sections'])) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        $jobId = cms_growth_agent_log_job(
            $pdo, 'gsc_content_optimization', 'growth_agent', $pageId, 'failed', $inputBrief, null,
            $agent['model'], null, null, $result['latency_ms'] ?? null, $errorMessage, $priority
        );
        return ['ok' => false, 'job_id' => $jobId, 'error' => $errorMessage];
    }

    $jobId = cms_growth_agent_log_job(
        $pdo, 'gsc_content_optimization', 'growth_agent', $pageId, 'succeeded', $inputBrief, $parsed,
        $agent['model'], null, null, $result['latency_ms'] ?? null, '', $priority
    );

    return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
}

/**
 * Type 3 ("No article" category) — new article idea candidate. Generates
 * ONE job for ONE query, called on-demand when the operator clicks
 * "Generate" on a Prioritized Opportunities row — candidate selection/
 * scoring (including the "already suggested this query before" exclusion)
 * already happened in cms_gsc_compute_opportunities() (gsc-api.php), this
 * function only does the AI call + log. Also flows through the generic
 * Approve/Reject queue on pages/growth-agent.php, same as Type 2.
 *
 * @param array{query:string, impressions:int, avg_position:float|int} $queryData
 * @return array{ok:bool, job_id:int, error:string}
 */
function cms_growth_agent_generate_article_idea(PDO $pdo, array $queryData, string $priority = 'medium'): array
{
    try {
        require_once __DIR__ . '/ai-helpers.php';
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $query = (string) $queryData['query'];
    $impressions = (int) ($queryData['impressions'] ?? 0);
    $avgPosition = (float) ($queryData['avg_position'] ?? 0);

    $defaultSystemPrompt =
        'You are the Growth Agent content strategist for Sagagoal, a livescore & sports news website ' .
        '(football, basketball/NBA, Formula 1). You are given a search query that gets meaningful search ' .
        'impressions but has NO existing article on the site addressing it. Propose a new article idea: a ' .
        'compelling title, and a short outline (3-6 bullet points) covering what the article should ' .
        'include. Keep it realistic for a sports news/livescore site — not a generic listicle. Respond in ' .
        'the same language as the query (default Bahasa Indonesia). Respond with ONLY a raw JSON object, ' .
        'no markdown, no code fences, no commentary, in exactly this shape: {"title": "...", "outline": ["...", "..."]}';

    $agent = cms_ai_resolve_agent($pdo, 'growth_agent', $defaultSystemPrompt);
    if (!$agent['ok']) {
        return ['ok' => false, 'job_id' => 0, 'error' => $agent['error']];
    }

    $growthContext = '';
    try {
        require_once dirname(__DIR__, 2) . '/services/GrowthAgentPromptBuilder.php';
        $growthContext = trim((new GrowthAgentPromptBuilder($pdo))->buildContext('growth_agent', 'gsc_article_idea'));
    } catch (Throwable $e) {
        // Ignore — generation proceeds on the agent's own system prompt.
    }
    $systemPrompt = $growthContext !== ''
        ? trim($agent['system_prompt'] . "\n\n" . $growthContext)
        : $agent['system_prompt'];

    $userPrompt = "Search query: {$query}\n" .
        "Total impressions (recent window): {$impressions}\n" .
        "Average position: " . round($avgPosition, 1);

    // SEO-G0 Gate (GROWTH_AGENT_V2_PROPOSAL.md Fase A item 3) — run against
    // the raw query BEFORE the AI call, since the gate exists to catch
    // overlap in the underlying topic itself, not in whatever title the AI
    // happens to phrase. Advisory only: never affects whether generation
    // proceeds, just rides along in input_brief for the operator's review.
    $gateResult = cms_growth_agent_seo_g0_gate($pdo, 'gsc_article_idea', $query);

    $inputBrief = [
        'query' => $query,
        'gsc_impressions' => $impressions,
        'avg_position' => round($avgPosition, 1),
        'seo_g0_gate' => $gateResult,
    ];

    try {
        $result = cms_ai_call_provider(
            $agent['provider'], $agent['api_key'], $agent['model'],
            $userPrompt, $systemPrompt, max($agent['max_tokens'], 400), $agent['temperature']
        );
    } catch (Throwable $e) {
        return ['ok' => false, 'job_id' => 0, 'error' => $e->getMessage()];
    }

    $parsed = $result['success'] ? cms_ai_extract_json($result['text']) : null;

    if (!$result['success'] || !is_array($parsed) || !isset($parsed['title'], $parsed['outline'])) {
        $errorMessage = $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error']);
        $jobId = cms_growth_agent_log_job(
            $pdo, 'gsc_article_idea', 'growth_agent', null, 'failed', $inputBrief, null,
            $agent['model'], null, null, $result['latency_ms'] ?? null, $errorMessage, $priority
        );
        return ['ok' => false, 'job_id' => $jobId, 'error' => $errorMessage];
    }

    $jobId = cms_growth_agent_log_job(
        $pdo, 'gsc_article_idea', 'growth_agent', null, 'succeeded', $inputBrief, $parsed,
        $agent['model'], null, null, $result['latency_ms'] ?? null, '', $priority
    );

    return ['ok' => true, 'job_id' => $jobId, 'error' => ''];
}

/**
 * Content Agent Adapter for `gsc_article_idea` (GROWTH_AGENT_SEO_ROADMAP.md
 * MVP item #5 / ROADMAP.md gap #1, closed 27 Jul 2026). Turns an *approved*
 * gsc_article_idea job's {"title", "outline"} output into a real draft row
 * in `pages` — before this, Approve only flipped the job to 'succeeded'
 * with no article ever created, leaving the operator to copy-paste the
 * idea into Content Agent (article-generate.php) by hand.
 *
 * For every OTHER job type, "approve" is deliberately NOT "execute" (see
 * the roadmap's own guardrail: "Approve tidak sama dengan Execute"). This
 * job type is the one deliberate exception: there is nothing else an
 * approved article idea can become except a draft, so approve IS the
 * execution step here — but it still only ever produces a `draft`, never
 * `published` ("artikel baru selalu draft" — same guardrail, still
 * enforced). Full-article generation stays a manual, separate step: the
 * operator opens the resulting draft and runs the existing Content Agent
 * (article-generate.php) on it if they want AI to flesh out the body —
 * this function only writes a placeholder outline, not prose.
 *
 * Never throws, matching this file's own convention (e.g.
 * cms_growth_agent_log_job()) — the caller (growth-agent.php's approve
 * handler) is responsible for reflecting a failure as the job's `failed`
 * status + error_message rather than silently leaving it looking approved
 * with no draft to show for it.
 */
function cms_growth_agent_create_article_draft_from_idea(PDO $pdo, array $job, ?int $authorId): array
{
    try {
        $output = json_decode((string) ($job['output_json'] ?? ''), true);
        $title = is_array($output) ? trim((string) ($output['title'] ?? '')) : '';
        if ($title === '') {
            return ['ok' => false, 'page_id' => 0, 'error' => 'Job output tidak berisi title yang valid.'];
        }
        $outline = is_array($output['outline'] ?? null) ? $output['outline'] : [];

        require_once __DIR__ . '/functions.php';
        require_once __DIR__ . '/sitemap-service.php';

        // Same slugify + dedupe-by-suffix approach as pages.php's own
        // duplicate-slug check, just applied proactively here instead of
        // rejecting — there's no form round-trip to show a "slug already
        // in use" error to, so this picks the next free "-2"/"-3" suffix.
        $slugBase = cms_slugify($title);
        if ($slugBase === '') {
            $slugBase = 'ide-artikel-' . (int) $job['id'];
        }
        $slug = $slugBase;
        $dupCheck = $pdo->prepare('SELECT COUNT(*) FROM pages WHERE slug = :slug');
        for ($suffix = 2; ; $suffix++) {
            $dupCheck->execute(['slug' => $slug]);
            if ((int) $dupCheck->fetchColumn() === 0) {
                break;
            }
            $slug = $slugBase . '-' . $suffix;
        }

        // Placeholder outline, not a full article — one <h2> per bullet
        // with a stub <p> underneath, using only the tag set
        // article-generate.php's own Content Agent already restricts
        // itself to (<p>, <h2>, <h3>, <ul>, <li>, <strong>, <em>), so this
        // reads consistently whether or not the operator later re-runs
        // Content Agent on top of it.
        $contentHtml = '<p><em>Draft dibuat otomatis oleh Growth Agent dari ide artikel berbasis GSC search query — lengkapi tiap bagian di bawah sebelum publish.</em></p>';
        foreach ($outline as $point) {
            $point = trim((string) $point);
            if ($point === '') {
                continue;
            }
            $contentHtml .= '<h2>' . htmlspecialchars($point, ENT_QUOTES, 'UTF-8') . '</h2><p>[Tulis konten untuk bagian ini]</p>';
        }

        // category_id/league_id/sport_key deliberately left null — this
        // job type has no known sport/category association (it comes from
        // a raw search query, not an existing article), and guessing one
        // would violate the roadmap's "Growth Agent hanya mereferensikan
        // evidence yang diberikan" principle. The operator sets these
        // during their required review-before-publish pass.
        $payload = [
            'title'     => $title,
            'slug'      => $slug,
            'content'   => $contentHtml,
            'status'    => 'draft',
            'author_id' => $authorId,
        ];

        $insert = $pdo->prepare(
            'INSERT INTO pages (title, slug, content, status, author_id, created_at, updated_at)
             VALUES (:title, :slug, :content, :status, :author_id, NOW(), NOW())'
        );
        $insert->execute($payload);
        $pageId = (int) $pdo->lastInsertId();

        try {
            cms_sitemap_ensure_schema($pdo);
            cms_sitemap_on_article_save($pdo, [], $payload + [
                'page_id'       => $pageId,
                'noindex'       => 0,
                'canonical_url' => null,
                'published_at'  => null,
            ]);
        } catch (Throwable $e) {
            // Sitemap bookkeeping is best-effort here — a failure in it must
            // never undo an already-created draft (cms_sitemap_upsert()
            // failing shouldn't make the operator lose their new article).
            error_log('[cms_growth_agent_create_article_draft_from_idea] Sitemap upsert failed: ' . $e->getMessage());
        }

        return ['ok' => true, 'page_id' => $pageId, 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'page_id' => 0, 'error' => $e->getMessage()];
    }
}

/**
 * Indexing Workflow (Phase 5 roadmap, ROADMAP.md gap #2, closed 27 Jul
 * 2026) — pure pattern-matching against Search Console's own verdict
 * fields, NOT an LLM call. Same "deterministic code, not AI" principle as
 * cms_gsc_compute_opportunities() (gsc-api.php): the checklist only ever
 * lists causes the API's own enums already imply, it never invents a
 * diagnosis. Matches the checklist categories named in
 * GROWTH_AGENT_SEO_ROADMAP.md Phase 5: robots, noindex, canonical,
 * redirect, soft 404, orphan page, thin/duplicate content.
 *
 * @param array{verdict?:string,coverage_state?:string,robots_txt_state?:string,indexing_state?:string,page_fetch_state?:string,google_canonical?:string,user_canonical?:string,sitemap?:?string} $inspection
 * @return list<string>
 */
function cms_growth_agent_build_indexing_checklist(array $inspection): array
{
    $checklist = [];

    if (strtoupper((string) ($inspection['robots_txt_state'] ?? '')) === 'DISALLOWED') {
        $checklist[] = 'Diblokir oleh robots.txt — cek aturan disallow untuk path ini.';
    }

    $indexingState = strtoupper((string) ($inspection['indexing_state'] ?? ''));
    if ($indexingState !== '' && $indexingState !== 'INDEXING_ALLOWED') {
        $checklist[] = 'Halaman diblokir dari indexing (kemungkinan noindex meta tag/header) — indexingState: ' . $inspection['indexing_state'];
    }

    $pageFetchState = strtoupper((string) ($inspection['page_fetch_state'] ?? ''));
    if (str_contains($pageFetchState, 'SOFT_404') || str_contains($pageFetchState, 'NOT_FOUND')) {
        $checklist[] = 'Terindikasi soft 404 / halaman tidak ditemukan saat crawl — cek konten & status HTTP.';
    } elseif ($pageFetchState !== '' && $pageFetchState !== 'SUCCESSFUL') {
        $checklist[] = 'Google gagal fetch halaman ini (pageFetchState: ' . $inspection['page_fetch_state'] . ') — cek redirect/error server.';
    }

    $googleCanonical = trim((string) ($inspection['google_canonical'] ?? ''));
    $userCanonical = trim((string) ($inspection['user_canonical'] ?? ''));
    if ($googleCanonical !== '' && $userCanonical !== '' && rtrim($googleCanonical, '/') !== rtrim($userCanonical, '/')) {
        $checklist[] = 'Google memilih canonical berbeda dari yang di-declare (kemungkinan thin/duplicate content) — Google: ' . $googleCanonical . ', Declared: ' . $userCanonical;
    }

    $coverageState = strtolower((string) ($inspection['coverage_state'] ?? ''));
    if (str_contains($coverageState, 'duplicate')) {
        $checklist[] = 'Coverage state menyebutkan duplicate content: "' . $inspection['coverage_state'] . '".';
    }
    if (str_contains($coverageState, 'redirect')) {
        $checklist[] = 'Coverage state menyebutkan redirect: "' . $inspection['coverage_state'] . '" — pastikan ini redirect yang disengaja.';
    }
    if (str_contains($coverageState, 'crawled') && str_contains($coverageState, 'not indexed')) {
        $checklist[] = 'Sudah di-crawl tapi belum diindeks — kualitas/relevansi konten mungkin jadi faktor.';
    }

    if (empty($inspection['sitemap'])) {
        $checklist[] = 'URL tidak ditemukan lewat sitemap manapun menurut Google — cek apakah halaman ini orphan (tidak ada internal link yang crawlable).';
    }

    if ($checklist === []) {
        $checklist[] = 'Verdict: ' . ($inspection['verdict'] ?? 'UNKNOWN') . ' — tidak ada pola penyebab spesifik yang terdeteksi dari data verdict, cek detail lengkap secara manual.';
    }

    return $checklist;
}

/**
 * Whether an inspection result is worth surfacing as a
 * 'review_indexing_issue' job — anything short of a clean PASS verdict,
 * plus a few coverage_state substrings that can still hide a problem
 * under a technically-passing verdict.
 */
function cms_growth_agent_indexing_issue_needs_review(array $inspection): bool
{
    $verdict = strtoupper((string) ($inspection['verdict'] ?? ''));
    if ($verdict !== '' && $verdict !== 'PASS') {
        return true;
    }

    $coverageState = strtolower((string) ($inspection['coverage_state'] ?? ''));
    foreach (['duplicate', 'not indexed', 'redirect', 'not found', 'excluded'] as $needle) {
        if (str_contains($coverageState, $needle)) {
            return true;
        }
    }

    return false;
}

/** Deterministic priority from verdict severity — FAIL is worse than NEUTRAL/PARTIAL. */
function cms_growth_agent_indexing_issue_priority(array $inspection): string
{
    $verdict = strtoupper((string) ($inspection['verdict'] ?? ''));
    if ($verdict === 'FAIL') {
        return 'high';
    }
    if ($verdict === 'NEUTRAL' || $verdict === 'PARTIAL') {
        return 'medium';
    }

    return 'low';
}

/**
 * Logs a deterministic 'review_indexing_issue' job for one page, given an
 * already-fetched inspection result (cms_gsc_inspect_url()'s $data).
 * agent_key is 'gsc_indexing' rather than a real ai_agent_settings key —
 * that column is display-only in this codebase (rendered as plain
 * <code>text</code> on growth-agent.php, never looked up), and there is no
 * AI call behind this job type to attribute to a real agent.
 *
 * output_json is ONLY the checklist + raw verdict fields — never a
 * suggestion to rewrite or republish the article. Per
 * GROWTH_AGENT_SEO_ROADMAP.md Phase 5's own guardrail, deciding what (if
 * anything) to fix is entirely the operator's manual call.
 *
 * Dedup: skips creating a new job if there's already an unresolved
 * (status='manual_action') review_indexing_issue job for this exact
 * page_id — otherwise every re-inspection of a still-broken URL would
 * spam a fresh job every time the batch button is clicked. Returns the
 * existing job's id in that case instead of 0, so callers can still treat
 * it as "there is a job to look at" without double-counting it as new.
 *
 * Never throws.
 */
function cms_growth_agent_log_indexing_issue(PDO $pdo, int $pageId, string $url, array $inspection): int
{
    try {
        $existing = $pdo->prepare(
            "SELECT id FROM growth_agent_jobs WHERE job_type = 'review_indexing_issue' AND page_id = :page_id AND status = 'manual_action' LIMIT 1"
        );
        $existing->execute(['page_id' => $pageId]);
        $existingId = $existing->fetchColumn();
        if ($existingId !== false) {
            return (int) $existingId;
        }

        $checklist = cms_growth_agent_build_indexing_checklist($inspection);
        $priority = cms_growth_agent_indexing_issue_priority($inspection);

        $inputBrief = [
            'url' => $url,
            'verdict' => (string) ($inspection['verdict'] ?? ''),
            'coverage_state' => (string) ($inspection['coverage_state'] ?? ''),
        ];
        $output = ['checklist' => $checklist, 'inspection' => $inspection];

        return cms_growth_agent_log_job(
            $pdo, 'review_indexing_issue', 'gsc_indexing', $pageId, 'manual_action', $inputBrief, $output,
            null, null, null, null, '', $priority
        );
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_log_indexing_issue] Failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Logs a deterministic 'cannibalization_review' job — surfaces one
 * cannibalized query + its competing pages/shares for an operator to
 * review. ROADMAP.md gap #5, closed 28 Jul 2026. No AI involved anywhere
 * in this function: deciding whether to differentiate intent,
 * consolidate content, or pick a pillar page is a judgment call this
 * codebase deliberately never routes to AI (see
 * cms_gsc_ensure_cannibalization_action()'s own note in gsc-api.php).
 * agent_key is 'manual_review' — same "display-only, not a real
 * ai_agent_settings key" convention as review_indexing_issue's
 * 'gsc_indexing'.
 *
 * page_id is intentionally NULL — a cannibalization opportunity spans
 * 2+ pages, so there's no single page to attribute the job to; the full
 * list lives in output_json instead.
 *
 * Dedup: growth_agent_jobs has no dedicated "query" column to index on
 * (unlike cms_growth_agent_log_indexing_issue()'s page_id), so this reads
 * the small set of currently-unresolved cannibalization_review jobs and
 * compares input_brief's decoded 'query' field in PHP rather than
 * string-matching raw JSON in SQL — correct regardless of how the JSON
 * happens to be escaped, and cheap since there should only ever be a
 * handful of open cannibalization reviews at a time.
 *
 * Never throws.
 *
 * @param list<array{page_id:int,title:string,clicks:int,impressions:int,share:float}> $competingPages
 */
function cms_growth_agent_log_cannibalization_review(PDO $pdo, string $queryText, array $competingPages, int $totalClicks, int $totalImpressions, string $priority = 'medium'): int
{
    try {
        $existingStmt = $pdo->query(
            "SELECT id, input_brief FROM growth_agent_jobs WHERE job_type = 'cannibalization_review' AND status = 'manual_action'"
        );
        foreach ($existingStmt->fetchAll() as $existingRow) {
            $existingBrief = json_decode((string) ($existingRow['input_brief'] ?? ''), true);
            if (is_array($existingBrief) && ($existingBrief['query'] ?? null) === $queryText) {
                return (int) $existingRow['id'];
            }
        }

        $inputBrief = [
            'query' => $queryText,
            'total_clicks' => $totalClicks,
            'total_impressions' => $totalImpressions,
            'page_count' => count($competingPages),
        ];
        $output = ['competing_pages' => $competingPages];

        return cms_growth_agent_log_job(
            $pdo, 'cannibalization_review', 'manual_review', null, 'manual_action', $inputBrief, $output,
            null, null, null, null, '', $priority
        );
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_log_cannibalization_review] Failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Manual batch trigger — "Inspect prioritas" button on growth-agent.php.
 * No cron in this codebase (see cms_growth_agent_cleanup_old_jobs()'s own
 * note on that), so URL Inspection only ever runs when an operator clicks
 * a button — either this batch entry point or a per-article single
 * inspect action on growth-agent.php.
 *
 * Candidate selection (both sources feed the same $limit, combined and
 * deduped — matches the "belum pernah diinspeksi ATAU terkait opportunity
 * open+high" wording in ROADMAP.md gap #2):
 *   - published pages linked to an OPEN, HIGH-priority gsc_opportunities
 *     row (already flagged as worth attention elsewhere in Growth Agent);
 *   - published pages never inspected yet, or with the oldest
 *     inspected_at (round-robin coverage over time so nothing gets stuck
 *     unchecked forever).
 *
 * Never throws — one URL's inspection failing (bad token, network) does
 * not stop the rest of the batch; cms_gsc_inspect_url() itself never
 * throws either, so this is mostly defensive.
 *
 * @return array{inspected:int, issues_found:int, errors:int}
 */
function cms_growth_agent_inspect_priority_urls(PDO $pdo, int $limit = 10): array
{
    $stats = ['inspected' => 0, 'issues_found' => 0, 'errors' => 0];
    $limit = max(1, min(50, $limit));

    try {
        require_once __DIR__ . '/gsc-api.php';
        cms_gsc_ensure_schema($pdo);

        $pageIds = [];

        $oppStmt = $pdo->query(
            "SELECT DISTINCT o.matched_page_id AS page_id
               FROM gsc_opportunities o
               JOIN pages p ON p.page_id = o.matched_page_id
              WHERE o.status = 'open' AND o.priority = 'high' AND p.status = 'published'
              ORDER BY o.computed_at DESC
              LIMIT " . $limit
        );
        foreach ($oppStmt->fetchAll() as $row) {
            $pageIds[] = (int) $row['page_id'];
        }

        if (count($pageIds) < $limit) {
            $remaining = $limit - count($pageIds);
            $placeholders = $pageIds !== [] ? implode(',', array_fill(0, count($pageIds), '?')) : null;
            $sql = "SELECT p.page_id
                      FROM pages p
                      LEFT JOIN gsc_url_inspections i ON i.page_id = p.page_id
                     WHERE p.status = 'published'"
                 . ($placeholders !== null ? " AND p.page_id NOT IN ({$placeholders})" : '')
                 . ' ORDER BY (i.inspected_at IS NULL) DESC, i.inspected_at ASC
                     LIMIT ' . $remaining;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($pageIds);
            foreach ($stmt->fetchAll() as $row) {
                $pageIds[] = (int) $row['page_id'];
            }
        }

        $pageIds = array_slice(array_values(array_unique($pageIds)), 0, $limit);
    } catch (Throwable $e) {
        return $stats;
    }

    if ($pageIds === []) {
        return $stats;
    }

    try {
        require_once __DIR__ . '/sitemap-service.php';
    } catch (Throwable $e) {
        return $stats;
    }

    foreach ($pageIds as $pageId) {
        try {
            $pageStmt = $pdo->prepare('SELECT page_id, slug, canonical_url FROM pages WHERE page_id = :id LIMIT 1');
            $pageStmt->execute(['id' => $pageId]);
            $page = $pageStmt->fetch();
            if (!$page) {
                continue;
            }
            $canonical = trim((string) ($page['canonical_url'] ?? ''));
            $url = $canonical !== '' ? $canonical : cms_sitemap_absolute_url(cms_sitemap_path_for('article', (string) $page['slug']));

            $result = cms_gsc_inspect_url($pdo, $url, $pageId);
            $stats['inspected']++;
            if (!$result['ok']) {
                $stats['errors']++;
                continue;
            }

            if (cms_growth_agent_indexing_issue_needs_review($result['data'])) {
                $jobId = cms_growth_agent_log_indexing_issue($pdo, $pageId, $url, $result['data']);
                if ($jobId > 0) {
                    $stats['issues_found']++;
                }
            }
        } catch (Throwable $e) {
            $stats['errors']++;
        }
    }

    return $stats;
}

/**
 * Agent Memory (ROADMAP.md gap #3, GROWTH_AGENT_SEO_ROADMAP.md § Growth
 * memory, closed 28 Jul 2026) — deterministic (NOT AI) detection of
 * historical patterns from gsc_query_data, upserted into
 * growth_agent_memory as ADVISORY context only. Per the roadmap's own
 * explicit guardrail — "memory hanya menjadi advisory context bagi Growth
 * Agent; memory tidak boleh membuat, approve, atau execute action
 * sendiri" — this function (and everything downstream of it,
 * GrowthAgentPromptBuilder::buildMemoryContext()) never creates a
 * growth_agent_jobs row, never touches gsc_opportunities, and never
 * writes to `pages`. The only table this function writes to is
 * growth_agent_memory itself.
 *
 * Two pattern types (both use cms_gsc_get_memory_thresholds() — no new
 * thresholds invented for this):
 *   - winning_pattern (scope 'page' OR 'query'): >= min_distinct_weeks
 *     distinct ISO weeks with avg CTR >= winning_ctr_threshold AND avg
 *     position <= winning_position_threshold AND total impressions >=
 *     min_impressions. 'page' scope only considers rows already matched
 *     to a published article; 'query' scope aggregates a query across ALL
 *     its rows regardless of match, so a query can independently be both
 *     a winning_pattern AND a content_gap — that's not a contradiction,
 *     it means the topic performs well in search even without a
 *     dedicated page yet.
 *   - content_gap (scope 'query' only): a query recurring across
 *     >= min_distinct_weeks distinct weeks with total impressions >=
 *     min_impressions but NEVER matched to any page. Deliberately
 *     different from gsc_opportunities' one-off "No article" category
 *     (cms_gsc_compute_opportunities(), reacts to the current fetch
 *     window only) — this only fires once the SAME gap has been observed
 *     persistently over multiple detection runs.
 *
 * Promotion (ON DUPLICATE KEY UPDATE on dedupe_key, same md5-hash upsert
 * convention as gsc_opportunities):
 *   - not seen before → inserted as 'pending_review'.
 *   - already 'pending_review' → promoted to 'active' (redetecting the
 *     same dedupe_key means it's still consistent, since this whole
 *     function only ever runs once per detection_interval_days via
 *     cms_growth_agent_detect_memory_if_stale()).
 *   - already 'active' → stays 'active', evidence/last_confirmed_at refreshed.
 *   - already 'stale' → drops back to 'pending_review' rather than
 *     jumping straight to 'active' — a lapsed pattern has to re-earn
 *     confirmation, not resume where it left off.
 *
 * Housekeeping (runs every time this function runs, not a separate lazy
 * gate): 'active' rows not reconfirmed within active_stale_days, or
 * 'pending_review' rows not reconfirmed within pending_review_stale_days,
 * flip to 'stale' — never deleted, same "keep as history" convention as
 * closed_as_legacy elsewhere in this file.
 *
 * Never throws.
 *
 * @return array{winning_patterns:int, content_gaps:int, staled:int}
 */
function cms_growth_agent_detect_memory_patterns(PDO $pdo): array
{
    $stats = ['winning_patterns' => 0, 'content_gaps' => 0, 'staled' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);
        require_once __DIR__ . '/gsc-api.php';
        $thresholds = cms_gsc_get_memory_thresholds($pdo);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_detect_memory_patterns] Setup failed: ' . $e->getMessage());
        return $stats;
    }

    $minWeeks = max(1, (int) $thresholds['min_distinct_weeks']);
    $minImpressions = max(0, (int) $thresholds['min_impressions']);
    $winningCtr = (float) $thresholds['winning_ctr_threshold'];
    $winningPosition = (float) $thresholds['winning_position_threshold'];

    $upsert = $pdo->prepare(
        "INSERT INTO growth_agent_memory
            (pattern_type, scope_type, matched_page_id, query_text, status, evidence_json, distinct_weeks_seen,
             dedupe_key, first_detected_at, last_confirmed_at, created_at, updated_at)
         VALUES
            (:pattern_type, :scope_type, :matched_page_id, :query_text, 'pending_review', :evidence_json, :distinct_weeks_seen,
             :dedupe_key, NOW(), NOW(), NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            evidence_json = VALUES(evidence_json),
            distinct_weeks_seen = VALUES(distinct_weeks_seen),
            last_confirmed_at = NOW(),
            status = CASE
                        WHEN status = 'pending_review' THEN 'active'
                        WHEN status = 'stale' THEN 'pending_review'
                        ELSE status
                     END,
            updated_at = NOW()"
    );

    // ── winning_pattern / scope=page ──────────────────────────────────
    try {
        $pageStmt = $pdo->prepare(
            "SELECT g.matched_page_id AS page_id,
                    COUNT(DISTINCT YEARWEEK(g.data_date, 3)) AS distinct_weeks,
                    SUM(g.impressions) AS total_impressions,
                    SUM(g.clicks) AS total_clicks,
                    AVG(g.position) AS avg_position
               FROM gsc_query_data g
               INNER JOIN pages p ON p.page_id = g.matched_page_id
              WHERE g.matched_page_id IS NOT NULL AND p.status = 'published'
              GROUP BY g.matched_page_id
             HAVING distinct_weeks >= :min_weeks AND total_impressions >= :min_impressions"
        );
        $pageStmt->execute(['min_weeks' => $minWeeks, 'min_impressions' => $minImpressions]);
        $pageRows = $pageStmt->fetchAll();
    } catch (Throwable $e) {
        $pageRows = [];
    }

    foreach ($pageRows as $row) {
        $impressions = (int) $row['total_impressions'];
        $ctr = $impressions > 0 ? ((int) $row['total_clicks'] / $impressions) : 0.0;
        $position = (float) $row['avg_position'];
        if ($ctr < $winningCtr || $position > $winningPosition) {
            continue;
        }

        $pageId = (int) $row['page_id'];
        $weeksSeen = (int) $row['distinct_weeks'];
        $evidence = [
            'avg_ctr' => round($ctr, 4),
            'avg_position' => round($position, 1),
            'total_impressions' => $impressions,
            'distinct_weeks_seen' => $weeksSeen,
        ];

        $upsert->execute([
            'pattern_type' => 'winning_pattern',
            'scope_type' => 'page',
            'matched_page_id' => $pageId,
            'query_text' => null,
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'distinct_weeks_seen' => $weeksSeen,
            'dedupe_key' => md5('winning_pattern|page|' . $pageId),
        ]);
        $stats['winning_patterns']++;
    }

    // ── winning_pattern / scope=query ──────────────────────────────────
    try {
        $queryStmt = $pdo->prepare(
            "SELECT query,
                    COUNT(DISTINCT YEARWEEK(data_date, 3)) AS distinct_weeks,
                    SUM(impressions) AS total_impressions,
                    SUM(clicks) AS total_clicks,
                    AVG(position) AS avg_position
               FROM gsc_query_data
              GROUP BY query
             HAVING distinct_weeks >= :min_weeks AND total_impressions >= :min_impressions"
        );
        $queryStmt->execute(['min_weeks' => $minWeeks, 'min_impressions' => $minImpressions]);
        $queryRows = $queryStmt->fetchAll();
    } catch (Throwable $e) {
        $queryRows = [];
    }

    foreach ($queryRows as $row) {
        $impressions = (int) $row['total_impressions'];
        $ctr = $impressions > 0 ? ((int) $row['total_clicks'] / $impressions) : 0.0;
        $position = (float) $row['avg_position'];
        if ($ctr < $winningCtr || $position > $winningPosition) {
            continue;
        }

        $queryText = mb_substr((string) $row['query'], 0, 255);
        $weeksSeen = (int) $row['distinct_weeks'];
        $evidence = [
            'avg_ctr' => round($ctr, 4),
            'avg_position' => round($position, 1),
            'total_impressions' => $impressions,
            'distinct_weeks_seen' => $weeksSeen,
        ];

        $upsert->execute([
            'pattern_type' => 'winning_pattern',
            'scope_type' => 'query',
            'matched_page_id' => null,
            'query_text' => $queryText,
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'distinct_weeks_seen' => $weeksSeen,
            'dedupe_key' => md5('winning_pattern|query|' . $queryText),
        ]);
        $stats['winning_patterns']++;
    }

    // ── content_gap / scope=query (persistent, unlike gsc_opportunities'
    // one-off "No article" category) ───────────────────────────────────
    try {
        $gapStmt = $pdo->prepare(
            "SELECT query,
                    COUNT(DISTINCT YEARWEEK(data_date, 3)) AS distinct_weeks,
                    SUM(impressions) AS total_impressions
               FROM gsc_query_data
              GROUP BY query
             HAVING SUM(CASE WHEN matched_page_id IS NOT NULL THEN 1 ELSE 0 END) = 0
                AND distinct_weeks >= :min_weeks AND total_impressions >= :min_impressions"
        );
        $gapStmt->execute(['min_weeks' => $minWeeks, 'min_impressions' => $minImpressions]);
        $gapRows = $gapStmt->fetchAll();
    } catch (Throwable $e) {
        $gapRows = [];
    }

    foreach ($gapRows as $row) {
        $queryText = mb_substr((string) $row['query'], 0, 255);
        $weeksSeen = (int) $row['distinct_weeks'];
        $evidence = [
            'total_impressions' => (int) $row['total_impressions'],
            'distinct_weeks_seen' => $weeksSeen,
        ];

        $upsert->execute([
            'pattern_type' => 'content_gap',
            'scope_type' => 'query',
            'matched_page_id' => null,
            'query_text' => $queryText,
            'evidence_json' => json_encode($evidence, JSON_UNESCAPED_UNICODE),
            'distinct_weeks_seen' => $weeksSeen,
            'dedupe_key' => md5('content_gap|query|' . $queryText),
        ]);
        $stats['content_gaps']++;
    }

    // ── Housekeeping: age out unconfirmed rows to 'stale' ──────────────
    try {
        $staleActive = $pdo->prepare(
            "UPDATE growth_agent_memory
                SET status = 'stale', updated_at = NOW()
              WHERE status = 'active' AND last_confirmed_at < (NOW() - INTERVAL :days DAY)"
        );
        $staleActive->execute(['days' => max(1, (int) $thresholds['active_stale_days'])]);
        $stats['staled'] += $staleActive->rowCount();

        $stalePending = $pdo->prepare(
            "UPDATE growth_agent_memory
                SET status = 'stale', updated_at = NOW()
              WHERE status = 'pending_review' AND last_confirmed_at < (NOW() - INTERVAL :days DAY)"
        );
        $stalePending->execute(['days' => max(1, (int) $thresholds['pending_review_stale_days'])]);
        $stats['staled'] += $stalePending->rowCount();
    } catch (Throwable $e) {
        // Non-fatal — worst case some stale rows linger as active/pending a bit longer.
    }

    return $stats;
}

/**
 * Lazy trigger for cms_growth_agent_detect_memory_patterns() — no cron in
 * this codebase (see cms_growth_agent_cleanup_old_jobs()'s own note on
 * that), mirrors cms_gsc_fetch_if_stale()'s "check last-run timestamp, run
 * only if past the configured interval" pattern exactly, just keyed off
 * gsc_settings.last_memory_detection_at / memory_thresholds_json's
 * detection_interval_days instead of last_fetch_at/the fetch interval.
 * Called from growth-agent.php's page load, right alongside the existing
 * cms_gsc_fetch_if_stale() call.
 *
 * Never throws.
 */
function cms_growth_agent_detect_memory_if_stale(PDO $pdo): void
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $settings = cms_gsc_get_settings($pdo);
        $thresholds = cms_gsc_get_memory_thresholds($pdo);
        $intervalDays = max(1, (int) $thresholds['detection_interval_days']);

        $lastRun = $settings['last_memory_detection_at'] ?? null;
        $isStale = $lastRun === null
            || (time() - strtotime((string) $lastRun)) >= ($intervalDays * 86400);

        if (!$isStale) {
            return;
        }

        cms_growth_agent_detect_memory_patterns($pdo);

        $pdo->prepare('UPDATE gsc_settings SET last_memory_detection_at = NOW() ORDER BY id ASC LIMIT 1')->execute();
    } catch (Throwable $e) {
        // A lazy background detection must never break the page it's attached to.
    }
}

/**
 * The one manual action Agent Memory has — deliberately NOT "approve" or
 * "execute" (memory is not an action queue, see the guardrail on
 * cms_growth_agent_detect_memory_patterns()). Lets an operator turn off a
 * pattern they judge no longer relevant, same semantics as
 * 'closed_as_legacy' elsewhere: a manual override, not a judgment that the
 * detection was wrong. Never deletes the row (kept as history).
 *
 * Never throws. Returns true if a row was actually updated.
 */
function cms_growth_agent_mark_memory_stale(PDO $pdo, int $memoryId): bool
{
    try {
        $stmt = $pdo->prepare("UPDATE growth_agent_memory SET status = 'stale', updated_at = NOW() WHERE id = :id");
        $stmt->execute(['id' => $memoryId]);
        return $stmt->rowCount() > 0;
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_mark_memory_stale] Failed: ' . $e->getMessage());
        return false;
    }
}

/**
 * Feedback Loop (ROADMAP.md gap #4, GROWTH_AGENT_SEO_ROADMAP.md § Phase 6
 * "Feedback and Measurement", closed 28 Jul 2026) — daily per-page snapshot
 * of gsc_query_data into growth_agent_performance. This is what turns the
 * schema-only table (noted in this file's own top docblock as "nothing
 * ingests into it yet") into a durable historical record: unlike
 * gsc_query_data, which cms_gsc_fetch_and_cache() prunes to
 * fetch_window_days, growth_agent_performance is never pruned — so a
 * before/after comparison spanning further back than the current GSC
 * retention window still has data here, as long as a snapshot ran while
 * gsc_query_data still held it.
 *
 * avg_ranking_position is impressions-weighted (SUM(position*impressions)
 * / SUM(impressions)) when combining multiple queries for the same
 * page+day — a plain AVG() would let a low-impression query's position
 * skew the page's real, traffic-weighted ranking.
 *
 * Upsert by (page_id, metric_date) per the table's own UNIQUE key —
 * ON DUPLICATE KEY UPDATE, same convention as gsc_opportunities/
 * growth_agent_memory. pageviews is intentionally left untouched (stays
 * whatever it already was, default 0) — this repo has no GA/analytics
 * integration, so nothing populates that column; it exists for a future
 * integration this function doesn't attempt.
 *
 * Never throws.
 *
 * @return array{rows_upserted:int}
 */
function cms_growth_agent_snapshot_performance(PDO $pdo): array
{
    $stats = ['rows_upserted' => 0];

    try {
        cms_growth_agent_ensure_schema($pdo);

        $rows = $pdo->query(
            "SELECT matched_page_id AS page_id, data_date AS metric_date,
                    SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    SUM(position * impressions) AS weighted_position_sum
               FROM gsc_query_data
              WHERE matched_page_id IS NOT NULL
              GROUP BY matched_page_id, data_date"
        )->fetchAll();
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_snapshot_performance] Query failed: ' . $e->getMessage());
        return $stats;
    }

    if ($rows === []) {
        return $stats;
    }

    try {
        $upsert = $pdo->prepare(
            'INSERT INTO growth_agent_performance (page_id, metric_date, impressions, clicks, ctr, avg_ranking_position, created_at)
             VALUES (:page_id, :metric_date, :impressions, :clicks, :ctr, :avg_ranking_position, NOW())
             ON DUPLICATE KEY UPDATE
                impressions = VALUES(impressions),
                clicks = VALUES(clicks),
                ctr = VALUES(ctr),
                avg_ranking_position = VALUES(avg_ranking_position)'
        );

        foreach ($rows as $row) {
            $impressions = (int) $row['total_impressions'];
            $clicks = (int) $row['total_clicks'];
            $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
            $avgPosition = $impressions > 0 ? round(((float) $row['weighted_position_sum']) / $impressions, 2) : null;

            $upsert->execute([
                'page_id' => (int) $row['page_id'],
                'metric_date' => (string) $row['metric_date'],
                'impressions' => $impressions,
                'clicks' => $clicks,
                'ctr' => $ctr,
                'avg_ranking_position' => $avgPosition,
            ]);
            $stats['rows_upserted']++;
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_snapshot_performance] Upsert failed: ' . $e->getMessage());
    }

    return $stats;
}

/**
 * Lazy trigger for cms_growth_agent_snapshot_performance() — no cron in
 * this codebase, mirrors cms_gsc_fetch_if_stale()/
 * cms_growth_agent_detect_memory_if_stale()'s "check last-run timestamp,
 * run only if past the configured interval" pattern exactly, keyed off
 * gsc_settings.last_performance_snapshot_at. Called from growth-agent.php's
 * page load. Never throws.
 */
function cms_growth_agent_snapshot_performance_if_stale(PDO $pdo, int $maxAgeHours = 24): void
{
    try {
        require_once __DIR__ . '/gsc-api.php';
        $settings = cms_gsc_get_settings($pdo);

        $lastRun = $settings['last_performance_snapshot_at'] ?? null;
        $isStale = $lastRun === null
            || (time() - strtotime((string) $lastRun)) >= (max(1, $maxAgeHours) * 3600);

        if (!$isStale) {
            return;
        }

        cms_growth_agent_snapshot_performance($pdo);

        $pdo->prepare('UPDATE gsc_settings SET last_performance_snapshot_at = NOW() ORDER BY id ASC LIMIT 1')->execute();
    } catch (Throwable $e) {
        // A lazy background snapshot must never break the page it's attached to.
    }
}

/**
 * Aggregates page performance over one date range (inclusive), preferring
 * growth_agent_performance (the durable snapshot) and falling back to
 * gsc_query_data directly if the snapshot doesn't have enough distinct
 * days for this page/range yet (e.g. snapshotting hasn't run recently, or
 * this range predates when snapshotting started) — gsc_query_data is the
 * same underlying source, just not yet materialized/durable.
 *
 * @return array{start:string,end:string,distinct_days:int,clicks:int,impressions:int,ctr:float,avg_position:?float,source:string}
 */
function cms_growth_agent_aggregate_page_window(PDO $pdo, int $pageId, string $start, string $end): array
{
    $empty = static fn (string $source): array => [
        'start' => $start, 'end' => $end, 'distinct_days' => 0,
        'clicks' => 0, 'impressions' => 0, 'ctr' => 0.0, 'avg_position' => null, 'source' => $source,
    ];

    try {
        $snapRow = $pdo->prepare(
            'SELECT COUNT(*) AS distinct_days, SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    SUM(avg_ranking_position * impressions) AS weighted_position_sum
               FROM growth_agent_performance
              WHERE page_id = :page_id AND metric_date BETWEEN :start AND :end'
        );
        $snapRow->execute(['page_id' => $pageId, 'start' => $start, 'end' => $end]);
        $snap = $snapRow->fetch();
    } catch (Throwable $e) {
        $snap = false;
    }

    $snapDays = $snap !== false ? (int) ($snap['distinct_days'] ?? 0) : 0;

    try {
        $rawRow = $pdo->prepare(
            "SELECT COUNT(DISTINCT data_date) AS distinct_days, SUM(clicks) AS total_clicks, SUM(impressions) AS total_impressions,
                    SUM(position * impressions) AS weighted_position_sum
               FROM gsc_query_data
              WHERE matched_page_id = :page_id AND data_date BETWEEN :start AND :end"
        );
        $rawRow->execute(['page_id' => $pageId, 'start' => $start, 'end' => $end]);
        $raw = $rawRow->fetch();
    } catch (Throwable $e) {
        $raw = false;
    }

    $rawDays = $raw !== false ? (int) ($raw['distinct_days'] ?? 0) : 0;

    // Whichever source has more day-coverage for this exact range wins —
    // the snapshot is preferred as the durable record, but a fresher/more
    // complete raw window (e.g. snapshot lag) should not be discarded.
    $row = $snapDays >= $rawDays ? $snap : $raw;
    $days = max($snapDays, $rawDays);
    $source = $snapDays >= $rawDays ? 'growth_agent_performance' : 'gsc_query_data';

    if ($row === false || $days === 0) {
        return $empty($source);
    }

    $impressions = (int) ($row['total_impressions'] ?? 0);
    $clicks = (int) ($row['total_clicks'] ?? 0);
    $ctr = $impressions > 0 ? round($clicks / $impressions, 4) : 0.0;
    $avgPosition = $impressions > 0 ? round(((float) ($row['weighted_position_sum'] ?? 0)) / $impressions, 2) : null;

    return [
        'start' => $start, 'end' => $end, 'distinct_days' => $days,
        'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $ctr,
        'avg_position' => $avgPosition, 'source' => $source,
    ];
}

/**
 * Before/after comparison for one page around one change date — the core
 * of the Feedback Loop. Deliberately READ-ONLY reporting: this function
 * (and everything that calls it) never creates, approves, or executes a
 * growth_agent_jobs row, never writes to gsc_opportunities, never touches
 * `pages` — same "advisory/reporting only, not an action queue" posture
 * as Agent Memory and Indexing Workflow before it.
 *
 * "Before" is the $windowDays ending the day before $changeDate; "after"
 * starts ON $changeDate and runs $windowDays forward — matches "ukur
 * per page/query... bandingkan window yang setara" from
 * GROWTH_AGENT_SEO_ROADMAP.md § Phase 6.
 *
 * Guardrail: if either side has fewer than $minDays (default 7) distinct
 * days of data, returns status='insufficient_data' with NO delta computed
 * — per the roadmap's own explicit rule, a thin window must never be
 * dressed up as a real before/after result. This also means: actions
 * approved before this feature existed have no valid "before" baseline captured
 * at the time and may legitimately never clear this bar — that's a real
 * limitation of retroactive measurement, not a bug to work around.
 *
 * Never throws.
 *
 * @return array{status:string,page_id:int,change_date:string,window_days:int,before:array,after:array,delta?:array,error?:string}
 */
function cms_growth_agent_compare_before_after(PDO $pdo, int $pageId, string $changeDate, int $windowDays = 28, int $minDays = 7): array
{
    $windowDays = max(1, min(180, $windowDays));
    $minDays = max(1, min($windowDays, $minDays));

    $changeTs = strtotime($changeDate);
    if ($changeTs === false) {
        return [
            'status' => 'insufficient_data', 'page_id' => $pageId, 'change_date' => $changeDate,
            'window_days' => $windowDays, 'before' => [], 'after' => [], 'error' => 'Invalid change_date',
        ];
    }

    try {
        $beforeStart = date('Y-m-d', strtotime('-' . $windowDays . ' days', $changeTs));
        $beforeEnd = date('Y-m-d', strtotime('-1 day', $changeTs));
        $afterStart = date('Y-m-d', $changeTs);
        $afterEnd = date('Y-m-d', strtotime('+' . $windowDays . ' days', $changeTs));

        $before = cms_growth_agent_aggregate_page_window($pdo, $pageId, $beforeStart, $beforeEnd);
        $after = cms_growth_agent_aggregate_page_window($pdo, $pageId, $afterStart, $afterEnd);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_compare_before_after] Failed: ' . $e->getMessage());
        return [
            'status' => 'insufficient_data', 'page_id' => $pageId, 'change_date' => $changeDate,
            'window_days' => $windowDays, 'before' => [], 'after' => [], 'error' => $e->getMessage(),
        ];
    }

    $result = [
        'status' => 'ok', 'page_id' => $pageId, 'change_date' => $changeDate,
        'window_days' => $windowDays, 'before' => $before, 'after' => $after,
    ];

    if ($before['distinct_days'] < $minDays || $after['distinct_days'] < $minDays) {
        $result['status'] = 'insufficient_data';
        return $result;
    }

    $result['delta'] = [
        'clicks' => $after['clicks'] - $before['clicks'],
        'impressions' => $after['impressions'] - $before['impressions'],
        'ctr' => round($after['ctr'] - $before['ctr'], 4),
        'avg_position' => ($before['avg_position'] !== null && $after['avg_position'] !== null)
            ? round($after['avg_position'] - $before['avg_position'], 2)
            : null,
    ];

    return $result;
}

/**
 * Builds the "Feedback / Before-After" report growth-agent.php renders —
 * one row per page that had a REAL, verifiable applied change:
 *   - seo_recommendation: only jobs that actually went through Apply
 *     (job_type='seo_recommendation', status='succeeded', page_id set —
 *     succeeded only ever happens via seo-recommendation-review.php's
 *     Apply action, which is the one thing that writes meta_title/
 *     meta_description). change_date = job.updated_at (set at Apply time).
 *   - gsc_article_idea: only jobs whose draft actually got published
 *     (job_type='gsc_article_idea', page_id set, joined pages.status =
 *     'published'). change_date = pages.published_at (falls back to
 *     pages.updated_at if published_at is somehow null).
 *
 * gsc_content_optimization is deliberately EXCLUDED — traced 28 Jul 2026:
 * that job type has no "applied to the page" event anywhere in this
 * codebase. cms_growth_agent_generate_content_optimization() logs it as
 * 'succeeded' immediately at generation time (it's a proposal the human
 * copies in manually, per GROWTH_AGENT_SEO_ROADMAP.md's own "jangan
 * langsung menulis ke production" rule for this job type), and the
 * generic Approve/Reject buttons on growth-agent.php only ever write
 * growth_agent_feedback + flip job status — never `pages`. There is no
 * reliable timestamp for "when was this suggestion actually applied, if
 * ever", so including it would mean measuring before/after around a date
 * that may have no relation to any real edit. Confirmed with the user
 * before implementing rather than guessing a proxy date.
 *
 * Never throws.
 *
 * @return list<array{page_id:int,page_title:string,action_type:string,change_date:string,comparison:array}>
 */
function cms_growth_agent_get_feedback_report(PDO $pdo, int $limit = 20, int $windowDays = 28): array
{
    $limit = max(1, min(100, $limit));
    $candidates = [];

    try {
        $seoStmt = $pdo->prepare(
            "SELECT j.page_id, p.title, j.updated_at AS change_date
               FROM growth_agent_jobs j
               INNER JOIN pages p ON p.page_id = j.page_id
              WHERE j.job_type = 'seo_recommendation' AND j.status = 'succeeded' AND j.page_id IS NOT NULL
              ORDER BY j.updated_at DESC
              LIMIT " . $limit
        );
        $seoStmt->execute();
        foreach ($seoStmt->fetchAll() as $row) {
            $candidates[] = [
                'page_id' => (int) $row['page_id'],
                'page_title' => (string) $row['title'],
                'action_type' => 'seo_recommendation',
                'change_date' => (string) $row['change_date'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_get_feedback_report] seo_recommendation query failed: ' . $e->getMessage());
    }

    try {
        $ideaStmt = $pdo->prepare(
            "SELECT j.page_id, p.title, COALESCE(p.published_at, p.updated_at) AS change_date
               FROM growth_agent_jobs j
               INNER JOIN pages p ON p.page_id = j.page_id
              WHERE j.job_type = 'gsc_article_idea' AND j.page_id IS NOT NULL AND p.status = 'published'
              ORDER BY COALESCE(p.published_at, p.updated_at) DESC
              LIMIT " . $limit
        );
        $ideaStmt->execute();
        foreach ($ideaStmt->fetchAll() as $row) {
            $candidates[] = [
                'page_id' => (int) $row['page_id'],
                'page_title' => (string) $row['title'],
                'action_type' => 'gsc_article_idea',
                'change_date' => (string) $row['change_date'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_get_feedback_report] gsc_article_idea query failed: ' . $e->getMessage());
    }

    // Most recent change first overall, capped at $limit total (not per type).
    usort($candidates, static fn (array $a, array $b): int => strtotime((string) $b['change_date']) <=> strtotime((string) $a['change_date']));
    $candidates = array_slice($candidates, 0, $limit);

    $report = [];
    foreach ($candidates as $candidate) {
        try {
            $comparison = cms_growth_agent_compare_before_after($pdo, $candidate['page_id'], $candidate['change_date'], $windowDays);
        } catch (Throwable $e) {
            $comparison = ['status' => 'insufficient_data', 'error' => $e->getMessage()];
        }

        $report[] = $candidate + ['comparison' => $comparison];
    }

    return $report;
}

/**
 * Delete old, already-resolved growth_agent_jobs rows so the table doesn't
 * grow forever (there's no cron in this codebase — see sitemap-service.php's
 * own note that everything here runs synchronously on request, not on a
 * schedule — so this is invoked both lazily on every growth-agent.php page
 * load, matching the cms_ensure_table() "self-maintaining on request"
 * pattern, and via an explicit "Bersihkan job lama" button for on-demand use).
 *
 * Deliberately conservative about what it deletes:
 *   - 'ready' / 'running' / 'manual_action' jobs are NEVER touched, no
 *     matter how old — 'manual_action' still needs a human decision
 *     (e.g. an un-reviewed SEO recommendation or indexing issue), and
 *     'ready'/'running' are still in flight.
 *   - 'failed' jobs older than the retention window are deleted — a
 *     failed generation has no future use once it's old.
 *   - 'closed_as_legacy' jobs older than the window are deleted too, same
 *     reasoning as 'failed' — explicitly marked "no longer useful" by a
 *     human, so there's no reason to keep it around as a few-shot example.
 *   - 'succeeded' jobs older than the window are deleted UNLESS a human
 *     explicitly approved it as-is (growth_agent_feedback.action =
 *     'approved_as_is') — those are the Fase 3 few-shot example pool
 *     (see GrowthAgentPromptBuilder::approvedExamples()) and must survive
 *     cleanup indefinitely, or future generations quietly lose their
 *     reference examples.
 *
 * Never throws. Returns the number of jobs deleted (0 on any failure).
 */
function cms_growth_agent_cleanup_old_jobs(PDO $pdo, int $retentionDays = 90): int
{
    try {
        cms_growth_agent_ensure_schema($pdo);
        $days = max(7, min(365, $retentionDays));

        $idStmt = $pdo->query(
            "SELECT id FROM growth_agent_jobs
              WHERE created_at < (NOW() - INTERVAL {$days} DAY)
                AND (
                    status IN ('failed', 'closed_as_legacy')
                    OR (
                        status = 'succeeded'
                        AND id NOT IN (SELECT job_id FROM growth_agent_feedback WHERE action = 'approved_as_is')
                    )
                )"
        );
        $ids = $idStmt->fetchAll(PDO::FETCH_COLUMN, 0) ?: [];

        if ($ids === []) {
            return 0;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM growth_agent_feedback WHERE job_id IN ($placeholders)")->execute($ids);
        $pdo->prepare("DELETE FROM growth_agent_jobs WHERE id IN ($placeholders)")->execute($ids);

        return count($ids);
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_cleanup_old_jobs] Failed: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Feeds the notification bell in includes/navbar.php (shown on every admin
 * page). "Needs attention" means: a generation that failed (retryable), or
 * a manual_action job awaiting a human decision (currently only
 * seo_recommendation jobs use that status — see
 * cms_growth_agent_scan_seo_recommendations()). 'ready'/'running' jobs are
 * excluded on purpose — they're not problems, just in-flight/queued work.
 *
 * Never throws — a notification lookup failing must never break every
 * single admin page. Returns ['count' => int, 'items' => array] with
 * count reflecting the TOTAL number needing attention (not capped by
 * $limit — $limit only bounds how many are listed in the dropdown).
 *
 * @return array{count:int, items:array<int, array<string, mixed>>}
 */
function cms_growth_agent_notifications(PDO $pdo, int $limit = 8): array
{
    $result = ['count' => 0, 'items' => []];

    try {
        cms_growth_agent_ensure_schema($pdo);

        $countRow = $pdo->query(
            "SELECT COUNT(*) AS cnt FROM growth_agent_jobs WHERE status IN ('failed', 'manual_action')"
        )->fetch();
        $result['count'] = (int) ($countRow['cnt'] ?? 0);

        if ($result['count'] === 0) {
            return $result;
        }

        $stmt = $pdo->prepare(
            "SELECT j.id, j.job_type, j.status, j.created_at, p.title AS page_title
               FROM growth_agent_jobs j
               LEFT JOIN pages p ON p.page_id = j.page_id
              WHERE j.status IN ('failed', 'manual_action')
              ORDER BY j.created_at DESC
              LIMIT " . max(1, $limit)
        );
        $stmt->execute();
        $result['items'] = $stmt->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[cms_growth_agent_notifications] Failed: ' . $e->getMessage());
    }

    return $result;
}
