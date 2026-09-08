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
     fixture_id INT NULL DEFAULT NULL,
     is_live TINYINT(1) NOT NULL DEFAULT 0,
     embed_code TEXT DEFAULT NULL,
     stream_title VARCHAR(255) DEFAULT NULL,
     stream_description TEXT DEFAULT NULL,
     is_custom TINYINT(1) NOT NULL DEFAULT 0,
     custom_home_name VARCHAR(255) DEFAULT NULL,
     custom_away_name VARCHAR(255) DEFAULT NULL,
     custom_league_name VARCHAR(255) DEFAULT NULL,
     created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
     updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     UNIQUE KEY uniq_fixture_id (fixture_id)"
);

// Self-heal (8 Sep 2026, "manual/custom live stream" request) for
// databases where fixture_streams was already created by the 7 Sep
// version above (fixture_id NOT NULL, no is_custom/custom_* columns).
// fixture_id must become NULLable so a "custom" entry (not tied to any
// real fixtures row — user wants to be able to spin up a random/manual
// stream, e.g. an event that isn't in the API-Football sync at all) can
// leave it NULL; MySQL's UNIQUE KEY still allows any number of NULLs, so
// multiple custom entries coexist fine alongside real fixture-linked ones.
cms_widen_column($pdo, 'fixture_streams', 'fixture_id', 'INT NULL DEFAULT NULL');
cms_ensure_column($pdo, 'fixture_streams', 'is_custom', 'TINYINT(1) NOT NULL DEFAULT 0');
cms_ensure_column($pdo, 'fixture_streams', 'custom_home_name', 'VARCHAR(255) DEFAULT NULL');
cms_ensure_column($pdo, 'fixture_streams', 'custom_away_name', 'VARCHAR(255) DEFAULT NULL');
cms_ensure_column($pdo, 'fixture_streams', 'custom_league_name', 'VARCHAR(255) DEFAULT NULL');

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
        $delId = (int) ($_POST['id'] ?? 0);
        if ($delId > 0) {
            // Custom entries have no fixture_id, so they're deleted by their
            // own internal `id` instead.
            $pdo->prepare('DELETE FROM fixture_streams WHERE id = :id')->execute(['id' => $delId]);
        } elseif ($delFixtureId > 0) {
            $pdo->prepare('DELETE FROM fixture_streams WHERE fixture_id = :id')->execute(['id' => $delFixtureId]);
        }
        $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Pengaturan live streaming itu dihapus.'];
        header('Location: ' . $selfUrl, true, 302);
        exit;
    }

    if ($action === 'save_custom') {
        // ── Manual/"random" entry — not tied to any real fixtures row
        // (8 Sep 2026 request: "misal mau random bikin streaming, tapi
        // yg sekarang pilih match juga boleh" — this coexists with the
        // fixture-search-and-pick flow above, doesn't replace it). ──
        $customId = (int) ($_POST['id'] ?? 0);
        $homeName = trim((string) ($_POST['custom_home_name'] ?? ''));
        $awayName = trim((string) ($_POST['custom_away_name'] ?? ''));
        $leagueName = trim((string) ($_POST['custom_league_name'] ?? ''));
        $isLive = isset($_POST['is_live']) ? 1 : 0;
        $embedCode = trim((string) ($_POST['embed_code'] ?? ''));
        $streamTitle = trim((string) ($_POST['stream_title'] ?? ''));
        $streamDescription = trim((string) ($_POST['stream_description'] ?? ''));

        if ($homeName === '' || $awayName === '') {
            $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Isi dulu nama kedua tim/peserta untuk entry manual ini.'];
            header('Location: ' . $selfUrl, true, 302);
            exit;
        }
        if ($isLive === 1 && $embedCode === '') {
            $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Isi dulu kode embed / link streaming-nya sebelum mengaktifkan status Live.'];
            header('Location: ' . $selfUrl, true, 302);
            exit;
        }

        if ($customId > 0) {
            $pdo->prepare(
                'UPDATE fixture_streams
                 SET is_live = :is_live, embed_code = :embed_code, stream_title = :stream_title,
                     stream_description = :stream_description, custom_home_name = :home_name,
                     custom_away_name = :away_name, custom_league_name = :league_name
                 WHERE id = :id AND is_custom = 1'
            )->execute([
                'is_live' => $isLive,
                'embed_code' => $embedCode !== '' ? $embedCode : null,
                'stream_title' => $streamTitle !== '' ? $streamTitle : null,
                'stream_description' => $streamDescription !== '' ? $streamDescription : null,
                'home_name' => $homeName,
                'away_name' => $awayName,
                'league_name' => $leagueName !== '' ? $leagueName : null,
                'id' => $customId,
            ]);
            $newId = $customId;
        } else {
            $pdo->prepare(
                'INSERT INTO fixture_streams
                    (fixture_id, is_custom, is_live, embed_code, stream_title, stream_description,
                     custom_home_name, custom_away_name, custom_league_name)
                 VALUES (NULL, 1, :is_live, :embed_code, :stream_title, :stream_description,
                     :home_name, :away_name, :league_name)'
            )->execute([
                'is_live' => $isLive,
                'embed_code' => $embedCode !== '' ? $embedCode : null,
                'stream_title' => $streamTitle !== '' ? $streamTitle : null,
                'stream_description' => $streamDescription !== '' ? $streamDescription : null,
                'home_name' => $homeName,
                'away_name' => $awayName,
                'league_name' => $leagueName !== '' ? $leagueName : null,
            ]);
            $newId = (int) $pdo->lastInsertId();
        }

        $_SESSION['cms_flash'] = ['type' => 'success', 'message' => $isLive === 1 ? 'Live diaktifkan untuk entry manual ini — muncul di halaman /live.' : 'Disimpan (status Live untuk entry manual ini nonaktif).'];
        header('Location: ' . $selfUrl . '?edit_custom=' . $newId, true, 302);
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
    // f.kickoff_at is stored in UTC (same as everywhere else in this
    // codebase — see football.php's CONVERT_TZ usage); wrap it here too
    // so the admin sees WIB like every public-facing page, instead of
    // the raw UTC value 7 hours behind (8 Sep 2026 bug, reported as
    // "jam tandingnya ngaco").
    $stmt = $pdo->prepare(
        "SELECT f.id, CONVERT_TZ(f.kickoff_at, '+00:00', '+07:00') AS kickoff_at, f.status_short,
                l.name AS league_name, ht.name AS home_name, at.name AS away_name
         FROM fixtures f
         JOIN leagues l ON l.id = f.league_id
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         WHERE ht.name LIKE :q1 OR at.name LIKE :q2
         ORDER BY f.kickoff_at DESC
         LIMIT 20"
    );
    // Two distinct placeholders, not the same :q reused twice — this
    // codebase runs with PDO::ATTR_EMULATE_PREPARES => false (real
    // server-side prepares), which does NOT allow binding the same named
    // parameter more than once in one statement (unlike emulated mode).
    // Reusing :q here caused a fatal PDOException -> 500 (7 Sep 2026).
    $likeTerm = '%' . $searchQuery . '%';
    $stmt->execute(['q1' => $likeTerm, 'q2' => $likeTerm]);
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
        "SELECT f.id, CONVERT_TZ(f.kickoff_at, '+00:00', '+07:00') AS kickoff_at, f.status_short,
                l.name AS league_name, ht.name AS home_name, at.name AS away_name
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

// ── Custom entry being added/edited (GET ?edit_custom=<id>) — same idea
// as $editFixture/$editStream above but for manual entries. ──
$editCustomId = (int) ($_GET['edit_custom'] ?? 0);
$editCustom = null;
if ($editCustomId > 0) {
    $stmt = $pdo->prepare('SELECT * FROM fixture_streams WHERE id = :id AND is_custom = 1 LIMIT 1');
    $stmt->execute(['id' => $editCustomId]);
    $editCustom = $stmt->fetch() ?: null;
}

// ── All configured matches (for the list below) — join fixtures/teams/
// leagues for the real-fixture rows; custom rows (fixture_id IS NULL)
// use their own custom_* columns directly, no join needed. UNION so both
// kinds show in one list, most recently touched first. ──
// NOTE: every string column below is wrapped in
// CONVERT(... USING utf8mb4) COLLATE utf8mb4_unicode_ci — without this,
// MySQL throws "Illegal mix of collations for operation 'UNION'"
// because league_name/home_name/away_name come from `teams`/`leagues`
// (whatever collation those tables use) on one side of the UNION but
// straight off fixture_streams' own custom_* columns on the other side,
// and the two don't necessarily share a collation (8 Sep 2026 bug).
$configuredStreams = $pdo->query(
    "SELECT fs.*, CONVERT_TZ(f.kickoff_at, '+00:00', '+07:00') AS kickoff_at, f.status_short,
            CONVERT(l.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS league_name,
            CONVERT(ht.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS home_name,
            CONVERT(at.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS away_name
     FROM fixture_streams fs
     JOIN fixtures f ON f.id = fs.fixture_id
     JOIN leagues l ON l.id = f.league_id
     JOIN teams ht ON ht.id = f.home_team_id
     JOIN teams at ON at.id = f.away_team_id
     WHERE fs.is_custom = 0
     UNION ALL
     SELECT fs.*, NULL AS kickoff_at, NULL AS status_short,
            CONVERT(fs.custom_league_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS league_name,
            CONVERT(fs.custom_home_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS home_name,
            CONVERT(fs.custom_away_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS away_name
     FROM fixture_streams fs
     WHERE fs.is_custom = 1
     ORDER BY updated_at DESC"
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
                                <td class="muted"><?= cms_esc((string) $row['kickoff_at']) ?> WIB</td>
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
            <p style="padding:0 20px;margin:0 0 12px;"><strong><?= cms_esc((string) $editFixture['home_name']) ?> vs <?= cms_esc((string) $editFixture['away_name']) ?></strong> — <?= cms_esc((string) $editFixture['league_name']) ?>, <?= cms_esc((string) $editFixture['kickoff_at']) ?> WIB</p>
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
            <h3 class="panel__title">Atau: Tambah Entry Manual</h3>
            <?php if ($editCustom !== null) : ?>
                <span class="pill pill--<?= (int) $editCustom['is_live'] === 1 ? 'ok' : 'warn' ?>"><?= (int) $editCustom['is_live'] === 1 ? '🔴 Sedang Live' : 'Tidak Live' ?></span>
            <?php endif; ?>
        </div>
        <p class="section-lead" style="padding:0 20px 12px;">Buat siaran live yang nggak terikat pertandingan di database (mis. event/laga yang belum/tidak ke-sync API). Isi nama peserta sendiri, sisanya sama seperti setting pertandingan biasa.</p>
        <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>" style="padding: 0 20px 20px;">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="save_custom">
            <input type="hidden" name="id" value="<?= (int) $editCustomId ?>">
            <div style="display:flex; gap:12px; flex-wrap:wrap;">
                <label class="field" style="flex:1; min-width:200px;">Nama Tim/Peserta 1
                    <input type="text" name="custom_home_name" value="<?= cms_esc((string) ($editCustom['custom_home_name'] ?? '')) ?>" placeholder="mis. Persija Jakarta">
                </label>
                <label class="field" style="flex:1; min-width:200px;">Nama Tim/Peserta 2
                    <input type="text" name="custom_away_name" value="<?= cms_esc((string) ($editCustom['custom_away_name'] ?? '')) ?>" placeholder="mis. Persib Bandung">
                </label>
            </div>
            <label class="field">Liga/Kategori (opsional)
                <input type="text" name="custom_league_name" value="<?= cms_esc((string) ($editCustom['custom_league_name'] ?? '')) ?>" placeholder="mis. Liga 1 Indonesia">
            </label>
            <label class="field" style="display:flex; align-items:center; gap:10px; flex-direction:row;">
                <input type="checkbox" name="is_live" value="1" <?= $editCustom !== null && (int) $editCustom['is_live'] === 1 ? 'checked' : '' ?> style="width:auto;">
                <span>Sedang Live sekarang — tampil di halaman /live</span>
            </label>
            <label class="field">Kode Embed / Link Streaming
                <textarea name="embed_code" rows="5" placeholder='Paste di sini: kode <iframe>...</iframe> ATAU cukup link video/live-nya saja' style="font-family:monospace;font-size:12.5px;"><?= cms_esc((string) ($editCustom['embed_code'] ?? '')) ?></textarea>
            </label>
            <label class="field">Judul Siaran (opsional)
                <input type="text" name="stream_title" value="<?= cms_esc((string) ($editCustom['stream_title'] ?? '')) ?>">
            </label>
            <label class="field">Deskripsi Siaran (opsional)
                <textarea name="stream_description" rows="3"><?= cms_esc((string) ($editCustom['stream_description'] ?? '')) ?></textarea>
            </label>
            <button type="submit" class="admin-btn admin-btn--primary"><?= $editCustomId > 0 ? 'Update Entry Manual' : 'Simpan Entry Manual Baru' ?></button>
        </form>
    </div>

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
                    <?php foreach ($configuredStreams as $row) :
                        $isCustomRow = (int) $row['is_custom'] === 1;
                        $editHref = $isCustomRow
                            ? $selfUrl . '?edit_custom=' . (int) $row['id']
                            : $selfUrl . '?edit=' . (int) $row['fixture_id'];
                        ?>
                        <tr>
                            <td><?= cms_esc((string) ($row['league_name'] ?? '')) ?><?php if ($isCustomRow) : ?> <span class="pill pill--warn" style="font-size:10.5px;">Manual</span><?php endif; ?></td>
                            <td><?= cms_esc((string) $row['home_name']) ?> vs <?= cms_esc((string) $row['away_name']) ?></td>
                            <td><span class="pill pill--<?= (int) $row['is_live'] === 1 ? 'ok' : 'warn' ?>"><?= (int) $row['is_live'] === 1 ? '🔴 Live' : 'Nonaktif' ?></span></td>
                            <td>
                                <a class="admin-btn admin-btn--secondary" href="<?= cms_esc($editHref) ?>">Edit</a>
                                <form method="post" action="<?= cms_esc($selfUrl) ?>" style="display:inline;" onsubmit="return confirm('Hapus pengaturan live streaming ini?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <?php if ($isCustomRow) : ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <?php else : ?>
                                        <input type="hidden" name="fixture_id" value="<?= (int) $row['fixture_id'] ?>">
                                    <?php endif; ?>
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
