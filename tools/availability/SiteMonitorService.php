<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use SeoAnalytics\Repositories\SiteMonitorRepository;

final class SiteMonitorService
{
    public function __construct(
        private readonly SiteMonitorRepository $repository = new SiteMonitorRepository()
    ) {
    }

    public function checkProject(array $project): array
    {
        $monitor = $this->repository->ensureForProject($project);
        $result = $this->checkUrl(
            (string) $monitor['url'],
            (int) $monitor['timeout_seconds'],
            (int) $monitor['slow_threshold_ms']
        );

        $this->repository->recordCheck(
            (int) $monitor['id'],
            $result
        );

        return $result;
    }

    public function dashboard(array $project, string $dateFrom, string $dateTo): array
    {
        return $this->repository->dashboard($project, $dateFrom, $dateTo);
    }

    private function checkUrl(
        string $url,
        int $timeoutSeconds,
        int $slowThresholdMs
    ): array {
        $currentUrl = $this->normalizeUrl($url);
        $httpCode = null;
        $responseMs = null;
        $finalUrl = $currentUrl;
        $error = null;

        try {
            for ($redirect = 0; $redirect <= 5; $redirect++) {
                $response = $this->requestOnce(
                    $currentUrl,
                    $timeoutSeconds
                );
                $httpCode = $response['http_code'];
                $responseMs = ($responseMs ?? 0) + $response['response_ms'];
                $finalUrl = $currentUrl;

                if (
                    $httpCode >= 300
                    && $httpCode < 400
                    && $response['redirect_url'] !== ''
                ) {
                    $currentUrl = $this->resolveRedirect(
                        $currentUrl,
                        $response['redirect_url']
                    );
                    continue;
                }

                break;
            }
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $sslExpiresAt = null;
        $sslWarning = null;

        try {
            $parts = parse_url($finalUrl);

            if (
                is_array($parts)
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            ) {
                $sslExpiresAt = $this->sslExpiration(
                    (string) ($parts['host'] ?? ''),
                    (int) ($parts['port'] ?? 443),
                    $timeoutSeconds
                );

                if ($sslExpiresAt !== null) {
                    $days = (int) floor(
                        ((new \DateTimeImmutable($sslExpiresAt))->getTimestamp()
                        - time()) / 86400
                    );

                    if ($days < 0) {
                        $sslWarning = 'SSL-сертификат просрочен.';
                    } elseif ($days < 14) {
                        $sslWarning = "SSL-сертификат истекает через {$days} дн.";
                    }
                }
            }
        } catch (\Throwable $exception) {
            $sslWarning = 'Не удалось проверить SSL: ' . $exception->getMessage();
        }

        if ($error !== null) {
            return [
                'status' => 'down',
                'http_code' => $httpCode,
                'response_ms' => $responseMs,
                'final_url' => $finalUrl,
                'error_message' => $error,
                'ssl_expires_at' => $sslExpiresAt,
            ];
        }

        if ($httpCode === null || $httpCode < 200 || $httpCode >= 400) {
            return [
                'status' => 'down',
                'http_code' => $httpCode,
                'response_ms' => $responseMs,
                'final_url' => $finalUrl,
                'error_message' => "Сайт вернул HTTP {$httpCode}.",
                'ssl_expires_at' => $sslExpiresAt,
            ];
        }

        $status = 'up';
        $warnings = [];

        if ($responseMs !== null && $responseMs > $slowThresholdMs) {
            $status = 'degraded';
            $warnings[] = "Медленный ответ: {$responseMs} мс.";
        }

        if ($sslWarning !== null) {
            $status = 'degraded';
            $warnings[] = $sslWarning;
        }

        return [
            'status' => $status,
            'http_code' => $httpCode,
            'response_ms' => $responseMs,
            'final_url' => $finalUrl,
            'error_message' => $warnings === []
                ? null
                : implode(' ', $warnings),
            'ssl_expires_at' => $sslExpiresAt,
        ];
    }

    private function requestOnce(string $url, int $timeoutSeconds): array
    {
        $parts = parse_url($url);

        if (!is_array($parts)) {
            throw new \RuntimeException('Некорректный адрес сайта.');
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = trim((string) ($parts['host'] ?? ''));

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new \RuntimeException('Разрешены только публичные HTTP/HTTPS-адреса.');
        }

        $port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
        $ip = $this->publicIpv4($host);

        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('Не удалось инициализировать HTTP-проверку.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'Mirsaitov-Site-Monitor/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
                'Cache-Control: no-cache',
            ],
        ]);

        $started = microtime(true);
        $body = curl_exec($ch);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $errno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new \RuntimeException(
                $curlError !== ''
                    ? $curlError
                    : 'HTTP-проверка завершилась ошибкой.'
            );
        }

        if (in_array($httpCode, [405, 501], true)) {
            return $this->requestGetFallback(
                $url,
                $host,
                $port,
                $ip,
                $timeoutSeconds
            );
        }

        return [
            'http_code' => $httpCode,
            'response_ms' => $elapsed,
            'redirect_url' => $redirectUrl,
        ];
    }

    private function requestGetFallback(
        string $url,
        string $host,
        int $port,
        string $ip,
        int $timeoutSeconds
    ): array {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new \RuntimeException('Не удалось выполнить GET-проверку.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => min(10, $timeoutSeconds),
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_USERAGENT => 'Mirsaitov-Site-Monitor/1.0',
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
            CURLOPT_RANGE => '0-2047',
        ]);

        $started = microtime(true);
        $body = curl_exec($ch);
        $elapsed = (int) round((microtime(true) - $started) * 1000);
        $errno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $redirectUrl = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
        curl_close($ch);

        if ($body === false || $errno !== 0) {
            throw new \RuntimeException(
                $curlError !== '' ? $curlError : 'GET-проверка завершилась ошибкой.'
            );
        }

        return [
            'http_code' => $httpCode,
            'response_ms' => $elapsed,
            'redirect_url' => $redirectUrl,
        ];
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new \RuntimeException('В проекте указан некорректный адрес сайта.');
        }

        return $url;
    }

    private function publicIpv4(string $host): string
    {
        $records = dns_get_record($host, DNS_A) ?: [];

        foreach ($records as $record) {
            $ip = (string) ($record['ip'] ?? '');

            if (
                filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_IPV4
                    | FILTER_FLAG_NO_PRIV_RANGE
                    | FILTER_FLAG_NO_RES_RANGE
                )
            ) {
                return $ip;
            }
        }

        throw new \RuntimeException(
            'Сайт не имеет доступного публичного IPv4-адреса.'
        );
    }

    private function resolveRedirect(string $baseUrl, string $location): string
    {
        if (filter_var($location, FILTER_VALIDATE_URL)) {
            return $this->normalizeUrl($location);
        }

        $base = parse_url($baseUrl);

        if (!is_array($base) || empty($base['host'])) {
            throw new \RuntimeException('Не удалось обработать перенаправление.');
        }

        $scheme = (string) ($base['scheme'] ?? 'https');
        $port = isset($base['port']) ? ':' . (int) $base['port'] : '';

        if (str_starts_with($location, '//')) {
            return $this->normalizeUrl($scheme . ':' . $location);
        }

        if (str_starts_with($location, '/')) {
            return $this->normalizeUrl(
                $scheme . '://' . $base['host'] . $port . $location
            );
        }

        $path = (string) ($base['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');

        return $this->normalizeUrl(
            $scheme . '://' . $base['host'] . $port
            . ($directory === '' ? '' : $directory)
            . '/' . $location
        );
    }

    private function sslExpiration(
        string $host,
        int $port,
        int $timeoutSeconds
    ): ?string {
        if ($host === '') {
            return null;
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            "ssl://{$host}:{$port}",
            $errno,
            $error,
            min(10, $timeoutSeconds),
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($socket)) {
            throw new \RuntimeException(
                $error !== '' ? $error : "Ошибка SSL {$errno}."
            );
        }

        $parameters = stream_context_get_params($socket);
        fclose($socket);
        $certificate = $parameters['options']['ssl']['peer_certificate'] ?? null;

        if ($certificate === null) {
            return null;
        }

        $parsed = openssl_x509_parse($certificate);
        $timestamp = is_array($parsed)
            ? (int) ($parsed['validTo_time_t'] ?? 0)
            : 0;

        return $timestamp > 0
            ? date('Y-m-d H:i:s', $timestamp)
            : null;
    }
}
