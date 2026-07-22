<?php
declare(strict_types=1);

/**
 * Public sitemap XML endpoint(s) — Sagagoal (14 Jul 2026).
 *
 * Deliberately minimal dependencies (just the DB connection) so this file
 * stays fast and never risks corrupting the XML output with stray HTML/
 * whitespace the way including the full site-bootstrap.php could. It never
 * *computes* anything — every value it prints comes straight out of
 * `sitemap_urls`, which cms-admin/includes/sitemap-service.php keeps in
 * sync with real content via hooks on every article/category/tag/redirect
 * save (see that file's header comment). Reachable via clean URLs mapped
 * in the root .htaccess:
 *   /sitemap.xml            -> sitemap.php?type=index
 *   /sitemap-index.xml      -> sitemap.php?type=index
 *   /sitemap-pages.xml      -> sitemap.php?type=pages
 *   /sitemap-articles.xml   -> sitemap.php?type=articles
 *   /sitemap-categories.xml -> sitemap.php?type=categories
 *   /sitemap-custom.xml     -> sitemap.php?type=custom
 */

require_once __DIR__ . '/cms-admin/config/database.php';

function sitemap_xml_esc(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sitemap_iso8601(?string $datetime): string
{
    $ts = $datetime !== null && $datetime !== '' ? strtotime($datetime) : false;
    return date('c', $ts !== false ? $ts : time());
}

/** scheme://host — used to build each <sitemap><loc> in the index. */
function sitemap_self_base(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
    $scriptDir = rtrim(str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/sitemap.php'))), '/');
    return $scheme . '://' . $host . $scriptDir;
}

$type = (string) ($_GET['type'] ?? 'index');
$fileMap = [
    'pages'      => 'sitemap-pages.xml',
    'articles'   => 'sitemap-articles.xml',
    'categories' => 'sitemap-categories.xml',
    'custom'     => 'sitemap-custom.xml',
];

header('Content-Type: application/xml; charset=UTF-8');

if ($type === 'index' || !isset($fileMap[$type])) {
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $base = sitemap_self_base();
    foreach ($fileMap as $slug => $filename) {
        try {
            $row = $pdo->prepare(
                "SELECT COUNT(*) AS cnt, MAX(lastmod) AS latest
                 FROM sitemap_urls
                 WHERE sitemap_file = :file AND included = 1 AND status != 'deleted'"
            );
            $row->execute(['file' => $filename]);
            $agg = $row->fetch();
        } catch (Throwable $e) {
            $agg = ['cnt' => 0, 'latest' => null];
        }

        if ((int) ($agg['cnt'] ?? 0) < 1) {
            continue; // don't advertise an empty sub-sitemap
        }

        echo '  <sitemap>' . "\n";
        echo '    <loc>' . sitemap_xml_esc($base . '/' . $filename) . '</loc>' . "\n";
        echo '    <lastmod>' . sitemap_xml_esc(sitemap_iso8601($agg['latest'])) . '</lastmod>' . "\n";
        echo '  </sitemap>' . "\n";
    }

    echo '</sitemapindex>' . "\n";
    exit;
}

$filename = $fileMap[$type];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

try {
    $stmt = $pdo->prepare(
        "SELECT url, lastmod, changefreq, priority
         FROM sitemap_urls
         WHERE sitemap_file = :file AND included = 1 AND status != 'deleted'
         ORDER BY priority DESC, id ASC"
    );
    $stmt->execute(['file' => $filename]);
    $rows = $stmt->fetchAll();
} catch (Throwable $e) {
    $rows = [];
}

foreach ($rows as $row) {
    echo '  <url>' . "\n";
    echo '    <loc>' . sitemap_xml_esc((string) $row['url']) . '</loc>' . "\n";
    echo '    <lastmod>' . sitemap_xml_esc(sitemap_iso8601($row['lastmod'])) . '</lastmod>' . "\n";
    echo '    <changefreq>' . sitemap_xml_esc((string) $row['changefreq']) . '</changefreq>' . "\n";
    echo '    <priority>' . sitemap_xml_esc(number_format((float) $row['priority'], 1)) . '</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '</urlset>' . "\n";
