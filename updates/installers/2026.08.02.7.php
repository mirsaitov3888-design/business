<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function p02out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function p02columnType(PDO $pdo, string $table, string $column): ?string
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
    return is_string($value) ? $value : null;
}

function p02tableExists(PDO $pdo, string $table): bool
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

function p02scanOldRoleReferences(string $root): array
{
    $references = [];
    foreach (['app', 'api.php', 'index.php', 'bin'] as $relative) {
        $path = $root . '/' . $relative;
        $files = [];

        if (is_file($path)) {
            $files[] = $path;
        } elseif (is_dir($path)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator(
                    $path,
                    FilesystemIterator::SKIP_DOTS
                )
            );
            foreach ($iterator as $file) {
                if (
                    $file instanceof SplFileInfo
                    && $file->isFile()
                    && in_array(
                        strtolower($file->getExtension()),
                        ['php', 'js'],
                        true
                    )
                ) {
                    $files[] = $file->getPathname();
                }
            }
        }

        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if (!is_string($content)) {
                continue;
            }

            $lines = preg_split('/\R/', $content) ?: [];
            foreach ($lines as $index => $line) {
                if (
                    preg_match(
                        '~[\'\"](admin|analyst|viewer)[\'\"]~',
                        $line,
                        $match
                    ) === 1
                ) {
                    $references[] = [
                        'file' => str_starts_with($file, $root . '/')
                            ? substr($file, strlen($root) + 1)
                            : $file,
                        'line' => $index + 1,
                        'role' => $match[1],
                        'preview' => mb_substr(trim($line), 0, 300),
                    ];
                }
            }
        }
    }

    return $references;
}

$root = getcwd() ?: '';
if (!is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Запустите установщик из корня проекта.');
}

require $root . '/app/bootstrap.php';
$pdo = \SeoAnalytics\Core\Database::pdo();

if (!p02tableExists($pdo, 'users')) {
    throw new RuntimeException('Таблица users не найдена.');
}

$backupTable = 'users_before_role_normalization_' . date('Ymd_His');
$pdo->exec(
    'CREATE TABLE `' . $backupTable . '` LIKE users'
);
$pdo->exec(
    'INSERT INTO `' . $backupTable . '` SELECT * FROM users'
);

try {
    $pdo->beginTransaction();

    $pdo->exec(
        "UPDATE users
         SET role = CASE
             WHEN role = 'admin' THEN 'administrator'
             WHEN role = 'analyst' THEN 'manager'
             WHEN role = 'viewer' THEN 'client'
             WHEN role IN ('administrator', 'moderator', 'manager', 'client')
                 THEN role
             ELSE 'client'
         END"
    );

    $pdo->exec(
        "UPDATE users
         SET account_status = 'active'
         WHERE account_status IS NULL OR account_status = ''"
    );

    $pdo->commit();
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    throw $exception;
}

$references = p02scanOldRoleReferences($root);
$auditDirectory = $root . '/storage/system-audits';
if (!is_dir($auditDirectory) && !mkdir($auditDirectory, 0700, true) && !is_dir($auditDirectory)) {
    throw new RuntimeException('Не удалось создать каталог системных аудитов.');
}

$auditPath = $auditDirectory . '/roles-' . date('Ymd-His') . '.json';
$audit = [
    'generated_at' => date(DATE_ATOM),
    'backup_table' => $backupTable,
    'current_column_type' => p02columnType($pdo, 'users', 'role'),
    'target_roles' => [
        'administrator',
        'moderator',
        'manager',
        'client',
    ],
    'old_role_references' => $references,
    'enum_contraction_ready' => count($references) === 0,
];
file_put_contents(
    $auditPath,
    json_encode(
        $audit,
        JSON_UNESCAPED_UNICODE
        | JSON_UNESCAPED_SLASHES
        | JSON_PRETTY_PRINT
    ) . PHP_EOL,
    LOCK_EX
);

$counts = $pdo->query(
    'SELECT role, COUNT(*) AS users_count
     FROM users
     GROUP BY role
     ORDER BY role'
)->fetchAll(PDO::FETCH_ASSOC);

p02out('P0.2 — значения ролей пользователей нормализованы.');
foreach ($counts as $row) {
    p02out(
        '- ' . (string) $row['role']
        . ': ' . (int) $row['users_count']
        . ' пользователей;'
    );
}
p02out('- резервная таблица: ' . $backupTable . ';');
p02out('- старых ссылок на роли в коде: ' . count($references) . ';');
p02out('- аудит: ' . $auditPath . ';');

if ($references) {
    p02out(
        'Предупреждение: enum пока не сужен. '
        . 'Сначала нужно заменить найденные проверки старых ролей в коде.'
    );
} else {
    p02out(
        'Код готов к отдельному патчу сужения enum до четырёх ролей.'
    );
}
