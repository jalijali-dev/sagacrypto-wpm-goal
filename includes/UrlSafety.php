<?php
declare(strict_types=1);

/**
 * SSRF guard for admin-configured outbound API base URLs (12 Sep 2026
 * security audit finding #11, Low). ApiFootballClient/ApiBasketballClient/
 * ApiFormula1Client all take their base_url straight from
 * cms-admin/pages/livescore-api-settings.php (superadmin-only) with zero
 * validation, then curl_init() it directly — including the "test
 * connection" action, which curls whatever base_url is in the POST body
 * even before it's saved. Risk is low (gated to superadmin already), but
 * there's nothing today stopping a base_url like http://169.254.169.254/
 * or http://localhost/ from being saved/tested, which would make the
 * server itself issue requests against internal-only targets.
 *
 * Deliberately simple: require https, require a hostname that resolves to
 * a public (non-private/non-reserved/non-loopback) IP. Not a general
 * SSRF library — just enough to stop the obvious internal-network cases
 * for this one admin-only input.
 */
function cms_is_safe_outbound_url(string $url): bool
{
    $parts = parse_url($url);
    if ($parts === false || ($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
        return false;
    }

    $host = $parts['host'];
    $ip = filter_var($host, FILTER_VALIDATE_IP) ? $host : gethostbyname($host);

    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return false;
    }

    return filter_var(
        $ip,
        FILTER_VALIDATE_IP,
        FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
    ) !== false;
}
