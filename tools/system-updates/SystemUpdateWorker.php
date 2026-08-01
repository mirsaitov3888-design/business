<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;
use SeoAnalytics\Repositories\SystemUpdateRepository;

final class SystemUpdateWorker
{
    public function __construct(
        private readonly SystemUpdateRepository $repository = new SystemUpdateRepository(),
        private readonly SystemUpdateService $service = new SystemUpdateService()
    ) {
    }

    public function run(): void
    {
        $config = $this->service->config();
        $lockPath = (string) ($config['lock_path'] ?? '');

        if ($lockPath === '') {
            throw new RuntimeException('Не настроен файл блокировки обновлений.');
        }

        $directory = dirname($lockPath);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог обновлений.');
        }

        $lock = fopen($lockPath, 'c+');

        if (!is_resource($lock)) {
            throw new RuntimeException('Не удалось открыть файл блокировки.');
        }

        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return;
        }

        try {
            $job = $this->repository->nextJob();

            if (!$job) {
                return;
            }

            if ((string) $job['action_type'] === 'rollback') {
                $this->rollback($job);
            } else {
                $this->install($job);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function install(array $job): void
    {
        $id = (int) $job['id'];
        $this->repository->markStarted($id, 'installing');
        $log = $this->line('Начинаем установку версии ' . $job['version']);
        $backupPath = null;

        try {
            $release = json_decode(
                (string) ($job['manifest_json'] ?? ''),
                true
            );

            if (!is_array($release)) {
                throw new RuntimeException('Не удалось прочитать описание обновления.');
            }

            $this->service->validateRelease($release);
            $currentManifest = $this->service->manifest();
            $latest = $currentManifest['latest'] ?? null;

            if (
                !is_array($latest)
                || (string) ($latest['version'] ?? '') !== (string) $job['version']
                || (string) ($latest['sha256'] ?? '') !== (string) $job['sha256']
            ) {
                throw new RuntimeException(
                    'Манифест изменился после постановки обновления в очередь.'
                );
            }

            $installer = $this->service->download(
                (string) $job['installer_url'],
                120,
                20 * 1024 * 1024
            );
            $actualSha = hash('sha256', $installer);

            if (!hash_equals(strtolower((string) $job['sha256']), strtolower($actualSha))) {
                throw new RuntimeException(
                    'Контрольная сумма установщика не совпала.'
                );
            }

            $runtimePath = $this->runtimePath(
                'update-' . $job['version'] . '-' . $id . '.php'
            );
            file_put_contents($runtimePath, $installer, LOCK_EX);
            chmod($runtimePath, 0600);
            $log .= $this->line('Установщик загружен и проверен по SHA-256.');
            $this->lint($runtimePath);
            $log .= $this->line('PHP-синтаксис установщика корректен.');

            $backupPath = $this->createBackup(
                'before-' . $job['version'] . '-' . $id
            );
            $log .= $this->line('Создана полная резервная копия: ' . $backupPath);
            $this->repository->appendLog($id, $log);
            $log = '';

            [$exitCode, $output] = $this->execute([
                PHP_BINARY,
                $runtimePath,
            ], $this->service->projectRoot(), 900);
            $log .= $this->line('Вывод установщика:');
            $log .= $output . PHP_EOL;

            if ($exitCode !== 0) {
                throw new RuntimeException(
                    'Установщик завершился с кодом ' . $exitCode . '.'
                );
            }

            $this->healthCheck();
            $log .= $this->line('Проверка PHP-файлов после обновления пройдена.');
            $this->writeVersion((string) $job['version']);
            $log .= $this->line('Версия системы обновлена.');
            @unlink($runtimePath);

            $this->repository->markFinished(
                $id,
                'installed',
                $log,
                $backupPath
            );
        } catch (\Throwable $exception) {
            $log .= $this->line('ОШИБКА: ' . $exception->getMessage());

            if ($backupPath !== null && is_file($backupPath)) {
                try {
                    $quarantine = $this->restoreBackup($backupPath, 'failed-update');
                    $log .= $this->line(
                        'Рабочая версия восстановлена. Неудачная версия сохранена: '
                        . $quarantine
                    );
                } catch (\Throwable $rollbackException) {
                    $log .= $this->line(
                        'КРИТИЧЕСКАЯ ОШИБКА ОТКАТА: '
                        . $rollbackException->getMessage()
                    );
                }
            }

            $this->repository->markFinished(
                $id,
                'failed',
                $log,
                $backupPath
            );
        }
    }

    private function rollback(array $job): void
    {
        $id = (int) $job['id'];
        $this->repository->markStarted($id, 'rolling_back');
        $log = $this->line('Начинаем откат версии ' . $job['version']);
        $backupPath = (string) ($job['backup_path'] ?? '');

        try {
            if ($backupPath === '' || !is_file($backupPath)) {
                throw new RuntimeException('Файл резервной копии не найден.');
            }

            $currentBackup = $this->createBackup(
                'before-rollback-' . $id
            );
            $log .= $this->line(
                'Текущее состояние сохранено: ' . $currentBackup
            );
            $quarantine = $this->restoreBackup($backupPath, 'rollback-source');
            $log .= $this->line(
                'Резервная копия восстановлена. Предыдущее состояние: '
                . $quarantine
            );
            $this->healthCheck();
            $log .= $this->line('Проверка после отката пройдена.');

            $this->repository->markFinished(
                $id,
                'rolled_back',
                $log,
                $currentBackup
            );
        } catch (\Throwable $exception) {
            $log .= $this->line('ОШИБКА ОТКАТА: ' . $exception->getMessage());
            $this->repository->markFinished(
                $id,
                'rollback_failed',
                $log,
                null
            );
        }
    }

    private function createBackup(string $label): string
    {
        $root = $this->service->projectRoot();
        $config = $this->service->config();
        $backupDirectory = (string) ($config['backup_directory'] ?? '');

        if ($backupDirectory === '') {
            throw new RuntimeException('Не настроен каталог резервных копий.');
        }

        if (
            !is_dir($backupDirectory)
            && !mkdir($backupDirectory, 0700, true)
            && !is_dir($backupDirectory)
        ) {
            throw new RuntimeException('Не удалось создать каталог резервных копий.');
        }

        $archive = rtrim($backupDirectory, '/')
            . '/' . preg_replace('/[^0-9A-Za-z._-]+/', '-', $label)
            . '-' . date('Ymd-His') . '.tar.gz';
        $parent = dirname($root);
        $name = basename($root);
        [$code, $output] = $this->execute([
            'tar',
            '--exclude=' . $name . '/storage/backups',
            '--exclude=' . $name . '/storage/logs',
            '--exclude=' . $name . '/storage/cache',
            '-czf',
            $archive,
            '-C',
            $parent,
            $name,
        ], $parent, 600);

        if ($code !== 0 || !is_file($archive)) {
            throw new RuntimeException(
                'Не удалось создать резервную копию: ' . trim($output)
            );
        }

        chmod($archive, 0600);

        return $archive;
    }

    private function restoreBackup(
        string $archive,
        string $prefix
    ): string {
        $root = $this->service->projectRoot();
        $parent = dirname($root);
        $quarantine = $root . '.' . $prefix . '-' . date('Ymd-His');

        if (!rename($root, $quarantine)) {
            throw new RuntimeException(
                'Не удалось переместить текущую версию перед восстановлением.'
            );
        }

        [$code, $output] = $this->execute([
            'tar',
            '-xzf',
            $archive,
            '-C',
            $parent,
        ], $parent, 600);

        if ($code !== 0 || !is_dir($root)) {
            if (is_dir($root)) {
                $this->removeDirectory($root);
            }
            @rename($quarantine, $root);
            throw new RuntimeException(
                'Не удалось распаковать резервную копию: ' . trim($output)
            );
        }

        return $quarantine;
    }

    private function healthCheck(): void
    {
        $root = $this->service->projectRoot();

        foreach (['index.php', 'api.php', 'app/bootstrap.php'] as $relative) {
            $this->lint($root . '/' . $relative);
        }
    }

    private function lint(string $path): void
    {
        [$code, $output] = $this->execute([
            PHP_BINARY,
            '-l',
            $path,
        ], dirname($path), 60);

        if ($code !== 0) {
            throw new RuntimeException(
                'Ошибка PHP-синтаксиса в ' . $path . ': ' . trim($output)
            );
        }
    }

    private function writeVersion(string $version): void
    {
        $path = $this->service->versionPath();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог версии.');
        }

        $payload = json_encode([
            'version' => $version,
            'updated_at' => date(DATE_ATOM),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        if (!is_string($payload) || file_put_contents($path, $payload . PHP_EOL, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось сохранить номер версии.');
        }

        chmod($path, 0600);
    }

    private function runtimePath(string $filename): string
    {
        $directory = (string) (
            $this->service->config()['runtime_directory'] ?? ''
        );

        if ($directory === '') {
            throw new RuntimeException('Не настроен временный каталог обновлений.');
        }

        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать временный каталог.');
        }

        return rtrim($directory, '/') . '/' . basename($filename);
    }

    private function execute(
        array $command,
        string $workingDirectory,
        int $timeout
    ): array {
        $escaped = implode(' ', array_map('escapeshellarg', $command));
        $fullCommand = 'cd ' . escapeshellarg($workingDirectory)
            . ' && timeout ' . max(1, $timeout) . 's '
            . $escaped . ' 2>&1';
        $output = [];
        $code = 0;
        exec($fullCommand, $output, $code);

        return [$code, implode(PHP_EOL, $output)];
    }

    private function line(string $text): string
    {
        return '[' . date('Y-m-d H:i:s') . '] ' . $text . PHP_EOL;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(
                $path,
                \FilesystemIterator::SKIP_DOTS
            ),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($path);
    }
}
