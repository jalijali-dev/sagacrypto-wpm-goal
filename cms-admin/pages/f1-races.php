<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__, 2) . '/includes/TimeHelpers.php';

// Read-only reporting page — same tier as Football Matches / NBA Games.
cms_require_role(['superadmin', 'admin']);

$selfUrl = 'f1-races.php';

$filterSeason = (int) ($_GET['season'] ?? 0);
$filterStatus = trim((string) ($_GET['status'] ?? ''));

// Status vocabulary matches f1.php's $statusLabels exactly (API-Formula-1
// free-text status column) — keep both in sync if the API ever adds one.
$STATUS_LABELS = [
    'Scheduled' => 'Akan Datang', 'Live' => 'Live', 'Completed' => 'Selesai',
    'Postponed' => 'Ditunda', 'Cancelled' => 'Dibatalkan',
];

$where = ['1 = 1'];
$params = [];

if ($filterSeason > 0) {
    $where[] = 'season = :season';
    $params['season'] = $filterSeason;
}
if ($filterStatus !== '') {
    $where[] = 'status = :status';
    $params['status'] = $filterStatus;
}

$whereSql = implode(' AND ', $where);

$races = [];
$seasons = [];
try {
    $stmt = $pdo->prepare("SELECT * FROM f1_races WHERE {$whereSql} ORDER BY race_date DESC LIMIT 200");
    $stmt->execute($params);
    $races = $stmt->fetchAll();

    $seasons = $pdo->query('SELECT DISTINCT season FROM f1_races ORDER BY season DESC')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    // f1_races may not have any rows yet (cron never run) — keep as empty
    // arrays, the empty-state row below handles it.
}

$lastSyncedAt = null;
try {
    $lastSyncedAt = $pdo->query('SELECT MAX(updated_at) FROM f1_races')->fetchColumn() ?: null;
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

$pageTitle = 'F1 Races';
$currentNav = 'f1-races';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'F1 Races', 'href' => ''],
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
            <h2 class="section-title">F1 Races</h2>
            <p class="section-lead">Kalender balapan Formula 1 — data diisi otomatis oleh <code>cron/sync_f1_races.php</code>, read-only di sini.</p>
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
            <select name="status" class="filter-select">
                <option value="">Semua Status</option>
                <?php foreach ($STATUS_LABELS as $code => $label) : ?>
                    <option value="<?= cms_esc($code) ?>"<?= $filterStatus === $code ? ' selected' : '' ?>><?= cms_esc($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
            <?php if ($filterSeason > 0 || $filterStatus !== '') : ?>
                <a href="<?= cms_esc($selfUrl) ?>" class="admin-btn admin-btn--secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Daftar Race</h3>
            <span class="panel__meta"><?= count($races) ?> race (maks. 200 terbaru)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Nama Race</th>
                        <th>Sirkuit</th>
                        <th>Tanggal</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($races === []) : ?>
                        <tr><td colspan="4" class="muted">Belum ada race untuk filter ini. Pastikan <code>cron/sync_f1_races.php</code> sudah pernah jalan.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($races as $race) : ?>
                        <tr>
                            <td><?= cms_esc((string) $race['competition_name']) ?></td>
                            <td><?= cms_esc((string) ($race['circuit_name'] ?? '—')) ?><?php if (!empty($race['competition_location'])) : ?> — <?= cms_esc((string) $race['competition_location']) ?><?php endif; ?></td>
                            <td><?= cms_esc(wpm_format_match_time((string) $race['race_date'], 'd M Y, H:i')) ?> WIB</td>
                            <td>
                                <span class="pill pill--<?= $race['status'] === 'Completed' ? 'ok' : ((string) $race['status'] === 'Cancelled' ? 'muted' : 'accent') ?>">
                                    <?= cms_esc($STATUS_LABELS[$race['status']] ?? (string) $race['status']) ?>
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
