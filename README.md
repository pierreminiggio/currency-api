# currency-api

# currency-api

A simple API to convert between EUR, USD and cryptocurrencies, for any date (including historical
dates), using [Frankfurter](https://frankfurter.dev/) for EUR/USD and
[CoinGecko](https://www.coingecko.com/en/api) (keyless public tier) for crypto, with a MariaDB
cache so the same date is never fetched from either upstream source twice.

## Endpoints

* `GET /to-usd/{amount}` - Converts `{amount}` EUR into USD, using today's rate.
* `GET /to-usd/{amount}?date=YYYY-MM-DD` - Converts `{amount}` EUR into USD, using the rate of the given date.
* `GET /to-eur/{amount}` - Converts `{amount}` USD into EUR, using today's rate.
* `GET /to-eur/{amount}?date=YYYY-MM-DD` - Converts `{amount}` USD into EUR, using the rate of the given date.
* `GET /to-usd/{amount}/{coin}` - Converts `{amount}` of `{coin}` into USD, using today's price.
* `GET /to-usd/{amount}/{coin}?date=YYYY-MM-DD` - Same, using the price of the given date.
* `GET /to-crypto/{amount}/{coin}` - Converts `{amount}` USD into `{coin}`, using today's price.
* `GET /to-crypto/{amount}/{coin}?date=YYYY-MM-DD` - Same, using the price of the given date.
* `GET /openapi` - Interactive API documentation (Swagger UI), served from an inline OpenAPI 3.0 spec.

`{amount}` must be a positive number (integer or decimal, e.g. `10` or `12.5`).

`{coin}` accepts `BTC`, `ETH`, `BNB`, `XRP`, `SOL`, `ADA` or `DOGE` (case-insensitive), or any
[CoinGecko coin ID](https://www.coingecko.com/en/all-cryptocurrencies) directly (e.g. `polkadot`,
`litecoin`). Other tickers aren't auto-resolved since they're ambiguous across CoinGecko's 17,000+
coins; use the coin's ID instead, found either on its CoinGecko page (the "API ID" field) or via
CoinGecko's own [`/coins/list`](https://api.coingecko.com/api/v3/coins/list) endpoint (no key
required), which lists every supported coin's `id` and `symbol`.

### Examples

```
GET /to-usd/10
{"amount":10,"from":"EUR","to":"USD","rate":1.1751,"date":"2026-01-01","result":11.751}

GET /to-usd/1/btc?date=2017-06-15
{"amount":1,"from":"BTC","to":"USD","coin":"bitcoin","rate":2508.59,"date":"2017-06-15","result":2508.59}

GET /to-crypto/100/eth
{"amount":100,"from":"USD","to":"ETH","coin":"ethereum","rate":3200.12,"date":"2026-06-22","result":0.03124...}
```

## Setup

1. `composer install`
2. `cp config.example.php config.php` and fill in your DB credentials
3. Run the migration below on your database
4. Point your webserver's document root to `public/`, or use the provided `.htaccess` with Apache

## Migration

```sql
CREATE TABLE `exchange_rate` (
  `id` int(11) NOT NULL,
  `base` char(3) NOT NULL,
  `quote` char(3) NOT NULL,
  `rate_date` date NOT NULL,
  `rate` decimal(20,10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `exchange_rate`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `base_quote_rate_date` (`base`, `quote`, `rate_date`);

ALTER TABLE `exchange_rate`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE `crypto_price` (
  `id` int(11) NOT NULL,
  `coin_id` varchar(64) NOT NULL,
  `vs_currency` char(3) NOT NULL,
  `price_date` date NOT NULL,
  `price` decimal(30,10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `crypto_price`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `coin_vs_currency_price_date` (`coin_id`, `vs_currency`, `price_date`);

ALTER TABLE `crypto_price`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
```

Only the EUR→USD rate is ever stored in `exchange_rate` (`base` = `EUR`, `quote` = `USD`). The
USD→EUR direction is derived by dividing instead of caching a second row, since both rates come
from the same Frankfurter lookup. The columns are kept generic in case more pairs are added later.

`crypto_price` always stores prices against USD (`vs_currency` = `usd`); converting USD into a
coin is derived by dividing, the same way as the fiat table.

`price` uses `decimal(30,10)` rather than `exchange_rate.rate`'s `decimal(20,10)` to comfortably
fit Bitcoin-sized prices today and headroom for large future values, while still keeping enough
decimal precision for very low-priced coins.

## A note on the CoinGecko keyless tier

This API calls CoinGecko's [keyless public tier](https://docs.coingecko.com/docs/keyless-public-api),
which has a low, unofficial rate limit (roughly 5-15 calls/minute) and is described by CoinGecko as
unsuitable for production workloads. The database cache absorbs most of this in practice, since a
given coin/date combination is only ever fetched once. If `/to-usd/{amount}/{coin}` or
`/to-crypto/{amount}/{coin}` starts returning `503`s under real traffic, sign up for a free
CoinGecko Demo API key (no credit card) and set it in `CoinGeckoClient::request()`, where there's
a commented-out line showing where to add the `x-cg-demo-api-key` header.
