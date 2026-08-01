<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function suOut(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function suRoot(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/api.php')
        || !is_file($root . '/assets/app.js')
        || !is_file($root . '/assets/app.css')
        || !is_file($root . '/app/bootstrap.php')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function suRead(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function suDownload(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov System Updates Installer/1.0',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = file_get_contents($url, false, $context);

    if (!is_string($content) || $content === '') {
        throw new RuntimeException(
            "Не удалось загрузить компонент: {$url}"
        );
    }

    return $content;
}

function suReplaceOnce(
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $position = strpos($content, $needle);

    if ($position === false) {
        throw new RuntimeException(
            "Не найдена точка изменения: {$label}"
        );
    }

    return substr($content, 0, $position)
        . $replacement
        . substr($content, $position + strlen($needle));
}

function suInsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return suReplaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function suWrite(string $path, string $content): void
{
    $directory = dirname($path);

    if (
        !is_dir($directory)
        && !mkdir($directory, 0775, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            "Не удалось создать каталог {$directory}"
        );
    }

    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException(
            "Не удалось записать {$temporary}"
        );
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException(
            "Не удалось заменить {$path}"
        );
    }
}

function suBackup(
    string $root,
    array $paths,
    string $backupDirectory
): array {
    if (
        !mkdir($backupDirectory, 0700, true)
        && !is_dir($backupDirectory)
    ) {
        throw new RuntimeException(
            'Не удалось создать резервную копию.'
        );
    }

    $manifest = [];

    foreach ($paths as $relativePath) {
        $source = str_starts_with($relativePath, '/')
            ? $relativePath
            : $root . '/' . $relativePath;
        $exists = is_file($source);
        $manifest[$relativePath] = $exists;

        if (!$exists) {
            continue;
        }

        $safeName = str_starts_with($relativePath, '/')
            ? '_external/' . basename($relativePath)
            : $relativePath;
        $destination = $backupDirectory . '/' . $safeName;
        $directory = dirname($destination);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                "Не удалось создать каталог копии {$directory}"
            );
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException(
                "Не удалось сохранить копию {$relativePath}"
            );
        }
    }

    return $manifest;
}

function suRollback(
    string $root,
    string $backupDirectory,
    array $manifest
): void {
    foreach ($manifest as $relativePath => $existed) {
        $destination = str_starts_with($relativePath, '/')
            ? $relativePath
            : $root . '/' . $relativePath;
        $safeName = str_starts_with($relativePath, '/')
            ? '_external/' . basename($relativePath)
            : $relativePath;
        $source = $backupDirectory . '/' . $safeName;

        if ($existed && is_file($source)) {
            @copy($source, $destination);
        } elseif (!$existed && is_file($destination)) {
            @unlink($destination);
        }
    }
}

function suLint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }

    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY)
        . ' -l '
        . escapeshellarg($path)
        . ' 2>&1',
        $output,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            "Ошибка PHP-синтаксиса в {$path}:\n"
            . implode("\n", $output)
        );
    }
}

function suInstallCron(
    string $workerPath,
    string $logPath
): bool {
    if (!function_exists('exec')) {
        return false;
    }

    $output = [];
    $code = 0;
    exec('command -v crontab 2>/dev/null', $output, $code);

    if ($code !== 0 || trim(implode('', $output)) === '') {
        return false;
    }

    $current = [];
    $currentCode = 0;
    exec('crontab -l 2>/dev/null', $current, $currentCode);
    $currentText = implode(PHP_EOL, $current);

    if (str_contains($currentText, $workerPath)) {
        return true;
    }

    $line = '* * * * * '
        . escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($workerPath)
        . ' >> '
        . escapeshellarg($logPath)
        . ' 2>&1';
    $newText = trim($currentText);
    $newText = ($newText !== '' ? $newText . PHP_EOL : '')
        . $line . PHP_EOL;
    $temp = tempnam(sys_get_temp_dir(), 'mirsaitov-cron-');

    if (!is_string($temp)) {
        return false;
    }

    file_put_contents($temp, $newText, LOCK_EX);
    $installOutput = [];
    $installCode = 0;
    exec('crontab ' . escapeshellarg($temp) . ' 2>&1', $installOutput, $installCode);
    @unlink($temp);

    return $installCode === 0;
}

$root = suRoot();
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$repositoryPath = $root . '/app/Repositories/SystemUpdateRepository.php';
$servicePath = $root . '/app/Services/SystemUpdateService.php';
$workerClassPath = $root . '/app/Services/SystemUpdateWorker.php';
$workerPath = $root . '/bin/system_update_worker.php';
$dataRoot = dirname($root, 2);
$configPath = $dataRoot . '/system-updates-config.php';
$workerLogPath = $dataRoot . '/system-update-worker.log';
$versionPath = $root . '/storage/system-updates/version.json';

$indexOriginal = suRead($indexPath);
$apiOriginal = suRead($apiPath);
$jsOriginal = suRead($jsPath);
$cssOriginal = suRead($cssPath);
$schemaOriginal = suRead($schemaPath);

if (
    str_contains($indexOriginal, 'SYSTEM_UPDATES_MODULE')
    || str_contains($jsOriginal, 'SYSTEM_UPDATES_MODULE_JS')
) {
    suOut('Модуль системных обновлений уже установлен.');
    exit(0);
}

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'sql/schema.sql',
    'app/Repositories/SystemUpdateRepository.php',
    'app/Services/SystemUpdateService.php',
    'app/Services/SystemUpdateWorker.php',
    'bin/system_update_worker.php',
    'storage/system-updates/version.json',
    $configPath,
];
$backupDirectory = $root
    . '/storage/backups/system-updates-module-'
    . date('Ymd-His');
$manifest = suBackup($root, $paths, $backupDirectory);
suOut("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/system-updates/';

try {
    $repository = suDownload($baseUrl . 'SystemUpdateRepository.php');
    $service = suDownload($baseUrl . 'SystemUpdateService.php');
    $workerClass = suDownload($baseUrl . 'SystemUpdateWorker.php');
    $worker = suDownload($baseUrl . 'system_update_worker.php');
    $section = suDownload($baseUrl . 'section.html');
    $jsFragment = suDownload($baseUrl . 'app.js');
    $cssFragment = suDownload($baseUrl . 'app.css');
    $getFragment = suDownload($baseUrl . 'api_get.phpfrag');
    $postFragment = suDownload($baseUrl . 'api_post.phpfrag');
    $schemaFragment = suDownload($baseUrl . 'schema.sql');

    $index = $indexOriginal;
    $api = $apiOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;
    $schema = $schemaOriginal;

    $settingsButton = '                <button class="nav-link" data-section="settings">Настройки</button>';
    $index = suReplaceOnce(
        $index,
        $settingsButton,
        "                <button class=\"nav-link\" data-section=\"system-updates\">Обновления</button>\n"
        . $settingsButton,
        'пункт меню «Обновления»'
    );

    $index = suInsertBefore(
        $index,
        '        <section id="section-settings" class="section">',
        $section . "\n",
        'раздел системных обновлений'
    );

    $api = suReplaceOnce(
        $api,
        'use SeoAnalytics\Repositories\ProjectRepository;',
        "use SeoAnalytics\\Repositories\\ProjectRepository;\n"
        . 'use SeoAnalytics\Repositories\SystemUpdateRepository;' . "\n"
        . 'use SeoAnalytics\Services\SystemUpdateService;',
        'импорты системных обновлений'
    );

    $unknownNeedles = [
        "        Security::json(['error' => 'Неизвестное действие.'], 404);",
        "        Security::json([\n            'error' => 'Неизвестное действие.'\n        ], 404);",
    ];
    $inserted = false;

    foreach ($unknownNeedles as $needle) {
        if (str_contains($api, $needle)) {
            $api = suInsertBefore(
                $api,
                $needle,
                $getFragment,
                'GET API системных обновлений'
            );
            $inserted = true;
            break;
        }
    }

    if (!$inserted) {
        throw new RuntimeException(
            'Не найдена точка вставки GET API обновлений.'
        );
    }

    $api = suInsertBefore(
        $api,
        "    if (\$action === 'save_project') {",
        $postFragment,
        'POST API системных обновлений'
    );

    $js = suInsertBefore(
        $js,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript системных обновлений'
    );
    $css .= "\n" . $cssFragment . "\n";

    if (!str_contains($schema, 'SYSTEM_UPDATES_SCHEMA')) {
        $schema .= "\n\n" . $schemaFragment . "\n";
    }

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=18',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=18',
        $index
    ) ?? $index;

    $backupRoot = $dataRoot . '/system-update-backups';
    $runtimeRoot = $dataRoot . '/system-update-runtime';
    $configContent = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return [\n"
        . "    'manifest_url' => 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/manifest.json',\n"
        . "    'allowed_installer_prefix' => 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/',\n"
        . "    'backup_directory' => " . var_export($backupRoot, true) . ",\n"
        . "    'runtime_directory' => " . var_export($runtimeRoot, true) . ",\n"
        . "    'lock_path' => " . var_export($runtimeRoot . '/worker.lock', true) . ",\n"
        . "];\n";
    $versionContent = json_encode([
        'version' => '2026.08.01.1',
        'updated_at' => date(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

    if (!is_string($versionContent)) {
        throw new RuntimeException('Не удалось подготовить файл версии.');
    }

    suWrite($configPath, $configContent);
    @chmod($configPath, 0600);
    suWrite($repositoryPath, $repository);
    suWrite($servicePath, $service);
    suWrite($workerClassPath, $workerClass);
    suWrite($workerPath, $worker);
    suWrite($versionPath, $versionContent . PHP_EOL);
    @chmod($versionPath, 0600);
    suWrite($indexPath, $index);
    suWrite($apiPath, $api);
    suWrite($jsPath, $js);
    suWrite($cssPath, $css);
    suWrite($schemaPath, $schema);

    suLint($repositoryPath);
    suLint($servicePath);
    suLint($workerClassPath);
    suLint($workerPath);
    suLint($indexPath);
    suLint($apiPath);

    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();
    $statements = array_filter(array_map(
        'trim',
        preg_split('/;\s*(?:\r?\n|$)/', $schemaFragment) ?: []
    ));

    foreach ($statements as $statement) {
        $statement = preg_replace('/^--[^\n]*\n/', '', $statement) ?? $statement;

        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    if (!is_dir($backupRoot)) {
        mkdir($backupRoot, 0700, true);
    }
    if (!is_dir($runtimeRoot)) {
        mkdir($runtimeRoot, 0700, true);
    }
    @chmod($backupRoot, 0700);
    @chmod($runtimeRoot, 0700);

    $cronInstalled = suInstallCron(
        $workerPath,
        $workerLogPath
    );

    (new \SeoAnalytics\Services\SystemUpdateWorker())->run();

    suOut('');
    suOut('Модуль системных обновлений установлен.');
    suOut('- проверка обновлений из кабинета;');
    suOut('- фоновая установка через очередь;');
    suOut('- проверка SHA-256;');
    suOut('- полная резервная копия проекта;');
    suOut('- автоматический откат при ошибке;');
    suOut('- журнал операций и ручной откат.');
    suOut('');

    if ($cronInstalled) {
        suOut('Cron worker установлен автоматически: каждую минуту.');
    } else {
        suOut('Cron не удалось установить автоматически. Добавьте в планировщик:');
        suOut('* * * * * ' . PHP_BINARY . ' ' . $workerPath . ' >> ' . $workerLogPath . ' 2>&1');
    }

    suOut('Текущая версия: 2026.08.01.1');
    suOut("Конфиг: {$configPath}");
    suOut("Резервные копии обновлений: {$backupRoot}");
    suOut("Резервная копия установки модуля: {$backupDirectory}");
} catch (Throwable $exception) {
    suRollback($root, $backupDirectory, $manifest);

    fwrite(
        STDERR,
        PHP_EOL
        . 'ОШИБКА: '
        . $exception->getMessage()
        . PHP_EOL
        . 'Файлы восстановлены из резервной копии.'
        . PHP_EOL
    );

    exit(1);
}
