<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__, 2) . '/agents/SeoAgent.php';

$pageTitle  = 'AI SEO Test';
$currentNav = '';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'AI SEO Test', 'href' => ''],
];

$alerts = [];
$seoResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $product = [
        'name'          => trim((string) ($_POST['name'] ?? '')),
        'category_name' => trim((string) ($_POST['category_name'] ?? '')),
        'description'   => trim((string) ($_POST['description'] ?? '')),
        'price'         => trim((string) ($_POST['price'] ?? '')),
    ];

    if ($product['name'] === '') {
        $alerts[] = ['type' => 'error', 'message' => 'Nama produk wajib diisi.'];
    } else {
        try {
            $agent     = new SeoAgent();
            $seoResult = $agent->generateProductSeo($product);

            if (!$seoResult['success']) {
                $alerts[] = ['type' => 'error', 'message' => 'Gagal generate SEO: ' . $seoResult['error']];
                $seoResult = null;
            }
        } catch (\Throwable $e) {
            $alerts[] = ['type' => 'error', 'message' => 'Error: ' . $e->getMessage()];
        }
    }
}

require dirname(__DIR__) . '/includes/header.php';
require dirname(__DIR__) . '/includes/sidebar.php';
require dirname(__DIR__) . '/includes/navbar.php';
require dirname(__DIR__) . '/includes/breadcrumb.php';
require dirname(__DIR__) . '/includes/alerts.php';
?>
<section class="admin-stack">
    <div class="toolbar">
        <div class="toolbar__left">
            <h2 class="section-title">AI SEO Test</h2>
            <p class="section-lead">Generate meta_title dan meta_description produk menggunakan Claude AI.</p>
        </div>
    </div>

    <div class="admin-grid admin-grid--2">

        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Data Produk</h3>
            </div>
            <form class="form-stack" method="post" action="">
                <?= cms_csrf_field() ?>
                <label class="field">Nama Produk <span style="color:var(--danger)">*</span>
                    <input
                        type="text"
                        name="name"
                        value="<?= cms_esc((string) ($_POST['name'] ?? '')) ?>"
                        placeholder="Contoh: Black Forest Birthday Cake"
                        required
                    >
                </label>
                <label class="field">Kategori
                    <input
                        type="text"
                        name="category_name"
                        value="<?= cms_esc((string) ($_POST['category_name'] ?? '')) ?>"
                        placeholder="Contoh: Birthday Cake"
                    >
                </label>
                <label class="field">Deskripsi Produk
                    <textarea
                        name="description"
                        rows="4"
                        placeholder="Contoh: Kue ulang tahun lapis cokelat premium dengan cherry segar dan krim lembut."
                    ><?= cms_esc((string) ($_POST['description'] ?? '')) ?></textarea>
                </label>
                <label class="field">Harga (Rp)
                    <input
                        type="text"
                        name="price"
                        value="<?= cms_esc((string) ($_POST['price'] ?? '')) ?>"
                        placeholder="Contoh: 285000"
                    >
                </label>
                <button type="submit" class="admin-btn admin-btn--primary">Generate SEO</button>
            </form>
        </div>

        <div class="panel">
            <div class="panel__head">
                <h3 class="panel__title">Hasil Generate</h3>
            </div>
            <?php if ($seoResult !== null) : ?>
                <div class="form-stack">
                    <label class="field">meta_title
                        <input
                            type="text"
                            value="<?= cms_esc($seoResult['meta_title']) ?>"
                            readonly
                        >
                        <small class="muted">
                            <?= mb_strlen($seoResult['meta_title'], 'UTF-8') ?> / 60 karakter
                        </small>
                    </label>
                    <label class="field">meta_description
                        <textarea rows="3" readonly><?= cms_esc($seoResult['meta_description']) ?></textarea>
                        <small class="muted">
                            <?= mb_strlen($seoResult['meta_description'], 'UTF-8') ?> / 155 karakter
                        </small>
                    </label>
                </div>
            <?php else : ?>
                <p class="muted" style="padding:16px 0;">Isi form di sebelah kiri lalu klik <strong>Generate SEO</strong>.</p>
            <?php endif; ?>
        </div>

    </div>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
