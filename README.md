# currency-api

A simple API to convert between EUR and USD, for any date (including historical dates), using
[Frankfurter](https://frankfurter.dev/) as the rate source and a MariaDB table as a cache so the
same date is never fetched from Frankfurter twice.

## Endpoints

* `GET /usd/{amount}` - Converts `{amount}` EUR into USD, using today's rate.
* `GET /usd/{amount}?date=YYYY-MM-DD` - Converts `{amount}` EUR into USD, using the rate of the given date.
* `GET /eur/{amount}` - Converts `{amount}` USD into EUR, using today's rate.
* `GET /eur/{amount}?date=YYYY-MM-DD` - Converts `{amount}` USD into EUR, using the rate of the given date.

`{amount}` must be a positive number (integer or decimal, e.g. `10` or `12.5`).

### Example

```
GET /usd/10
{"amount":10,"from":"EUR","to":"USD","rate":1.1751,"date":"2026-01-01","result":11.751}
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
```

Only the EUR→USD rate is ever stored (`base` = `EUR`, `quote` = `USD`). The USD→EUR direction is
derived by dividing instead of caching a second row, since both rates come from the same Frankfurter
lookup. The columns are kept generic in case more pairs are added later.
