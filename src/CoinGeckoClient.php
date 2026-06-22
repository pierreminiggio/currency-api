<?php

namespace App;

class CoinGeckoClient
{
    private const BASE_URL = 'https://api.coingecko.com/api/v3';

    public const ERROR_NOT_FOUND = 'not_found';
    public const ERROR_RATE_LIMITED = 'rate_limited';
    public const ERROR_UPSTREAM = 'upstream_error';

    private int $lastHttpCode = 0;
    private string $lastCurlError = '';
    private ?string $lastResponseBody = null;

    /**
     * Fetches the price of a coin in the given currency, for a given date (DD-MM-YYYY) or
     * the latest price if $date is null.
     *
     * @return array{price: float}|string Price on success, or one of the self::ERROR_* constants on failure.
     */
    public function getPrice(string $coinId, string $vsCurrency, ?string $coinGeckoDate): array|string
    {
        if ($coinGeckoDate !== null) {
            return $this->getHistoricalPrice($coinId, $vsCurrency, $coinGeckoDate);
        }

        return $this->getLatestPrice($coinId, $vsCurrency);
    }

    /**
     * @return array{price: float}|string
     */
    private function getLatestPrice(string $coinId, string $vsCurrency): array|string
    {
        $url = self::BASE_URL . '/simple/price?ids=' . urlencode($coinId)
            . '&vs_currencies=' . urlencode($vsCurrency);

        $response = $this->request($url);

        if (is_string($response)) {
            return $response;
        }

        if (
            ! isset($response[$coinId])
            || ! isset($response[$coinId][$vsCurrency])
            || ! is_numeric($response[$coinId][$vsCurrency])
        ) {
            return self::ERROR_NOT_FOUND;
        }

        return ['price' => (float) $response[$coinId][$vsCurrency]];
    }

    /**
     * @param string $coinGeckoDate Date in DD-MM-YYYY format, as expected by CoinGecko.
     *
     * @return array{price: float}|string
     */
    private function getHistoricalPrice(string $coinId, string $vsCurrency, string $coinGeckoDate): array|string
    {
        $url = self::BASE_URL . '/coins/' . urlencode($coinId) . '/history?date=' . urlencode($coinGeckoDate)
            . '&localization=false';

        $response = $this->request($url);

        if (is_string($response)) {
            return $response;
        }

        $price = $response['market_data']['current_price'][$vsCurrency] ?? null;

        if (! is_numeric($price)) {
            return self::ERROR_NOT_FOUND;
        }

        return ['price' => (float) $price];
    }

    /**
     * @return array|string Decoded JSON body on success, or one of the self::ERROR_* constants on failure.
     */
    private function request(string $url): array|string
    {
        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json']);

        // CoinGecko sits behind Cloudflare, which can reject requests with an empty or
        // missing User-Agent as bot traffic (Cloudflare error 1020). PHP's cURL doesn't
        // send one by default, so this needs to be explicit.
        curl_setopt($curl, CURLOPT_USERAGENT, 'currency-api/1.0 (+https://github.com/)');

        // No API key: using the keyless public tier. If this ever gets rate-limited or
        // ASN-blocked in practice, sign up for a free Demo key and add it here, e.g.:
        // curl_setopt($curl, CURLOPT_HTTPHEADER, ['x-cg-demo-api-key: ' . $apiKey]);

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_error($curl);
        curl_close($curl);

        $this->lastHttpCode = $httpCode;
        $this->lastCurlError = $curlError;
        $this->lastResponseBody = is_string($result) ? $result : null;

        if ($result === false) {
            return self::ERROR_UPSTREAM;
        }

        if ($httpCode === 429) {
            return self::ERROR_RATE_LIMITED;
        }

        if ($httpCode === 404) {
            return self::ERROR_NOT_FOUND;
        }

        if ($httpCode !== 200) {
            return self::ERROR_UPSTREAM;
        }

        $decoded = json_decode($result, true);

        if (! is_array($decoded)) {
            return self::ERROR_UPSTREAM;
        }

        return $decoded;
    }

    /**
     * Diagnostic info about the last request, regardless of whether it succeeded. Useful for
     * logging/debugging an ERROR_UPSTREAM response, since that bucket alone doesn't say why.
     * httpCode is 0 if the connection never completed (DNS/TLS/timeout failure; see curlError).
     *
     * @return array{httpCode: int, curlError: string, responseBody: string|null}
     */
    public function getLastRequestDebugInfo(): array
    {
        return [
            'httpCode' => $this->lastHttpCode,
            'curlError' => $this->lastCurlError,
            'responseBody' => $this->lastResponseBody
        ];
    }
}
