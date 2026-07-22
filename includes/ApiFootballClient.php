<?php
declare(strict_types=1);

/**
 * Minimal API-Football (v3.football.api-sports.io) HTTP client. Settings
 * (base URL, key, key header) come from LivescoreSettings::load() — never
 * hardcoded — so switching provider/plan only ever means editing the
 * Livescore API Settings admin page.
 *
 * Every method returns the same shape as ai-helpers.php's provider calls
 * (ok/http_status/data/error), so callers (admin test-connection action,
 * cron scripts) don't need a try/catch per call — cURL/network failures
 * come back as ok=false instead of throwing.
 */

final class ApiFootballClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $apiKeyHeader;

    public function __construct(string $baseUrl, string $apiKey, string $apiKeyHeader = 'x-apisports-key')
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->apiKeyHeader = $apiKeyHeader;
    }

    public static function fromSettings(array $settings): self
    {
        return new self(
            (string) $settings['base_url'],
            (string) $settings['api_key'],
            (string) $settings['api_key_header']
        );
    }

    /** GET /status — cheapest possible call, used purely to validate the key + report quota. */
    public function status(): array
    {
        return $this->get('/status');
    }

    /** GET /leagues — full catalogue (or ?id=) so the admin can pick tracked leagues by name. */
    public function leagues(array $query = []): array
    {
        return $this->get('/leagues', $query);
    }

    public function teams(int $leagueId, int $season): array
    {
        return $this->get('/teams', ['league' => $leagueId, 'season' => $season]);
    }

    public function fixtures(array $query): array
    {
        return $this->get('/fixtures', $query);
    }

    /** Fixtures currently in play, across every tracked league in one call. */
    public function liveFixtures(array $leagueIds): array
    {
        return $this->get('/fixtures', ['live' => implode('-', $leagueIds)]);
    }

    /**
     * Free API-Football plans reject /fixtures?date= outside a rolling
     * few-day window with a 200-status body like:
     *   {"errors": {"plan": "Free plans do not have access to this date,
     *   try from 2026-07-20 to 2026-07-22."}}
     * which ApiFootballClient::get() flattens into the `error` string.
     * Parses that specific message so callers can tell "plan can't fetch
     * this date at all" apart from every other failure (network error,
     * rate limit, bad key, ...). Returns null for anything else.
     *
     * @return ?array{start: string, end: string}
     */
    public static function parseDateWindowError(string $error): ?array
    {
        if (preg_match('/Free plans do not have access to this date.*?try from (\d{4}-\d{2}-\d{2}) to (\d{4}-\d{2}-\d{2})/i', $error, $m) === 1) {
            return ['start' => $m[1], 'end' => $m[2]];
        }

        return null;
    }

    /**
     * @return array{ok: bool, http_status: int, data: array, error: string}
     */
    private function get(string $path, array $query = []): array
    {
        if ($this->apiKey === '') {
            return ['ok' => false, 'http_status' => 0, 'data' => [], 'error' => 'No API key configured.'];
        }

        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                $this->apiKeyHeader . ': ' . $this->apiKey,
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $curlError !== '') {
            return ['ok' => false, 'http_status' => $httpStatus, 'data' => [], 'error' => $curlError !== '' ? $curlError : 'Request failed.'];
        }

        $decoded = json_decode((string) $response, true);
        if (!is_array($decoded)) {
            return ['ok' => false, 'http_status' => $httpStatus, 'data' => [], 'error' => 'Unparseable response from API-Football.'];
        }

        // API-Football always returns 200 with an "errors" array/object for
        // auth/quota problems rather than a 4xx status — surface that too.
        $apiErrors = $decoded['errors'] ?? [];
        if ($httpStatus >= 400 || (is_array($apiErrors) && $apiErrors !== [])) {
            $message = is_array($apiErrors) && $apiErrors !== []
                ? implode('; ', array_map('strval', $apiErrors))
                : ('HTTP ' . $httpStatus);
            return ['ok' => false, 'http_status' => $httpStatus, 'data' => $decoded, 'error' => $message];
        }

        return ['ok' => true, 'http_status' => $httpStatus, 'data' => $decoded, 'error' => ''];
    }
}
