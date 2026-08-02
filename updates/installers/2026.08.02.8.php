<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

const P03_TARGET_ENUM = "enum('administrator','moderator','manager','client')";
const P03_EXPANDED_ENUM = "enum('admin','analyst','viewer','administrator','moderator','manager','client')";

function p03out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function p03read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function p03write(string $path, string $content): void
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

function p03lint(string $path): void
{
    if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'php') {
        return;
    }
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
            'Ошибка PHP-синтаксиса в ' . $path . ':\n' . implode("\n", $output)
        );
    }
}

function p03tableExists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}

function p03columnType(PDO $pdo, string $table, string $column): ?string
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name
         LIMIT 1'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    $value = $stmt->fetchColumn();
    return is_string($value) ? strtolower($value) : null;
}

function p03applicationFiles(string $root): array
{
    $files = [];

    foreach (['app', 'bin'] as $relative) {
        $directory = $root . '/' . $relative;
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
                && in_array(strtolower($file->getExtension()), ['php', 'js'], true)
            ) {
                $files[] = $file->getPathname();
            }
        }
    }

    foreach (['api.php', 'index.php'] as $relative) {
        $path = $root . '/' . $relative;
        if (is_file($path)) {
            $files[] = $path;
        }
    }

    $files = array_values(array_unique($files));
    sort($files, SORT_STRING);
    return $files;
}

function p03relative(string $root, string $path): string
{
    return str_starts_with($path, $root . '/')
        ? substr($path, strlen($root) + 1)
        : $path;
}

function p03scanOldRoleReferences(string $root): array
{
    $references = [];

    foreach (p03applicationFiles($root) as $file) {
        $content = @file_get_contents($file);
        if (!is_string($content)) {
            continue;
        }

        $lines = preg_split('/\R/', $content) ?: [];
        foreach ($lines as $index => $line) {
            if (
                preg_match_all(
                    '~([\'\"])(admin|analyst|viewer)\\1~',
                    $line,
                    $matches,
                    PREG_SET_ORDER
                ) < 1
            ) {
                continue;
            }

            foreach ($matches as $match) {
                $references[] = [
                    'file' => p03relative($root, $file),
                    'line' => $index + 1,
                    'role' => $match[2],
                    'preview' => mb_substr(trim($line), 0, 300),
                ];
            }
        }
    }

    return $references;
}

function p03replaceRoleLiterals(string $content, array &$counts): string
{
    $mapping = [
        'admin' => 'administrator',
        'analyst' => 'manager',
        'viewer' => 'client',
    ];

    $updated = preg_replace_callback(
        '~([\'\"])(admin|analyst|viewer)\\1~',
        static function (array $match) use ($mapping, &$counts): string {
            $old = $match[2];
            $new = $mapping[$old];
            $counts[$old] = ($counts[$old] ?? 0) + 1;
            return $match[1] . $new . $match[1];
        },
        $content
    );

    if (!is_string($updated)) {
        throw new RuntimeException('Не удалось заменить старые литералы ролей.');
    }

    return $updated;
}

function p03updateSchema(string $schema): string
{
    $updated = preg_replace(
        '~role\s+ENUM\s*\([^\)]*\)\s+NOT\s+NULL\s+DEFAULT\s+[\'\"][^\'\"]+[\'\"]~i',
        "role ENUM('administrator','moderator','manager','client') NOT NULL DEFAULT 'client'",
        $schema,
        1,
        $count
    );

    if (!is_string($updated)) {
        throw new RuntimeException('Не удалось обработать sql/schema.sql.');
    }

    if ($count === 0 && str_contains(strtolower($schema), 'role enum(')) {
        throw new RuntimeException('Строка роли в sql/schema.sql имеет неизвестный формат.');
    }

    return $updated;
}

function p03backupFiles(string $root, array $files, string $backupDirectory): void
{
    foreach ($files as $path) {
        if (!is_file($path)) {
            continue;
        }

        $relative = p03relative($root, $path);
        $target = $backupDirectory . '/files/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог резервной копии: ' . $directory);
        }
        if (!copy($path, $target)) {
            throw new RuntimeException('Не удалось сохранить резервную копию: ' . $relative);
        }
    }
}

function p03restoreFiles(string $root, string $backupDirectory): void
{
    $filesRoot = $backupDirectory . '/files';
    if (!is_dir($filesRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($filesRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) {
            continue;
        }

        $relative = substr($file->getPathname(), strlen($filesRoot) + 1);
        $target = $root . '/' . $relative;
        $directory = dirname($target);
        if (!is_dir($directory)) {
            @mkdir($directory, 0775, true);
        }
        @copy($file->getPathname(), $target);
    }
}

$root = getcwd() ?: '';
if (!is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Запустите установщик из корня проекта.');
}

require $root . '/app/bootstrap.php';
$pdo = \SeoAnalytics\Core\Database::pdo();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!p03tableExists($pdo, 'users')) {
    throw new RuntimeException('Таблица users не найдена.');
}

$currentType = p03columnType($pdo, 'users', 'role');
$initialReferences = p03scanOldRoleReferences($root);
$schemaPath = $root . '/sql/schema.sql';

if ($currentType === P03_TARGET_ENUM && $initialReferences === []) {
    p03out('P0.3 уже установлен: enum и рабочий код используют только актуальные роли.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/p0-role-finalization-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать каталог резервной копии.');
}

$filesToBackup = p03applicationFiles($root);
if (is_file($schemaPath)) {
    $filesToBackup[] = $schemaPath;
}
p03backupFiles($root, $filesToBackup, $backupDirectory);

$backupTable = 'users_before_role_finalization_' . date('Ymd_His');
$pdo->exec('CREATE TABLE `' . $backupTable . '` LIKE users');
$pdo->exec('INSERT INTO `' . $backupTable . '` SELECT * FROM users');

$replacementCounts = [
    'admin' => 0,
    'analyst' => 0,
    'viewer' => 0,
];
$changedFiles = [];

try {
    foreach (p03applicationFiles($root) as $file) {
        $content = p03read($file);
        $updated = p03replaceRoleLiterals($content, $replacementCounts);
        if ($updated === $content) {
            continue;
        }

        p03write($file, $updated);
        p03lint($file);
        $changedFiles[] = p03relative($root, $file);
    }

    $remainingReferences = p03scanOldRoleReferences($root);
    if ($remainingReferences !== []) {
        throw new RuntimeException(
            'После безопасной замены осталось ссылок на старые роли: '
            . count($remainingReferences)
        );
    }

    if (is_file($schemaPath)) {
        $schema = p03read($schemaPath);
        $updatedSchema = p03updateSchema($schema);
        if ($updatedSchema !== $schema) {
            p03write($schemaPath, $updatedSchema);
            $changedFiles[] = 'sql/schema.sql';
        }
    }

    $pdo->exec(
        "UPDATE users
         SET role = CASE
             WHEN role = 'admin' THEN 'administrator'
             WHEN role = 'analyst' THEN 'manager'
             WHEN role = 'viewer' THEN 'client'
             WHEN role IN ('administrator', 'moderator', 'manager', 'client') THEN role
             ELSE 'client'
         END"
    );

    $invalid = (int) $pdo->query(
        "SELECT COUNT(*)
         FROM users
         WHERE role NOT IN ('administrator', 'moderator', 'manager', 'client')"
    )->fetchColumn();
    if ($invalid > 0) {
        throw new RuntimeException('В users остались неизвестные значения ролей: ' . $invalid);
    }

    $pdo->exec(
        "ALTER TABLE users
         MODIFY role ENUM('administrator','moderator','manager','client')
         NOT NULL DEFAULT 'client'"
    );

    $finalType = p03columnType($pdo, 'users', 'role');
    if ($finalType !== P03_TARGET_ENUM) {
        throw new RuntimeException(
            'После миграции получен неожиданный тип users.role: ' . ($finalType ?? 'null')
        );
    }

    $auditDirectory = $root . '/storage/system-audits';
    if (!is_dir($auditDirectory) && !mkdir($auditDirectory, 0700, true) && !is_dir($auditDirectory)) {
        throw new RuntimeException('Не удалось создать каталог системных аудитов.');
    }

    $audit = [
        'generated_at' => date(DATE_ATOM),
        'version' => '2026.08.02.8',
        'backup_directory' => $backupDirectory,
        'backup_table' => $backupTable,
        'initial_references' => $initialReferences,
        'replacement_counts' => $replacementCounts,
        'changed_files' => $changedFiles,
        'remaining_references' => [],
        'column_type' => $finalType,
        'target_roles' => ['administrator', 'moderator', 'manager', 'client'],
    ];
    $auditPath = $auditDirectory . '/roles-final-' . date('Ymd-His') . '.json';
    p03write(
        $auditPath,
        json_encode(
            $audit,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
        ) . PHP_EOL
    );

    $counts = $pdo->query(
        'SELECT role, COUNT(*) AS users_count
         FROM users
         GROUP BY role
         ORDER BY role'
    )->fetchAll(PDO::FETCH_ASSOC);

    p03out('P0.3 — роли окончательно нормализованы.');
    p03out('- исходных ссылок на старые роли: ' . count($initialReferences) . ';');
    p03out('- изменено файлов: ' . count(array_unique($changedFiles)) . ';');
    p03out('- замен admin → administrator: ' . $replacementCounts['admin'] . ';');
    p03out('- замен analyst → manager: ' . $replacementCounts['analyst'] . ';');
    p03out('- замен viewer → client: ' . $replacementCounts['viewer'] . ';');
    p03out('- users.role: ' . $finalType . ';');
    foreach ($counts as $row) {
        p03out('- ' . $row['role'] . ': ' . (int) $row['users_count'] . ' пользователей;');
    }
    p03out('- резервная копия файлов: ' . $backupDirectory . ';');
    p03out('- резервная таблица: ' . $backupTable . ';');
    p03out('- аудит: ' . $auditPath . ';');
} catch (Throwable $exception) {
    p03restoreFiles($root, $backupDirectory);

    try {
        $pdo->exec(
            "ALTER TABLE users
             MODIFY role ENUM('admin','analyst','viewer','administrator','moderator','manager','client')
             NOT NULL DEFAULT 'admin'"
        );
        $pdo->exec(
            'UPDATE users u
             INNER JOIN `' . $backupTable . '` b ON b.id = u.id
             SET u.role = b.role,
                 u.account_status = b.account_status'
        );
    } catch (Throwable $rollbackException) {
        p03out('Предупреждение: откат базы требует проверки: ' . $rollbackException->getMessage());
    }

    throw new RuntimeException(
        $exception->getMessage() . ' Файлы восстановлены из резервной копии.',
        0,
        $exception
    );
}
