<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';
require_once dirname(__DIR__) . '/includes/PushNotificationHelper.php';

// Same tier as pages/site-settings.php.
cms_require_role(['superadmin', 'admin']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/site-settings.php', true, 302);
    exit;
}

$result = cms_push_send_test_notification($pdo);

if (!$result['ok']) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Test notification gagal: ' . $result['error']];
} elseif ($result['sent'] === 0) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Test notification gagal terkirim ke semua ' . $result['failed'] . ' subscriber. Cek lagi kredensial Firebase.'];
} else {
    $_SESSION['cms_flash'] = [
        'type' => $result['failed'] > 0 ? 'info' : 'success',
        'message' => 'Test notification terkirim ke ' . $result['sent'] . ' subscriber'
            . ($result['failed'] > 0 ? ', gagal ke ' . $result['failed'] . ' (token tidak valid otomatis dinonaktifkan).' : '.'),
    ];
}

header('Location: ../pages/site-settings.php', true, 302);
exit;
