<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

const LK2_VERSION = '2026.08.02.16';
const LK2_JS_MARKER = 'LK2_CLIENT_STRUCTURE_BUNDLED_V180216';
const LK2_CSS_MARKER = 'LK2_CLIENT_STRUCTURE_BUNDLED_V180216';

function lk2out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function lk2read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function lk2write(string $path, string $content): void
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

function lk2lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException(
            'Ошибка PHP-синтаксиса в ' . $path . ':\n' . implode("\n", $output)
        );
    }
}

function lk2tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function lk2columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() '
        . 'AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function lk2addColumn(
    PDO $pdo,
    string $table,
    string $column,
    string $definition,
    array &$addedColumns
): void {
    if (lk2columnExists($pdo, $table, $column)) {
        return;
    }
    $safeTable = str_replace('`', '', $table);
    $safeColumn = str_replace('`', '', $column);
    $pdo->exec(
        'ALTER TABLE `' . $safeTable . '` ADD COLUMN `' . $safeColumn . '` ' . $definition
    );
    $addedColumns[] = [$safeTable, $safeColumn];
}

function lk2loadComponent(string $key, string $filename, string $expectedSha): string
{
    $localRoot = trim((string) getenv('LK2_COMPONENT_ROOT'));
    if ($localRoot !== '') {
        $localPath = rtrim($localRoot, '/\\') . DIRECTORY_SEPARATOR . $filename;
        if (is_file($localPath)) {
            $content = lk2read($localPath);
            if (!hash_equals($expectedSha, hash('sha256', $content))) {
                throw new RuntimeException('Не совпала SHA-256 локального компонента LK.2: ' . $key);
            }
            return $content;
        }
    }

    $url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/'
        . 'tools/lk2-v16/' . rawurlencode($filename);
    $context = stream_context_create([
        'http' => [
            'timeout' => 120,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov LK2 Installer/1.0',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = file_get_contents($url, false, $context);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Не удалось загрузить компонент LK.2: ' . $key);
    }
    if (!hash_equals($expectedSha, hash('sha256', $content))) {
        throw new RuntimeException('Не совпала SHA-256 компонента LK.2: ' . $key);
    }
    return $content;
}

$hashes = json_decode('{"service":"b2f29c54b9742168d34ae7f39022750c5899dca2e3b059657fdc696bdf39e6d6","api":"53b1e3618fcbd734fe9913c58b0d5a6419973414924a863b7b27ff3ed7ca97e2","js":"c1320ceab7b0d78b6a95b972d3a843d25985010b019d8e3260bfe2fe0f7c56c1","css":"a4bb8fb584450b22613d46c982420d70aefd60920c25309648f56f812aa4fca1","schema":"dd7fec8cd0e1f1546571ecba908eac53f1f3b92bf3a6e57c02ba60a039e24754"}', true);
if (!is_array($hashes)) {
    throw new RuntimeException('Некорректная карта SHA-256 LK.2.');
}

$componentFiles = [
    'service' => 'ClientStructureService.php',
    'api' => 'client-structure-api.php',
    'js' => 'client-structure.js',
    'css' => 'client-structure.css',
    'schema' => 'schema.sqlfrag',
];
$components = [];
foreach ($componentFiles as $key => $filename) {
    $expectedSha = (string) ($hashes[$key] ?? '');
    if ($expectedSha === '') {
        throw new RuntimeException('Не указана SHA-256 компонента LK.2: ' . $key);
    }
    $components[$key] = lk2loadComponent($key, $filename, $expectedSha);
}
$root = getcwd() ?: '';
$required = [
    $root . '/app/bootstrap.php',
    $root . '/app/Services/PortalAccessService.php',
    $root . '/app/Services/PortalContextService.php',
    $root . '/assets/app.js',
    $root . '/assets/app.css',
    $root . '/sql/schema.sql',
];
foreach ($required as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Не найден обязательный файл: ' . $path);
    }
}

$appJsPath = $root . '/assets/app.js';
$appCssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
if (!str_contains(lk2read($appJsPath), 'LK_CONTEXT_BUNDLED_V180214')) {
    throw new RuntimeException('Версия ' . LK2_VERSION . ' требует установленный LK.1 / 2026.08.02.15.');
}

require_once $root . '/app/bootstrap.php';
$pdo = \SeoAnalytics\Core\Database::pdo();
foreach (['clients', 'client_users', 'projects', 'project_client_links', 'project_sites'] as $table) {
    if (!lk2tableExists($pdo, $table)) {
        throw new RuntimeException('Не найдена обязательная таблица LK.2: ' . $table);
    }
}

$destinations = [
    'service' => 'app/Services/ClientStructureService.php',
    'api' => 'client-structure-api.php',
];
$tracked = array_values($destinations);
$tracked[] = 'assets/app.js';
$tracked[] = 'assets/app.css';
$tracked[] = 'sql/schema.sql';

$backupDirectory = $root . '/storage/backups/lk2-client-structure-'
    . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию LK.2.');
}

$manifest = [];
foreach ($tracked as $relative) {
    $source = $root . '/' . $relative;
    $manifest[$relative] = is_file($source);
    if (!is_file($source)) {
        continue;
    }
    $destination = $backupDirectory . '/' . $relative;
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог резервной копии LK.2.');
    }
    if (!copy($source, $destination)) {
        throw new RuntimeException('Не удалось сохранить резервную копию: ' . $relative);
    }
}

$tableState = [
    'client_structure_changes' => lk2tableExists($pdo, 'client_structure_changes'),
];
$addedColumns = [];

try {
    foreach ($destinations as $key => $relative) {
        lk2write($root . '/' . $relative, $components[$key]);
    }

    $appJs = lk2read($appJsPath);
    if (!str_contains($appJs, LK2_JS_MARKER)) {
        $appJs = rtrim($appJs) . PHP_EOL . PHP_EOL
            . '/* ' . LK2_JS_MARKER . ' */' . PHP_EOL
            . trim($components['js']) . PHP_EOL;
        lk2write($appJsPath, $appJs);
    }

    $appCss = lk2read($appCssPath);
    if (!str_contains($appCss, LK2_CSS_MARKER)) {
        $appCss = rtrim($appCss) . PHP_EOL . PHP_EOL
            . '/* ' . LK2_CSS_MARKER . ' */' . PHP_EOL
            . trim($components['css']) . PHP_EOL;
        lk2write($appCssPath, $appCss);
    }

    $schema = lk2read($schemaPath);
    if (!str_contains($schema, 'LK2_CLIENT_STRUCTURE_SCHEMA_V180216')) {
        $schema = rtrim($schema) . PHP_EOL . PHP_EOL
            . trim($components['schema']) . PHP_EOL;
        lk2write($schemaPath, $schema);
    }

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS client_structure_changes (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            action_key VARCHAR(80) NOT NULL,
            entity_type VARCHAR(40) NOT NULL,
            entity_id BIGINT UNSIGNED NULL,
            before_json MEDIUMTEXT NULL,
            after_json MEDIUMTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_client_structure_changes_entity (entity_type, entity_id, created_at),
            KEY idx_client_structure_changes_user (user_id, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    lk2addColumn($pdo, 'projects', 'name', 'VARCHAR(190) NULL', $addedColumns);
    lk2addColumn($pdo, 'projects', 'active', 'TINYINT(1) NOT NULL DEFAULT 1', $addedColumns);
    lk2addColumn($pdo, 'projects', 'lk_description', 'TEXT NULL', $addedColumns);
    lk2addColumn($pdo, 'projects', 'lk_status', "VARCHAR(30) NOT NULL DEFAULT 'active'", $addedColumns);
    lk2addColumn($pdo, 'projects', 'lk_sort_order', 'INT NOT NULL DEFAULT 0', $addedColumns);

    lk2addColumn($pdo, 'project_sites', 'sort_order', 'INT NOT NULL DEFAULT 0', $addedColumns);
    lk2addColumn($pdo, 'project_sites', 'notes', 'TEXT NULL', $addedColumns);

    foreach ([
        ['status', "VARCHAR(30) NOT NULL DEFAULT 'active'"],
        ['manager_user_id', 'BIGINT UNSIGNED NULL'],
        ['contact_name', 'VARCHAR(255) NULL'],
        ['contact_email', 'VARCHAR(255) NULL'],
        ['contact_phone', 'VARCHAR(100) NULL'],
        ['notes', 'TEXT NULL'],
        ['created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['updated_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
    ] as [$column, $definition]) {
        lk2addColumn($pdo, 'clients', $column, $definition, $addedColumns);
    }

    lk2addColumn(
        $pdo,
        'client_users',
        'created_at',
        'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        $addedColumns
    );
    lk2addColumn(
        $pdo,
        'project_client_links',
        'created_at',
        'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
        $addedColumns
    );

    $pdo->exec('UPDATE project_sites SET sort_order = id * 10 WHERE sort_order = 0');
    $pdo->exec('UPDATE projects SET lk_sort_order = id * 10 WHERE lk_sort_order = 0');

    lk2lint($root . '/app/Services/ClientStructureService.php');
    lk2lint($root . '/client-structure-api.php');

    if (function_exists('exec')) {
        $probeOutput = [];
        $probeCode = 0;
        $probe = "const value = null; value?.property; const fallback = value ?? 'ok';";
        exec('node -e ' . escapeshellarg($probe) . ' 2>&1', $probeOutput, $probeCode);
        if ($probeCode === 0) {
            $nodeOutput = [];
            $nodeCode = 0;
            exec('node --check ' . escapeshellarg($appJsPath) . ' 2>&1', $nodeOutput, $nodeCode);
            if ($nodeCode !== 0) {
                throw new RuntimeException(
                    'Ошибка JavaScript LK.2 после установки: ' . implode("\n", $nodeOutput)
                );
            }
        } else {
            lk2out('Проверка JavaScript через Node.js пропущена: серверная версия Node.js устарела.');
        }
    }

    if (substr_count(lk2read($appJsPath), LK2_JS_MARKER) !== 1) {
        throw new RuntimeException('JavaScript LK.2 подключён некорректно.');
    }
    if (substr_count(lk2read($appCssPath), LK2_CSS_MARKER) !== 1) {
        throw new RuntimeException('Стили LK.2 подключены некорректно.');
    }
    if (!class_exists('SeoAnalytics\\Services\\ClientStructureService')) {
        throw new RuntimeException('Сервис структуры клиентов не загрузился.');
    }

    $clientCount = (int) $pdo->query('SELECT COUNT(*) FROM clients')->fetchColumn();
    $projectCount = (int) $pdo->query('SELECT COUNT(*) FROM project_client_links')->fetchColumn();
    $siteCount = (int) $pdo->query('SELECT COUNT(*) FROM project_sites')->fetchColumn();

    lk2out('LK.2: управление клиентами, проектами и сайтами установлено.');
    lk2out('- клиентов в общей базе: ' . $clientCount . ';');
    lk2out('- привязанных проектов: ' . $projectCount . ';');
    lk2out('- сайтов проектов: ' . $siteCount . ';');
    lk2out('- карточка клиента использует общую базу портала;');
    lk2out('- у каждого проекта отдельный набор сайтов и счётчиков;');
    lk2out('- резервная копия: ' . $backupDirectory . ';');
} catch (Throwable $exception) {
    foreach ($manifest as $relative => $existed) {
        $target = $root . '/' . $relative;
        $backup = $backupDirectory . '/' . $relative;
        if ($existed && is_file($backup)) {
            @copy($backup, $target);
        } elseif (!$existed && is_file($target)) {
            @unlink($target);
        }
    }

    foreach (array_reverse($addedColumns) as [$table, $column]) {
        try {
            if (lk2columnExists($pdo, $table, $column)) {
                $pdo->exec(
                    'ALTER TABLE `' . str_replace('`', '', $table)
                    . '` DROP COLUMN `' . str_replace('`', '', $column) . '`'
                );
            }
        } catch (Throwable) {
        }
    }
    try {
        if (!$tableState['client_structure_changes']
            && lk2tableExists($pdo, 'client_structure_changes')) {
            $pdo->exec('DROP TABLE client_structure_changes');
        }
    } catch (Throwable) {
    }

    fwrite(STDERR, 'ОШИБКА: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Файлы LK.2 восстановлены из резервной копии.' . PHP_EOL);
    exit(1);
}
