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

$tab = ($_GET['tab'] ?? '') === 'terbaru' ? 'terbaru' : 'untuk-anda';
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

/* ── Trending sidebar: featured articles by views, falls back gracefully if empty ── */
$trendingStmt = $pdo->query(
    "SELECT p.*, a.name AS author_name
     FROM pages p
     LEFT JOIN admins a ON a.admin_id = p.author_id
     WHERE p.status = 'published' AND p.is_featured = 1
     ORDER BY p.views DESC, p.published_at DESC
     LIMIT 4"
);
$trendingArticles = $trendingStmt->fetchAll();

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
$canonicalUrl = wpm_site_url('index.php');

require __DIR__ . '/includes/site-header.php';
?>

    <div class="crypto-container">
        <!-- ══════════ LIVE SEKARANG (renders nothing if no live match anywhere) ══════════ -->
        <?= wpm_live_now_widget($pdo) ?>

        <!-- ══════════ TABS: Untuk Anda / Terbaru ══════════ -->
        <div class="news-tabs">
            <a class="news-tabs__item<?= $tab === 'untuk-anda' ? ' is-active' : '' ?>" href="<?= wpm_esc($tabUrl('untuk-anda')) ?>">Untuk Anda</a>
            <a class="news-tabs__item<?= $tab === 'terbaru' ? ' is-active' : '' ?>" href="<?= wpm_esc($tabUrl('terbaru')) ?>">Terbaru</a>
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
                    <div class="news-list">
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
                <nav class="pagination" aria-label="Pagination">
                    <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= wpm_esc($paginateUrl(max(1, $page - 1))) ?>">&larr;</a>
                    <?php for ($p = 1; $p <= $totalPages; $p++) : ?>
                        <a class="<?= $p === $page ? 'is-current' : '' ?>" href="<?= wpm_esc($paginateUrl($p)) ?>"><?= $p ?></a>
                    <?php endfor; ?>
                    <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= wpm_esc($paginateUrl(min($totalPages, $page + 1))) ?>">&rarr;</a>
                </nav>
                <?php endif; ?>
            </div>

            <!-- ══════════ SIDEBAR: SEDANG TREN ══════════ -->
            <?php if ($trendingArticles !== []) : ?>
            <aside class="news-layout__sidebar">
                <div class="trending-panel">
                    <h2 class="trending-panel__title"><?= wpm_icon('flame') ?> Sedang Tren</h2>
                    <?php foreach ($trendingArticles as $i => $article) : ?>
                        <?= wpm_trending_item($article, $i + 1) ?>
                    <?php endforeach; ?>
                </div>
            </aside>
            <?php endif; ?>
        </div>
    </div>

    <!-- ══════════ APLIKASI SAGAGOAL — SEGERA HADIR (shared w/ tentang.php) ══════════ -->
    <?= wpm_app_promo_section() ?>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
