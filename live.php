<?php
declare(strict_types=1);

/**
 * Sagagoal — Live Streaming (7 Sep 2026, "next level, per pertandingan").
 * REPLACES the earlier single-global-stream version of this file (3-6
 * Sep 2026) with a per-match model — this page now has two modes:
 *
 *   /live                          -> listing of every currently-live match
 *   /live/<fixture_id>/<slug>      -> one specific match's player
 *
 * (.htaccess routes the second form here with ?id=&slug=; the plain
 * /live form falls through to the generic single-segment alias rule and
 * arrives with no query string at all.)
 *
 * Data comes from the new `fixture_streams` table (fixture_id => is_live
 * + embed_code + optional title/description), joined to the existing
 * API-Football-synced `fixtures`/`teams`/`leagues` tables for match
 * context (team names/logos, kickoff, live match-status). Managed from
 * cms-admin/pages/live-streaming.php (now a searchable fixture picker +
 * CRUD list, not a singleton settings form). See
 * includes/site-bootstrap.php's wpm_live_stream_fixture_ids() /
 * wpm_url_live_match() / wpm_fixture_match_slug() for the shared helpers.
 *
 * `slug` in the URL is COSMETIC ONLY (like artikel.php's category slug
 * isn't validated against the article) — the numeric fixture id is what
 * actually resolves the page; a stale/wrong slug still loads correctly,
 * it's just not what wpm_url_live_match() would currently generate.
 */

require_once __DIR__ . '/includes/site-bootstrap.php';

$fixtureId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$customId = isset($_GET['custom_id']) ? (int) $_GET['custom_id'] : 0;

if ($customId > 0) {
    // ── Single-match mode, MANUAL/CUSTOM entry (8 Sep 2026) — no
    // fixtures/teams/leagues join at all, everything comes straight off
    // fixture_streams' own custom_* columns. Distinct from the real-
    // fixture branch below only in where the match info comes from; the
    // rest of the page (player, empty state, meta tags) is identical. ──
    $stmt = $pdo->prepare('SELECT * FROM fixture_streams WHERE id = :id AND is_custom = 1 LIMIT 1');
    $stmt->execute(['id' => $customId]);
    $match = $stmt->fetch();

    if ($match === false) {
        http_response_code(404);
        $pageTitle = 'Siaran Tidak Ditemukan — Sagagoal';
        $pageDescription = 'Siaran yang kamu cari tidak ditemukan.';
        require __DIR__ . '/includes/site-header.php';
        ?>
        <section class="crypto-section">
            <div class="crypto-container">
                <div class="empty-state">
                    <?= wpm_icon('info') ?>
                    <p>Siaran tidak ditemukan.</p>
                    <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_site_url(wpm_url_live())) ?>" style="margin-top:16px;display:inline-flex;">Lihat Semua Live</a>
                </div>
            </div>
        </section>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
        <?php
        exit;
    }

    $homeName = (string) $match['custom_home_name'];
    $awayName = (string) $match['custom_away_name'];
    $isLive = (int) ($match['is_live'] ?? 0) === 1;
    $embedHtml = $isLive ? wpm_render_live_embed((string) ($match['embed_code'] ?? '')) : null;
    $showPlayer = $embedHtml !== null;
    $streamTitle = trim((string) ($match['stream_title'] ?? ''));
    $streamDescription = trim((string) ($match['stream_description'] ?? ''));
    $leagueName = trim((string) ($match['custom_league_name'] ?? ''));
    $matchTitle = $homeName . ' vs ' . $awayName;

    $pageTitle = ($isLive ? '🔴 Live: ' : '') . ($streamTitle !== '' ? $streamTitle : $matchTitle) . ' — Sagagoal';
    $pageDescription = $streamDescription !== ''
        ? $streamDescription
        : ($isLive ? "Nonton siaran langsung $matchTitle di Sagagoal, gratis langsung dari browser." : "Info live streaming untuk $matchTitle.");
    $activeNav = 'live';
    $canonicalUrl = wpm_url_live_custom($customId, $homeName, $awayName);

    require __DIR__ . '/includes/site-header.php';
    ?>

    <section class="page-hero">
        <div class="crypto-container">
            <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= wpm_esc(wpm_site_url('')) ?>">Beranda</a> <span>/</span> <a href="<?= wpm_esc(wpm_site_url(wpm_url_live())) ?>">Live Streaming</a> <span>/</span> <?= wpm_esc($matchTitle) ?></nav>
            <span class="section-kicker"><?= $isLive ? '🔴 SEDANG LIVE' : 'LIVE STREAMING' ?></span>
            <h1><?= wpm_esc($streamTitle !== '' ? $streamTitle : $matchTitle) ?></h1>
            <?php if ($leagueName !== '') : ?><p><?= wpm_esc($leagueName) ?></p><?php endif; ?>
            <?php if ($streamDescription !== '') : ?>
                <div class="page-hero-lead"><?= wpm_esc($streamDescription) ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="crypto-section--tight">
        <div class="crypto-container">
            <?php if ($showPlayer) : ?>
                <div class="glass-card wpm-live-player-card">
                    <div class="wpm-live-player-card__frame">
                        <?= $embedHtml ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="glass-card empty-state" style="padding:48px 24px;">
                    <?= wpm_icon('info') ?>
                    <p><strong><?= wpm_esc($matchTitle) ?></strong> belum/tidak sedang live streaming.</p>
                    <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_site_url(wpm_url_live())) ?>" style="margin-top:16px;display:inline-flex;">Lihat Pertandingan Live Lainnya</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    </main>
    <?php require __DIR__ . '/includes/site-footer.php'; ?>
    <?php
    exit;
}

if ($fixtureId > 0) {
    // ── Single-match mode ──────────────────────────────────────────
    $stmt = $pdo->prepare(
        "SELECT fs.*, f.status_short, f.elapsed, f.kickoff_at,
                l.name AS league_name, ht.name AS home_name, at.name AS away_name,
                ht.logo AS home_logo, at.logo AS away_logo
         FROM fixtures f
         JOIN leagues l ON l.id = f.league_id
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         LEFT JOIN fixture_streams fs ON fs.fixture_id = f.id AND fs.is_custom = 0
         WHERE f.id = :id
         LIMIT 1"
    );
    $stmt->execute(['id' => $fixtureId]);
    $match = $stmt->fetch();

    if ($match === false) {
        http_response_code(404);
        $pageTitle = 'Pertandingan Tidak Ditemukan — Sagagoal';
        $pageDescription = 'Pertandingan yang kamu cari tidak ditemukan.';
        require __DIR__ . '/includes/site-header.php';
        ?>
        <section class="crypto-section">
            <div class="crypto-container">
                <div class="empty-state">
                    <?= wpm_icon('info') ?>
                    <p>Pertandingan tidak ditemukan.</p>
                    <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_site_url(wpm_url_live())) ?>" style="margin-top:16px;display:inline-flex;">Lihat Semua Live</a>
                </div>
            </div>
        </section>
        </main>
        <?php require __DIR__ . '/includes/site-footer.php'; ?>
        <?php
        exit;
    }

    $homeName = (string) $match['home_name'];
    $awayName = (string) $match['away_name'];
    $isLive = (int) ($match['is_live'] ?? 0) === 1;
    $embedHtml = $isLive ? wpm_render_live_embed((string) ($match['embed_code'] ?? '')) : null;
    $showPlayer = $embedHtml !== null;
    $streamTitle = trim((string) ($match['stream_title'] ?? ''));
    $streamDescription = trim((string) ($match['stream_description'] ?? ''));
    $matchTitle = $homeName . ' vs ' . $awayName;

    $pageTitle = ($isLive ? '🔴 Live: ' : '') . ($streamTitle !== '' ? $streamTitle : $matchTitle) . ' — Sagagoal';
    $pageDescription = $streamDescription !== ''
        ? $streamDescription
        : ($isLive ? "Nonton siaran langsung $matchTitle di Sagagoal, gratis langsung dari browser." : "Info live streaming untuk pertandingan $matchTitle.");
    $activeNav = 'live';
    $canonicalUrl = wpm_url_live_match($fixtureId, $homeName, $awayName);

    require __DIR__ . '/includes/site-header.php';
    ?>

    <section class="page-hero">
        <div class="crypto-container">
            <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= wpm_esc(wpm_site_url('')) ?>">Beranda</a> <span>/</span> <a href="<?= wpm_esc(wpm_site_url(wpm_url_live())) ?>">Live Streaming</a> <span>/</span> <?= wpm_esc($matchTitle) ?></nav>
            <span class="section-kicker"><?= $isLive ? '🔴 SEDANG LIVE' : 'LIVE STREAMING' ?></span>
            <h1><?= wpm_esc($streamTitle !== '' ? $streamTitle : $matchTitle) ?></h1>
            <p><?= wpm_esc((string) $match['league_name']) ?></p>
            <?php if ($streamDescription !== '') : ?>
                <div class="page-hero-lead"><?= wpm_esc($streamDescription) ?></div>
            <?php endif; ?>
        </div>
    </section>

    <section class="crypto-section--tight">
        <div class="crypto-container">
            <?php if ($showPlayer) : ?>
                <div class="glass-card wpm-live-player-card">
                    <div class="wpm-live-player-card__frame">
                        <?= $embedHtml ?>
                    </div>
                </div>
            <?php else : ?>
                <div class="glass-card empty-state" style="padding:48px 24px;">
                    <?= wpm_icon('info') ?>
                    <p>Pertandingan <strong><?= wpm_esc($matchTitle) ?></strong> belum/tidak sedang live streaming.</p>
                    <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_site_url(wpm_url_live())) ?>" style="margin-top:16px;display:inline-flex;">Lihat Pertandingan Live Lainnya</a>
                </div>
            <?php endif; ?>
        </div>
    </section>

    </main>
    <?php require __DIR__ . '/includes/site-footer.php'; ?>
    <?php
    exit;
}

// ── Listing mode (/live, no id) ─────────────────────────────────────
$liveMatches = [];
try {
    // UNION: real fixture-linked rows (need the fixtures/teams/leagues
    // join for names+logos+kickoff) with manual/custom rows (names come
    // straight off their own custom_* columns, no kickoff to sort by so
    // NULL falls back to created_at). is_custom flag carried through so
    // the render loop below knows which URL helper/logo source to use.
    // NOTE: name/league columns wrapped in CONVERT(...USING utf8mb4)
    // COLLATE utf8mb4_unicode_ci — without this MySQL throws "Illegal mix
    // of collations for operation 'UNION'" because these come from
    // `teams`/`leagues` on one side vs fixture_streams' own custom_*
    // columns on the other, which don't necessarily share a collation
    // (8 Sep 2026 bug, same root cause as the admin panel's query).
    $liveMatches = $pdo->query(
        "SELECT fs.*, f.status_short, f.elapsed, f.kickoff_at,
                CONVERT(l.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS league_name,
                CONVERT(ht.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS home_name,
                CONVERT(at.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS away_name,
                ht.logo AS home_logo, at.logo AS away_logo
         FROM fixture_streams fs
         JOIN fixtures f ON f.id = fs.fixture_id
         JOIN leagues l ON l.id = f.league_id
         JOIN teams ht ON ht.id = f.home_team_id
         JOIN teams at ON at.id = f.away_team_id
         WHERE fs.is_live = 1 AND fs.is_custom = 0
         UNION ALL
         SELECT fs.*, NULL AS status_short, NULL AS elapsed, fs.created_at AS kickoff_at,
                CONVERT(fs.custom_league_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS league_name,
                CONVERT(fs.custom_home_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS home_name,
                CONVERT(fs.custom_away_name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS away_name,
                NULL AS home_logo, NULL AS away_logo
         FROM fixture_streams fs
         WHERE fs.is_live = 1 AND fs.is_custom = 1
         ORDER BY kickoff_at DESC"
    )->fetchAll();
} catch (Throwable $e) {
    $liveMatches = [];
}

$pageTitle = $liveMatches !== [] ? '🔴 Live Streaming — Sagagoal' : 'Live Streaming — Sagagoal';
$pageDescription = 'Nonton siaran langsung pertandingan sepak bola di Sagagoal, gratis langsung dari browser.';
$activeNav = 'live';
$canonicalUrl = wpm_site_url(wpm_url_live());

require __DIR__ . '/includes/site-header.php';
?>

<section class="page-hero">
    <div class="crypto-container">
        <nav class="breadcrumb" aria-label="Breadcrumb"><a href="<?= wpm_esc(wpm_site_url('')) ?>">Beranda</a> <span>/</span> Live Streaming</nav>
        <span class="section-kicker"><?= $liveMatches !== [] ? '🔴 SEDANG LIVE' : 'LIVE STREAMING' ?></span>
        <h1>Live Streaming</h1>
        <p><?= count($liveMatches) ?> pertandingan sedang live saat ini.</p>
    </div>
</section>

<section class="crypto-section--tight">
    <div class="crypto-container">
        <?php if ($liveMatches !== []) : ?>
            <div class="crypto-grid crypto-grid--3">
                <?php foreach ($liveMatches as $match) :
                    $isCustomMatch = (int) $match['is_custom'] === 1;
                    $homeName = (string) $match['home_name'];
                    $awayName = (string) $match['away_name'];
                    $homeLogo = wpm_image($match['home_logo'] ?? null);
                    $awayLogo = wpm_image($match['away_logo'] ?? null);
                    $matchUrl = $isCustomMatch
                        ? wpm_url_live_custom((int) $match['id'], $homeName, $awayName)
                        : wpm_url_live_match((int) $match['fixture_id'], $homeName, $awayName);
                    $matchTitle = trim((string) ($match['stream_title'] ?? '')) !== '' ? (string) $match['stream_title'] : ($homeName . ' vs ' . $awayName);
                    ?>
                    <a class="glass-card wpm-live-match-card" href="<?= wpm_esc($matchUrl) ?>">
                        <span class="wpm-live-match-card__badge"><span class="fixture-card__live-stream-dot" aria-hidden="true"></span>Live<?= $isCustomMatch ? ' · Manual' : '' ?></span>
                        <div class="wpm-live-match-card__teams">
                            <span class="wpm-live-match-card__team"><?= $homeLogo !== null ? '<img src="' . wpm_esc($homeLogo) . '" alt="" loading="lazy">' : wpm_icon('trophy') ?><?= wpm_esc($homeName) ?></span>
                            <span class="wpm-live-match-card__vs">vs</span>
                            <span class="wpm-live-match-card__team"><?= $awayLogo !== null ? '<img src="' . wpm_esc($awayLogo) . '" alt="" loading="lazy">' : wpm_icon('trophy') ?><?= wpm_esc($awayName) ?></span>
                        </div>
                        <?php if (trim((string) ($match['league_name'] ?? '')) !== '') : ?>
                            <p class="wpm-live-match-card__league"><?= wpm_esc((string) $match['league_name']) ?></p>
                        <?php endif; ?>
                    </a>
                <?php endforeach; ?>
            </div>
        <?php else : ?>
            <div class="glass-card empty-state" style="padding:48px 24px;">
                <?= wpm_icon('info') ?>
                <p>Belum ada siaran langsung saat ini. Pantau terus halaman ini — begitu ada pertandingan live, siarannya akan otomatis tampil di sini.</p>
                <a class="crypto-btn crypto-btn--primary" href="<?= wpm_esc(wpm_site_url('')) ?>" style="margin-top:16px;display:inline-flex;">Kembali ke Beranda</a>
            </div>
        <?php endif; ?>
    </div>
</section>

</main>
<?php require __DIR__ . '/includes/site-footer.php'; ?>
