<?php

namespace App;

class FrankfurterClient
{
    private const BASE_URL = 'https://api.frankfurter.dev/v2';

    /**
     * Fetches the EUR -> USD rate for the given date (or the latest rate if $date is null).
     *
     * @return array{date: string, rate: float}|null Null if the call failed or the response was unexpected.
     */
    public function getEurToUsdRate(?string $date): ?array
    {
        $url = self::BASE_URL . '/rate/EUR/USD';

        if ($date !== null) {
            $url .= '?date=' . urlencode($date);
        }

        $curl = curl_init($url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);

        $result = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($result === false || $httpCode !== 200) {
            return null;
        }

        $decoded = json_decode($result, true);

        if (
            ! is_array($decoded)
            || ! isset($decoded['date'])
            || ! isset($decoded['rate'])
            || ! is_numeric($decoded['rate'])
        ) {
            return null;
        }

        return [
            'date' => $decoded['date'],
            'rate' => (float) $decoded['rate']
        ];
    }
}
