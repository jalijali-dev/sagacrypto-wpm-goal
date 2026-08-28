<?php
declare(strict_types=1);

/**
 * Sagagoal — AJAX "Muat Lebih Banyak" endpoint for the homepage feed
 * (index.php). Replaces the old numbered-pagination <nav> there (28 Agu
 * 2026, operator request — numbered 1..12 pagination felt clunky on
 * mobile). Mirrors index.php's own query logic exactly (same $where /
 * $orderBy / $perPage) so page N here always matches what index.php
 * itself would have shown for ?page=N — this is NOT the hero-card page 1,
 * it's only ever called for page >= 2 (hero card + hasSport logic doesn't
 * apply here, see index.php's own $heroArticle comment).
 *
 * Uses includes/site-bootstrap.php (not the lighter cms-admin/config
 * pattern api/push-subscribe.php uses) because rendering article rows
 * needs wpm_news_list_row() / wpm_esc() / wpm_image() / wpm_url_artikel()
 * etc., all defined there — this endpoint only ever returns an HTML
 * fragment, never JSON, so pulling in the full public bootstrap is fine.
 */

require_once __DIR__ . '/../includes/site-bootstrap.php';

header('Content-Type: text/html; charset=utf-8');

$tab = ($_GET['tab'] ?? '') === 'untuk-anda' ? 'untuk-anda' : 'terbaru';
$page = max(2, (int) ($_GET['page'] ?? 2)); // page 1 is only ever the initial server-rendered load
$perPage = 10;

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

if ($page > $totalPages) {
    // Nothing more to load — empty body + we still report hasMore via header
    // so the front-end JS can hide the button without guessing from an
    // empty response body (an empty string is also what a genuine
    // zero-article page would look like).
    header('X-Has-More: 0');
    exit;
}

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

header('X-Has-More: ' . ($page < $totalPages ? '1' : '0'));

foreach ($feedArticles as $article) {
    echo wpm_news_list_row($article);
}
