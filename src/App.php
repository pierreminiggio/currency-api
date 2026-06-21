<?php

namespace App;

use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\DatabaseFetcher\Exception\DatabaseFetcherException;

class App
{
    private const USD_ENDPOINT = 'usd';
    private const EUR_ENDPOINT = 'eur';

    public function run(string $path, ?string $queryParameters): void
    {
        $parsedPath = $this->parsePath($path);

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

        if ($endpoint === self::USD_ENDPOINT) {
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
    private function parsePath(string $path): ?array
    {
        $segments = explode('/', trim($path, '/'));

        if (count($segments) !== 2) {
            return null;
        }

        [$endpoint, $rawAmount] = $segments;

        if ($endpoint !== self::USD_ENDPOINT && $endpoint !== self::EUR_ENDPOINT) {
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
}
