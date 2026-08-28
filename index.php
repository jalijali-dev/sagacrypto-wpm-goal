<?php
declare(strict_types=1);

/**
 * Sagagoal — public homepage. Tabbed news feed ("Untuk Anda" / "Terbaru")
 * with a league/live filter row, a hero card + article list on the left,
 * and a trending sidebar on the right. Replaces the old single-page
 * hero+about+contact homepage — "Tentang Kami" moved to its own page
 * (tentang.php); "Kontak" is now a Special Page served via page.php (see
 * includes/SpecialPages.php).
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

// Default tab (10 Agu 2026, permintaan operator) — "Terbaru" adalah tab
// pertama/utama di homepage, jadi itu yang harus tampil default saat
// pertama buka index.php tanpa ?tab= sama sekali. Sebelumnya default-nya
// kebalik (jatuh ke "Untuk Anda" kecuali eksplisit ?tab=terbaru).
$tab = ($_GET['tab'] ?? '') === 'untuk-anda' ? 'untuk-anda' : 'terbaru';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 10;

// Sport filter chip (see wpm_sport_filter_row()) — only accept keys that
// actually exist in the sports registry, so a bogus ?sport= can't silently
// zero out the feed.
$sportKeyParam = trim((string) ($_GET['sport'] ?? ''));
$activeSportKey = null;
if ($sportKeyParam !== '') {
    $sportExistsStmt = $pdo->prepare('SELECT 1 FROM sports WHERE `key` = :key LIMIT 1');
    $sportExistsStmt->execute(['key' => $sportKeyParam]);
    $activeSportKey = $sportExistsStmt->fetchColumn() ? $sportKeyParam : null;
}

$where = $tab === 'terbaru'
    ? "p.status = 'published'"
    : "p.status = 'published' AND p.is_featured = 1";
$queryParams = [];
if ($activeSportKey !== null) {
    $where .= ' AND p.sport_key = :sportKey';
    $queryParams['sportKey'] = $activeSportKey;
}
$orderBy = $tab === 'terbaru' ? 'p.created_at DESC' : 'p.published_at DESC';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pages p WHERE $where");
$countStmt->execute($queryParams);
$totalArticles = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalArticles / $perPage));
$page = min($page, $totalPages);
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name, a.name AS author_name
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     LEFT JOIN admins a ON a.admin_id = p.author_id
     WHERE $where
     ORDER BY $orderBy
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($queryParams);
$feedArticles = $listStmt->fetchAll();

// Hero card is just the first result of page 1 — subsequent pages are a plain list.
$heroArticle = ($page === 1 && $feedArticles !== []) ? array_shift($feedArticles) : null;

/* ── Trending sidebar: recency-weighted (views in the last 7 days,
   page_view_daily), NOT lifetime total views / is_featured — see
   wpm_increment_views() in includes/site-bootstrap.php for how that
   table gets populated. A lifetime-views sort let old articles that
   racked up views over months permanently outrank something genuinely
   viral today; is_featured-only meant an article never showed up here
   at all unless an admin remembered to flag it. Falls back to the old
   is_featured+lifetime-views query (unchanged) whenever the 7-day query
   comes back empty — covers both a brand-new deploy (page_view_daily
   has no rows yet, or doesn't exist yet if literally nobody has viewed
   an article since this feature shipped) and the exception case (table
   genuinely missing), so the widget is never blank. ── */
$trendingArticles = [];
try {
    $trendingStmt = $pdo->query(
        "SELECT p.*, a.name AS author_name,
                COALESCE(SUM(pvd.views), 0) AS views_7d
         FROM pages p
         LEFT JOIN admins a ON a.admin_id = p.author_id
         LEFT JOIN page_view_daily pvd
                ON pvd.page_id = p.page_id
               AND pvd.view_date >= (CURDATE() - INTERVAL 7 DAY)
         WHERE p.status = 'published'
         GROUP BY p.page_id
         HAVING views_7d > 0
         ORDER BY views_7d DESC, p.published_at DESC
         LIMIT 4"
    );
    $trendingArticles = $trendingStmt->fetchAll();
} catch (Throwable $e) {
    // page_view_daily doesn't exist yet (brand-new deploy, nobody has
    // viewed an article since this shipped) — falls through to the
    // is_featured fallback below, same as the "0 rows" case.
    $trendingArticles = [];
}
if ($trendingArticles === []) {
    $trendingFallbackStmt = $pdo->query(
        "SELECT p.*, a.name AS author_name
         FROM pages p
         LEFT JOIN admins a ON a.admin_id = p.author_id
         WHERE p.status = 'published' AND p.is_featured = 1
         ORDER BY p.views DESC, p.published_at DESC
         LIMIT 4"
    );
    $trendingArticles = $trendingFallbackStmt->fetchAll();
}

/* ── Promo banners (cms-admin/pages/banners.php), placement="home" ── */
$homeBanners = wpm_banners_active($pdo, 'home');

$paginateUrl = static function (int $p) use ($tab, $activeSportKey): string {
    $url = 'index.php?tab=' . $tab . '&page=' . $p;
    return $activeSportKey !== null ? $url . '&sport=' . rawurlencode($activeSportKey) : $url;
};
$tabUrl = static function (string $t) use ($activeSportKey): string {
    $url = 'index.php?tab=' . $t;
    return $activeSportKey !== null ? $url . '&sport=' . rawurlencode($activeSportKey) : $url;
};

$pageTitle = 'Sagagoal — Livescore & Berita Bola Terkini';
$pageDescription = 'Sagagoal adalah portal livescore dan berita sepak bola: jadwal pertandingan, skor live, klasemen liga, dan berita terkini.';
$activeNav = 'beranda';
$canonicalUrl = wpm_site_url('');

require __DIR__ . '/includes/site-header.php';
?>

    <div class="crypto-container">
        <!-- ══════════ LIVE SEKARANG (renders nothing if no live match anywhere) ══════════ -->
        <?= wpm_live_now_widget($pdo) ?>

        <!-- ══════════ TABS: Terbaru / Untuk Anda (urutan ditukar 9 Agu 2026, permintaan operator) ══════════ -->
        <div class="news-tabs">
            <a class="news-tabs__item<?= $tab === 'terbaru' ? ' is-active' : '' ?>" href="<?= wpm_esc($tabUrl('terbaru')) ?>">Terbaru</a>
            <a class="news-tabs__item<?= $tab === 'untuk-anda' ? ' is-active' : '' ?>" href="<?= wpm_esc($tabUrl('untuk-anda')) ?>">Untuk Anda</a>
        </div>

        <!-- ══════════ FILTER ROW: Cabang Olahraga ══════════ -->
        <?= wpm_sport_filter_row($pdo, $tab, $activeSportKey) ?>

        <!-- ══════════ PROMO BANNERS (admin-configurable) ══════════ -->
        <?php if ($homeBanners !== []) : ?>
        <div class="banner-strip" style="margin-bottom:24px;">
            <div class="banner-strip__grid">
                <?php foreach ($homeBanners as $banner) : ?>
                    <?= wpm_banner_markup($banner) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="news-layout">
            <!-- ══════════ MAIN COLUMN ══════════ -->
            <div class="news-layout__main">
                <?php if ($heroArticle !== null) : ?>
                    <?= wpm_news_hero_card($heroArticle) ?>
                    <?= wpm_render_ad_slot($pdo, 'homepage-hero', 'homepage') ?>
                <?php endif; ?>

                <?php if ($feedArticles !== []) : ?>
                    <div class="news-list" id="wpm-news-list">
                        <?php foreach ($feedArticles as $i => $article) : ?>
                            <?= wpm_news_list_row($article) ?>
                            <?php if ($i === 4) : ?>
                                <?= wpm_render_ad_slot($pdo, 'between-article-cards', 'homepage') ?>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php elseif ($heroArticle === null) : ?>
                    <div class="empty-state"><?= wpm_icon('news') ?><p>Belum ada artikel untuk ditampilkan.</p></div>
                <?php endif; ?>

                <?php if ($totalPages > 1) : ?>
                <!-- "Muat Lebih Banyak" (28 Agu 2026) — ganti numbered pagination
                     yang dirasa kaku di mobile (permintaan operator). JS di
                     assets/js/site.js fetch api/load-more-articles.php dan
                     append hasilnya ke #wpm-news-list. data-* di sini bawa
                     semua state (tab/sport/page) yang tadinya ada di URL
                     query string $paginateUrl(). -->
                <div class="load-more" id="wpm-load-more"
                     data-tab="<?= wpm_esc($tab) ?>"
                     data-sport="<?= wpm_esc($activeSportKey ?? '') ?>"
                     data-next-page="<?= $page + 1 ?>"
                     data-has-more="<?= $page < $totalPages ? '1' : '0' ?>">
                    <button type="button" class="load-more__btn" id="wpm-load-more-btn">Muat Lebih Banyak</button>
                </div>
                <?php endif; ?>
            </div>

            <!-- ══════════ SIDEBAR: SEDANG TREN + IKLAN ══════════ -->
            <aside class="news-layout__sidebar">
                <?php if ($trendingArticles !== []) : ?>
                <div class="trending-panel">
                    <h2 class="trending-panel__title"><?= wpm_icon('flame') ?> Sedang Tren</h2>
                    <?php foreach ($trendingArticles as $i => $article) : ?>
                        <?= wpm_trending_item($article, $i + 1) ?>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>

                <!-- Slot iklan sidebar homepage (10 Agu 2026, permintaan
                     operator) — sebelumnya iklan cuma ada di antara
                     konten berita (homepage-hero, between-article-cards).
                     Slug diperbaiki 11 Agu 2026: operator ternyata sudah
                     punya posisi 'sidebar-right' (bukan 'homepage-sidebar'
                     yang di-tebak awal) di ad_positions — 15 posisi lain
                     sudah lama ada (header, footer, popup, dst, lihat
                     ad-positions.php), jadi pakai slug yang sudah ada
                     itu, bukan bikin slug baru. Render function ini aman
                     dipanggil walau belum ada iklan aktif untuk slot ini
                     — otomatis render string kosong (lihat
                     wpm_render_ad_slot()). -->
                <?= wpm_render_ad_slot($pdo, 'sidebar-right', 'homepage') ?>
            </aside>
        </div>
    </div>

    <!-- ══════════ APLIKASI SAGAGOAL — SEGERA HADIR (shared w/ tentang.php) ══════════ -->
    <?= wpm_app_promo_section($pdo) ?>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
