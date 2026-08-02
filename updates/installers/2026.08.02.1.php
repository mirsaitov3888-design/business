<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function h180201out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function h180201read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function h180201write(string $path, string $content): void
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

function h180201lint(string $path): void
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

$root = getcwd() ?: '';
$repositoryPath = $root . '/app/Repositories/MonitoringRepository.php';
$jsPath = $root . '/assets/app.js';

foreach ([$repositoryPath, $jsPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$repository = h180201read($repositoryPath);
$js = h180201read($jsPath);

$repositoryFixed = str_contains($repository, ':details_present')
    && str_contains($repository, ':details_append');
$jsFixed = str_contains($js, 'GLOBAL_DATA_MUTATION_LOOP_HOTFIX_V180201');

if ($repositoryFixed && $jsFixed) {
    h180201out('Исправления мониторинга и интерфейса уже установлены.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/monitoring-bitrix-hotfix-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$backupFiles = [
    $repositoryPath => $backupDirectory . '/MonitoringRepository.php',
    $jsPath => $backupDirectory . '/app.js',
];

foreach ($backupFiles as $source => $destination) {
    if (!copy($source, $destination)) {
        throw new RuntimeException('Не удалось сохранить резервную копию: ' . basename($source));
    }
}

try {
    if (!$repositoryFixed) {
        $repository = str_replace(
            'CASE WHEN :details <>',
            'CASE WHEN :details_present <>',
            $repository,
            $conditionCount
        );

        $repository = str_replace(
            '"\\n", :details) ELSE details END',
            '"\\n", :details_append) ELSE details END',
            $repository,
            $appendCount
        );

        $oldParams = <<<'PHPBLOCK'
            'id' => $incident['id'],
            'details' => mb_substr($details, 0, 10000),
PHPBLOCK;

        $newParams = <<<'PHPBLOCK'
            'id' => $incident['id'],
            'details_present' => mb_substr($details, 0, 10000),
            'details_append' => mb_substr($details, 0, 10000),
PHPBLOCK;

        $repository = str_replace(
            $oldParams,
            $newParams,
            $repository,
            $paramsCount
        );

        if ($conditionCount !== 1 || $appendCount !== 1 || $paramsCount !== 1) {
            throw new RuntimeException(
                'Не удалось однозначно исправить повторное использование :details '
                . "(condition={$conditionCount}, append={$appendCount}, params={$paramsCount})."
            );
        }

        if (str_contains($repository, 'CASE WHEN :details <>')
            || !str_contains($repository, ':details_present')
            || !str_contains($repository, ':details_append')) {
            throw new RuntimeException('Проверка исправления SQL-параметров не пройдена.');
        }

        h180201write($repositoryPath, $repository);
        h180201lint($repositoryPath);
    }

    if (!$jsFixed) {
        $oldJs = <<<'JSBLOCK'
            const body = table.tBodies[0];
            if (!body) return;
            const rows = Array.from(body.rows);
JSBLOCK;

        $newJs = <<<'JSBLOCK'
            const body = table.tBodies[0];
            if (!body) return;
            /* GLOBAL_DATA_MUTATION_LOOP_HOTFIX_V180201 */
            if (body.querySelector('.global-data-loading-row')) return;
            const rows = Array.from(body.rows);
JSBLOCK;

        $js = str_replace($oldJs, $newJs, $js, $jsCount);

        if ($jsCount !== 1
            || !str_contains($js, 'GLOBAL_DATA_MUTATION_LOOP_HOTFIX_V180201')) {
            throw new RuntimeException(
                'Не удалось установить защиту от цикла MutationObserver '
                . "(replacements={$jsCount})."
            );
        }

        h180201write($jsPath, $js);
    }

    h180201out('Hotfix мониторинга и раздела Битрикс24 установлен.');
    h180201out('- устранена SQLSTATE[HY093] при закрытии инцидента мониторинга;');
    h180201out('- устранён бесконечный цикл перерисовки строки «Загрузка данных»;');
    h180201out('- раздел Битрикс24 больше не должен блокировать вкладку браузера;');
    h180201out('- PHP-файлы проверены после изменения;');
    h180201out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $source) {
        if (is_file($source)) {
            @copy($source, $destination);
        }
    }

    fwrite(
        STDERR,
        "ОШИБКА: {$exception->getMessage()}\n"
        . "Файлы восстановлены из резервной копии.\n"
    );
    exit(1);
}
