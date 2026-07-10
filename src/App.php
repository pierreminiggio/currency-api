<?php

namespace App;

use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\DatabaseFetcher\Exception\DatabaseFetcherException;

class App
{
    private const TO_USD_ENDPOINT = 'to-usd';
    private const TO_EUR_ENDPOINT = 'to-eur';
    private const TO_CRYPTO_ENDPOINT = 'to-crypto';
    private const OPENAPI_ENDPOINT = 'openapi';

    private const VS_CURRENCY = 'usd';

    public function run(string $path, ?string $queryParameters): void
    {
        header('Content-Type: application/json');

        $trimmedPath = trim($path, '/');

        if ($trimmedPath === self::OPENAPI_ENDPOINT) {
            $this->renderOpenApiDoc();

            return;
        }

        $segments = explode('/', $trimmedPath);

        if (count($segments) === 2) {
            $this->handleFiatConversion($segments, $queryParameters);

            return;
        }

        if (count($segments) === 3) {
            $this->handleCryptoConversion($segments, $queryParameters);

            return;
        }

        http_response_code(404);
    }

    private function handleFiatConversion(array $segments, ?string $queryParameters): void
    {
        [$endpoint, $rawAmount] = $segments;

        if ($endpoint !== self::TO_USD_ENDPOINT && $endpoint !== self::TO_EUR_ENDPOINT) {
            http_response_code(404);

            return;
        }

        $amount = $this->parseAmount($rawAmount);

        if ($amount === null) {
            http_response_code(400);
            echo json_encode(['message' => 'Amount must be a positive number']);

            return;
        }

        $requestedDate = $this->getRequestedDate($queryParameters);

        if ($requestedDate === false) {
            http_response_code(400);
            echo json_encode(['message' => 'date must be in YYYY-MM-DD format']);

            return;
        }

        $fetcher = $this->createDatabaseFetcher();
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

    private function handleCryptoConversion(array $segments, ?string $queryParameters): void
    {
        [$endpoint, $rawAmount, $coinSymbolOrId] = $segments;

        if ($endpoint !== self::TO_USD_ENDPOINT && $endpoint !== self::TO_CRYPTO_ENDPOINT) {
            http_response_code(404);

            return;
        }

        $amount = $this->parseAmount($rawAmount);

        if ($amount === null) {
            http_response_code(400);
            echo json_encode(['message' => 'Amount must be a positive number']);

            return;
        }

        if ($coinSymbolOrId === '') {
            http_response_code(404);

            return;
        }

        $fetcher = $this->createDatabaseFetcher();
        $coinId = $this->resolveCoinId($coinSymbolOrId, new CryptoSymbolMapRepository($fetcher));

        $requestedDate = $this->getRequestedDate($queryParameters);

        if ($requestedDate === false) {
            http_response_code(400);
            echo json_encode(['message' => 'date must be in YYYY-MM-DD format']);

            return;
        }

        $repository = new CryptoPriceRepository($fetcher);
        $client = new CoinGeckoClient();

        try {
            $priceInfo = $this->getCryptoPrice($repository, $client, $coinId, $requestedDate);
        } catch (DatabaseFetcherException $e) {
            http_response_code(500);
            echo json_encode(['message' => 'Database error']);

            return;
        }

        if (is_string($priceInfo)) {
            if ($priceInfo === CoinGeckoClient::ERROR_NOT_FOUND) {
                http_response_code(404);
                echo json_encode(['message' => 'Unknown coin: ' . $coinSymbolOrId]);

                return;
            }

            if ($priceInfo === CoinGeckoClient::ERROR_RATE_LIMITED) {
                http_response_code(503);
                echo json_encode(['message' => 'Rate limited by upstream source, please retry shortly']);

                return;
            }

            http_response_code(502);
            echo json_encode(['message' => 'Could not retrieve crypto price']);

            $debugInfo = $client->getLastRequestDebugInfo();
            error_log(sprintf(
                'CoinGecko upstream error: httpCode=%d curlError=%s responseBody=%s',
                $debugInfo['httpCode'],
                $debugInfo['curlError'] !== '' ? $debugInfo['curlError'] : '(none)',
                $debugInfo['responseBody'] !== null ? substr($debugInfo['responseBody'], 0, 500) : '(none)'
            ));

            return;
        }

        ['date' => $effectiveDate, 'price' => $priceInUsd] = $priceInfo;

        if ($endpoint === self::TO_USD_ENDPOINT) {
            $from = strtoupper($coinSymbolOrId);
            $to = 'USD';
            $result = $amount * $priceInUsd;
        } else {
            $from = 'USD';
            $to = strtoupper($coinSymbolOrId);
            $result = $amount / $priceInUsd;
        }

        http_response_code(200);
        echo json_encode([
            'amount' => $amount,
            'from' => $from,
            'to' => $to,
            'coin' => $coinId,
            'rate' => $priceInUsd,
            'date' => $effectiveDate,
            'result' => $result
        ]);
    }

    /**
     * CoinGecko coin IDs don't always match their ticker symbol (e.g. BNB is "binancecoin",
     * not "bnb"). Resolution order:
     *
     *  1. A small hardcoded list of the most common tickers - instant, and unambiguous by
     *     construction (curated by hand rather than "most popular coin for this symbol").
     *  2. The cached CoinGecko symbol -> id mapping (see CryptoSymbolMapRepository), which covers
     *     every ticker CoinGecko tracks. When several coins share a symbol, the cache resolves to
     *     the one with the best market cap rank. If the mapping table hasn't been populated yet
     *     (bin/refresh-crypto-symbols.php has never run) or the DB lookup fails for any reason,
     *     this step is silently skipped rather than erroring the request.
     *  3. Otherwise, the input is passed through unchanged, on the assumption it's already a
     *     valid CoinGecko coin ID (e.g. "solana", "dogecoin") rather than a ticker.
     */
    private function resolveCoinId(string $symbolOrId, CryptoSymbolMapRepository $symbolMapRepository): string
    {
        static $knownSymbols = [
            'btc' => 'bitcoin',
            'eth' => 'ethereum',
            'bnb' => 'binancecoin',
            'xrp' => 'ripple',
            'sol' => 'solana',
            'ada' => 'cardano',
            'doge' => 'dogecoin'
        ];

        $normalized = strtolower($symbolOrId);

        if (isset($knownSymbols[$normalized])) {
            return $knownSymbols[$normalized];
        }

        try {
            $cachedCoinId = $symbolMapRepository->findCoinIdBySymbol($normalized);
        } catch (DatabaseFetcherException $e) {
            $cachedCoinId = null;
        }

        return $cachedCoinId ?? $normalized;
    }

    private function createDatabaseFetcher(): DatabaseFetcher
    {
        $config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
        $dbConfig = $config['db'];

        return new DatabaseFetcher(new DatabaseConnection(
            $dbConfig['host'],
            $dbConfig['database'],
            $dbConfig['username'],
            $dbConfig['password'],
            DatabaseConnection::UTF8_MB4
        ));
    }

    /**
     * @return float|null Null if $rawAmount isn't a valid positive number.
     */
    private function parseAmount(string $rawAmount): ?float
    {
        if (! is_numeric($rawAmount) || (float) $rawAmount < 0) {
            return null;
        }

        return (float) $rawAmount;
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
     * @return array{date: string, price: float}|string array on success, or one of the
     *                                                    CoinGeckoClient::ERROR_* constants on failure.
     */
    private function getCryptoPrice(
        CryptoPriceRepository $repository,
        CoinGeckoClient $client,
        string $coinId,
        ?string $requestedDate
    ): array|string {
        // A specific past date was requested: if we already have it cached, we never
        // need to call CoinGecko again, since historical prices don't change.
        if ($requestedDate !== null) {
            $cachedPrice = $repository->findCachedPrice($coinId, self::VS_CURRENCY, $requestedDate);

            if ($cachedPrice !== null) {
                return ['date' => $requestedDate, 'price' => $cachedPrice];
            }
        }

        // CoinGecko's historical endpoint expects DD-MM-YYYY, unlike the rest of this API
        // (and unlike Frankfurter) which use YYYY-MM-DD. Convert only for the outgoing call.
        // $requestedDate has already passed isValidDate() by this point, so this conversion
        // cannot actually fail, but we guard it anyway rather than assume.
        $coinGeckoDate = null;

        if ($requestedDate !== null) {
            $parsedDate = \DateTime::createFromFormat('Y-m-d', $requestedDate);

            if ($parsedDate === false) {
                return CoinGeckoClient::ERROR_UPSTREAM;
            }

            $coinGeckoDate = $parsedDate->format('d-m-Y');
        }

        $fetched = $client->getPrice($coinId, self::VS_CURRENCY, $coinGeckoDate);

        if (is_string($fetched)) {
            return $fetched;
        }

        // Unlike Frankfurter, CoinGecko's historical endpoint doesn't echo back an
        // "effective" date that could differ from what was requested (e.g. for non-trading
        // days), so the date we cache under is simply the one that was requested, or today's
        // date (UTC) when none was given.
        $effectiveDate = $requestedDate ?? gmdate('Y-m-d');

        // Only cache prices for past dates: "today"'s price is still moving live.
        if ($effectiveDate !== gmdate('Y-m-d')) {
            $repository->storePrice($coinId, self::VS_CURRENCY, $effectiveDate, $fetched['price']);
        }

        return ['date' => $effectiveDate, 'price' => $fetched['price']];
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
                'description' => 'Converts amounts between EUR, USD and cryptocurrencies, for the latest rate '
                    . 'or any past date, caching rates per date so repeated historical lookups never re-query '
                    . 'the upstream sources (Frankfurter for EUR/USD, CoinGecko for crypto).' . "\n\n"
                    . '### Finding available coin symbols' . "\n\n"
                    . 'The `{coin}` path parameter on the crypto endpoints accepts a ticker symbol '
                    . '(`BTC`, `BCH`, `SOL`, ... case-insensitive) or any '
                    . '[CoinGecko coin ID](https://www.coingecko.com/en/all-cryptocurrencies) directly '
                    . '(e.g. `polkadot`, `litecoin`). Tickers are resolved via a small hardcoded list of the '
                    . 'most common ones, then via a cached mapping of every symbol CoinGecko tracks, refreshed '
                    . 'periodically from CoinGecko itself (see `bin/refresh-crypto-symbols.php`). Where a symbol '
                    . 'is shared by several coins, the one with the best market cap rank is used. If a ticker '
                    . 'isn\'t recognized (e.g. the mapping cache is stale or the coin is too obscure to be in '
                    . 'it), pass the coin\'s ID instead: search for it on coingecko.com and read the ID from its '
                    . 'page URL or its "API ID" field, or call CoinGecko\'s own '
                    . '[`/coins/list`](https://api.coingecko.com/api/v3/coins/list) endpoint directly.',
                'version' => '1.0.0'
            ],
            'paths' => [
                '/' . self::TO_USD_ENDPOINT . '/{amount}' => $this->buildConversionPathSpec('EUR', 'USD'),
                '/' . self::TO_EUR_ENDPOINT . '/{amount}' => $this->buildConversionPathSpec('USD', 'EUR'),
                '/' . self::TO_USD_ENDPOINT . '/{amount}/{coin}' => $this->buildCryptoConversionPathSpec(
                    'Convert a cryptocurrency to USD',
                    'amount',
                    'in the given coin'
                ),
                '/' . self::TO_CRYPTO_ENDPOINT . '/{amount}/{coin}' => $this->buildCryptoConversionPathSpec(
                    'Convert USD to a cryptocurrency',
                    'amount in USD',
                    ''
                )
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
                    'CryptoConversionResult' => [
                        'type' => 'object',
                        'properties' => [
                            'amount' => ['type' => 'number', 'example' => 1],
                            'from' => ['type' => 'string', 'example' => 'BTC'],
                            'to' => ['type' => 'string', 'example' => 'USD'],
                            'coin' => [
                                'type' => 'string',
                                'example' => 'bitcoin',
                                'description' => 'The resolved CoinGecko coin ID that was actually used.'
                            ],
                            'rate' => ['type' => 'number', 'example' => 96000.42],
                            'date' => ['type' => 'string', 'format' => 'date', 'example' => '2026-01-01'],
                            'result' => ['type' => 'number', 'example' => 96000.42]
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

    private function buildCryptoConversionPathSpec(string $summary, string $amountMeaning, string $amountSuffix): array
    {
        $amountDescription = trim('Amount, ' . $amountMeaning . ', e.g. 1 or 0.5 ' . $amountSuffix);

        return [
            'get' => [
                'summary' => $summary,
                'parameters' => [
                    [
                        'name' => 'amount',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'number'],
                        'description' => $amountDescription
                    ],
                    [
                        'name' => 'coin',
                        'in' => 'path',
                        'required' => true,
                        'schema' => ['type' => 'string'],
                        'description' => 'A ticker symbol (e.g. BTC, BCH, SOL, case-insensitive), '
                            . 'or any CoinGecko coin ID (e.g. polkadot). See the description above '
                            . 'for how ticker resolution works and how to look up a coin\'s ID.'
                    ],
                    [
                        'name' => 'date',
                        'in' => 'query',
                        'required' => false,
                        'schema' => ['type' => 'string', 'format' => 'date'],
                        'description' => 'Date to use for the price, in YYYY-MM-DD format. '
                            . 'Defaults to the latest available price when omitted.'
                    ]
                ],
                'responses' => [
                    '200' => [
                        'description' => 'Conversion result',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/CryptoConversionResult']
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
                    '404' => [
                        'description' => 'Unknown coin',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/Error']
                            ]
                        ]
                    ],
                    '502' => [
                        'description' => 'Could not retrieve the price from the upstream source',
                        'content' => [
                            'application/json' => [
                                'schema' => ['$ref' => '#/components/schemas/Error']
                            ]
                        ]
                    ],
                    '503' => [
                        'description' => 'Rate limited by the upstream source; retry shortly',
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
