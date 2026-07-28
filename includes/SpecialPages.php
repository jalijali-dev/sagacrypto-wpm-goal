<?php
declare(strict_types=1);

/**
 * Special Pages — admin-managed static pages (Kontak, FAQ, etc.) served
 * through page.php without a dev creating a new file each time (Fase 3,
 * Special Pages & Sports Modules spec, 24 Jul 2026).
 *
 * page_key is the permanent internal identity (not editable from the admin
 * UI once created — used to trigger special rendering like the Kontak
 * form template); slug is the editable public URL.
 *
 * Seeds one row on first run: page_key='contact' (the Kontak page,
 * migrated from the old standalone kontak.php — see page.php's
 * page_key==='contact' branch and the retired kontak.php/contact-submit.php).
 */

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';

/** Slugs already owned by hardcoded routes/files — a special page can't claim these (except a page keeping its own already-seeded slug). */
const WPM_SPECIAL_PAGE_RESERVED_SLUGS = [
    'berita', 'football', 'basket', 'f1', 'artikel', 'pencarian', 'about', 'tentang-kami', 'index', 'kontak',
];

function wpm_ensure_special_pages_table(PDO $pdo): void
{
    $created = cms_ensure_table(
        $pdo,
        'special_pages',
        "special_page_id INT AUTO_INCREMENT PRIMARY KEY,
         page_key VARCHAR(50) NOT NULL UNIQUE,
         title VARCHAR(150) NOT NULL,
         slug VARCHAR(150) NOT NULL UNIQUE,
         content MEDIUMTEXT NULL,
         meta_title VARCHAR(150) NULL,
         meta_description VARCHAR(300) NULL,
         show_in_menu TINYINT(1) NOT NULL DEFAULT 1,
         show_in_footer TINYINT(1) NOT NULL DEFAULT 1,
         sort_order INT NOT NULL DEFAULT 0,
         status ENUM('published','draft') NOT NULL DEFAULT 'draft',
         created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
         updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"
    );

    if ($created) {
        $pdo->prepare(
            'INSERT INTO special_pages (page_key, title, slug, content, show_in_menu, show_in_footer, sort_order, status)
             VALUES (:page_key, :title, :slug, :content, 1, 0, 100, :status)'
        )->execute([
            'page_key' => 'contact',
            'title' => 'Kontak',
            'slug' => 'kontak',
            'content' => 'Tertarik kerja sama, kirim rilis berita, atau gabung komunitas pembaca Sagagoal? Kirim pesan lewat form di bawah.',
            'status' => 'published',
        ]);
    }
}

/** All special pages, admin-configured order — for the admin Special Pages list. */
function wpm_special_pages_all(PDO $pdo): array
{
    wpm_ensure_special_pages_table($pdo);
    return $pdo->query('SELECT * FROM special_pages ORDER BY sort_order ASC, title ASC')->fetchAll();
}

function wpm_special_page_by_id(PDO $pdo, int $id): ?array
{
    wpm_ensure_special_pages_table($pdo);
    $stmt = $pdo->prepare('SELECT * FROM special_pages WHERE special_page_id = :id LIMIT 1');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Published page for a given public slug — used by page.php's router. */
function wpm_special_page_by_slug(PDO $pdo, string $slug): ?array
{
    wpm_ensure_special_pages_table($pdo);
    $stmt = $pdo->prepare("SELECT * FROM special_pages WHERE slug = :slug AND status = 'published' LIMIT 1");
    $stmt->execute(['slug' => $slug]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Published, menu-visible pages in sort order — used by wpm_nav_menu(). */
function wpm_special_pages_for_menu(PDO $pdo): array
{
    wpm_ensure_special_pages_table($pdo);
    return $pdo->query(
        "SELECT * FROM special_pages WHERE show_in_menu = 1 AND status = 'published' ORDER BY sort_order ASC, title ASC"
    )->fetchAll();
}

/** Published, footer-visible pages in sort order — used by the footer template. */
function wpm_special_pages_for_footer(PDO $pdo): array
{
    wpm_ensure_special_pages_table($pdo);
    return $pdo->query(
        "SELECT * FROM special_pages WHERE show_in_footer = 1 AND status = 'published' ORDER BY sort_order ASC, title ASC"
    )->fetchAll();
}

/**
 * True if $slug collides with a hardcoded route/file. $ownSlug (the
 * record's own current slug, when editing) is exempted so a page doesn't
 * get locked out by owning a slug that's also on the reserved list (e.g.
 * the seeded Kontak page owns 'kontak').
 */
function wpm_special_page_slug_is_reserved(string $slug, ?string $ownSlug = null): bool
{
    if ($ownSlug !== null && $slug === $ownSlug) {
        return false;
    }
    return in_array($slug, WPM_SPECIAL_PAGE_RESERVED_SLUGS, true);
}
