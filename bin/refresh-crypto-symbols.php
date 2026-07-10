<?php

/**
 * Refreshes the crypto_symbol_map table from CoinGecko, so ticker symbols (e.g. "bch") can be
 * resolved to CoinGecko coin IDs (e.g. "bitcoin-cash") without calling CoinGecko on every request.
 *
 * Run this on a schedule (e.g. daily or weekly - coin listings don't change hour to hour) rather
 * than triggering it from a live request:
 *   - it makes 1 + 4 calls to CoinGecko's keyless tier (rate-limited to roughly 5-15 calls/minute),
 *     which would compete with actual price lookups if done inline;
 *   - it can take a several seconds to run, which shouldn't block an API response.
 *
 * Example crontab entry (daily at 03:17, low-traffic hour to be a good neighbor to CoinGecko):
 *   17 3 * * * php /path/to/currency-api/bin/refresh-crypto-symbols.php >> /var/log/currency-api/refresh-crypto-symbols.log 2>&1
 *
 * Usage: php bin/refresh-crypto-symbols.php
 * Exit code: 0 on success, 1 on failure (so cron/monitoring can alert on it).
 */

declare(strict_types=1);

require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

use App\CoinGeckoClient;
use App\CryptoSymbolMapRepository;
use PierreMiniggio\DatabaseConnection\DatabaseConnection;
use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;
use PierreMiniggio\DatabaseFetcher\Exception\DatabaseFetcherException;

function logLine(string $message): void
{
    echo '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message . PHP_EOL;
}

$config = require __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'config.php';
$dbConfig = $config['db'];

$fetcher = new DatabaseFetcher(new DatabaseConnection(
    $dbConfig['host'],
    $dbConfig['database'],
    $dbConfig['username'],
    $dbConfig['password']
));

$client = new CoinGeckoClient();

logLine('Fetching coin list from CoinGecko...');
$coins = $client->fetchCoinList();

if (is_string($coins)) {
    logLine('FAILED: could not fetch coin list (' . $coins . '). Leaving existing cache untouched.');

    exit(1);
}

logLine('Fetched ' . count($coins) . ' coins. Fetching market cap ranks (best-effort)...');
$ranks = $client->fetchMarketCapRanks();
logLine('Fetched ranks for ' . count($ranks) . ' coins.');

$rows = array_map(
    static fn (array $coin): array => [
        'symbol' => strtolower($coin['symbol']),
        'coin_id' => $coin['id'],
        'name' => $coin['name'],
        'market_cap_rank' => $ranks[$coin['id']] ?? null
    ],
    $coins
);

$repository = new CryptoSymbolMapRepository($fetcher);

try {
    logLine('Swapping in ' . count($rows) . ' rows...');
    $repository->refresh($rows);
} catch (DatabaseFetcherException $e) {
    logLine('FAILED: database error while refreshing the cache: ' . $e->getMessage());

    exit(1);
}

logLine('Done. crypto_symbol_map now has ' . $repository->count() . ' rows.');

exit(0);
