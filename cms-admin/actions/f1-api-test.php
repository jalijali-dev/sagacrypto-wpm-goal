<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/ai-helpers.php';
require_once dirname(__DIR__, 2) . '/includes/FormulaOneSettings.php';
require_once dirname(__DIR__, 2) . '/includes/ApiFormula1Client.php';

/**
 * Test-connection action for the Formula 1 accordion section in
 * cms-admin/pages/livescore-api-settings.php (consolidated hub) — mirrors
 * cms-admin/actions/livescore-api-test.php / basketball-api-test.php.
 */

cms_require_role(['superadmin']);

header('Content-Type: application/json');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$saved = FormulaOneSettings::load($pdo);

$baseUrl = trim((string) ($_POST['base_url'] ?? '')) ?: $saved['base_url'];
$apiKeyHeader = trim((string) ($_POST['api_key_header'] ?? '')) ?: $saved['api_key_header'];
$apiKeyInput = trim((string) ($_POST['api_key'] ?? ''));
$apiKey = $apiKeyInput !== '' ? $apiKeyInput : $saved['api_key'];

$client = new ApiFormula1Client($baseUrl, $apiKey, $apiKeyHeader);
$result = $client->status();

if ($result['ok']) {
    $status = 'success';
    $resp = $result['data']['response'] ?? [];
    $requests = $resp['requests'] ?? [];
    $used = $requests['current'] ?? '?';
    $limit = $requests['limit_day'] ?? '?';
    $message = "Koneksi berhasil. Kuota hari ini: {$used}/{$limit} request.";
} else {
    $status = 'failed';
    $message = $result['error'] !== '' ? $result['error'] : 'Koneksi gagal, tidak ada detail error dari API.';
}

$update = $pdo->prepare(
    'UPDATE f1_api_settings
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
