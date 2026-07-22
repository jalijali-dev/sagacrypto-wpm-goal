<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__, 2) . '/includes/LivescoreSettings.php';
require_once dirname(__DIR__, 2) . '/includes/ApiFootballClient.php';

/**
 * Fetches the full /leagues catalogue from API-Football so
 * livescore-api-settings.php can render tracked_league_ids as a
 * name-based checklist instead of raw ids. Deliberately a separate,
 * explicit "Muat Daftar Liga" button click rather than auto-loading on
 * every page view — this list is large and the call costs API quota.
 */

cms_require_role(['superadmin']);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$settings = LivescoreSettings::load($pdo);

if ($settings['api_key'] === '') {
    echo json_encode(['ok' => false, 'error' => 'Simpan dan test API key dulu sebelum memuat daftar liga.']);
    exit;
}

$client = ApiFootballClient::fromSettings($settings);
$result = $client->leagues();

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'error' => $result['error'] ?: 'Gagal mengambil daftar liga dari API-Football.']);
    exit;
}

$leagues = [];
foreach ($result['data']['response'] ?? [] as $entry) {
    $league = $entry['league'] ?? [];
    $country = $entry['country'] ?? [];
    if (empty($league['id'])) {
        continue;
    }
    $leagues[] = [
        'id' => (int) $league['id'],
        'name' => (string) ($league['name'] ?? ''),
        'country' => (string) ($country['name'] ?? ''),
    ];
}

usort($leagues, static fn (array $a, array $b): int => strcmp($a['country'] . $a['name'], $b['country'] . $b['name']));

echo json_encode(['ok' => true, 'leagues' => $leagues]);
