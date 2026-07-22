<?php
declare(strict_types=1);

/**
 * Sagagoal — public search results page. /pencarian.php?q=bitcoin
 * Searches published articles only (title, excerpt, content).
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$query = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 9;
$articles = [];
$totalArticles = 0;
$totalPages = 1;

if (mb_strlen($query) >= 2) {
    $like = '%' . $query . '%';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*) FROM pages
         WHERE status = 'published' AND (title LIKE :q1 OR excerpt LIKE :q2 OR content LIKE :q3)"
    );
    $countStmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
    $totalArticles = (int) $countStmt->fetchColumn();
    $totalPages = max(1, (int) ceil($totalArticles / $perPage));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $perPage;

    $listStmt = $pdo->prepare(
        "SELECT p.*, c.name AS category_name
         FROM pages p
         LEFT JOIN article_categories c ON c.id = p.category_id
         WHERE p.status = 'published' AND (p.title LIKE :q1 OR p.excerpt LIKE :q2 OR p.content LIKE :q3)
         ORDER BY p.published_at DESC
         LIMIT $perPage OFFSET $offset"
    );
    $listStmt->execute(['q1' => $like, 'q2' => $like, 'q3' => $like]);
    $articles = $listStmt->fetchAll();
}

$pageTitle = $query !== '' ? 'Hasil Pencarian: ' . $query . ' — Sagagoal' : 'Pencarian — Sagagoal';
$pageDescription = 'Cari berita bola, jadwal pertandingan, dan artikel di Sagagoal.';
$activeNav = '';
$canonicalUrl = wpm_site_url(wpm_url_pencarian());

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Pencarian</nav>
        <span class="section-kicker">Cari</span>
        <h1>Cari Artikel</h1>
        <form class="search-form" method="get" action="<?= wpm_esc(wpm_url_pencarian()) ?>">
            <input type="search" name="q" value="<?= wpm_esc($query) ?>" placeholder="Cari berita bola, tim, atau topik lainnya..." minlength="2" required>
            <button type="submit"><?= wpm_icon('search') ?></button>
        </form>
    </div>
</section>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <?php if ($query === '') : ?>
            <div class="empty-state"><?= wpm_icon('search') ?><p>Masukkan kata kunci untuk mulai mencari.</p></div>
        <?php elseif (mb_strlen($query) < 2) : ?>
            <div class="empty-state"><?= wpm_icon('search') ?><p>Kata kunci minimal 2 karakter.</p></div>
        <?php elseif ($articles === []) : ?>
            <div class="empty-state"><?= wpm_icon('search') ?><p>Tidak ada hasil untuk "<?= wpm_esc($query) ?>".</p></div>
        <?php else : ?>
            <p style="color:var(--text-muted);margin-bottom:24px;"><?= (int) $totalArticles ?> hasil untuk "<?= wpm_esc($query) ?>"</p>
            <div class="crypto-grid crypto-grid--3">
                <?php foreach ($articles as $article) : ?>
                    <?= wpm_article_card($article) ?>
                <?php endforeach; ?>
            </div>
            <?php if ($totalPages > 1) : ?>
            <nav class="pagination" aria-label="Pagination">
                <a class="<?= $page <= 1 ? 'is-disabled' : '' ?>" href="<?= wpm_esc(wpm_url_pencarian($query)) ?>&page=<?= max(1, $page - 1) ?>">&larr;</a>
                <?php for ($p = 1; $p <= $totalPages; $p++) : ?>
                    <a class="<?= $p === $page ? 'is-current' : '' ?>" href="<?= wpm_esc(wpm_url_pencarian($query)) ?>&page=<?= $p ?>"><?= $p ?></a>
                <?php endfor; ?>
                <a class="<?= $page >= $totalPages ? 'is-disabled' : '' ?>" href="<?= wpm_esc(wpm_url_pencarian($query)) ?>&page=<?= min($totalPages, $page + 1) ?>">&rarr;</a>
            </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
