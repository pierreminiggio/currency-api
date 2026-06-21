<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

class ExchangeRateRepository
{
    private const TABLE = 'exchange_rate';
    private const BASE = 'EUR';
    private const QUOTE = 'USD';

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * Looks up a cached EUR -> USD rate for the given date.
     *
     * @return float|null Null if no row is cached for that date yet.
     */
    public function findCachedRate(string $date): ?float
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('rate')
                ->where('base = :base AND quote = :quote AND rate_date = :rate_date')
            ,
            [
                'base' => self::BASE,
                'quote' => self::QUOTE,
                'rate_date' => $date
            ]
        );

        if (! $rows) {
            return null;
        }

        return (float) $rows[0]['rate'];
    }

    /**
     * Stores the rate for the given date. Safe to call even if another request already
     * cached the same date in the meantime (e.g. two concurrent requests on a cache miss),
     * since the row is updated instead of duplicated on a unique key conflict.
     */
    public function storeRate(string $date, float $rate): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'base, quote, rate_date, rate',
                    ':base, :quote, :rate_date, :rate'
                )
                ->onDuplicateKeyUpdate('rate = :rate')
            ,
            [
                'base' => self::BASE,
                'quote' => self::QUOTE,
                'rate_date' => $date,
                'rate' => $rate
            ]
        );
    }
}
