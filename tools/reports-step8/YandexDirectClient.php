<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;

final class YandexDirectClient
{
    private const REPORT_URL = 'https://api.direct.yandex.com/json/v501/reports';
    private const SERVICE_BASE = 'https://api.direct.yandex.com/json/v501/';

    private array $config;

    public function __construct(?array $config = null)
    {
        $this->config = $config ?? $this->loadConfig();
    }

    public function configured(): bool
    {
        return trim((string) ($this->config['token'] ?? '')) !== '';
    }

    public function clientLogin(): string
    {
        return trim((string) ($this->config['client_login'] ?? ''));
    }

    public function clientsGet(): array
    {
        return $this->serviceGet('clients', [
            'FieldNames' => [
                'Login',
                'ClientId',
                'ClientInfo',
                'Currency',
                'Type',
            ],
        ]);
    }

    public function report(array $params): array
    {
        $this->requireConfigured();

        $body = json_encode(
            ['params' => $params],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($body)) {
            throw new RuntimeException('Не удалось сформировать запрос отчёта Direct.');
        }

        $startedAt = time();
        $attempts = 0;

        while (true) {
            $attempts++;
            [$status, $headers, $response] = $this->request(
                self::REPORT_URL,
                $body,
                true
            );

            if ($status === 200) {
                return $this->parseTsv($response);
            }

            if (in_array($status, [201, 202], true)) {
                $retryIn = max(1, (int) ($headers['retryin'] ?? 5));

                if ((time() - $startedAt) + $retryIn > 240 || $attempts >= 20) {
                    throw new RuntimeException(
                        'Отчёт Direct не успел сформироваться за 4 минуты.'
                    );
                }

                sleep($retryIn);
                continue;
            }

            $this->throwApiError($status, $response);
        }
    }

    public function serviceGet(string $service, array $params): array
    {
        $this->requireConfigured();

        $body = json_encode([
            'method' => 'get',
            'params' => $params,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (!is_string($body)) {
            throw new RuntimeException('Не удалось сформировать запрос Direct.');
        }

        [$status, , $response] = $this->request(
            self::SERVICE_BASE . rawurlencode($service),
            $body,
            false
        );

        if ($status !== 200) {
            $this->throwApiError($status, $response);
        }

        $decoded = json_decode($response, true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Direct вернул некорректный JSON.');
        }

        if (isset($decoded['error'])) {
            $error = is_array($decoded['error']) ? $decoded['error'] : [];
            throw new RuntimeException(
                trim((string) (
                    $error['error_string']
                    ?? $error['error_detail']
                    ?? 'Ошибка API Яндекс Директа.'
                ))
            );
        }

        return is_array($decoded['result'] ?? null)
            ? $decoded['result']
            : [];
    }

    private function request(
        string $url,
        string $body,
        bool $reportRequest
    ): array {
        $headers = [
            'Authorization: Bearer ' . trim((string) $this->config['token']),
            'Accept-Language: ru',
            'Content-Type: application/json; charset=utf-8',
        ];

        $clientLogin = $this->clientLogin();

        if ($clientLogin !== '') {
            $headers[] = 'Client-Login: ' . $clientLogin;
        }

        if ($reportRequest) {
            $headers[] = 'processingMode: auto';
            $headers[] = 'returnMoneyInMicros: false';
            $headers[] = 'skipReportHeader: true';
            $headers[] = 'skipReportSummary: true';
        }

        $responseHeaders = [];
        $curl = curl_init($url);

        if ($curl === false) {
            throw new RuntimeException('Не удалось инициализировать cURL.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_HEADERFUNCTION => static function (
                $handle,
                string $line
            ) use (&$responseHeaders): int {
                $length = strlen($line);
                $position = strpos($line, ':');

                if ($position !== false) {
                    $name = strtolower(trim(substr($line, 0, $position)));
                    $value = trim(substr($line, $position + 1));
                    $responseHeaders[$name] = $value;
                }

                return $length;
            },
        ]);

        $response = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($response === false) {
            throw new RuntimeException(
                'Ошибка соединения с Яндекс Директом: ' . $error
            );
        }

        return [$status, $responseHeaders, (string) $response];
    }

    private function parseTsv(string $response): array
    {
        $response = preg_replace('/^\xEF\xBB\xBF/', '', $response) ?? $response;
        $lines = preg_split('/\r\n|\r|\n/', trim($response));

        if (!is_array($lines) || $lines === [] || trim($lines[0]) === '') {
            return [];
        }

        $headers = str_getcsv(array_shift($lines), "\t");
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $values = str_getcsv($line, "\t");
            $row = [];

            foreach ($headers as $index => $header) {
                $row[(string) $header] = $values[$index] ?? null;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function throwApiError(int $status, string $response): never
    {
        $decoded = json_decode($response, true);
        $error = is_array($decoded['error'] ?? null)
            ? $decoded['error']
            : [];
        $message = trim((string) (
            $error['error_string']
            ?? $error['error_detail']
            ?? $error['error_message']
            ?? ''
        ));

        if ($message === '') {
            $message = 'Direct API вернул HTTP ' . $status . '.';
        }

        throw new RuntimeException($message);
    }

    private function requireConfigured(): void
    {
        if (!$this->configured()) {
            throw new RuntimeException(
                'Токен Яндекс Директа не настроен.'
            );
        }
    }

    private function loadConfig(): array
    {
        $path = getenv('YANDEX_DIRECT_CONFIG')
            ?: dirname(APP_ROOT, 2) . '/yandex-direct-config.php';

        if (!is_file($path)) {
            return [];
        }

        $config = require $path;

        return is_array($config) ? $config : [];
    }
}
