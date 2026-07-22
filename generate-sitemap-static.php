<?php
declare(strict_types=1);

/**
 * One-off / on-demand generator for a STATIC sitemap.xml snapshot.
 *
 * The project's normal sitemap (root sitemap.php, wired via .htaccess to
 * /sitemap.xml, /sitemap-articles.xml, etc.) is dynamic — it always
 * reflects the current `sitemap_urls` table and needs zero maintenance.
 * This script exists only because a static *file* was specifically
 * requested (e.g. to match a "flat single-file" example, or a workflow
 * that expects an uploadable artifact).
 *
 * IMPORTANT — read before using:
 * Root .htaccess has `RewriteCond %{REQUEST_FILENAME} -f` before the
 * sitemap RewriteRules, meaning a REAL FILE at sitemap.xml always wins
 * over the dynamic route. So the moment this script writes sitemap.xml,
 * https://sagagoal.com/sitemap.xml stops being live/dynamic and starts
 * serving that frozen snapshot instead — it will NOT include new articles
 * until this page is run again. Use the "Remove static file" button below
 * any time to delete it and instantly go back to the always-fresh dynamic
 * version (the .htaccess rule falls through automatically once the file
 * is gone — no other change needed).
 *
 * Access: same admin login + role as the rest of cms-admin (superadmin/admin).
 */

require_once __DIR__ . '/cms-admin/includes/auth.php';
require_once __DIR__ . '/cms-admin/config/database.php';
require_once __DIR__ . '/cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/cms-admin/includes/sitemap-service.php';

// Manual role check (not cms_require_role()) because that helper's
// redirect target assumes it's being called from cms-admin/ or
// cms-admin/pages/, which resolves wrong from this project-root script.
if (!in_array(cms_admin_role(), ['superadmin', 'admin'], true)) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Kamu tidak punya akses ke halaman ini.'];
    header('Location: cms-admin/dashboard.php', true, 302);
    exit;
}

cms_sitemap_ensure_schema($pdo); // safe on a fresh install where sitemap_urls doesn't exist yet

$staticPath = __DIR__ . '/sitemap.xml';
$message = null;
$messageType = 'success';

function gss_xml_esc(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function gss_iso8601(?string $datetime): string
{
    $ts = $datetime !== null && $datetime !== '' ? strtotime($datetime) : false;
    return date('c', $ts !== false ? $ts : time());
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    cms_verify_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'generate') {
        // Always sync first so the snapshot is complete (homepage, static
        // hub pages, categories, tags, articles) even if nobody has ever
        // opened cms-admin/pages/sitemaps.php before — same bootstrap this
        // project already runs there on first visit.
        cms_sitemap_full_resync($pdo, cms_sitemap_actor());

        $rows = $pdo->query(
            "SELECT url, lastmod, changefreq, priority
               FROM sitemap_urls
              WHERE included = 1 AND status != 'deleted'
              ORDER BY priority DESC, id ASC"
        )->fetchAll();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($rows as $row) {
            $xml .= '  <url>' . "\n";
            $xml .= '    <loc>' . gss_xml_esc((string) $row['url']) . '</loc>' . "\n";
            $xml .= '    <lastmod>' . gss_xml_esc(gss_iso8601($row['lastmod'])) . '</lastmod>' . "\n";
            $xml .= '    <changefreq>' . gss_xml_esc((string) $row['changefreq']) . '</changefreq>' . "\n";
            $xml .= '    <priority>' . gss_xml_esc(number_format((float) $row['priority'], 1)) . '</priority>' . "\n";
            $xml .= '  </url>' . "\n";
        }
        $xml .= '</urlset>' . "\n";

        $written = @file_put_contents($staticPath, $xml);
        if ($written === false) {
            $message = 'Gagal menulis sitemap.xml — cek permission folder project root (butuh write access).';
            $messageType = 'error';
        } else {
            $message = sprintf('Berhasil. %d URL ditulis ke sitemap.xml (%s).', count($rows), date('d M Y H:i:s'));
        }
    }

    if ($action === 'remove_static') {
        if (file_exists($staticPath) && @unlink($staticPath)) {
            $message = 'File statis dihapus — /sitemap.xml sekarang kembali dinamis (live) lagi via sitemap.php.';
        } else {
            $message = 'Tidak ada file statis untuk dihapus, atau gagal menghapus (cek permission).';
            $messageType = 'error';
        }
    }
}

$fileExists = file_exists($staticPath);
$fileMtime = $fileExists ? date('d M Y H:i:s', filemtime($staticPath)) : null;
$fileUrlCount = $fileExists ? (int) substr_count((string) file_get_contents($staticPath), '<url>') : 0;

$liveCount = (int) $pdo->query("SELECT COUNT(*) FROM sitemap_urls WHERE included = 1 AND status != 'deleted'")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Generate Static sitemap.xml — Sagagoal</title>
<style>
  body { font-family: -apple-system, sans-serif; max-width: 640px; margin: 40px auto; padding: 0 16px; color: #222; line-height: 1.55; }
  h1 { font-size: 20px; }
  .box { border: 1px solid #ddd; border-radius: 8px; padding: 16px; margin: 16px 0; }
  .muted { color: #777; font-size: 13px; }
  .msg { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
  .msg--success { background: #e6f6ea; color: #1a7a34; }
  .msg--error { background: #fdeaea; color: #b3261e; }
  button { padding: 8px 16px; border-radius: 6px; border: 1px solid #ccc; background: #f5f5f5; cursor: pointer; font-size: 14px; margin-right: 8px; }
  button.primary { background: #6c3ce0; color: white; border-color: #6c3ce0; }
  a { color: #6c3ce0; }
</style>
</head>
<body>
<h1>Generate static sitemap.xml</h1>
<p class="muted">Snapshot beku dari <code>sitemap_urls</code> — bukan versi live/dinamis. Baca peringatan di komentar file ini kalau belum ngerti trade-off-nya.</p>

<?php if ($message !== null) : ?>
<div class="msg msg--<?= gss_xml_esc($messageType) ?>"><?= gss_xml_esc($message) ?></div>
<?php endif; ?>

<div class="box">
  <strong>Status file statis:</strong>
  <?php if ($fileExists) : ?>
    <p>Ada — <?= $fileUrlCount ?> URL, terakhir digenerate <?= gss_xml_esc((string) $fileMtime) ?>.<br>
    <span class="muted">Selama file ini ada, /sitemap.xml serve konten BEKU ini, bukan versi live.</span></p>
  <?php else : ?>
    <p>Belum ada. /sitemap.xml sekarang masih serve versi dinamis (live) seperti biasa.</p>
  <?php endif; ?>
  <p class="muted">Data live saat ini di database: <?= $liveCount ?> URL siap disertakan.</p>
</div>

<form method="post" style="display:inline;">
  <?= cms_csrf_field() ?>
  <input type="hidden" name="action" value="generate">
  <button type="submit" class="primary"><?= $fileExists ? 'Regenerate sitemap.xml' : 'Generate sitemap.xml' ?></button>
</form>

<?php if ($fileExists) : ?>
<form method="post" style="display:inline;" onsubmit="return confirm('Hapus file statis dan balik ke versi dinamis (live)?');">
  <?= cms_csrf_field() ?>
  <input type="hidden" name="action" value="remove_static">
  <button type="submit">Hapus file statis (balik ke live)</button>
</form>
<?php endif; ?>

<p style="margin-top:24px;"><a href="sitemap.xml" target="_blank">Lihat /sitemap.xml</a> · <a href="cms-admin/dashboard.php">Kembali ke admin</a></p>
</body>
</html>
