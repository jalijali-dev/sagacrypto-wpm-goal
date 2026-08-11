<?php
declare(strict_types=1);

/**
 * Sagagoal — Football livescore page (/football, renamed from /livescore
 * 24 Jul 2026 — "livescore" is a feature every sport has, not a page
 * name). Jadwal & skor pertandingan, dikelompokkan per liga. Sumber
 * data: tabel fixtures/teams/leagues (diisi oleh cron sync API-Football).
 * Controls: search (client-side), toggle "Live (N)" (client-side,
 * filters whatever date is currently loaded), 7-day date strip + full
 * calendar popup (both navigate via ?date=). Auto-refresh via
 * livescore-poll.php only runs when viewing today (the only day live
 * matches can exist on) — query lokal ke tabel fixtures, TIDAK pernah hit
 * API-Football langsung dari browser.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';
require_once __DIR__ . '/includes/LivescoreSettings.php';

$dateParam = trim((string) ($_GET['date'] ?? ''));
$today = wpm_today_wib();
$targetDate = ($dateParam !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateParam) === 1) ? $dateParam : $today;
$isToday = $targetDate === $today;

$fixtures = [];
try {
    $stmt = $pdo->prepare(
        "SELECT f.*, ht.name AS home_name, ht.logo AS home_logo,
                at.name AS away_name, at.logo AS away_logo,
                l.name AS league_name, l.logo AS league_logo, l.country AS league_country
         FROM fixtures f
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         JOIN leagues l ON l.id = f.league_id
         WHERE DATE(CONVERT_TZ(f.kickoff_at, '+00:00', '+07:00')) = :targetDate
         ORDER BY l.sort_order ASC, f.kickoff_at ASC"
    );
    $stmt->execute(['targetDate' => $targetDate]);
    $fixtures = $stmt->fetchAll();
} catch (Throwable $e) {
    $fixtures = [];
}

$leagueGroups = wpm_group_fixtures_by_league($fixtures);
$liveCount = wpm_count_live_fixtures($pdo);

// Distinguishes "genuinely no matches for our tracked leagues" from "our
// API plan can't even fetch this date yet" (free-plan /fixtures?date=
// only allows a rolling few-day window). Purely a cached-value read —
// football.php never calls API-Football live itself; the window is
// learned by cron/sync_fixtures.php's throttled probe.
$apiWindow = LivescoreSettings::getApiDateWindow($pdo);
if ($apiWindow['checked_at'] === null) {
    // Never learned yet — fall back to the empirically-observed default
    // (today ±1 day) so the message is still sensible before cron's
    // first probe has run.
    $apiWindowStart = date('Y-m-d', strtotime($today . ' -1 day'));
    $apiWindowEnd = date('Y-m-d', strtotime($today . ' +1 day'));
} else {
    // null here (post-check) means "checked, and no restriction found".
    $apiWindowStart = $apiWindow['start'];
    $apiWindowEnd = $apiWindow['end'];
}
$isOutsideApiWindow = $leagueGroups === []
    && (($apiWindowStart !== null && $targetDate < $apiWindowStart)
        || ($apiWindowEnd !== null && $targetDate > $apiWindowEnd));

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
    return 'football/' . $date;
};

$pageTitle = 'Livescore Sepak Bola — Sagagoal';
$pageDescription = 'Jadwal dan skor live pertandingan sepak bola hari ini, besok, dan yang sedang berlangsung.';
$activeNav = 'football';
$canonicalUrl = wpm_site_url(wpm_url_football());
$livescoreJsVer = @filemtime(__DIR__ . '/assets/js/livescore.js') ?: 1;
$extraHead = '<script src="assets/js/livescore.js?v=' . $livescoreJsVer . '" defer></script>';

/* ── Promo banners (cms-admin/pages/banners.php), placement="football" ── */
$footballBanners = wpm_banners_active($pdo, 'football');

// Page title/subtitle editable from Livescore API Settings (24 Jul 2026) —
// sports_api_settings.page_title/page_subtitle, fall back to the original
// hardcoded text if the admin hasn't set them (fresh row, empty field).
$footballSettings = LivescoreSettings::load($pdo);
$footballPageTitle = trim((string) ($footballSettings['page_title'] ?? '')) !== '' ? $footballSettings['page_title'] : 'Jadwal & Skor Pertandingan';
$footballPageSubtitle = trim((string) ($footballSettings['page_subtitle'] ?? '')) !== '' ? $footballSettings['page_subtitle'] : 'Live score, jadwal hari ini dan besok, dikelompokkan per liga.';

require __DIR__ . '/includes/site-header.php';
?>

    <section class="page-hero">
        <div class="crypto-container">
            <nav class="breadcrumb" aria-label="Breadcrumb"><a href="index.php">Beranda</a> <span>/</span> Sepak Bola</nav>
            <span class="section-kicker"><?= wpm_icon('football') ?> Sepak Bola</span>
            <h1><?= htmlspecialchars($footballPageTitle) ?></h1>
            <p><?= htmlspecialchars($footballPageSubtitle) ?></p>
        </div>
    </section>

    <div class="crypto-container">
        <div class="news-layout news-layout--ad-only">
        <div class="news-layout__main">
        <!-- ══════════ CONTROLS: search + live toggle ══════════ -->
        <div class="livescore-controls">
            <div class="livescore-search">
                <?= wpm_icon('search') ?>
                <input type="text" id="livescore-search-input" placeholder="Cari pertandingan, tim, atau liga..." autocomplete="off">
            </div>
            <button type="button" class="livescore-live-toggle" id="livescore-live-toggle" data-live-count="<?= $liveCount ?>" aria-pressed="false">
                <span class="livescore-live-toggle__dot" aria-hidden="true"></span>
                Live<?php if ($liveCount > 0) : ?> <span class="livescore-live-toggle__count"><?= $liveCount ?></span><?php endif; ?>
            </button>
        </div>

        <!-- ══════════ DATE STRIP + CALENDAR ══════════ -->
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

        <?php if ($footballBanners !== []) : ?>
        <div class="banner-strip" style="margin-bottom:24px;">
            <div class="banner-strip__grid">
                <?php foreach ($footballBanners as $banner) : ?>
                    <?= wpm_banner_markup($banner) ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div id="livescore-list"<?= $isToday ? ' data-poll="1"' : '' ?>>
            <?php if ($leagueGroups === []) : ?>
                <?php if ($isOutsideApiWindow) : ?>
                    <div class="empty-state" id="livescore-empty-state">
                        <?= wpm_icon('clock') ?>
                        <p>Jadwal untuk tanggal ini belum tersedia dari provider data kami.</p>
                        <p class="empty-state__hint">Coba lagi mendekati tanggal tersebut — data pertandingan biasanya baru bisa diambil beberapa hari sebelumnya.</p>
                    </div>
                <?php else : ?>
                    <div class="empty-state" id="livescore-empty-state">
                        <?= wpm_icon('trophy') ?>
                        <p>Belum ada jadwal dalam rentang tanggal ini.</p>
                        <p class="empty-state__hint">Coba pilih liga/kompetisi atau tanggal lain, atau cek lagi nanti — ini bukan error, kemungkinan besar musim liga belum berjalan di tanggal ini.</p>
                    </div>
                <?php endif; ?>
            <?php else : ?>
                <?php foreach ($leagueGroups as $group) : ?>
                    <div class="fixture-league-group">
                        <div class="fixture-league-group__header">
                            <?php $groupLogo = wpm_image($group['league']['logo'] ?? null); ?>
                            <?= $groupLogo !== null ? '<img src="' . wpm_esc($groupLogo) . '" alt="">' : wpm_icon('trophy') ?>
                            <div class="fixture-league-group__title">
                                <span><?= wpm_esc((string) $group['league']['name']) ?></span>
                                <small><?= wpm_esc(wpm_league_subtitle($group['league']['country'] ?? null)) ?></small>
                            </div>
                        </div>
                        <?php foreach ($group['rounds'] as $roundGroup) : ?>
                            <div class="fixture-round-group">
                                <?php if ($roundGroup['round_label'] !== '') : ?>
                                    <div class="fixture-round-group__label"><?= wpm_esc($roundGroup['round_label']) ?></div>
                                <?php endif; ?>
                                <div class="fixture-league-group__list">
                                    <?php foreach ($roundGroup['fixtures'] as $fixture) : ?>
                                        <?= wpm_fixture_card($fixture) ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <div class="empty-state" id="livescore-no-match-state" hidden><?= wpm_icon('search') ?><p>Tidak ada pertandingan yang cocok dengan pencarian/filter.</p></div>
            <?php endif; ?>
        </div>
        </div>

        <!-- ══════════ SIDEBAR: IKLAN (11 Agu 2026, permintaan operator —
             samain gaya sama homepage/berita: kolom kanan reserved buat
             iklan, bukan cuma inline di antara konten) ══════════ -->
        <aside class="news-layout__sidebar">
            <?= wpm_render_ad_slot($pdo, 'sidebar-right', 'football') ?>
        </aside>
        </div>
    </div>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
