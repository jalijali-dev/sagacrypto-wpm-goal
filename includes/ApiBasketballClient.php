<?php
declare(strict_types=1);

/**
 * Minimal API-Basketball/NBA (v2.nba.api-sports.io) HTTP client — sibling
 * product to API-Football under the same api-sports.io account family.
 * Same response envelope ({get, parameters, errors, results, response}),
 * same 200-with-errors-array quirk for auth/quota/plan problems, same
 * x-apisports-key auth header — this class deliberately mirrors
 * ApiFootballClient.php's shape so both clients are easy to reason about
 * side by side. Settings come from BasketballSettings::load().
 *
 * Game status codes (NBA v2): 1=Not Started, 2=Live, 3=Finished,
 * 4=Postponed, 5=Delayed, 6=Canceled.
 */

final class ApiBasketballClient
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

    /** GET /games — supports ?date=, ?id=, ?team=, ?season= per the NBA v2 docs. */
    public function games(array $query = []): array
    {
        return $this->get('/games', $query);
    }

    /** GET /teams — full team catalogue (or ?id=). Not the primary source in practice (see BasketballSync.php — teams are upserted from embedded /games data instead). */
    public function teams(array $query = []): array
    {
        return $this->get('/teams', $query);
    }

    /**
     * Same free-plan date/season restriction pattern as API-Football
     * (same account family, same underlying quirks). Parses whichever
     * "Free plans do not have access to this X, try Y" message shows up.
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
            return ['ok' => false, 'http_status' => $httpStatus, 'data' => [], 'error' => 'Unparseable response from API-Basketball.'];
        }

        // Same quirk as API-Football: always HTTP 200, errors surface in an "errors" array/object.
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
