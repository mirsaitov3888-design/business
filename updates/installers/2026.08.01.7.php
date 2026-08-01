<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите установщик через PHP CLI.\n");
}

function mon7out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function mon7read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function mon7download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 90,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Monitoring Installer/1.0',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = file_get_contents($url, false, $context);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException("Не удалось загрузить компонент: {$url}");
    }
    return $content;
}

function mon7replaceOnce(
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $position = strpos($content, $needle);
    if ($position === false) {
        throw new RuntimeException("Не найдена точка изменения: {$label}");
    }
    return substr($content, 0, $position)
        . $replacement
        . substr($content, $position + strlen($needle));
}

function mon7insertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return mon7replaceOnce($content, $needle, $insertion . $needle, $label);
}

function mon7write(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать каталог {$directory}");
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temporary}");
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

function mon7lint(string $path): void
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

function mon7installCron(string $workerPath, string $logPath): bool
{
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
    $line = '*/5 * * * * '
        . escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($workerPath)
        . ' >> '
        . escapeshellarg($logPath)
        . ' 2>&1';
    $newText = trim($currentText);
    $newText = ($newText !== '' ? $newText . PHP_EOL : '') . $line . PHP_EOL;
    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-monitor-cron-');
    if (!is_string($temporary)) {
        return false;
    }
    file_put_contents($temporary, $newText, LOCK_EX);
    $installOutput = [];
    $installCode = 0;
    exec('crontab ' . escapeshellarg($temporary) . ' 2>&1', $installOutput, $installCode);
    @unlink($temporary);
    return $installCode === 0;
}

$root = getcwd() ?: '';
$required = [
    $root . '/index.php',
    $root . '/api.php',
    $root . '/assets/app.js',
    $root . '/assets/app.css',
    $root . '/app/bootstrap.php',
    $root . '/sql/schema.sql',
];
foreach ($required as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Не найден файл проекта: ' . $path);
    }
}

$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$repositoryPath = $root . '/app/Repositories/MonitoringRepository.php';
$notifierPath = $root . '/app/Services/MonitoringNotifier.php';
$servicePath = $root . '/app/Services/SiteMonitoringService.php';
$workerPath = $root . '/bin/site_monitor_worker.php';
$dataRoot = dirname($root, 2);
$configPath = $dataRoot . '/monitoring-config.php';
$workerLogPath = $dataRoot . '/site-monitor-worker.log';

$indexOriginal = mon7read($indexPath);
$apiOriginal = mon7read($apiPath);
$jsOriginal = mon7read($jsPath);
$cssOriginal = mon7read($cssPath);
$schemaOriginal = mon7read($schemaPath);

if (str_contains($indexOriginal, 'SITE_MONITORING_MODULE')) {
    mon7out('Модуль мониторинга сайтов уже установлен.');
    exit(0);
}

$paths = [
    $indexPath,
    $apiPath,
    $jsPath,
    $cssPath,
    $schemaPath,
    $repositoryPath,
    $notifierPath,
    $servicePath,
    $workerPath,
    $configPath,
];
$backupDirectory = $root . '/storage/backups/site-monitoring-module-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}
$manifest = [];
foreach ($paths as $path) {
    $exists = is_file($path);
    $manifest[$path] = $exists;
    if (!$exists) {
        continue;
    }
    $name = str_starts_with($path, $root . '/')
        ? ltrim(substr($path, strlen($root)), '/')
        : '_external/' . basename($path);
    $destination = $backupDirectory . '/' . $name;
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог резервной копии.');
    }
    if (!copy($path, $destination)) {
        throw new RuntimeException('Не удалось сохранить резервную копию ' . $path);
    }
}

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/updates/monitoring/';

try {
    $repository = mon7download($baseUrl . 'MonitoringRepository.php');
    $notifier = mon7download($baseUrl . 'MonitoringNotifier.php');
    $service = mon7download($baseUrl . 'SiteMonitoringService.php');
    $worker = mon7download($baseUrl . 'site_monitor_worker.php');
    $section = mon7download($baseUrl . 'section.html');
    $jsFragment = mon7download($baseUrl . 'app.js');
    $cssFragment = mon7download($baseUrl . 'app.css');
    $getFragment = mon7download($baseUrl . 'api_get.phpfrag');
    $postFragment = mon7download($baseUrl . 'api_post.phpfrag');
    $schemaFragment = mon7download($baseUrl . 'schema.sql');

    $index = $indexOriginal;
    $api = $apiOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;
    $schema = $schemaOriginal;

    $settingsButton = '                <button class="nav-link" data-section="settings">Настройки</button>';
    $index = mon7replaceOnce(
        $index,
        $settingsButton,
        "                <button class=\"nav-link\" data-section=\"site-monitoring\">Мониторинг сайтов</button>\n"
            . $settingsButton,
        'пункт меню «Мониторинг сайтов»'
    );
    $index = mon7insertBefore(
        $index,
        '        <section id="section-settings" class="section">',
        $section . "\n",
        'раздел мониторинга сайтов'
    );
    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=25', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=25', $index) ?? $index;

    $api = mon7replaceOnce(
        $api,
        'use SeoAnalytics\Repositories\ProjectRepository;',
        "use SeoAnalytics\\Repositories\\ProjectRepository;\n"
            . 'use SeoAnalytics\Repositories\MonitoringRepository;' . "\n"
            . 'use SeoAnalytics\Services\SiteMonitoringService;',
        'импорты мониторинга'
    );

    $unknownNeedles = [
        "        Security::json(['error' => 'Неизвестное действие.'], 404);",
        "        Security::json([\n            'error' => 'Неизвестное действие.'\n        ], 404);",
    ];
    $inserted = false;
    foreach ($unknownNeedles as $needle) {
        if (str_contains($api, $needle)) {
            $api = mon7insertBefore(
                $api,
                $needle,
                $getFragment,
                'GET API мониторинга'
            );
            $inserted = true;
            break;
        }
    }
    if (!$inserted) {
        throw new RuntimeException('Не найдена точка вставки GET API мониторинга.');
    }
    $api = mon7insertBefore(
        $api,
        "    if (\$action === 'save_project') {",
        $postFragment,
        'POST API мониторинга'
    );

    $js = mon7insertBefore(
        $js,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript мониторинга'
    );
    $css .= "\n" . $cssFragment . "\n";
    if (!str_contains($schema, 'SITE_MONITORING_MODULE_SCHEMA')) {
        $schema .= "\n\n" . $schemaFragment . "\n";
    }

    if (!is_file($configPath)) {
        $config = "<?php\ndeclare(strict_types=1);\n\nreturn [\n"
            . "    'telegram_bot_token' => '',\n"
            . "    'email_from' => '',\n"
            . "    'email_from_name' => 'Мониторинг сайтов',\n"
            . "];\n";
        mon7write($configPath, $config);
        @chmod($configPath, 0600);
    }

    mon7write($repositoryPath, $repository);
    mon7write($notifierPath, $notifier);
    mon7write($servicePath, $service);
    mon7write($workerPath, $worker);
    @chmod($workerPath, 0750);
    mon7write($indexPath, $index);
    mon7write($apiPath, $api);
    mon7write($jsPath, $js);
    mon7write($cssPath, $css);
    mon7write($schemaPath, $schema);

    foreach ([$repositoryPath, $notifierPath, $servicePath, $workerPath, $indexPath, $apiPath] as $path) {
        mon7lint($path);
    }

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

    $cronInstalled = mon7installCron($workerPath, $workerLogPath);

    $workerOutput = [];
    $workerCode = 0;
    if (function_exists('exec')) {
        exec(
            escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($workerPath) . ' 2>&1',
            $workerOutput,
            $workerCode
        );
    }

    mon7out('');
    mon7out('Модуль «Мониторинг сайтов» установлен.');
    mon7out('- несколько сайтов на один проект;');
    mon7out('- первичный аудит сразу после добавления;');
    mon7out('- доступность и время ответа каждые 5 минут;');
    mon7out('- три повторные проверки перед фиксацией сбоя;');
    mon7out('- инциденты «сайт недоступен» и «сайт восстановлен»;');
    mon7out('- Title, Description, H1, canonical, meta robots и X-Robots-Tag;');
    mon7out('- robots.txt, Sitemap и favicon;');
    mon7out('- SSL, DNS и срок регистрации домена через RDAP;');
    mon7out('- счётчики Яндекс Метрики и состояние Вебвизора;');
    mon7out('- сравнение обычного ответа и ответа для YandexBot;');
    mon7out('- история изменений и маршрутизация уведомлений;');
    mon7out('- внутренние уведомления, email и Telegram.');
    mon7out('');
    if ($cronInstalled) {
        mon7out('Cron worker установлен: проверка очереди каждые 5 минут.');
    } else {
        mon7out('Cron не удалось установить автоматически. Добавьте в планировщик:');
        mon7out('*/5 * * * * ' . PHP_BINARY . ' ' . $workerPath . ' >> ' . $workerLogPath . ' 2>&1');
    }
    if ($workerCode !== 0) {
        mon7out('Первый запуск worker завершился с предупреждением:');
        mon7out(implode("\n", $workerOutput));
    }
    mon7out('Конфиг уведомлений: ' . $configPath);
    mon7out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($manifest as $path => $existed) {
        $name = str_starts_with($path, $root . '/')
            ? ltrim(substr($path, strlen($root)), '/')
            : '_external/' . basename($path);
        $backup = $backupDirectory . '/' . $name;
        if ($existed && is_file($backup)) {
            @copy($backup, $path);
        } elseif (!$existed && is_file($path)) {
            @unlink($path);
        }
    }
    fwrite(
        STDERR,
        "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n"
    );
    exit(1);
}
