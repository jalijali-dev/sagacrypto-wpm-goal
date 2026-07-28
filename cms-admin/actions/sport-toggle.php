<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__, 2) . '/includes/LivescoreSettings.php';
require_once dirname(__DIR__, 2) . '/includes/BasketballSettings.php';
require_once dirname(__DIR__, 2) . '/includes/FormulaOneSettings.php';

/**
 * Quick on/off toggle for one row in the consolidated Livescore API
 * Settings accordion (cms-admin/pages/livescore-api-settings.php) — lets
 * an admin flip a sport's is_active without opening its full form.
 * Updates that sport's own settings table AND propagates to `sports.
 * is_active` via that sport's syncSportVisibility(), same as saving the
 * full settings form would.
 */

cms_require_role(['superadmin']);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$sport = (string) ($_POST['sport'] ?? '');
$isActive = !empty($_POST['is_active']);

switch ($sport) {
    case 'football':
        $pdo->prepare('UPDATE sports_api_settings SET is_active = :is_active WHERE sport_key = \'football\'')
            ->execute(['is_active' => $isActive ? 1 : 0]);
        break;
    case 'basketball':
        $pdo->prepare('UPDATE sports_api_settings SET is_active = :is_active WHERE sport_key = \'basketball\'')
            ->execute(['is_active' => $isActive ? 1 : 0]);
        BasketballSettings::syncSportVisibility($pdo, $isActive);
        break;
    case 'motorsport':
        $pdo->prepare('UPDATE sports_api_settings SET is_active = :is_active WHERE sport_key = \'motorsport\'')
            ->execute(['is_active' => $isActive ? 1 : 0]);
        FormulaOneSettings::syncSportVisibility($pdo, $isActive);
        break;
    default:
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown sport.']);
        exit;
}

// Football doesn't have its own syncSportVisibility() (its /football page
// was never gated by the `sports` table's is_active — it's the one sport
// that also has its own permanent nav item), so mirror the sports-table
// flip here for consistency with the accordion's badge.
if ($sport === 'football') {
    require_once dirname(__DIR__, 2) . '/includes/SportsRegistry.php';
    wpm_ensure_sports_table($pdo);
    $pdo->prepare('UPDATE sports SET is_active = :is_active WHERE `key` = \'football\'')
        ->execute(['is_active' => $isActive ? 1 : 0]);
}

echo json_encode(['ok' => true, 'sport' => $sport, 'is_active' => $isActive]);
