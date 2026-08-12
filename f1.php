<?php
declare(strict_types=1);

/**
 * Sagagoal — Formula 1 (/f1). Footer-only placement (not in the main
 * nav) — see wpm_nav_menu()/wpm_url_football()/wpm_url_basket() in
 * site-bootstrap.php; the /olahraga hub this used to be reached through
 * was removed 24 Jul 2026. Deliberately NOT built like football.php/
 * basket.php's search+live-toggle+date-strip pattern — F1 doesn't fit
 * that shape (races are sparse, ~24/year, no two-team score). Instead:
 * a season calendar (Grand Prix cards, podium once completed) +
 * driver/constructor standings, switched via a simple tab param.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';
require_once __DIR__ . '/includes/FormulaOneSettings.php';

$tab = (string) ($_GET['tab'] ?? 'kalender');
if (!in_array($tab, ['kalender', 'klasemen'], true)) {
    $tab = 'kalender';
}

$season = (int) wpm_now_wib()->format('Y');

$statusLabels = [
    'Scheduled' => 'Akan Datang',
    'Live' => 'LIVE',
    'Completed' => 'Selesai',
    'Postponed' => 'Ditunda',
    'Cancelled' => 'Dibatalkan',
];

$grandPrixList = [];
$driverStandings = [];
$constructorStandings = [];

try {
    if ($tab === 'kalender') {
        $stmt = $pdo->prepare('SELECT * FROM f1_races WHERE season = :season ORDER BY race_date ASC');
        $stmt->execute(['season' => $season]);
        $races = $stmt->fetchAll();

        // A race weekend is several rows (practice/qualifying/race) sharing
        // one competition_name — group them and use the "Race" type row as
        // the primary date/status anchor (fallback: earliest session).
        $groups = [];
        foreach ($races as $race) {
            $key = (string) $race['competition_name'];
            if (!isset($groups[$key])) {
                $groups[$key] = ['sessions' => [], 'race' => null];
            }
            $groups[$key]['sessions'][] = $race;
            if ($race['type'] === 'Race') {
                $groups[$key]['race'] = $race;
            }
        }
        foreach ($groups as $key => $group) {
            if ($group['race'] === null) {
                $group['race'] = $group['sessions'][0];
            }
            $raceId = (int) $group['race']['id'];
            $podium = [];
            if ($group['race']['status'] === 'Completed') {
                $podiumStmt = $pdo->prepare('SELECT * FROM f1_race_podium WHERE race_id = :race_id ORDER BY position ASC');
                $podiumStmt->execute(['race_id' => $raceId]);
                $podium = $podiumStmt->fetchAll();
            }
            $grandPrixList[] = ['race' => $group['race'], 'podium' => $podium];
        }
    } else {
        $driverStmt = $pdo->prepare('SELECT * FROM f1_driver_standings WHERE season = :season ORDER BY position ASC');
        $driverStmt->execute(['season' => $season]);
        $driverStandings = $driverStmt->fetchAll();

        $constructorStmt = $pdo->prepare('SELECT * FROM f1_constructor_standings WHERE season = :season ORDER BY position ASC');
        $constructorStmt->execute(['season' => $season]);
        $constructorStandings = $constructorStmt->fetchAll();
    }
} catch (Throwable $e) {
    $grandPrixList = [];
    $driverStandings = [];
    $constructorStandings = [];
}

$tabUrl = static function (string $t): string {
    return 'f1.php?tab=' . $t;
};

$pageTitle = 'Formula 1 — Sagagoal';
$pageDescription = 'Kalender race, hasil, dan klasemen pembalap & konstruktor Formula 1 musim ' . $season . '.';
$activeNav = ''; // Not in the main nav — footer-only (see wpm_nav_menu()).
$canonicalUrl = wpm_site_url(wpm_url_f1());

// Page title/subtitle editable from Livescore API Settings (24 Jul 2026) —
// sports_api_settings.page_title/page_subtitle, fall back to the original
// hardcoded text if the admin hasn't set them (fresh row, empty field).
// The season number is always appended live (not admin-editable) since
// it's inherently dynamic, not static page copy.
$f1Settings = FormulaOneSettings::load($pdo);
$f1PageTitle = trim((string) ($f1Settings['page_title'] ?? '')) !== '' ? $f1Settings['page_title'] : 'Kalender & Klasemen F1';
$f1PageSubtitle = trim((string) ($f1Settings['page_subtitle'] ?? '')) !== '' ? $f1Settings['page_subtitle'] : 'Jadwal race musim ini, hasil podium, dan klasemen pembalap & konstruktor.';

require __DIR__ . '/includes/site-header.php';
?>

    <section class="page-hero">
        <div class="crypto-container">
            <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= wpm_esc(wpm_site_url('')) ?>">Beranda</a> <span>/</span> Formula 1</nav>
            <span class="section-kicker"><?= wpm_icon('motorsport') ?> Formula 1</span>
            <h1><?= htmlspecialchars($f1PageTitle) ?> <?= (int) $season ?></h1>
            <p><?= htmlspecialchars($f1PageSubtitle) ?></p>
        </div>
    </section>

    <div class="crypto-container">
        <div class="news-tabs">
            <a class="news-tabs__item<?= $tab === 'kalender' ? ' is-active' : '' ?>" href="<?= wpm_esc($tabUrl('kalender')) ?>">Kalender &amp; Hasil</a>
            <a class="news-tabs__item<?= $tab === 'klasemen' ? ' is-active' : '' ?>" href="<?= wpm_esc($tabUrl('klasemen')) ?>">Klasemen</a>
        </div>

        <?php if ($tab === 'kalender') : ?>
            <p class="livescore-tz-note">Semua jam ditampilkan dalam WIB (UTC+7).</p>
            <?php if ($grandPrixList === []) : ?>
                <div class="empty-state">
                    <?= wpm_icon('motorsport') ?>
                    <p>Belum ada kalender race untuk musim <?= (int) $season ?>.</p>
                    <p class="empty-state__hint">Kalender disync dari provider — cek lagi setelah admin mengaktifkan &amp; menjalankan sync.</p>
                </div>
            <?php else : ?>
                <div class="f1-race-list">
                    <?php foreach ($grandPrixList as $entry) : ?>
                        <?php $race = $entry['race']; $podium = $entry['podium']; ?>
                        <div class="f1-race-card">
                            <div class="f1-race-card__info">
                                <span class="f1-race-card__gp"><?= wpm_esc((string) $race['competition_name']) ?></span>
                                <span class="f1-race-card__circuit"><?= wpm_esc((string) ($race['circuit_name'] ?? '')) ?><?php if (!empty($race['competition_location'])) : ?> — <?= wpm_esc((string) $race['competition_location']) ?><?php endif; ?></span>
                                <span class="f1-race-card__date"><?= wpm_esc(wpm_format_match_time((string) $race['race_date'], 'd M Y, H:i')) ?></span>
                            </div>
                            <div class="f1-race-card__status">
                                <?php $status = (string) $race['status']; ?>
                                <span class="f1-status-badge f1-status-badge--<?= wpm_esc(strtolower($status)) ?>"><?= wpm_esc($statusLabels[$status] ?? $status) ?></span>
                                <?php if ($podium !== []) : ?>
                                    <div class="f1-podium">
                                        <?php foreach ($podium as $row) : ?>
                                            <div class="f1-podium__row">
                                                <span class="f1-podium__pos">P<?= (int) $row['position'] ?></span>
                                                <span class="f1-podium__driver"><?= wpm_esc((string) $row['driver_name']) ?></span>
                                                <span class="f1-podium__team"><?= wpm_esc((string) ($row['team_name'] ?? '')) ?></span>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php else : ?>
            <div class="f1-standings-grid">
                <div>
                    <h3 class="f1-standings-title">Klasemen Pembalap</h3>
                    <?php if ($driverStandings === []) : ?>
                        <div class="empty-state"><?= wpm_icon('motorsport') ?><p>Klasemen pembalap belum tersedia.</p></div>
                    <?php else : ?>
                        <div class="crypto-table-wrap">
                            <table class="crypto-table">
                                <thead><tr><th>#</th><th>Pembalap</th><th>Tim</th><th>Menang</th><th>Poin</th></tr></thead>
                                <tbody>
                                    <?php foreach ($driverStandings as $row) : ?>
                                        <tr>
                                            <td><?= (int) $row['position'] ?></td>
                                            <td><?= wpm_esc((string) $row['driver_name']) ?></td>
                                            <td><?= wpm_esc((string) ($row['team_name'] ?? '—')) ?></td>
                                            <td><?= (int) $row['wins'] ?></td>
                                            <td><strong><?= wpm_esc((string) $row['points']) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
                <div>
                    <h3 class="f1-standings-title">Klasemen Konstruktor</h3>
                    <?php if ($constructorStandings === []) : ?>
                        <div class="empty-state"><?= wpm_icon('motorsport') ?><p>Klasemen konstruktor belum tersedia.</p></div>
                    <?php else : ?>
                        <div class="crypto-table-wrap">
                            <table class="crypto-table">
                                <thead><tr><th>#</th><th>Tim</th><th>Menang</th><th>Poin</th></tr></thead>
                                <tbody>
                                    <?php foreach ($constructorStandings as $row) : ?>
                                        <tr>
                                            <td><?= (int) $row['position'] ?></td>
                                            <td><?= wpm_esc((string) $row['team_name']) ?></td>
                                            <td><?= (int) $row['wins'] ?></td>
                                            <td><strong><?= wpm_esc((string) $row['points']) ?></strong></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
