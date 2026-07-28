<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/TimeHelpers.php';

// Read-only reporting page — same tier as Ad Statistics.
cms_require_role(['superadmin', 'admin']);

$selfUrl = 'matches.php';

$filterLeagueId = (int) ($_GET['league_id'] ?? 0);
$filterDate = trim((string) ($_GET['date'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

$where = ['1 = 1'];
$params = [];

if ($filterLeagueId > 0) {
    $where[] = 'f.league_id = :league_id';
    $params['league_id'] = $filterLeagueId;
}
if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate) === 1) {
    $where[] = "DATE(CONVERT_TZ(f.kickoff_at, '+00:00', '+07:00')) = :date";
    $params['date'] = $filterDate;
}
if ($filterStatus !== '') {
    $where[] = 'f.status_short = :status';
    $params['status'] = $filterStatus;
}

$whereSql = implode(' AND ', $where);

$matches = [];
$leagues = [];
try {
    $stmt = $pdo->prepare(
        "SELECT f.*, l.name AS league_name, ht.name AS home_name, at.name AS away_name
         FROM fixtures f
         JOIN leagues l ON l.id = f.league_id
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         WHERE {$whereSql}
         ORDER BY f.kickoff_at DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $matches = $stmt->fetchAll();

    $leagues = $pdo->query('SELECT id, name FROM leagues ORDER BY sort_order ASC, name ASC')->fetchAll();
} catch (Throwable $e) {
    // fixtures/leagues/teams may not have any rows yet (cron never run) —
    // keep both as empty arrays, the empty-state row below handles it.
}

// "Data ini hidup atau statis?" indicator — fixtures.updated_at ticks on
// every sync upsert (ON DUPLICATE KEY UPDATE still triggers MySQL's ON
// UPDATE CURRENT_TIMESTAMP), so MAX() here is a truer "last synced at"
// signal than livescore_api_settings (which only records Test Connection
// checks, not actual sync runs).
$lastSyncedAt = null;
try {
    $lastSyncedAt = $pdo->query('SELECT MAX(updated_at) FROM fixtures')->fetchColumn() ?: null;
} catch (Throwable $e) {
    $lastSyncedAt = null;
}

$lastSyncedRelative = null;
if ($lastSyncedAt !== null) {
    $diffSeconds = time() - strtotime($lastSyncedAt);
    if ($diffSeconds < 60) {
        $lastSyncedRelative = 'baru saja';
    } elseif ($diffSeconds < 3600) {
        $lastSyncedRelative = (int) floor($diffSeconds / 60) . ' menit lalu';
    } elseif ($diffSeconds < 86400) {
        $lastSyncedRelative = (int) floor($diffSeconds / 3600) . ' jam lalu';
    } else {
        $lastSyncedRelative = (int) floor($diffSeconds / 86400) . ' hari lalu';
    }
}

$STATUS_LABELS = [
    'NS' => 'Belum Mulai', '1H' => 'Babak 1', 'HT' => 'Half-time', '2H' => 'Babak 2',
    'FT' => 'Selesai', 'PST' => 'Ditunda', 'CANC' => 'Dibatalkan',
];

$pageTitle = 'Football Matches';
$currentNav = 'matches';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Football Matches', 'href' => ''],
];

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Football Matches</h2>
            <p class="section-lead">Jadwal &amp; skor pertandingan sepak bola — data diisi otomatis oleh <code>cron/sync_fixtures.php</code>, read-only di sini.</p>
            <p style="margin:6px 0 0;font-size:12.5px;">
                <?php if ($lastSyncedAt !== null) : ?>
                    <span class="pill pill--ok">●</span> Data terakhir disinkronkan: <strong><?= cms_esc(date('d M Y, H:i', strtotime($lastSyncedAt))) ?></strong> <span style="opacity:.6;">(<?= cms_esc($lastSyncedRelative) ?>)</span>
                <?php else : ?>
                    <span class="pill pill--muted">●</span> <span style="opacity:.7;">Belum pernah disync — jalankan <code>cron/sync_fixtures.php</code> atau tombol Sync Sekarang di Livescore API Settings.</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="panel">
        <form class="filter-bar" method="get" action="<?= cms_esc($selfUrl) ?>">
            <select name="league_id" class="filter-select">
                <option value="">Semua Liga</option>
                <?php foreach ($leagues as $league) : ?>
                    <option value="<?= (int) $league['id'] ?>"<?= $filterLeagueId === (int) $league['id'] ? ' selected' : '' ?>><?= cms_esc((string) $league['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <input type="date" name="date" class="filter-input" value="<?= cms_esc($filterDate) ?>">
            <select name="status" class="filter-select">
                <option value="">Semua Status</option>
                <?php foreach ($STATUS_LABELS as $code => $label) : ?>
                    <option value="<?= cms_esc($code) ?>"<?= $filterStatus === $code ? ' selected' : '' ?>><?= cms_esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
            <?php if ($filterLeagueId > 0 || $filterDate !== '' || $filterStatus !== '') : ?>
                <a href="<?= cms_esc($selfUrl) ?>" class="admin-btn admin-btn--secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Daftar Pertandingan</h3>
            <span class="panel__meta"><?= count($matches) ?> pertandingan (maks. 200 terbaru)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Liga</th>
                        <th>Pertandingan</th>
                        <th>Skor</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($matches === []) : ?>
                        <tr><td colspan="5" class="muted">Belum ada pertandingan untuk filter ini. Pastikan <code>cron/sync_fixtures.php</code> sudah pernah jalan.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($matches as $fx) : ?>
                        <tr>
                            <td><?= cms_esc(wpm_format_match_time((string) $fx['kickoff_at'], 'd M Y, H:i')) ?> WIB</td>
                            <td><?= cms_esc((string) $fx['league_name']) ?></td>
                            <td><?= cms_esc((string) $fx['home_name']) ?> vs <?= cms_esc((string) $fx['away_name']) ?></td>
                            <td><?= $fx['home_score'] !== null ? (int) $fx['home_score'] . ' – ' . (int) $fx['away_score'] : '—' ?></td>
                            <td>
                                <span class="pill pill--<?= in_array($fx['status_short'], ['1H', 'HT', '2H'], true) ? 'accent' : 'muted' ?>">
                                    <?= cms_esc($STATUS_LABELS[$fx['status_short']] ?? (string) $fx['status_short']) ?><?= $fx['elapsed'] !== null ? " ({$fx['elapsed']}')" : '' ?>
                                </span>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
