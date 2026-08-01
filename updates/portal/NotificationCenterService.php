<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class NotificationCenterService
{
    public function __construct(
        private readonly PortalAccessService $access = new PortalAccessService()
    ) {
    }

    public function context(): array
    {
        $this->syncSources();
        $user = $this->access->currentUser();
        return [
            'user' => $user,
            'role_labels' => AccessService::roles(),
            'visible_sections' => $this->access->visibleSections(),
            'permissions' => $this->access->permissions(),
            'unread_notifications' => $this->unreadCount($user['id']),
        ];
    }

    public function list(array $filters = []): array
    {
        $this->syncSources();
        $user = $this->access->currentUser();
        $params = ['user_id' => $user['id']];
        $where = ['r.user_id = :user_id', 'r.archived_at IS NULL'];

        $type = trim((string) ($filters['notification_type'] ?? ''));
        if ($type !== '') {
            $where[] = 'n.notification_type = :notification_type';
            $params['notification_type'] = $type;
        }
        $severity = trim((string) ($filters['severity'] ?? ''));
        if ($severity !== '') {
            $where[] = 'n.severity = :severity';
            $params['severity'] = $severity;
        }
        $clientId = (int) ($filters['client_id'] ?? 0);
        if ($clientId > 0) {
            $this->access->requireClient($clientId);
            $where[] = 'n.client_id = :client_id';
            $params['client_id'] = $clientId;
        }
        if (!empty($filters['unread_only'])) {
            $where[] = 'r.read_at IS NULL';
        }

        $stmt = Database::pdo()->prepare(
            'SELECT n.*, r.read_at, r.archived_at, c.name AS client_name,
                    p.name AS project_name, s.name AS site_name
             FROM notification_recipients r
             INNER JOIN notifications n ON n.id = r.notification_id
             LEFT JOIN clients c ON c.id = n.client_id
             LEFT JOIN projects p ON p.id = n.project_id
             LEFT JOIN monitored_sites s ON s.id = n.site_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY (r.read_at IS NULL) DESC,
                      FIELD(n.severity, "critical", "warning", "info") ASC,
                      n.created_at DESC
             LIMIT 300'
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            foreach (['id', 'client_id', 'project_id', 'site_id', 'created_by'] as $key) {
                if (array_key_exists($key, $row)) {
                    $row[$key] = $row[$key] === null ? null : (int) $row[$key];
                }
            }
            $row['is_unread'] = empty($row['read_at']);
        }
        unset($row);

        return [
            'items' => $rows,
            'unread_count' => $this->unreadCount($user['id']),
            'filters' => $filters,
        ];
    }

    public function create(array $input): int
    {
        $user = $this->access->requireRoles(['administrator', 'moderator', 'manager']);
        $type = (string) ($input['notification_type'] ?? 'operational');
        if (!in_array($type, ['system', 'operational', 'client'], true)) {
            $type = 'operational';
        }
        if ($type === 'system' && $user['role'] !== 'administrator') {
            throw new RuntimeException('Системные уведомления может создавать только администратор.');
        }

        $clientId = (int) ($input['client_id'] ?? 0);
        if ($type !== 'system') {
            if ($clientId <= 0) {
                throw new RuntimeException('Выберите клиента.');
            }
            $this->access->requireClient($clientId);
        } else {
            $clientId = 0;
        }

        $title = trim((string) ($input['title'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        if ($title === '' || $message === '') {
            throw new RuntimeException('Заполните заголовок и текст уведомления.');
        }
        $severity = (string) ($input['severity'] ?? 'info');
        if (!in_array($severity, ['critical', 'warning', 'info'], true)) {
            $severity = 'info';
        }

        $pdo = Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO notifications
             (notification_type, severity, title, message, client_id, project_id, site_id,
              source_type, source_id, action_url, dedupe_key, status, created_by, created_at)
             VALUES
             (:notification_type, :severity, :title, :message, :client_id, :project_id, :site_id,
              :source_type, :source_id, :action_url, NULL, "open", :created_by, NOW())'
        );
        $stmt->execute([
            'notification_type' => $type,
            'severity' => $severity,
            'title' => mb_substr($title, 0, 500),
            'message' => mb_substr($message, 0, 50000),
            'client_id' => $clientId > 0 ? $clientId : null,
            'project_id' => $this->nullableInt($input['project_id'] ?? null),
            'site_id' => $this->nullableInt($input['site_id'] ?? null),
            'source_type' => 'manual',
            'source_id' => null,
            'action_url' => $this->nullableString($input['action_url'] ?? null, 1000),
            'created_by' => $user['id'],
        ]);
        $notificationId = (int) $pdo->lastInsertId();
        $this->assignRecipients($notificationId, $type, $clientId, $user['id']);
        return $notificationId;
    }

    public function markRead(int $notificationId): void
    {
        $userId = $this->access->currentUser()['id'];
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_recipients SET read_at = COALESCE(read_at, NOW())
             WHERE notification_id = :notification_id AND user_id = :user_id'
        );
        $stmt->execute(['notification_id' => $notificationId, 'user_id' => $userId]);
    }

    public function markAllRead(): void
    {
        $userId = $this->access->currentUser()['id'];
        $stmt = Database::pdo()->prepare(
            'UPDATE notification_recipients SET read_at = COALESCE(read_at, NOW())
             WHERE user_id = :user_id AND archived_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);
    }

    public function unreadCount(?int $userId = null): int
    {
        $userId ??= $this->access->currentUser()['id'];
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM notification_recipients
             WHERE user_id = :user_id AND read_at IS NULL AND archived_at IS NULL'
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    public function syncSources(): void
    {
        $this->syncSystemUpdates();
        $this->syncMonitoringEvents();
    }

    private function syncSystemUpdates(): void
    {
        if (!$this->tableExists('system_updates')) {
            return;
        }
        $rows = Database::pdo()->query(
            'SELECT id, version, title, status, log_text, created_at
             FROM system_updates
             WHERE status IN ("failed", "rollback_failed")
             ORDER BY id DESC LIMIT 20'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $dedupe = 'system_update:' . $row['id'] . ':' . $row['status'];
            if ($this->dedupeExists($dedupe)) {
                continue;
            }
            $message = trim((string) ($row['log_text'] ?? ''));
            if ($message === '') {
                $message = 'Операция обновления завершилась ошибкой.';
            }
            $id = $this->insertAutomated([
                'notification_type' => 'system',
                'severity' => 'critical',
                'title' => 'Ошибка системного обновления ' . (string) $row['version'],
                'message' => mb_substr($message, 0, 50000),
                'source_type' => 'system_update',
                'source_id' => (string) $row['id'],
                'dedupe_key' => $dedupe,
                'action_url' => '#system-updates',
            ]);
            $this->assignRecipients($id, 'system', 0, null);
        }
    }

    private function syncMonitoringEvents(): void
    {
        if (!$this->tableExists('monitor_events')) {
            return;
        }
        $rows = Database::pdo()->query(
            'SELECT e.id, e.site_id, e.event_type, e.category, e.severity, e.message, e.created_at,
                    scl.client_id, ms.project_id, ms.name AS site_name
             FROM monitor_events e
             LEFT JOIN site_client_links scl ON scl.site_id = e.site_id
             LEFT JOIN monitored_sites ms ON ms.id = e.site_id
             ORDER BY e.id DESC LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $dedupe = 'monitor_event:' . $row['id'];
            if ($this->dedupeExists($dedupe)) {
                continue;
            }
            $clientId = (int) ($row['client_id'] ?? 0);
            $severity = in_array((string) ($row['severity'] ?? ''), ['critical', 'warning', 'info'], true)
                ? (string) $row['severity']
                : 'info';
            $siteName = trim((string) ($row['site_name'] ?? ''));
            $title = $this->monitorEventTitle((string) ($row['event_type'] ?? ''), $siteName);
            $id = $this->insertAutomated([
                'notification_type' => 'operational',
                'severity' => $severity,
                'title' => $title,
                'message' => (string) ($row['message'] ?? 'Состояние сайта изменилось.'),
                'client_id' => $clientId > 0 ? $clientId : null,
                'project_id' => $this->nullableInt($row['project_id'] ?? null),
                'site_id' => $this->nullableInt($row['site_id'] ?? null),
                'source_type' => 'monitor_event',
                'source_id' => (string) $row['id'],
                'dedupe_key' => $dedupe,
                'action_url' => '#site-monitoring',
            ]);
            $this->assignRecipients($id, 'operational', $clientId, null);
        }
    }

    private function insertAutomated(array $data): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO notifications
             (notification_type, severity, title, message, client_id, project_id, site_id,
              source_type, source_id, action_url, dedupe_key, status, created_by, created_at)
             VALUES
             (:notification_type, :severity, :title, :message, :client_id, :project_id, :site_id,
              :source_type, :source_id, :action_url, :dedupe_key, "open", NULL, NOW())'
        );
        $stmt->execute([
            'notification_type' => $data['notification_type'],
            'severity' => $data['severity'],
            'title' => mb_substr((string) $data['title'], 0, 500),
            'message' => mb_substr((string) $data['message'], 0, 50000),
            'client_id' => $data['client_id'] ?? null,
            'project_id' => $data['project_id'] ?? null,
            'site_id' => $data['site_id'] ?? null,
            'source_type' => $data['source_type'] ?? null,
            'source_id' => $data['source_id'] ?? null,
            'action_url' => $data['action_url'] ?? null,
            'dedupe_key' => $data['dedupe_key'] ?? null,
        ]);
        return (int) Database::pdo()->lastInsertId();
    }

    private function assignRecipients(int $notificationId, string $type, int $clientId, ?int $creatorId): void
    {
        $ids = [];
        $pdo = Database::pdo();
        if ($type === 'system') {
            $ids = $pdo->query(
                'SELECT id FROM users WHERE role = "administrator" AND account_status = "active"'
            )->fetchAll(PDO::FETCH_COLUMN);
        } elseif ($type === 'operational') {
            $ids = $pdo->query(
                'SELECT id FROM users WHERE role IN ("administrator", "moderator") AND account_status = "active"'
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($clientId > 0) {
                $stmt = $pdo->prepare('SELECT manager_user_id FROM clients WHERE id = :id');
                $stmt->execute(['id' => $clientId]);
                $manager = (int) $stmt->fetchColumn();
                if ($manager > 0) {
                    $ids[] = $manager;
                }
            }
        } else {
            if ($clientId > 0) {
                $stmt = $pdo->prepare('SELECT user_id FROM client_users WHERE client_id = :client_id');
                $stmt->execute(['client_id' => $clientId]);
                $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            }
        }
        if ($creatorId !== null && $creatorId > 0) {
            $ids[] = $creatorId;
        }
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $insert = $pdo->prepare(
            'INSERT IGNORE INTO notification_recipients
             (notification_id, user_id, read_at, archived_at, created_at)
             VALUES (:notification_id, :user_id, NULL, NULL, NOW())'
        );
        foreach ($ids as $userId) {
            $insert->execute(['notification_id' => $notificationId, 'user_id' => $userId]);
        }
    }

    private function dedupeExists(string $key): bool
    {
        $stmt = Database::pdo()->prepare('SELECT id FROM notifications WHERE dedupe_key = :dedupe_key LIMIT 1');
        $stmt->execute(['dedupe_key' => $key]);
        return (bool) $stmt->fetchColumn();
    }

    private function monitorEventTitle(string $eventType, string $siteName): string
    {
        $labels = [
            'site_down' => 'Сайт недоступен',
            'site_restored' => 'Сайт восстановлен',
            'slow_response' => 'Сайт отвечает медленно',
            'robots_changed' => 'Изменился robots.txt',
            'sitemap_changed' => 'Изменился Sitemap',
            'metrika_missing' => 'Не обнаружен счётчик Метрики',
            'indexing_blocked' => 'Обнаружен запрет индексации',
            'ssl_expiring' => 'Истекает SSL-сертификат',
        ];
        $title = $labels[$eventType] ?? 'Изменилось состояние сайта';
        return $siteName !== '' ? $title . ': ' . $siteName : $title;
    }

    private function tableExists(string $table): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = (int) $value;
        return $value > 0 ? $value : null;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
