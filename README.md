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

`{coin}` accepts a ticker symbol (`BTC`, `BCH`, `SOL`, ... case-insensitive) or any
[CoinGecko coin ID](https://www.coingecko.com/en/all-cryptocurrencies) directly (e.g. `polkadot`,
`litecoin`). Tickers are resolved in two steps:

1. A small hardcoded list in `App::resolveCoinId()` (`BTC`, `ETH`, `BNB`, `XRP`, `SOL`, `ADA`,
   `DOGE`) - instant, and unambiguous by construction.
2. A cached mapping of every symbol CoinGecko tracks, stored in `crypto_symbol_map` and refreshed
   periodically by `bin/refresh-crypto-symbols.php` (see below). Since ticker symbols aren't
   unique on CoinGecko (multiple coins can share a symbol, e.g. several coins are ticked `GMT`),
   the coin with the best market cap rank for that symbol is used.

If a ticker resolves to the wrong coin, or isn't recognized because the cache is empty/stale or
the coin is too obscure to be worth disambiguating automatically, pass the coin's ID directly
instead - found either on its CoinGecko page (the "API ID" field) or via CoinGecko's own
[`/coins/list`](https://api.coingecko.com/api/v3/coins/list) endpoint (no key required).

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

CREATE TABLE `crypto_symbol_map` (
  `id` int(11) NOT NULL,
  `symbol` varchar(20) NOT NULL,
  `coin_id` varchar(64) NOT NULL,
  `name` varchar(255) NOT NULL,
  `market_cap_rank` int(11) DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE `crypto_symbol_map`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `symbol_coin_id` (`symbol`, `coin_id`),
  ADD KEY `symbol_rank` (`symbol`, `market_cap_rank`);

ALTER TABLE `crypto_symbol_map`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;
```

`crypto_symbol_map` starts empty. Ticker resolution for anything outside the small hardcoded list
(see above) silently falls back to treating the input as a literal coin ID until it's been
populated - see "Keeping the symbol mapping fresh" below.

Only the EUR→USD rate is ever stored in `exchange_rate` (`base` = `EUR`, `quote` = `USD`). The
USD→EUR direction is derived by dividing instead of caching a second row, since both rates come
from the same Frankfurter lookup. The columns are kept generic in case more pairs are added later.

`crypto_price` always stores prices against USD (`vs_currency` = `usd`); converting USD into a
coin is derived by dividing, the same way as the fiat table.

`price` uses `decimal(30,10)` rather than `exchange_rate.rate`'s `decimal(20,10)` to comfortably
fit Bitcoin-sized prices today and headroom for large future values, while still keeping enough
decimal precision for very low-priced coins.

## Keeping the symbol mapping fresh

`bin/refresh-crypto-symbols.php` rebuilds `crypto_symbol_map` from CoinGecko's `/coins/list` and
`/coins/markets` endpoints. Run it once after setup, then put it on a cron - daily or weekly is
plenty, since coin listings barely change day to day:

```
17 3 * * * php /path/to/currency-api/bin/refresh-crypto-symbols.php >> /var/log/currency-api/refresh-crypto-symbols.log 2>&1
```

It's intentionally a separate offline job rather than something triggered from a live request:
it makes 5 calls to CoinGecko's rate-limited keyless tier and can take several seconds, neither
of which belongs in the request path of an API call. It swaps the table in atomically (via a
shadow table + `RENAME TABLE`), so it's safe to run while the API is serving live traffic - no
window where a lookup could hit an empty or half-populated table. It exits `1` on failure so cron
or a monitoring wrapper can alert on it; a failed run leaves the existing cache untouched.

## A note on the CoinGecko keyless tier

This API calls CoinGecko's [keyless public tier](https://docs.coingecko.com/docs/keyless-public-api),
which has a low, unofficial rate limit (roughly 5-15 calls/minute) and is described by CoinGecko as
unsuitable for production workloads. The database cache absorbs most of this in practice, since a
given coin/date combination is only ever fetched once. If `/to-usd/{amount}/{coin}` or
`/to-crypto/{amount}/{coin}` starts returning `503`s under real traffic, sign up for a free
CoinGecko Demo API key (no credit card) and set it in `CoinGeckoClient::request()`, where there's
a commented-out line showing where to add the `x-cg-demo-api-key` header.
