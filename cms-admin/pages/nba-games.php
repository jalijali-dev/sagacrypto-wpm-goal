<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/TimeHelpers.php';

// Read-only reporting page — same tier as Football Matches.
cms_require_role(['superadmin', 'admin']);

$selfUrl = 'nba-games.php';

// NBA only has one competition (unlike football's tracked_league_ids), so
// there's no league filter here — just date and status, matching
// matches.php's filter bar minus the league dropdown.
$filterDate = trim((string) ($_GET['date'] ?? ''));
$filterStatus = trim((string) ($_GET['status'] ?? ''));

$where = ['1 = 1'];
$params = [];

if ($filterDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDate) === 1) {
    $where[] = "DATE(CONVERT_TZ(g.game_date, '+00:00', '+07:00')) = :date";
    $params['date'] = $filterDate;
}
if ($filterStatus !== '' && preg_match('/^\d+$/', $filterStatus) === 1) {
    $where[] = 'g.status_short = :status';
    $params['status'] = (int) $filterStatus;
}

$whereSql = implode(' AND ', $where);

$games = [];
try {
    $stmt = $pdo->prepare(
        "SELECT g.*, ht.name AS home_name, at.name AS away_name
         FROM nba_games g
         JOIN nba_teams ht ON ht.id = g.home_team_id
         JOIN nba_teams at ON at.id = g.away_team_id
         WHERE {$whereSql}
         ORDER BY g.game_date DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $games = $stmt->fetchAll();
} catch (Throwable $e) {
    // nba_games/nba_teams may not have any rows yet (cron never run) —
    // keep as empty array, the empty-state row below handles it.
}

// Same "last synced" signal as Football Matches: updated_at ticks on every
// sync upsert, so MAX() here is truer than any settings-table timestamp.
$lastSyncedAt = null;
try {
    $lastSyncedAt = $pdo->query('SELECT MAX(updated_at) FROM nba_games')->fetchColumn() ?: null;
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

// Matches the status_short vocabulary from wpm_nba_status_badge() in
// includes/site-bootstrap.php (API-Basketball convention) — kept in sync
// with that function's $labels map.
$STATUS_LABELS = [
    1 => 'Belum Mulai', 2 => 'Live', 3 => 'Selesai',
    4 => 'Ditunda', 5 => 'Delay', 6 => 'Dibatalkan',
];

$pageTitle = 'NBA Games';
$currentNav = 'nba-games';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'NBA Games', 'href' => ''],
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
            <h2 class="section-title">NBA Games</h2>
            <p class="section-lead">Jadwal &amp; skor pertandingan NBA — data diisi otomatis oleh <code>cron/sync_nba_games.php</code>, read-only di sini.</p>
            <p style="margin:6px 0 0;font-size:12.5px;">
                <?php if ($lastSyncedAt !== null) : ?>
                    <span class="pill pill--ok">●</span> Data terakhir disinkronkan: <strong><?= cms_esc(date('d M Y, H:i', strtotime($lastSyncedAt))) ?></strong> <span style="opacity:.6;">(<?= cms_esc($lastSyncedRelative) ?>)</span>
                <?php else : ?>
                    <span class="pill pill--muted">●</span> <span style="opacity:.7;">Belum pernah disync — jalankan <code>cron/sync_nba_games.php</code> atau tombol Sync Sekarang di Livescore API Settings.</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="panel">
        <form class="filter-bar" method="get" action="<?= cms_esc($selfUrl) ?>">
            <input type="date" name="date" class="filter-input" value="<?= cms_esc($filterDate) ?>">
            <select name="status" class="filter-select">
                <option value="">Semua Status</option>
                <?php foreach ($STATUS_LABELS as $code => $label) : ?>
                    <option value="<?= (int) $code ?>"<?= $filterStatus === (string) $code ? ' selected' : '' ?>><?= cms_esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
            <?php if ($filterDate !== '' || $filterStatus !== '') : ?>
                <a href="<?= cms_esc($selfUrl) ?>" class="admin-btn admin-btn--secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Daftar Pertandingan</h3>
            <span class="panel__meta"><?= count($games) ?> pertandingan (maks. 200 terbaru)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Waktu</th>
                        <th>Pertandingan</th>
                        <th>Skor</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($games === []) : ?>
                        <tr><td colspan="4" class="muted">Belum ada pertandingan untuk filter ini. Pastikan <code>cron/sync_nba_games.php</code> sudah pernah jalan.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($games as $g) : ?>
                        <tr>
                            <td><?= cms_esc(wpm_format_match_time((string) $g['game_date'], 'd M Y, H:i')) ?> WIB</td>
                            <td><?= cms_esc((string) $g['home_name']) ?> vs <?= cms_esc((string) $g['away_name']) ?></td>
                            <td><?= $g['home_score'] !== null ? (int) $g['home_score'] . ' – ' . (int) $g['away_score'] : '—' ?></td>
                            <td>
                                <span class="pill pill--<?= (int) $g['status_short'] === 2 ? 'accent' : 'muted' ?>">
                                    <?= cms_esc($STATUS_LABELS[(int) $g['status_short']] ?? (string) $g['status_short']) ?><?= (int) $g['status_short'] === 2 && $g['period_current'] !== null ? " (Q{$g['period_current']})" : '' ?>
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
