<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function b24Out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function b24Root(): string
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

function b24Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function b24Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Bitrix24 Installer/1.0',
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

function b24ReplaceOnce(
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

function b24InsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return b24ReplaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function b24Write(string $path, string $content): void
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

function b24Backup(
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

function b24Rollback(
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

function b24Lint(string $path): void
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

function b24HiddenPrompt(string $label): string
{
    fwrite(STDOUT, $label);
    $stty = function_exists('shell_exec')
        ? trim((string) shell_exec('command -v stty 2>/dev/null'))
        : '';

    if ($stty !== '') {
        shell_exec('stty -echo');
    }

    $value = trim((string) fgets(STDIN));

    if ($stty !== '') {
        shell_exec('stty echo');
    }

    fwrite(STDOUT, PHP_EOL);

    return $value;
}

function b24NormalizeWebhook(string $url): string
{
    $url = trim($url);

    if (!preg_match(
        '#^https://[a-z0-9.-]+/rest/\d+/[a-z0-9_-]+/?$#i',
        $url
    )) {
        throw new RuntimeException(
            'Некорректный URL входящего вебхука Битрикс24.'
        );
    }

    return rtrim($url, '/') . '/';
}

function b24TestCall(
    string $webhook,
    string $method,
    array $params = []
): array {
    $ch = curl_init(
        $webhook . rawurlencode($method) . '.json'
    );

    if ($ch === false) {
        throw new RuntimeException(
            'Не удалось инициализировать проверку вебхука.'
        );
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode(
            $params,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $body = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if (!is_string($body)) {
        throw new RuntimeException(
            'Ошибка соединения с Битрикс24: '
            . ($error !== '' ? $error : 'нет ответа')
        );
    }

    $decoded = json_decode($body, true);

    if (!is_array($decoded)) {
        throw new RuntimeException(
            'Битрикс24 вернул некорректный ответ.'
        );
    }

    if ($httpCode >= 400 || isset($decoded['error'])) {
        throw new RuntimeException(
            (string) (
                $decoded['error_description']
                ?? $decoded['error']
                ?? "HTTP {$httpCode}"
            )
        );
    }

    return $decoded;
}

$root = b24Root();
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$clientPath = $root . '/app/Services/Bitrix24Client.php';
$syncPath = $root . '/app/Services/Bitrix24SyncService.php';
$repositoryPath = $root . '/app/Repositories/Bitrix24Repository.php';
$configPath = dirname($root, 2) . '/bitrix24-config.php';

$indexOriginal = b24Read($indexPath);
$apiOriginal = b24Read($apiPath);
$jsOriginal = b24Read($jsPath);
$cssOriginal = b24Read($cssPath);
$schemaOriginal = b24Read($schemaPath);

if (
    str_contains($indexOriginal, 'BITRIX24_STEP1')
    || str_contains($jsOriginal, 'BITRIX24_STEP1_JS')
) {
    b24Out('Интеграция Битрикс24 уже установлена.');
    exit(0);
}

$existingWebhook = '';

if (is_file($configPath)) {
    $config = require $configPath;
    $existingWebhook = is_array($config)
        ? (string) ($config['webhook_url'] ?? '')
        : '';
}

if ($existingWebhook !== '') {
    b24Out('Найден существующий конфиг Битрикс24. Он будет использован.');
    $webhook = b24NormalizeWebhook($existingWebhook);
} else {
    b24Out('Вставьте полный URL входящего вебхука Битрикс24.');
    b24Out('Значение скрыто и не будет выведено в Shell.');
    $webhook = b24NormalizeWebhook(
        b24HiddenPrompt('URL вебхука: ')
    );
}

b24Out('Проверяем вебхук…');
$profileResponse = b24TestCall($webhook, 'profile');
$profile = $profileResponse['result'] ?? [];
$userName = trim(implode(' ', array_filter([
    (string) ($profile['NAME'] ?? ''),
    (string) ($profile['LAST_NAME'] ?? ''),
])));
b24Out('Связь установлена: ' . ($userName !== '' ? $userName : 'пользователь Битрикс24'));

$permissionWarnings = [];
$permissionTests = [
    'Проекты' => [
        'sonet_group.get',
        ['FILTER' => ['ACTIVE' => 'Y'], 'start' => 0],
    ],
    'Задачи' => [
        'tasks.task.list',
        [
            'filter' => ['ID' => 0],
            'select' => ['ID'],
            'start' => 0,
        ],
    ],
    'CRM' => [
        'crm.item.list',
        [
            'entityTypeId' => 4,
            'select' => ['id'],
            'filter' => ['id' => 0],
            'start' => 0,
        ],
    ],
    'Пользователи' => [
        'user.get',
        ['FILTER' => ['ID' => 0]],
    ],
];

foreach ($permissionTests as $label => [$method, $params]) {
    try {
        b24TestCall($webhook, $method, $params);
        b24Out("- {$label}: доступ есть");
    } catch (Throwable $exception) {
        $permissionWarnings[] = "{$label}: {$exception->getMessage()}";
        b24Out("- {$label}: нет доступа");
    }
}

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'sql/schema.sql',
    'app/Services/Bitrix24Client.php',
    'app/Services/Bitrix24SyncService.php',
    'app/Repositories/Bitrix24Repository.php',
    $configPath,
];
$backupDirectory = $root
    . '/storage/backups/bitrix24-step1-'
    . date('Ymd-His');
$manifest = b24Backup(
    $root,
    $paths,
    $backupDirectory
);
b24Out("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/bitrix24-step1/';

try {
    $client = b24Download($baseUrl . 'Bitrix24Client.php');
    $sync = b24Download($baseUrl . 'Bitrix24SyncService.php');
    $repository = b24Download($baseUrl . 'Bitrix24Repository.php');
    $section = b24Download($baseUrl . 'section.html');
    $jsFragment = b24Download($baseUrl . 'app.js');
    $cssFragment = b24Download($baseUrl . 'app.css');
    $getFragment = b24Download($baseUrl . 'api_get.phpfrag');
    $postFragment = b24Download($baseUrl . 'api_post.phpfrag');
    $schemaFragment = b24Download($baseUrl . 'schema.sql');

    $index = $indexOriginal;
    $api = $apiOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;
    $schema = $schemaOriginal;

    $index = b24ReplaceOnce(
        $index,
        '                <button class="nav-link" data-section="settings">Настройки</button>',
        "                <button class=\"nav-link\" data-section=\"bitrix24\">Битрикс24</button>\n"
        . '                <button class="nav-link" data-section="settings">Настройки</button>',
        'пункт меню Битрикс24'
    );

    $index = b24InsertBefore(
        $index,
        '        <section id="section-settings" class="section">',
        $section . "\n",
        'раздел Битрикс24'
    );

    $api = b24ReplaceOnce(
        $api,
        'use SeoAnalytics\Repositories\ProjectRepository;',
        "use SeoAnalytics\\Repositories\\ProjectRepository;\n"
        . 'use SeoAnalytics\Repositories\Bitrix24Repository;' . "\n"
        . 'use SeoAnalytics\Services\Bitrix24Client;' . "\n"
        . 'use SeoAnalytics\Services\Bitrix24SyncService;',
        'импорты Битрикс24'
    );

    $unknownNeedles = [
        "        Security::json(['error' => 'Неизвестное действие.'], 404);",
        "        Security::json([\n            'error' => 'Неизвестное действие.'\n        ], 404);",
    ];
    $inserted = false;

    foreach ($unknownNeedles as $needle) {
        if (str_contains($api, $needle)) {
            $api = b24InsertBefore(
                $api,
                $needle,
                $getFragment,
                'GET API Битрикс24'
            );
            $inserted = true;
            break;
        }
    }

    if (!$inserted) {
        throw new RuntimeException(
            'Не найдена точка вставки GET API Битрикс24.'
        );
    }

    $api = b24InsertBefore(
        $api,
        "    if (\$action === 'save_project') {",
        $postFragment,
        'POST API Битрикс24'
    );

    $js = b24InsertBefore(
        $js,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript Битрикс24'
    );
    $css .= "\n" . $cssFragment . "\n";

    if (!str_contains($schema, 'BITRIX24_STEP1_SCHEMA')) {
        $schema .= "\n\n" . $schemaFragment . "\n";
    }

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=16',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=16',
        $index
    ) ?? $index;

    $configContent = "<?php\n"
        . "declare(strict_types=1);\n\n"
        . "return [\n"
        . "    'webhook_url' => "
        . var_export($webhook, true)
        . ",\n];\n";

    b24Write($configPath, $configContent);
    @chmod($configPath, 0600);
    b24Write($clientPath, $client);
    b24Write($syncPath, $sync);
    b24Write($repositoryPath, $repository);
    b24Write($indexPath, $index);
    b24Write($apiPath, $api);
    b24Write($jsPath, $js);
    b24Write($cssPath, $css);
    b24Write($schemaPath, $schema);

    b24Lint($clientPath);
    b24Lint($syncPath);
    b24Lint($repositoryPath);
    b24Lint($indexPath);
    b24Lint($apiPath);

    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();
    $statements = array_filter(array_map(
        'trim',
        preg_split('/;\s*(?:\r?\n|$)/', $schemaFragment) ?: []
    ));

    foreach ($statements as $statement) {
        if (
            $statement === ''
            || str_starts_with($statement, '--')
        ) {
            $statement = preg_replace('/^--[^\n]*\n/', '', $statement) ?? $statement;
        }

        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    b24Out('');
    b24Out('Интеграция Битрикс24 установлена.');
    b24Out('- выбор проекта / рабочей группы;');
    b24Out('- привязка компании клиента;');
    b24Out('- тег client_report;');
    b24Out('- синхронизация задач;');
    b24Out('- фактические трудозатраты за период;');
    b24Out('- локальный кэш для будущих отчётов.');

    if ($permissionWarnings !== []) {
        b24Out('');
        b24Out('Вебхуку не хватает некоторых прав:');

        foreach ($permissionWarnings as $warning) {
            b24Out('- ' . $warning);
        }
    }

    b24Out('');
    b24Out("Конфиг: {$configPath}");
    b24Out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    b24Rollback(
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
        . 'Файлы восстановлены из резервной копии.'
        . PHP_EOL
    );

    exit(1);
}
