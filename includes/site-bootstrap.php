<?php
declare(strict_types=1);

/**
 * Sagagoal public front-end — shared bootstrap.
 *
 * Included by every root-level public page (index.php, artikel.php,
 * kategori.php, pencarian.php). Self-contained: only depends on
 * cms-admin/config/database.php — never on cms-admin/includes/auth.php,
 * so the public site keeps working independently of admin login state.
 */

// Marks that bootstrap has run — site-header.php / site-footer.php check
// this and refuse to render if someone requests them directly by URL.
define('WPM_BOOTSTRAPPED', true);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../cms-admin/config/database.php';
require_once __DIR__ . '/../cms-admin/includes/schema-guard.php';
require_once __DIR__ . '/TimeHelpers.php';

function wpm_esc(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/** Small inline SVG icon set used across every public page (stroke/fill = currentColor). */
function wpm_icon(string $name): string
{
    static $icons = [
        'news' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='4' width='14' height='16' rx='1.5'/><path d='M17 8h3v10a2 2 0 0 1-2 2H7'/><line x1='6.5' y1='8' x2='13.5' y2='8'/><line x1='6.5' y1='11.5' x2='13.5' y2='11.5'/><line x1='6.5' y1='15' x2='11' y2='15'/></svg>",
        'chart' => "<svg viewBox='0 0 24 24' fill='currentColor'><rect x='3' y='13' width='4' height='8' rx='1'/><rect x='10' y='7' width='4' height='14' rx='1'/><rect x='17' y='10' width='4' height='11' rx='1'/></svg>",
        'eye' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z'/><circle cx='12' cy='12' r='3'/></svg>",
        'book' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M4 5.5A2.5 2.5 0 0 1 6.5 3H12v18H6.5A2.5 2.5 0 0 1 4 18.5v-13Z'/><path d='M20 5.5A2.5 2.5 0 0 0 17.5 3H12v18h5.5a2.5 2.5 0 0 0 2.5-2.5v-13Z'/></svg>",
        'search' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.8' stroke-linecap='round' stroke-linejoin='round'><circle cx='11' cy='11' r='7'/><line x1='21' y1='21' x2='16.2' y2='16.2'/></svg>",
        'megaphone' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M3 10v4h3l8 5V5l-8 5H3Z'/><path d='M18 9c1.2 1 1.2 5 0 6'/><path d='M21 7c2 2.2 2 7.8 0 10'/></svg>",
        'rocket' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M12 2c3 1.5 5 5 5 9 0 2-1 4-1 4l-4 3-4-3s-1-2-1-4c0-4 2-7.5 5-9Z'/><circle cx='12' cy='10' r='1.6'/><path d='M8.5 15 6 21l4-2'/><path d='M15.5 15 18 21l-4-2'/></svg>",
        'network' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><circle cx='5' cy='6' r='2.2'/><circle cx='19' cy='6' r='2.2'/><circle cx='12' cy='18' r='2.2'/><path d='M6.9 7.3 10.3 16.3'/><path d='M17.1 7.3 13.7 16.3'/><path d='M7.2 6h9.6'/></svg>",
        'calendar' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='5' width='18' height='16' rx='2'/><line x1='3' y1='10' x2='21' y2='10'/><line x1='7' y1='2.5' x2='7' y2='6.5'/><line x1='17' y1='2.5' x2='17' y2='6.5'/></svg>",
        'info' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='9'/><line x1='12' y1='11' x2='12' y2='16.5'/><circle cx='12' cy='7.5' r='0.9' fill='currentColor' stroke='none'/></svg>",
        'football' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='9'/><path d='M12 8.2 15.5 10.6 14.2 14.8H9.8L8.5 10.6Z'/><path d='M12 3v5.2M6.5 8.4 3.3 9.8M17.5 8.4l3.2 1.4M9.8 14.8 8 20.3M14.2 14.8l1.8 5.5'/></svg>",
        'basketball' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='9'/><path d='M3 12h18M12 3v18'/><path d='M5.3 5.3c3 3 3 10.4 0 13.4M18.7 5.3c-3 3-3 10.4 0 13.4'/></svg>",
        'motorsport' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'><rect x='4' y='5' width='16' height='14' rx='1.5'/><path d='M4 9h4v4H4ZM12 9h4v4h-4ZM8 13h4v4H8ZM16 13h4v-4'/></svg>",
        'mail' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><rect x='3' y='5' width='18' height='14' rx='2'/><path d='m4 6.5 8 6.5 8-6.5'/></svg>",
        'chat' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M4 20l1.2-3.6A8 8 0 1 1 8.8 19L4 20Z'/><line x1='8' y1='11' x2='16' y2='11'/><line x1='8' y1='14.2' x2='13.5' y2='14.2'/></svg>",
        'pin' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M12 21s7-6.4 7-12a7 7 0 0 0-14 0c0 5.6 7 12 7 12Z'/><circle cx='12' cy='9' r='2.4'/></svg>",
        'clock' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><circle cx='12' cy='12' r='9'/><path d='M12 7v5l3.5 2'/></svg>",
        'tag' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M20.6 12.3 12 20.9a2 2 0 0 1-2.8 0l-6.1-6.1a2 2 0 0 1 0-2.8L11.7 3.4a2 2 0 0 1 1.4-.6H19a2 2 0 0 1 2 2v5.9a2 2 0 0 1-.4 1Z'/><circle cx='16' cy='8' r='1.4'/></svg>",
        'share' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><circle cx='6' cy='12' r='2.6'/><circle cx='18' cy='6' r='2.6'/><circle cx='18' cy='18' r='2.6'/><path d='m8.3 10.7 7.4-4.4M8.3 13.3l7.4 4.4'/></svg>",
        'trophy' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M8 4h8v5a4 4 0 0 1-8 0V4Z'/><path d='M8 5H5a2 2 0 0 0 2 4M16 5h3a2 2 0 0 1-2 4'/><path d='M12 13v3M9 20h6M9.5 20l-.5-4h6l-.5 4'/></svg>",
        'arrow-left' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.9' stroke-linecap='round' stroke-linejoin='round'><path d='M19 12H5M11 6l-6 6 6 6'/></svg>",
        'arrow-right' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.9' stroke-linecap='round' stroke-linejoin='round'><path d='M5 12h14M13 6l6 6-6 6'/></svg>",
        'flame' => "<svg viewBox='0 0 24 24' fill='none' stroke='currentColor' stroke-width='1.7' stroke-linecap='round' stroke-linejoin='round'><path d='M12 2c1.5 3 0 4.5-1 6-1.4 2-2 3.5-2 5.5A5 5 0 0 0 14 18a4 4 0 0 0 2-7.5c1.7 1 2 3 2 4.5A7 7 0 0 1 7 15.5C6.5 11 9 9 9 6.5 9 5 10.5 3 12 2Z'/></svg>",
        'apple' => "<svg viewBox='0 0 24 24' fill='currentColor'><path d='M17.4 12.4c0-2.2 1.8-3.3 1.9-3.3-1-1.5-2.6-1.7-3.2-1.7-1.4-.1-2.7.8-3.4.8-.7 0-1.8-.8-2.9-.8-1.5 0-2.9.9-3.7 2.2-1.6 2.7-.4 6.7 1.1 8.9.8 1.1 1.6 2.3 2.8 2.2 1.1 0 1.6-.7 2.9-.7s1.7.7 2.9.7c1.2 0 2-1.1 2.7-2.2.9-1.2 1.2-2.4 1.2-2.5-.1 0-2.3-.9-2.3-3.6Z'/><path d='M14.9 5.9c.6-.7 1-1.8.9-2.8-.9.1-2 .6-2.6 1.3-.6.7-1.1 1.7-.9 2.7 1 .1 2-.5 2.6-1.2Z'/></svg>",
        // Official 4-color Google Play triangle mark (25 Jul 2026 — was a
        // plain monochrome play-triangle placeholder before). Apple's own
        // brand guideline keeps their logo strictly monochrome, so 'apple'
        // above is intentionally left as-is rather than forced into color.
        'google-play' => "<svg viewBox='0 0 512 512'><path fill='#00d2ff' d='M99.617 8.057a50.191 50.191 0 0 0-38.815-6.713l230.163 230.163 74.826-74.826L99.617 8.057z'/><path fill='#00f076' d='M32.139 20.116c-6.441 6.581-10.148 15.831-10.148 27.375v417.019c0 11.543 3.708 20.793 10.148 27.375l4.905 4.383 230.163-230.163v-4.63L37.044 15.733l-4.905 4.383z'/><path fill='#ffbc00' d='M295.895 158.87l-74.826-74.826L37.044 15.733v453.923L221.069 285.98l74.826-74.826z'/><path fill='#ff3141' d='M60.802 502.657a50.191 50.191 0 0 0 38.815-6.713L410.906 219.86l-74.826-74.826L60.802 502.657z'/></svg>",
        'moon' => "<svg viewBox='0 0 24 24' fill='currentColor'><path d='M20.4 14.7A8.5 8.5 0 0 1 9.3 3.6a.8.8 0 0 0-1-1A10 10 0 1 0 21.4 15.7a.8.8 0 0 0-1-1Z'/></svg>",
    ];

    return $icons[$name] ?? '';
}

/** Trim HTML content down to a plain-text excerpt of roughly $len chars. */
function wpm_excerpt(string $html, int $len = 160): string
{
    $text = trim(preg_replace('/\s+/', ' ', strip_tags($html)) ?? '');
    if (mb_strlen($text) <= $len) {
        return $text;
    }
    return mb_substr($text, 0, $len) . '…';
}

/**
 * Human date formatter for article/page timestamps (pages.published_at/
 * created_at) ONLY — despite the old "shared by every ... match card"
 * claim in this docblock, match/game/race kickoff times use the SEPARATE
 * wpm_format_match_time()/wpm_match_time_wib() helpers in TimeHelpers.php
 * (those are stored naive-UTC, a different convention — see that file's
 * own docblock). Conflating the two here would silently mis-render
 * whichever one doesn't match this function's assumption.
 *
 * $value is treated as an Asia/Jakarta wall-clock string (WIB) — pages.
 * published_at is written that way (cms_growth_agent_auto_publish_draft(),
 * pages.php's own publish handler), so parsing must say so EXPLICITLY
 * rather than relying on strtotime()'s implicit PHP-default-timezone
 * interpretation (9 Aug 2026 fix — a naive strtotime()/date() round-trip
 * happens to come out correct whenever the read and write side both
 * default to the exact same timezone, which made this bug invisible in
 * this environment specifically, but not something to depend on being
 * true everywhere this code runs).
 */
function wpm_format_date(?string $value, string $fmt = 'd M Y'): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        return (new DateTime($value, new DateTimeZone(WPM_MATCH_TZ)))->format($fmt);
    } catch (Throwable $e) {
        return $value;
    }
}

/**
 * Relative time in Bahasa Indonesia (e.g. "5 menit yang lalu", "3 jam yang
 * lalu", "2 hari yang lalu"). Falls back to an absolute date once older
 * than a week, since "52 minggu yang lalu" stops being useful.
 *
 * Same Asia/Jakarta-explicit parsing as wpm_format_date() above, same
 * 9 Aug 2026 fix, same reasoning — $ts needs to be the correct absolute
 * moment (not shifted by whatever the default timezone happens to be) for
 * the time()-$ts diff below to produce a truthful "N jam/hari yang lalu".
 */
function wpm_time_ago(?string $value): string
{
    if ($value === null || $value === '') {
        return '—';
    }
    try {
        $ts = (new DateTime($value, new DateTimeZone(WPM_MATCH_TZ)))->getTimestamp();
    } catch (Throwable $e) {
        return $value;
    }
    $diff = time() - $ts;
    if ($diff < 0) {
        return wpm_format_date($value);
    }
    if ($diff < 60) {
        return 'Baru saja';
    }
    if ($diff < 3600) {
        return (int) floor($diff / 60) . ' menit yang lalu';
    }
    if ($diff < 86400) {
        return (int) floor($diff / 3600) . ' jam yang lalu';
    }
    if ($diff < 604800) {
        return (int) floor($diff / 86400) . ' hari yang lalu';
    }
    return wpm_format_date($value);
}

/**
 * Resolve a stored media path (e.g. "/uploads/media/2026/07/x.webp") into a
 * usable src, or null. Media paths are stored root-relative (leading
 * slash), which — unlike the app's own relative links — is NOT covered by
 * the <base> tag (absolute-path URLs ignore <base>'s path and resolve
 * straight from the domain root). So when the app is mounted in a
 * subfolder (e.g. local dev at /wpm/), we prefix it with the detected app
 * base path here; in production at the domain root that prefix is empty
 * and this is a no-op. Full external URLs (http/https) pass through untouched.
 */
function wpm_image(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }
    if (preg_match('#^https?://#i', $path) === 1) {
        return $path;
    }
    if ($path[0] === '/') {
        return wpm_base_path() . $path;
    }
    return $path;
}

/**
 * Detect the URL path the app is mounted under — '' at the domain root
 * (production), or e.g. '/wpm' when running from a subfolder locally.
 * Reads $_SERVER['SCRIPT_NAME'], which PHP/Apache always resolve to the
 * PHYSICAL script that actually ran (e.g. "/wpm/artikel.php") even when
 * the request came in through a rewritten clean URL like
 * "/wpm/artikel/some-slug" — so this stays correct under both.
 */
function wpm_base_path(): string
{
    $scriptName = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? '/'));
    return rtrim(dirname($scriptName), '/');
}

/** Build an absolute URL for the current host — used for canonical/OG tags. */
function wpm_site_url(string $path = ''): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'sagagoal.com';
    return $scheme . '://' . $host . wpm_base_path() . '/' . ltrim($path, '/');
}

/**
 * Absolute <base href> for the current page — makes every relative link,
 * asset src, and form action on the page resolve against the SITE ROOT
 * instead of the current URL's own path. Needed because clean URLs like
 * /artikel/<slug> put the browser one path segment "deeper" than the old
 * /artikel.php?slug=... ever was, which otherwise breaks every relative
 * asset path (assets/css/site.css would resolve to
 * /artikel/assets/css/site.css instead of /assets/css/site.css). Emitted
 * as the very first thing in <head> in includes/site-header.php.
 */
function wpm_base_href(): string
{
    return wpm_site_url();
}

/**
 * Clean-URL builders — pair with the rewrite rules in the root .htaccess.
 * Every internal link/canonical tag should go through these instead of
 * hand-writing "artikel.php?slug=..." so the whole site stays consistent
 * if the URL scheme ever needs to change again. Always return a relative
 * path (no leading slash) so links keep working whether the app sits at
 * the domain root (production) or a subfolder (local dev) — same
 * portability approach the rewrite rules themselves use.
 */
function wpm_url_artikel(string $slug): string
{
    return 'artikel/' . rawurlencode($slug);
}

/** Berita listing — /berita, or /berita/kategori/<slug> for one category. */
function wpm_url_kategori(?string $slug = null): string
{
    return ($slug !== null && $slug !== '') ? 'berita/kategori/' . rawurlencode($slug) : 'berita';
}

/** Berita listing filtered by tag — /berita/tag/<slug>. */
function wpm_url_tag(string $slug): string
{
    return 'berita/tag/' . rawurlencode($slug);
}

function wpm_url_pencarian(?string $query = null): string
{
    return ($query !== null && $query !== '') ? 'pencarian?q=' . rawurlencode($query) : 'pencarian';
}

/** Football livescore & jadwal — /football (renamed from /livescore 24 Jul 2026: "livescore" is a generic term every sport needs its own version of, not a page name). */
function wpm_url_football(): string
{
    return 'football';
}

/** Basketball (NBA) livescore & jadwal — /basket (renamed from /nba 24 Jul 2026, same reasoning as wpm_url_football()). */
function wpm_url_basket(): string
{
    return 'basket';
}

/** Formula 1 calendar & standings — /f1. Footer-only placement (not in main nav) — see wpm_nav_menu(). */
function wpm_url_f1(): string
{
    return 'f1';
}

function wpm_url_tentang(): string
{
    return 'tentang-kami';
}

/**
 * Global site settings (name, tagline, logo, contact info, SEO defaults) —
 * managed from the admin panel's Site Settings page (cms-admin/pages/
 * site-settings.php), stored in the singleton `site_settings` table.
 * Cached per-request (static var) since the header, footer, and the Kontak
 * section on index.php all need it. Never throws — returns [] on any DB
 * error/missing table, callers are expected to fall back per-field.
 */
function wpm_site_settings(PDO $pdo): array
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    try {
        $row = $pdo->query('SELECT * FROM site_settings LIMIT 1')->fetch();
        $cached = $row !== false ? $row : [];
    } catch (Throwable $e) {
        $cached = [];
    }
    return $cached;
}

/**
 * Shared site nav — used by both the desktop bar and mobile drawer.
 *
 * Restructured 24 Jul 2026 (Special Pages / Sports Modules spec).
 * Fixed items are just Beranda and Berita now — sport items come from
 * `sports_api_settings WHERE nav_placement='menu'` (Fase 2) so admins can
 * reorder/hide them without a code change (Formula 1 defaults to
 * footer-only there, not omitted here), and Tentang Kami/Kontak (and any
 * future special page with show_in_menu=1) come from `special_pages` —
 * About was migrated INTO Special Pages (24 Jul 2026, v2 of the spec) so
 * it no longer gets its own hardcoded line here, avoiding a duplicate
 * entry once its row exists. The old /olahraga hub this used to route
 * through was deleted in Fase 1.
 */
function wpm_nav_menu(PDO $pdo): array
{
    $items = [
        ['id' => 'beranda', 'label' => 'Beranda', 'href' => wpm_site_url('')],
    ];
    foreach (wpm_sports_modules_by_placement($pdo, 'menu') as $module) {
        $items[] = ['id' => (string) $module['sport_key'], 'label' => (string) $module['label'], 'href' => (string) $module['route_slug']];
    }
    $items[] = ['id' => 'berita', 'label' => 'Berita', 'href' => wpm_url_kategori()];
    foreach (wpm_special_pages_for_menu($pdo) as $specialPage) {
        $items[] = ['id' => 'special-' . (string) $specialPage['page_key'], 'label' => (string) $specialPage['title'], 'href' => (string) $specialPage['slug']];
    }
    return $items;
}

/** True when at least one fixture is currently in-play (first half, half-time, or second half). */
function wpm_has_live_fixtures(PDO $pdo): bool
{
    return wpm_count_live_fixtures($pdo) > 0;
}

/** Count of fixtures currently in play — powers the "Live (N)" toggle on football.php. */
function wpm_count_live_fixtures(PDO $pdo): int
{
    try {
        $stmt = $pdo->query("SELECT COUNT(*) FROM fixtures WHERE status_short IN ('1H','HT','2H','ET','P')");
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Active leagues for the homepage filter row, in admin-configured order. */
function wpm_active_leagues(PDO $pdo): array
{
    try {
        $stmt = $pdo->query('SELECT id, name, logo, country, current_season FROM leagues WHERE is_active = 1 ORDER BY sort_order ASC, name ASC');
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

// Sport registry (wpm_all_sports(), wpm_ensure_sports_table()) — pulled
// out to includes/SportsRegistry.php so backend-only settings classes
// (BasketballSettings, etc.) can use it without loading this whole file.
require_once __DIR__ . '/SportsRegistry.php';

// Sports API Settings (wpm_nav_menu()'s dynamic menu/footer sport items,
// cron gating) — see includes/SportsApiSettings.php's docblock.
require_once __DIR__ . '/SportsApiSettings.php';

// Special Pages (wpm_nav_menu()'s dynamic Kontak/FAQ/etc. menu items,
// footer items, and page.php's router) — see includes/SpecialPages.php's
// docblock.
require_once __DIR__ . '/SpecialPages.php';

/**
 * Site-wide breaking-news ticker fragment — latest published headlines,
 * scrolling marquee. Returns '' when there are no published articles yet.
 */
function wpm_breaking_news_markup(PDO $pdo, int $limit = 8): string
{
    try {
        $stmt = $pdo->prepare("SELECT title, slug FROM pages WHERE status = 'published' ORDER BY published_at DESC LIMIT $limit");
        $stmt->execute();
        $articles = $stmt->fetchAll();
    } catch (Throwable $e) {
        $articles = [];
    }

    if ($articles === []) {
        return '';
    }

    $html = '<div class="breaking-ticker">';
    $html .= '<span class="breaking-ticker__label"><span class="dot" aria-hidden="true"></span> Breaking</span>';
    $html .= '<div class="breaking-ticker__track"><div class="breaking-ticker__scroll">';
    foreach (array_merge($articles, $articles) as $article) {
        $html .= '<a href="' . wpm_esc(wpm_url_artikel((string) $article['slug'])) . '">' . wpm_esc((string) $article['title']) . '</a>';
    }
    $html .= '</div></div></div>';

    return $html;
}

/* ── Advertisement rendering ─────────────────────────────────────────────
 * Every ad slot on the public site goes through wpm_render_ad_slot(). It
 * looks up the best-matching active ad for a position + placement scope,
 * counts an impression, and renders the right markup for the ad type.
 * Never throws — a missing/broken ads table just means no ad renders.
 */

function wpm_ad_settings(PDO $pdo): array
{
    try {
        $row = $pdo->query('SELECT * FROM ad_settings ORDER BY id ASC LIMIT 1')->fetch();
        return $row !== false ? $row : [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Sniffs the visitor's device class from the User-Agent for server-side
 * ad device targeting. Previously every call site either omitted the
 * $device argument or passed the literal 'all' sentinel, which the SQL
 * (`a.device = 'all' OR a.device = :device`) then matched ONLY against
 * device='all' rows — desktop-only/mobile-only ads never rendered for
 * anyone, regardless of who was actually visiting. wpm_render_ad_slot()
 * and wpm_ad_pick() now auto-resolve a null $device through this
 * function, so every existing call site starts filtering correctly
 * without needing its arguments changed.
 *
 * Deliberately simple (a handful of UA substrings) — good enough for ad
 * targeting, not meant to be a full device-detection library.
 */
function wpm_detect_device(): string
{
    $ua = strtolower((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
    if ($ua === '') {
        return 'desktop';
    }
    if (preg_match('/ipad|tablet|nexus 7|nexus 10|kindle|playbook|(android(?!.*mobile))/i', $ua) === 1) {
        return 'tablet';
    }
    if (preg_match('/mobile|iphone|ipod|android|blackberry|opera mini|iemobile|windows phone/i', $ua) === 1) {
        return 'mobile';
    }
    return 'desktop';
}

/**
 * Picks the best-matching ad for a position, resolving ties (same
 * sort_order) according to Ad Settings' rotation mode: priority (first by
 * most-recently-created — the original behaviour), random, or a
 * stateless "sequential" approximation (no session/cookie plumbing
 * needed — spreads impressions across tied ads based on their own
 * impression counts so far).
 */
function wpm_ad_rotate(PDO $pdo, array $rows): ?array
{
    if ($rows === []) {
        return null;
    }
    if (count($rows) === 1) {
        return $rows[0];
    }

    $bestSortOrder = (int) $rows[0]['sort_order'];
    $tied = array_values(array_filter($rows, static fn (array $r): bool => (int) $r['sort_order'] === $bestSortOrder));
    if (count($tied) === 1) {
        return $tied[0];
    }

    $mode = 'priority';
    try {
        $settings = wpm_ad_settings($pdo);
        $mode = (string) ($settings['rotation_mode'] ?? 'priority');
    } catch (Throwable $e) {
        // Fall through with 'priority'.
    }

    if ($mode === 'random') {
        return $tied[array_rand($tied)];
    }

    if ($mode === 'sequential') {
        $totalImpressions = array_sum(array_map(static fn (array $r): int => (int) $r['impressions'], $tied));
        return $tied[$totalImpressions % count($tied)];
    }

    // priority (default): keep the original ORDER BY id DESC tie-break.
    return $tied[0];
}

function wpm_ad_pick(PDO $pdo, string $positionSlug, string $scope = 'global', ?int $targetId = null, ?string $device = null): ?array
{
    try {
        $device = $device ?? wpm_detect_device();
        $sql = 'SELECT a.* FROM advertisements a
                INNER JOIN ad_positions p ON p.id = a.position_id
                WHERE p.slug = :slug
                  AND a.is_active = 1
                  AND (a.start_date IS NULL OR a.start_date <= CURDATE())
                  AND (a.end_date IS NULL OR a.end_date >= CURDATE())
                  AND (a.device = \'all\' OR a.device = :device)
                  AND (
                        a.placement_scope = \'global\'
                        OR (a.placement_scope = :scope AND (a.placement_target_id IS NULL OR a.placement_target_id = :targetId))
                      )
                ORDER BY a.sort_order ASC, a.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'slug' => $positionSlug,
            'device' => $device,
            'scope' => $scope,
            'targetId' => $targetId,
        ]);
        return wpm_ad_rotate($pdo, $stmt->fetchAll());
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Same matching rules as wpm_ad_pick(), but returns EVERY matching active
 * ad instead of picking just one via wpm_ad_rotate() (11 Agu 2026,
 * operator request — "if 2 ads share a scope, show both stacked" — only
 * for sidebar positions, see wpm_render_ad_slot()'s $stackPositions list;
 * every other position keeps the original single-pick rotation behavior
 * unchanged, so a crowded position like Header/Popup never silently
 * starts stacking multiple banners on top of each other).
 *
 * @return list<array<string, mixed>>
 */
function wpm_ad_pick_all(PDO $pdo, string $positionSlug, string $scope = 'global', ?int $targetId = null, ?string $device = null): array
{
    try {
        $device = $device ?? wpm_detect_device();
        $sql = 'SELECT a.* FROM advertisements a
                INNER JOIN ad_positions p ON p.id = a.position_id
                WHERE p.slug = :slug
                  AND a.is_active = 1
                  AND (a.start_date IS NULL OR a.start_date <= CURDATE())
                  AND (a.end_date IS NULL OR a.end_date >= CURDATE())
                  AND (a.device = \'all\' OR a.device = :device)
                  AND (
                        a.placement_scope = \'global\'
                        OR (a.placement_scope = :scope AND (a.placement_target_id IS NULL OR a.placement_target_id = :targetId))
                      )
                ORDER BY a.sort_order ASC, a.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'slug' => $positionSlug,
            'device' => $device,
            'scope' => $scope,
            'targetId' => $targetId,
        ]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function wpm_ad_pick_by_position_id(PDO $pdo, int $positionId, string $scope = 'global', ?int $targetId = null): ?array
{
    try {
        $sql = 'SELECT a.* FROM advertisements a
                WHERE a.position_id = :pid
                  AND a.is_active = 1
                  AND (a.start_date IS NULL OR a.start_date <= CURDATE())
                  AND (a.end_date IS NULL OR a.end_date >= CURDATE())
                  AND (
                        a.placement_scope = \'global\'
                        OR (a.placement_scope = :scope AND (a.placement_target_id IS NULL OR a.placement_target_id = :targetId))
                      )
                ORDER BY a.sort_order ASC, a.id DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute(['pid' => $positionId, 'scope' => $scope, 'targetId' => $targetId]);
        return wpm_ad_rotate($pdo, $stmt->fetchAll());
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Renders the "Ad ·  domain" text-format unit (ad_type='text'). Kept as
 * its own function (rather than another branch inline in wpm_ad_markup())
 * since it has more moving parts than the other types: an optional
 * "advertiser · domain" disclosure line, a required headline, an optional
 * description and CTA, and a $posClass modifier so CSS can lay it out
 * differently per position (see section 4 of the brief — header/sidebar/
 * inline/footer all look different, driven entirely by CSS, not by
 * generating different markup per position).
 */
function wpm_text_ad_markup(array $ad, string $label, string $posClass, int $id, string $clickHref): string
{
    $headline = trim((string) ($ad['headline'] ?? ''));
    if ($headline === '') {
        // A text ad with no headline has nothing worth showing — treat as absent.
        return '';
    }

    $advertiserLabel = trim((string) ($ad['advertiser_label'] ?? '')) ?: 'Ad';
    $displayDomain   = trim((string) ($ad['display_domain'] ?? ''));
    $description     = trim((string) ($ad['description'] ?? ''));
    $ctaText         = trim((string) ($ad['cta_text'] ?? ''));
    $showSponsored   = (int) ($ad['show_sponsored_label'] ?? 1) === 1;
    $newTab          = (int) ($ad['open_in_new_tab'] ?? 1) === 1;

    $meta = '';
    if ($showSponsored) {
        $meta = '<span class="text-ad__meta"><span class="text-ad__badge">' . wpm_esc($advertiserLabel) . '</span>';
        if ($displayDomain !== '') {
            $meta .= '<span class="text-ad__dot" aria-hidden="true">&middot;</span><span class="text-ad__domain">' . wpm_esc($displayDomain) . '</span>';
        }
        $meta .= '</span>';
    }

    $descHtml = $description !== '' ? '<span class="text-ad__desc">' . wpm_esc($description) . '</span>' : '';
    $ctaHtml  = $ctaText !== '' ? '<span class="text-ad__cta">' . wpm_esc($ctaText) . ' <span aria-hidden="true">&rarr;</span></span>' : '';
    $targetAttr = $newTab ? ' target="_blank"' : '';

    return '<a class="ad-slot ad-slot--text' . $posClass . '" data-ad-id="' . $id . '" href="' . wpm_esc($clickHref) . '"' . $targetAttr . ' rel="sponsored nofollow noopener">'
        . $label
        . '<span class="text-ad">' . $meta . '<span class="text-ad__headline">' . wpm_esc($headline) . '</span>' . $descHtml . $ctaHtml . '</span>'
        . '</a>';
}

function wpm_ad_markup(array $ad, bool $showLabel, string $positionSlug = ''): string
{
    $id = (int) $ad['id'];
    $clickHref = 'cms-admin/actions/ad-click.php?id=' . $id;
    $label = $showLabel ? '<span class="ad-slot__label">Ads</span>' : '';
    $posClass = $positionSlug !== '' ? ' ad-slot--pos-' . preg_replace('/[^a-z0-9-]/', '', strtolower($positionSlug)) : '';
    $adType = (string) ($ad['ad_type'] ?? 'image');

    if ($adType === 'text') {
        return wpm_text_ad_markup($ad, $label, $posClass, $id, $clickHref);
    }

    if ($adType === 'external_code' && !empty($ad['external_code'])) {
        // Trusted admin-authored embed code from an external ad network.
        // Restricted to superadmin at save time (ads.php) — never
        // sanitized/rewritten here, same trust model AdSense-style embeds
        // have always needed.
        return '<div class="ad-slot ad-slot--external' . $posClass . '" data-ad-id="' . $id . '">' . $label . $ad['external_code'] . '</div>';
    }

    if ($adType === 'html' && !empty($ad['html_code'])) {
        // Custom HTML — already sanitized at save time (cms_sanitize_ad_html()).
        return '<div class="ad-slot ad-slot--html' . $posClass . '" data-ad-id="' . $id . '">' . $label . $ad['html_code'] . '</div>';
    }

    if ($adType === 'video' && (!empty($ad['video_path']) || !empty($ad['video_url']))) {
        $src = !empty($ad['video_path']) ? (string) wpm_image((string) $ad['video_path']) : (string) $ad['video_url'];
        $posterPath = !empty($ad['video_poster']) ? (string) wpm_image((string) $ad['video_poster']) : null;
        $poster = $posterPath !== null ? ' poster="' . wpm_esc($posterPath) . '"' : '';
        $muted = (int) ($ad['video_muted'] ?? 1) === 1;
        // Autoplay is only ever honoured when muted — browsers block unmuted
        // autoplay anyway, but this also matches the brief's explicit rule.
        $autoplay = $muted && (int) ($ad['video_autoplay'] ?? 0) === 1;
        $loop = (int) ($ad['video_loop'] ?? 0) === 1;
        $controls = (int) ($ad['video_controls'] ?? 1) === 1;
        // autoplay is intentionally NOT in the static attribute list — a
        // small viewport-aware script (assets/js/site.js) starts/stops
        // playback for [data-autoplay] videos instead, so ads never play
        // while scrolled out of view (see wpm_render_ad_slot doc comment).
        $attrs = ($muted ? ' muted' : '') . ($loop ? ' loop' : '') . ($controls ? ' controls' : '') . ' playsinline preload="none"';
        $dataAutoplay = $autoplay ? ' data-autoplay="1"' : '';
        $cta = !empty($ad['cta_text']) ? '<a class="ad-slot__cta" href="' . wpm_esc($clickHref) . '" target="_blank" rel="sponsored nofollow noopener">' . wpm_esc((string) $ad['cta_text']) . '</a>' : '';
        return '<div class="ad-slot ad-slot--video' . $posClass . '" data-ad-id="' . $id . '">' . $label .
            '<video src="' . wpm_esc($src) . '"' . $poster . $attrs . $dataAutoplay . '></video>' . $cta . '</div>';
    }

    if (!empty($ad['banner_image'])) {
        $cta = !empty($ad['cta_text']) ? '<span class="ad-slot__cta-badge">' . wpm_esc((string) $ad['cta_text']) . '</span>' : '';
        $alt = !empty($ad['image_alt']) ? (string) $ad['image_alt'] : (string) ($ad['title'] ?? $ad['name'] ?? '');
        return '<a class="ad-slot ad-slot--image' . $posClass . '" data-ad-id="' . $id . '" href="' . wpm_esc($clickHref) . '" target="_blank" rel="noopener sponsored">' . $label .
            '<img src="' . wpm_esc((string) wpm_image((string) $ad['banner_image'])) . '" alt="' . wpm_esc($alt) . '" loading="lazy">' . $cta . '</a>';
    }

    return '';
}

/**
 * Positions where multiple simultaneously-active ads sharing a scope
 * should ALL render, stacked, instead of one being picked via rotation
 * (11 Agu 2026, operator request). Deliberately just the two sidebar
 * slots — see wpm_ad_pick_all()'s doc comment for why this isn't
 * site-wide: a narrow vertical column is the one shape on this site
 * that's actually designed to hold more than one stacked banner. Every
 * other position slug keeps the original single-pick-via-rotation
 * behavior below, completely unchanged.
 */
const WPM_AD_STACK_POSITIONS = ['sidebar-left', 'sidebar-right'];

function wpm_render_ad_slot(PDO $pdo, string $positionSlug, string $scope = 'global', ?int $targetId = null, ?string $device = null): string
{
    $settings = wpm_ad_settings($pdo);
    if (!empty($settings) && (int) ($settings['ads_enabled'] ?? 1) !== 1) {
        return '';
    }

    $showLabel = empty($settings) || (int) ($settings['show_ad_label'] ?? 1) === 1;

    if (in_array($positionSlug, WPM_AD_STACK_POSITIONS, true)) {
        $ads = wpm_ad_pick_all($pdo, $positionSlug, $scope, $targetId, $device);
        if ($ads === []) {
            return '';
        }

        $html = '';
        foreach ($ads as $ad) {
            try {
                $pdo->prepare('UPDATE advertisements SET impressions = impressions + 1 WHERE id = :id')->execute(['id' => (int) $ad['id']]);
            } catch (Throwable $e) {
                // Impression tracking is best-effort — never block rendering on it.
            }
            $html .= wpm_ad_markup($ad, $showLabel, $positionSlug);
        }

        return $html;
    }

    $ad = wpm_ad_pick($pdo, $positionSlug, $scope, $targetId, $device);
    if ($ad === null) {
        return '';
    }

    try {
        $pdo->prepare('UPDATE advertisements SET impressions = impressions + 1 WHERE id = :id')->execute(['id' => (int) $ad['id']]);
    } catch (Throwable $e) {
        // Impression tracking is best-effort — never block rendering on it.
    }

    return wpm_ad_markup($ad, $showLabel, $positionSlug);
}

/* ── Promotional banners (cms-admin/pages/banners.php) ──────────────────
 * Content-managed image banners — separate from the Advertisements
 * module above (that one is for paid/third-party ads with impression
 * tracking; this one is for the site's own promo images with an
 * optional title/subtitle/CTA overlay). Wired to the frontend 13 Jul
 * 2026 — previously the `banners` table only existed in the admin panel
 * with zero frontend rendering (confirmed via full codebase grep).
 */

function wpm_banners_active(PDO $pdo, string $placement = 'home'): array
{
    try {
        $stmt = $pdo->prepare(
            'SELECT * FROM banners
             WHERE placement = :placement
               AND is_active = 1
               AND (start_date IS NULL OR start_date <= CURDATE())
               AND (end_date IS NULL OR end_date >= CURDATE())
             ORDER BY sort_order ASC, id DESC'
        );
        $stmt->execute(['placement' => $placement]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function wpm_banner_markup(array $banner): string
{
    $desktopImg = trim((string) ($banner['desktop_image'] ?? ''));
    $mobileImg = trim((string) ($banner['mobile_image'] ?? ''));
    if ($desktopImg === '' && $mobileImg === '') {
        return '';
    }

    $title = trim((string) ($banner['title'] ?? ''));
    $subtitle = trim((string) ($banner['subtitle'] ?? ''));
    $buttonText = trim((string) ($banner['button_text'] ?? ''));
    $buttonUrl = trim((string) ($banner['button_url'] ?? ''));

    $picture = '<picture>';
    if ($mobileImg !== '') {
        $picture .= '<source media="(max-width: 640px)" srcset="' . wpm_esc((string) wpm_image($mobileImg)) . '">';
    }
    $imgSrc = $desktopImg !== '' ? $desktopImg : $mobileImg;
    $picture .= '<img src="' . wpm_esc((string) wpm_image($imgSrc)) . '" alt="' . wpm_esc($title !== '' ? $title : 'Banner') . '" loading="lazy">';
    $picture .= '</picture>';

    $overlay = '';
    if ($title !== '' || $subtitle !== '' || $buttonText !== '') {
        $overlay = '<div class="banner-card__overlay">';
        if ($title !== '') {
            $overlay .= '<span class="banner-card__title">' . wpm_esc($title) . '</span>';
        }
        if ($subtitle !== '') {
            $overlay .= '<span class="banner-card__subtitle">' . wpm_esc($subtitle) . '</span>';
        }
        if ($buttonText !== '') {
            $overlay .= '<span class="banner-card__cta">' . wpm_esc($buttonText) . '</span>';
        }
        $overlay .= '</div>';
    }

    $tag = $buttonUrl !== '' ? 'a' : 'div';
    $hrefAttr = $buttonUrl !== '' ? ' href="' . wpm_esc($buttonUrl) . '"' : '';

    return '<' . $tag . ' class="banner-card"' . $hrefAttr . '>' . $picture . $overlay . '</' . $tag . '>';
}

/**
 * Floating WhatsApp/Telegram chat buttons, bottom-right on every public
 * page (rendered from site-footer.php). Each button only renders if BOTH
 * its own show_*_button toggle is on AND the corresponding field is
 * actually filled in — an admin turning a toggle on with an empty field
 * would otherwise produce a dead button, and a filled-in field with the
 * toggle off must not render at all (not just CSS-hidden), per Site
 * Settings.
 *
 * telegram_username (column name kept for backward compat) stores a full
 * t.me/telegram.me URL now — public username link OR private invite link
 * (link format revised 27 Jul 2026, since a private invite link has no
 * "@username" equivalent to reassemble a link from) — so this renders the
 * stored value directly as the href, it must NOT re-prefix it with
 * "https://t.me/" the way a bare username would have needed. The only
 * normalization done here is adding a missing "https://" scheme, covering
 * two cases: an admin pasting "t.me/+xxxx" without the scheme (Site
 * Settings auto-prefixes this too, but a page may still be rendering a
 * value saved before that existed), and any pre-migration row that still
 * holds a bare username from the old design (e.g. "sagagoal") — the
 * fallback below assumes those become a public t.me/username link, same
 * as the old behavior, so already-configured buttons don't silently break.
 */
function wpm_floating_contact_buttons(array $siteSettings): string
{
    $showWhatsapp = (int) ($siteSettings['show_whatsapp_button'] ?? 0) === 1;
    $whatsappNumber = preg_replace('/\D+/', '', (string) ($siteSettings['whatsapp_number'] ?? ''));
    $showTelegram = (int) ($siteSettings['show_telegram_button'] ?? 0) === 1;
    $telegramUrl = trim((string) ($siteSettings['telegram_username'] ?? ''));
    if ($telegramUrl !== '' && !preg_match('#^https?://#i', $telegramUrl)) {
        $telegramUrl = str_contains($telegramUrl, '/')
            ? 'https://' . $telegramUrl
            : 'https://t.me/' . ltrim($telegramUrl, '@');
    }

    $html = '';

    if ($showWhatsapp && $whatsappNumber !== '') {
        $html .= '<a class="floating-contact__btn floating-contact__btn--whatsapp" href="https://wa.me/' . wpm_esc($whatsappNumber) . '" target="_blank" rel="noopener" aria-label="Chat WhatsApp">'
            . '<svg viewBox="0 0 32 32" fill="currentColor" aria-hidden="true"><path d="M16.001 3C9.1 3 3.5 8.6 3.5 15.5c0 2.36.65 4.57 1.78 6.46L3 29l7.24-2.24a12.4 12.4 0 0 0 5.76 1.42h.01c6.9 0 12.5-5.6 12.5-12.5S22.9 3 16.001 3Zm0 22.83h-.01a10.3 10.3 0 0 1-5.25-1.44l-.38-.22-3.9 1.21 1.24-3.8-.25-.39a10.3 10.3 0 0 1-1.58-5.5c0-5.7 4.63-10.33 10.34-10.33 2.76 0 5.35 1.08 7.3 3.03a10.26 10.26 0 0 1 3.03 7.3c0 5.7-4.64 10.14-10.44 10.14Zm5.66-7.73c-.31-.16-1.83-.9-2.11-1-.28-.1-.49-.16-.7.16-.2.31-.8 1-.98 1.2-.18.21-.36.23-.67.08-.31-.16-1.3-.48-2.47-1.53-.91-.81-1.53-1.82-1.71-2.13-.18-.31-.02-.48.13-.63.14-.14.31-.36.47-.55.16-.18.2-.31.31-.52.1-.21.05-.39-.02-.55-.08-.16-.7-1.69-.96-2.31-.25-.6-.51-.52-.7-.53h-.6c-.2 0-.55.08-.83.39-.28.31-1.09 1.06-1.09 2.6 0 1.53 1.12 3 1.28 3.21.16.21 2.2 3.36 5.34 4.71.75.32 1.33.51 1.78.66.75.24 1.43.2 1.97.13.6-.09 1.83-.75 2.09-1.47.26-.73.26-1.35.18-1.48-.08-.13-.28-.21-.59-.36Z"/></svg>'
            . '</a>';
    }

    if ($showTelegram && $telegramUrl !== '') {
        $html .= '<a class="floating-contact__btn floating-contact__btn--telegram" href="' . wpm_esc($telegramUrl) . '" target="_blank" rel="noopener" aria-label="Chat Telegram">'
            . '<svg viewBox="0 0 240 240" fill="currentColor" aria-hidden="true"><path d="M120 0a120 120 0 1 0 0 240 120 120 0 0 0 0-240Zm54.6 82-18.2 85.8c-1.4 6.1-5 7.6-10.1 4.7l-28-20.6-13.5 13c-1.5 1.5-2.8 2.8-5.6 2.8l2-28.4 51.7-46.7c2.3-2 -0.5-3.2-3.5-1.2l-63.9 40.2-27.5-8.6c-6-1.9-6.1-6 1.2-8.9l107.5-41.4c5-1.9 9.4 1.2 7.9 8.3Z"/></svg>'
            . '</a>';
    }

    if ($html === '') {
        return '';
    }

    return '<div class="floating-contact">' . $html . '</div>';
}

/* ── Article helpers ─────────────────────────────────────────────────── */

/** Renders one <article> card used across homepage/category/related/featured grids. */
function wpm_article_card(array $article, bool $row = false): string
{
    $img = wpm_image($article['featured_image'] ?? null);
    $media = $img !== null
        ? '<img src="' . wpm_esc($img) . '" alt="' . wpm_esc((string) $article['title']) . '" loading="lazy">'
        : wpm_icon('news');
    $category = trim((string) ($article['category_name'] ?? ''));
    $views = (int) ($article['views'] ?? 0);
    $date = wpm_format_date($article['published_at'] ?? null);

    $html = '<article class="glass-card news-card' . ($row ? ' news-card--row' : '') . '">';
    $html .= '<div class="news-card__media">' . $media . '</div>';
    $html .= '<div class="news-card__body">';
    if ($category !== '') {
        $html .= '<span class="article-card__tag">' . wpm_esc($category) . '</span>';
    }
    $html .= '<h3><a href="' . wpm_esc(wpm_url_artikel((string) $article['slug'])) . '">' . wpm_esc((string) $article['title']) . '</a></h3>';
    if (!empty($article['excerpt'])) {
        $html .= '<p>' . wpm_esc(wpm_excerpt((string) $article['excerpt'], 110)) . '</p>';
    }
    $html .= '<div class="news-card__meta"><span>' . wpm_icon('clock') . $date . '</span><span>' . wpm_icon('eye') . $views . '</span></div>';
    $html .= '</div></article>';

    return $html;
}

/** Shared "source · X jam yang lalu" byline used by the hero card, list rows, and trending items. */
function wpm_news_byline(array $article): string
{
    $source = trim((string) ($article['author_name'] ?? ''));
    $time = wpm_time_ago($article['published_at'] ?? ($article['created_at'] ?? null));
    $parts = [];
    if ($source !== '') {
        $parts[] = wpm_esc($source);
    }
    $parts[] = wpm_esc($time);
    return implode(' <span class="news-byline__dot">·</span> ', $parts);
}

/**
 * Big hero card for the top of the news homepage feed — full-width image,
 * bold title below (not overlaid on the image), then source + relative time.
 */
function wpm_news_hero_card(array $article): string
{
    $img = wpm_image($article['featured_image'] ?? null);
    $media = $img !== null
        ? '<img src="' . wpm_esc($img) . '" alt="' . wpm_esc((string) $article['title']) . '" loading="lazy">'
        : wpm_icon('news');

    $html = '<article class="news-hero">';
    $html .= '<a class="news-hero__media" href="' . wpm_esc(wpm_url_artikel((string) $article['slug'])) . '">' . $media . '</a>';
    $html .= '<h2 class="news-hero__title"><a href="' . wpm_esc(wpm_url_artikel((string) $article['slug'])) . '">' . wpm_esc((string) $article['title']) . '</a></h2>';
    $html .= '<div class="news-byline">' . wpm_news_byline($article) . '</div>';
    $html .= '</article>';

    return $html;
}

/** Compact list row — small thumbnail left, title + byline right. Used below the hero. */
function wpm_news_list_row(array $article): string
{
    $img = wpm_image($article['featured_image'] ?? null);
    $media = $img !== null
        ? '<img src="' . wpm_esc($img) . '" alt="' . wpm_esc((string) $article['title']) . '" loading="lazy">'
        : wpm_icon('news');
    $url = wpm_url_artikel((string) $article['slug']);

    $html = '<article class="news-row">';
    $html .= '<a class="news-row__media" href="' . wpm_esc($url) . '">' . $media . '</a>';
    $html .= '<div class="news-row__body">';
    $html .= '<h3 class="news-row__title"><a href="' . wpm_esc($url) . '">' . wpm_esc((string) $article['title']) . '</a></h3>';
    $html .= '<div class="news-byline">' . wpm_news_byline($article) . '</div>';
    $html .= '</div></article>';

    return $html;
}

/** Numbered trending sidebar item — green rank badge, 2-line clamped title, thumbnail right. */
function wpm_trending_item(array $article, int $rank): string
{
    $img = wpm_image($article['featured_image'] ?? null);
    $media = $img !== null
        ? '<img src="' . wpm_esc($img) . '" alt="' . wpm_esc((string) $article['title']) . '" loading="lazy">'
        : wpm_icon('news');
    $url = wpm_url_artikel((string) $article['slug']);

    $html = '<article class="trending-item">';
    $html .= '<span class="trending-item__rank">' . (int) $rank . '</span>';
    $html .= '<div class="trending-item__body">';
    $html .= '<h4 class="trending-item__title"><a href="' . wpm_esc($url) . '">' . wpm_esc((string) $article['title']) . '</a></h4>';
    $html .= '<div class="news-byline news-byline--sm">' . wpm_news_byline($article) . '</div>';
    $html .= '</div>';
    $html .= '<a class="trending-item__media" href="' . wpm_esc($url) . '">' . $media . '</a>';
    $html .= '</article>';

    return $html;
}

/**
 * "Live Sekarang" homepage widget — up to 3 mini fixture cards pulled
 * from whichever sports are currently ACTIVE (sports.is_active = 1),
 * mixed together and sorted by kickoff/game time. Reuses the exact same
 * card renderers as football.php (wpm_fixture_card()) and basket.php
 * (wpm_nba_game_card()) — just visually compacted via the
 * .live-now-widget CSS scope, not a separate component. Formula 1 is
 * deliberately excluded: it has no two-team "vs" score shape to fit this
 * card format (see f1.php's own race-calendar design instead).
 *
 * Returns '' (renders nothing) when there is nothing live anywhere right
 * now — no empty-state here on purpose, so quiet match hours don't leave
 * a dead widget cluttering the homepage.
 */
/**
 * "Aplikasi Sagagoal — Segera Hadir" promo card — shared by tentang.php,
 * page.php, and index.php (homepage) so all three reuse the exact same
 * markup instead of duplicating it.
 *
 * $pdo param added 11 Agu 2026 — this is also the "Halaman Apps" ad scope
 * operators can pick in Advertisements (previously that scope option
 * existed in the dropdown but no page actually requested it, so any ad
 * scoped to it could never show anywhere — see cms-admin/pages/ads.php's
 * $AD_SCOPES). Nullable/optional so any caller that genuinely can't reach
 * a $pdo instance still renders the promo card itself, just without the
 * ad slot below it — degrades gracefully rather than fataling.
 */
function wpm_app_promo_section(?PDO $pdo = null): string
{
    // Position slug 'footer' reused here (not a new position) — this promo
    // card sits directly above the site footer on every page it appears
    // on, and is a full-width block (not a narrow sidebar skyscraper), so
    // the existing full-width 'footer' slot fits its shape better than
    // 'sidebar-right' would.
    $adHtml = $pdo !== null ? wpm_render_ad_slot($pdo, 'footer', 'apps') : '';

    return '
<section class="crypto-section--tight">
    <div class="crypto-container">
        <div class="glass-card crypto-card app-promo-card">
            <div class="crypto-card__icon app-promo-card__icon">' . wpm_icon('rocket') . '</div>
            <h3 class="app-promo-card__title">Aplikasi Sagagoal — Segera Hadir</h3>
            <p class="app-promo-card__subtitle">Pantau live score dan jadwal pertandingan langsung dari genggaman. Aplikasi Android &amp; iOS sedang dalam pengembangan.</p>
            <div class="app-promo-card__badges">
                <span class="crypto-btn crypto-btn--ghost app-store-badge app-store-badge--disabled">
                    <span class="app-store-badge__icon">' . wpm_icon('google-play') . '</span>
                    Google Play — Segera Hadir
                </span>
                <span class="crypto-btn crypto-btn--ghost app-store-badge app-store-badge--disabled">
                    <span class="app-store-badge__icon">' . wpm_icon('apple') . '</span>
                    App Store — Segera Hadir
                </span>
            </div>
        </div>
    </div>' . ($adHtml !== '' ? '
    <div class="crypto-container app-promo-card__ad-wrap">' . $adHtml . '</div>' : '') . '
</section>';
}

function wpm_live_now_widget(PDO $pdo): string
{
    $activeSports = [];
    try {
        $activeSports = $pdo->query("SELECT `key` FROM sports WHERE is_active = 1")->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return '';
    }

    /** @var list<array{sort: int, html: string}> $items */
    $items = [];

    if (in_array('football', $activeSports, true)) {
        try {
            $stmt = $pdo->query(
                "SELECT f.*, ht.name AS home_name, ht.logo AS home_logo,
                        at.name AS away_name, at.logo AS away_logo,
                        l.name AS league_name, l.logo AS league_logo
                 FROM fixtures f
                 JOIN teams ht ON ht.id = f.home_team_id
                 JOIN teams at ON at.id = f.away_team_id
                 JOIN leagues l ON l.id = f.league_id
                 WHERE f.status_short IN ('1H','HT','2H','ET','P')
                 ORDER BY f.kickoff_at ASC
                 LIMIT 5"
            );
            foreach ($stmt->fetchAll() as $fixture) {
                $items[] = ['sort' => (int) strtotime((string) $fixture['kickoff_at']), 'html' => wpm_fixture_card($fixture)];
            }
        } catch (Throwable $e) {
            // fixtures/teams/leagues not ready yet — treat as none live.
        }
    }

    if (in_array('basketball', $activeSports, true)) {
        try {
            $stmt = $pdo->query(
                "SELECT g.*, ht.name AS home_name, ht.logo AS home_logo,
                        at.name AS away_name, at.logo AS away_logo
                 FROM nba_games g
                 JOIN nba_teams ht ON ht.id = g.home_team_id
                 JOIN nba_teams at ON at.id = g.away_team_id
                 WHERE g.status_short = 2
                 ORDER BY g.game_date ASC
                 LIMIT 5"
            );
            foreach ($stmt->fetchAll() as $game) {
                $items[] = ['sort' => (int) strtotime((string) $game['game_date']), 'html' => wpm_nba_game_card($game)];
            }
        } catch (Throwable $e) {
            // nba_games not created yet (no sync has run) — treat as none live.
        }
    }

    if ($items === []) {
        return '';
    }

    usort($items, static fn(array $a, array $b): int => $a['sort'] <=> $b['sort']);
    $items = array_slice($items, 0, 3);

    $html = '<div class="live-now-widget">';
    $html .= '<div class="live-now-widget__head">';
    $html .= '<h2 class="live-now-widget__title"><span class="live-now-widget__dot" aria-hidden="true"></span>Live Sekarang</h2>';
    $html .= '<a class="live-now-widget__link" href="' . wpm_esc(wpm_url_football()) . '">Lihat Semua Livescore →</a>';
    $html .= '</div>';
    $html .= '<div class="live-now-widget__list">';
    foreach ($items as $item) {
        $html .= $item['html'];
    }
    $html .= '</div></div>';

    return $html;
}

/**
 * Sport-filter chip row for the news homepage — "Semua" first, then one
 * chip per ACTIVE sport (sports.is_active = 1). Replaces the old
 * per-league filter row (world cup/premier league/etc.) — that was a
 * leftover from the football-only design; leagues are a livescore/
 * fixtures concern now (grouped inside football.php itself), not a
 * homepage news-browsing one. Clicking a chip filters the article feed
 * by pages.sport_key (see cms-admin/pages/pages.php's "Cabang Olahraga"
 * field). Returns '' if there are no active sports at all (shouldn't
 * happen in practice — football always ships active).
 */
function wpm_sport_filter_row(PDO $pdo, string $tab, ?string $activeSportKey): string
{
    $sports = [];
    try {
        // Read icon from sports table (legacy), but is_active from sports_api_settings
        // (single source of truth for active/inactive status as of 24 Jul 2026). Join
        // by matching sports.key with sports_api_settings.sport_key.
        // article_count via correlated subquery (not a LEFT JOIN + GROUP BY)
        // to keep it independent of the sports_api_settings join above —
        // same published-only rule as the category chip count on kategori.php
        // (COUNT(*) ... WHERE p.status = 'published').
        //
        // `COLLATE utf8mb4_unicode_ci` on the s.`key` side is required, not
        // decorative: sports.key is utf8mb4_general_ci but pages.sport_key
        // is utf8mb4_unicode_ci (columns added at different times), so a
        // direct s.`key` = p.sport_key comparison throws "Illegal mix of
        // collations" — this was never hit before because the only other
        // place these two get compared (index.php's chip-click filter)
        // binds sport_key against a PHP string parameter, not a column.
        $sports = $pdo->query(
            "SELECT s.`key`, s.name, s.icon, a.sort_order,
                    (SELECT COUNT(*) FROM pages p WHERE p.sport_key = s.`key` COLLATE utf8mb4_unicode_ci AND p.status = 'published') AS article_count
             FROM sports s
             INNER JOIN sports_api_settings a ON s.`key` = a.sport_key
             WHERE a.is_active = 1
             ORDER BY a.sort_order ASC, s.name ASC"
        )->fetchAll();
    } catch (Throwable $e) {
        return '';
    }

    if ($sports === []) {
        return '';
    }

    $chipUrl = static function (?string $sportKey) use ($tab): string {
        $url = 'index.php?tab=' . $tab;
        return $sportKey !== null ? $url . '&sport=' . rawurlencode($sportKey) : $url;
    };

    $html = '<div class="news-filter-row">';
    $html .= '<div class="news-filter-row__scroll">';

    $allClass = 'news-filter-pill' . ($activeSportKey === null ? ' is-active' : '');
    $html .= '<a class="' . $allClass . '" href="' . wpm_esc($chipUrl(null)) . '">Semua</a>';

    foreach ($sports as $sport) {
        $isActive = $activeSportKey === (string) $sport['key'];
        $classes = 'news-filter-pill' . ($isActive ? ' is-active' : '');
        $html .= '<a class="' . $classes . '" href="' . wpm_esc($chipUrl((string) $sport['key'])) . '">';
        $html .= wpm_icon((string) ($sport['icon'] ?? 'trophy'));
        // Article count badge removed 11 Agu 2026 (permintaan operator) —
        // was '<span>' . name . ' (' . article_count . ')</span>'. Query
        // that computes $sport['article_count'] (see the SELECT above)
        // deliberately left as-is rather than removed — cheap correlated
        // subquery, and removing it risks breaking something else that
        // might read the same $sports array later; simplest safe fix is
        // just not printing the count anymore.
        $html .= '<span>' . wpm_esc((string) $sport['name']) . '</span>';
        $html .= '</a>';
    }

    $html .= '</div></div>';

    return $html;
}

/* ── Livescore helpers ───────────────────────────────────────────────── */

/** True for the fixture statuses API-Football uses while a match is in progress. */
function wpm_fixture_is_live(string $statusShort): bool
{
    return in_array($statusShort, ['1H', 'HT', '2H', 'ET', 'P'], true);
}

/** Small status badge — kickoff time if not started, live+elapsed if in-play, else the short code. */
function wpm_fixture_status_badge(array $fixture): string
{
    $status = (string) ($fixture['status_short'] ?? 'NS');
    $elapsed = $fixture['elapsed'] ?? null;

    if ($status === 'NS' || $status === 'TBD') {
        return '<span class="fixture-status">' . wpm_esc(wpm_format_match_time((string) ($fixture['kickoff_at'] ?? ''))) . '</span>';
    }
    if (wpm_fixture_is_live($status)) {
        $label = $status === 'HT' ? 'HT' : ($elapsed !== null ? (int) $elapsed . "'" : $status);
        return '<span class="fixture-status fixture-status--live"><span class="fixture-status__dot" aria-hidden="true"></span>' . wpm_esc($label) . '</span>';
    }
    if (in_array($status, ['FT', 'AET', 'PEN'], true)) {
        return '<span class="fixture-status">FT</span>';
    }

    return '<span class="fixture-status fixture-status--muted">' . wpm_esc($status) . '</span>';
}

/** One fixture row — home/away team logo+name, score or "vs", status badge. Used on football.php and league detail's Jadwal tab. */
function wpm_fixture_card(array $fixture): string
{
    $homeLogo = wpm_image($fixture['home_logo'] ?? null);
    $awayLogo = wpm_image($fixture['away_logo'] ?? null);
    $homeName = (string) ($fixture['home_name'] ?? '—');
    $awayName = (string) ($fixture['away_name'] ?? '—');
    $leagueName = (string) ($fixture['league_name'] ?? '');
    $hasScore = $fixture['home_score'] !== null && $fixture['away_score'] !== null;
    $isLive = wpm_fixture_is_live((string) ($fixture['status_short'] ?? ''));

    // data-search backs the client-side "Cari pertandingan" filter on
    // football.php — no server round-trip per keystroke.
    $searchText = mb_strtolower($homeName . ' ' . $awayName . ' ' . $leagueName);

    $html = '<div class="fixture-card' . ($isLive ? ' is-live' : '') . '" data-fixture-id="' . (int) $fixture['id'] . '" data-status="' . wpm_esc((string) ($fixture['status_short'] ?? '')) . '" data-search="' . wpm_esc($searchText) . '">';

    $html .= '<div class="fixture-card__team fixture-card__team--home">';
    $html .= $homeLogo !== null ? '<img src="' . wpm_esc($homeLogo) . '" alt="" loading="lazy">' : wpm_icon('trophy');
    $html .= '<span>' . wpm_esc($homeName) . '</span>';
    $html .= '</div>';

    $html .= '<div class="fixture-card__center">';
    if ($hasScore) {
        $html .= '<div class="fixture-card__score" data-field="score"><span>' . (int) $fixture['home_score'] . '</span><span class="fixture-card__score-sep"></span><span>' . (int) $fixture['away_score'] . '</span></div>';
    } else {
        $html .= '<div class="fixture-card__score fixture-card__score--vs">vs</div>';
    }
    $html .= '<div data-field="status">' . wpm_fixture_status_badge($fixture) . '</div>';
    $html .= '</div>';

    $html .= '<div class="fixture-card__team fixture-card__team--away">';
    $html .= $awayLogo !== null ? '<img src="' . wpm_esc($awayLogo) . '" alt="" loading="lazy">' : wpm_icon('trophy');
    $html .= '<span>' . wpm_esc($awayName) . '</span>';
    $html .= '</div>';

    $html .= '</div>';

    return $html;
}

/**
 * Translates an API-Football `round` string into Indonesian for display.
 * Falls back to the raw string when a pattern isn't recognized — new
 * competitions/round formats degrade gracefully instead of breaking.
 */
function wpm_translate_round(string $round): string
{
    $round = trim($round);
    if ($round === '') {
        return '';
    }

    if (preg_match('/^Regular Season\s*-\s*(\d+)$/i', $round, $m) === 1) {
        return 'Pekan ' . $m[1];
    }
    if (preg_match('/^Group Stage\s*-\s*(\d+)$/i', $round, $m) === 1) {
        return 'Fase Grup - ' . $m[1];
    }
    if (preg_match('/^Round of (\d+)$/i', $round, $m) === 1) {
        return $m[1] . ' Besar';
    }
    if (preg_match('/^(\d+)(?:st|nd|rd|th)\s+Round$/i', $round, $m) === 1) {
        return 'Babak ' . $m[1];
    }

    $map = [
        'group stage' => 'Fase Grup',
        'quarterfinals' => 'Perempat Final',
        'quarter-finals' => 'Perempat Final',
        'semifinals' => 'Semifinal',
        'semi-finals' => 'Semifinal',
        'final' => 'Final',
        '3rd place final' => 'Perunggu',
        'third place final' => 'Perunggu',
        'bronze final' => 'Perunggu',
    ];

    return $map[mb_strtolower($round)] ?? $round;
}

/** "Internasional" for World Cup-style entries (country === 'World'), else the country name as-is. */
function wpm_league_subtitle(?string $country): string
{
    $country = trim((string) $country);
    return ($country === '' || $country === 'World') ? 'Internasional' : $country;
}

/** Groups a flat fixture list (already joined with round) into [['round' => raw, 'round_label' => translated, 'fixtures' => [...]], ...], in first-seen order. */
function wpm_group_fixtures_by_round(array $fixtures): array
{
    $groups = [];
    foreach ($fixtures as $fixture) {
        $round = (string) ($fixture['round'] ?? '');
        if (!isset($groups[$round])) {
            $groups[$round] = [
                'round' => $round,
                'round_label' => wpm_translate_round($round),
                'fixtures' => [],
            ];
        }
        $groups[$round]['fixtures'][] = $fixture;
    }
    return array_values($groups);
}

/** Groups a flat fixture list (already joined with league_name/league_logo/league_country) into per-league groups, each further split into rounds via wpm_group_fixtures_by_round(). */
function wpm_group_fixtures_by_league(array $fixtures): array
{
    $groups = [];
    foreach ($fixtures as $fixture) {
        $leagueId = (int) ($fixture['league_id'] ?? 0);
        if (!isset($groups[$leagueId])) {
            $groups[$leagueId] = [
                'league' => [
                    'id' => $leagueId,
                    'name' => $fixture['league_name'] ?? '—',
                    'logo' => $fixture['league_logo'] ?? null,
                    'country' => $fixture['league_country'] ?? null,
                ],
                'fixtures' => [],
            ];
        }
        $groups[$leagueId]['fixtures'][] = $fixture;
    }

    return array_map(
        static function (array $group): array {
            return [
                'league' => $group['league'],
                'rounds' => wpm_group_fixtures_by_round($group['fixtures']),
            ];
        },
        array_values($groups)
    );
}

/**
 * NBA game-card rendering (basket.php) — deliberately reuses the football
 * fixture-card's CSS classes (.fixture-card, .fixture-card__team, etc.)
 * and data-search/data-status/data-fixture-id attribute contract so
 * assets/js/livescore.js's search/live-toggle/calendar logic works
 * unchanged on basket.php; only the status-code meaning differs (NBA v2
 * uses numeric codes: 1=Not Started, 2=Live, 3=Finished, 4=Postponed,
 * 5=Delayed, 6=Canceled — not football's "1H/HT/2H/ET/P" strings).
 */
function wpm_nba_is_live(int $statusShort): bool
{
    return $statusShort === 2;
}

/** Count of NBA games currently in play — powers the "Live (N)" toggle on basket.php. */
function wpm_count_live_nba_games(PDO $pdo): int
{
    try {
        $stmt = $pdo->query('SELECT COUNT(*) FROM nba_games WHERE status_short = 2');
        return (int) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}

/** Small status badge — tip-off time if not started, live+period if in-play, "FINAL" if done, else the status_long text. */
function wpm_nba_status_badge(array $game): string
{
    $status = (int) ($game['status_short'] ?? 1);

    if ($status === 1) {
        return '<span class="fixture-status">' . wpm_esc(wpm_format_match_time((string) ($game['game_date'] ?? ''))) . '</span>';
    }
    if ($status === 2) {
        $period = $game['period_current'] ?? null;
        $label = $period !== null ? 'Q' . (int) $period : 'LIVE';
        return '<span class="fixture-status fixture-status--live"><span class="fixture-status__dot" aria-hidden="true"></span>' . wpm_esc($label) . '</span>';
    }
    if ($status === 3) {
        return '<span class="fixture-status">FINAL</span>';
    }

    $labels = [4 => 'Ditunda', 5 => 'Delay', 6 => 'Dibatalkan'];
    $label = $labels[$status] ?? (string) ($game['status_long'] ?? '');
    return '<span class="fixture-status fixture-status--muted">' . wpm_esc($label) . '</span>';
}

/** One NBA game row — home/away team logo+name, score or "vs", status badge. Used on basket.php. */
function wpm_nba_game_card(array $game): string
{
    $homeLogo = wpm_image($game['home_logo'] ?? null);
    $awayLogo = wpm_image($game['away_logo'] ?? null);
    $homeName = (string) ($game['home_name'] ?? '—');
    $awayName = (string) ($game['away_name'] ?? '—');
    $hasScore = $game['home_score'] !== null && $game['away_score'] !== null;
    $isLive = wpm_nba_is_live((int) ($game['status_short'] ?? 1));

    // data-search/data-status back the same client-side search + live
    // filter that football.php uses (assets/js/livescore.js).
    $searchText = mb_strtolower($homeName . ' ' . $awayName);

    $html = '<div class="fixture-card' . ($isLive ? ' is-live' : '') . '" data-fixture-id="' . (int) $game['id'] . '" data-status="' . (int) ($game['status_short'] ?? 1) . '" data-search="' . wpm_esc($searchText) . '">';

    $html .= '<div class="fixture-card__team fixture-card__team--home">';
    $html .= $homeLogo !== null ? '<img src="' . wpm_esc($homeLogo) . '" alt="" loading="lazy">' : wpm_icon('basketball');
    $html .= '<span>' . wpm_esc($homeName) . '</span>';
    $html .= '</div>';

    $html .= '<div class="fixture-card__center">';
    if ($hasScore) {
        $html .= '<div class="fixture-card__score" data-field="score"><span>' . (int) $game['home_score'] . '</span><span class="fixture-card__score-sep"></span><span>' . (int) $game['away_score'] . '</span></div>';
    } else {
        $html .= '<div class="fixture-card__score fixture-card__score--vs">vs</div>';
    }
    $html .= '<div data-field="status">' . wpm_nba_status_badge($game) . '</div>';
    $html .= '</div>';

    $html .= '<div class="fixture-card__team fixture-card__team--away">';
    $html .= $awayLogo !== null ? '<img src="' . wpm_esc($awayLogo) . '" alt="" loading="lazy">' : wpm_icon('basketball');
    $html .= '<span>' . wpm_esc($awayName) . '</span>';
    $html .= '</div>';

    $html .= '</div>';

    return $html;
}

/** Splits article HTML on paragraph boundaries and inserts $insertHtml at the midpoint. */
function wpm_inject_midpoint(string $html, string $insertHtml): string
{
    if (trim($insertHtml) === '') {
        return $html;
    }
    $parts = preg_split('/(<\/p>)/i', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    if ($parts === false || count($parts) < 4) {
        return $html . $insertHtml;
    }
    // Each paragraph is a (content, "</p>") pair, so the midpoint index needs rounding to an even boundary.
    $mid = (int) floor(count($parts) / 2);
    $mid += $mid % 2;
    $before = implode('', array_slice($parts, 0, $mid));
    $after = implode('', array_slice($parts, $mid));
    return $before . $insertHtml . $after;
}

function wpm_increment_views(PDO $pdo, int $pageId): void
{
    // One count per article per browser session, so refreshing the same
    // article repeatedly doesn't inflate the counter.
    $seen = $_SESSION['wpm_viewed_articles'] ?? [];
    if (in_array($pageId, $seen, true)) {
        return;
    }
    try {
        $pdo->prepare('UPDATE pages SET views = views + 1 WHERE page_id = :id')->execute(['id' => $pageId]);
        $seen[] = $pageId;
        $_SESSION['wpm_viewed_articles'] = array_slice($seen, -200);
    } catch (Throwable $e) {
        // Non-fatal.
    }
}
