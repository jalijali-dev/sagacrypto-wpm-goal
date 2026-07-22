<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

$pageTitle = 'Testimonials';
$currentNav = 'testimonials';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Testimonials', 'href' => ''],
];

$selfUrl = 'testimonials.php';

/**
 * Column mapping note (fixed 2026-07-08):
 * This page used to query customer_name / customer_role / testimonial_text /
 * customer_image / status — none of which exist on the actual `testimonials`
 * table. The real schema uses client_name / client_position / client_company /
 * content / photo / is_active (see wpm_cms.sql). Queries and form fields below
 * now match the real columns instead of requiring a schema change.
 */

$tm_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$tm_validate = static function (string $clientName, string $content, string $ratingRaw): ?string {
    if ($clientName === '') {
        return 'Customer name is required.';
    }
    if ($content === '') {
        return 'Testimonial text is required.';
    }
    if ($ratingRaw === '' || !is_numeric($ratingRaw)) {
        return 'Rating must be a number.';
    }

    return null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $tm_redirect('Invalid testimonial.', 'error');
        }
        $delete = $pdo->prepare('DELETE FROM testimonials WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        if ($delete->rowCount() < 1) {
            $tm_redirect('Testimonial not found or already deleted.', 'error');
        }
        $tm_redirect('Testimonial deleted successfully.');
    }

    $clientName = trim((string) ($_POST['client_name'] ?? ''));
    $clientPosition = trim((string) ($_POST['client_position'] ?? ''));
    $clientCompany = trim((string) ($_POST['client_company'] ?? ''));
    $content = trim((string) ($_POST['content'] ?? ''));
    $ratingRaw = trim((string) ($_POST['rating'] ?? ''));
    $photo = trim((string) ($_POST['photo'] ?? ''));
    $isFeatured = isset($_POST['is_featured']) && (int) $_POST['is_featured'] === 1 ? 1 : 0;
    $isActive = isset($_POST['is_active']) && (int) $_POST['is_active'] === 1 ? 1 : 0;
    $sortOrder = (int) ($_POST['sort_order'] ?? 0);

    $validationError = $tm_validate($clientName, $content, $ratingRaw);
    if ($validationError !== null) {
        $errorQuery = ($action === 'update' && (int) ($_POST['id'] ?? 0) > 0)
            ? 'edit=' . (int) $_POST['id'] : null;
        $tm_redirect($validationError, 'error', $errorQuery);
    }

    $payload = [
        'client_name' => $clientName,
        'client_position' => $clientPosition,
        'client_company' => $clientCompany,
        'content' => $content,
        'rating' => $ratingRaw,
        'photo' => $photo,
        'is_featured' => $isFeatured,
        'is_active' => $isActive,
        'sort_order' => $sortOrder,
    ];

    if ($action === 'create') {
        $insert = $pdo->prepare(
            'INSERT INTO testimonials (
                client_name, client_position, client_company, content, rating, photo,
                is_featured, is_active, sort_order, created_at, updated_at
            ) VALUES (
                :client_name, :client_position, :client_company, :content, :rating, :photo,
                :is_featured, :is_active, :sort_order, NOW(), NOW()
            )'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $tm_redirect('Testimonial created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $tm_redirect('Invalid testimonial.', 'error');
        }
        $update = $pdo->prepare(
            'UPDATE testimonials
             SET client_name = :client_name,
                 client_position = :client_position,
                 client_company = :client_company,
                 content = :content,
                 rating = :rating,
                 photo = :photo,
                 is_featured = :is_featured,
                 is_active = :is_active,
                 sort_order = :sort_order,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        $tm_redirect('Testimonial updated successfully.', 'success', 'edit=' . $updateId);
    }

    $tm_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
$testimonials = [];
$schemaError = null;

try {
    $listStmt = $pdo->query(
        'SELECT id, client_name, client_position, client_company, rating, photo,
                is_featured, is_active, sort_order, created_at,
                LEFT(content, 80) AS snippet
         FROM testimonials
         ORDER BY sort_order ASC, id DESC'
    );
    $testimonials = $listStmt->fetchAll();

    if ($editId > 0) {
        $editStmt = $pdo->prepare(
            'SELECT id, client_name, client_position, client_company, content, rating, photo,
                    is_featured, is_active, sort_order
             FROM testimonials WHERE id = :id LIMIT 1'
        );
        $editStmt->execute(['id' => $editId]);
        $editRow = $editStmt->fetch() ?: null;
        if ($editRow === null) {
            $alerts[] = ['type' => 'error', 'message' => 'Testimonial not found.'];
            $editId = 0;
        }
    }
} catch (Throwable $e) {
    // Don't fatal-error the whole admin panel if the schema is somehow
    // still incomplete (e.g. table missing entirely) — show a clear,
    // actionable message instead of a raw PDO exception.
    $schemaError = $e->getMessage();
}

$val = static fn (array $row, string $key): string => (string) ($row[$key] ?? '');

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Testimonials</h2>
            <p class="section-lead">Customer quotes shown on the storefront.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#testimonial-form') ?>">Add testimonial</a>
        </div>
    </div>

    <?php if ($schemaError !== null) : ?>
        <div class="admin-alert admin-alert--error">
            <strong>Testimonials table isn't ready yet.</strong>
            The database is missing columns this page needs, so nothing can be loaded safely right now.
            <br>Details: <?= cms_esc($schemaError) ?>
        </div>
    <?php else : ?>

    <div class="admin-grid admin-grid--2">
        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Testimonials</h3>
                <span class="panel__meta"><?= count($testimonials) ?> item(s)</span>
            </div>
            <div class="table-wrap">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Customer</th>
                            <th>Role / Company</th>
                            <th>Rating</th>
                            <th>Snippet</th>
                            <th>Featured</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($testimonials === []) : ?>
                            <tr><td colspan="7" class="muted">No testimonials yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($testimonials as $row) : ?>
                            <?php $rowId = (int) $row['id']; ?>
                            <tr>
                                <td><?= cms_esc($val($row, 'client_name')) ?></td>
                                <td>
                                    <?= cms_esc($val($row, 'client_position')) ?>
                                    <?php if ($val($row, 'client_company') !== '') : ?>
                                        &middot; <?= cms_esc($val($row, 'client_company')) ?>
                                    <?php endif; ?>
                                </td>
                                <td><?= cms_esc($val($row, 'rating')) ?> ★</td>
                                <td class="cell-clip"><?= cms_esc($val($row, 'snippet')) ?></td>
                                <td>
                                    <?php if ((int) ($row['is_featured'] ?? 0) === 1) : ?>
                                        <span class="pill pill--accent">Featured</span>
                                    <?php else : ?>
                                        <span class="muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="pill pill--<?= (int) ($row['is_active'] ?? 0) === 1 ? 'ok' : 'muted' ?>">
                                        <?= (int) ($row['is_active'] ?? 0) === 1 ? 'Active' : 'Inactive' ?>
                                    </span>
                                </td>
                                <td class="table-actions">
                                    <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= $rowId ?>">Edit</a>
                                    <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this testimonial?');">
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
        </div>

        <div class="panel" id="testimonial-form">
            <div class="panel__head">
                <h3 class="panel__title"><?= $editRow ? 'Edit testimonial' : 'New testimonial' ?></h3>
                <?php if ($editRow) : ?>
                    <a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a>
                <?php endif; ?>
            </div>
            <form class="form-stack" method="post" action="<?= cms_esc($selfUrl) ?>">
                <?= cms_csrf_field() ?>
                <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
                <?php if ($editRow) : ?>
                    <input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>">
                <?php endif; ?>
                <label class="field">Customer name
                    <input type="text" name="client_name" value="<?= cms_esc($editRow ? $val($editRow, 'client_name') : '') ?>" required>
                </label>
                <label class="field">Customer role
                    <input type="text" name="client_position" value="<?= cms_esc($editRow ? $val($editRow, 'client_position') : '') ?>" placeholder="e.g. Business Owner">
                </label>
                <label class="field">Company
                    <input type="text" name="client_company" value="<?= cms_esc($editRow ? $val($editRow, 'client_company') : '') ?>" placeholder="e.g. Sample Company">
                </label>
                <label class="field">Rating
                    <input type="number" name="rating" min="0" max="5" step="1" value="<?= cms_esc($editRow ? $val($editRow, 'rating') : '5') ?>" required>
                </label>
                <label class="field">Testimonial text
                    <textarea name="content" rows="5" required><?= cms_esc($editRow ? $val($editRow, 'content') : '') ?></textarea>
                </label>
                <label class="field">Photo path
                    <input type="text" name="photo" value="<?= cms_esc($editRow ? $val($editRow, 'photo') : '') ?>" placeholder="e.g. uploads/testimonials/customer.jpg">
                </label>
                <label class="field">Sort order
                    <input type="number" name="sort_order" min="0" step="1" value="<?= cms_esc($editRow ? $val($editRow, 'sort_order') : '0') ?>">
                </label>
                <label class="field">Featured
                    <select name="is_featured">
                        <option value="0"<?= !$editRow || (int) ($editRow['is_featured'] ?? 0) === 0 ? ' selected' : '' ?>>No</option>
                        <option value="1"<?= $editRow && (int) ($editRow['is_featured'] ?? 0) === 1 ? ' selected' : '' ?>>Yes</option>
                    </select>
                </label>
                <label class="field">Status
                    <select name="is_active" required>
                        <option value="1"<?= !$editRow || (int) ($editRow['is_active'] ?? 0) === 1 ? ' selected' : '' ?>>Active</option>
                        <option value="0"<?= $editRow && (int) ($editRow['is_active'] ?? 0) === 0 ? ' selected' : '' ?>>Inactive</option>
                    </select>
                </label>
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create testimonial' ?></button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
