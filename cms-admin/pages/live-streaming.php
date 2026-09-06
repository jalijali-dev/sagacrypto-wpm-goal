<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

/**
 * Live Streaming settings (6 Sep 2026, brief "Live Streaming — Cloudflare
 * Stream"). Singleton-row settings page — same pattern as site-settings.php
 * (one config row, form posts back to itself) rather than gsc-settings.php's
 * multi-step wizard, since there's no external OAuth-style handshake here:
 * the operator just pastes a Cloudflare Stream Playback ID (obtained from
 * their own Cloudflare dashboard after pointing OBS at the RTMP ingest URL
 * + Stream Key — neither of which this codebase ever sees or stores) and
 * flips an is_live switch manually before/after going live in OBS.
 *
 * Deliberately does NOT store an RTMP URL or Stream Key anywhere — those
 * only ever live inside OBS's own Settings > Stream panel. This table only
 * holds what the PUBLIC site needs to embed playback (Playback ID) and
 * decide what to show (is_live), see includes/site-bootstrap.php's
 * wpm_live_streaming_settings() for the read side and public/live.php for
 * the embed.
 *
 * Same tier as Site Settings (admin+, not superadmin-only) — this isn't a
 * raw-credential page like AI Credentials/GSC Settings, a Playback ID is
 * not a secret (it's already public knowledge for anyone who has the
 * publish/watch link Cloudflare's dashboard also exposes).
 */
cms_require_role(['superadmin', 'admin']);

cms_ensure_table(
    $pdo,
    'live_streaming_settings',
    "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     is_live TINYINT(1) NOT NULL DEFAULT 0,
     playback_id VARCHAR(255) DEFAULT NULL,
     stream_title VARCHAR(255) DEFAULT NULL,
     stream_description TEXT DEFAULT NULL,
     updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
);
// Make sure exactly one row always exists (singleton), same idiom as
// gsc_settings — everything below assumes fetch() never returns false.
if ((int) $pdo->query('SELECT COUNT(*) FROM live_streaming_settings')->fetchColumn() === 0) {
    $pdo->exec('INSERT INTO live_streaming_settings (is_live) VALUES (0)');
}

$pageTitle = 'Live Streaming';
$currentNav = 'live-streaming';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Live Streaming', 'href' => ''],
];

$selfUrl = 'live-streaming.php';

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    // CSRF already verified globally by includes/auth.php's cms_verify_csrf()
    // call for every POST request — no need to call it again here (matches
    // gsc-settings.php/site-settings-update.php's convention).
    $isLive = isset($_POST['is_live']) ? 1 : 0;
    $playbackId = trim((string) ($_POST['playback_id'] ?? ''));
    $streamTitle = trim((string) ($_POST['stream_title'] ?? ''));
    $streamDescription = trim((string) ($_POST['stream_description'] ?? ''));

    // Guard rail: don't let the operator flip is_live on with no Playback
    // ID set — that would show "🔴 Live" in the nav across the whole site
    // while live.php has nothing to actually embed.
    if ($isLive === 1 && $playbackId === '') {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Isi dulu Playback ID sebelum mengaktifkan status Live — kalau kosong, halaman /live tidak akan ada video buat ditampilkan.'];
        header('Location: ' . $selfUrl, true, 302);
        exit;
    }

    $pdo->prepare(
        'UPDATE live_streaming_settings
            SET is_live = :is_live, playback_id = :playback_id,
                stream_title = :stream_title, stream_description = :stream_description
          ORDER BY id ASC LIMIT 1'
    )->execute([
        'is_live' => $isLive,
        'playback_id' => $playbackId !== '' ? $playbackId : null,
        'stream_title' => $streamTitle !== '' ? $streamTitle : null,
        'stream_description' => $streamDescription !== '' ? $streamDescription : null,
    ]);

    $_SESSION['cms_flash'] = ['type' => 'success', 'message' => $isLive === 1 ? 'Status Live diaktifkan — badge "🔴 Live" sekarang muncul di menu situs.' : 'Status Live dimatikan — menu kembali ke teks "Live Streaming" biasa.'];
    header('Location: ' . $selfUrl, true, 302);
    exit;
}

$settings = $pdo->query('SELECT * FROM live_streaming_settings LIMIT 1')->fetch();
if ($settings === false) {
    $settings = ['is_live' => 0, 'playback_id' => '', 'stream_title' => '', 'stream_description' => ''];
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Live Streaming</h2>
            <p class="section-lead">Kelola status siaran langsung di sagagoal.com/live — stream dari OBS ke Cloudflare Stream, lalu paste Playback ID-nya di sini.</p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Setup Cloudflare Stream</h3>
        </div>
        <div style="padding: 0 20px 20px;">
            <p class="muted" style="margin: 0 0 8px;">Langkah singkat (sekali setup, dari dashboard Cloudflare Stream kamu sendiri — bukan dari panel ini):</p>
            <ol style="margin: 0; padding-left: 20px; color: var(--text-muted, #8a93a6); font-size: 13.5px; line-height: 1.7;">
                <li>Dashboard Cloudflare &rarr; Stream &rarr; Live Inputs &rarr; Create Live Input</li>
                <li>Copy <strong>RTMP URL</strong> + <strong>Stream Key</strong> ke OBS (Settings &rarr; Stream &rarr; Custom) — <strong>jangan</strong> ditaro di panel ini, itu cukup di OBS saja</li>
                <li>Copy <strong>Playback ID</strong> (bukan Stream Key) dari Live Input yang sama, paste di form di bawah</li>
                <li>Sebelum mulai siaran di OBS: aktifkan checkbox "Sedang Live" di bawah ini. Selesai siaran: matikan lagi</li>
            </ol>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Status &amp; Playback</h3>
            <span class="pill pill--<?= (int) $settings['is_live'] === 1 ? 'ok' : 'warn' ?>"><?= (int) $settings['is_live'] === 1 ? '🔴 Sedang Live' : 'Tidak Live' ?></span>
        </div>
        <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>" style="padding: 0 20px 20px;">
            <?= cms_csrf_field() ?>
            <label class="field" style="display:flex; align-items:center; gap:10px; flex-direction:row;">
                <input type="checkbox" name="is_live" value="1" <?= (int) $settings['is_live'] === 1 ? 'checked' : '' ?> style="width:auto;">
                <span>Sedang Live sekarang — tampilkan badge "🔴 Live" di menu situs</span>
            </label>
            <label class="field">Playback ID (Cloudflare Stream)
                <input type="text" name="playback_id" value="<?= cms_esc((string) ($settings['playback_id'] ?? '')) ?>" placeholder="mis. a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6">
                <small class="field__hint">Diambil dari Live Input di dashboard Cloudflare Stream — BUKAN Stream Key (Stream Key cuma buat OBS, jangan pernah ditaro di sini).</small>
            </label>
            <label class="field">Judul Siaran (opsional)
                <input type="text" name="stream_title" value="<?= cms_esc((string) ($settings['stream_title'] ?? '')) ?>" placeholder="mis. Live: Timnas Indonesia vs Malaysia">
            </label>
            <label class="field">Deskripsi Siaran (opsional)
                <textarea name="stream_description" rows="3" placeholder="Info tambahan buat penonton, jadwal, dsb."><?= cms_esc((string) ($settings['stream_description'] ?? '')) ?></textarea>
            </label>
            <button type="submit" class="admin-btn admin-btn--primary">Simpan</button>
        </form>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
