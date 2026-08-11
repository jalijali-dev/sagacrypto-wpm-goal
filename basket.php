<?php
declare(strict_types=1);

/**
 * Sagagoal — Basketball (NBA) livescore & jadwal (/basket, renamed from
 * /nba 24 Jul 2026, direct top-level page — no more /olahraga hub).
 * Mirrors football.php's controls (search, "Live (N)" toggle, 7-day
 * date strip, calendar popup) by deliberately reusing the same element
 * IDs/CSS classes and assets/js/livescore.js unchanged — see
 * wpm_nba_game_card() in site-bootstrap.php for how the card markup
 * stays compatible with that shared JS.
 *
 * No league/round grouping here (unlike football) — NBA is a single
 * competition, so games for a date render as one flat list.
 *
 * Auto-refresh (data-poll) is intentionally NOT wired up yet — that
 * needs an nba-poll.php endpoint mirroring livescore-poll.php, deferred
 * to a later pass. Until then, live scores update on manual reload.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';
require_once __DIR__ . '/includes/BasketballSettings.php';

$dateParam = trim((string) ($_GET['date'] ?? ''));
$today = wpm_today_wib();
$targetDate = ($dateParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam) === 1) ? $dateParam : $today;

$games = [];
try {
    $stmt = $pdo->prepare(
        "SELECT g.*, ht.name AS home_name, ht.logo AS home_logo,
                at.name AS away_name, at.logo AS away_logo
         FROM nba_games g
         JOIN nba_teams ht ON ht.id = g.home_team_id
         JOIN nba_teams at ON at.id = g.away_team_id
         WHERE DATE(CONVERT_TZ(g.game_date, '+00:00', '+07:00')) = :targetDate
         ORDER BY g.game_date ASC"
    );
    $stmt->execute(['targetDate' => $targetDate]);
    $games = $stmt->fetchAll();
} catch (Throwable $e) {
    $games = [];
}

$liveCount = wpm_count_live_nba_games($pdo);

// Same "genuinely empty" vs "provider can't fetch this date yet"
// classification as football.php — see BasketballSettings::getGameDateWindow().
$apiWindow = BasketballSettings::getGameDateWindow($pdo);
if ($apiWindow['checked_at'] === null) {
    $apiWindowStart = date('Y-m-d', strtotime($today . ' -1 day'));
    $apiWindowEnd = date('Y-m-d', strtotime($today . ' +1 day'));
} else {
    $apiWindowStart = $apiWindow['start'];
    $apiWindowEnd = $apiWindow['end'];
}
$isOutsideApiWindow = $games === []
    && (($apiWindowStart !== null && $targetDate < $apiWindowStart)
        || ($apiWindowEnd !== null && $targetDate > $apiWindowEnd));

// NBA regular season runs Oct-Apr, playoffs through June — Jul/Aug/Sep is
// a genuine dead period (no standard-season, playoff, or Summer League
// games), unlike a random in-season date that's just game-free. Worth
// saying outright instead of just hinting "maybe out of season".
$targetMonth = (int) date('n', strtotime($targetDate));
$isNbaOffSeason = in_array($targetMonth, [7, 8, 9], true);

/** @return list<array{date:string, day_num:string, day_label:string, is_today:bool, is_selected:bool}> */
$buildDateStrip = static function (string $today, string $selected): array {
    $dayLabels = ['Min', 'Sen', 'Sel', 'Rab', 'Kam', 'Jum', 'Sab'];
    $strip = [];
    for ($i = -3; $i <= 3; $i++) {
        $ts = strtotime($today . ($i >= 0 ? " +{$i} days" : " {$i} days"));
        $d = date('Y-m-d', $ts);
        $strip[] = [
            'date' => $d,
            'day_num' => date('j', $ts),
            'day_label' => $dayLabels[(int) date('w', $ts)],
            'is_today' => $d === $today,
            'is_selected' => $d === $selected,
        ];
    }
    return $strip;
};
$dateStrip = $buildDateStrip($today, $targetDate);

$dateUrl = static function (string $date): string {
    return 'basket/' . $date;
};

$pageTitle = 'NBA — Sagagoal';
$pageDescription = 'Jadwal dan skor live pertandingan NBA hari ini, besok, dan yang sedang berlangsung.';
$activeNav = 'basketball';
$canonicalUrl = wpm_site_url(wpm_url_basket());
$livescoreJsVer = @filemtime(__DIR__ . '/assets/js/livescore.js') ?: 1;
$extraHead = '<script src="assets/js/livescore.js?v=' . $livescoreJsVer . '" defer></script>';

/* ── Promo banners (cms-admin/pages/banners.php), placement="basket" ── */
$basketBanners = wpm_banners_active($pdo, 'basket');

// Page title/subtitle editable from Livescore API Settings (24 Jul 2026) —
// sports_api_settings.page_title/page_subtitle, fall back to the original
// hardcoded text if the admin hasn't set them (fresh row, empty field).
$basketSettings = BasketballSettings::load($pdo);
$basketPageTitle = trim((string) ($basketSettings['page_title'] ?? '')) !== '' ? $basketSettings['page_title'] : 'Jadwal & Skor NBA';
$basketPageSubtitle = trim((string) ($basketSettings['page_subtitle'] ?? '')) !== '' ? $basketSettings['page_subtitle'] : 'Live score dan jadwal pertandingan NBA, hari ini dan besok.';

require __DIR__ . '/includes/site-header.php';
?>

    <section class="page-hero">
        <div class="crypto-container">
            <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Basket</nav>
            <span class="section-kicker"><?= wpm_icon('basketball') ?> NBA</span>
            <h1><?= htmlspecialchars($basketPageTitle) ?></h1>
            <p><?= htmlspecialchars($basketPageSubtitle) ?></p>
        </div>
    </section>

    <div class="crypto-container">
        <div class="news-layout news-layout--ad-only">
        <div class="news-layout__main">
        <div class="livescore-controls">
            <div class="livescore-search">
                <?= wpm_icon('search') ?>
                <input type="text" id="livescore-search-input" placeholder="Cari pertandingan atau tim..." autocomplete="off">
            </div>
            <button type="button" class="livescore-live-toggle" id="livescore-live-toggle" data-live-count="<?= $liveCount ?>" aria-pressed="false">
                <span class="livescore-live-toggle__dot" aria-hidden="true"></span>
                Live<?php if ($liveCount > 0) : ?> <span class="livescore-live-toggle__count"><?= $liveCount ?></span><?php endif; ?>
            </button>
        </div>

        <div class="livescore-datebar">
            <div class="livescore-datestrip">
                <?php foreach ($dateStrip as $day) : ?>
                    <a class="livescore-datestrip__day<?= $day['is_today'] ? ' is-today' : '' ?><?= $day['is_selected'] ? ' is-selected' : '' ?>" href="<?= wpm_esc($dateUrl($day['date'])) ?>">
                        <span class="livescore-datestrip__label"><?= $day['is_today'] ? 'Hari ini' : wpm_esc($day['day_label']) ?></span>
                        <span class="livescore-datestrip__num"><?= wpm_esc($day['day_num']) ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            <div class="livescore-calendar-wrap">
                <button type="button" class="livescore-calendar-btn" id="livescore-calendar-btn" aria-label="Pilih tanggal" data-selected="<?= wpm_esc($targetDate) ?>" data-today="<?= wpm_esc($today) ?>" data-url-template="<?= wpm_esc($dateUrl('__DATE__')) ?>">
                    <?= wpm_icon('calendar') ?>
                </button>
                <div class="livescore-calendar-popup" id="livescore-calendar-popup" hidden></div>
            </div>
            <span class="livescore-api-hint" tabindex="0" title="Jadwal untuk tanggal yang jauh dari hari ini mungkin belum tersedia dari provider data kami — tergantung paket API yang aktif, biasanya baru bisa diambil beberapa hari sebelumnya.">
                <?= wpm_icon('info') ?>
            </span>
        </div>
        <p class="livescore-tz-note">Semua jam ditampilkan dalam WIB (UTC+7).</p>

        <?php if ($basketBanners !== []) : ?>
        <div class="banner-strip" style="margin-bottom:24px;">
            <div class="banner-strip__grid">
                <?php foreach ($basketBanners as $banner) : ?>
                    <?= wpm_banner_markup($banner) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="livescore-list" data-live-statuses="2">
            <?php if ($games === []) : ?>
                <?php if ($isOutsideApiWindow) : ?>
                    <div class="empty-state" id="livescore-empty-state">
                        <?= wpm_icon('clock') ?>
                        <p>Jadwal untuk tanggal ini belum tersedia dari provider data kami.</p>
                        <p class="empty-state__hint">Coba lagi mendekati tanggal tersebut — data pertandingan biasanya baru bisa diambil beberapa hari sebelumnya.</p>
                    </div>
                <?php elseif ($isNbaOffSeason) : ?>
                    <div class="empty-state" id="livescore-empty-state">
                        <?= wpm_icon('basketball') ?>
                        <p>NBA sedang off-season.</p>
                        <p class="empty-state__hint">Musim reguler dimulai kembali Oktober. Bukan masalah sistem — memang tidak ada pertandingan resmi di periode ini.</p>
                    </div>
                <?php else : ?>
                    <div class="empty-state" id="livescore-empty-state">
                        <?= wpm_icon('basketball') ?>
                        <p>Belum ada jadwal NBA untuk tanggal ini.</p>
                        <p class="empty-state__hint">Coba tanggal lain — kadang memang tidak ada pertandingan terjadwal hari itu.</p>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <div class="fixture-league-group">
                    <div class="fixture-league-group__header">
                        <?= wpm_icon('basketball') ?>
                        <div class="fixture-league-group__title">
                            <span>NBA</span>
                            <small>USA</small>
                        </div>
                    </div>
                    <div class="fixture-league-group__list">
                        <?php foreach ($games as $game) : ?>
                            <?= wpm_nba_game_card($game) ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="empty-state" id="livescore-no-match-state" hidden><?= wpm_icon('search') ?><p>Tidak ada pertandingan yang cocok dengan pencarian/filter.</p></div>
            <?php endif; ?>
        </div>
        </div>

        <!-- ══════════ SIDEBAR: IKLAN (11 Agu 2026, permintaan operator) ══════════ -->
        <aside class="news-layout__sidebar">
            <?= wpm_render_ad_slot($pdo, 'sidebar-right', 'basket') ?>
        </aside>
        </div>
    </div>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
