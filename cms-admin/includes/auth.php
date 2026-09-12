<?php
declare(strict_types=1);

require_once __DIR__ . '/functions.php';
require_once dirname(__DIR__) . '/config/database.php';

cms_session_start();

if (empty($_SESSION['cms_admin_id'])) {
    header('Location: ' . cms_login_href());
    exit;
}

/**
 * Idle timeout: 1 jam (12 Sep 2026 security audit finding #5, Medium).
 * Session cookie sebelumnya lifetime=0 (sampai browser ditutup) tanpa
 * batas idle sama sekali — laptop admin ke-lock/ditinggal di tempat umum
 * berarti session tetep aktif tanpa batas waktu. `cms_last_activity`
 * di-refresh tiap request halaman admin; kalau selisihnya lebih dari
 * CMS_IDLE_TIMEOUT_SECONDS, session di-destroy dan admin diarahkan balik
 * ke login (bukan session lifetime yang diubah — itu tetap "sampai
 * browser ditutup" untuk yang aktif kepake terus).
 */
const CMS_IDLE_TIMEOUT_SECONDS = 3600;

if (
    isset($_SESSION['cms_last_activity'])
    && (time() - (int) $_SESSION['cms_last_activity']) > CMS_IDLE_TIMEOUT_SECONDS
) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . cms_login_href() . '?timeout=1');
    exit;
}

$_SESSION['cms_last_activity'] = time();

/**
 * Re-check role + active status against the DB on every request (12 Sep
 * 2026 security audit finding #10, Medium). Before this, cms_admin_role()
 * only ever read $_SESSION['cms_admin_role'], set once at login and never
 * refreshed — so demoting an admin's role, or deactivating (is_active=0)
 * their account, had zero effect until that admin's session happened to
 * expire or they logged out themselves. A revoked admin could keep using
 * pages their new role/status should no longer allow, for as long as their
 * session cookie lived (session lifetime is 0 = until browser close, so in
 * practice this could be days).
 *
 * Cheap PK lookup, runs once per page load alongside every other auth.php
 * include — negligible cost compared to the security gap it closes.
 */
$stmt = $pdo->prepare('SELECT role, is_active FROM admins WHERE admin_id = :id LIMIT 1');
$stmt->execute(['id' => $_SESSION['cms_admin_id']]);
$adminRow = $stmt->fetch();

if ($adminRow === false || (int) $adminRow['is_active'] !== 1) {
    $_SESSION = [];
    session_destroy();
    header('Location: ' . cms_login_href());
    exit;
}

$_SESSION['cms_admin_role'] = (string) $adminRow['role'];

// H-1: CSRF enforcement. Placed after the auth check so unauthenticated POSTs
// are redirected to login rather than rejected, and only authenticated
// state-changing requests are validated. Non-POST requests pass through.
cms_verify_csrf();
