<?php
declare(strict_types=1);

/**
 * Minimal API-Formula-1 (v1.formula-1.api-sports.io) HTTP client — third
 * sibling product in the api-sports.io family (after API-Football and
 * API-Basketball/NBA), same response envelope and 200-with-errors-array
 * quirk, same x-apisports-key auth header. Settings come from
 * FormulaOneSettings::load().
 *
 * Unlike football/basketball, a race WEEKEND is several separate `race`
 * objects sharing one `competition` (Grand Prix) but a different `type`
 * (1st/2nd/3rd Practice, 1st/2nd/3rd Qualifying, Sprint + Sprint Shootout
 * variants, Race). Status is a string: Scheduled, Live, Completed,
 * Cancelled, Postponed — not football's short-code style.
 */

final class ApiFormula1Client
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

    /** GET /races — requires at least one of: id, date, next, last, season (per the docs). */
    public function races(array $query = []): array
    {
        return $this->get('/races', $query);
    }

    /** GET /rankings/races?race=<id> — full classification (finishing position, driver, team, points, gap) for one completed race. */
    public function raceRankings(int $raceId): array
    {
        return $this->get('/rankings/races', ['race' => $raceId]);
    }

    /** GET /rankings/drivers?season= — World Drivers' Championship standings. */
    public function driverStandings(int $season): array
    {
        return $this->get('/rankings/drivers', ['season' => $season]);
    }

    /** GET /rankings/teams?season= — World Constructors' Championship standings. */
    public function constructorStandings(int $season): array
    {
        return $this->get('/rankings/teams', ['season' => $season]);
    }

    /**
     * Same free-plan restriction message pattern as the other api-sports.io
     * products (same account family). Kept for parity even though F1's
     * sparse race calendar makes date-window restrictions far less likely
     * to matter in practice than football/NBA's daily fetches.
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
            return ['ok' => false, 'http_status' => $httpStatus, 'data' => [], 'error' => 'Unparseable response from API-Formula-1.'];
        }

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
