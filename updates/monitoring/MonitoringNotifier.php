<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;
use SeoAnalytics\Repositories\MonitoringRepository;

final class MonitoringNotifier
{
    private array $config;

    public function __construct(
        private readonly MonitoringRepository $repository = new MonitoringRepository()
    ) {
        $this->config = $this->loadConfig();
    }

    public function notify(array $event): void
    {
        $eventId = (int) ($event['id'] ?? 0);
        if ($eventId <= 0) {
            return;
        }

        $category = (string) ($event['category'] ?? 'technical');
        $subject = '[' . mb_strtoupper((string) ($event['severity'] ?? 'info')) . '] '
            . (string) ($event['site_name'] ?? 'Сайт')
            . ': '
            . (string) ($event['message'] ?? 'Изменение состояния');
        $body = $this->message($event);
        $sentSomething = false;

        if (!empty($event['notify_email'])) {
            $recipient = trim((string) (
                $category === 'marketing'
                    ? ($event['marketing_email'] ?? '')
                    : ($event['technical_email'] ?? '')
            ));

            if ($recipient !== '') {
                $sentSomething = true;
                $this->sendEmail($eventId, $recipient, $subject, $body);
            }
        }

        if (!empty($event['notify_telegram'])) {
            $recipient = trim((string) (
                $category === 'marketing'
                    ? ($event['marketing_telegram_chat'] ?? '')
                    : ($event['technical_telegram_chat'] ?? '')
            ));

            if ($recipient !== '') {
                $sentSomething = true;
                $this->sendTelegram($eventId, $recipient, $body);
            }
        }

        if (!$sentSomething) {
            $this->repository->addNotificationLog(
                $eventId,
                'internal',
                null,
                'stored',
                null
            );
        }

        $this->repository->markEventNotified($eventId);
    }

    public function publicSettings(): array
    {
        $token = trim((string) ($this->config['telegram_bot_token'] ?? ''));

        return [
            'telegram_configured' => $token !== '',
            'telegram_token_masked' => $token === ''
                ? ''
                : substr($token, 0, min(8, strlen($token))) . '••••••••',
            'email_from' => (string) ($this->config['email_from'] ?? ''),
            'email_from_name' => (string) ($this->config['email_from_name'] ?? 'Мониторинг сайтов'),
        ];
    }

    public function saveSettings(array $data): array
    {
        $current = $this->config;
        $token = trim((string) ($data['telegram_bot_token'] ?? ''));

        if ($token === '' || str_contains($token, '••')) {
            $token = (string) ($current['telegram_bot_token'] ?? '');
        }

        if ($token !== '' && !preg_match('/^\d{5,20}:[A-Za-z0-9_-]{20,100}$/', $token)) {
            throw new RuntimeException('Некорректный формат токена Telegram-бота.');
        }

        $config = [
            'telegram_bot_token' => $token,
            'email_from' => trim((string) ($data['email_from'] ?? ($current['email_from'] ?? ''))),
            'email_from_name' => trim((string) ($data['email_from_name'] ?? ($current['email_from_name'] ?? 'Мониторинг сайтов'))),
        ];

        $path = $this->configPath();
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn "
            . var_export($config, true)
            . ";\n";
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));

        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить настройки уведомлений.');
        }
        @chmod($temporary, 0600);

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось заменить файл настроек уведомлений.');
        }
        @chmod($path, 0600);
        $this->config = $config;

        return $this->publicSettings();
    }

    private function sendEmail(
        int $eventId,
        string $recipient,
        string $subject,
        string $body
    ): void {
        if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
            $this->repository->addNotificationLog(
                $eventId,
                'email',
                $recipient,
                'failed',
                'Некорректный email.'
            );
            return;
        }

        $from = trim((string) ($this->config['email_from'] ?? ''));
        $fromName = trim((string) ($this->config['email_from_name'] ?? 'Мониторинг сайтов'));
        $headers = [
            'Content-Type: text/plain; charset=UTF-8',
            'MIME-Version: 1.0',
        ];

        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $encodedName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
            $headers[] = 'From: ' . $encodedName . ' <' . $from . '>';
        }

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $success = @mail(
            $recipient,
            $encodedSubject,
            $body,
            implode("\r\n", $headers)
        );

        $this->repository->addNotificationLog(
            $eventId,
            'email',
            $recipient,
            $success ? 'sent' : 'failed',
            $success ? null : 'Функция mail() не подтвердила отправку.'
        );
    }

    private function sendTelegram(int $eventId, string $chatId, string $text): void
    {
        $token = trim((string) ($this->config['telegram_bot_token'] ?? ''));

        if ($token === '') {
            $this->repository->addNotificationLog(
                $eventId,
                'telegram',
                $chatId,
                'failed',
                'Токен Telegram-бота не настроен.'
            );
            return;
        }

        $ch = curl_init('https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage');
        if ($ch === false) {
            $this->repository->addNotificationLog(
                $eventId,
                'telegram',
                $chatId,
                'failed',
                'Не удалось инициализировать запрос Telegram.'
            );
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'chat_id' => $chatId,
                'text' => mb_substr($text, 0, 4000),
                'disable_web_page_preview' => 'true',
            ], '', '&', PHP_QUERY_RFC3986),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = is_string($response) ? json_decode($response, true) : null;
        $success = $httpCode >= 200
            && $httpCode < 300
            && is_array($decoded)
            && !empty($decoded['ok']);

        $this->repository->addNotificationLog(
            $eventId,
            'telegram',
            $chatId,
            $success ? 'sent' : 'failed',
            $success
                ? null
                : ($error !== ''
                    ? $error
                    : (string) ($decoded['description'] ?? ('HTTP ' . $httpCode)))
        );
    }

    private function message(array $event): string
    {
        $lines = [
            'Мониторинг сайтов',
            '',
            'Сайт: ' . (string) ($event['site_name'] ?? ''),
            'Адрес: ' . (string) ($event['base_url'] ?? ''),
            'Категория: ' . ($event['category'] === 'marketing' ? 'SEO / реклама' : 'Техническая'),
            'Важность: ' . (string) ($event['severity'] ?? 'info'),
            '',
            (string) ($event['message'] ?? ''),
            '',
            'Время: ' . (string) ($event['created_at'] ?? date('Y-m-d H:i:s')),
        ];

        return implode("\n", $lines);
    }

    private function loadConfig(): array
    {
        $path = $this->configPath();
        if (!is_file($path)) {
            return [
                'telegram_bot_token' => '',
                'email_from' => '',
                'email_from_name' => 'Мониторинг сайтов',
            ];
        }

        $config = require $path;
        return is_array($config) ? $config : [];
    }

    private function configPath(): string
    {
        $root = realpath(dirname(__DIR__, 2));
        if (!is_string($root)) {
            throw new RuntimeException('Не удалось определить каталог проекта.');
        }
        return dirname($root, 2) . '/monitoring-config.php';
    }
}
