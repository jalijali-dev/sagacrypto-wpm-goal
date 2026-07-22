<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/includes/schema-guard.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

/**
 * Auto-migration: featured/"Pamungkas" homepage section builder.
 * Idempotent, safe on every load.
 */
$fc_schemaError = null;
try {
    cms_ensure_table(
        $pdo,
        'featured_sections',
        'id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         title VARCHAR(150) NOT NULL,
         content_type ENUM(\'manual\',\'latest\',\'trending\',\'category\',\'livescore_api\',\'ad_banner\',\'app_promo_android\',\'app_promo_ios\') NOT NULL DEFAULT \'latest\',
         category_id INT UNSIGNED DEFAULT NULL,
         item_count INT UNSIGNED NOT NULL DEFAULT 6,
         layout ENUM(\'grid\',\'list\',\'carousel\',\'hero\') NOT NULL DEFAULT \'grid\',
         show_on_desktop TINYINT(1) NOT NULL DEFAULT 1,
         show_on_mobile TINYINT(1) NOT NULL DEFAULT 1,
         ad_position_id INT UNSIGNED DEFAULT NULL,
         is_active TINYINT(1) NOT NULL DEFAULT 1,
         sort_order INT NOT NULL DEFAULT 0,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
    );
    cms_ensure_table(
        $pdo,
        'featured_section_items',
        'section_id INT UNSIGNED NOT NULL,
         page_id INT NOT NULL,
         sort_order INT NOT NULL DEFAULT 0,
         PRIMARY KEY (section_id, page_id)'
    );
} catch (Throwable $e) {
    $fc_schemaError = $e->getMessage();
}

$pageTitle = 'Featured Content';
$currentNav = 'featured-content';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'Featured Content', 'href' => ''],
];

$selfUrl = 'featured-content.php';

$fc_redirect = static function (string $message, string $type = 'success', ?string $query = null) use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl . ($query ? '?' . $query : ''), true, 302);
    exit;
};

$contentTypes = [
    'manual'             => 'Artikel manual (pilih sendiri)',
    'latest'             => 'Artikel terbaru',
    'trending'           => 'Artikel trending',
    'category'           => 'Artikel berdasarkan kategori',
    'ad_banner'          => 'Banner iklan',
    'app_promo_android'  => 'Promosi aplikasi Android',
    'app_promo_ios'      => 'Promosi aplikasi iOS',
];
$layouts = ['grid' => 'Grid', 'list' => 'List', 'carousel' => 'Carousel', 'hero' => 'Hero (besar)'];

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'delete') {
        $deleteId = (int) ($_POST['id'] ?? 0);
        if ($deleteId <= 0) {
            $fc_redirect('Invalid section.', 'error');
        }
        $pdo->prepare('DELETE FROM featured_section_items WHERE section_id = :id')->execute(['id' => $deleteId]);
        $delete = $pdo->prepare('DELETE FROM featured_sections WHERE id = :id');
        $delete->execute(['id' => $deleteId]);
        $fc_redirect($delete->rowCount() > 0 ? 'Section deleted.' : 'Section not found.', $delete->rowCount() > 0 ? 'success' : 'error');
    }

    if ($action === 'toggle_active') {
        $toggleId = (int) ($_POST['id'] ?? 0);
        if ($toggleId <= 0) {
            $fc_redirect('Invalid section.', 'error');
        }
        $pdo->prepare('UPDATE featured_sections SET is_active = 1 - is_active WHERE id = :id')->execute(['id' => $toggleId]);
        $fc_redirect('Section status updated.');
    }

    $title       = trim((string) ($_POST['title'] ?? ''));
    $contentType = array_key_exists($_POST['content_type'] ?? '', $contentTypes) ? $_POST['content_type'] : 'latest';
    $categoryId  = (int) ($_POST['category_id'] ?? 0) ?: null;
    $itemCount   = max(1, (int) ($_POST['item_count'] ?? 6));
    $layout      = array_key_exists($_POST['layout'] ?? '', $layouts) ? $_POST['layout'] : 'grid';
    $showDesktop = !empty($_POST['show_on_desktop']) ? 1 : 0;
    $showMobile  = !empty($_POST['show_on_mobile']) ? 1 : 0;
    $adPositionId = (int) ($_POST['ad_position_id'] ?? 0) ?: null;
    $isActive    = !empty($_POST['is_active']) ? 1 : 0;
    $sortOrder   = (int) ($_POST['sort_order'] ?? 0);
    $manualIds   = array_filter(array_map('intval', $_POST['manual_page_ids'] ?? []));

    if ($title === '') {
        $fc_redirect('Section title is required.', 'error');
    }

    $payload = [
        'title'            => $title,
        'content_type'     => $contentType,
        'category_id'      => $categoryId,
        'item_count'       => $itemCount,
        'layout'           => $layout,
        'show_on_desktop'  => $showDesktop,
        'show_on_mobile'   => $showMobile,
        'ad_position_id'   => $adPositionId,
        'is_active'        => $isActive,
        'sort_order'       => $sortOrder,
    ];

    $fc_syncManualItems = static function (PDO $pdo, int $sectionId, array $pageIds): void {
        $pdo->prepare('DELETE FROM featured_section_items WHERE section_id = :id')->execute(['id' => $sectionId]);
        if ($pageIds === []) {
            return;
        }
        $insert = $pdo->prepare('INSERT IGNORE INTO featured_section_items (section_id, page_id, sort_order) VALUES (:section_id, :page_id, :sort_order)');
        $order = 0;
        foreach ($pageIds as $pid) {
            $insert->execute(['section_id' => $sectionId, 'page_id' => $pid, 'sort_order' => $order++]);
        }
    };

    if ($action === 'create') {
        $insert = $pdo->prepare(
            'INSERT INTO featured_sections (
                title, content_type, category_id, item_count, layout, show_on_desktop, show_on_mobile,
                ad_position_id, is_active, sort_order, created_at, updated_at
            ) VALUES (
                :title, :content_type, :category_id, :item_count, :layout, :show_on_desktop, :show_on_mobile,
                :ad_position_id, :is_active, :sort_order, NOW(), NOW()
            )'
        );
        $insert->execute($payload);
        $newId = (int) $pdo->lastInsertId();
        $fc_syncManualItems($pdo, $newId, $manualIds);
        $fc_redirect('Section created successfully.', 'success', 'edit=' . $newId);
    }

    if ($action === 'update') {
        $updateId = (int) ($_POST['id'] ?? 0);
        if ($updateId <= 0) {
            $fc_redirect('Invalid section.', 'error');
        }
        $update = $pdo->prepare(
            'UPDATE featured_sections
             SET title = :title, content_type = :content_type, category_id = :category_id,
                 item_count = :item_count, layout = :layout, show_on_desktop = :show_on_desktop,
                 show_on_mobile = :show_on_mobile, ad_position_id = :ad_position_id,
                 is_active = :is_active, sort_order = :sort_order, updated_at = NOW()
             WHERE id = :id'
        );
        $update->execute($payload + ['id' => $updateId]);
        $fc_syncManualItems($pdo, $updateId, $manualIds);
        $fc_redirect('Section updated successfully.', 'success', 'edit=' . $updateId);
    }

    $fc_redirect('Unknown action.', 'error');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}
if ($fc_schemaError !== null) {
    $alerts[] = ['type' => 'error', 'message' => 'Featured content setup could not run automatically: ' . $fc_schemaError];
}

$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
$editManualIds = [];
if ($editId > 0) {
    $editStmt = $pdo->prepare('SELECT * FROM featured_sections WHERE id = :id LIMIT 1');
    $editStmt->execute(['id' => $editId]);
    $editRow = $editStmt->fetch() ?: null;
    if ($editRow === null) {
        $alerts[] = ['type' => 'error', 'message' => 'Section not found.'];
        $editId = 0;
    } else {
        $itemStmt = $pdo->prepare('SELECT page_id FROM featured_section_items WHERE section_id = :id ORDER BY sort_order ASC');
        $itemStmt->execute(['id' => $editId]);
        $editManualIds = array_map('intval', array_column($itemStmt->fetchAll(), 'page_id'));
    }
}

// Categories for the "category" content type, ad positions for "ad between content".
$categories = [];
$positions = [];
try {
    $categories = $pdo->query('SELECT id, name FROM article_categories ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    $categories = [];
}
try {
    $positions = $pdo->query('SELECT id, name FROM ad_positions ORDER BY name ASC')->fetchAll();
} catch (Throwable $e) {
    $positions = [];
}

// Published articles for the manual picker.
$articles = $pdo->query(
    "SELECT page_id, title FROM pages WHERE status = 'published' ORDER BY published_at DESC LIMIT 200"
)->fetchAll();

$sections = $pdo->query(
    'SELECT s.*, c.name AS category_name,
            (SELECT COUNT(*) FROM featured_section_items i WHERE i.section_id = s.id) AS manual_count
     FROM featured_sections s
     LEFT JOIN article_categories c ON c.id = s.category_id
     ORDER BY s.sort_order ASC, s.id DESC'
)->fetchAll();

$val = static function (array $row, string $key): string {
    return (string) ($row[$key] ?? '');
};

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">Featured Content (Pamungkas)</h2>
            <p class="section-lead">Build the homepage's main content blocks — mix manual picks, auto content, livescore widgets, ads, and app promos.</p>
        </div>
        <div class="toolbar__right">
            <a class="admin-btn admin-btn--primary" href="<?= cms_esc($editRow ? $selfUrl : $selfUrl . '#create-section') ?>">+ New Section</a>
        </div>
    </div>

    <div class="panel" id="create-section">
        <div class="panel__head">
            <h3 class="panel__title"><?= $editRow ? 'Edit Section' : 'New Section' ?></h3>
            <?php if ($editRow) : ?><a class="panel__link" href="<?= cms_esc($selfUrl) ?>">Cancel edit</a><?php endif; ?>
        </div>
        <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
            <?= cms_csrf_field() ?>
            <input type="hidden" name="action" value="<?= $editRow ? 'update' : 'create' ?>">
            <?php if ($editRow) : ?><input type="hidden" name="id" value="<?= (int) $editRow['id'] ?>"><?php endif; ?>

            <label class="field">Section title
                <input type="text" name="title" required value="<?= $editRow ? cms_esc($val($editRow, 'title')) : '' ?>" placeholder="e.g. Berita Bola Terbaru">
            </label>
            <label class="field">Content type
                <select name="content_type" id="fc-type-select">
                    <?php $curType = $editRow ? $val($editRow, 'content_type') : 'latest'; ?>
                    <?php foreach ($contentTypes as $tVal => $tLabel) : ?>
                        <option value="<?= $tVal ?>"<?= $curType === $tVal ? ' selected' : '' ?>><?= cms_esc($tLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field fc-field-category">Category <small style="opacity:.7;">(untuk tipe "berdasarkan kategori")</small>
                <select name="category_id">
                    <option value="">— Pilih kategori —</option>
                    <?php foreach ($categories as $cat) : ?>
                        <option value="<?= (int) $cat['id'] ?>"<?= $editRow && (int) ($editRow['category_id'] ?? 0) === (int) $cat['id'] ? ' selected' : '' ?>><?= cms_esc($cat['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Jumlah item
                <input type="number" name="item_count" min="1" max="24" value="<?= $editRow ? (int) $editRow['item_count'] : 6 ?>">
            </label>
            <label class="field">Layout
                <select name="layout">
                    <?php $curLayout = $editRow ? $val($editRow, 'layout') : 'grid'; ?>
                    <?php foreach ($layouts as $lVal => $lLabel) : ?>
                        <option value="<?= $lVal ?>"<?= $curLayout === $lVal ? ' selected' : '' ?>><?= cms_esc($lLabel) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label class="field">Urutan tampil (sort order)
                <input type="number" name="sort_order" value="<?= $editRow ? (int) $editRow['sort_order'] : 0 ?>">
            </label>
            <label class="field">Sisipkan iklan dari posisi
                <select name="ad_position_id">
                    <option value="">— Tanpa iklan —</option>
                    <?php foreach ($positions as $pos) : ?>
                        <option value="<?= (int) $pos['id'] ?>"<?= $editRow && (int) ($editRow['ad_position_id'] ?? 0) === (int) $pos['id'] ? ' selected' : '' ?>><?= cms_esc($pos['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="field field--checkbox">
                <input type="checkbox" name="show_on_desktop" value="1"<?= (!$editRow || (int) ($editRow['show_on_desktop'] ?? 1) === 1) ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Tampil di desktop</span>
                </span>
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="show_on_mobile" value="1"<?= (!$editRow || (int) ($editRow['show_on_mobile'] ?? 1) === 1) ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Tampil di mobile</span>
                </span>
            </label>
            <label class="field field--checkbox">
                <input type="checkbox" name="is_active" value="1"<?= (!$editRow || (int) ($editRow['is_active'] ?? 1) === 1) ? ' checked' : '' ?>>
                <span class="field--checkbox__text">
                    <span class="field--checkbox__title">Section aktif</span>
                </span>
            </label>

            <div class="field fc-field-manual" style="grid-column: 1 / -1;">
                <span class="field" style="margin-bottom:6px;">Pilih artikel manual <small style="opacity:.7;">(untuk tipe "Artikel manual")</small></span>
                <select name="manual_page_ids[]" multiple size="8" style="width:100%;">
                    <?php foreach ($articles as $art) : ?>
                        <option value="<?= (int) $art['page_id'] ?>"<?= in_array((int) $art['page_id'], $editManualIds, true) ? ' selected' : '' ?>><?= cms_esc((string) $art['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <small style="font-size:11px;color:var(--muted,#888);display:block;margin-top:6px;">Ctrl/Cmd+klik untuk pilih beberapa artikel. Urutan pemilihan menentukan urutan tampil.</small>
            </div>

            <div class="form-grid__actions">
                <button type="submit" class="admin-btn admin-btn--primary"><?= $editRow ? 'Save changes' : 'Create Section' ?></button>
                <?php if ($editRow) : ?><a class="admin-btn admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>">Cancel</a><?php endif; ?>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel__head">
            <h3 class="panel__title">All Sections</h3>
            <span class="panel__meta"><?= count($sections) ?> section(s)</span>
        </div>
        <div class="table-wrap">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Title</th>
                        <th>Type</th>
                        <th>Layout</th>
                        <th>Devices</th>
                        <th>Status</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($sections === []) : ?>
                        <tr><td colspan="7" class="muted">No sections yet.</td></tr>
                    <?php endif; ?>
                    <?php foreach ($sections as $sec) : ?>
                        <?php
                        $typeLabel = $contentTypes[$sec['content_type']] ?? $sec['content_type'];
                        $devices = [];
                        if ((int) $sec['show_on_desktop'] === 1) { $devices[] = 'Desktop'; }
                        if ((int) $sec['show_on_mobile'] === 1) { $devices[] = 'Mobile'; }
                        ?>
                        <tr>
                            <td><?= (int) $sec['sort_order'] ?></td>
                            <td>
                                <?= cms_esc((string) $sec['title']) ?>
                                <?php if ($sec['content_type'] === 'manual') : ?>
                                    <br><small class="muted"><?= (int) $sec['manual_count'] ?> artikel dipilih</small>
                                <?php elseif ($sec['content_type'] === 'category' && !empty($sec['category_name'])) : ?>
                                    <br><small class="muted"><?= cms_esc((string) $sec['category_name']) ?></small>
                                <?php endif; ?>
                            </td>
                            <td><?= cms_esc($typeLabel) ?></td>
                            <td><code><?= cms_esc((string) $sec['layout']) ?></code></td>
                            <td><?= cms_esc($devices === [] ? '—' : implode(', ', $devices)) ?></td>
                            <td>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_active">
                                    <input type="hidden" name="id" value="<?= (int) $sec['id'] ?>">
                                    <button type="submit" class="pill pill--<?= (int) $sec['is_active'] === 1 ? 'ok' : 'muted' ?>" style="border:none;cursor:pointer;">
                                        <?= (int) $sec['is_active'] === 1 ? 'Active' : 'Inactive' ?>
                                    </button>
                                </form>
                            </td>
                            <td class="table-actions">
                                <a class="admin-btn admin-btn--sm admin-btn--secondary" href="<?= cms_esc($selfUrl) ?>?edit=<?= (int) $sec['id'] ?>">Edit</a>
                                <form class="inline-form" method="post" action="<?= cms_esc($selfUrl) ?>" onsubmit="return confirm('Delete this section?');">
                                    <?= cms_csrf_field() ?>
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= (int) $sec['id'] ?>">
                                    <button type="submit" class="admin-btn admin-btn--sm admin-btn--danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</section>
<script>
(function () {
    var typeSelect = document.getElementById('fc-type-select');
    var categoryBlock = document.querySelector('.fc-field-category');
    var manualBlock = document.querySelector('.fc-field-manual');

    function sync() {
        var t = typeSelect.value;
        categoryBlock.style.display = t === 'category' ? '' : 'none';
        manualBlock.style.display = t === 'manual' ? '' : 'none';
    }
    if (typeSelect) {
        typeSelect.addEventListener('change', sync);
        sync();
    }
})();
</script>
<?php require dirname(__DIR__) . '/includes/footer.php'; ?>
