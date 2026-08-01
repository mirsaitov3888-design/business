<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;
use SeoAnalytics\Repositories\SystemUpdateRepository;

final class SystemUpdateService
{
    private array $config;

    public function __construct(
        private readonly SystemUpdateRepository $repository = new SystemUpdateRepository()
    ) {
        $this->config = $this->loadConfig();
    }

    public function status(): array
    {
        $current = $this->currentVersion();
        $manifest = null;
        $error = null;

        try {
            $manifest = $this->manifest();
        } catch (\Throwable $exception) {
            $error = $exception->getMessage();
        }

        $release = is_array($manifest['latest'] ?? null)
            ? $manifest['latest']
            : null;
        $available = is_array($release)
            && version_compare(
                (string) ($release['version'] ?? '0'),
                $current,
                '>'
            );

        return [
            'current_version' => $current,
            'manifest' => $manifest,
            'latest' => $release,
            'update_available' => $available,
            'error' => $error,
            'history' => $this->repository->history(),
            'worker' => [
                'cron_expected' => true,
                'interval' => '1 минута',
            ],
        ];
    }

    public function queueLatest(?int $userId): int
    {
        $manifest = $this->manifest();
        $release = $manifest['latest'] ?? null;

        if (!is_array($release)) {
            throw new RuntimeException(
                'В манифесте нет доступного обновления.'
            );
        }

        $this->validateRelease($release);
        $current = $this->currentVersion();

        if (!version_compare((string) $release['version'], $current, '>')) {
            throw new RuntimeException(
                'Установлена актуальная версия системы.'
            );
        }

        return $this->repository->createInstall($release, $userId);
    }

    public function queueRollback(
        int $sourceUpdateId,
        ?int $userId
    ): int {
        return $this->repository->createRollback(
            $sourceUpdateId,
            $userId
        );
    }

    public function manifest(): array
    {
        $url = (string) ($this->config['manifest_url'] ?? '');

        if ($url === '') {
            throw new RuntimeException(
                'Не настроен адрес манифеста обновлений.'
            );
        }

        $body = $this->download($url, 30, 1024 * 1024);
        $manifest = json_decode($body, true);

        if (!is_array($manifest)) {
            throw new RuntimeException(
                'Сервер обновлений вернул некорректный манифест.'
            );
        }

        if ((string) ($manifest['channel'] ?? '') !== 'stable') {
            throw new RuntimeException(
                'Получен неизвестный канал обновлений.'
            );
        }

        if (isset($manifest['latest']) && is_array($manifest['latest'])) {
            $release = $manifest['latest'];

            if (($release['installer_url'] ?? '') !== '') {
                $this->validateRelease($release);
            }
        }

        return $manifest;
    }

    public function currentVersion(): string
    {
        $path = $this->versionPath();

        if (!is_file($path)) {
            return '0.0.0';
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) && isset($data['version'])
            ? (string) $data['version']
            : '0.0.0';
    }

    public function versionPath(): string
    {
        return $this->projectRoot()
            . '/storage/system-updates/version.json';
    }

    public function projectRoot(): string
    {
        $root = realpath(dirname(__DIR__, 2));

        if (!is_string($root) || !is_file($root . '/index.php')) {
            throw new RuntimeException(
                'Не удалось определить корень проекта.'
            );
        }

        return $root;
    }

    public function config(): array
    {
        return $this->config;
    }

    public function validateRelease(array $release): void
    {
        $version = trim((string) ($release['version'] ?? ''));
        $title = trim((string) ($release['title'] ?? ''));
        $url = trim((string) ($release['installer_url'] ?? ''));
        $sha = strtolower(trim((string) ($release['sha256'] ?? '')));

        if ($version === '' || $title === '') {
            throw new RuntimeException(
                'В описании обновления отсутствует версия или название.'
            );
        }

        if (!preg_match('/^[0-9A-Za-z._-]+$/', $version)) {
            throw new RuntimeException(
                'Версия обновления имеет недопустимый формат.'
            );
        }

        $allowedPrefix = (string) (
            $this->config['allowed_installer_prefix'] ?? ''
        );

        if (
            $url === ''
            || $allowedPrefix === ''
            || !str_starts_with($url, $allowedPrefix)
        ) {
            throw new RuntimeException(
                'Установщик находится вне разрешённого репозитория.'
            );
        }

        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new RuntimeException(
                'Не указана корректная контрольная сумма SHA-256.'
            );
        }
    }

    public function download(
        string $url,
        int $timeout,
        int $maxBytes
    ): string {
        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException(
                'Не удалось инициализировать загрузку.'
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'Mirsaitov System Updater/1.0',
            CURLOPT_ENCODING => '',
        ]);
        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body) || $httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException(
                'Не удалось загрузить файл обновления: '
                . ($error !== '' ? $error : "HTTP {$httpCode}")
            );
        }

        if (strlen($body) > $maxBytes) {
            throw new RuntimeException(
                'Загружаемый файл превышает допустимый размер.'
            );
        }

        return $body;
    }

    private function loadConfig(): array
    {
        $root = realpath(dirname(__DIR__, 2));
        $candidates = [];

        if (is_string($root)) {
            $candidates = [
                dirname($root, 2) . '/system-updates-config.php',
                dirname($root) . '/system-updates-config.php',
            ];
        }

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }

            $config = require $path;

            if (is_array($config)) {
                return $config;
            }
        }

        throw new RuntimeException(
            'Конфигурация системных обновлений не найдена.'
        );
    }
}
