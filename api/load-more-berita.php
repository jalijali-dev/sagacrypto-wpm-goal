<?php
declare(strict_types=1);

/**
 * Sagagoal — AJAX "Muat Lebih Banyak" endpoint for kategori.php (the
 * /berita listing page — all 4 modes: all/kategori/tag/league). Same
 * pattern as api/load-more-articles.php (homepage's load-more, 28 Agu
 * 2026) — replaces kategori.php's numbered <nav class="pagination">
 * (7 Sep 2026, operator: "pages halaman ga perlu ada lagi... gunakan
 * model muat lebih banyak... yg pernah dibikin"). Mirrors kategori.php's
 * own $where-building logic exactly (slug / tag / league / all) so page
 * N here always matches what kategori.php itself would have shown for
 * ?page=N. Only ever called for page >= 2 — page 1 stays server-rendered
 * by kategori.php itself.
 *
 * Renders with wpm_article_card() (NOT wpm_news_list_row(), which is the
 * homepage's own compact-row renderer) — kategori.php's grid uses the
 * bigger image+excerpt card, so appended rows must match that, not
 * homepage's format. See includes/site-bootstrap.php for both functions.
 *
 * Deliberately does NOT re-insert the "between-article-cards" ad slot
 * that kategori.php places once, at a fixed index within page 1's own
 * article set — that placement doesn't have an equivalent position once
 * more pages are appended, and duplicating/repositioning it here would
 * need product input kategori.php doesn't currently have either. Appended
 * pages are plain article cards only.
 */

require_once __DIR__ . '/../includes/site-bootstrap.php';

// Same SCRIPT_NAME spoof as api/load-more-articles.php, for the same
// reason: this file physically lives one level deeper (api/) than the
// page it's rendering rows FOR (kategori.php at the site root), and
// wpm_base_path() derives the URL base from $_SERVER['SCRIPT_NAME'].
// Left alone, every absolute-rooted image path built by wpm_image()
// would resolve against "/api" instead of the real site root, 404ing
// every thumbnail in the appended cards.
$_SERVER['SCRIPT_NAME'] = '/kategori.php';

header('Content-Type: text/html; charset=utf-8');

$categorySlug = trim((string) ($_GET['slug'] ?? ''));
$tagSlug = trim((string) ($_GET['tag'] ?? ''));
$leagueId = (int) ($_GET['league'] ?? 0);
$page = max(2, (int) ($_GET['page'] ?? 2)); // page 1 is only ever the initial server-rendered load
$perPage = 9;

$where = "p.status = 'published'";
$params = [];

if ($categorySlug !== '') {
    $catStmt = $pdo->prepare('SELECT id FROM article_categories WHERE slug = :slug LIMIT 1');
    $catStmt->execute(['slug' => $categorySlug]);
    $catId = $catStmt->fetchColumn();
    if ($catId === false) {
        header('X-Has-More: 0');
        exit;
    }
    $where .= ' AND p.category_id = :catId';
    $params['catId'] = (int) $catId;
} elseif ($tagSlug !== '') {
    $tagStmt = $pdo->prepare('SELECT id FROM article_tags WHERE slug = :slug LIMIT 1');
    $tagStmt->execute(['slug' => $tagSlug]);
    $tagId = $tagStmt->fetchColumn();
    if ($tagId === false) {
        header('X-Has-More: 0');
        exit;
    }
    $where .= ' AND p.page_id IN (SELECT page_id FROM article_tag_map WHERE tag_id = :tagId)';
    $params['tagId'] = (int) $tagId;
} elseif ($leagueId > 0) {
    $leagueStmt = $pdo->prepare('SELECT id FROM leagues WHERE id = :id AND is_active = 1 LIMIT 1');
    $leagueStmt->execute(['id' => $leagueId]);
    $leagueRowId = $leagueStmt->fetchColumn();
    if ($leagueRowId === false) {
        header('X-Has-More: 0');
        exit;
    }
    $where .= ' AND p.league_id = :leagueId';
    $params['leagueId'] = (int) $leagueRowId;
}

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM pages p WHERE $where");
$countStmt->execute($params);
$totalArticles = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalArticles / $perPage));

if ($page > $totalPages) {
    header('X-Has-More: 0');
    exit;
}

$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare(
    "SELECT p.*, c.name AS category_name, c.slug AS category_slug
     FROM pages p
     LEFT JOIN article_categories c ON c.id = p.category_id
     WHERE $where
     ORDER BY p.published_at DESC
     LIMIT $perPage OFFSET $offset"
);
$listStmt->execute($params);
$articles = $listStmt->fetchAll();

header('X-Has-More: ' . ($page < $totalPages ? '1' : '0'));

foreach ($articles as $article) {
    echo wpm_article_card($article);
}
