<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function av12out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function av12read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function av12write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temporary}");
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

function av12lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("Ошибка PHP-синтаксиса в {$path}:\n" . implode("\n", $output));
    }
}

function av12removeCron(string $needle): array
{
    if (!function_exists('exec')) {
        return ['changed' => false, 'reason' => 'exec недоступен'];
    }

    $lines = [];
    $code = 0;
    exec('crontab -l 2>/dev/null', $lines, $code);
    if ($code !== 0 && $lines === []) {
        return ['changed' => false, 'reason' => 'crontab отсутствует'];
    }

    $filtered = array_values(array_filter(
        $lines,
        static fn(string $line): bool => !str_contains($line, $needle)
    ));

    if ($filtered === $lines) {
        return ['changed' => false, 'reason' => 'старая Cron-задача не найдена'];
    }

    $temporary = tempnam(sys_get_temp_dir(), 'availability-cron-cleanup-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Не удалось создать временный файл Cron.');
    }

    file_put_contents($temporary, implode(PHP_EOL, $filtered) . PHP_EOL, LOCK_EX);
    $output = [];
    $installCode = 0;
    exec('crontab ' . escapeshellarg($temporary) . ' 2>&1', $output, $installCode);
    @unlink($temporary);

    if ($installCode !== 0) {
        throw new RuntimeException('Не удалось обновить Cron: ' . implode("\n", $output));
    }

    return ['changed' => true, 'reason' => 'старая Cron-задача удалена'];
}

$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$legacyRepository = $root . '/app/Repositories/SiteMonitorRepository.php';
$legacyService = $root . '/app/Services/SiteMonitorService.php';
$legacyWorker = $root . '/bin/run_site_monitor.php';

foreach ([$indexPath, $apiPath, $jsPath, $cssPath, $schemaPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$index = av12read($indexPath);
$api = av12read($apiPath);
$js = av12read($jsPath);
$css = av12read($cssPath);
$schema = av12read($schemaPath);

if (
    str_contains($index, 'AVAILABILITY_LEGACY_REMOVED_V12')
    && !str_contains($index, 'section-availability')
    && !str_contains($api, 'availability_dashboard')
) {
    av12out('Старый раздел «Доступность сайта» уже удалён.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/remove-legacy-availability-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$backupFiles = [
    $indexPath => 'index.php',
    $apiPath => 'api.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
    $schemaPath => 'schema.sql',
    $legacyRepository => 'SiteMonitorRepository.php',
    $legacyService => 'SiteMonitorService.php',
    $legacyWorker => 'run_site_monitor.php',
];

foreach ($backupFiles as $source => $name) {
    if (is_file($source) && !copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException("Не удалось сохранить резервную копию {$name}");
    }
}

$cronBefore = [];
if (function_exists('exec')) {
    $cronCode = 0;
    exec('crontab -l 2>/dev/null', $cronBefore, $cronCode);
    file_put_contents(
        $backupDirectory . '/crontab.txt',
        implode(PHP_EOL, $cronBefore) . PHP_EOL,
        LOCK_EX
    );
}

$getBlock = <<<'PHPBLOCK'
        // AVAILABILITY_MODULE_API_GET
        if ($action === 'availability_dashboard') {
            $project = $projectRepository->firstActive();

            if (!$project) {
                Security::json(
                    ['error' => 'Проект не настроен.'],
                    422
                );
            }

            [$dateFrom, $dateTo] = validateDates(
                trim((string) ($_GET['date1'] ?? '')),
                trim((string) ($_GET['date2'] ?? ''))
            );

            $data = (new SiteMonitorService())->dashboard(
                $project,
                $dateFrom,
                $dateTo
            );

            Security::json([
                'data' => $data,
            ]);
        }
PHPBLOCK;

$postBlock = <<<'PHPBLOCK'
    // AVAILABILITY_MODULE_API_POST
    if ($action === 'run_availability_check') {
        $project = $projectRepository->firstActive();

        if (!$project) {
            Security::json(
                ['error' => 'Проект не настроен.'],
                422
            );
        }

        $check = (new SiteMonitorService())->checkProject($project);

        Auth::audit('site_availability_checked', [
            'project_id' => (int) $project['id'],
            'status' => $check['status'],
            'http_code' => $check['http_code'],
            'response_ms' => $check['response_ms'],
        ]);

        Security::json([
            'ok' => true,
            'check' => $check,
        ]);
    }
PHPBLOCK;

try {
    // Удаляем пункт меню и старый раздел. Поддерживает повторные вставки.
    $index = preg_replace(
        '#\s*<button class="nav-link" data-section="availability">\s*Доступность сайта\s*</button>\s*#u',
        "\n",
        $index
    ) ?? $index;
    $index = preg_replace(
        '#\s*<!-- AVAILABILITY_MODULE -->\s*<section id="section-availability" class="section">.*?</section>\s*#s',
        "\n",
        $index
    ) ?? $index;

    if (!str_contains($index, 'AVAILABILITY_LEGACY_REMOVED_V12')) {
        $index = str_replace(
            '</body>',
            "    <!-- AVAILABILITY_LEGACY_REMOVED_V12 -->\n</body>",
            $index
        );
    }

    // Удаляем старые API-маршруты и импорт.
    $api = str_replace(
        [
            "use SeoAnalytics\\Services\\SiteMonitorService;\n",
            "use SeoAnalytics\\Services\\SiteMonitorService;\r\n",
            $getBlock . "\n",
            $getBlock . "\r\n",
            $postBlock . "\n",
            $postBlock . "\r\n",
        ],
        '',
        $api
    );

    // Страховочная очистка маршрутов, если пробелы менялись последующими патчами.
    $api = preg_replace(
        '#\s*// AVAILABILITY_MODULE_API_GET\s*if \(\$action === \'availability_dashboard\'\) \{.*?\n\s*\}\s*(?=//|if \(|\})#s',
        "\n",
        $api
    ) ?? $api;
    $api = preg_replace(
        '#\s*// AVAILABILITY_MODULE_API_POST\s*if \(\$action === \'run_availability_check\'\) \{.*?\n\s*\}\s*(?=//|if \(|\})#s',
        "\n",
        $api
    ) ?? $api;

    // Удаляем JavaScript старого раздела.
    $js = preg_replace(
        '#\n?\s*/\* AVAILABILITY_MODULE_JS \*/.*?\n\s*\$\(\'#runAvailabilityCheck\'\)\?\.addEventListener\(\s*\'click\',\s*runAvailabilityCheck\s*\);\s*#s',
        "\n",
        $js
    ) ?? $js;

    // Удаляем CSS старого раздела до следующего модульного комментария.
    $css = preg_replace(
        '#\n*/\* AVAILABILITY_MODULE_CSS \*/.*?(?=\n/\* [A-Z0-9_][^*]*\*/|\z)#s',
        "\n",
        $css
    ) ?? $css;

    // Старые таблицы не удаляем из живой БД, но убираем из схемы новых установок.
    $schema = preg_replace('#\n*-- AVAILABILITY_MODULE_SCHEMA\s*#', "\n", $schema) ?? $schema;
    foreach (['site_monitors', 'site_monitor_checks', 'site_monitor_incidents'] as $table) {
        $pattern = '#\n*CREATE TABLE IF NOT EXISTS ' . preg_quote($table, '#')
            . ' \(.*?\) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;\s*#s';
        $schema = preg_replace($pattern, "\n", $schema) ?? $schema;
    }

    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=32', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=32', $index) ?? $index;

    av12write($indexPath, $index);
    av12write($apiPath, $api);
    av12write($jsPath, $js);
    av12write($cssPath, $css);
    av12write($schemaPath, $schema);

    av12lint($indexPath);
    av12lint($apiPath);

    foreach ([$legacyRepository, $legacyService, $legacyWorker] as $legacyFile) {
        if (is_file($legacyFile) && !unlink($legacyFile)) {
            throw new RuntimeException('Не удалось удалить старый файл: ' . $legacyFile);
        }
    }

    $cronResult = av12removeCron('run_site_monitor.php');

    av12out('Старый модуль «Доступность сайта» удалён.');
    av12out('- удалён пункт меню;');
    av12out('- удалён старый раздел интерфейса;');
    av12out('- удалены API-маршруты, JavaScript и CSS;');
    av12out('- удалены SiteMonitorService и SiteMonitorRepository;');
    av12out('- удалён старый worker run_site_monitor.php;');
    av12out('- ' . $cronResult['reason'] . ';');
    av12out('- legacy-таблицы сохранены в базе как резерв;');
    av12out('- из schema.sql legacy-таблицы удалены.');
    av12out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $name) {
        $source = $backupDirectory . '/' . $name;
        if (is_file($source)) {
            @copy($source, $destination);
        }
    }

    if (function_exists('exec') && is_file($backupDirectory . '/crontab.txt')) {
        $cronTemp = tempnam(sys_get_temp_dir(), 'availability-cron-restore-');
        if (is_string($cronTemp)) {
            @copy($backupDirectory . '/crontab.txt', $cronTemp);
            @exec('crontab ' . escapeshellarg($cronTemp) . ' 2>/dev/null');
            @unlink($cronTemp);
        }
    }

    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
