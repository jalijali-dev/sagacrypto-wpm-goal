<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

/**
 * "Tambah Cabang Olahraga Baru" request form handler — logs a request
 * for the owner to review manually. Does NOT provision anything: every
 * new sport needs its own API client + schema (see FormulaOneSync.php /
 * BasketballSync.php as examples of the actual dev work involved), so
 * this is intentionally just a request queue, not a wizard.
 */

cms_require_role(['superadmin', 'admin']);

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

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sportName = trim((string) ($_POST['sport_name'] ?? ''));
    $notes = trim((string) ($_POST['notes'] ?? ''));

    if ($sportName !== '') {
        $stmt = $pdo->prepare(
            'INSERT INTO sport_requests (sport_name, notes, requested_by) VALUES (:sport_name, :notes, :requested_by)'
        );
        $stmt->execute([
            'sport_name' => $sportName,
            'notes' => $notes !== '' ? $notes : null,
            'requested_by' => (string) ($_SESSION['cms_admin_name'] ?? ''),
        ]);
        $_SESSION['cms_flash'] = ['type' => 'success', 'message' => "Permintaan cabang olahraga \"{$sportName}\" tercatat — akan direview manual, bukan otomatis aktif."];
    }
}

header('Location: livescore-api-settings.php', true, 302);
exit;
