<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

/**
 * Live Streaming — per-match (7 Sep 2026, "next level"). REPLACES the
 * earlier singleton is_live/embed_code form (3-6 Sep 2026 iterations,
 * table `live_streaming_settings`) with a search-and-pick-a-fixture CRUD
 * over a new table `fixture_streams` — any number of matches can be
 * live at once now, each with its own row here and its own public URL
 * (/live/<fixture_id>/<slug>, see live.php + wpm_url_live_match() in
 * includes/site-bootstrap.php).
 *
 * fixture_id is NOT a foreign key to `fixtures` — that table is wholly
 * owned/overwritten by the API-Football sync (includes/LivescoreSync.php),
 * so this deliberately avoids any constraint that sync could ever trip
 * over. Validity is just checked at read-time via a JOIN.
 *
 * Same tier as the old page (admin+, not superadmin-only) — a Playback
 * ID / embed URL is not a secret, same reasoning as before.
 */
cms_require_role(['superadmin', 'admin']);

cms_ensure_table(
    $pdo,
    'fixture_streams',
    "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
     fixture_id INT NOT NULL,
     is_live TINYINT(1) NOT NULL DEFAULT 0,
     embed_code TEXT DEFAULT NULL,
     stream_title VARCHAR(255) DEFAULT NULL,
     stream_description TEXT DEFAULT NULL,
     created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_fixture_id (fixture_id)"
);

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

// ── POST: create/update one fixture_streams row, or delete one ──────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? 'save');

    if ($action === 'delete') {
        $delFixtureId = (int) ($_POST['fixture_id'] ?? 0);
        if ($delFixtureId > 0) {
            $pdo->prepare('DELETE FROM fixture_streams WHERE fixture_id = :id')->execute(['id' => $delFixtureId]);
        }
        $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Pengaturan live streaming pertandingan itu dihapus.'];
        header('Location: ' . $selfUrl, true, 302);
        exit;
    }

    $fixtureId = (int) ($_POST['fixture_id'] ?? 0);
    $isLive = isset($_POST['is_live']) ? 1 : 0;
    $embedCode = trim((string) ($_POST['embed_code'] ?? ''));
    $streamTitle = trim((string) ($_POST['stream_title'] ?? ''));
    $streamDescription = trim((string) ($_POST['stream_description'] ?? ''));

    if ($fixtureId <= 0) {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Pilih dulu pertandingannya lewat pencarian di bawah sebelum menyimpan.'];
        header('Location: ' . $selfUrl, true, 302);
        exit;
    }
    if ($isLive === 1 && $embedCode === '') {
        $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Isi dulu kode embed / link streaming-nya sebelum mengaktifkan status Live untuk pertandingan ini.'];
        header('Location: ' . $selfUrl, true, 302);
        exit;
    }

    // Upsert on the unique fixture_id — one row per match, editing an
    // existing match just re-submits with the same fixture_id.
    $pdo->prepare(
        'INSERT INTO fixture_streams (fixture_id, is_live, embed_code, stream_title, stream_description)
         VALUES (:fixture_id, :is_live, :embed_code, :stream_title, :stream_description)
         ON DUPLICATE KEY UPDATE
            is_live = VALUES(is_live), embed_code = VALUES(embed_code),
            stream_title = VALUES(stream_title), stream_description = VALUES(stream_description)'
    )->execute([
        'fixture_id' => $fixtureId,
        'is_live' => $isLive,
        'embed_code' => $embedCode !== '' ? $embedCode : null,
        'stream_title' => $streamTitle !== '' ? $streamTitle : null,
        'stream_description' => $streamDescription !== '' ? $streamDescription : null,
    ]);

    $_SESSION['cms_flash'] = ['type' => 'success', 'message' => $isLive === 1 ? 'Live diaktifkan untuk pertandingan ini — badge "🔴 Live" sekarang muncul di halaman Sepak Bola & menu situs.' : 'Disimpan (status Live untuk pertandingan ini nonaktif).'];
    header('Location: ' . $selfUrl . '?edit=' . $fixtureId, true, 302);
    exit;
}

// ── Search fixtures (GET ?q=...) — join teams/leagues so results are
// readable, most recent kickoff first. Limited to 20 rows, no pagination
// needed for an admin quick-picker. ──
$searchQuery = trim((string) ($_GET['q'] ?? ''));
$searchResults = [];
if ($searchQuery !== '') {
    $stmt = $pdo->prepare(
        "SELECT f.id, f.kickoff_at, f.status_short, l.name AS league_name,
                ht.name AS home_name, at.name AS away_name
         FROM fixtures f
         JOIN leagues l ON l.id = f.league_id
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         WHERE ht.name LIKE :q OR at.name LIKE :q
         ORDER BY f.kickoff_at DESC
         LIMIT 20"
    );
    $stmt->execute(['q' => '%' . $searchQuery . '%']);
    $searchResults = $stmt->fetchAll();
}

// ── Fixture being added/edited (GET ?edit=<fixture_id>) — prefills the
// form below the search box, whether or not a fixture_streams row
// already exists for it (new match picked from search vs. re-editing an
// existing configured one both flow through the same form). ──
$editFixtureId = (int) ($_GET['edit'] ?? 0);
$editFixture = null;
$editStream = null;
if ($editFixtureId > 0) {
    $stmt = $pdo->prepare(
        "SELECT f.id, f.kickoff_at, f.status_short, l.name AS league_name,
                ht.name AS home_name, at.name AS away_name
         FROM fixtures f
         JOIN leagues l ON l.id = f.league_id
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         WHERE f.id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $editFixtureId]);
    $editFixture = $stmt->fetch() ?: null;

    if ($editFixture !== null) {
        $streamStmt = $pdo->prepare('SELECT * FROM fixture_streams WHERE fixture_id = :id LIMIT 1');
        $streamStmt->execute(['id' => $editFixtureId]);
        $editStream = $streamStmt->fetch() ?: null;
    }
}

// ── All configured matches (for the list below) — join fixtures/teams/
// leagues same as everywhere else so it's readable, most recently
// touched first. ──
$configuredStreams = $pdo->query(
    "SELECT fs.*, f.kickoff_at, f.status_short, l.name AS league_name,
            ht.name AS home_name, at.name AS away_name
     FROM fixture_streams fs
     JOIN fixtures f ON f.id = fs.fixture_id
     JOIN leagues l ON l.id = f.league_id
     JOIN teams ht ON ht.id = f.home_team_id
     JOIN teams at ON at.id = f.away_team_id
     ORDER BY fs.updated_at DESC"
)->fetchAll();

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
            <p class="section-lead">Aktifkan live streaming per pertandingan — bisa lebih dari satu sekaligus. Cari pertandingannya, paste kode embed dari provider streaming apa saja (YouTube Live, Cloudflare Stream, Facebook Live, Twitch, dsb), lalu aktifkan status Live.</p>
        </div>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">1. Cari Pertandingan</h3>
        </div>
        <form class="form-stack" method="get" action="<?= cms_esc($selfUrl) ?>" style="padding: 0 20px 20px;">
            <label class="field">Nama tim
                <input type="text" name="q" value="<?= cms_esc($searchQuery) ?>" placeholder="mis. Real Madrid, Persib, Manchester United...">
            </label>
            <button type="submit" class="admin-btn admin-btn--primary">Cari</button>
        </form>

        <?php if ($searchQuery !== '') : ?>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead><tr><th>Liga</th><th>Pertandingan</th><th style="width:160px;">Kickoff</th><th style="width:100px;"></th></tr></thead>
                    <tbody>
                        <?php if ($searchResults === []) : ?>
                            <tr><td colspan="4" class="muted">Tidak ada pertandingan ditemukan untuk "<?= cms_esc($searchQuery) ?>".</td></tr>
                        <?php endif; ?>
                        <?php foreach ($searchResults as $row) : ?>
                            <tr>
                                <td><?= cms_esc((string) $row['league_name']) ?></td>
                                <td><?= cms_esc((string) $row['home_name']) ?> vs <?= cms_esc((string) $row['away_name']) ?></td>
                                <td class="muted"><?= cms_esc((string) $row['kickoff_at']) ?></td>
                                <td><a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= (int) $row['id'] ?>">Pilih</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($editFixtureId > 0) : ?>
    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">2. Setting Live Streaming</h3>
            <?php if ($editStream !== null) : ?>
                <span class="pill pill--<?= (int) $editStream['is_live'] === 1 ? 'ok' : 'warn' ?>"><?= (int) $editStream['is_live'] === 1 ? '🔴 Sedang Live' : 'Tidak Live' ?></span>
            <?php endif; ?>
        </div>
        <?php if ($editFixture === null) : ?>
            <p class="muted" style="padding:0 20px 20px;">Pertandingan dengan ID <?= (int) $editFixtureId ?> tidak ditemukan.</p>
        <?php else : ?>
            <p style="padding:0 20px;margin:0 0 12px;"><strong><?= cms_esc((string) $editFixture['home_name']) ?> vs <?= cms_esc((string) $editFixture['away_name']) ?></strong> — <?= cms_esc((string) $editFixture['league_name']) ?>, <?= cms_esc((string) $editFixture['kickoff_at']) ?></p>
            <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>" style="padding: 0 20px 20px;">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="fixture_id" value="<?= (int) $editFixtureId ?>">
                <label class="field" style="display:flex; align-items:center; gap:10px; flex-direction:row;">
                    <input type="checkbox" name="is_live" value="1" <?= $editStream !== null && (int) $editStream['is_live'] === 1 ? 'checked' : '' ?> style="width:auto;">
                    <span>Sedang Live sekarang — tampilkan badge "🔴 Live" di halaman Sepak Bola &amp; menu situs</span>
                </label>
                <label class="field">Kode Embed / Link Streaming
                    <textarea name="embed_code" rows="5" placeholder='Paste di sini: kode <iframe>...</iframe> dari YouTube/Cloudflare/Facebook/Twitch, ATAU cukup link video/live-nya saja' style="font-family:monospace;font-size:12.5px;"><?= cms_esc((string) ($editStream['embed_code'] ?? '')) ?></textarea>
                    <small class="field__hint">Boleh paste kode <code>&lt;iframe&gt;</code> lengkap, ATAU cukup link videonya saja — sistem otomatis bungkus jadi iframe kalau yang ditaro cuma link biasa.</small>
                </label>
                <label class="field">Judul Siaran (opsional)
                    <input type="text" name="stream_title" value="<?= cms_esc((string) ($editStream['stream_title'] ?? '')) ?>" placeholder="mis. Real Madrid vs Barcelona — El Clasico">
                </label>
                <label class="field">Deskripsi Siaran (opsional)
                    <textarea name="stream_description" rows="3" placeholder="Info tambahan buat penonton."><?= cms_esc((string) ($editStream['stream_description'] ?? '')) ?></textarea>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary">Simpan</button>
            </form>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Pertandingan yang Sudah Disetting</h3>
            <span class="panel__meta"><?= count($configuredStreams) ?> pertandingan</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead><tr><th>Liga</th><th>Pertandingan</th><th style="width:120px;">Status</th><th style="width:180px;"></th></tr></thead>
                <tbody>
                    <?php if ($configuredStreams === []) : ?>
                        <tr><td colspan="4" class="muted">Belum ada pertandingan yang disetting live streaming.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($configuredStreams as $row) : ?>
                        <tr>
                            <td><?= cms_esc((string) $row['league_name']) ?></td>
                            <td><?= cms_esc((string) $row['home_name']) ?> vs <?= cms_esc((string) $row['away_name']) ?></td>
                            <td><span class="pill pill--<?= (int) $row['is_live'] === 1 ? 'ok' : 'warn' ?>"><?= (int) $row['is_live'] === 1 ? '🔴 Live' : 'Nonaktif' ?></span></td>
                            <td>
                                <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= (int) $row['fixture_id'] ?>">Edit</a>
                                <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:inline;" onsubmit="return confirm('Hapus pengaturan live streaming pertandingan ini?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="fixture_id" value="<?= (int) $row['fixture_id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--ghost">Hapus</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
