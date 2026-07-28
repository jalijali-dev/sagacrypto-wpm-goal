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

/**
 * Rebases a stored sitemap_urls.url onto the CURRENT request's scheme+host,
 * discarding whatever scheme+host it was stored with. Added 26 Jul 2026
 * after Google Search Console rejected the whole sitemap: rows written by
 * cms_sitemap_absolute_url() bake in $_SERVER['HTTP_HOST'] at WRITE time
 * (whenever an article was saved, or "Regenerate Sitemap" was clicked) —
 * if that ever happened from a dev/staging host, the wrong host is frozen
 * into the DB until that exact row is re-saved. Rebasing here instead,
 * every time the public sitemap is actually served, means the host Google
 * sees always matches the domain it fetched the sitemap from — regardless
 * of what host a row happened to be written under.
 */
function sitemap_rebase_url(string $storedUrl, string $currentBase): string
{
    $path = parse_url($storedUrl, PHP_URL_PATH) ?: '/';
    // A stale row can have MORE than just the wrong host baked in — if it
    // was written while this app was served from its local-htdocs folder
    // (http://localhost:8008/wpm_sagagoal.com/...), that folder segment is
    // part of the stored *path* too, not just the host, so a plain
    // scheme+host swap alone would still leave "/wpm_sagagoal.com/" in
    // front of every URL. Strip it if present; harmless no-op otherwise.
    // $currentBase already carries the equivalent prefix for whatever
    // environment is serving THIS request (see sitemap_self_base()), so
    // this can't under- or over-strip between environments.
    $path = preg_replace('#^/wpm_sagagoal\.com(?=/|$)#i', '', $path) ?? $path;
    if ($path === '') {
        $path = '/';
    }
    $query = parse_url($storedUrl, PHP_URL_QUERY);
    return $currentBase . $path . ($query !== null && $query !== '' ? '?' . $query : '');
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

$base = sitemap_self_base();

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
    echo '    <loc>' . sitemap_xml_esc(sitemap_rebase_url((string) $row['url'], $base)) . '</loc>' . "\n";
    echo '    <lastmod>' . sitemap_xml_esc(sitemap_iso8601($row['lastmod'])) . '</lastmod>' . "\n";
    echo '    <changefreq>' . sitemap_xml_esc((string) $row['changefreq']) . '</changefreq>' . "\n";
    echo '    <priority>' . sitemap_xml_esc(number_format((float) $row['priority'], 1)) . '</priority>' . "\n";
    echo '  </url>' . "\n";
}

echo '</urlset>' . "\n";
