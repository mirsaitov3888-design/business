<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function r17out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function r17download(string $url, int $timeout = 120): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => $timeout,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Recovery Installer/17',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Не удалось загрузить файл: ' . $url);
    }
    return $body;
}

function r17write(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог: ' . $directory);
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный файл.');
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить файл: ' . $path);
    }
}

function r17lint(string $path): void
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

function r17columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function r17runInstaller(string $root): void
{
    $index = (string) file_get_contents($root . '/index.php');
    $api = (string) file_get_contents($root . '/api.php');
    $accessPath = $root . '/app/Services/PortalAccessService.php';

    $installed = str_contains($index, 'CLIENT_ACCESS_PORTAL_V16')
        && str_contains($api, 'CLIENT_ACCESS_PORTAL_V16_GUARD')
        && is_file($accessPath);

    if ($installed) {
        return;
    }

    $url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.01.16.php';
    $sha = 'd1df9059e146e076dd0bb03bbcc81f64446ff594523191848b31fa8ce4a08f58';
    $body = r17download($url);
    if (!hash_equals($sha, hash('sha256', $body))) {
        throw new RuntimeException('Не совпала SHA-256 версии 2026.08.01.16.');
    }

    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-16-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Не удалось создать временный файл версии 2026.08.01.16.');
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
        throw new RuntimeException("Не удалось применить версию 2026.08.01.16:\n" . implode("\n", $output));
    }
}

$root = getcwd() ?: '';
foreach (['index.php', 'api.php', 'assets/app.js', 'app/bootstrap.php'] as $relative) {
    if (!is_file($root . '/' . $relative)) {
        throw new RuntimeException('Не найден файл проекта: ' . $relative);
    }
}

$files = [
    $root . '/index.php',
    $root . '/api.php',
    $root . '/assets/app.js',
    $root . '/app/Services/PortalAccessService.php',
    $root . '/app/Repositories/SystemUpdateRepository.php',
];
$backupDirectory = $root . '/storage/backups/admin-access-recovery-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}
$manifest = [];
foreach ($files as $file) {
    $manifest[$file] = is_file($file);
    if (is_file($file)) {
        if (!copy($file, $backupDirectory . '/' . basename($file))) {
            throw new RuntimeException('Не удалось сохранить резервную копию ' . basename($file));
        }
    }
}

try {
    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();

    if (!r17columnExists($pdo, 'users', 'role')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'administrator'");
    }
    if (!r17columnExists($pdo, 'users', 'account_status')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN account_status VARCHAR(30) NOT NULL DEFAULT 'active'");
    }

    // Миграция старых значений ролей. До появления ролевой модели все существующие аккаунты были администраторами.
    $pdo->exec(
        "UPDATE users SET role = CASE
            WHEN LOWER(TRIM(COALESCE(role, ''))) IN ('administrator','admin','superadmin','super_admin','owner','root') THEN 'administrator'
            WHEN LOWER(TRIM(COALESCE(role, ''))) IN ('moderator','moder') THEN 'moderator'
            WHEN LOWER(TRIM(COALESCE(role, ''))) IN ('manager','specialist','employee') THEN 'manager'
            WHEN LOWER(TRIM(COALESCE(role, ''))) IN ('client','customer','user') THEN 'client'
            ELSE 'administrator'
         END"
    );
    $pdo->exec("UPDATE users SET account_status = 'active' WHERE account_status IS NULL OR account_status = ''");
    $stmt = $pdo->prepare(
        "UPDATE users SET role = 'administrator', account_status = 'active' WHERE LOWER(email) = LOWER(:email)"
    );
    $stmt->execute(['email' => 'admin@mirsaitov.net']);

    r17runInstaller($root);

    $accessUrl = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/portal/PortalAccessService.php';
    $access = r17download($accessUrl);
    $accessPath = $root . '/app/Services/PortalAccessService.php';
    r17write($accessPath, $access);
    r17lint($accessPath);

    // Исправляем длительность операций: считаем её на сервере, без зависимости от часового пояса браузера.
    $repositoryPath = $root . '/app/Repositories/SystemUpdateRepository.php';
    if (is_file($repositoryPath)) {
        $repository = (string) file_get_contents($repositoryPath);
        if (!str_contains($repository, 'AS duration_seconds')) {
            $repository = str_replace(
                "'SELECT * FROM system_updates ORDER BY id DESC LIMIT ' . \$limit",
                "'SELECT *, TIMESTAMPDIFF(SECOND, COALESCE(started_at, created_at), COALESCE(finished_at, NOW())) AS duration_seconds FROM system_updates ORDER BY id DESC LIMIT ' . \$limit",
                $repository,
                $count
            );
            if ($count === 1) {
                $repository = str_replace(
                    "            \$row['id'] = (int) \$row['id'];\n",
                    "            \$row['id'] = (int) \$row['id'];\n            \$row['duration_seconds'] = max(0, (int) (\$row['duration_seconds'] ?? 0));\n",
                    $repository
                );
                r17write($repositoryPath, $repository);
                r17lint($repositoryPath);
            }
        }
    }

    $jsPath = $root . '/assets/app.js';
    $js = (string) file_get_contents($jsPath);
    if (str_contains($js, 'function systemUpdatesElapsed(row)') && !str_contains($js, 'SYSTEM_UPDATE_SERVER_DURATION_V17')) {
        $needle = "    function systemUpdatesElapsed(row) {\n";
        $replacement = "    function systemUpdatesElapsed(row) {\n        /* SYSTEM_UPDATE_SERVER_DURATION_V17 */\n        const serverSeconds = Number(row.duration_seconds);\n        if (Number.isFinite(serverSeconds) && serverSeconds >= 0) {\n            if (serverSeconds < 60) return `${Math.round(serverSeconds)} сек.`;\n            const serverMinutes = Math.floor(serverSeconds / 60);\n            if (serverMinutes < 60) return `${serverMinutes} мин. ${Math.round(serverSeconds % 60)} сек.`;\n            return `${Math.floor(serverMinutes / 60)} ч. ${serverMinutes % 60} мин.`;\n        }\n";
        $js = str_replace($needle, $replacement, $js, $count);
        if ($count === 1) {
            r17write($jsPath, $js);
        }
    }

    r17out('Доступ администратора и клиентский контур восстановлены.');
    r17out('- старые роли приведены к новой модели;');
    r17out('- admin@mirsaitov.net закреплён как администратор;');
    r17out('- определение текущего пользователя усилено;');
    r17out('- версия 2026.08.01.16 применена или восстановлена;');
    r17out('- длительность обновлений считается на сервере без ошибки часового пояса.');
    r17out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($manifest as $destination => $existed) {
        $source = $backupDirectory . '/' . basename($destination);
        if ($existed && is_file($source)) {
            @copy($source, $destination);
        } elseif (!$existed && is_file($destination)) {
            @unlink($destination);
        }
    }
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
