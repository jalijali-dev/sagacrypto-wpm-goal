<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

$pageTitle = 'Media Library';
$currentNav = 'media-library';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Media Library', 'href' => ''],
];

$selfUrl = 'media-library.php';

/**
 * Auto-migration: idempotent column self-heal, safe to run on every load.
 */
$mediaSchemaError = null;
try {
    cms_ensure_column($pdo, 'media_library', 'mime_type', 'VARCHAR(100) DEFAULT NULL AFTER `file_type`');
    cms_ensure_column($pdo, 'media_library', 'file_size_kb', 'INT(10) UNSIGNED DEFAULT NULL AFTER `mime_type`');
    cms_ensure_column($pdo, 'media_library', 'is_active', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `file_size_kb`');
    cms_ensure_column($pdo, 'media_library', 'updated_at', 'TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER `created_at`');
} catch (Throwable $e) {
    $mediaSchemaError = $e->getMessage();
}

$ml_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$ml_validate = static function (string $fileName, string $filePath, string $fileType, string $fileSizeRaw): ?string {
    if ($fileName === '') {
        return 'File name is required.';
    }
    if ($filePath === '') {
        return 'File path is required.';
    }
    if ($fileType === '') {
        return 'File type is required.';
    }
    if ($fileSizeRaw !== '' && !is_numeric($fileSizeRaw)) {
        return 'File size must be a number.';
    }

    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $ml_redirect('Invalid media file.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM media_library WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $ml_redirect('Media file not found or already deleted.', 'error');
        }
        $ml_redirect('Media file deleted successfully.');
    }

    // -------------------------------------------------------------------------
    // File upload (optional — falls back to manual file_path if omitted)
    // Stored path always uses a leading slash: /uploads/media/YYYY/MM/file.jpg
    // -------------------------------------------------------------------------
    $uploadedRelPath  = '';   // e.g. /uploads/media/2026/05/photo-abc123def456gh78.jpg
    $uploadedFileName = '';
    $uploadedMime     = '';
    $uploadedSizeKb   = 0;
    $uploadedFileType = '';

    $errEditQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
        ? 'edit=' . (int) $_POST['id'] : null;

    // Guard file content — matches every other uploads/* guard exactly.
    $guardContent = "<?php\ndeclare(strict_types=1);\n\nhttp_response_code(403);\nexit('Forbidden');\n";

    if (
        isset($_FILES['media_file']) && is_array($_FILES['media_file'])
        && (int) ($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ) {
        $uploadErr = (int) ($_FILES['media_file']['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($uploadErr !== UPLOAD_ERR_OK) {
            $ml_redirect('File upload failed (error code ' . $uploadErr . ').', 'error', $errEditQuery);
        }

        $tmpName   = (string) ($_FILES['media_file']['tmp_name'] ?? '');
        $origName  = (string) ($_FILES['media_file']['name']     ?? '');
        $fileBytes = (int)    ($_FILES['media_file']['size']      ?? 0);

        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            $ml_redirect('Invalid upload.', 'error', $errEditQuery);
        }
        if ($fileBytes <= 0) {
            $ml_redirect('Uploaded file is empty.', 'error', $errEditQuery);
        }

        // --- Step 1: preliminary extension check (fast, before finfo) ----------
        $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf'];
        $clientExt   = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($clientExt === '' || !in_array($clientExt, $allowedExts, true)) {
            $ml_redirect('Disallowed file extension.', 'error', $errEditQuery);
        }

        // --- Step 2: MIME detection via finfo (reads actual file bytes) ---------
        // The map also provides the canonical saved extension — derived from
        // the detected MIME, never from the client-supplied filename.
        $mimeExtMap = [
            'image/jpeg'      => 'jpg',
            'image/png'       => 'png',
            'image/webp'      => 'webp',
            'image/gif'       => 'gif',
            'application/pdf' => 'pdf',
        ];
        $finfo        = new finfo(FILEINFO_MIME_TYPE);
        $detectedMime = (string) ($finfo->file($tmpName) ?: '');
        if ($detectedMime === '' || !array_key_exists($detectedMime, $mimeExtMap)) {
            $ml_redirect('Disallowed file type (' . $detectedMime . ').', 'error', $errEditQuery);
        }

        // --- Step 3: per-type size limit (5 MB images, 10 MB PDF) ---------------
        $maxBytes = ($detectedMime === 'application/pdf') ? 10 * 1024 * 1024 : 5 * 1024 * 1024;
        if ($fileBytes > $maxBytes) {
            $limitLabel = ($maxBytes === 10 * 1024 * 1024) ? '10 MB' : '5 MB';
            $ml_redirect('File exceeds the ' . $limitLabel . ' limit for this file type.', 'error', $errEditQuery);
        }

        // Canonical extension comes from the MIME map, not the user's filename.
        $ext = $mimeExtMap[$detectedMime];

        // --- Step 4: create upload directory and write guard files if missing ---
        $projectRoot = CMS_PROJECT_ROOT;
        $yr          = date('Y');
        $mo          = date('m');
        $relBase     = 'uploads/media';
        $relYear     = $relBase  . '/' . $yr;
        $relDir      = $relYear  . '/' . $mo;
        $diskDir     = $projectRoot . '/' . $relDir;

        if (!is_dir($diskDir) && !mkdir($diskDir, 0755, true) && !is_dir($diskDir)) {
            $ml_redirect('Upload directory could not be created.', 'error', $errEditQuery);
        }
        // Ensure each directory level has an index.php guard (403 on direct browse).
        foreach ([$relBase, $relYear, $relDir] as $guardLevel) {
            $guardFile = $projectRoot . '/' . $guardLevel . '/index.php';
            if (!file_exists($guardFile)) {
                file_put_contents($guardFile, $guardContent);
                @chmod($guardFile, 0644);
            }
        }

        // --- Step 5: safe filename — lowercase base + 16-char hex suffix --------
        $base = trim(
            (string) (preg_replace('/[^a-z0-9_-]+/', '-', strtolower(pathinfo($origName, PATHINFO_FILENAME))) ?? ''),
            '-'
        );
        if ($base === '') { $base = 'upload'; }

        do {
            $safeFilename = $base . '-' . bin2hex(random_bytes(8)) . '.' . $ext;
            $targetPath   = $diskDir . '/' . $safeFilename;
        } while (file_exists($targetPath));

        // --- Step 6: move to final location -------------------------------------
        if (!move_uploaded_file($tmpName, $targetPath)) {
            $ml_redirect('Could not save the uploaded file.', 'error', $errEditQuery);
        }
        @chmod($targetPath, 0644);

        // Stored path uses leading slash — matches all other upload modules.
        $uploadedRelPath  = '/' . $relDir . '/' . $safeFilename;
        $uploadedFileName = $safeFilename;
        $uploadedMime     = $detectedMime;
        $uploadedSizeKb   = (int) ceil($fileBytes / 1024);
        $uploadedFileType = str_starts_with($detectedMime, 'image/') ? 'image'
                          : ($detectedMime === 'application/pdf' ? 'document' : 'other');
    }
    // -------------------------------------------------------------------------

    $fileName    = trim((string) ($_POST['file_name']    ?? ''));
    $filePath    = trim((string) ($_POST['file_path']    ?? ''));
    $fileType    = trim((string) ($_POST['file_type']    ?? ''));
    $mimeType    = trim((string) ($_POST['mime_type']    ?? ''));
    $fileSizeRaw = trim((string) ($_POST['file_size_kb'] ?? ''));
    $altText     = trim((string) ($_POST['alt_text']     ?? ''));
    $caption     = trim((string) ($_POST['caption']      ?? ''));
    $isActive    = (int) ($_POST['is_active'] ?? 0) === 1 ? 1 : 0;

    // If a file was uploaded, its values take precedence over (possibly empty) form fields.
    if ($uploadedRelPath !== '') {
        $filePath    = $uploadedRelPath;               // always use real saved path
        $mimeType    = $uploadedMime;                  // always use detected MIME
        $fileSizeRaw = (string) $uploadedSizeKb;       // always use actual size
        $fileType    = $uploadedFileType;              // always use derived type
        if ($fileName === '') {
            $fileName = $uploadedFileName;             // fill name only when still empty
        }
    }

    // Normalize file_path: always store with a leading slash.
    // Consistent with /uploads/banners/x.jpg, /uploads/products/x.png, etc.
    // app_asset_preview_url() tolerates both formats via ltrim, but normalising
    // here prevents raw-concatenation bugs in future consumers.
    if ($filePath !== '') {
        $filePath = '/' . ltrim($filePath, '/');
    }

    // H-3: reject manually entered local paths that escape /uploads/ or contain
    // traversal. External http(s):// URLs are out of H-3 scope (see M-2) and are
    // left unchanged. Uploaded files always produce a safe /uploads/ path.
    if (
        $filePath !== ''
        && preg_match('#^https?://#i', $filePath) !== 1
        && !app_is_safe_local_media_path($filePath)
    ) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $ml_redirect('Invalid file path. Local paths must start with /uploads/ and cannot contain "..".', 'error', $errorQuery);
    }

    $validationError = $ml_validate($fileName, $filePath, $fileType, $fileSizeRaw);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $ml_redirect($validationError, 'error', $errorQuery);
    }

    $fileSizeKb = $fileSizeRaw === '' ? null : (int) $fileSizeRaw;

    $payload = [
        'file_name' => $fileName,
        'file_path' => $filePath,
        'file_type' => $fileType,
        'mime_type' => $mimeType,
        'file_size_kb' => $fileSizeKb,
        'alt_text' => $altText,
        'caption' => $caption,
        'is_active' => $isActive,
    ];

    if ($action === 'create') {
        $insert = $pdo->prepare(
            'INSERT INTO media_library (
                file_name, file_path, file_type, mime_type, file_size_kb,
                alt_text, caption, is_active, created_at, updated_at
            ) VALUES (
                :file_name, :file_path, :file_type, :mime_type, :file_size_kb,
                :alt_text, :caption, :is_active, NOW(), NOW()
            )'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $ml_redirect('Media file created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $ml_redirect('Invalid media file.', 'error');
        }
        $update = $pdo->prepare(
            'UPDATE media_library
             SET file_name = :file_name,
                 file_path = :file_path,
                 file_type = :file_type,
                 mime_type = :mime_type,
                 file_size_kb = :file_size_kb,
                 alt_text = :alt_text,
                 caption = :caption,
                 is_active = :is_active,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        $ml_redirect('Media file updated successfully.', 'success', 'edit=' . $updateId);
    }

    $ml_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

if ($mediaSchemaError !== null) {
    $alerts[] = [
        'type' => 'error',
        'raw' => true,
        'message' => 'Media Library belum bisa dipakai sepenuhnya: skema database belum lengkap dan '
            . 'perbaikan otomatis gagal dijalankan (' . cms_esc($mediaSchemaError) . '). '
            . 'Jalankan migration manual: <a href="../migrate-media-library.php">Jalankan Migration Media Library</a>.',
    ];
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;

// "Media files" vs "New/Edit media file" as separate views (24 Aug 2026,
// same pattern as pages.php's ?view=create split) — this page used to
// cram BOTH into one 2-column grid (admin-grid--2) always rendered
// together, which is also why thumbnails stayed tiny (squeezed into half
// the page width). ?view=create now switches to a full-width form-only
// view; editing (?edit=ID) already takes its own view. Neither param
// present -> full-width list-only view (the default).
$showCreateForm = ($_GET['view'] ?? '') === 'create';

// ---- Server-side search + type/status filter + pagination (24 Aug 2026)
// ---- Replaces the old "fetch all rows, hide/show with JS" approach,
// which got slower and slower to scroll as the library grew (132 files
// and counting) since every row rendered up front regardless of filters.
$mlSearchRaw = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
if (mb_strlen($mlSearchRaw, 'UTF-8') > 100) {
    $mlSearchRaw = mb_substr($mlSearchRaw, 0, 100, 'UTF-8');
}
$mlTypeFilter = isset($_GET['type']) ? strtolower(trim((string) $_GET['type'])) : '';
if (!in_array($mlTypeFilter, ['image', 'document', 'video', 'other'], true)) {
    $mlTypeFilter = '';
}
$mlStatusFilter = isset($_GET['status']) ? strtolower(trim((string) $_GET['status'])) : '';
if (!in_array($mlStatusFilter, ['active', 'inactive'], true)) {
    $mlStatusFilter = '';
}

$mlPerPage = 24;
$mlPage    = max(1, (int) ($_GET['page'] ?? 1));

$mlWhere  = [];
$mlParams = [];
if ($mlSearchRaw !== '') {
    $mlEscaped              = str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $mlSearchRaw);
    $mlWhere[]               = '(m.file_name LIKE :search_name OR m.file_path LIKE :search_path)';
    $mlParams['search_name'] = '%' . $mlEscaped . '%';
    $mlParams['search_path'] = '%' . $mlEscaped . '%';
}
if ($mlTypeFilter !== '') {
    if ($mlTypeFilter === 'other') {
        $mlWhere[] = "(m.file_type IS NULL OR m.file_type NOT IN ('image', 'document', 'video'))";
    } else {
        $mlWhere[]              = 'm.file_type = :type_filter';
        $mlParams['type_filter'] = $mlTypeFilter;
    }
}
if ($mlStatusFilter !== '') {
    $mlWhere[]                 = 'm.is_active = :status_filter';
    $mlParams['status_filter'] = $mlStatusFilter === 'active' ? 1 : 0;
}
$mlWhereClause = $mlWhere !== [] ? ' WHERE ' . implode(' AND ', $mlWhere) : '';

$mediaFiles     = [];
$mlTotalRows    = 0;
$mlTotalPages   = 1;
try {
    $mlCountStmt = $pdo->prepare('SELECT COUNT(*) FROM media_library m' . $mlWhereClause);
    $mlCountStmt->execute($mlParams);
    $mlTotalRows  = (int) $mlCountStmt->fetchColumn();
    $mlTotalPages = max(1, (int) ceil($mlTotalRows / $mlPerPage));
    if ($mlPage > $mlTotalPages) {
        $mlPage = $mlTotalPages;
    }
    $mlOffset = ($mlPage - 1) * $mlPerPage;

    $listSql = 'SELECT m.id, m.file_name, m.file_path, m.file_type, m.mime_type,
                       m.file_size_kb, m.is_active, m.created_at
                FROM media_library m'
             . $mlWhereClause
             . ' ORDER BY m.id DESC
                LIMIT :limit OFFSET :offset';
    $listStmt = $pdo->prepare($listSql);
    $listStmt->bindValue(':limit', $mlPerPage, PDO::PARAM_INT);
    $listStmt->bindValue(':offset', $mlOffset, PDO::PARAM_INT);
    foreach ($mlParams as $key => $val_) {
        $listStmt->bindValue(':' . $key, $val_);
    }
    $listStmt->execute();
    $mediaFiles = $listStmt->fetchAll();
} catch (PDOException $e) {
    $mediaFiles = [];
    if ($mediaSchemaError === null) {
        $alerts[] = [
            'type' => 'error',
            'message' => 'Gagal memuat daftar media: ' . $e->getMessage(),
        ];
    }
}

// Preserves search/type/status params across pagination links.
$mlPaginateUrl = static function (int $targetPage) use ($selfUrl, $mlSearchRaw, $mlTypeFilter, $mlStatusFilter): string {
    $q = [];
    if ($mlSearchRaw !== '')   { $q['search'] = $mlSearchRaw; }
    if ($mlTypeFilter !== '')  { $q['type']   = $mlTypeFilter; }
    if ($mlStatusFilter !== ''){ $q['status'] = $mlStatusFilter; }
    $q['page'] = $targetPage;
    return $selfUrl . '?' . http_build_query($q);
};

if ($editId > 0) {
    try {
        $editStmt = $pdo->prepare(
            'SELECT id, file_name, file_path, file_type, mime_type, file_size_kb, alt_text, caption, is_active
             FROM media_library WHERE id = :id LIMIT 1'
        );
        $editStmt->execute(['id' => $editId]);
        $editRow = $editStmt->fetch() ?: null;
    } catch (PDOException $e) {
        $editRow = null;
        $alerts[] = ['type' => 'error', 'message' => 'Could not load record: ' . $e->getMessage()];
    }
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Media file not found.'];
        $editId = 0;
    }
}

$formatDt = static function (?string $value): string {
    if ($value === null || $value === '') {
        return '—';
    }
    $ts = strtotime($value);
    return $ts !== false ? date('d M Y, H:i', $ts) : $value;
};

$val = static fn (array $row, string $key): string => (string) ($row[$key] ?? '');

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<style>
/* ---- path preview (live image from typed path) ---- */
.cms-path-upload__preview{display:block;max-width:100%;max-height:100px;margin:6px 0 0;border-radius:8px;object-fit:contain;border:1px solid var(--line)}
.cms-path-upload__preview[hidden]{display:none!important}
/* ---- list row thumbnail ---- */
.ml-thumb{flex-shrink:0;width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid var(--line)}
.ml-thumb--ph{display:flex;align-items:center;justify-content:center;font-size:28px;background:var(--accent-soft);border:1px solid var(--line-subtle);border-radius:8px;width:72px;height:72px;flex-shrink:0}
/* ---- file name truncation ---- */
.ml-fname{overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:block;max-width:100%}
/* ---- type badges (base in admin.css; module-specific rules below) ---- */
/* ---- filter controls bar ---- */
.ml-controls{display:flex;flex-wrap:wrap;gap:8px;padding:10px 14px 10px;border-bottom:1px solid var(--line-subtle)}
.ml-ctrl-search{flex:1;min-width:120px;padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:var(--input-bg);color:var(--text);font-size:13px;font-family:inherit}
.ml-ctrl-select{padding:7px 10px;border:1px solid var(--line);border-radius:8px;background:var(--input-bg);color:var(--text);font-size:13px;font-family:inherit}
/* ---- table layout (fixed to prevent overflow) ---- */
.ml-table-wrap{overflow-x:auto}
.ml-table{table-layout:fixed;width:100%;min-width:460px}
.ml-col-file   {width:auto}
.ml-col-type   {width:90px}
.ml-col-size   {width:64px}
.ml-col-usage  {width:100px}
.ml-col-status {width:78px}
.ml-col-actions{width:150px}
/* ---- form helper text ---- */
.ml-hint{font-size:11px;color:var(--muted);display:block;margin-top:4px;line-height:1.45}
.ml-hint code{background:var(--accent-soft);padding:1px 5px;border-radius:3px;font-size:11px}
/* ---- pagination (copied from pages.php's .pg-pagination/.pg-page-btn —
   that CSS lives in pages.php's own inline <style>, not admin.css, so it
   isn't available here without duplicating it) ---- */
.pg-pagination{display:flex;flex-wrap:wrap;align-items:center;gap:4px;margin-top:14px}
.pg-page-btn{display:inline-flex;align-items:center;justify-content:center;min-width:34px;height:34px;padding:0 10px;border-radius:8px;border:1px solid var(--line);background:var(--surface-soft);color:var(--text);font-size:13px;font-weight:500;text-decoration:none;cursor:pointer;font-family:inherit;transition:background .12s,border-color .12s}
.pg-page-btn:hover{background:var(--navlink-hover-bg);border-color:var(--navlink-active-border)}
.pg-page-btn--active{background:var(--accent);border-color:var(--accent);color:var(--accent-text);cursor:default}
.pg-page-btn--disabled{color:var(--muted);border-color:var(--line-subtle);background:var(--surface-soft);cursor:default}
.pg-page-ellipsis{padding:0 4px;color:var(--muted);font-size:13px;line-height:34px}
</style>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Media library</h2>
            <p class="section-lead">Central file store — enter file path as text (upload coming soon).</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary"
               href="<?= cms_esc($showCreateForm || $editRow ? $selfUrl : $selfUrl . '?view=create') ?>">
                <?= ($showCreateForm || $editRow) ? 'Back to List' : 'Add Media Path' ?>
            </a>
        </div>
    </div>

    <?php if (!$editRow && !$showCreateForm) : ?>
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Media files</h3>
                <span class="panel__meta"><?= $mlTotalRows ?> file(s)</span>
            </div>

            <!-- Filter / search controls -->
            <form class="ml-controls" method="get" action="">
                <input type="search" name="search" class="ml-ctrl-search"
                       placeholder="Search media…" autocomplete="off"
                       value="<?= cms_esc($mlSearchRaw) ?>">
                <select name="type" class="ml-ctrl-select">
                    <option value="">All types</option>
                    <option value="image"    <?= $mlTypeFilter === 'image'    ? 'selected' : '' ?>>Image</option>
                    <option value="document" <?= $mlTypeFilter === 'document' ? 'selected' : '' ?>>Document</option>
                    <option value="video"    <?= $mlTypeFilter === 'video'    ? 'selected' : '' ?>>Video</option>
                    <option value="other"    <?= $mlTypeFilter === 'other'    ? 'selected' : '' ?>>Other</option>
                </select>
                <select name="status" class="ml-ctrl-select">
                    <option value="">All statuses</option>
                    <option value="active"   <?= $mlStatusFilter === 'active'   ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $mlStatusFilter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
                <button type="submit" class="admin-btn admin-btn--secondary">Filter</button>
                <?php if ($mlSearchRaw !== '' || $mlTypeFilter !== '' || $mlStatusFilter !== '') : ?>
                    <a href="<?= cms_esc($selfUrl) ?>" class="admin-btn admin-btn--secondary">Reset</a>
                <?php endif; ?>
            </form>

            <div class="table-wrap ml-table-wrap">
                <table class="admin-table ml-table">
                    <colgroup>
                        <col class="ml-col-file">
                        <col class="ml-col-type">
                        <col class="ml-col-size">
                        <col class="ml-col-usage">
                        <col class="ml-col-status">
                        <col class="ml-col-actions">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>File</th>
                            <th>Type</th>
                            <th>Size</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="ml-tbody">
                        <?php if ($mediaFiles === []) : ?>
                            <tr><td colspan="5" class="muted">
                                <?= ($mlSearchRaw !== '' || $mlTypeFilter !== '' || $mlStatusFilter !== '')
                                    ? 'No files match your search/filter.'
                                    : 'No media files yet.' ?>
                            </td></tr>
                        <?php endif; ?>
                        <?php foreach ($mediaFiles as $row) : ?>
                            <?php
                            $rowId     = (int) $row['id'];
                            $rowType   = strtolower($val($row, 'file_type'));
                            $rowMime   = strtolower($val($row, 'mime_type'));
                            $rowFPath  = $val($row, 'file_path');
                            $isImg     = $rowType === 'image' || str_starts_with($rowMime, 'image/');
                            $thumbSrc  = ($isImg && $rowFPath !== '') ? app_asset_preview_url($rowFPath) : '';
                            $rowStatus = (int) ($row['is_active'] ?? 0) === 1 ? 'active' : 'inactive';
                            $badgeKey  = in_array($rowType, ['image', 'document', 'video'], true) ? $rowType : 'other';
                            ?>
                            <tr data-name="<?= cms_esc(strtolower($val($row, 'file_name'))) ?>"
                                data-path="<?= cms_esc(strtolower($rowFPath)) ?>"
                                data-type="<?= cms_esc($rowType) ?>"
                                data-status="<?= $rowStatus ?>">
                                <td>
                                    <div style="display:flex;align-items:center;gap:8px;min-width:0;overflow:hidden">
                                        <?php if ($thumbSrc !== '') : ?>
                                            <img class="ml-thumb"
                                                 src="<?= cms_esc($thumbSrc) ?>"
                                                 alt=""
                                                 loading="lazy"
                                                 onerror="this.hidden=true">
                                        <?php else : ?>
                                            <div class="ml-thumb ml-thumb--ph" aria-hidden="true">📄</div>
                                        <?php endif; ?>
                                        <span class="ml-fname"
                                              title="<?= cms_esc($val($row, 'file_name')) ?>">
                                            <?= cms_esc($val($row, 'file_name')) ?>
                                        </span>
                                    </div>
                                </td>
                                <td>
                                    <span class="ml-type-badge ml-type-badge--<?= $badgeKey ?>">
                                        <?= cms_esc($rowType !== '' ? $rowType : 'other') ?>
                                    </span>
                                </td>
                                <td><?= $row['file_size_kb'] !== null && $row['file_size_kb'] !== '' ? cms_esc((string) $row['file_size_kb']) . ' KB' : '—' ?></td>
                                <td>
                                    <span class="pill pill--<?= $rowStatus === 'active' ? 'ok' : 'muted' ?>">
                                        <?= $rowStatus === 'active' ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <button type="button"
                                            class="admin-btn admin-btn--sm admin-btn--ghost ml-copy-btn"
                                            data-path="<?= cms_esc($rowFPath) ?>"
                                            title="Copy path to clipboard">Copy</button>
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary"
                                       href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>"
                                          onsubmit="return confirm('Delete this media file?');">
                                        <?= cms_csrf_field() ?>
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $rowId ?>">
                                        <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($mlTotalPages > 1) : ?>
            <nav class="pg-pagination" aria-label="Navigasi halaman media" style="padding:14px;">
                <?php if ($mlPage > 1) : ?>
                    <a class="pg-page-btn" href="<?= cms_esc($mlPaginateUrl($mlPage - 1)) ?>">« Prev</a>
                <?php else : ?>
                    <span class="pg-page-btn pg-page-btn--disabled">« Prev</span>
                <?php endif; ?>
                <?php
                $mlWin = 2;
                $mlMin = max(1, $mlPage - $mlWin);
                $mlMax = min($mlTotalPages, $mlPage + $mlWin);
                if ($mlMin === 1) { $mlMax = min($mlTotalPages, 1 + $mlWin * 2); }
                if ($mlMax === $mlTotalPages) { $mlMin = max(1, $mlTotalPages - $mlWin * 2); }
                if ($mlMin > 1) : ?>
                    <a class="pg-page-btn" href="<?= cms_esc($mlPaginateUrl(1)) ?>">1</a>
                    <?php if ($mlMin > 2) : ?><span class="pg-page-ellipsis">…</span><?php endif; ?>
                <?php endif; ?>
                <?php for ($mlI = $mlMin; $mlI <= $mlMax; $mlI++) : ?>
                    <?php if ($mlI === $mlPage) : ?>
                        <span class="pg-page-btn pg-page-btn--active"><?= $mlI ?></span>
                    <?php else : ?>
                        <a class="pg-page-btn" href="<?= cms_esc($mlPaginateUrl($mlI)) ?>"><?= $mlI ?></a>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ($mlMax < $mlTotalPages) : ?>
                    <?php if ($mlMax < $mlTotalPages - 1) : ?><span class="pg-page-ellipsis">…</span><?php endif; ?>
                    <a class="pg-page-btn" href="<?= cms_esc($mlPaginateUrl($mlTotalPages)) ?>"><?= $mlTotalPages ?></a>
                <?php endif; ?>
                <?php if ($mlPage < $mlTotalPages) : ?>
                    <a class="pg-page-btn" href="<?= cms_esc($mlPaginateUrl($mlPage + 1)) ?>">Next »</a>
                <?php else : ?>
                    <span class="pg-page-btn pg-page-btn--disabled">Next »</span>
                <?php endif; ?>
            </nav>
            <?php endif; ?>
        </div>
    <?php endif; // !$editRow && !$showCreateForm ?>

    <?php if ($editRow || $showCreateForm) : ?>
        <div class="panel" id="media-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit media file' : 'New media file' ?></h3>
                <?php if ($editRow) : ?>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>"
                  enctype="multipart/form-data">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow) : ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>
                <?php $editFileType = $editRow ? $val($editRow, 'file_type') : ''; ?>

                <label class="field">Upload file
                    <input type="file"
                           name="media_file"
                           id="ml-upload-file"
                           accept=".jpg,.jpeg,.png,.webp,.gif,.pdf">
                    <small class="ml-hint">
                        Allowed: JPG, PNG, WebP, GIF, PDF · Max 5 MB.
                        Uploading auto-fills the fields below.
                        <?php if ($editRow && $val($editRow, 'file_path') !== '') : ?>
                            Leave empty to keep the current file.
                        <?php endif; ?>
                    </small>
                </label>

                <label class="field">File path
                    <input type="text"
                           name="file_path"
                           id="ml-file-path"
                           class="cms-path-upload__input"
                           value="<?= cms_esc($editRow ? $val($editRow, 'file_path') : '') ?>"
                           required
                           placeholder="/uploads/media/YYYY/MM/file.webp"
                           autocomplete="off">
                    <small class="ml-hint">
                        Path from the project root, starting with a slash.
                        Example: <code>/uploads/media/2026/05/photo.webp</code>
                    </small>
                </label>
                <img class="cms-path-upload__preview"
                     id="ml-path-preview"
                     alt=""
                     hidden>

                <label class="field">File name
                    <input type="text"
                           name="file_name"
                           id="ml-file-name"
                           value="<?= cms_esc($editRow ? $val($editRow, 'file_name') : '') ?>"
                           required
                           placeholder="Auto-filled from path, or enter manually">
                    <small class="ml-hint">Auto-filled from the path above. You can edit it.</small>
                </label>

                <label class="field">File type
                    <select name="file_type" required>
                        <option value="">— Select type —</option>
                        <option value="image"    <?= $editFileType === 'image'    ? 'selected' : '' ?>>image</option>
                        <option value="document" <?= $editFileType === 'document' ? 'selected' : '' ?>>document</option>
                        <option value="video"    <?= $editFileType === 'video'    ? 'selected' : '' ?>>video</option>
                        <option value="other"    <?= ($editFileType !== '' && !in_array($editFileType, ['image', 'document', 'video'], true)) ? 'selected' : ($editFileType === 'other' ? 'selected' : '') ?>>other</option>
                    </select>
                </label>

                <label class="field">MIME type
                    <input type="text"
                           name="mime_type"
                           value="<?= cms_esc($editRow ? $val($editRow, 'mime_type') : '') ?>"
                           placeholder="e.g. image/jpeg">
                    <small class="ml-hint">Optional. Helps the media picker recognise image files. Examples: <code>image/jpeg</code>, <code>image/webp</code>, <code>application/pdf</code></small>
                </label>

                <label class="field">File size (KB)
                    <input type="number"
                           name="file_size_kb"
                           min="0" step="1"
                           value="<?= cms_esc($editRow && $editRow['file_size_kb'] !== null ? (string) $editRow['file_size_kb'] : '') ?>"
                           placeholder="e.g. 245">
                    <small class="ml-hint">Optional. For reference only — does not affect functionality.</small>
                </label>

                <label class="field">Alt text
                    <input type="text" name="alt_text" value="<?= cms_esc($editRow ? $val($editRow, 'alt_text') : '') ?>">
                </label>
                <label class="field">Caption
                    <input type="text" name="caption" value="<?= cms_esc($editRow ? $val($editRow, 'caption') : '') ?>">
                </label>
                <label class="field">Status
                    <select name="is_active" required>
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create media file' ?></button>
            </form>
        </div>
    <?php endif; // $editRow || $showCreateForm ?>
</section>
<script>
(function () {
    // ---- Resolve a relative path to a browser URL for live preview ----
    // Mirrors app_asset_preview_url(): relative paths are prefixed with BASE_URL.
    function previewUrl(path) {
        path = (path || '').trim();
        if (!path) return '';
        if (/^https?:\/\//i.test(path) || path.charAt(0) === '/') return path;
        return '../../' + path.replace(/^(\.\.\/)+/, '').replace(/^\//, '');
    }

    var pathInput   = document.getElementById('ml-file-path');
    var nameInput   = document.getElementById('ml-file-name');
    var pathPreview = document.getElementById('ml-path-preview');
    var uploadInput = document.getElementById('ml-upload-file');

    // ---- Live image preview from file_path ----
    // Hoisted to outer scope so the upload handler can also trigger it.
    function syncPreview() {
        if (!pathInput || !pathPreview) return;
        var url = previewUrl(pathInput.value);
        if (!url) {
            pathPreview.hidden = true;
            pathPreview.removeAttribute('src');
            return;
        }
        pathPreview.src = url;
        pathPreview.hidden = false;
        pathPreview.onerror = function () { pathPreview.hidden = true; };
    }
    if (pathInput) { pathInput.addEventListener('input', syncPreview); syncPreview(); }

    // ---- Auto-fill file_name from file_path basename (manual path typing) ----
    if (pathInput && nameInput) {
        pathInput.addEventListener('input', function () {
            if (nameInput.value.trim() !== '') return;
            var path = pathInput.value.trim();
            if (!path) return;
            var basename = path.replace(/\\/g, '/').split('/').pop() || '';
            if (basename) { nameInput.value = basename; }
        });
    }

    // ---- Auto-fill all form fields when a file is selected for upload ----
    // The server will generate the final path; this shows a realistic preview.
    if (uploadInput) {
        uploadInput.addEventListener('change', function () {
            var file = uploadInput.files && uploadInput.files[0];
            if (!file) return;

            var origName = file.name;
            var dotPos   = origName.lastIndexOf('.');
            var extFull  = dotPos !== -1 ? origName.slice(dotPos).toLowerCase() : '';
            var basePart = (dotPos !== -1 ? origName.slice(0, dotPos) : origName)
                             .toLowerCase()
                             .replace(/[^a-z0-9_-]+/g, '-')
                             .replace(/^-+|-+$/g, '') || 'upload';

            // Build a preview path matching the server-side date-directory pattern
            var now = new Date();
            var yr  = now.getFullYear();
            var mo  = String(now.getMonth() + 1).padStart(2, '0');
            var previewPath = 'uploads/media/' + yr + '/' + mo + '/' + basePart + '-xxxxxxxx' + extFull;

            // Fill file path (placeholder — server sets the real unique name)
            if (pathInput) { pathInput.value = previewPath; syncPreview(); }

            // Fill file name if still empty
            if (nameInput && nameInput.value.trim() === '') {
                nameInput.value = basePart + extFull;
            }

            // Fill MIME type
            var mime   = file.type || '';
            var mimeEl = document.querySelector('[name="mime_type"]');
            if (mimeEl) { mimeEl.value = mime; }

            // Derive and fill file_type
            var typeEl = document.querySelector('[name="file_type"]');
            if (typeEl) {
                var derived = mime.startsWith('image/')       ? 'image'
                            : mime === 'application/pdf'      ? 'document'
                            : '';
                typeEl.value = derived;
            }

            // Fill file size in KB
            var sizeEl = document.querySelector('[name="file_size_kb"]');
            if (sizeEl && file.size) { sizeEl.value = Math.ceil(file.size / 1024); }
        });
    }
})();

// Search/type/status filtering is server-side now (see $mlWhere/$mlParams
// in the PHP above) — the client-side JS row-hiding that used to live here
// was removed 24 Aug 2026 alongside the pagination fix, since it only
// filtered whatever page of rows happened to be loaded, not the whole
// library.

// ---- Copy Path button ----
(function () {
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.ml-copy-btn');
        if (!btn) return;
        var path = btn.getAttribute('data-path') || '';
        if (!path) return;
        var orig = btn.textContent;
        function flash() {
            btn.textContent = 'Copied!';
            setTimeout(function () { btn.textContent = orig; }, 1800);
        }
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(path).then(flash);
        } else {
            // Fallback for older/non-secure contexts
            var ta = document.createElement('textarea');
            ta.value = path;
            ta.style.cssText = 'position:fixed;top:0;left:0;opacity:0;pointer-events:none';
            document.body.appendChild(ta);
            ta.select();
            try { document.execCommand('copy'); flash(); } catch (_) {}
            document.body.removeChild(ta);
        }
    });
})();

// "Add Media Path" is a plain link to ?view=create now (see the toolbar
// button in the PHP above) — full navigation, always a clean form, no
// in-place JS reset/scroll trick needed since the create form has its own
// dedicated full-page view now instead of sharing a page with the list.
</script>
<?php
require dirname(__DIR__) . '/includes/footer.php';
