<?php
declare(strict_types=1);

/**
 * Sagagoal — article detail page.
 * Reached via the clean URL /artikel/<slug> (see root .htaccess), which
 * rewrites to this file as ?slug=<slug> under the hood — this file's own
 * logic never changed, only outgoing links now use wpm_url_artikel().
 * Still directly reachable as artikel.php?slug=<slug> too, for old links.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$slug = trim((string) ($_GET['slug'] ?? ''));

if ($slug === '') {
    header('Location: ' . wpm_url_kategori(), true, 302);
    exit;
}

$stmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name, c.slug AS category_slug, a.name AS author_name
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     LEFT JOIN admins a ON a.admin_id = p.author_id
     WHERE p.slug = :slug AND p.status = 'published'
     LIMIT 1"
);
$stmt->execute(['slug' => $slug]);
$article = $stmt->fetch();

if (!$article) {
    http_response_code(404);
    $pageTitle = 'Artikel Tidak Ditemukan — Sagagoal';
    $pageDescription = 'Artikel yang kamu cari tidak ditemukan atau belum diterbitkan.';
    $activeNav = 'berita';
    require __DIR__ . '/includes/site-header.php';
    ?>
    <section class="crypto-section">
        <div class="crypto-container">
            <div class="empty-state">
                <?= wpm_icon('news') ?>
                <p>Artikel tidak ditemukan atau belum diterbitkan.</p>
                <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_url_kategori()) ?>" style="margin-top:16px;display:inline-flex;">Lihat Semua Berita</a>
            </div>
        </div>
    </section>
    </main>
    <?php require __DIR__ . '/includes/site-footer.php'; ?>
    <?php
    exit;
}

$pageId = (int) $article['page_id'];
wpm_increment_views($pdo, $pageId);

/* Tags */
$tagStmt = $pdo->prepare(
    'SELECT t.name, t.slug FROM article_tags t
     INNER JOIN article_tag_map m ON m.tag_id = t.id
     WHERE m.page_id = :id ORDER BY t.name ASC'
);
$tagStmt->execute(['id' => $pageId]);
$tags = $tagStmt->fetchAll();

/* Related articles — same category, excluding this one */
$related = [];
if (!empty($article['category_id'])) {
    $relStmt = $pdo->prepare(
        "SELECT p.*, c.name AS category_name
         FROM pages p
         LEFT JOIN article_categories c ON c.id = p.category_id
         WHERE p.status = 'published' AND p.category_id = :cat AND p.page_id != :id
         ORDER BY p.published_at DESC LIMIT 4"
    );
    $relStmt->execute(['cat' => (int) $article['category_id'], 'id' => $pageId]);
    $related = $relStmt->fetchAll();
}
if (count($related) < 4) {
    $need = 4 - count($related);
    $excludeIds = array_merge([$pageId], array_column($related, 'page_id'));
    $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
    $fallStmt = $pdo->prepare(
        "SELECT p.*, c.name AS category_name
         FROM pages p
         LEFT JOIN article_categories c ON c.id = p.category_id
         WHERE p.status = 'published' AND p.page_id NOT IN ($placeholders)
         ORDER BY p.published_at DESC LIMIT $need"
    );
    $fallStmt->execute($excludeIds);
    $related = array_merge($related, $fallStmt->fetchAll());
}

/* FAQ — generated via the admin "Generate FAQ" button (cms-admin/api/faq-generate.php),
 * stored as pages.faq_json. Renders nothing at all if that column is empty or the
 * admin never clicked Generate FAQ for this article — this section only exists
 * when there's real content to show. */
$faqItems = [];
$faqDecoded = json_decode((string) ($article['faq_json'] ?? ''), true);
if (is_array($faqDecoded)) {
    foreach ($faqDecoded as $faqItem) {
        if (!is_array($faqItem)) {
            continue;
        }
        $faqQuestion = trim((string) ($faqItem['question'] ?? ''));
        $faqAnswer = trim((string) ($faqItem['answer'] ?? ''));
        if ($faqQuestion === '' || $faqAnswer === '') {
            continue;
        }
        $faqItems[] = ['question' => $faqQuestion, 'answer' => $faqAnswer];
    }
}

$pageTitle = !empty($article['meta_title']) ? (string) $article['meta_title'] : (string) $article['title'] . ' — Sagagoal';
$pageDescription = !empty($article['meta_description']) ? (string) $article['meta_description'] : wpm_excerpt((string) ($article['excerpt'] ?: $article['content']), 160);
$activeNav = 'berita';
$canonicalUrl = !empty($article['canonical_url']) ? (string) $article['canonical_url'] : wpm_site_url(wpm_url_artikel($slug));
$pageNoindex = (int) ($article['noindex'] ?? 0) === 1;
$ogImage = wpm_image($article['og_image'] ?? null) ?? wpm_image($article['featured_image'] ?? null);
if ($ogImage !== null) {
    $ogImage = wpm_site_url(ltrim($ogImage, '/'));
}

$shareUrl = wpm_site_url(wpm_url_artikel($slug));
$ogType = 'article';

/* ── Structured data (schema.org JSON-LD): NewsArticle + BreadcrumbList ──
 * GSC reports "URL has no enhancements" for articles — this site is a
 * sports news portal, so NewsArticle is the practical requirement for Top
 * Stories eligibility (plus richer date/thumbnail display in results).
 * Built here (before site-header.php is required) and injected via
 * $extraHead — see that file's own docblock for the convention. Separate
 * from the FAQPage block further down in the body; that one is untouched.
 */
$wpmIso8601 = static function (?string $value): ?string {
    if ($value === null || $value === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts !== false ? date(DATE_ATOM, $ts) : null;
};

$schemaSiteSettings = wpm_site_settings($pdo);
$schemaSiteName = trim((string) ($schemaSiteSettings['site_name'] ?? '')) !== ''
    ? (string) $schemaSiteSettings['site_name'] : 'Sagagoal';
$schemaLogoUrl = wpm_image((string) ($schemaSiteSettings['logo_path'] ?? ''));
if ($schemaLogoUrl !== null) {
    $schemaLogoUrl = wpm_site_url(ltrim($schemaLogoUrl, '/'));
}

$newsArticleSchema = array_filter([
    '@context' => 'https://schema.org',
    '@type' => 'NewsArticle',
    'headline' => mb_substr((string) $article['title'], 0, 110),
    'description' => $pageDescription,
    'datePublished' => $wpmIso8601($article['published_at'] ?? null),
    'dateModified' => $wpmIso8601(!empty($article['updated_at']) ? (string) $article['updated_at'] : ($article['published_at'] ?? null)),
    'mainEntityOfPage' => $canonicalUrl,
    'author' => !empty($article['author_name'])
        ? ['@type' => 'Person', 'name' => (string) $article['author_name']]
        : ['@type' => 'Organization', 'name' => $schemaSiteName],
    'publisher' => array_filter([
        '@type' => 'Organization',
        'name' => $schemaSiteName,
        'logo' => $schemaLogoUrl !== null ? ['@type' => 'ImageObject', 'url' => $schemaLogoUrl] : null,
    ]),
], static fn ($value): bool => $value !== null);
if ($ogImage !== null) {
    $newsArticleSchema['image'] = [$ogImage];
}

// Mirrors the visual breadcrumb <nav> markup below exactly (Beranda →
// Berita → category, category skipped when there isn't one).
$breadcrumbItems = [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Beranda', 'item' => wpm_site_url('')],
    ['@type' => 'ListItem', 'position' => 2, 'name' => 'Berita', 'item' => wpm_site_url(wpm_url_kategori())],
];
if (!empty($article['category_name'])) {
    $breadcrumbItems[] = [
        '@type' => 'ListItem',
        'position' => 3,
        'name' => (string) $article['category_name'],
        'item' => wpm_site_url(wpm_url_kategori((string) $article['category_slug'])),
    ];
}
$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => $breadcrumbItems,
];

// JSON_HEX_TAG escapes angle brackets as backslash-u-escaped hex
// codepoints instead of leaving them literal — without it, a title
// or description containing the substring "</script>" would close
// this script block early and let the rest execute as JavaScript
// (stored XSS via the editor role, the lowest-trust tier in the
// 3-tier RBAC — see docs/DECISIONS.md 2026-07-15). Doesn't affect
// JSON-LD validity: JSON parsers (Google's included) decode the
// escaped codepoints back to the literal characters.
$extraHead = ($extraHead ?? '')
    . '<script type="application/ld+json">' . json_encode($newsArticleSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>'
    . '<script type="application/ld+json">' . json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';

/* ── Promo banners (cms-admin/pages/banners.php), placement="article" ── */
$articleBanners = wpm_banners_active($pdo, 'article');

/* ── Sidebar ad slots (25 Jul 2026) — rendered once here (not inside the
 * layout markup below) so the resulting HTML doubles as both the "is an ad
 * actually active in this slot" check AND the markup to print, instead of
 * querying/counting the impression twice. When a slot comes back empty the
 * <aside> for it is skipped entirely (not just hidden) so the grid column
 * it would have occupied is never reserved — see .article-layout's
 * variant classes in site.css for the column-count logic this drives. */
$leftAdHtml = wpm_render_ad_slot($pdo, 'sidebar-left', 'article', $pageId);
$rightAdHtml = wpm_render_ad_slot($pdo, 'sidebar-right', 'article', $pageId);
$hasLeftAd = $leftAdHtml !== '';
$hasRightAd = $rightAdHtml !== '';
$articleLayoutClass = 'article-layout';
if ($hasLeftAd && $hasRightAd) {
    $articleLayoutClass .= ' article-layout--both';
} elseif ($hasRightAd) {
    $articleLayoutClass .= ' article-layout--right-only';
} elseif ($hasLeftAd) {
    $articleLayoutClass .= ' article-layout--left-only';
} else {
    $articleLayoutClass .= ' article-layout--no-ads';
}

require __DIR__ . '/includes/site-header.php';
?>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb">
            <a href="index.php">Beranda</a> <span>/</span>
            <a href="<?= wpm_esc(wpm_url_kategori()) ?>">Berita</a>
            <?php if (!empty($article['category_name'])) : ?>
                <span>/</span> <a href="<?= wpm_esc(wpm_url_kategori((string) $article['category_slug'])) ?>"><?= wpm_esc((string) $article['category_name']) ?></a>
            <?php endif; ?>
        </nav>

        <div class="<?= wpm_esc($articleLayoutClass) ?>">
            <?php if ($hasLeftAd) : ?>
            <!-- Sticky sidebar ad (left) -->
            <aside class="article-layout__sidebar">
                <?= $leftAdHtml ?>
            </aside>
            <?php endif; ?>

            <div class="article-layout__main">
                <?= wpm_render_ad_slot($pdo, 'article-before-title', 'article', $pageId) ?>

                <div class="article-head">
                    <?php if (!empty($article['category_name'])) : ?>
                        <a href="<?= wpm_esc(wpm_url_kategori((string) $article['category_slug'])) ?>" class="article-head__category"><?= wpm_esc((string) $article['category_name']) ?></a>
                    <?php endif; ?>
                    <h1><?= wpm_esc((string) $article['title']) ?></h1>
                    <div class="article-head__meta">
                        <?php if (!empty($article['author_name'])) : ?><span><?= wpm_icon('news') ?><?= wpm_esc((string) $article['author_name']) ?></span><?php endif; ?>
                        <span><?= wpm_icon('clock') ?><?= wpm_esc(wpm_format_date($article['published_at'] ?? null, 'd M Y, H:i')) ?></span>
                        <span><?= wpm_icon('eye') ?><?= (int) $article['views'] ?> views</span>
                    </div>
                </div>

                <?= wpm_render_ad_slot($pdo, 'article-after-title', 'article', $pageId) ?>

                <?php if (($img = wpm_image($article['featured_image'] ?? null)) !== null) : ?>
                    <div class="article-cover"><img src="<?= wpm_esc($img) ?>" alt="<?= wpm_esc((string) $article['title']) ?>"></div>
                <?php endif; ?>

                <?= wpm_render_ad_slot($pdo, 'above-article', 'article', $pageId) ?>

                <div class="article-prose">
                    <?= wpm_inject_midpoint((string) $article['content'], wpm_render_ad_slot($pdo, 'middle-of-article', 'article', $pageId)) ?>
                </div>

                <?= wpm_render_ad_slot($pdo, 'below-article', 'article', $pageId) ?>

                <?php if ($articleBanners !== []) : ?>
                <div class="banner-strip" style="margin:24px 0;">
                    <div class="banner-strip__grid">
                        <?php foreach ($articleBanners as $banner) : ?>
                            <?= wpm_banner_markup($banner) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>

                <?php if ($tags !== []) : ?>
                <div class="article-tags">
                    <?php foreach ($tags as $tag) : ?>
                        <a href="<?= wpm_esc(wpm_url_tag((string) $tag['slug'])) ?>"><?= wpm_icon('tag') ?><?= wpm_esc((string) $tag['name']) ?></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <div class="article-share">
                    <span class="article-share__label">Bagikan:</span>
                    <a href="https://wa.me/?text=<?= urlencode((string) $article['title'] . ' ' . $shareUrl) ?>" target="_blank" rel="noopener" aria-label="Bagikan ke WhatsApp"><?= wpm_icon('chat') ?></a>
                    <a href="https://twitter.com/intent/tweet?text=<?= urlencode((string) $article['title']) ?>&amp;url=<?= urlencode($shareUrl) ?>" target="_blank" rel="noopener" aria-label="Bagikan ke Twitter/X"><?= wpm_icon('share') ?></a>
                    <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode($shareUrl) ?>" target="_blank" rel="noopener" aria-label="Bagikan ke Facebook"><?= wpm_icon('network') ?></a>
                    <button type="button" id="wpm-copy-link" title="Salin link" aria-label="Salin link"><?= wpm_icon('tag') ?></button>
                </div>

                <?php if ($faqItems !== []) : ?>
                <div class="article-faq">
                    <h2>Pertanyaan Umum</h2>
                    <?php foreach ($faqItems as $faqItem) : ?>
                        <details class="article-faq__item">
                            <summary><?= wpm_esc($faqItem['question']) ?></summary>
                            <div class="article-faq__answer"><?= wpm_esc($faqItem['answer']) ?></div>
                        </details>
                    <?php endforeach; ?>
                </div>
                <script type="application/ld+json"><?= json_encode([
                    '@context' => 'https://schema.org',
                    '@type' => 'FAQPage',
                    'mainEntity' => array_map(static fn (array $i): array => [
                        '@type' => 'Question',
                        'name' => $i['question'],
                        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $i['answer']],
                    ], $faqItems),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
                <?php endif; ?>

                <?php if ($related !== []) : ?>
                <div class="related-articles">
                    <h2>Artikel Terkait</h2>
                    <div class="crypto-grid crypto-grid--2">
                        <?php foreach ($related as $rel) : ?>
                            <?= wpm_article_card($rel, true) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <?php if ($hasRightAd) : ?>
            <!-- Sticky sidebar ad (right) -->
            <aside class="article-layout__sidebar">
                <?= $rightAdHtml ?>
            </aside>
            <?php endif; ?>
        </div>
    </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
