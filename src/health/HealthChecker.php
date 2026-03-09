<?php

declare(strict_types=1);

namespace Nola\Deploy\Health;

class HealthChecker
{
    /** @return HealthResult[] */
    public function check(array $urls, string $baseUrl = '', int $timeout = 10): array
    {
        $results = [];

        foreach ($urls as $url) {
            $fullUrl = $url;
            if (!str_starts_with($url, 'http')) {
                $fullUrl = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
            }

            if (empty($fullUrl) || !str_starts_with($fullUrl, 'http')) {
                $results[] = new HealthResult(
                    url: $url,
                    statusCode: 0,
                    responseTime: 0,
                    passed: false,
                    error: 'Invalid URL — configure base_url or provide full URLs',
                );
                continue;
            }

            $ch = curl_init($fullUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_NOBODY => false,
            ]);

            $response = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $time = (float) curl_getinfo($ch, CURLINFO_TOTAL_TIME);
            $error = curl_error($ch);
            curl_close($ch);

            $results[] = new HealthResult(
                url: $url,
                statusCode: $httpCode,
                responseTime: round($time, 3),
                passed: $httpCode >= 200 && $httpCode < 400,
                error: $error ?: null,
            );
        }

        return $results;
    }

    /** @param HealthResult[] $results */
    public function allPassed(array $results): bool
    {
        foreach ($results as $result) {
            if (!$result->passed) {
                return false;
            }
        }
        return !empty($results);
    }
}
