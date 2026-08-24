<?php
declare(strict_types=1);

/**
 * AJAX endpoint for the TinyMCE "Select from Media Library" picker's
 * drag & drop / click-to-upload UI (cms-admin/includes/tinymce-media-picker.php,
 * 23 Aug 2026) — lets an editor upload a new image without leaving the
 * modal to go to the separate Media Library page first.
 *
 * Reuses cms_handle_media_upload() (cms-admin/config/app.php) — the exact
 * same validation/save logic cms-admin/pages/media-library.php's own
 * upload form uses (extension allowlist, finfo MIME sniff, 5 MB image /
 * 10 MB PDF limit, uploads/media/YYYY/MM/ layout + guard files, safe
 * random filename). This endpoint only adds the media_library INSERT and
 * JSON response shape the picker's JS needs.
 *
 * Auth: require auth.php below does the same session-gate + CSRF check
 * (cms_verify_csrf()) every other cms-admin/api/*.php endpoint in this
 * project uses — an unauthenticated request gets auth.php's normal
 * login-redirect, never reaches this file's own logic.
 *
 * Request:  POST multipart/form-data — media_file, csrf_token
 * Response: JSON —
 *   success: {success:true, id, file_path, file_name, thumb_url, width, height}
 *   failure: {success:false, error}
 */

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

header('Content-Type: application/json; charset=utf-8');

/** @return never */
function cms_media_upload_respond(array $payload, int $status = 200)
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    cms_media_upload_respond(['success' => false, 'error' => 'Method not allowed.'], 405);
}

// Same idempotent self-heal media-library.php runs on every load — this
// endpoint can be hit before anyone has ever opened that page (e.g. a
// brand-new install where the very first image is uploaded from inside
// the TinyMCE picker), so it needs the same guarantee independently.
try {
    cms_ensure_column($pdo, 'media_library', 'mime_type', 'VARCHAR(100) DEFAULT NULL AFTER `file_type`');
    cms_ensure_column($pdo, 'media_library', 'file_size_kb', 'INT(10) UNSIGNED DEFAULT NULL AFTER `mime_type`');
    cms_ensure_column($pdo, 'media_library', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `file_size_kb`');
    cms_ensure_column($pdo, 'media_library', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
} catch (Throwable $e) {
    cms_media_upload_respond(['success' => false, 'error' => 'Media Library schema is not ready: ' . $e->getMessage()], 500);
}

if (!isset($_FILES['media_file']) || !is_array($_FILES['media_file'])) {
    cms_media_upload_respond(['success' => false, 'error' => 'No file was uploaded.']);
}

$uploadResult = cms_handle_media_upload($_FILES['media_file']);
if (!$uploadResult['ok']) {
    cms_media_upload_respond(['success' => false, 'error' => $uploadResult['error']]);
}

try {
    $insert = $pdo->prepare(
        'INSERT INTO media_library (
            file_name, file_path, file_type, mime_type, file_size_kb,
            alt_text, caption, is_active, created_at, updated_at
        ) VALUES (
            :file_name, :file_path, :file_type, :mime_type, :file_size_kb,
            :alt_text, :caption, 1, NOW(), NOW()
        )'
    );
    $insert->execute([
        'file_name' => $uploadResult['file_name'],
        'file_path' => $uploadResult['file_path'],
        'file_type' => $uploadResult['file_type'],
        'mime_type' => $uploadResult['mime_type'],
        'file_size_kb' => $uploadResult['file_size_kb'],
        'alt_text' => '',
        'caption' => '',
    ]);
    $newId = (int) $pdo->lastInsertId();
} catch (Throwable $e) {
    // The file already saved fine to disk at this point (cms_handle_media_
    // upload() succeeded) — it just isn't catalogued. Same "never lose the
    // file over a bookkeeping failure" reasoning used elsewhere in this
    // project (see cms_growth_agent_save_generated_image()'s own
    // media_library insert). Report it as a failure to the picker (it has
    // no id/path to select without a catalogue row), but the uploaded
    // bytes are not orphaned — an admin can still find/attach them via
    // Media Library's own "manual file_path" field.
    cms_media_upload_respond(['success' => false, 'error' => 'File saved but could not be catalogued: ' . $e->getMessage()]);
}

// Real dimensions for the picker's data-width/data-height attributes —
// same getimagesize()-on-disk approach tinymce-media-picker.php's own PHP
// render already uses for existing items, so a freshly-uploaded item
// behaves identically to a page-load-rendered one.
$width = 0;
$height = 0;
if ($uploadResult['file_type'] === 'image') {
    $diskPath = app_safe_media_disk_path($uploadResult['file_path'], CMS_PROJECT_ROOT);
    if ($diskPath !== null && is_file($diskPath)) {
        $dim = @getimagesize($diskPath);
        if (is_array($dim) && $dim[0] > 0 && $dim[1] > 0) {
            $width = (int) $dim[0];
            $height = (int) $dim[1];
        }
    }
}

cms_media_upload_respond([
    'success' => true,
    'id' => $newId,
    'file_path' => $uploadResult['file_path'],
    'file_name' => $uploadResult['file_name'],
    'thumb_url' => app_asset_preview_url($uploadResult['file_path']),
    'width' => $width,
    'height' => $height,
]);
