<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__, 2) . '/includes/LivescoreSettings.php';
require_once dirname(__DIR__, 2) . '/includes/ApiFootballClient.php';
require_once dirname(__DIR__, 2) . '/includes/BasketballSettings.php';
require_once dirname(__DIR__, 2) . '/includes/FormulaOneSettings.php';
require_once dirname(__DIR__, 2) . '/includes/SportsRegistry.php';

/**
 * Consolidated "Livescore API Settings" hub — one page for every sport's
 * API config (football/API-Football, basketball/API-Basketball,
 * motorsport/API-Formula-1), replacing the 3 separate settings pages
 * that used to live in the sidebar. Each sport's form is the exact same
 * markup that used to live in its own standalone page — moved into
 * cms-admin/partials/settings-form-<sport>.php and included here inside
 * an accordion row, not rewritten. Test Connection / Sync Sekarang keep
 * calling their existing per-sport action endpoints unchanged.
 *
 * Rows are sourced from the `sports` registry table (see
 * includes/SportsRegistry.php) — the same table the homepage's sport
 * filter chips read — so this page and the public site never drift out
 * of sync.
 */

cms_require_role(['superadmin']);

$selfUrl = 'livescore-api-settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sport = (string) ($_POST['sport'] ?? '');

    // nav_placement/sort_order (24 Jul 2026 sports_api_settings consolidation) —
    // shared across all 3 sports, saved alongside each sport's own fields below.
    $navPlacement = (string) ($_POST['nav_placement'] ?? 'menu');
    if (!in_array($navPlacement, ['menu', 'footer', 'hidden'], true)) {
        $navPlacement = 'menu';
    }
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    // Page title/subtitle (24 Jul 2026) — public-facing hero text on
    // football.php/basket.php/f1.php, editable per sport here instead of
    // hardcoded. Saved as NULL when left blank so the frontend's own
    // fallback-to-default logic kicks in (see e.g. football.php).
    $sportPageTitleInput = trim((string) ($_POST['page_title'] ?? ''));
    $sportPageSubtitleInput = trim((string) ($_POST['page_subtitle'] ?? ''));
    $sportPageTitle = $sportPageTitleInput !== '' ? $sportPageTitleInput : null;
    $sportPageSubtitle = $sportPageSubtitleInput !== '' ? $sportPageSubtitleInput : null;

    if ($sport === 'football') {
        $provider = trim((string) ($_POST['provider'] ?? '')) ?: 'API-Football';
        $baseUrl = trim((string) ($_POST['base_url'] ?? '')) ?: 'https://v3.football.api-sports.io';
        $apiKeyHeader = trim((string) ($_POST['api_key_header'] ?? '')) ?: 'x-apisports-key';
        $apiKeyInput = trim((string) ($_POST['api_key'] ?? ''));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $autoSyncEnabled = !empty($_POST['auto_sync_enabled']) ? 1 : 0;
        $syncFixturesInterval = max(1, (int) ($_POST['sync_fixtures_interval_minutes'] ?? 5)) * 60;
        $syncLeaguesTeamsInterval = max(1, (int) ($_POST['sync_leagues_teams_interval_hours'] ?? 1)) * 3600;
        $cacheDurationLive = max(10, (int) ($_POST['cache_duration_live'] ?? 60));
        $trackedLeagueIds = LivescoreSettings::formatLeagueIds(
            array_map('intval', $_POST['tracked_league_ids'] ?? [])
        );

        $setSql = 'provider = :provider, base_url = :base_url, api_key_header = :api_key_header,
            tracked_ids = :tracked_ids, sync_primary_interval = :sync_primary_interval,
            sync_secondary_interval = :sync_secondary_interval,
            cache_duration_live = :cache_duration_live, is_active = :is_active,
            auto_sync_enabled = :auto_sync_enabled, nav_placement = :nav_placement, sort_order = :sort_order,
            page_title = :page_title, page_subtitle = :page_subtitle';
        $params = [
            'provider' => $provider,
            'base_url' => $baseUrl,
            'api_key_header' => $apiKeyHeader,
            'tracked_ids' => $trackedLeagueIds !== '' ? $trackedLeagueIds : null,
            'sync_primary_interval' => $syncFixturesInterval,
            'sync_secondary_interval' => $syncLeaguesTeamsInterval,
            'cache_duration_live' => $cacheDurationLive,
            'is_active' => $isActive,
            'auto_sync_enabled' => $autoSyncEnabled,
            'nav_placement' => $navPlacement,
            'sort_order' => $sortOrder,
            'page_title' => $sportPageTitle,
            'page_subtitle' => $sportPageSubtitle,
        ];
        if ($apiKeyInput !== '') {
            $setSql .= ', api_key_enc = :api_key_enc';
            $params['api_key_enc'] = cms_ai_encrypt($apiKeyInput);
        }
        $pdo->prepare("UPDATE sports_api_settings SET {$setSql} WHERE sport_key = 'football'")->execute($params);

        wpm_ensure_sports_table($pdo);
        $pdo->prepare('UPDATE sports SET is_active = :is_active WHERE `key` = \'football\'')
            ->execute(['is_active' => $isActive]);

        $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Football (Livescore) API settings saved.'];
    } elseif ($sport === 'basketball') {
        $provider = trim((string) ($_POST['provider'] ?? '')) ?: 'API-Basketball (NBA)';
        $baseUrl = trim((string) ($_POST['base_url'] ?? '')) ?: 'https://v2.nba.api-sports.io';
        $apiKeyHeader = trim((string) ($_POST['api_key_header'] ?? '')) ?: 'x-apisports-key';
        $apiKeyInput = trim((string) ($_POST['api_key'] ?? ''));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $autoSyncEnabled = !empty($_POST['auto_sync_enabled']) ? 1 : 0;
        $syncGamesInterval = max(1, (int) ($_POST['sync_games_interval_minutes'] ?? 5)) * 60;
        $cacheDurationLive = max(10, (int) ($_POST['cache_duration_live'] ?? 60));

        $setSql = 'provider = :provider, base_url = :base_url, api_key_header = :api_key_header,
            sync_primary_interval = :sync_primary_interval, cache_duration_live = :cache_duration_live,
            is_active = :is_active, auto_sync_enabled = :auto_sync_enabled,
            nav_placement = :nav_placement, sort_order = :sort_order,
            page_title = :page_title, page_subtitle = :page_subtitle';
        $params = [
            'provider' => $provider,
            'base_url' => $baseUrl,
            'api_key_header' => $apiKeyHeader,
            'sync_primary_interval' => $syncGamesInterval,
            'cache_duration_live' => $cacheDurationLive,
            'is_active' => $isActive,
            'auto_sync_enabled' => $autoSyncEnabled,
            'nav_placement' => $navPlacement,
            'sort_order' => $sortOrder,
            'page_title' => $sportPageTitle,
            'page_subtitle' => $sportPageSubtitle,
        ];
        if ($apiKeyInput !== '') {
            $setSql .= ', api_key_enc = :api_key_enc';
            $params['api_key_enc'] = cms_ai_encrypt($apiKeyInput);
        }
        $pdo->prepare("UPDATE sports_api_settings SET {$setSql} WHERE sport_key = 'basketball'")->execute($params);
        BasketballSettings::syncSportVisibility($pdo, (bool) $isActive);

        $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Basketball (NBA) API settings saved.'];
    } elseif ($sport === 'motorsport') {
        $provider = trim((string) ($_POST['provider'] ?? '')) ?: 'API-Formula-1';
        $baseUrl = trim((string) ($_POST['base_url'] ?? '')) ?: 'https://v1.formula-1.api-sports.io';
        $apiKeyHeader = trim((string) ($_POST['api_key_header'] ?? '')) ?: 'x-apisports-key';
        $apiKeyInput = trim((string) ($_POST['api_key'] ?? ''));
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        $autoSyncEnabled = !empty($_POST['auto_sync_enabled']) ? 1 : 0;
        $syncInterval = max(1, (int) ($_POST['sync_interval_minutes'] ?? 60)) * 60;

        $setSql = 'provider = :provider, base_url = :base_url, api_key_header = :api_key_header,
            sync_primary_interval = :sync_primary_interval, is_active = :is_active, auto_sync_enabled = :auto_sync_enabled,
            nav_placement = :nav_placement, sort_order = :sort_order,
            page_title = :page_title, page_subtitle = :page_subtitle';
        $params = [
            'provider' => $provider,
            'base_url' => $baseUrl,
            'api_key_header' => $apiKeyHeader,
            'sync_primary_interval' => $syncInterval,
            'is_active' => $isActive,
            'auto_sync_enabled' => $autoSyncEnabled,
            'nav_placement' => $navPlacement,
            'sort_order' => $sortOrder,
            'page_title' => $sportPageTitle,
            'page_subtitle' => $sportPageSubtitle,
        ];
        if ($apiKeyInput !== '') {
            $setSql .= ', api_key_enc = :api_key_enc';
            $params['api_key_enc'] = cms_ai_encrypt($apiKeyInput);
        }
        $pdo->prepare("UPDATE sports_api_settings SET {$setSql} WHERE sport_key = 'motorsport'")->execute($params);
        FormulaOneSettings::syncSportVisibility($pdo, (bool) $isActive);

        $_SESSION['cms_flash'] = ['type' => 'success', 'message' => 'Formula 1 API settings saved.'];
    }

    header('Location: ' . $selfUrl . ($sport !== '' ? '?open=' . urlencode($sport) : ''), true, 302);
    exit;
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$openKey = (string) ($_GET['open'] ?? '');

$footballSettings = LivescoreSettings::load($pdo);
$basketballSettings = BasketballSettings::load($pdo);
$f1Settings = FormulaOneSettings::load($pdo);

// Football's tracked-league checklist names — same lookup the old
// standalone page did (local `leagues` table first, live API fallback
// for anything still unresolved).
$trackedNames = [];
if ($footballSettings['tracked_league_ids'] !== []) {
    $placeholders = implode(',', array_fill(0, count($footballSettings['tracked_league_ids']), '?'));
    $stmt = $pdo->prepare("SELECT id, name, country FROM leagues WHERE id IN ({$placeholders})");
    $stmt->execute($footballSettings['tracked_league_ids']);
    foreach ($stmt->fetchAll() as $row) {
        $trackedNames[(int) $row['id']] = $row['name'] . (!empty($row['country']) ? ' (' . $row['country'] . ')' : '');
    }
    $stillMissing = array_diff($footballSettings['tracked_league_ids'], array_keys($trackedNames));
    if ($stillMissing !== [] && $footballSettings['api_key'] !== '') {
        $client = ApiFootballClient::fromSettings($footballSettings);
        foreach ($stillMissing as $missingId) {
            $lookup = $client->leagues(['id' => $missingId]);
            if (!$lookup['ok'] || empty($lookup['data']['response'][0])) {
                continue;
            }
            $entry = $lookup['data']['response'][0];
            $name = (string) ($entry['league']['name'] ?? '');
            $country = (string) ($entry['country']['name'] ?? '');
            if ($name !== '') {
                $trackedNames[$missingId] = $name . ($country !== '' ? ' (' . $country . ')' : '');
            }
        }
    }
}

$sports = wpm_all_sports($pdo);
$sportEmoji = ['football' => '⚽', 'basketball' => '🏀', 'motorsport' => '🏎️'];
$sportSettingsByKey = [
    'football' => $footballSettings,
    'basketball' => $basketballSettings,
    'motorsport' => $f1Settings,
];

/** @return array{0: string, 1: string} [label, tone] */
$statusFor = static function (array $sportRow, array $settingsByKey): array {
    $key = (string) $sportRow['key'];
    $settings = $settingsByKey[$key] ?? null;
    if ($settings === null) {
        return [(int) $sportRow['is_active'] === 1 ? 'Aktif' : 'Segera Hadir', (int) $sportRow['is_active'] === 1 ? 'ok' : 'muted'];
    }
    if ($settings['is_active']) {
        return ['Aktif', 'ok'];
    }
    if ($settings['api_key'] !== '') {
        return ['Nonaktif', 'warn'];
    }
    return ['Segera Hadir', 'muted'];
};

/**
 * Quota-exhaustion visual alert (24 Jul 2026) — last_test_status/message/at
 * now get written by the cron sync itself on a quota failure (see
 * *Settings::recordSyncFailure() / wpm_is_quota_error() in the Sync
 * files), not just by a manual Test Connection click, so this badge
 * surfaces a cron-detected outage without needing an admin to go check
 * server logs. Returns null when there's nothing to show (last test
 * succeeded, or never ran).
 *
 * @return ?array{0: string, 1: string} [badge label, tooltip]
 */
$syncFailureBadgeFor = static function (array $sportRow, array $settingsByKey): ?array {
    $key = (string) $sportRow['key'];
    $settings = $settingsByKey[$key] ?? null;
    if ($settings === null || ($settings['last_test_status'] ?? null) !== 'failed') {
        return null;
    }
    $message = (string) ($settings['last_test_message'] ?? '');
    $isQuota = stripos($message, 'request limit') !== false;
    $label = $isQuota ? '⚠ Sync terakhir gagal: quota habis' : '⚠ Sync terakhir gagal';
    $lastAt = $settings['last_test_at'] ?? null;
    $tooltip = $message . ($lastAt !== null ? ' — ' . $lastAt : '');
    return [$label, $tooltip];
};

$sportRequests = [];
try {
    cms_ensure_table(
        $pdo,
        'sport_requests',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         sport_name VARCHAR(100) NOT NULL,
         notes TEXT DEFAULT NULL,
         requested_by VARCHAR(150) DEFAULT NULL,
         status VARCHAR(20) NOT NULL DEFAULT \'open\',
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP'
    );
    $sportRequests = $pdo->query('SELECT * FROM sport_requests ORDER BY created_at DESC LIMIT 20')->fetchAll();
} catch (Throwable $e) {
    $sportRequests = [];
}

$pageTitle = 'Livescore API Settings';
$currentNav = 'livescore-api-settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Livescore API Settings', 'href' => ''],
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
            <h2 class="section-title">Livescore API Settings</h2>
            <p class="section-lead">Konfigurasi provider API per cabang olahraga. Klik salah satu baris untuk buka form pengaturannya — tiap cabang punya API key, Test Connection, dan Sync Sekarang sendiri-sendiri.</p>
        </div>
    </div>

    <div class="panel" style="padding:0;overflow:hidden;">
        <?php foreach ($sports as $sportRow) : ?>
            <?php
            $key = (string) $sportRow['key'];
            [$statusLabel, $statusTone] = $statusFor($sportRow, $sportSettingsByKey);
            $isOpen = $openKey === $key;
            ?>
            <details class="sport-accordion__item"<?= $isOpen ? ' open' : '' ?>>
                <summary class="sport-accordion__summary">
                    <span class="sport-accordion__emoji"><?= $sportEmoji[$key] ?? '🏆' ?></span>
                    <span class="sport-accordion__name"><?= cms_esc((string) $sportRow['name']) ?></span>
                    <span class="pill pill--<?= $statusTone ?>"><?= cms_esc($statusLabel) ?></span>
                    <?php $failureBadge = $syncFailureBadgeFor($sportRow, $sportSettingsByKey); ?>
                    <?php if ($failureBadge !== null) : ?>
                        <span class="pill pill--warn" title="<?= cms_esc($failureBadge[1]) ?>"><?= cms_esc($failureBadge[0]) ?></span>
                    <?php endif; ?>
                    <?php if (!empty($sportRow['notes'])) : ?>
                        <?php
                        // notes is free text in `sports`, one row per sport (25 Jul 2026
                        // consistency fix) — stored as "main line\nsecondary line" so every
                        // sport renders with the same 2-tier hierarchy (provider info, then
                        // dimmer setup instruction) regardless of what each row's text says.
                        // A row with no \n (or a future sport that only fills in one line)
                        // still renders fine — sub just doesn't appear.
                        $notesLines = array_values(array_filter(array_map('trim', explode("\n", (string) $sportRow['notes']))));
                        $notesMain = $notesLines[0] ?? '';
                        $notesSub = implode(' ', array_slice($notesLines, 1));
                        ?>
                        <span class="sport-accordion__notes">
                            <?php if ($notesMain !== '') : ?><span class="sport-accordion__notes-main"><?= cms_esc($notesMain) ?></span><?php endif; ?>
                            <?php if ($notesSub !== '') : ?><span class="sport-accordion__notes-sub"><?= cms_esc($notesSub) ?></span><?php endif; ?>
                        </span>
                    <?php endif; ?>
                    <label class="sport-accordion__quick-toggle" onclick="event.stopPropagation();">
                        <input type="checkbox" class="sport-quick-toggle"
                               data-sport="<?= cms_esc($key) ?>"
                               data-toggle-action="<?= cms_esc(cms_action_href('sport-toggle.php')) ?>"
                               data-csrf-token="<?= cms_esc(cms_csrf_token()) ?>"
                               <?= (int) $sportRow['is_active'] === 1 ? 'checked' : '' ?>>
                        <span></span>
                    </label>
                    <span class="sport-accordion__hint">Kelola</span>
                    <svg class="sport-accordion__chevron" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 12 15 18 9"></polyline></svg>
                </summary>
                <div class="sport-accordion__body">
                    <?php if ($key === 'football') : ?>
                        <?php $settings = $footballSettings; require dirname(__DIR__) . '/partials/settings-form-football.php'; ?>
                    <?php elseif ($key === 'basketball') : ?>
                        <?php $settings = $basketballSettings; require dirname(__DIR__) . '/partials/settings-form-basketball.php'; ?>
                    <?php elseif ($key === 'motorsport') : ?>
                        <?php $settings = $f1Settings; require dirname(__DIR__) . '/partials/settings-form-f1.php'; ?>
                    <?php endif; ?>
                </div>
            </details>
        <?php endforeach; ?>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">Tambah Cabang Sport Baru</h3>
        </div>
        <p class="section-lead">Menambahkan cabang olahraga baru memerlukan pekerjaan development terpisah (client API + skema database sendiri, seperti NBA/Formula 1 di atas) — form ini <strong>tidak</strong> langsung mengaktifkan apa pun, cuma mengajukan permintaan yang direview manual.</p>
        <form method="post" action="<?= cms_esc(cms_action_href('sport-request-submit.php')) ?>" class="form-grid">
            <?= cms_csrf_field() ?>
            <label class="field">Nama cabang olahraga
                <input type="text" name="sport_name" placeholder="Misal: Badminton, MotoGP, eSports..." required>
            </label>
            <label class="field" style="grid-column: 1 / -1;">Catatan / provider yang diusulkan (opsional)
                <textarea name="notes" rows="3" placeholder="Misal: sumber data yang disarankan, alasan permintaan, dsb."></textarea>
            </label>
            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary">Ajukan Permintaan</button>
            </div>
        </form>

        <?php if ($sportRequests !== []) : ?>
            <div class="table-wrap" style="margin-top:20px;">
                <table class="admin-table">
                    <thead><tr><th>Cabang Diminta</th><th>Catatan</th><th>Diajukan Oleh</th><th>Tanggal</th></tr></thead>
                    <tbody>
                        <?php foreach ($sportRequests as $req) : ?>
                            <tr>
                                <td><?= cms_esc((string) $req['sport_name']) ?></td>
                                <td><?= cms_esc((string) ($req['notes'] ?? '—')) ?></td>
                                <td><?= cms_esc((string) ($req['requested_by'] ?? '—')) ?></td>
                                <td><?= cms_esc(date('d M Y, H:i', strtotime((string) $req['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</section>

<style>
.sport-accordion__item { border-bottom: 1px solid var(--border, #2a2a35); }
.sport-accordion__item:last-child { border-bottom: none; }
.sport-accordion__summary {
    list-style: none;
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 18px 22px;
    cursor: pointer;
    user-select: none;
    transition: background .16s ease;
}
.sport-accordion__summary:hover { background: var(--table-row-hover); }
.sport-accordion__summary::-webkit-details-marker { display: none; }
.sport-accordion__emoji { font-size: 22px; }
.sport-accordion__name { font-weight: 700; font-size: 15px; min-width: 140px; }
.sport-accordion__notes { display: flex; flex-direction: column; gap: 2px; flex: 1; }
.sport-accordion__notes-main { font-size: 12.5px; opacity: .75; }
.sport-accordion__notes-sub { font-size: 11.5px; opacity: .5; }
.sport-accordion__body { padding: 4px 22px 24px; }
.sport-accordion__quick-toggle { margin-left: auto; display: inline-flex; align-items: center; cursor: pointer; }
.sport-accordion__hint { font-size: 12.5px; color: var(--muted, #888); flex-shrink: 0; }
.sport-accordion__chevron { flex-shrink: 0; color: var(--muted, #888); transition: transform 0.2s ease; }
.sport-accordion__item[open] > .sport-accordion__summary .sport-accordion__chevron { transform: rotate(180deg); }
.sport-accordion__quick-toggle input { display: none; }
.sport-accordion__quick-toggle span {
    display: inline-block;
    width: 38px;
    height: 22px;
    border-radius: 999px;
    background: var(--border, #3a3a45);
    position: relative;
    transition: background .16s ease;
}
.sport-accordion__quick-toggle span::before {
    content: "";
    position: absolute;
    top: 2px;
    left: 2px;
    width: 18px;
    height: 18px;
    border-radius: 50%;
    background: #fff;
    transition: transform .16s ease;
}
.sport-accordion__quick-toggle input:checked + span { background: #22c55e; }
.sport-accordion__quick-toggle input:checked + span::before { transform: translateX(16px); }
</style>

<script>
(function () {
    Array.prototype.forEach.call(document.querySelectorAll('.sport-quick-toggle'), function (toggle) {
        toggle.addEventListener('change', function () {
            var sport = toggle.dataset.sport;
            var action = toggle.dataset.toggleAction;
            var token = toggle.dataset.csrfToken;
            var checked = toggle.checked;
            toggle.disabled = true;

            var body = new URLSearchParams();
            body.set('sport', sport);
            body.set('is_active', checked ? '1' : '0');

            fetch(action, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                    'X-CSRF-Token': token || ''
                },
                body: body.toString(),
                credentials: 'same-origin'
            })
                .then(function (res) { return res.json(); })
                .then(function (data) {
                    if (!data.ok) {
                        toggle.checked = !checked;
                        alert(data.error || 'Gagal mengubah status.');
                        return;
                    }
                    // Reload so the status badge + disabled Sync Sekarang
                    // button (which depends on is_active) stay in sync.
                    window.location.reload();
                })
                .catch(function () {
                    toggle.checked = !checked;
                    alert('Request gagal (jaringan/server error).');
                })
                .finally(function () {
                    toggle.disabled = false;
                });
        });
    });
})();
</script>

<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
