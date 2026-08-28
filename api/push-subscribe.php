<?php
declare(strict_types=1);

/**
 * Public endpoint: register/refresh one FCM push token (27 Agu 2026).
 *
 * Called from includes/site-footer.php's "Aktifkan Notifikasi" flow
 * after a visitor approves the browser notification permission prompt
 * and Firebase's getToken() returns a token. Anonymous by design — this
 * site has no public user accounts, so a subscription is just "this
 * browser/device install wants notifications", identified only by its
 * FCM token (see push_subscribers table, cms-admin/includes/
 * PushNotificationHelper.php).
 *
 * No CSRF token (there is no admin session here, same reasoning as the
 * public Kontak form in page.php not using one either) — this is a
 * same-origin fetch() call from the site's own footer script, not an
 * admin action. INSERT ... ON DUPLICATE KEY UPDATE on the UNIQUE
 * fcm_token column makes re-registering the same token (token refresh,
 * page reload) idempotent rather than erroring.
 *
 * Self-contained via config/database.php directly, same pattern as
 * includes/site-bootstrap.php — never depends on cms-admin/includes/
 * auth.php, so the public site keeps working independently of admin
 * login state.
 *
 * Request:  POST, fcm_token (form-encoded or JSON body)
 * Response: JSON — {success:true} or {success:false, error}
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/../cms-admin/includes/PushNotificationHelper.php';

header('Content-Type: application/json; charset=utf-8');

/** @return never */
function cms_push_subscribe_respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cms_push_subscribe_respond(['success' => false, 'error' => 'Method not allowed.'], 405);
}

try {
    cms_push_ensure_schema($pdo);
} catch (Throwable $e) {
    cms_push_subscribe_respond(['success' => false, 'error' => 'Schema not ready.'], 500);
}

$token = trim((string) ($_POST['fcm_token'] ?? ''));
if ($token === '') {
    // Fall back to a JSON body — fetch() from the frontend can send
    // either form-encoded or application/json depending on how the
    // caller built the request.
    $raw = file_get_contents('php://input');
    $decoded = $raw !== false && $raw !== '' ? json_decode($raw, true) : null;
    if (is_array($decoded)) {
        $token = trim((string) ($decoded['fcm_token'] ?? ''));
    }
}

if ($token === '' || strlen($token) > 512) {
    cms_push_subscribe_respond(['success' => false, 'error' => 'Invalid or missing fcm_token.']);
}

// Raw User-Agent (27 Agu 2026) — parsed into a readable "Chrome di
// Android"-style label only at display time (cms_push_parse_user_agent(),
// PushNotificationHelper.php), not here; this just captures whatever the
// browser sent as-is. No length validation beyond the column width —
// truncating a malformed/oversized UA string here is harmless (it only
// ever feeds the admin-facing label parser, never a security decision).
$userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 512);

try {
    $stmt = $pdo->prepare(
        'INSERT INTO push_subscribers (fcm_token, user_agent, created_at, last_seen_at, is_active)
         VALUES (:token, :user_agent, NOW(), NOW(), 1)
         ON DUPLICATE KEY UPDATE user_agent = :user_agent_update, last_seen_at = NOW(), is_active = 1'
    );
    $stmt->execute(['token' => $token, 'user_agent' => $userAgent, 'user_agent_update' => $userAgent]);
} catch (Throwable $e) {
    cms_push_subscribe_respond(['success' => false, 'error' => 'Could not save subscription.'], 500);
}

cms_push_subscribe_respond(['success' => true]);
