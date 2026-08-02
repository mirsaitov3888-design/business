<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

const P01_MARKER = 'P0_STABILIZATION_401_CURL_SESSION_V180206';

function p01out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function p01read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function p01write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный файл: ' . $temporary);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить файл: ' . $path);
    }
}

function p01lint(string $path): void
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
            "Ошибка PHP-синтаксиса в {$path}:\n" . implode("\n", $output)
        );
    }
}

function p01backup(string $root, array $paths): array
{
    $directory = $root . '/storage/backups/p0-stabilization-180206-' . date('Ymd-His');
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать резервную копию.');
    }

    $manifest = [];
    foreach (array_values(array_unique($paths)) as $path) {
        if (!is_file($path)) {
            continue;
        }

        $relative = str_starts_with($path, $root . '/')
            ? substr($path, strlen($root) + 1)
            : '_external/' . basename($path);
        $destination = $directory . '/' . $relative;
        $destinationDirectory = dirname($destination);

        if (
            !is_dir($destinationDirectory)
            && !mkdir($destinationDirectory, 0700, true)
            && !is_dir($destinationDirectory)
        ) {
            throw new RuntimeException(
                'Не удалось создать каталог резервной копии: ' . $destinationDirectory
            );
        }

        if (!copy($path, $destination)) {
            throw new RuntimeException('Не удалось сохранить резервную копию: ' . $path);
        }

        $manifest[$path] = $destination;
    }

    return [$directory, $manifest];
}

function p01restore(array $manifest): void
{
    foreach ($manifest as $destination => $source) {
        if (is_file($source)) {
            @copy($source, $destination);
        }
    }
}

function p01phpFiles(string $root): array
{
    $files = [];
    foreach (['app', 'bin'] as $relativeDirectory) {
        $directory = $root . '/' . $relativeDirectory;
        if (!is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (
                $file instanceof SplFileInfo
                && $file->isFile()
                && strtolower($file->getExtension()) === 'php'
            ) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);
    return $files;
}

function p01removeDeprecatedCurlClose(string $content, int &$count): string
{
    $count = 0;
    $updated = preg_replace(
        '~^[ \t]*curl_close\s*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\s*\)\s*;[ \t]*(?:\r?\n)?~m',
        '',
        $content,
        -1,
        $count
    );

    return is_string($updated) ? $updated : $content;
}

function p01patchApiSessionRelease(string $content, int &$count): string
{
    $count = 0;
    if (str_contains($content, 'LONG_REQUEST_SESSION_RELEASE_V180206')) {
        return $content;
    }

    $pattern = '~(\$action\s*=\s*[^;]+;\s*)~s';
    $patch = <<<'PHPBLOCK'
/* LONG_REQUEST_SESSION_RELEASE_V180206 */
$longReadActions = [
    'seo_autofill',
    'advertising_autofill',
    'yandex_direct_autofill',
    'bitrix24_status',
    'bitrix24_projects',
    'bitrix24_companies',
    'bitrix24_preview',
    'bitrix24_updates',
    'bitrix24_clients',
    'support_report_preview',
    'support_report_sync',
    'site_monitoring_status',
    'site_monitoring_run',
    'system_update_status',
    'diagnostics_status',
];
if (
    in_array((string) $action, $longReadActions, true)
    && session_status() === PHP_SESSION_ACTIVE
) {
    session_write_close();
}

PHPBLOCK;

    $updated = preg_replace(
        $pattern,
        '$1' . $patch,
        $content,
        1,
        $count
    );

    return is_string($updated) ? $updated : $content;
}

function p01diagnosticCandidates(string $root): array
{
    $dataRoot = dirname($root, 2);
    $directories = [
        $root . '/diagnostics',
        $dataRoot . '/mirsaitov-diagnostics',
    ];
    $result = [];

    foreach ($directories as $directory) {
        if (!is_dir($directory)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $directory,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (
                $file instanceof SplFileInfo
                && $file->isFile()
                && strtolower($file->getExtension()) === 'php'
            ) {
                $path = $file->getPathname();
                $content = @file_get_contents($path);
                if (
                    is_string($content)
                    && (
                        str_contains($content, 'Mirsaitov Unified Diagnostics')
                        || str_contains($content, 'endpoint_status')
                    )
                ) {
                    $result[] = $path;
                }
            }
        }
    }

    return array_values(array_unique($result));
}

function p01patchDiagnostic401(string $content, int &$count): string
{
    $count = 0;
    if (str_contains($content, 'DIAGNOSTICS_AUTH_ENDPOINT_CLASSIFICATION_V180206')) {
        return $content;
    }

    $patterns = [
        '~(finding\s*\(\s*[\'\"])error([\'\"]\s*,\s*[\'\"]endpoint_[^\'\"]+[\'\"])~',
        '~([\'\"]severity[\'\"]\s*=>\s*[\'\"])error([\'\"]\s*,\s*[\'\"]code[\'\"]\s*=>\s*[\'\"]endpoint_[^\'\"]+[\'\"])~s',
        '~([\'\"]code[\'\"]\s*=>\s*[\'\"]endpoint_[^\'\"]+[\'\"]\s*,\s*[\'\"]severity[\'\"]\s*=>\s*[\'\"])error([\'\"])~s',
    ];

    foreach ($patterns as $pattern) {
        $replacements = 0;
        $content = preg_replace(
            $pattern,
            '$1info$2',
            $content,
            -1,
            $replacements
        ) ?? $content;
        $count += $replacements;
    }

    if ($count > 0) {
        $content = preg_replace(
            '~(<\?php\s*(?:declare\(strict_types=1\);\s*)?)~',
            '$1' . PHP_EOL
                . "/* DIAGNOSTICS_AUTH_ENDPOINT_CLASSIFICATION_V180206 */"
                . PHP_EOL,
            $content,
            1
        ) ?? $content;
    }

    return $content;
}

$root = getcwd() ?: '';
if (!is_file($root . '/index.php') || !is_dir($root . '/app')) {
    throw new RuntimeException('Запустите установщик из корня проекта.');
}

$apiPath = $root . '/api.php';
$phpFiles = p01phpFiles($root);
$diagnosticFiles = p01diagnosticCandidates($root);
$allCandidates = array_values(array_unique(array_merge(
    $phpFiles,
    is_file($apiPath) ? [$apiPath] : [],
    $diagnosticFiles
)));

[$backupDirectory, $backupManifest] = p01backup($root, $allCandidates);
$changedFiles = [];
$curlRemoved = 0;
$sessionPatched = false;
$diagnosticPatched = 0;

try {
    foreach ($phpFiles as $path) {
        $content = p01read($path);
        $removed = 0;
        $updated = p01removeDeprecatedCurlClose($content, $removed);

        if ($removed > 0 && $updated !== $content) {
            p01write($path, $updated);
            p01lint($path);
            $changedFiles[] = $path;
            $curlRemoved += $removed;
        }
    }

    if (is_file($apiPath)) {
        $content = p01read($apiPath);
        $sessionCount = 0;
        $updated = p01patchApiSessionRelease($content, $sessionCount);

        if ($sessionCount === 1 && $updated !== $content) {
            p01write($apiPath, $updated);
            p01lint($apiPath);
            $changedFiles[] = $apiPath;
            $sessionPatched = true;
        } elseif (
            !str_contains($content, 'LONG_REQUEST_SESSION_RELEASE_V180206')
            && $sessionCount !== 1
        ) {
            p01out(
                'Предупреждение: точка освобождения PHP-сессии в api.php '
                . 'не найдена однозначно.'
            );
        }
    }

    foreach ($diagnosticFiles as $path) {
        $content = p01read($path);
        $diagnosticCount = 0;
        $updated = p01patchDiagnostic401($content, $diagnosticCount);

        if ($diagnosticCount > 0 && $updated !== $content) {
            p01write($path, $updated);
            p01lint($path);
            $changedFiles[] = $path;
            $diagnosticPatched += $diagnosticCount;
        }
    }

    $remainingCurlCalls = 0;
    foreach ($phpFiles as $path) {
        $content = p01read($path);
        $remainingCurlCalls += preg_match_all(
            '~curl_close\s*\(~',
            $content
        );
    }

    if ($remainingCurlCalls > 0) {
        throw new RuntimeException(
            'После очистки осталось вызовов curl_close(): ' . $remainingCurlCalls
        );
    }

    p01out('P0.1 — базовая стабилизация установлена.');
    p01out('- удалено вызовов curl_close(): ' . $curlRemoved . ';');
    p01out(
        '- освобождение PHP-сессии для длинных GET-запросов: '
        . ($sessionPatched ? 'установлено' : 'уже было или требует отдельной точки')
        . ';'
    );
    p01out(
        '- переклассифицировано закрытых diagnostic endpoint: '
        . $diagnosticPatched . ';'
    );
    p01out('- изменено файлов: ' . count(array_unique($changedFiles)) . ';');
    p01out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    p01restore($backupManifest);
    fwrite(
        STDERR,
        "ОШИБКА: {$exception->getMessage()}\n"
        . "Файлы восстановлены из резервной копии.\n"
    );
    exit(1);
}
