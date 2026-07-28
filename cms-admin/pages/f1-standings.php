<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Read-only reporting page — same tier as Football Matches / NBA Games.
cms_require_role(['superadmin', 'admin']);

$selfUrl = 'f1-standings.php';

$view = ($_GET['view'] ?? 'driver') === 'constructor' ? 'constructor' : 'driver';
$filterSeason = (int) ($_GET['season'] ?? 0);

$table = $view === 'driver' ? 'f1_driver_standings' : 'f1_constructor_standings';

$where = ['1 = 1'];
$params = [];
if ($filterSeason > 0) {
    $where[] = 'season = :season';
    $params['season'] = $filterSeason;
}
$whereSql = implode(' AND ', $where);

$standings = [];
$seasons = [];
$lastSyncedAt = null;
try {
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE {$whereSql} ORDER BY season DESC, position ASC LIMIT 200");
    $stmt->execute($params);
    $standings = $stmt->fetchAll();

    $seasons = $pdo->query("SELECT DISTINCT season FROM {$table} ORDER BY season DESC")->fetchAll(PDO::FETCH_COLUMN);
    $lastSyncedAt = $pdo->query("SELECT MAX(updated_at) FROM {$table}")->fetchColumn() ?: null;
} catch (Throwable $e) {
    // f1_driver_standings/f1_constructor_standings may not have rows yet
    // (cron never run) — keep as empty arrays, empty-state row handles it.
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

$tabUrl = static function (string $tabView) use ($selfUrl, $filterSeason): string {
    $url = $selfUrl . '?view=' . $tabView;
    return $filterSeason > 0 ? $url . '&season=' . $filterSeason : $url;
};

$pageTitle = 'F1 Standings';
$currentNav = 'f1-standings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'F1 Standings', 'href' => ''],
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
            <h2 class="section-title">F1 Standings</h2>
            <p class="section-lead">Klasemen pembalap &amp; konstruktor Formula 1 — data diisi otomatis oleh <code>cron/sync_f1_standings.php</code>, read-only di sini.</p>
            <p style="margin:6px 0 0;font-size:12.5px;">
                <?php if ($lastSyncedAt !== null) : ?>
                    <span class="pill pill--ok">●</span> Data terakhir disinkronkan: <strong><?= cms_esc(date('d M Y, H:i', strtotime($lastSyncedAt))) ?></strong> <span style="opacity:.6;">(<?= cms_esc($lastSyncedRelative) ?>)</span>
                <?php else : ?>
                    <span class="pill pill--muted">●</span> <span style="opacity:.7;">Belum pernah disync — jalankan <code>cron/sync_f1_standings.php</code> atau tombol Sync Sekarang di Livescore API Settings.</span>
                <?php endif; ?>
            </p>
        </div>
        <div class="toolbar__right" style="display:flex;gap:8px;">
            <a class="admin-btn admin-btn--<?= $view === 'driver' ? 'primary' : 'secondary' ?>" href="<?= cms_esc($tabUrl('driver')) ?>">Klasemen Pembalap</a>
            <a class="admin-btn admin-btn--<?= $view === 'constructor' ? 'primary' : 'secondary' ?>" href="<?= cms_esc($tabUrl('constructor')) ?>">Klasemen Konstruktor</a>
        </div>
    </div>

    <div class="panel">
        <form class="filter-bar" method="get" action="<?= cms_esc($selfUrl) ?>">
            <input type="hidden" name="view" value="<?= cms_esc($view) ?>">
            <select name="season" class="filter-select">
                <option value="">Semua Musim</option>
                <?php foreach ($seasons as $season) : ?>
                    <option value="<?= (int) $season ?>"<?= $filterSeason === (int) $season ? ' selected' : '' ?>><?= (int) $season ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
            <?php if ($filterSeason > 0) : ?>
                <a href="<?= cms_esc($tabUrl($view)) ?>" class="admin-btn admin-btn--secondary">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title"><?= $view === 'driver' ? 'Klasemen Pembalap' : 'Klasemen Konstruktor' ?></h3>
            <span class="panel__meta"><?= count($standings) ?> baris</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <?php if ($view === 'driver') : ?>
                        <tr><th>Posisi</th><th>Nama Pembalap</th><th>Tim</th><th>Poin</th></tr>
                    <?php else : ?>
                        <tr><th>Posisi</th><th>Nama Tim</th><th>Poin</th></tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php if ($standings === []) : ?>
                        <tr><td colspan="<?= $view === 'driver' ? 4 : 3 ?>" class="muted">Belum ada klasemen untuk filter ini. Pastikan <code>cron/sync_f1_standings.php</code> sudah pernah jalan.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($standings as $row) : ?>
                        <?php if ($view === 'driver') : ?>
                            <tr>
                                <td><?= (int) $row['position'] ?></td>
                                <td><?= cms_esc((string) $row['driver_name']) ?></td>
                                <td><?= cms_esc((string) ($row['team_name'] ?? '—')) ?></td>
                                <td><?= cms_esc((string) $row['points']) ?></td>
                            </tr>
                        <?php else : ?>
                            <tr>
                                <td><?= (int) $row['position'] ?></td>
                                <td><?= cms_esc((string) $row['team_name']) ?></td>
                                <td><?= cms_esc((string) $row['points']) ?></td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
