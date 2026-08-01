<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function p16out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function p16read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function p16write(string $path, string $content): void
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

function p16download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 120,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Portal Installer/16',
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

function p16lint(string $path): void
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

function p16insertBefore(string $content, string $needle, string $insertion, string $label): string
{
    $position = strpos($content, $needle);
    if ($position === false) {
        throw new RuntimeException("Не найдена точка вставки: {$label}");
    }
    return substr($content, 0, $position) . $insertion . $needle . substr($content, $position + strlen($needle));
}

function p16applyPrevious(string $root): void
{
    $accessPath = $root . '/app/Services/AccessService.php';
    if (is_file($accessPath)) {
        return;
    }

    $url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.01.15.php';
    $expectedSha = 'e855b704d2eb0e6b0b11bd1a93ba7d506a02ea56907cde6b3f0284e0c9c4d457';
    $body = p16download($url);
    if (!hash_equals($expectedSha, hash('sha256', $body))) {
        throw new RuntimeException('Не совпала SHA-256 обязательной версии 2026.08.01.15.');
    }
    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-15-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Не удалось создать временный файл версии 2026.08.01.15.');
    }
    file_put_contents($temporary, $body, LOCK_EX);
    $output = [];
    $code = 0;
    exec(
        'cd ' . escapeshellarg($root)
        . ' && ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($temporary)
        . ' 2>&1',
        $output,
        $code
    );
    @unlink($temporary);
    if ($code !== 0) {
        throw new RuntimeException("Не удалось применить версию 2026.08.01.15:\n" . implode("\n", $output));
    }
}

function p16executeSchema(PDO $pdo, string $schema): void
{
    $statements = preg_split('/;\s*(?:\r?\n|$)/', $schema) ?: [];
    foreach ($statements as $statement) {
        $statement = preg_replace('/^\s*--[^\n]*\n/m', '', trim($statement)) ?? trim($statement);
        if ($statement !== '') {
            $pdo->exec($statement);
        }
    }
}

function p16columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

$root = getcwd() ?: '';
foreach (['index.php', 'api.php', 'assets/app.js', 'assets/app.css', 'sql/schema.sql', 'app/bootstrap.php'] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Не найден файл проекта: ' . $relative);
    }
}

p16applyPrevious($root);

$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$accessPath = $root . '/app/Services/PortalAccessService.php';
$clientRepositoryPath = $root . '/app/Repositories/ClientRepository.php';
$notificationServicePath = $root . '/app/Services/NotificationCenterService.php';

$index = p16read($indexPath);
$api = p16read($apiPath);
$js = p16read($jsPath);
$css = p16read($cssPath);
$schema = p16read($schemaPath);

if (
    str_contains($index, 'CLIENT_ACCESS_PORTAL_V16')
    && str_contains($js, 'CLIENT_ACCESS_PORTAL_V16_JS')
    && is_file($accessPath)
    && is_file($clientRepositoryPath)
    && is_file($notificationServicePath)
) {
    p16out('Клиентский контур и центр уведомлений уже установлены.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/client-access-portal-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$backupFiles = [
    $indexPath => 'index.php',
    $apiPath => 'api.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
    $schemaPath => 'schema.sql',
    $accessPath => 'PortalAccessService.php',
    $clientRepositoryPath => 'ClientRepository.php',
    $notificationServicePath => 'NotificationCenterService.php',
];
$manifest = [];
foreach ($backupFiles as $source => $name) {
    $manifest[$source] = is_file($source);
    if (is_file($source) && !copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException("Не удалось сохранить резервную копию {$name}");
    }
}

$baseUrl = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/portal/';

try {
    $accessService = p16download($baseUrl . 'PortalAccessService.php');
    $clientRepository = p16download($baseUrl . 'ClientRepository.php');
    $notificationService = p16download($baseUrl . 'NotificationCenterService.php');
    $section = p16download($baseUrl . 'section.html');
    $jsFragment = p16download($baseUrl . 'app.js');
    $cssFragment = p16download($baseUrl . 'app.css');
    $apiGet = p16download($baseUrl . 'api_get.phpfrag');
    $apiPost = p16download($baseUrl . 'api_post.phpfrag');
    $schemaFragment = p16download($baseUrl . 'schema.sql');

    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();
    if (!p16columnExists($pdo, 'users', 'role')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'administrator'");
    }
    if (!p16columnExists($pdo, 'users', 'account_status')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN account_status VARCHAR(30) NOT NULL DEFAULT 'active'");
    }
    p16executeSchema($pdo, $schemaFragment);

    p16write($accessPath, $accessService);
    p16write($clientRepositoryPath, $clientRepository);
    p16write($notificationServicePath, $notificationService);

    if (!str_contains($index, 'CLIENT_ACCESS_PORTAL_V16')) {
        $settingsButton = '                <button class="nav-link" data-section="settings">Настройки</button>';
        $nav = <<<'HTML'
                <button class="nav-link" data-section="clients">Клиенты</button>
                <button class="nav-link portal-nav-notifications" data-section="notifications">
                    <span>Уведомления</span>
                    <b id="portalNotificationsBadge" class="hidden">0</b>
                </button>
HTML;
        $index = p16insertBefore(
            $index,
            $settingsButton,
            $nav . "\n",
            'пункты меню клиентов и уведомлений'
        );
        $index = p16insertBefore(
            $index,
            '        <section id="section-settings" class="section">',
            $section . "\n",
            'разделы клиентов и уведомлений'
        );
    }

    if (!str_contains($api, 'CLIENT_ACCESS_PORTAL_V16_GUARD')) {
        $guarded = preg_replace(
            '/(\$action\s*=\s*[^;]+;)/',
            "$1\n\n    // CLIENT_ACCESS_PORTAL_V16_GUARD\n    (new \\SeoAnalytics\\Services\\PortalAccessService())->guardAction((string) \$action);",
            $api,
            1,
            $guardCount
        );
        if (!is_string($guarded) || $guardCount !== 1) {
            throw new RuntimeException('Не удалось добавить серверную проверку прав API.');
        }
        $api = $guarded;
    }

    if (!str_contains($api, 'CLIENT_ACCESS_PORTAL_V16_API_GET')) {
        $unknownNeedles = [
            "        Security::json(['error' => 'Неизвестное действие.'], 404);",
            "        Security::json([\n            'error' => 'Неизвестное действие.'\n        ], 404);",
        ];
        $inserted = false;
        foreach ($unknownNeedles as $needle) {
            if (str_contains($api, $needle)) {
                $api = p16insertBefore($api, $needle, $apiGet, 'GET API клиентского контура');
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            throw new RuntimeException('Не найдена точка вставки GET API клиентского контура.');
        }
    }

    if (!str_contains($api, 'CLIENT_ACCESS_PORTAL_V16_API_POST')) {
        $postNeedles = [
            "    if (\$action === 'save_project') {",
            '    // REPORTS_STEP1_API_POST',
        ];
        $inserted = false;
        foreach ($postNeedles as $needle) {
            if (str_contains($api, $needle)) {
                $api = p16insertBefore($api, $needle, $apiPost, 'POST API клиентского контура');
                $inserted = true;
                break;
            }
        }
        if (!$inserted) {
            throw new RuntimeException('Не найдена точка вставки POST API клиентского контура.');
        }
    }

    if (!str_contains($js, 'CLIENT_ACCESS_PORTAL_V16_JS')) {
        $js = p16insertBefore(
            $js,
            '    async function loadDashboard() {',
            $jsFragment . "\n",
            'JavaScript клиентского контура'
        );
    }
    if (!str_contains($css, 'CLIENT_ACCESS_PORTAL_V16_CSS')) {
        $css .= "\n" . $cssFragment . "\n";
    }
    if (!str_contains($schema, 'CLIENT_ACCESS_PORTAL_V16_SCHEMA')) {
        $schema .= "\n\n" . $schemaFragment . "\n";
    }

    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=35', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=35', $index) ?? $index;

    p16write($indexPath, $index);
    p16write($apiPath, $api);
    p16write($jsPath, $js);
    p16write($cssPath, $css);
    p16write($schemaPath, $schema);

    foreach ([$accessPath, $clientRepositoryPath, $notificationServicePath, $indexPath, $apiPath] as $path) {
        p16lint($path);
    }

    p16out('Клиенты и области доступа установлены.');
    p16out('- добавлен рабочий раздел «Клиенты»;');
    p16out('- добавлены карточки, менеджеры, проекты, сайты и клиентские аккаунты;');
    p16out('- роли управляют видимостью меню;');
    p16out('- чувствительные API-действия защищены серверной проверкой;');
    p16out('- добавлен единый раздел «Уведомления» и счётчик непрочитанных;');
    p16out('- ошибки обновлений и события мониторинга собираются автоматически;');
    p16out('- модератор и менеджер могут создавать клиентские уведомления.');
    p16out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $name) {
        $source = $backupDirectory . '/' . $name;
        if ($manifest[$destination] && is_file($source)) {
            @copy($source, $destination);
        } elseif (!$manifest[$destination] && is_file($destination)) {
            @unlink($destination);
        }
    }
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
