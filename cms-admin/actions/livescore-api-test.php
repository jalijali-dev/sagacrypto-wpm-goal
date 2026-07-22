<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__, 2) . '/includes/LivescoreSettings.php';
require_once dirname(__DIR__, 2) . '/includes/ApiFootballClient.php';

/**
 * Test-connection action for cms-admin/pages/livescore-api-settings.php —
 * called via fetch() (see the theme switcher in assets/js/admin.js for the
 * same fetch/CSRF convention). Accepts the form's *current* (possibly
 * unsaved) base_url/api_key/api_key_header so an admin can test before
 * saving; falls back to whatever is already stored otherwise. Always
 * persists the result to last_test_status/message/at regardless of which
 * key was actually tested.
 */

cms_require_role(['superadmin']);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$saved = LivescoreSettings::load($pdo);

$baseUrl = trim((string) ($_POST['base_url'] ?? '')) ?: $saved['base_url'];
$apiKeyHeader = trim((string) ($_POST['api_key_header'] ?? '')) ?: $saved['api_key_header'];
// A blank api_key field means "keep using the already-saved key" — the
// settings page never re-displays the decrypted key in the input value,
// so an empty submit here is the normal case, not a request to test with
// no key at all.
$apiKeyInput = trim((string) ($_POST['api_key'] ?? ''));
$apiKey = $apiKeyInput !== '' ? $apiKeyInput : $saved['api_key'];

$client = new ApiFootballClient($baseUrl, $apiKey, $apiKeyHeader);
$result = $client->status();

if ($result['ok']) {
    $status = 'success';
    $resp = $result['data']['response'] ?? [];
    $account = $resp['account']['firstname'] ?? null;
    $requests = $resp['requests'] ?? [];
    $used = $requests['current'] ?? '?';
    $limit = $requests['limit_day'] ?? '?';
    $message = "Koneksi berhasil. Kuota hari ini: {$used}/{$limit} request" . ($account ? " (akun: {$account})" : '') . '.';
} else {
    $status = 'failed';
    $message = $result['error'] !== '' ? $result['error'] : 'Koneksi gagal, tidak ada detail error dari API.';
}

$update = $pdo->prepare(
    'UPDATE livescore_api_settings
     SET last_test_status = :status, last_test_message = :message, last_test_at = NOW()
     WHERE id = 1'
);
$update->execute(['status' => $status, 'message' => $message]);

echo json_encode([
    'ok' => $result['ok'],
    'status' => $status,
    'message' => $message,
    'tested_at' => date('d M Y, H:i'),
]);
