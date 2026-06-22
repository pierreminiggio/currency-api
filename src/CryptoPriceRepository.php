<?php

namespace App;

use PierreMiniggio\DatabaseFetcher\DatabaseFetcher;

class CryptoPriceRepository
{
    private const TABLE = 'crypto_price';

    public function __construct(private DatabaseFetcher $fetcher)
    {
    }

    /**
     * Looks up a cached price for the given coin, currency and date.
     *
     * @return float|null Null if no row is cached for that combination yet.
     */
    public function findCachedPrice(string $coinId, string $vsCurrency, string $date): ?float
    {
        $rows = $this->fetcher->query(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->select('price')
                ->where('coin_id = :coin_id AND vs_currency = :vs_currency AND price_date = :price_date')
            ,
            [
                'coin_id' => $coinId,
                'vs_currency' => $vsCurrency,
                'price_date' => $date
            ]
        );

        if (! $rows) {
            return null;
        }

        return (float) $rows[0]['price'];
    }

    /**
     * Stores the price for the given coin, currency and date. Safe to call even if another
     * request already cached the same combination in the meantime, since the row is updated
     * instead of duplicated on a unique key conflict.
     */
    public function storePrice(string $coinId, string $vsCurrency, string $date, float $price): void
    {
        $this->fetcher->exec(
            $this->fetcher
                ->createQuery(self::TABLE)
                ->insertInto(
                    'coin_id, vs_currency, price_date, price',
                    ':coin_id, :vs_currency, :price_date, :price'
                )
                ->onDuplicateKeyUpdate('price = :price')
            ,
            [
                'coin_id' => $coinId,
                'vs_currency' => $vsCurrency,
                'price_date' => $date,
                'price' => $price
            ]
        );
    }
}
