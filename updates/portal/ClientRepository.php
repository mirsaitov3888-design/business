<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;
use SeoAnalytics\Services\PortalAccessService;

final class ClientRepository
{
    public function __construct(
        private readonly PortalAccessService $access = new PortalAccessService()
    ) {
    }

    public function summary(): array
    {
        $rows = $this->list();
        $summary = [
            'total' => count($rows),
            'active' => 0,
            'pending' => 0,
            'paused' => 0,
            'archived' => 0,
            'without_manager' => 0,
        ];
        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'active');
            if (array_key_exists($status, $summary)) {
                $summary[$status]++;
            }
            if (empty($row['manager_user_id'])) {
                $summary['without_manager']++;
            }
        }
        return $summary;
    }

    public function list(array $filters = []): array
    {
        $ids = $this->access->accessibleClientIds();
        if ($ids === []) {
            return [];
        }

        $params = [];
        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'client_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }

        $where = ['c.id IN (' . implode(',', $placeholders) . ')'];
        $status = trim((string) ($filters['status'] ?? ''));
        if ($status !== '') {
            $where[] = 'c.status = :status';
            $params['status'] = $status;
        }
        $managerId = (int) ($filters['manager_user_id'] ?? 0);
        if ($managerId > 0) {
            $where[] = 'c.manager_user_id = :manager_user_id';
            $params['manager_user_id'] = $managerId;
        }
        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $where[] = '(c.name LIKE :search OR c.contact_name LIKE :search OR c.contact_email LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        $sql = 'SELECT c.*, u.name AS manager_name, u.email AS manager_email,
                       (SELECT COUNT(*) FROM project_client_links pcl WHERE pcl.client_id = c.id) AS projects_count,
                       (SELECT COUNT(*) FROM site_client_links scl WHERE scl.client_id = c.id) AS sites_count,
                       (SELECT COUNT(*) FROM client_users cu WHERE cu.client_id = c.id) AS users_count,
                       (SELECT COUNT(*) FROM notifications n WHERE n.client_id = c.id AND n.status = "open") AS open_notifications
                FROM clients c
                LEFT JOIN users u ON u.id = c.manager_user_id
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY (c.status = "active") DESC, c.name ASC';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row = $this->normalize($row);
        }
        unset($row);
        return $rows;
    }

    public function detail(int $clientId): array
    {
        $this->access->requireClient($clientId);
        $stmt = Database::pdo()->prepare(
            'SELECT c.*, u.name AS manager_name, u.email AS manager_email
             FROM clients c
             LEFT JOIN users u ON u.id = c.manager_user_id
             WHERE c.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            throw new RuntimeException('Клиент не найден.');
        }

        return [
            'client' => $this->normalize($client),
            'users' => $this->clientUsers($clientId),
            'projects' => $this->clientProjects($clientId),
            'sites' => $this->clientSites($clientId),
            'notifications' => $this->clientNotifications($clientId),
        ];
    }

    public function options(): array
    {
        $pdo = Database::pdo();
        $users = $pdo->query(
            'SELECT id, name, email, role, account_status FROM users ORDER BY COALESCE(name, email), id'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($users as &$user) {
            $user['id'] = (int) $user['id'];
        }
        unset($user);

        $projects = $pdo->query('SELECT * FROM projects ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($projects as &$project) {
            $project['id'] = (int) $project['id'];
            $project['display_name'] = (string) (
                $project['name']
                ?? $project['title']
                ?? $project['site_name']
                ?? $project['url']
                ?? ('Проект #' . $project['id'])
            );
            $project['display_url'] = (string) (
                $project['site_url']
                ?? $project['url']
                ?? $project['domain']
                ?? ''
            );
        }
        unset($project);

        $sites = [];
        if ($this->tableExists('monitored_sites')) {
            $sites = $pdo->query(
                'SELECT id, project_id, name, base_url, host, is_active FROM monitored_sites ORDER BY name, id'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($sites as &$site) {
                $site['id'] = (int) $site['id'];
                $site['project_id'] = $site['project_id'] === null ? null : (int) $site['project_id'];
            }
            unset($site);
        }

        return [
            'users' => $users,
            'managers' => array_values(array_filter(
                $users,
                static fn(array $user): bool => in_array((string) ($user['role'] ?? ''), ['administrator', 'manager'], true)
            )),
            'client_users' => array_values(array_filter(
                $users,
                static fn(array $user): bool => (string) ($user['role'] ?? '') === 'client'
            )),
            'projects' => $projects,
            'sites' => $sites,
        ];
    }

    public function save(array $data): int
    {
        $this->access->requireRoles(['administrator', 'moderator']);
        $id = (int) ($data['id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Укажите название клиента.');
        }

        $status = (string) ($data['status'] ?? 'active');
        if (!in_array($status, ['active', 'pending', 'paused', 'archived'], true)) {
            $status = 'active';
        }
        $managerId = (int) ($data['manager_user_id'] ?? 0);
        $managerId = $managerId > 0 ? $managerId : null;

        $params = [
            'name' => mb_substr($name, 0, 255),
            'status' => $status,
            'manager_user_id' => $managerId,
            'contact_name' => $this->nullable($data['contact_name'] ?? null, 255),
            'contact_email' => $this->nullable($data['contact_email'] ?? null, 255),
            'contact_phone' => $this->nullable($data['contact_phone'] ?? null, 100),
            'notes' => $this->nullable($data['notes'] ?? null, 20000),
        ];

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            if ($id > 0) {
                $check = $pdo->prepare('SELECT id FROM clients WHERE id = :id');
                $check->execute(['id' => $id]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Клиент не найден.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE clients SET name = :name, status = :status, manager_user_id = :manager_user_id,
                     contact_name = :contact_name, contact_email = :contact_email,
                     contact_phone = :contact_phone, notes = :notes, updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute($params + ['id' => $id]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO clients
                     (name, status, manager_user_id, contact_name, contact_email, contact_phone, notes, created_at, updated_at)
                     VALUES
                     (:name, :status, :manager_user_id, :contact_name, :contact_email, :contact_phone, :notes, NOW(), NOW())'
                );
                $stmt->execute($params);
                $id = (int) $pdo->lastInsertId();
            }

            $this->replaceLinks($pdo, 'project_client_links', 'project_id', $id, $data['project_ids'] ?? []);
            $this->replaceLinks($pdo, 'site_client_links', 'site_id', $id, $data['site_ids'] ?? []);
            $this->replaceLinks($pdo, 'client_users', 'user_id', $id, $data['user_ids'] ?? []);
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }

        return $id;
    }

    private function replaceLinks(PDO $pdo, string $table, string $column, int $clientId, mixed $values): void
    {
        $values = is_array($values) ? array_values(array_unique(array_filter(array_map('intval', $values)))) : [];
        $delete = $pdo->prepare('DELETE FROM ' . $table . ' WHERE client_id = :client_id');
        $delete->execute(['client_id' => $clientId]);
        if ($values === []) {
            return;
        }
        $insert = $pdo->prepare(
            'INSERT INTO ' . $table . ' (' . $column . ', client_id, created_at)
             VALUES (:' . $column . ', :client_id, NOW())'
        );
        foreach ($values as $value) {
            if ($value > 0) {
                $insert->execute([$column => $value, 'client_id' => $clientId]);
            }
        }
    }

    private function clientUsers(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT u.id, u.name, u.email, u.role, u.account_status
             FROM client_users cu INNER JOIN users u ON u.id = cu.user_id
             WHERE cu.client_id = :client_id ORDER BY COALESCE(u.name, u.email)'
        );
        $stmt->execute(['client_id' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        return $rows;
    }

    private function clientProjects(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT p.* FROM project_client_links pcl INNER JOIN projects p ON p.id = pcl.project_id
             WHERE pcl.client_id = :client_id ORDER BY p.id DESC'
        );
        $stmt->execute(['client_id' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['display_name'] = (string) ($row['name'] ?? $row['title'] ?? $row['site_name'] ?? ('Проект #' . $row['id']));
            $row['display_url'] = (string) ($row['site_url'] ?? $row['url'] ?? $row['domain'] ?? '');
        }
        unset($row);
        return $rows;
    }

    private function clientSites(int $clientId): array
    {
        if (!$this->tableExists('monitored_sites')) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT s.id, s.project_id, s.name, s.base_url, s.host, s.is_active, s.last_status,
                    s.last_http_code, s.last_response_ms, s.last_checked_at
             FROM site_client_links scl INNER JOIN monitored_sites s ON s.id = scl.site_id
             WHERE scl.client_id = :client_id ORDER BY s.name, s.id'
        );
        $stmt->execute(['client_id' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['project_id'] = $row['project_id'] === null ? null : (int) $row['project_id'];
        }
        unset($row);
        return $rows;
    }

    private function clientNotifications(int $clientId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, notification_type, severity, title, message, status, created_at
             FROM notifications WHERE client_id = :client_id ORDER BY created_at DESC LIMIT 20'
        );
        $stmt->execute(['client_id' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        return $rows;
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

    private function normalize(array $row): array
    {
        foreach (['id', 'manager_user_id', 'projects_count', 'sites_count', 'users_count', 'open_notifications'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        return $row;
    }

    private function nullable(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
