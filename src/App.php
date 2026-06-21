<?php

namespace App;

use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\DatabaseFetcher\Exception\DatabaseFetcherException;

class App
{
    private const TO_USD_ENDPOINT = 'to-usd';
    private const TO_EUR_ENDPOINT = 'to-eur';
    private const OPENAPI_ENDPOINT = 'openapi';

    public function run(string $path, ?string $queryParameters): void
    {
        header('Content-Type: application/json');

        $trimmedPath = trim($path, '/');

        if ($trimmedPath === self::OPENAPI_ENDPOINT) {
            $this->renderOpenApiDoc();

            return;
        }

        $parsedPath = $this->parsePath($trimmedPath);

        if ($parsedPath === null) {
            http_response_code(404);

            return;
        }

        [$endpoint, $rawAmount] = $parsedPath;

        if (! is_numeric($rawAmount) || (float) $rawAmount < 0) {
            http_response_code(400);
            echo json_encode(['message' => 'Amount must be a positive number']);

            return;
        }

        $amount = (float) $rawAmount;

        $requestedDate = $this->getRequestedDate($queryParameters);

        if ($requestedDate === false) {
            http_response_code(400);
            echo json_encode(['message' => 'date must be in YYYY-MM-DD format']);

            return;
        }

        $config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
        $dbConfig = $config['db'];
        $fetcher = new DatabaseFetcher(new DatabaseConnection(
            $dbConfig['host'],
            $dbConfig['database'],
            $dbConfig['username'],
            $dbConfig['password']
        ));

        $repository = new ExchangeRateRepository($fetcher);
        $client = new FrankfurterClient();

        try {
            $rateInfo = $this->getEurToUsdRate($repository, $client, $requestedDate);
        } catch (DatabaseFetcherException $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Database error']);

            return;
        }

        if ($rateInfo === null) {
            http_response_code(502);
            echo json_encode(['message' => 'Could not retrieve exchange rate']);

            return;
        }

        ['date' => $effectiveDate, 'rate' => $eurToUsdRate] = $rateInfo;

        if ($endpoint === self::TO_USD_ENDPOINT) {
            $from = 'EUR';
            $to = 'USD';
            $result = $amount * $eurToUsdRate;
        } else {
            $from = 'USD';
            $to = 'EUR';
            $result = $amount / $eurToUsdRate;
        }

        http_response_code(200);
        echo json_encode([
            'amount' => $amount,
            'from' => $from,
            'to' => $to,
            'rate' => $eurToUsdRate,
            'date' => $effectiveDate,
            'result' => $result
        ]);
    }

    /**
     * @return array{0: string, 1: string}|null Array of [endpoint, rawAmount], or null if the path is invalid.
     */
    private function parsePath(string $trimmedPath): ?array
    {
        $segments = explode('/', $trimmedPath);

        if (count($segments) !== 2) {
            return null;
        }

        [$endpoint, $rawAmount] = $segments;

        if ($endpoint !== self::TO_USD_ENDPOINT && $endpoint !== self::TO_EUR_ENDPOINT) {
            return null;
        }

        if ($rawAmount === '') {
            return null;
        }

        return [$endpoint, $rawAmount];
    }

    /**
     * @return string|null|false The requested date, null if none was requested (use latest),
     *                            or false if the provided date is malformed.
     */
    private function getRequestedDate(?string $queryParameters): string|null|false
    {
        if ($queryParameters === null) {
            return null;
        }

        parse_str(ltrim($queryParameters, '?'), $parsedQuery);

        if (empty($parsedQuery['date'])) {
            return null;
        }

        $date = $parsedQuery['date'];

        if (! is_string($date) || ! $this->isValidDate($date)) {
            return false;
        }

        return $date;
    }

    private function isValidDate(string $date): bool
    {
        $dateTime = \DateTime::createFromFormat('Y-m-d', $date);

        return $dateTime !== false && $dateTime->format('Y-m-d') === $date;
    }

    /**
     * @return array{date: string, rate: float}|null
     */
    private function getEurToUsdRate(
        ExchangeRateRepository $repository,
        FrankfurterClient $client,
        ?string $requestedDate
    ): ?array {
        // A specific past date was requested: if we already have it cached, we never
        // need to call Frankfurter again, since historical rates don't change.
        if ($requestedDate !== null) {
            $cachedRate = $repository->findCachedRate($requestedDate);

            if ($cachedRate !== null) {
                return ['date' => $requestedDate, 'rate' => $cachedRate];
            }
        }

        // Either no date was requested (we want "latest", which can still move during
        // the day so it's always fetched live) or the requested date wasn't cached yet.
        $fetched = $client->getEurToUsdRate($requestedDate);

        if ($fetched === null) {
            return null;
        }

        // The date Frankfurter actually used. This can differ from $requestedDate when
        // it falls back to the latest business day for weekends/holidays, and is always
        // set when no date was requested at all.
        $effectiveDate = $fetched['date'];

        // The resolved date might already be cached too (e.g. a "latest" request landing
        // on a date that a previous explicit-date request already cached).
        $cachedRate = $repository->findCachedRate($effectiveDate);

        if ($cachedRate !== null) {
            return ['date' => $effectiveDate, 'rate' => $cachedRate];
        }

        // Only cache rates for past dates. "Today"'s rate can still move until the day's
        // final ECB publication, so caching it could lock in a stale value.
        if ($effectiveDate !== gmdate('Y-m-d')) {
            $repository->storeRate($effectiveDate, $fetched['rate']);
        }

        return ['date' => $effectiveDate, 'rate' => $fetched['rate']];
    }

    /**
     * Serves a human-readable API documentation page (Swagger UI) backed by an inline
     * OpenAPI 3.0 spec, so the whole thing works from a single endpoint with no extra files.
     */
    private function renderOpenApiDoc(): void
    {
        $spec = [
            'openapi' => '3.0.3',
            'info' => [
                'title' => 'Currency API',
                'description' => 'Converts amounts between EUR and USD, for the latest rate or any past date, '
                    . 'caching rates per date so repeated historical lookups never re-query the upstream source.',
                'version' => '1.0.0'
            ],
            'paths' => [
                '/' . self::TO_USD_ENDPOINT . '/{amount}' => $this->buildConversionPathSpec('EUR', 'USD'),
                '/' . self::TO_EUR_ENDPOINT . '/{amount}' => $this->buildConversionPathSpec('USD', 'EUR')
            ],
            'components' => [
                'schemas' => [
                    'ConversionResult' => [
                        'type' => 'object',
                        'properties' => [
                            'amount' => ['type' => 'number', 'example' => 10],
                            'from' => ['type' => 'string', 'example' => 'EUR'],
                            'to' => ['type' => 'string', 'example' => 'USD'],
                            'rate' => ['type' => 'number', 'example' => 1.1751],
                            'date' => ['type' => 'string', 'format' => 'date', 'example' => '2026-01-01'],
                            'result' => ['type' => 'number', 'example' => 11.751]
                        ]
                    ],
                    'Error' => [
                        'type' => 'object',
                        'properties' => [
                            'message' => ['type' => 'string']
                        ]
                    ]
                ]
            ]
        ];

        $encodedSpec = json_encode($spec, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Currency API documentation</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui.min.css">
</head>
<body>
    <div id="swagger-ui"></div>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/swagger-ui/5.17.14/swagger-ui-bundle.min.js"></script>
    <script>
        window.onload = function () {
            SwaggerUIBundle({
                spec: {$encodedSpec},
                dom_id: '#swagger-ui'
            });
        };
    </script>
</body>
</html>
HTML;
    }

    private function buildConversionPathSpec(string $from, string $to): array
    {
        return [
            'get' => [
                'summary' => 'Convert ' . $from . ' to ' . $to,
                'parameters' => [
                    [
                        'name' => 'amount',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'number'],
                        'description' => 'Amount in ' . $from . ' to convert, e.g. 10 or 12.5'
                    ],
                    [
                        'name' => 'date',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'format' => 'date'],
                        'description' => 'Date to use for the rate, in YYYY-MM-DD format. '
                            . 'Defaults to the latest available rate when omitted.'
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Conversion result',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/ConversionResult']
                            ]
                        ]
                    ],
                    '400' => [
                        'description' => 'Invalid amount or date',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/Error']
                            ]
                        ]
                    ],
                    '502' => [
                        'description' => 'Could not retrieve the exchange rate from the upstream source',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/Error']
                            ]
                        ]
                    ]
                ]
            ]
        ];
    }
}
