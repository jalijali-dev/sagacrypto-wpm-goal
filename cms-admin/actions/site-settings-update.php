<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/upload.php';
require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

// Same tier as pages/site-settings.php — admin-tier.
cms_require_role(['superadmin', 'admin']);

// See pages/site-settings.php for why these 3 columns/fields exist and why
// telegram_username is VARCHAR(255) (stores a full URL, not a bare username).
cms_ensure_column($pdo, 'site_settings', 'telegram_username', 'VARCHAR(255) NULL AFTER whatsapp_number');
cms_widen_column($pdo, 'site_settings', 'telegram_username', 'VARCHAR(255) NULL AFTER whatsapp_number');
cms_ensure_column($pdo, 'site_settings', 'show_whatsapp_button', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER telegram_username');
cms_ensure_column($pdo, 'site_settings', 'show_telegram_button', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER show_whatsapp_button');

// Cloudflare Turnstile anti-spam for the Kontak form — see pages/site-settings.php.
cms_ensure_column($pdo, 'site_settings', 'turnstile_site_key', 'VARCHAR(255) NULL AFTER show_telegram_button');
cms_ensure_column($pdo, 'site_settings', 'turnstile_secret_key', 'VARCHAR(255) NULL AFTER turnstile_site_key');

// Push Notification (Firebase Cloud Messaging) — see pages/site-settings.php.
require_once __DIR__ . '/../includes/PushNotificationHelper.php';
require_once __DIR__ . '/../includes/ai-helpers.php';
cms_push_ensure_schema($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: ../pages/site-settings.php', true, 302);
    exit;
}

$projectRoot = CMS_PROJECT_ROOT;
$redirect = '../pages/site-settings.php';

$existingSettings = null;

try {
    $settingsRow = $pdo->query(
        'SELECT id, logo_path, favicon_path, og_image, fcm_service_account_json FROM site_settings LIMIT 1'
    )->fetch();
    $existingSettings = is_array($settingsRow) ? $settingsRow : null;
} catch (PDOException) {
    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => 'Could not load current site settings. Please try again.',
    ];
    header('Location: ' . $redirect, true, 302);
    exit;
}

// telegram_username stores a full URL now (see pages/site-settings.php) —
// accept the link with or without a leading "https://" and auto-prefix it
// here so the stored value is always an absolute, clickable URL by the
// time wpm_floating_contact_buttons() renders it verbatim (no more
// "https://t.me/" + username assembly on the consuming side).
$telegramUrl = ltrim(trim((string) ($_POST['telegram_username'] ?? '')), '@');
if ($telegramUrl !== '' && !preg_match('#^https?://#i', $telegramUrl)) {
    $telegramUrl = 'https://' . $telegramUrl;
}
// Soft, non-blocking format check: no strict regex, just flag anything
// that doesn't even resemble a t.me/telegram.me link (e.g. a typo) so the
// admin notices — save proceeds either way (see the flash message below).
$telegramLooksValid = $telegramUrl === '' || (bool) preg_match('#^https?://([^/]*\.)?(t\.me|telegram\.me)/#i', $telegramUrl);

// Push Notification (FCM) — service account JSON is stored encrypted and
// never round-tripped back into the form (see pages/site-settings.php's
// "kosongkan buat pertahankan" hint), so: blank textarea = keep whatever
// is already in the DB; non-blank = validate it's real JSON with the two
// fields we actually need, then re-encrypt and replace.
$fcmServiceAccountRaw = trim((string) ($_POST['fcm_service_account_json'] ?? ''));
$fcmServiceAccountEncrypted = (string) ($existingSettings['fcm_service_account_json'] ?? '');
$fcmServiceAccountError = null;
if ($fcmServiceAccountRaw !== '') {
    $fcmDecoded = json_decode($fcmServiceAccountRaw, true);
    if (!is_array($fcmDecoded) || empty($fcmDecoded['client_email']) || empty($fcmDecoded['private_key'])) {
        $fcmServiceAccountError = 'Service account JSON tidak valid — pastikan ini file JSON asli dari Firebase (harus ada client_email dan private_key).';
    } else {
        $fcmServiceAccountEncrypted = cms_ai_encrypt($fcmServiceAccountRaw);
    }
}

$fcmWebAppConfigRaw = trim((string) ($_POST['fcm_web_app_config_json'] ?? ''));
$fcmWebAppConfigDecoded = null;
if ($fcmWebAppConfigRaw !== '') {
    $fcmWebAppConfigDecoded = json_decode($fcmWebAppConfigRaw, true);
    if (!is_array($fcmWebAppConfigDecoded)) {
        $fcmServiceAccountError = ($fcmServiceAccountError !== null ? $fcmServiceAccountError . ' ' : '')
            . 'Firebase Web App config bukan JSON yang valid.';
    }
}

$payload = [
    'site_name' => trim((string) ($_POST['site_name'] ?? '')),
    'site_tagline' => trim((string) ($_POST['site_tagline'] ?? '')),
    'logo_path' => trim((string) ($existingSettings['logo_path'] ?? '')),
    'favicon_path' => trim((string) ($existingSettings['favicon_path'] ?? '')),
    'og_image' => trim((string) ($existingSettings['og_image'] ?? '')),
    'whatsapp_number' => trim((string) ($_POST['whatsapp_number'] ?? '')),
    'telegram_username' => $telegramUrl,
    'show_whatsapp_button' => !empty($_POST['show_whatsapp_button']) ? 1 : 0,
    'show_telegram_button' => !empty($_POST['show_telegram_button']) ? 1 : 0,
    'instagram_url' => trim((string) ($_POST['instagram_url'] ?? '')),
    'email' => trim((string) ($_POST['email'] ?? '')),
    'address' => trim((string) ($_POST['address'] ?? '')),
    'meta_title' => trim((string) ($_POST['meta_title'] ?? '')),
    'meta_description' => trim((string) ($_POST['meta_description'] ?? '')),
    'meta_keywords' => trim((string) ($_POST['meta_keywords'] ?? '')),
    'google_analytics_id' => trim((string) ($_POST['google_analytics_id'] ?? '')),
    'turnstile_site_key' => trim((string) ($_POST['turnstile_site_key'] ?? '')),
    'turnstile_secret_key' => trim((string) ($_POST['turnstile_secret_key'] ?? '')),
    'push_notification_enabled' => !empty($_POST['push_notification_enabled']) ? 1 : 0,
    'fcm_vapid_public_key' => trim((string) ($_POST['fcm_vapid_public_key'] ?? '')),
    'fcm_project_id' => trim((string) ($_POST['fcm_project_id'] ?? '')),
    'fcm_web_app_config_json' => $fcmWebAppConfigRaw,
    'fcm_service_account_json' => $fcmServiceAccountEncrypted,
];

if ($fcmServiceAccountError !== null) {
    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => $fcmServiceAccountError];
    header('Location: ' . $redirect, true, 302);
    exit;
}

$specs = [
    'logo_file' => [
        'path_field' => 'logo_path',
        'label'      => 'Logo',
        'disk_dir'   => 'uploads/site/logo',
        'web_prefix' => '/uploads/site/logo/',
        'max_bytes'  => 5 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'svg', 'webp'],
        'mimes'      => ['image/jpeg', 'image/png', 'image/svg+xml', 'image/webp'],
    ],
    'favicon_file' => [
        'path_field'  => 'favicon_path',
        'label'       => 'Favicon',
        'disk_dir'    => 'uploads/site/favicon',
        'web_prefix'  => '/uploads/site/favicon/',
        'max_bytes'   => 1024 * 1024,
        'extensions'  => ['ico', 'png'],
        'mimes'       => ['image/png', 'image/x-icon', 'image/vnd.microsoft.icon'],
        'extra_mimes' => ['application/octet-stream'], // some finfo builds report .ico as this
    ],
    'og_image_file' => [
        'path_field' => 'og_image',
        'label'      => 'OG image',
        'disk_dir'   => 'uploads/site/seo',
        'web_prefix' => '/uploads/site/seo/',
        'max_bytes'  => 5 * 1024 * 1024,
        'extensions' => ['jpg', 'jpeg', 'png', 'webp'],
        'mimes'      => ['image/jpeg', 'image/png', 'image/webp'],
    ],
];

$currentPaths = [
    'logo_path'    => $payload['logo_path'],
    'favicon_path' => $payload['favicon_path'],
    'og_image'     => $payload['og_image'],
];

$uploadResult = cms_process_file_uploads($specs, $currentPaths, $projectRoot);

// Map result back onto the variable names used by the unchanged code below.
$uploadErrors                = $uploadResult['errors'];
$newlyUploadedDiskPaths      = $uploadResult['new_files'];
$pathsToDeleteAfterDbSuccess = $uploadResult['delete_after'];

// Push updated image paths back into the DB payload.
foreach (['logo_path', 'favicon_path', 'og_image'] as $field) {
    $payload[$field] = $uploadResult['paths'][$field];
}

if ($uploadErrors !== []) {
    foreach ($newlyUploadedDiskPaths as $uploadedPath) {
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }

    $_SESSION['cms_flash'] = [
        'type' => 'error',
        'message' => implode(' ', $uploadErrors),
    ];
    header('Location: ' . $redirect, true, 302);
    exit;
}

try {
    if ($existingSettings !== null) {
        $update = $pdo->prepare(
            'UPDATE site_settings
             SET site_name = :site_name,
                 site_tagline = :site_tagline,
                 logo_path = :logo_path,
                 favicon_path = :favicon_path,
                 og_image = :og_image,
                 whatsapp_number = :whatsapp_number,
                 telegram_username = :telegram_username,
                 show_whatsapp_button = :show_whatsapp_button,
                 show_telegram_button = :show_telegram_button,
                 instagram_url = :instagram_url,
                 email = :email,
                 address = :address,
                 meta_title = :meta_title,
                 meta_description = :meta_description,
                 meta_keywords = :meta_keywords,
                 google_analytics_id = :google_analytics_id,
                 turnstile_site_key = :turnstile_site_key,
                 turnstile_secret_key = :turnstile_secret_key,
                 push_notification_enabled = :push_notification_enabled,
                 fcm_vapid_public_key = :fcm_vapid_public_key,
                 fcm_project_id = :fcm_project_id,
                 fcm_web_app_config_json = :fcm_web_app_config_json,
                 fcm_service_account_json = :fcm_service_account_json,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $existingSettings['id']]);
    } else {
        $insert = $pdo->prepare(
            'INSERT INTO site_settings (
                site_name, site_tagline, logo_path, favicon_path, og_image, whatsapp_number,
                telegram_username, show_whatsapp_button, show_telegram_button, instagram_url,
                email, address, meta_title, meta_description, meta_keywords, google_analytics_id,
                turnstile_site_key, turnstile_secret_key,
                push_notification_enabled, fcm_vapid_public_key, fcm_project_id,
                fcm_web_app_config_json, fcm_service_account_json,
                created_at, updated_at
            ) VALUES (
                :site_name, :site_tagline, :logo_path, :favicon_path, :og_image, :whatsapp_number,
                :telegram_username, :show_whatsapp_button, :show_telegram_button, :instagram_url,
                :email, :address, :meta_title, :meta_description, :meta_keywords, :google_analytics_id,
                :turnstile_site_key, :turnstile_secret_key,
                :push_notification_enabled, :fcm_vapid_public_key, :fcm_project_id,
                :fcm_web_app_config_json, :fcm_service_account_json,
                NOW(), NOW()
            )'
        );
        $insert->execute($payload);
    }

    foreach (array_unique($pathsToDeleteAfterDbSuccess) as $oldPath) {
        if (is_file($oldPath)) {
            @unlink($oldPath);
        }
    }

    // Regenerate the FCM_WEB_CONFIG block in the site-root sw.js so the
    // service worker's Firebase Messaging companion (see sw.js's own
    // docblock) picks up whatever was just saved — best-effort, never
    // blocks the settings save itself (sw.js might not have the markers
    // yet on an older production deploy).
    cms_push_regenerate_sw_js_config($fcmWebAppConfigDecoded);

    $_SESSION['cms_flash'] = $telegramLooksValid
        ? ['type' => 'success', 'message' => 'Site settings saved successfully.']
        : [
            'type' => 'info',
            'message' => 'Site settings saved. Catatan: link Telegram ("' . $telegramUrl . '") tidak terlihat seperti format t.me/telegram.me — dicek lagi kalau ini bukan yang dimaksud.',
        ];
} catch (PDOException) {
    foreach ($newlyUploadedDiskPaths as $uploadedPath) {
        if (is_file($uploadedPath)) {
            @unlink($uploadedPath);
        }
    }

    $_SESSION['cms_flash'] = ['type' => 'error', 'message' => 'Could not save site settings. Please try again.'];
}

header('Location: ' . $redirect, true, 302);
exit;
