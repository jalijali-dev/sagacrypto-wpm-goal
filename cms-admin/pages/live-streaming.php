<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

/**
 * Live Streaming settings (6 Sep 2026, brief "Live Streaming"). Singleton-
 * row settings page — same pattern as site-settings.php (one config row,
 * form posts back to itself) rather than gsc-settings.php's multi-step
 * wizard, since there's no OAuth-style handshake here.
 *
 * Provider-agnostic by design (revised same day, operator: "biar bisa
 * dinamis copy paste embed apapun... stream pake apa aja, tinggal
 * disetting"). First draft of this page had a Cloudflare-Stream-only
 * Playback ID field, then a provider dropdown (Cloudflare/YouTube) with
 * ID-extraction logic — both scrapped in favor of ONE free-form
 * `embed_code` field: the operator pastes whatever embed snippet their
 * streaming provider's own "Share > Embed" button gives them (YouTube
 * Live, Cloudflare Stream, Facebook Live, Twitch, Restream, a raw HLS
 * player URL, literally anything with an iframe-embeddable form), and
 * live.php renders it close to verbatim — see wpm_render_live_embed() in
 * includes/site-bootstrap.php for exactly how "close to verbatim" works
 * (accepts either a full <iframe> snippet OR a bare URL that gets
 * wrapped in one).
 *
 * This means there is deliberately NO validation of embed_code's content
 * beyond a coarse iframe/URL shape check — an admin-only field trusted at
 * the same level as special_pages.content (already raw HTML rendered
 * unescaped on the public site, see page.php), NOT a public-facing input.
 * Never expose this field or its raw value to non-admin-role users.
 *
 * Deliberately does NOT store any RTMP URL / Stream Key / platform
 * credential anywhere — those only ever live inside OBS's own Settings >
 * Stream panel (or whichever tool the operator is using to go live).
 */
cms_require_role(['superadmin', 'admin']);

cms_ensure_table(
    $pdo,
    'live_streaming_settings',
    "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     is_live TINYINT(1) NOT NULL DEFAULT 0,
     embed_code TEXT DEFAULT NULL,
     stream_title VARCHAR(255) DEFAULT NULL,
     stream_description TEXT DEFAULT NULL,
     updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
);
// Self-heal columns for the handful of earlier same-day iterations of this
// table (playback_id / embed_provider, from the Cloudflare-only and then
// provider-dropdown drafts) — add the new column, drop the old ones if
// present. Safe to run on every load (checks INFORMATION_SCHEMA first).
cms_ensure_column($pdo, 'live_streaming_settings', 'embed_code', 'TEXT DEFAULT NULL AFTER is_live');
try {
    $oldColsCheck = $pdo->query(
        "SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'live_streaming_settings'
            AND COLUMN_NAME IN ('playback_id', 'embed_provider')"
    )->fetchAll(PDO::FETCH_COLUMN);
    // One-time migration of any value already saved under the old
    // Cloudflare-only `playback_id` column into the new generic
    // `embed_code`, so an operator who already configured this earlier
    // today doesn't lose their setting.
    if (in_array('playback_id', $oldColsCheck, true)) {
        $pdo->exec(
            "UPDATE live_streaming_settings
                SET embed_code = CASE
                    WHEN (embed_code IS NULL OR embed_code = '') AND playback_id IS NOT NULL AND playback_id <> ''
                    THEN CONCAT('https://iframe.cloudflarestream.com/', playback_id)
                    ELSE embed_code
                END"
        );
        $pdo->exec('ALTER TABLE live_streaming_settings DROP COLUMN playback_id');
    }
    if (in_array('embed_provider', $oldColsCheck, true)) {
        $pdo->exec('ALTER TABLE live_streaming_settings DROP COLUMN embed_provider');
    }
} catch (Throwable $e) {
    // Best-effort cleanup — if it fails, the old columns just sit unused,
    // nothing here depends on them being gone.
}
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
    $embedCode = trim((string) ($_POST['embed_code'] ?? ''));
    $streamTitle = trim((string) ($_POST['stream_title'] ?? ''));
    $streamDescription = trim((string) ($_POST['stream_description'] ?? ''));

    // Guard rail: don't let the operator flip is_live on with nothing to
    // embed — that would show "🔴 Live" in the nav across the whole site
    // while live.php has no player to actually show.
    if ($isLive === 1 && $embedCode === '') {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Isi dulu kode embed / link streaming-nya sebelum mengaktifkan status Live — kalau kosong, halaman /live tidak akan ada video buat ditampilkan.'];
        header('Location: ' . $selfUrl, true, 302);
        exit;
    }

    $pdo->prepare(
        'UPDATE live_streaming_settings
            SET is_live = :is_live, embed_code = :embed_code,
                stream_title = :stream_title, stream_description = :stream_description
          ORDER BY id ASC LIMIT 1'
    )->execute([
        'is_live' => $isLive,
        'embed_code' => $embedCode !== '' ? $embedCode : null,
        'stream_title' => $streamTitle !== '' ? $streamTitle : null,
        'stream_description' => $streamDescription !== '' ? $streamDescription : null,
    ]);

    $_SESSION['cms_flash'] = ['type' => 'success', 'message' => $isLive === 1 ? 'Status Live diaktifkan — badge "🔴 Live" sekarang muncul di menu situs.' : 'Status Live dimatikan — menu kembali ke teks "Live Streaming" biasa.'];
    header('Location: ' . $selfUrl, true, 302);
    exit;
}

$settings = $pdo->query('SELECT * FROM live_streaming_settings LIMIT 1')->fetch();
if ($settings === false) {
    $settings = ['is_live' => 0, 'embed_code' => '', 'stream_title' => '', 'stream_description' => ''];
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
            <p class="section-lead">Kelola status siaran langsung di sagagoal.com/live — paste kode embed dari provider streaming apa saja (YouTube Live, Cloudflare Stream, Facebook Live, Twitch, dsb), lalu aktifkan status Live saat mulai siaran.</p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Cara Setup (Streaming)</h3>
        </div>
        <div style="padding: 0 20px 20px;">
            <p class="muted" style="margin: 0 0 8px;">Langkah singkat — bisa pakai provider streaming apa saja, semua dilakukan di dashboard provider itu sendiri, bukan di panel ini:</p>
            <ol style="margin: 0; padding-left: 20px; color: var(--text-muted, #8a93a6); font-size: 13.5px; line-height: 1.7;">
                <li>Siapkan Live Stream di provider pilihanmu (contoh: YouTube Studio &rarr; Buat &rarr; Live, atau Cloudflare Stream &rarr; Live Inputs, atau Facebook Live, dsb) — di situ kamu akan dapat <strong>RTMP URL + Stream Key</strong> buat dipasang di OBS (Settings &rarr; Stream &rarr; Custom). RTMP URL/Stream Key TIDAK PERNAH ditaro di panel ini, cukup di OBS saja.</li>
                <li>Di provider yang sama, cari tombol <strong>"Share" / "Embed" / "Sematkan"</strong> pada video live-nya — itu akan kasih kode <code>&lt;iframe&gt;...&lt;/iframe&gt;</code> atau sebuah link video.</li>
                <li>Copy kode/link itu, paste utuh di kolom <strong>"Kode Embed / Link Streaming"</strong> di bawah.</li>
                <li>Sebelum mulai siaran di OBS: aktifkan checkbox "Sedang Live" di bawah ini. Selesai siaran: matikan lagi.</li>
            </ol>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Status &amp; Embed</h3>
            <span class="pill pill--<?= (int) $settings['is_live'] === 1 ? 'ok' : 'warn' ?>"><?= (int) $settings['is_live'] === 1 ? '🔴 Sedang Live' : 'Tidak Live' ?></span>
        </div>
        <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>" style="padding: 0 20px 20px;">
            <?= cms_csrf_field() ?>
            <label class="field" style="display:flex; align-items:center; gap:10px; flex-direction:row;">
                <input type="checkbox" name="is_live" value="1" <?= (int) $settings['is_live'] === 1 ? 'checked' : '' ?> style="width:auto;">
                <span>Sedang Live sekarang — tampilkan badge "🔴 Live" di menu situs</span>
            </label>
            <label class="field">Kode Embed / Link Streaming
                <textarea name="embed_code" rows="5" placeholder='Paste di sini: kode <iframe>...</iframe> dari YouTube/Cloudflare/Facebook/Twitch, ATAU cukup link video/live-nya saja' style="font-family:monospace;font-size:12.5px;"><?= cms_esc((string) ($settings['embed_code'] ?? '')) ?></textarea>
                <small class="field__hint">Boleh paste kode <code>&lt;iframe&gt;</code> lengkap (paling akurat, ambil dari tombol "Embed"/"Sematkan" provider-nya), ATAU cukup link videonya saja (mis. link YouTube atau URL player Cloudflare) — sistem akan otomatis bungkus jadi iframe kalau yang ditaro cuma link biasa.</small>
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
