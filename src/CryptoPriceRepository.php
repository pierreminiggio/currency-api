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
     * Same as findCachedPrice(), but also returns how long ago the row was last written - used
     * to apply a TTL to "latest price" caching (see App::getCryptoPrice()), since unlike
     * historical prices, today's price isn't immutable and shouldn't be cached forever.
     *
     * The age is computed in SQL (UTC_TIMESTAMP() - created_at) rather than in PHP against
     * gmdate(), so it isn't thrown off by any clock difference between the web server and the
     * database server.
     *
     * @return array{price: float, ageSeconds: int}|null Null if no row is cached for that
     *                                                     combination yet.
     */
    public function findCachedPriceWithAge(string $coinId, string $vsCurrency, string $date): ?array
    {
        $rows = $this->fetcher->rawQuery(
            'SELECT price, TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) as age_seconds '
                . 'FROM `' . self::TABLE . '` '
                . 'WHERE coin_id = :coin_id AND vs_currency = :vs_currency AND price_date = :price_date',
            [
                'coin_id' => $coinId,
                'vs_currency' => $vsCurrency,
                'price_date' => $date
            ]
        );

        if (! $rows) {
            return null;
        }

        return [
            'price' => (float) $rows[0]['price'],
            'ageSeconds' => (int) $rows[0]['age_seconds']
        ];
    }

    /**
     * Stores the price for the given coin, currency and date. Safe to call even if another
     * request already cached the same combination in the meantime, since the row is updated
     * instead of duplicated on a unique key conflict.
     *
     * created_at is refreshed on conflict too (not just on first insert), since it doubles as
     * "last refreshed at" for the TTL check in findCachedPriceWithAge() - without this, a "latest
     * price" row would look permanently stale (or permanently fresh, depending on read vs write
     * timing) after its first update.
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
                ->onDuplicateKeyUpdate('price = :price, created_at = UTC_TIMESTAMP()')
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
