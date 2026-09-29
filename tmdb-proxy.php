<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

const TMDB_API_BASE_HTTPS = 'https://api.themoviedb.org/3';
const TMDB_API_BASE_HTTP = 'http://api.themoviedb.org/3';
const TMDB_ALLOWED_PARAMS = [
    'query',
    'language',
    'page',
    'include_adult',
    'region',
    'watch_region',
    'with_watch_monetization_types',
    'sort_by',
    'with_watch_providers',
    'with_release_type',

    'vote_count.gte',
    'vote_count.lte',

    'primary_release_date.gte',
    'primary_release_date.lte',

    'release_date.gte',
    'release_date.lte',

    'first_air_date.gte',
    'first_air_date.lte',
];

const TMDB_BATCH_LIMIT = 20;

const TMDB_PARAM_ALIASES = [
    'vote_count_gte' => 'vote_count.gte',
    'vote_count_lte' => 'vote_count.lte',

    'primary_release_date_gte' => 'primary_release_date.gte',
    'primary_release_date_lte' => 'primary_release_date.lte',

    'release_date_gte' => 'release_date.gte',
    'release_date_lte' => 'release_date.lte',

    'first_air_date_gte' => 'first_air_date.gte',
    'first_air_date_lte' => 'first_air_date.lte',
];

function jsonResponse(int $statusCode, array $payload): never
{
    http_response_code($statusCode);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function serverValue(string $key): string
{
    $value = $_SERVER[$key] ?? getenv($key);

    return is_string($value) ? trim($value) : '';
}

function configString(array $config, array $keys): string
{
    foreach ($keys as $key) {
        $value = $config[$key] ?? '';

        if (!is_string($value)) {
            continue;
        }

        $value = trim($value);

        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function tmdbAuthFileCredentials(): array
{
    $authPath = __DIR__ . '/tmdb-auth.json';

    if (!is_file($authPath)) {
        return [
            'bearer' => '',
            'apiKey' => '',
        ];
    }

    $rawConfig = file_get_contents($authPath);

    if ($rawConfig === false) {
        jsonResponse(500, ['error' => 'Nie udalo sie odczytac pliku tmdb-auth.json.']);
    }

    $config = json_decode($rawConfig, true);

    if (!is_array($config)) {
        jsonResponse(500, ['error' => 'Nieprawidlowy plik tmdb-auth.json: ' . json_last_error_msg()]);
    }

    return [
        'bearer' => configString($config, [
            'bearer',
            'bearerToken',
            'TMDB_API_BEARER_TOKEN',
            'TMDB_BEARER_TOKEN',
        ]),
        'apiKey' => configString($config, [
            'apiKey',
            'api_key',
            'TMDB_API_KEY',
            'TMDB_V3_API_KEY',
        ]),
    ];
}

function tmdbCredentials(): array
{
    $bearerToken = serverValue('TMDB_API_BEARER_TOKEN');
    if ($bearerToken === '') {
        $bearerToken = serverValue('TMDB_BEARER_TOKEN');
    }

    $apiKey = serverValue('TMDB_API_KEY');
    if ($apiKey === '') {
        $apiKey = serverValue('TMDB_V3_API_KEY');
    }

    $authFileCredentials = tmdbAuthFileCredentials();

    if ($bearerToken === '') {
        $bearerToken = $authFileCredentials['bearer'];
    }

    if ($apiKey === '') {
        $apiKey = $authFileCredentials['apiKey'];
    }

    return [
        'bearer' => $bearerToken,
        'apiKey' => $apiKey,
    ];
}

function allowedPath(string $path): bool
{
    static $exactMatches = [
        '/search/movie',
        '/search/tv',
        '/discover/movie',
        '/discover/tv',
        '/genre/movie/list',
        '/genre/tv/list',
    ];

    if (in_array($path, $exactMatches, true)) {
        return true;
    }

    if ((bool) preg_match('#^/movie/\d+$#', $path)) {
        return true;
    }

    if ((bool) preg_match('#^/collection/\d+$#', $path)) {
        return true;
    }

    return (bool) preg_match('#^/(movie|tv)/\d+/(watch/providers|translations)$#', $path);
}

function filteredParams(array $query): array
{
    $params = [];
    $allowed = array_fill_keys(TMDB_ALLOWED_PARAMS, true);

    foreach ($query as $rawKey => $rawValue) {
        if ($rawKey === 'path') {
            continue;
        }

        if (!is_string($rawKey) || !is_string($rawValue)) {
            continue;
        }

        $key = TMDB_PARAM_ALIASES[$rawKey] ?? $rawKey;

        if (!isset($allowed[$key])) {
            continue;
        }

        $value = trim($rawValue);

        if ($value === '') {
            continue;
        }

        $params[$key] = $value;
    }

    return $params;
}

function tmdbBaseUrl(): string
{
    if (function_exists('curl_init') || in_array('https', stream_get_wrappers(), true)) {
        return TMDB_API_BASE_HTTPS;
    }

    return TMDB_API_BASE_HTTP;
}

function tmdbRequestTarget(string $path, array $params, array $credentials): array
{
    $query = http_build_query($params);
    $baseUrl = tmdbBaseUrl();
    $url = $baseUrl . $path . ($query !== '' ? '?' . $query : '');
    $headers = [
        'Accept: application/json',
        'Accept-Encoding: identity',
    ];
    $preferApiKey = str_starts_with($baseUrl, 'http://');

    if (!$preferApiKey && $credentials['bearer'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $credentials['bearer'];
    } elseif ($credentials['apiKey'] !== '') {
        $separator = str_contains($url, '?') ? '&' : '?';
        $url .= $separator . 'api_key=' . rawurlencode($credentials['apiKey']);
    } elseif ($credentials['bearer'] !== '') {
        $headers[] = 'Authorization: Bearer ' . $credentials['bearer'];
    }

    return [$url, $headers];
}

function tmdbRequest(string $path, array $params, array $credentials): array
{
    [$url, $headers] = tmdbRequestTarget($path, $params, $credentials);

    if (function_exists('curl_init')) {
        $curlHandle = curl_init($url);

        curl_setopt_array($curlHandle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        $body = curl_exec($curlHandle);

        if ($body === false) {
            $errorMessage = curl_error($curlHandle);
            curl_close($curlHandle);
            jsonResponse(502, ['error' => 'Nie udało się połączyć z TMDb: ' . $errorMessage]);
        }

        $statusCode = (int) curl_getinfo($curlHandle, CURLINFO_RESPONSE_CODE);
        curl_close($curlHandle);

        return [$statusCode, decodeTmdbResponseBody((string) $body)];
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => implode("\r\n", $headers),
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        jsonResponse(502, ['error' => 'Nie udało się połączyć z TMDb z poziomu serwera.']);
    }

    $statusCode = 200;

    if (isset($http_response_header) && is_array($http_response_header)) {
        foreach ($http_response_header as $headerLine) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $matches)) {
                $statusCode = (int) $matches[1];
                break;
            }
        }
    }

    return [$statusCode, decodeTmdbResponseBody((string) $body)];
}

function tmdbBatchRequest(array $requests, array $credentials): array
{
    $multiHandle = curl_multi_init();
    $handles = [];

    foreach ($requests as $index => [$path, $params]) {
        [$url, $headers] = tmdbRequestTarget($path, $params, $credentials);
        $handle = curl_init($url);

        curl_setopt_array($handle, [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2TLS,
            CURLOPT_PIPEWAIT => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        curl_multi_add_handle($multiHandle, $handle);
        $handles[$index] = $handle;
    }

    do {
        $status = curl_multi_exec($multiHandle, $running);

        if ($running) {
            curl_multi_select($multiHandle, 1.0);
        }
    } while ($running && $status === CURLM_OK);

    $results = [];

    foreach ($handles as $index => $handle) {
        $body = curl_multi_getcontent($handle);
        $statusCode = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $payload = is_string($body) && $body !== '' ? json_decode(decodeTmdbResponseBody($body), true) : null;

        curl_multi_remove_handle($multiHandle, $handle);
        curl_close($handle);

        $results[$index] = [
            'status' => $statusCode > 0 ? $statusCode : 502,
            'body' => is_array($payload) ? $payload : ['error' => 'Nie udało się pobrać danych z TMDb.'],
        ];
    }

    curl_multi_close($multiHandle);

    return $results;
}

function decodeTmdbResponseBody(string $body): string
{
    if (!str_starts_with($body, "\x1f\x8b")) {
        return $body;
    }

    if (!function_exists('gzdecode')) {
        jsonResponse(502, ['error' => 'TMDb zwróciło skompresowaną odpowiedź, ale PHP nie ma obsługi gzip.']);
    }

    $decoded = @gzdecode($body);

    if (!is_string($decoded)) {
        jsonResponse(502, ['error' => 'Nie udało się rozpakować odpowiedzi TMDb.']);
    }

    return $decoded;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonResponse(405, ['error' => 'Dozwolona jest tylko metoda GET.']);
}

if (isset($_GET['batch'])) {
    $decoded = is_string($_GET['batch']) ? json_decode($_GET['batch'], true) : null;

    if (!is_array($decoded) || $decoded === [] || !array_is_list($decoded) || count($decoded) > TMDB_BATCH_LIMIT) {
        jsonResponse(400, ['error' => 'Nieprawidłowa lista zapytań TMDb.']);
    }

    $requests = [];

    foreach ($decoded as $entry) {
        $entryPath = is_array($entry) && is_string($entry['path'] ?? null) ? trim($entry['path']) : '';
        $entryParams = is_array($entry['params'] ?? null) ? $entry['params'] : [];

        if ($entryPath === '' || !allowedPath($entryPath)) {
            jsonResponse(400, ['error' => 'Nieprawidłowy endpoint TMDb.']);
        }

        $requests[] = [$entryPath, filteredParams($entryParams)];
    }

    $credentials = tmdbCredentials();

    if ($credentials['bearer'] === '' && $credentials['apiKey'] === '') {
        jsonResponse(500, ['error' => 'Brak konfiguracji TMDb po stronie serwera.']);
    }

    if (!function_exists('curl_multi_init')) {
        jsonResponse(501, ['error' => 'Serwer nie obsługuje zapytań zbiorczych TMDb.']);
    }

    jsonResponse(200, ['results' => tmdbBatchRequest($requests, $credentials)]);
}

$path = trim((string) ($_GET['path'] ?? ''));

if ($path === '' || !allowedPath($path)) {
    jsonResponse(400, ['error' => 'Nieprawidłowy endpoint TMDb.']);
}

$credentials = tmdbCredentials();

if ($credentials['bearer'] === '' && $credentials['apiKey'] === '') {
    jsonResponse(500, ['error' => 'Brak konfiguracji TMDb po stronie serwera.']);
}

[$statusCode, $body] = tmdbRequest($path, filteredParams($_GET), $credentials);

http_response_code($statusCode);
echo $body;
