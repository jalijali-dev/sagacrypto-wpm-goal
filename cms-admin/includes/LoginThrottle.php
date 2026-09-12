<?php
declare(strict_types=1);

/**
 * Anti brute-force throttling for cms-admin/login.php (12 Sep 2026
 * security audit finding #2, CRITICAL). Before this, login.php had NO
 * failed-attempt counter, lockout, or delay of any kind — password
 * hashing (password_verify()) and a generic error message were the
 * only defenses, which only slow down, not stop, an automated
 * credential-stuffing/brute-force script (a bot can still try
 * thousands of passwords per minute against any known admin email).
 *
 * Approach: a sliding time-window failed-attempt count, checked
 * against BOTH the submitted email AND the requester's IP (brief:
 * "counter gagal-login per akun DAN per IP") — blocking on EITHER
 * threshold covers two different attack shapes: (a) many passwords
 * against ONE email from one IP (classic brute-force), and (b) many
 * emails hit from one IP, OR one email hit from many IPs
 * (credential-stuffing / distributed attempts). A sliding window
 * (COUNT(*) WHERE created_at > NOW() - INTERVAL) rather than a stored
 * "locked_until" timestamp — self-expires as old failures age out of
 * the window, no separate unlock step needed.
 *
 * CAPTCHA (audit's other suggestion, e.g. Cloudflare Turnstile — this
 * site is already behind Cloudflare) is NOT implemented here — that's
 * a separate integration decision or follow-up, not something to fold
 * silently into this fix.
 */

require_once __DIR__ . '/schema-guard.php';

const CMS_LOGIN_MAX_ATTEMPTS = 5;
const CMS_LOGIN_WINDOW_MINUTES = 15;

/** Idempotent — safe to call on every login.php load. */
function cms_login_throttle_ensure_schema(PDO $pdo): void
{
    cms_ensure_table(
        $pdo,
        'login_attempts',
        "id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
         email VARCHAR(255) NOT NULL,
         ip_address VARCHAR(45) NOT NULL,
         success TINYINT(1) NOT NULL DEFAULT 0,
         created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
         KEY idx_email_created (email, created_at),
         KEY idx_ip_created (ip_address, created_at)"
    );
}

/**
 * Best-effort real client IP. This site runs behind Cloudflare (see
 * cf-ray/cf-cache-status response headers, confirmed in the 12 Sep
 * 2026 audit) — $_SERVER['REMOTE_ADDR'] alone would just be
 * Cloudflare's edge IP, not the actual visitor, which would make every
 * request look like it came from the same handful of IPs and defeat
 * the whole point of a per-IP counter. CF-Connecting-IP is Cloudflare's
 * own header for this and is trusted first; X-Forwarded-For (first hop)
 * is the fallback for non-Cloudflare requests (e.g. local dev); direct
 * REMOTE_ADDR is the last resort.
 */
function cms_client_ip(): string
{
    $cfIp = trim((string) ($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
    if ($cfIp !== '') {
        return $cfIp;
    }
    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        return trim($parts[0]);
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

/**
 * @return array{locked: bool, attempts_by_email: int, attempts_by_ip: int}
 */
function cms_login_check_lockout(PDO $pdo, string $email, string $ip): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE email = :email AND success = 0
           AND created_at > (NOW() - INTERVAL :window MINUTE)'
    );
    $stmt->bindValue(':email', $email);
    $stmt->bindValue(':window', CMS_LOGIN_WINDOW_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    $byEmail = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE ip_address = :ip AND success = 0
           AND created_at > (NOW() - INTERVAL :window MINUTE)'
    );
    $stmt->bindValue(':ip', $ip);
    $stmt->bindValue(':window', CMS_LOGIN_WINDOW_MINUTES, PDO::PARAM_INT);
    $stmt->execute();
    $byIp = (int) $stmt->fetchColumn();

    return [
        'locked' => $byEmail >= CMS_LOGIN_MAX_ATTEMPTS || $byIp >= CMS_LOGIN_MAX_ATTEMPTS,
        'attempts_by_email' => $byEmail,
        'attempts_by_ip' => $byIp,
    ];
}

/** Logs every attempt (success and failure) — failures drive the lockout count above; successes are kept too as a lightweight audit trail (who logged in, when, from where). */
function cms_login_record_attempt(PDO $pdo, string $email, string $ip, bool $success): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO login_attempts (email, ip_address, success, created_at) VALUES (:email, :ip, :success, NOW())'
    );
    $stmt->execute(['email' => $email, 'ip' => $ip, 'success' => $success ? 1 : 0]);
}
