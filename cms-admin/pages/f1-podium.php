<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/TimeHelpers.php';

// Read-only reporting page — same tier as Football Matches / NBA Games.
cms_require_role(['superadmin', 'admin']);

$selfUrl = 'f1-podium.php';

$filterSeason = (int) ($_GET['season'] ?? 0);

$where = ['1 = 1'];
$params = [];

if ($filterSeason > 0) {
    $where[] = 'r.season = :season';
    $params['season'] = $filterSeason;
}

$whereSql = implode(' AND ', $where);

// One row per race — pivot the 3 podium rows (position 1/2/3) into columns
// via conditional aggregation, since f1_race_podium is (race_id, position,
// driver_name, ...) with up to 3 rows per race, not one row per race.
$podiums = [];
$seasons = [];
try {
    $stmt = $pdo->prepare(
        "SELECT r.id, r.competition_name, r.race_date, r.season,
                MAX(CASE WHEN p.position = 1 THEN p.driver_name END) AS p1_driver,
                MAX(CASE WHEN p.position = 2 THEN p.driver_name END) AS p2_driver,
                MAX(CASE WHEN p.position = 3 THEN p.driver_name END) AS p3_driver
         FROM f1_race_podium p
         JOIN f1_races r ON r.id = p.race_id
         WHERE {$whereSql}
         GROUP BY r.id, r.competition_name, r.race_date, r.season
         ORDER BY r.race_date DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $podiums = $stmt->fetchAll();

    $seasons = $pdo->query('SELECT DISTINCT season FROM f1_races ORDER BY season DESC')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // f1_race_podium/f1_races may not have any rows yet (cron never run) —
    // keep as empty arrays, the empty-state row below handles it.
}

$lastSyncedAt = null;
try {
    $lastSyncedAt = $pdo->query('SELECT MAX(updated_at) FROM f1_races WHERE id IN (SELECT DISTINCT race_id FROM f1_race_podium)')->fetchColumn() ?: null;
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

$pageTitle = 'F1 Podium';
$currentNav = 'f1-podium';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'F1 Podium', 'href' => ''],
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
            <h2 class="section-title">F1 Podium</h2>
            <p class="section-lead">Podium (P1/P2/P3) tiap race Formula 1 — data diisi otomatis oleh <code>cron/sync_f1_races.php</code>, read-only di sini.</p>
            <p style="margin:6px 0 0;font-size:12.5px;">
                <?php if ($lastSyncedAt !== null) : ?>
                    <span class="pill pill--ok">●</span> Data terakhir disinkronkan: <strong><?= cms_esc(date('d M Y, H:i', strtotime($lastSyncedAt))) ?></strong> <span style="opacity:.6;">(<?= cms_esc($lastSyncedRelative) ?>)</span>
                <?php else : ?>
                    <span class="pill pill--muted">●</span> <span style="opacity:.7;">Belum pernah disync — jalankan <code>cron/sync_f1_races.php</code> atau tombol Sync Sekarang di Livescore API Settings.</span>
                <?php endif; ?>
            </p>
        </div>
    </div>

    <div class="panel">
        <form class="filter-bar" method="get" action="<?= cms_esc($selfUrl) ?>">
            <select name="season" class="filter-select">
                <option value="">Semua Musim</option>
                <?php foreach ($seasons as $season) : ?>
                    <option value="<?= (int) $season ?>"<?= $filterSeason === (int) $season ? ' selected' : '' ?>><?= (int) $season ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
            <?php if ($filterSeason > 0) : ?>
                <a href="<?= cms_esc($selfUrl) ?>" class="admin-btn admin-btn--secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Daftar Podium</h3>
            <span class="panel__meta"><?= count($podiums) ?> race (maks. 200 terbaru)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Race</th>
                        <th>Tanggal</th>
                        <th>P1</th>
                        <th>P2</th>
                        <th>P3</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($podiums === []) : ?>
                        <tr><td colspan="5" class="muted">Belum ada podium untuk filter ini. Podium hanya terisi setelah race berstatus selesai.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($podiums as $row) : ?>
                        <tr>
                            <td><?= cms_esc((string) $row['competition_name']) ?></td>
                            <td><?= cms_esc(wpm_format_match_time((string) $row['race_date'], 'd M Y')) ?></td>
                            <td><?= $row['p1_driver'] !== null ? cms_esc((string) $row['p1_driver']) : '—' ?></td>
                            <td><?= $row['p2_driver'] !== null ? cms_esc((string) $row['p2_driver']) : '—' ?></td>
                            <td><?= $row['p3_driver'] !== null ? cms_esc((string) $row['p3_driver']) : '—' ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
