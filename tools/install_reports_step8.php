<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function step8Out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function step8Root(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/api.php')
        || !is_file($root . '/assets/app.js')
        || !is_file($root . '/app/bootstrap.php')
        || !is_file($root . '/app/Services/AdvertisingAutoFillService.php')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function step8Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function step8Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Direct Installer/8.0',
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

function step8InsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    $position = strpos($content, $needle);

    if ($position === false) {
        throw new RuntimeException(
            "Не найдена точка изменения: {$label}"
        );
    }

    return substr($content, 0, $position)
        . $insertion
        . $needle
        . substr($content, $position + strlen($needle));
}

function step8Write(string $path, string $content, int $mode = 0644): void
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

    @chmod($temporary, $mode);

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException(
            "Не удалось заменить {$path}"
        );
    }

    @chmod($path, $mode);
}

function step8Backup(
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

    foreach ($paths as $path) {
        $absolute = str_starts_with($path, '/')
            ? $path
            : $root . '/' . $path;
        $key = str_starts_with($path, '/')
            ? '__external__/' . basename($path)
            : $path;
        $exists = is_file($absolute);
        $manifest[$path] = [
            'exists' => $exists,
            'backup_key' => $key,
        ];

        if (!$exists) {
            continue;
        }

        $destination = $backupDirectory . '/' . $key;
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

        if (!copy($absolute, $destination)) {
            throw new RuntimeException(
                "Не удалось сохранить копию {$absolute}"
            );
        }
    }

    return $manifest;
}

function step8Rollback(
    string $root,
    string $backupDirectory,
    array $manifest
): void {
    foreach ($manifest as $path => $info) {
        $absolute = str_starts_with($path, '/')
            ? $path
            : $root . '/' . $path;

        if ($info['exists']) {
            $backup = $backupDirectory . '/' . $info['backup_key'];

            if (is_file($backup)) {
                @copy($backup, $absolute);
            }
        } elseif (is_file($absolute)) {
            @unlink($absolute);
        }
    }
}

function step8LintPhp(string $path): void
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

function step8Prompt(string $label, string $default = ''): string
{
    fwrite(STDOUT, $label);
    $value = trim((string) fgets(STDIN));

    return $value === '' ? $default : $value;
}

function step8PromptHidden(string $label): string
{
    fwrite(STDOUT, $label);
    $sttyAvailable = function_exists('shell_exec')
        && trim((string) shell_exec('command -v stty 2>/dev/null')) !== '';

    if ($sttyAvailable) {
        shell_exec('stty -echo');
    }

    $value = trim((string) fgets(STDIN));

    if ($sttyAvailable) {
        shell_exec('stty echo');
    }

    fwrite(STDOUT, PHP_EOL);

    return $value;
}

$root = step8Root();
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$clientPath = $root . '/app/Services/YandexDirectClient.php';
$servicePath = $root . '/app/Services/YandexDirectService.php';
$advertisingPath = $root . '/app/Services/AdvertisingAutoFillService.php';
$configPath = dirname($root, 2) . '/yandex-direct-config.php';

$indexOriginal = step8Read($indexPath);
$jsOriginal = step8Read($jsPath);

if (!str_contains($jsOriginal, 'REPORTS_STEP7_JS')) {
    throw new RuntimeException(
        'Сначала установите разделение рекламного и SEO-шаблона — шаг 7.'
    );
}

if (str_contains($jsOriginal, 'REPORTS_STEP8_JS')) {
    step8Out('Интеграция Яндекс Директа уже установлена.');
    exit(0);
}

$existingConfig = [];

if (is_file($configPath)) {
    $loaded = require $configPath;
    $existingConfig = is_array($loaded) ? $loaded : [];
}

step8Out('');
step8Out('Настройка Яндекс Директа');
step8Out('Токен не будет отображаться на экране.');

$token = step8PromptHidden(
    isset($existingConfig['token']) && trim((string) $existingConfig['token']) !== ''
        ? 'OAuth-токен Direct [Enter — использовать сохранённый]: '
        : 'OAuth-токен Direct: '
);

if ($token === '') {
    $token = trim((string) ($existingConfig['token'] ?? ''));
}

if ($token === '') {
    throw new RuntimeException('OAuth-токен не указан.');
}

$clientLogin = step8Prompt(
    'Client-Login рекламодателя [можно оставить пустым]: ',
    trim((string) ($existingConfig['client_login'] ?? ''))
);

$paths = [
    'index.php',
    'assets/app.js',
    'app/Services/YandexDirectClient.php',
    'app/Services/YandexDirectService.php',
    'app/Services/AdvertisingAutoFillService.php',
    $configPath,
];
$backupDirectory = $root
    . '/storage/backups/reports-step8-'
    . date('Ymd-His');
$manifest = step8Backup(
    $root,
    $paths,
    $backupDirectory
);

step8Out("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/reports-step8/';

try {
    $client = step8Download($baseUrl . 'YandexDirectClient.php');
    $service = step8Download($baseUrl . 'YandexDirectService.php');
    $advertising = step8Download(
        $baseUrl . 'AdvertisingAutoFillService.php'
    );
    $jsFragment = step8Download($baseUrl . 'reports_step8.js');

    $configContent = "<?php\ndeclare(strict_types=1);\n\nreturn "
        . var_export([
            'token' => $token,
            'client_login' => $clientLogin,
        ], true)
        . ";\n";

    step8Write($configPath, $configContent, 0600);
    step8Write($clientPath, $client);
    step8Write($servicePath, $service);
    step8Write($advertisingPath, $advertising);

    $js = step8InsertBefore(
        $jsOriginal,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript Direct'
    );
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=15',
        $indexOriginal
    ) ?? $indexOriginal;

    step8Write($jsPath, $js);
    step8Write($indexPath, $index);

    step8LintPhp($clientPath);
    step8LintPhp($servicePath);
    step8LintPhp($advertisingPath);
    step8LintPhp($indexPath);

    require $root . '/app/bootstrap.php';

    $direct = new \SeoAnalytics\Services\YandexDirectService();
    $verification = $direct->verify();
    $testDate = date('Y-m-d', strtotime('-2 days'));
    $test = $direct->load($testDate, $testDate);

    step8Out('');
    step8Out('Яндекс Директ подключён.');
    step8Out('- OAuth-токен проверен через Clients.get;');
    step8Out('- тестовый отчёт Reports успешно сформирован;');
    step8Out('- расходы, показы и клики загружаются из Direct;');
    step8Out('- кампании и группы объявлений загружаются из Direct;');
    step8Out('- заявки и UTM-разбивка продолжают загружаться из Метрики;');
    step8Out('- квалификация, договоры и выручка не перезаписываются.');
    step8Out('');
    step8Out('Проверенных клиентов: ' . count($verification['Clients'] ?? []));
    step8Out('Кампаний в тестовом дне: ' . count($test['current']['campaigns'] ?? []));
    step8Out("Конфиг Direct: {$configPath}");
    step8Out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    step8Rollback(
        $root,
        $backupDirectory,
        $manifest
    );

    fwrite(
        STDERR,
        PHP_EOL
        . 'ОШИБКА: '
        . $exception->getMessage()
        . PHP_EOL
        . 'Файлы и конфиг восстановлены из резервной копии.'
        . PHP_EOL
    );

    exit(1);
}
