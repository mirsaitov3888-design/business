<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function p04out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function p04read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function p04write(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог: ' . $directory);
    }

    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный файл: ' . $temporary);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить файл: ' . $path);
    }
}

function p04lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }

    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1',
        $output,
        $code
    );
    if ($code !== 0) {
        throw new RuntimeException(
            'Ошибка PHP-синтаксиса в ' . $path . ':\n' . implode("\n", $output)
        );
    }
}

function p04installCron(string $scriptPath, string $logPath): array
{
    if (!function_exists('exec')) {
        return [
            'installed' => false,
            'message' => 'Функция exec недоступна; ежедневный запуск не добавлен.',
        ];
    }

    $check = [];
    $checkCode = 0;
    exec('command -v crontab 2>/dev/null', $check, $checkCode);
    if ($checkCode !== 0 || trim(implode('', $check)) === '') {
        return [
            'installed' => false,
            'message' => 'crontab недоступен; модуль можно запускать из bin/system_retention.php.',
        ];
    }

    $current = [];
    $currentCode = 0;
    exec('crontab -l 2>/dev/null', $current, $currentCode);
    if ($currentCode !== 0) {
        $current = [];
    }

    $marker = '# Mirsaitov system retention';
    $lines = [];
    foreach ($current as $line) {
        if (!str_contains($line, $marker) && !str_contains($line, $scriptPath)) {
            $lines[] = $line;
        }
    }

    $lines[] = '35 4 * * * '
        . escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($scriptPath)
        . ' >> '
        . escapeshellarg($logPath)
        . ' 2>&1 '
        . $marker;

    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-retention-cron-');
    if (!is_string($temporary)) {
        return [
            'installed' => false,
            'message' => 'Не удалось создать временный файл crontab.',
        ];
    }

    file_put_contents($temporary, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    $output = [];
    $code = 0;
    exec('crontab ' . escapeshellarg($temporary) . ' 2>&1', $output, $code);
    @unlink($temporary);

    return [
        'installed' => $code === 0,
        'message' => $code === 0
            ? 'Ежедневный запуск установлен на 04:35 серверного времени.'
            : 'Не удалось обновить crontab: ' . implode(' ', $output),
    ];
}

$root = getcwd() ?: '';
if (!is_dir($root . '/app') || !is_dir($root . '/bin')) {
    throw new RuntimeException('Запустите установщик из корня проекта.');
}

$servicePath = $root . '/app/Services/SystemRetentionService.php';
$cliPath = $root . '/bin/system_retention.php';
$dataRoot = dirname($root, 2);
$retentionLog = $dataRoot . '/system-retention.log';

$backupDirectory = $root . '/storage/backups/p0-retention-module-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию модуля.');
}

$existed = [];
foreach ([$servicePath, $cliPath] as $path) {
    if (is_file($path)) {
        $existed[$path] = true;
        if (!copy($path, $backupDirectory . '/' . basename($path))) {
            throw new RuntimeException('Не удалось сохранить резервную копию: ' . $path);
        }
    }
}

$service = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class SystemRetentionService
{
    private const MARKER = 'SYSTEM_RETENTION_V180209';

    private readonly string $siteRoot;
    private readonly string $dataRoot;
    private readonly string $wwwRoot;
    private array $actions = [];

    public function __construct(?string $siteRoot = null)
    {
        $resolved = realpath($siteRoot ?? dirname(__DIR__, 2));
        if (!is_string($resolved) || !is_dir($resolved)) {
            throw new RuntimeException('Не удалось определить корень портала.');
        }

        $this->siteRoot = $resolved;
        $this->dataRoot = dirname($resolved, 2);
        $this->wwwRoot = dirname($resolved);
    }

    public function run(bool $dryRun = false): array
    {
        $this->actions = [];
        $startedAt = microtime(true);

        $this->pruneSystemUpdateBackups($dryRun);
        $this->pruneApplicationBackups($dryRun);
        $this->pruneFailedUpdates($dryRun);
        $this->pruneDiagnostics($dryRun);
        $this->pruneAudits($dryRun);
        $this->rotateLogs($dryRun);

        $summary = [
            'version' => '2026.08.02.9',
            'marker' => self::MARKER,
            'generated_at' => date(DATE_ATOM),
            'dry_run' => $dryRun,
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
            'policy' => $this->policy(),
            'counts' => $this->countActions(),
            'actions' => $this->actions,
        ];

        $auditDirectory = $this->siteRoot . '/storage/system-audits';
        if (!is_dir($auditDirectory) && !mkdir($auditDirectory, 0700, true) && !is_dir($auditDirectory)) {
            throw new RuntimeException('Не удалось создать каталог системных аудитов.');
        }

        $latestPath = $auditDirectory . '/retention-latest.json';
        $historyPath = $auditDirectory . '/retention-' . date('Ymd-His') . '.json';
        $json = json_encode(
            $summary,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
        ) . PHP_EOL;

        file_put_contents($latestPath, $json, LOCK_EX);
        file_put_contents($historyPath, $json, LOCK_EX);

        return $summary;
    }

    public function policy(): array
    {
        return [
            'system_update_backups' => [
                'minimum_to_keep' => 10,
                'delete_only_after_days' => 45,
            ],
            'application_backups' => [
                'minimum_per_category' => 3,
                'delete_only_after_days' => 45,
            ],
            'failed_updates' => [
                'minimum_to_keep' => 3,
                'delete_only_after_days' => 14,
            ],
            'diagnostic_reports' => [
                'minimum_to_keep' => 100,
                'delete_only_after_days' => 180,
            ],
            'system_audits' => [
                'minimum_to_keep' => 50,
                'delete_only_after_days' => 180,
            ],
            'logs' => [
                'rotate_after_bytes' => 20 * 1024 * 1024,
                'minimum_archives_to_keep' => 5,
                'delete_archives_after_days' => 30,
            ],
        ];
    }

    private function pruneSystemUpdateBackups(bool $dryRun): void
    {
        $directory = $this->dataRoot . '/system-update-backups';
        $paths = is_dir($directory)
            ? glob($directory . '/*') ?: []
            : [];
        $this->prunePaths(
            $paths,
            10,
            45,
            'system_update_backup',
            $dryRun,
            [$directory]
        );
    }

    private function pruneApplicationBackups(bool $dryRun): void
    {
        $directory = $this->siteRoot . '/storage/backups';
        if (!is_dir($directory)) {
            return;
        }

        $groups = [];
        foreach (glob($directory . '/*') ?: [] as $path) {
            if (is_link($path)) {
                continue;
            }
            $groups[$this->backupCategory(basename($path))][] = $path;
        }

        foreach ($groups as $category => $paths) {
            $this->prunePaths(
                $paths,
                3,
                45,
                'application_backup:' . $category,
                $dryRun,
                [$directory]
            );
        }
    }

    private function pruneFailedUpdates(bool $dryRun): void
    {
        $pattern = $this->wwwRoot . '/' . basename($this->siteRoot) . '.failed-update-*';
        $paths = glob($pattern, GLOB_ONLYDIR) ?: [];
        $this->prunePaths(
            $paths,
            3,
            14,
            'failed_update',
            $dryRun,
            [$this->wwwRoot]
        );
    }

    private function pruneDiagnostics(bool $dryRun): void
    {
        $directory = $this->dataRoot . '/mirsaitov-diagnostics';
        if (!is_dir($directory)) {
            return;
        }

        $paths = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
                continue;
            }
            $name = $file->getFilename();
            if ($name === 'latest.json' || $name === 'index.php' || str_starts_with($name, '.')) {
                continue;
            }
            if (
                preg_match('~^(update-|report-|diagnostic-|\d{6,})~i', $name) === 1
                && in_array(strtolower($file->getExtension()), ['json', 'html'], true)
            ) {
                $paths[] = $file->getPathname();
            }
        }

        $this->prunePaths(
            $paths,
            100,
            180,
            'diagnostic_report',
            $dryRun,
            [$directory]
        );
    }

    private function pruneAudits(bool $dryRun): void
    {
        $directory = $this->siteRoot . '/storage/system-audits';
        if (!is_dir($directory)) {
            return;
        }

        $paths = array_values(array_filter(
            glob($directory . '/*.json') ?: [],
            static fn(string $path): bool => basename($path) !== 'retention-latest.json'
        ));
        $this->prunePaths(
            $paths,
            50,
            180,
            'system_audit',
            $dryRun,
            [$directory]
        );
    }

    private function rotateLogs(bool $dryRun): void
    {
        foreach ([
            $this->dataRoot . '/system-update-worker.log',
            $this->dataRoot . '/site-monitor-worker.log',
            $this->dataRoot . '/system-retention.log',
        ] as $logPath) {
            if (!is_file($logPath) || is_link($logPath)) {
                continue;
            }

            $size = filesize($logPath);
            if (is_int($size) && $size > 20 * 1024 * 1024) {
                $archive = $logPath . '.' . date('Ymd-His') . '.log';
                $this->actions[] = [
                    'category' => 'log_rotation',
                    'path' => $logPath,
                    'action' => $dryRun ? 'would_rotate' : 'rotated',
                    'size_bytes' => $size,
                    'archive' => $archive,
                ];
                if (!$dryRun) {
                    if (!rename($logPath, $archive)) {
                        throw new RuntimeException('Не удалось ротировать лог: ' . $logPath);
                    }
                    if (file_put_contents($logPath, '') === false) {
                        throw new RuntimeException('Не удалось создать новый лог: ' . $logPath);
                    }
                }
            }

            $archives = glob($logPath . '.*.log') ?: [];
            $this->prunePaths(
                $archives,
                5,
                30,
                'log_archive',
                $dryRun,
                [dirname($logPath)]
            );
        }
    }

    private function prunePaths(
        array $paths,
        int $minimumToKeep,
        int $deleteOnlyAfterDays,
        string $category,
        bool $dryRun,
        array $allowedRoots
    ): void {
        $items = [];
        foreach (array_unique($paths) as $path) {
            if ((!is_file($path) && !is_dir($path)) || is_link($path)) {
                continue;
            }
            $mtime = filemtime($path);
            $items[] = [
                'path' => $path,
                'mtime' => is_int($mtime) ? $mtime : 0,
            ];
        }

        usort(
            $items,
            static fn(array $left, array $right): int => $right['mtime'] <=> $left['mtime']
        );
        $cutoff = time() - ($deleteOnlyAfterDays * 86400);

        foreach ($items as $index => $item) {
            $eligible = $index >= $minimumToKeep && $item['mtime'] < $cutoff;
            if (!$eligible) {
                continue;
            }

            $this->assertAllowed($item['path'], $allowedRoots);
            $size = $this->pathSize($item['path']);
            $this->actions[] = [
                'category' => $category,
                'path' => $item['path'],
                'action' => $dryRun ? 'would_delete' : 'deleted',
                'mtime' => date(DATE_ATOM, $item['mtime']),
                'size_bytes' => $size,
            ];

            if (!$dryRun) {
                $this->deletePath($item['path'], $allowedRoots);
            }
        }
    }

    private function backupCategory(string $name): string
    {
        $category = preg_replace(
            '~[-_]\d{8}[-_]\d{6}(?:\..*)?$~',
            '',
            $name
        );
        if (!is_string($category) || $category === $name) {
            $category = preg_replace('~[-_]\d{8}(?:\..*)?$~', '', $name);
        }
        return is_string($category) && $category !== '' ? $category : 'other';
    }

    private function assertAllowed(string $path, array $allowedRoots): void
    {
        $candidate = realpath($path);
        if (!is_string($candidate)) {
            throw new RuntimeException('Не удалось проверить путь: ' . $path);
        }

        foreach ($allowedRoots as $root) {
            $resolvedRoot = realpath($root);
            if (
                is_string($resolvedRoot)
                && ($candidate === $resolvedRoot || str_starts_with($candidate, $resolvedRoot . DIRECTORY_SEPARATOR))
            ) {
                return;
            }
        }

        throw new RuntimeException('Попытка удалить путь за пределами разрешённого каталога: ' . $path);
    }

    private function deletePath(string $path, array $allowedRoots): void
    {
        $this->assertAllowed($path, $allowedRoots);

        if (is_file($path)) {
            if (!unlink($path)) {
                throw new RuntimeException('Не удалось удалить файл: ' . $path);
            }
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (!$item instanceof SplFileInfo || $item->isLink()) {
                continue;
            }
            if ($item->isDir()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Не удалось удалить каталог: ' . $item->getPathname());
                }
            } elseif (!unlink($item->getPathname())) {
                throw new RuntimeException('Не удалось удалить файл: ' . $item->getPathname());
            }
        }

        if (!rmdir($path)) {
            throw new RuntimeException('Не удалось удалить каталог: ' . $path);
        }
    }

    private function pathSize(string $path): int
    {
        if (is_file($path)) {
            $size = filesize($path);
            return is_int($size) ? $size : 0;
        }

        $total = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item instanceof SplFileInfo && $item->isFile() && !$item->isLink()) {
                $total += $item->getSize();
            }
        }
        return $total;
    }

    private function countActions(): array
    {
        $counts = [
            'deleted' => 0,
            'would_delete' => 0,
            'rotated' => 0,
            'would_rotate' => 0,
            'bytes_released' => 0,
            'bytes_that_would_be_released' => 0,
        ];

        foreach ($this->actions as $action) {
            $type = (string) ($action['action'] ?? '');
            if (array_key_exists($type, $counts)) {
                $counts[$type]++;
            }
            $size = (int) ($action['size_bytes'] ?? 0);
            if ($type === 'deleted') {
                $counts['bytes_released'] += $size;
            } elseif ($type === 'would_delete') {
                $counts['bytes_that_would_be_released'] += $size;
            }
        }

        return $counts;
    }
}
PHPFILE;

$cli = <<<'PHPFILE'
<?php
declare(strict_types=1);

use SeoAnalytics\Services\SystemRetentionService;

require dirname(__DIR__) . '/app/Services/SystemRetentionService.php';

$dryRun = in_array('--dry-run', $argv, true);
$service = new SystemRetentionService(dirname(__DIR__));
$result = $service->run($dryRun);

echo json_encode(
    $result,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT
) . PHP_EOL;
PHPFILE;

try {
    p04write($servicePath, $service);
    p04write($cliPath, $cli);
    @chmod($cliPath, 0755);
    p04lint($servicePath);
    p04lint($cliPath);

    require_once $servicePath;
    $retention = new \SeoAnalytics\Services\SystemRetentionService($root);
    $result = $retention->run(false);
    $cron = p04installCron($cliPath, $retentionLog);

    p04out('P0.4 — политика хранения установлена.');
    p04out('- удалено объектов: ' . (int) ($result['counts']['deleted'] ?? 0) . ';');
    p04out('- освобождено байт: ' . (int) ($result['counts']['bytes_released'] ?? 0) . ';');
    p04out('- ротировано логов: ' . (int) ($result['counts']['rotated'] ?? 0) . ';');
    p04out('- свежие failed-update и последние резервные копии сохранены;');
    p04out('- ' . $cron['message']);
    p04out('- отчёт: ' . $root . '/storage/system-audits/retention-latest.json;');
} catch (Throwable $exception) {
    foreach ([$servicePath, $cliPath] as $path) {
        $backup = $backupDirectory . '/' . basename($path);
        if (is_file($backup)) {
            @copy($backup, $path);
        } elseif (!isset($existed[$path])) {
            @unlink($path);
        }
    }

    throw new RuntimeException(
        $exception->getMessage() . ' Файлы модуля восстановлены.',
        0,
        $exception
    );
}
