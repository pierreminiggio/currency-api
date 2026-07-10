<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

/**
 * Caches CoinGecko's full symbol -> coin ID mapping (fetched via CoinGeckoClient::fetchCoinList()
 * and enriched with market_cap_rank via CoinGeckoClient::fetchMarketCapRanks()), so tickers like
 * "bch" can resolve to "bitcoin-cash" without calling CoinGecko on every request.
 *
 * The table is rebuilt wholesale by bin/refresh-crypto-symbols.php (run on a cron, not per-request:
 * see that script for why). refresh() swaps in the new data atomically via a shadow table, so
 * concurrent lookups from live traffic are never served a half-populated table.
 */
class CryptoSymbolMapRepository
{
    private const TABLE = 'crypto_symbol_map';
    private const SHADOW_TABLE = 'crypto_symbol_map_new';
    private const OLD_TABLE = 'crypto_symbol_map_old';

    private const BATCH_SIZE = 500;

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * Resolves a ticker symbol (e.g. "bch", already lowercased) to a CoinGecko coin ID.
     *
     * A symbol can map to several coins (tickers aren't unique on CoinGecko), so this picks the
     * one with the best (lowest) market_cap_rank, i.e. the most well-known coin using that ticker.
     * Coins with no known rank are ranked last, since an unranked coin sharing a popular ticker
     * (e.g. some obscure token also called "SOL") is almost never what the caller means.
     *
     * @return string|null Null if the symbol isn't in the cached mapping at all.
     */
    public function findCoinIdBySymbol(string $symbol): ?string
    {
        $rows = $this->fetcher->rawQuery(
            'SELECT coin_id FROM `' . self::TABLE . '` '
                . 'WHERE symbol = :symbol '
                . 'ORDER BY (market_cap_rank IS NULL) ASC, market_cap_rank ASC '
                . 'LIMIT 1',
            ['symbol' => $symbol]
        );

        if (! $rows) {
            return null;
        }

        return $rows[0]['coin_id'];
    }

    /**
     * @return int Number of currently cached symbol rows, 0 if the table is empty (e.g. the
     *             refresh script has never run yet).
     */
    public function count(): int
    {
        $rows = $this->fetcher->rawQuery('SELECT COUNT(*) as c FROM `' . self::TABLE . '`');

        return $rows ? (int) $rows[0]['c'] : 0;
    }

    /**
     * @return string|null Null if the table has never been populated.
     */
    public function lastRefreshedAt(): ?string
    {
        $rows = $this->fetcher->rawQuery('SELECT MAX(updated_at) as latest FROM `' . self::TABLE . '`');

        return $rows[0]['latest'] ?? null;
    }

    /**
     * Wholesale-replaces the cached mapping with $coins, atomically (readers never see a partial
     * table). Meant to be called from bin/refresh-crypto-symbols.php only.
     *
     * @param list<array{symbol: string, coin_id: string, name: string, market_cap_rank: int|null}> $coins
     */
    public function refresh(array $coins): void
    {
        // Defensive cleanup in case a previous run died mid-swap.
        $this->fetcher->rawExec('DROP TABLE IF EXISTS `' . self::SHADOW_TABLE . '`');
        $this->fetcher->rawExec('CREATE TABLE `' . self::SHADOW_TABLE . '` LIKE `' . self::TABLE . '`');

        foreach (array_chunk($coins, self::BATCH_SIZE) as $batch) {
            $this->insertBatch($batch);
        }

        // Atomic in MySQL/MariaDB: both renames happen as a single operation, so any request
        // running concurrently sees either the fully-old or the fully-new table, never a gap.
        $this->fetcher->rawExec(
            'RENAME TABLE `' . self::TABLE . '` TO `' . self::OLD_TABLE . '`, '
                . '`' . self::SHADOW_TABLE . '` TO `' . self::TABLE . '`'
        );

        $this->fetcher->rawExec('DROP TABLE IF EXISTS `' . self::OLD_TABLE . '`');
    }

    /**
     * @param list<array{symbol: string, coin_id: string, name: string, market_cap_rank: int|null}> $batch
     */
    private function insertBatch(array $batch): void
    {
        if ($batch === []) {
            return;
        }

        $valuePlaceholders = [];
        $parameters = [];

        foreach (array_values($batch) as $i => $coin) {
            $valuePlaceholders[] = "(:symbol{$i}, :coin_id{$i}, :name{$i}, :rank{$i})";
            $parameters['symbol' . $i] = $coin['symbol'];
            $parameters['coin_id' . $i] = $coin['coin_id'];
            $parameters['name' . $i] = $coin['name'];
            $parameters['rank' . $i] = $coin['market_cap_rank'];
        }

        $this->fetcher->rawExec(
            'INSERT INTO `' . self::SHADOW_TABLE . '` (symbol, coin_id, name, market_cap_rank) VALUES '
                . implode(', ', $valuePlaceholders),
            $parameters
        );
    }
}
