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
         avg_ranking_position DECIMAL(6,2) DEFAULT NULL,
         clicks INT UNSIGNED NOT NULL DEFAULT 0,
         ctr DECIMAL(6,4) DEFAULT NULL,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         UNIQUE KEY uniq_gap_page_date (page_id, metric_date)"
    );
}

/**
 * Insert one growth_agent_jobs row. Never throws — a logging failure must
 * never break the actual generate response, matching cms_ai_log()'s own
 * philosophy in ai-helpers.php. Returns the new job id, or 0 on failure.
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
    string $errorMessage = ''
): int {
    try {
        cms_growth_agent_ensure_schema($pdo);

        $stmt = $pdo->prepare(
            'INSERT INTO growth_agent_jobs
                (job_type, agent_key, page_id, status, input_brief, output_json, model_used, tokens_in, tokens_out, latency_ms, error_message, created_by, created_at, updated_at)
             VALUES
                (:job_type, :agent_key, :page_id, :status, :input_brief, :output_json, :model_used, :tokens_in, :tokens_out, :latency_ms, :error_message, :created_by, NOW(), NOW())'
        );
        $stmt->execute([
            'job_type'      => $jobType,
            'agent_key'     => $agentKey,
            'page_id'       => $pageId,
            'status'        => $status,
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
    $stats = ['scanned' => 0, 'created' => 0, 'errors' => 0];

    try {
        require_once __DIR__ . '/ai-helpers.php';
        cms_growth_agent_ensure_schema($pdo);
    } catch (Throwable $e) {
        return $stats;
    }

    $limit = max(1, min(20, $limit));

    try {
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
        return $stats;
    }

    if ($pages === []) {
        return $stats;
    }

    $defaultSystemPrompt =
        'You are "Agent SEO" reviewing the EXISTING meta_title and meta_description of a published ' .
        'Sagagoal article. Given the article title, slug, excerpt, content, and its current ' .
        'meta_title/meta_description, suggest an improved meta_title (max 60 characters) and ' .
        'meta_description (max 155 characters) that is more compelling and better optimized for search, ' .
        'in the same language as the content (default Bahasa Indonesia). If the current metadata is ' .
        'already strong, a small refinement is fine — do not change things just to change them. ' .
        'Respond with ONLY a raw JSON object, no markdown, no code fences, no commentary, in exactly ' .
        'this shape: {"recommended_meta_title": "...", "recommended_meta_description": "..."}';

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
            cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                $result['success'] ? 'AI response was not in the expected format' : ('AI request failed: ' . $result['error'])
                    . ($retried ? ' (after 1 retry)' : '')
            );
            continue;
        }

        $recommendedMetaTitle = mb_substr(trim((string) $parsed['recommended_meta_title']), 0, 255);
        $recommendedMetaDescription = mb_substr(trim((string) $parsed['recommended_meta_description']), 0, 255);

        if ($recommendedMetaTitle === '' || $recommendedMetaDescription === '') {
            $stats['errors']++;
            cms_growth_agent_log_job(
                $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'failed', $inputBrief, null,
                $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null,
                'AI returned an empty recommendation' . ($retried ? ' (after 1 retry)' : '')
            );
            continue;
        }

        $output = [
            'current_meta_title' => $currentMetaTitle,
            'current_meta_description' => $currentMetaDescription,
            'recommended_meta_title' => $recommendedMetaTitle,
            'recommended_meta_description' => $recommendedMetaDescription,
        ];

        cms_growth_agent_log_job(
            $pdo, 'seo_recommendation', 'seo_agent', $pageId, 'manual_action', $inputBrief, $output,
            $agent['model'], $tokensIn ?: null, $tokensOut ?: null, $result['latency_ms'] ?? null
        );
        $stats['created']++;
    }

    return $stats;
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
 *     (e.g. an un-reviewed SEO recommendation), and 'ready'/'running'
 *     are still in flight.
 *   - 'failed' jobs older than the retention window are deleted — a
 *     failed generation has no future use once it's old.
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
                    status = 'failed'
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
