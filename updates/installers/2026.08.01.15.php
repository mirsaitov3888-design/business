<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function r15out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function r15read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function r15write(string $path, string $content): void
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

function r15lint(string $path): void
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

function r15columnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute(['table_name' => $table, 'column_name' => $column]);
    return (int) $stmt->fetchColumn() > 0;
}

function r15applyPrevious(string $root): void
{
    $repository = $root . '/app/Repositories/SystemUpdateRepository.php';
    $content = is_file($repository) ? (string) file_get_contents($repository) : '';
    if (str_contains($content, 'public function setProgress(')) {
        return;
    }

    $url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.01.14.php';
    $expectedSha = '4d9ba61a86de31cb2ee79d17239b830c7195054b02895dce74918742c1bd0be6';
    $context = stream_context_create([
        'http' => ['timeout' => 120, 'follow_location' => 1, 'user_agent' => 'Mirsaitov Update Chain/15'],
        'https' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $body = file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Не удалось загрузить обязательную версию 2026.08.01.14.');
    }
    if (!hash_equals($expectedSha, hash('sha256', $body))) {
        throw new RuntimeException('Не совпала SHA-256 обязательной версии 2026.08.01.14.');
    }
    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-14-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Не удалось создать временный файл версии 2026.08.01.14.');
    }
    file_put_contents($temporary, $body, LOCK_EX);
    $output = [];
    $code = 0;
    exec('cd ' . escapeshellarg($root) . ' && ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($temporary) . ' 2>&1', $output, $code);
    @unlink($temporary);
    if ($code !== 0) {
        throw new RuntimeException("Не удалось применить версию 2026.08.01.14:\n" . implode("\n", $output));
    }
}

$root = getcwd() ?: '';
if (!is_file($root . '/app/bootstrap.php') || !is_file($root . '/sql/schema.sql')) {
    throw new RuntimeException('Запустите установщик из корня проекта.');
}

r15applyPrevious($root);

$schemaPath = $root . '/sql/schema.sql';
$accessServicePath = $root . '/app/Services/AccessService.php';
$notificationRepositoryPath = $root . '/app/Repositories/NotificationRepository.php';
$schema = r15read($schemaPath);

$backupDirectory = $root . '/storage/backups/roles-notifications-foundation-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}
foreach ([$schemaPath, $accessServicePath, $notificationRepositoryPath] as $source) {
    if (is_file($source)) {
        copy($source, $backupDirectory . '/' . basename($source));
    }
}

try {
    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();

    if (!r15columnExists($pdo, 'users', 'role')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(30) NOT NULL DEFAULT 'administrator'");
    }
    if (!r15columnExists($pdo, 'users', 'account_status')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN account_status VARCHAR(30) NOT NULL DEFAULT 'active'");
    }
    $pdo->exec("UPDATE users SET role = 'administrator' WHERE role IS NULL OR role = ''");

    $statements = [
        "CREATE TABLE IF NOT EXISTS clients (\n"
        . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
        . " name VARCHAR(255) NOT NULL,\n"
        . " status VARCHAR(30) NOT NULL DEFAULT 'active',\n"
        . " manager_user_id BIGINT UNSIGNED NULL,\n"
        . " contact_name VARCHAR(255) NULL,\n"
        . " contact_email VARCHAR(255) NULL,\n"
        . " contact_phone VARCHAR(100) NULL,\n"
        . " notes TEXT NULL,\n"
        . " created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . " updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (id), KEY idx_clients_status (status), KEY idx_clients_manager (manager_user_id)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS client_users (\n"
        . " client_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (client_id, user_id), KEY idx_client_users_user (user_id)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS project_client_links (\n"
        . " project_id BIGINT UNSIGNED NOT NULL, client_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (project_id), KEY idx_project_client_client (client_id)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS site_client_links (\n"
        . " site_id BIGINT UNSIGNED NOT NULL, client_id BIGINT UNSIGNED NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (site_id), KEY idx_site_client_client (client_id)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notifications (\n"
        . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
        . " notification_type VARCHAR(30) NOT NULL, severity VARCHAR(20) NOT NULL DEFAULT 'info',\n"
        . " title VARCHAR(500) NOT NULL, message TEXT NOT NULL,\n"
        . " client_id BIGINT UNSIGNED NULL, project_id BIGINT UNSIGNED NULL, site_id BIGINT UNSIGNED NULL,\n"
        . " source_type VARCHAR(80) NULL, source_id VARCHAR(100) NULL, action_url VARCHAR(1000) NULL,\n"
        . " dedupe_key VARCHAR(190) NULL, status VARCHAR(30) NOT NULL DEFAULT 'open',\n"
        . " created_by BIGINT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, resolved_at DATETIME NULL,\n"
        . " PRIMARY KEY (id), KEY idx_notifications_type_date (notification_type, created_at),\n"
        . " KEY idx_notifications_client (client_id, created_at), KEY idx_notifications_status (status, created_at),\n"
        . " UNIQUE KEY uniq_notifications_dedupe (dedupe_key)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_recipients (\n"
        . " notification_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL,\n"
        . " read_at DATETIME NULL, archived_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (notification_id, user_id), KEY idx_notification_recipient_user (user_id, read_at)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS notification_deliveries (\n"
        . " id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, notification_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NULL,\n"
        . " channel VARCHAR(30) NOT NULL, recipient VARCHAR(255) NULL, delivery_status VARCHAR(30) NOT NULL DEFAULT 'queued',\n"
        . " error_text TEXT NULL, sent_at DATETIME NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n"
        . " PRIMARY KEY (id), KEY idx_notification_delivery (notification_id, channel)\n"
        . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];
    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $accessService = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

final class AccessService
{
    public const ROLE_ADMINISTRATOR = 'administrator';
    public const ROLE_MODERATOR = 'moderator';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_CLIENT = 'client';

    public static function roles(): array
    {
        return [
            self::ROLE_ADMINISTRATOR => 'Администратор',
            self::ROLE_MODERATOR => 'Модератор',
            self::ROLE_MANAGER => 'Менеджер',
            self::ROLE_CLIENT => 'Клиент',
        ];
    }

    public static function can(string $role, string $permission): bool
    {
        $matrix = [
            self::ROLE_ADMINISTRATOR => ['*'],
            self::ROLE_MODERATOR => ['clients.manage', 'projects.manage', 'notifications.manage', 'reports.moderate', 'monitoring.view', 'analytics.view'],
            self::ROLE_MANAGER => ['clients.assigned', 'notifications.assigned', 'reports.manage', 'monitoring.manage', 'analytics.view', 'support.manage'],
            self::ROLE_CLIENT => ['own.view', 'own.notifications', 'own.reports', 'own.monitoring', 'own.support'],
        ];
        $permissions = $matrix[$role] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }
}
PHPFILE;

    $notificationRepository = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use SeoAnalytics\Core\Database;

final class NotificationRepository
{
    public function create(array $data, array $recipientUserIds = []): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO notifications
             (notification_type, severity, title, message, client_id, project_id, site_id,
              source_type, source_id, action_url, dedupe_key, created_by, created_at)
             VALUES
             (:notification_type, :severity, :title, :message, :client_id, :project_id, :site_id,
              :source_type, :source_id, :action_url, :dedupe_key, :created_by, NOW())'
        );
        $stmt->execute([
            'notification_type' => (string) ($data['notification_type'] ?? 'operational'),
            'severity' => (string) ($data['severity'] ?? 'info'),
            'title' => mb_substr(trim((string) ($data['title'] ?? 'Уведомление')), 0, 500),
            'message' => trim((string) ($data['message'] ?? '')),
            'client_id' => $data['client_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'dedupe_key' => $data['dedupe_key'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
        $id = (int) Database::pdo()->lastInsertId();
        $recipient = Database::pdo()->prepare(
            'INSERT IGNORE INTO notification_recipients (notification_id, user_id, created_at) VALUES (:notification_id, :user_id, NOW())'
        );
        foreach (array_unique(array_map('intval', $recipientUserIds)) as $userId) {
            if ($userId > 0) {
                $recipient->execute(['notification_id' => $id, 'user_id' => $userId]);
            }
        }
        return $id;
    }

    public function forUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT n.*, r.read_at, r.archived_at
             FROM notification_recipients r
             INNER JOIN notifications n ON n.id = r.notification_id
             WHERE r.user_id = :user_id AND r.archived_at IS NULL
             ORDER BY (r.read_at IS NULL) DESC, n.created_at DESC
             LIMIT ' . $limit
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function markRead(int $notificationId, int $userId): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_recipients SET read_at = COALESCE(read_at, NOW())
             WHERE notification_id = :notification_id AND user_id = :user_id'
        );
        $stmt->execute(['notification_id' => $notificationId, 'user_id' => $userId]);
    }
}
PHPFILE;

    r15write($accessServicePath, $accessService);
    r15write($notificationRepositoryPath, $notificationRepository);
    r15lint($accessServicePath);
    r15lint($notificationRepositoryPath);

    if (!str_contains($schema, 'ROLES_CLIENTS_NOTIFICATIONS_FOUNDATION_V15')) {
        $schema .= "\n\n-- ROLES_CLIENTS_NOTIFICATIONS_FOUNDATION_V15\n"
            . "-- Роли пользователей: administrator, moderator, manager, client.\n"
            . "-- Таблицы clients, client_users, project_client_links, site_client_links, notifications, notification_recipients, notification_deliveries создаются установщиком 2026.08.01.15.\n";
        r15write($schemaPath, $schema);
    }

    r15out('Фундамент ролей, клиентов и уведомлений установлен.');
    r15out('- добавлены четыре роли пользователей;');
    r15out('- все существующие пользователи сохранены администраторами;');
    r15out('- добавлены клиенты и назначение менеджеров;');
    r15out('- добавлены привязки проектов и сайтов к клиентам;');
    r15out('- добавлено единое хранилище системных, операционных и клиентских уведомлений;');
    r15out('- добавлены базовые классы прав доступа и уведомлений.');
    r15out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\n");
    exit(1);
}
