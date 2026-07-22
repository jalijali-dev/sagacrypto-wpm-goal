<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

// Site-wide configuration is admin-tier — see cms_require_role() in
// functions.php for the full tier breakdown.
cms_require_role(['superadmin', 'admin']);

/**
 * "Tentang Kami" (About Us) settings — homepage #tentang section.
 *
 * Repurposed 13 Jul 2026 from the old "Landing Page" builder, which
 * managed a generic multi-section `landing_sections` table left over
 * from the pre-pivot TheAwsoft site and was never actually rendered
 * anywhere on the Sagagoal frontend (confirmed via full codebase
 * grep). Rather than add a new table, this reuses `landing_sections`
 * with a single fixed page_key ('about') and 5 fixed section_keys:
 *   - main               -> title + subtitle (main heading/paragraph)
 *   - feature_1..4       -> title + subtitle (the 4 feature cards)
 * Icons for the 4 cards stay fixed by position (matches the previous
 * hardcoded array in index.php) — only the text is editable here.
 *
 * index.php reads these same rows (page_key='about', status='published')
 * to render the #tentang section, falling back to the defaults below if
 * a row doesn't exist yet (e.g. before this form is ever saved).
 */

const ABOUT_PAGE_KEY = 'about';

/** Same defaults used in index.php's fallback — keep these two in sync. */
const ABOUT_DEFAULTS = [
    'main' => [
        'title' => 'Sagagoal, Portal Livescore & Berita Bola Terpercaya',
        'subtitle' => 'Sagagoal menghadirkan jadwal pertandingan, live score, klasemen liga, dan berita sepak bola dalam satu tempat — disajikan ringkas, akurat, dan mudah dipahami untuk pembaca dari berbagai level.',
    ],
    'feature_1' => ['icon' => 'megaphone', 'title' => 'Berita Bola', 'subtitle' => 'Update berita sepak bola tercepat dan terpercaya, dari transfer pemain hingga hasil pertandingan.'],
    'feature_2' => ['icon' => 'chart', 'title' => 'Live Score', 'subtitle' => 'Skor pertandingan real-time, dari kick-off sampai peluit akhir, langsung di halaman utama.'],
    'feature_3' => ['icon' => 'book', 'title' => 'Jadwal Pertandingan', 'subtitle' => 'Jadwal lengkap pertandingan dari liga-liga pilihan, mudah dipantau setiap hari.'],
    'feature_4' => ['icon' => 'flame', 'title' => 'Klasemen Liga', 'subtitle' => 'Klasemen liga terkini, update otomatis setiap pertandingan selesai.'],
];

$pageTitle = 'About';
$currentNav = 'about-settings';
$breadcrumbs = [
    ['label' => 'Dashboard', 'href' => cms_dashboard_href()],
    ['label' => 'About', 'href' => ''],
];

$selfUrl = 'about-settings.php';

$as_redirect = static function (string $message, string $type = 'success') use ($selfUrl): void {
    $_SESSION['cms_flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . $selfUrl, true, 302);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $sections = ['main', 'feature_1', 'feature_2', 'feature_3', 'feature_4'];

    // landing_sections has no UNIQUE KEY on (page_key, section_key) — do an
    // explicit select-then-insert/update instead of relying on
    // ON DUPLICATE KEY UPDATE (which would silently no-op / just keep
    // inserting duplicate rows without that constraint).
    $findStmt = $pdo->prepare('SELECT landing_section_id FROM landing_sections WHERE page_key = :pk AND section_key = :sk LIMIT 1');
    $updateStmt = $pdo->prepare(
        'UPDATE landing_sections SET title = :title, subtitle = :subtitle, sort_order = :sort_order, status = \'published\', updated_at = NOW()
         WHERE landing_section_id = :id'
    );
    $insertStmt = $pdo->prepare(
        'INSERT INTO landing_sections (page_key, section_key, section_type, title, subtitle, status, sort_order, created_at, updated_at)
         VALUES (:pk, :sk, :type, :title, :subtitle, \'published\', :sort_order, NOW(), NOW())'
    );

    try {
        foreach ($sections as $i => $key) {
            $title = trim((string) ($_POST[$key . '_title'] ?? '')) ?: ABOUT_DEFAULTS[$key]['title'];
            $subtitle = trim((string) ($_POST[$key . '_subtitle'] ?? '')) ?: ABOUT_DEFAULTS[$key]['subtitle'];

            $findStmt->execute(['pk' => ABOUT_PAGE_KEY, 'sk' => $key]);
            $id = $findStmt->fetchColumn();

            if ($id) {
                $updateStmt->execute(['title' => $title, 'subtitle' => $subtitle, 'sort_order' => $i, 'id' => $id]);
            } else {
                $insertStmt->execute([
                    'pk' => ABOUT_PAGE_KEY,
                    'sk' => $key,
                    'type' => $key === 'main' ? 'about_main' : 'about_feature',
                    'title' => $title,
                    'subtitle' => $subtitle,
                    'sort_order' => $i,
                ]);
            }
        }
    } catch (PDOException $e) {
        $as_redirect('Gagal menyimpan: ' . $e->getMessage(), 'error');
    }

    $as_redirect('Pengaturan Tentang Kami berhasil disimpan.');
}

$alerts = [];
if (isset($_SESSION['cms_flash']) && is_array($_SESSION['cms_flash'])) {
    $alerts[] = $_SESSION['cms_flash'];
    unset($_SESSION['cms_flash']);
}

/** @var array<string, array{title:string, subtitle:string}> $current */
$current = [];
try {
    $stmt = $pdo->prepare('SELECT section_key, title, subtitle FROM landing_sections WHERE page_key = :pk');
    $stmt->execute(['pk' => ABOUT_PAGE_KEY]);
    foreach ($stmt->fetchAll() as $row) {
        $current[(string) $row['section_key']] = [
            'title' => (string) ($row['title'] ?? ''),
            'subtitle' => (string) ($row['subtitle'] ?? ''),
        ];
    }
} catch (PDOException $e) {
    // landing_sections not reachable — form will just show defaults.
}

$val = static function (string $key, string $field): string {
    global $current;
    if (isset($current[$key]) && $current[$key][$field] !== '') {
        return $current[$key][$field];
    }
    return ABOUT_DEFAULTS[$key][$field] ?? '';
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
            <h2 class="section-title">About</h2>
            <p class="section-lead">Konten section "Tentang Kami" di homepage (<code>#tentang</code>) — judul, deskripsi, dan 4 kartu fitur.</p>
        </div>
    </div>

    <form class="form-grid" method="post" action="<?= cms_esc($selfUrl) ?>">
        <?= cms_csrf_field() ?>

        <div class="panel" style="grid-column: 1 / -1;">
            <div class="panel__head">
                <h3 class="panel__title">Konten Utama</h3>
            </div>
            <div class="form-grid" style="padding: 0 20px 20px;">
                <label class="field" style="grid-column: 1 / -1;">Judul
                    <input type="text" name="main_title" value="<?= cms_esc($val('main', 'title')) ?>">
                </label>
                <label class="field" style="grid-column: 1 / -1;">Deskripsi
                    <textarea name="main_subtitle" rows="3"><?= cms_esc($val('main', 'subtitle')) ?></textarea>
                </label>
            </div>
        </div>

        <?php
        $featureLabels = [
            'feature_1' => 'Kartu 1 — ikon megaphone',
            'feature_2' => 'Kartu 2 — ikon book',
            'feature_3' => 'Kartu 3 — ikon chart',
            'feature_4' => 'Kartu 4 — ikon flame',
        ];
        foreach ($featureLabels as $key => $label) :
        ?>
        <div class="panel" style="grid-column: 1 / -1;">
            <div class="panel__head">
                <h3 class="panel__title"><?= cms_esc($label) ?></h3>
            </div>
            <div class="form-grid" style="padding: 0 20px 20px;">
                <label class="field">Judul kartu
                    <input type="text" name="<?= $key ?>_title" value="<?= cms_esc($val($key, 'title')) ?>">
                </label>
                <label class="field">Deskripsi kartu
                    <textarea name="<?= $key ?>_subtitle" rows="2"><?= cms_esc($val($key, 'subtitle')) ?></textarea>
                </label>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="form-grid__actions" style="grid-column: 1 / -1;">
            <button type="submit" class="admin-btn admin-btn--primary">Simpan perubahan</button>
        </div>
    </form>
</section>
<?php
require dirname(__DIR__) . '/includes/footer.php';
