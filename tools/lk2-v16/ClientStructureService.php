<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class ClientStructureDeniedException extends RuntimeException
{
}

final class ClientStructureService
{
    private PDO $pdo;
    private PortalAccessService $access;
    private array $columns = [];

    public function __construct(?PortalAccessService $access = null)
    {
        $this->pdo = Database::pdo();
        $this->access = $access ?? new PortalAccessService();
    }

    public function context(): array
    {
        $user = $this->access->currentUser();
        $role = (string) ($user['role'] ?? 'client');
        $clients = $this->clients($user);

        return [
            'user' => [
                'id' => (int) ($user['id'] ?? 0),
                'name' => (string) ($user['name'] ?? ''),
                'role' => $role,
            ],
            'clients' => $clients,
            'managers' => $this->managerOptions(),
            'client_users' => $this->clientUserOptions(),
            'permissions' => [
                'create_client' => in_array($role, ['administrator', 'moderator'], true),
                'manage_client_users' => in_array($role, ['administrator', 'moderator'], true),
                'manage_manager' => in_array($role, ['administrator', 'moderator'], true),
                'edit_assigned' => in_array($role, ['administrator', 'moderator', 'manager'], true),
                'read_only' => $role === 'client',
            ],
        ];
    }

    public function clients(?array $user = null): array
    {
        $user ??= $this->access->currentUser();
        $role = (string) ($user['role'] ?? 'client');
        $params = [];
        $where = '';

        if ($role === 'manager') {
            $where = 'WHERE c.manager_user_id = :user_id';
            $params['user_id'] = (int) $user['id'];
        } elseif ($role === 'client') {
            $where = 'WHERE EXISTS (
                SELECT 1 FROM client_users cu
                WHERE cu.client_id = c.id AND cu.user_id = :user_id
            )';
            $params['user_id'] = (int) $user['id'];
        } elseif (!in_array($role, ['administrator', 'moderator'], true)) {
            return [];
        }

        $sql = 'SELECT c.*,
                       manager.name AS manager_name,
                       (SELECT COUNT(*) FROM project_client_links pcl WHERE pcl.client_id = c.id) AS project_count,
                       (SELECT COUNT(*)
                          FROM project_sites ps
                          INNER JOIN project_client_links pcl2 ON pcl2.project_id = ps.project_id
                         WHERE pcl2.client_id = c.id AND ps.status <> "archived") AS site_count
                  FROM clients c
             LEFT JOIN users manager ON manager.id = c.manager_user_id
                  ' . $where . '
              ORDER BY CASE WHEN c.status = "active" THEN 0 ELSE 1 END,
                       c.name ASC,
                       c.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row = $this->normalizeClient($row);
        }
        unset($row);
        return $rows;
    }

    public function client(int $clientId): array
    {
        $this->requireClient($clientId, false);
        $stmt = $this->pdo->prepare(
            'SELECT c.*, manager.name AS manager_name
               FROM clients c
          LEFT JOIN users manager ON manager.id = c.manager_user_id
              WHERE c.id = :id
              LIMIT 1'
        );
        $stmt->execute(['id' => $clientId]);
        $client = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$client) {
            throw new RuntimeException('Клиент не найден.');
        }

        $usersStmt = $this->pdo->prepare(
            'SELECT u.id, u.name, u.email, u.role, u.account_status
               FROM client_users cu
               INNER JOIN users u ON u.id = cu.user_id
              WHERE cu.client_id = :client_id
              ORDER BY u.name ASC, u.id ASC'
        );
        $usersStmt->execute(['client_id' => $clientId]);
        $clientUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($clientUsers as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return [
            'client' => $this->normalizeClient($client),
            'client_users' => $clientUsers,
            'projects' => $this->projects($clientId),
        ];
    }

    public function saveClient(array $data): int
    {
        $user = $this->access->currentUser();
        $role = (string) $user['role'];
        $id = max(0, (int) ($data['id'] ?? 0));

        if ($id <= 0 && !in_array($role, ['administrator', 'moderator'], true)) {
            throw new ClientStructureDeniedException('Создавать клиентов может администратор или модератор.');
        }
        if ($id > 0) {
            $this->requireClient($id, true);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Укажите название клиента.');
        }

        $status = trim((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            $status = 'active';
        }

        $managerId = max(0, (int) ($data['manager_user_id'] ?? 0));
        if ($role === 'manager') {
            $managerId = (int) $user['id'];
            $status = (string) ($this->findClient($id)['status'] ?? 'active');
        }

        $payload = [
            'name' => mb_substr($name, 0, 255),
            'status' => $status,
            'manager_user_id' => $managerId > 0 ? $managerId : null,
            'contact_name' => $this->nullableText($data['contact_name'] ?? null, 255),
            'contact_email' => $this->nullableText($data['contact_email'] ?? null, 255),
            'contact_phone' => $this->nullableText($data['contact_phone'] ?? null, 100),
            'notes' => $this->nullableText($data['notes'] ?? null, 10000),
        ];

        if ($id > 0) {
            $before = $this->findClient($id);
            $stmt = $this->pdo->prepare(
                'UPDATE clients SET
                    name = :name,
                    status = :status,
                    manager_user_id = :manager_user_id,
                    contact_name = :contact_name,
                    contact_email = :contact_email,
                    contact_phone = :contact_phone,
                    notes = :notes,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $stmt->execute($payload + ['id' => $id]);
            $this->audit('client_updated', 'client', $id, $before, $this->findClient($id));
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO clients
             (name, status, manager_user_id, contact_name, contact_email,
              contact_phone, notes, created_at, updated_at)
             VALUES
             (:name, :status, :manager_user_id, :contact_name, :contact_email,
              :contact_phone, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
        );
        $stmt->execute($payload);
        $id = (int) $this->pdo->lastInsertId();
        $this->audit('client_created', 'client', $id, null, $this->findClient($id));
        return $id;
    }

    public function saveClientUsers(int $clientId, array $userIds): void
    {
        $user = $this->access->currentUser();
        if (!in_array((string) $user['role'], ['administrator', 'moderator'], true)) {
            throw new ClientStructureDeniedException('Недостаточно прав для управления пользователями клиента.');
        }
        $this->requireClient($clientId, true);

        $ids = array_values(array_unique(array_filter(
            array_map('intval', $userIds),
            static fn(int $id): bool => $id > 0
        )));
        $valid = [];
        if ($ids !== []) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $stmt = $this->pdo->prepare(
                'SELECT id FROM users
                 WHERE id IN (' . $placeholders . ')
                   AND role = "client"
                   AND account_status = "active"'
            );
            $stmt->execute($ids);
            $valid = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $this->pdo->beginTransaction();
        try {
            $beforeStmt = $this->pdo->prepare(
                'SELECT user_id FROM client_users WHERE client_id = :client_id ORDER BY user_id'
            );
            $beforeStmt->execute(['client_id' => $clientId]);
            $before = array_map('intval', $beforeStmt->fetchAll(PDO::FETCH_COLUMN));

            $this->pdo->prepare('DELETE FROM client_users WHERE client_id = :client_id')
                ->execute(['client_id' => $clientId]);
            $insert = $this->pdo->prepare(
                'INSERT INTO client_users (client_id, user_id, created_at)
                 VALUES (:client_id, :user_id, CURRENT_TIMESTAMP)'
            );
            foreach ($valid as $userId) {
                $insert->execute(['client_id' => $clientId, 'user_id' => $userId]);
            }
            $this->audit('client_users_updated', 'client', $clientId, $before, $valid);
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function projects(int $clientId): array
    {
        $this->requireClient($clientId, false);
        $nameExpression = $this->projectNameExpression('p');
        $descriptionExpression = $this->hasColumn('projects', 'lk_description')
            ? 'p.lk_description'
            : 'NULL';
        $statusExpression = $this->hasColumn('projects', 'lk_status')
            ? 'p.lk_status'
            : ($this->hasColumn('projects', 'active')
                ? 'CASE WHEN p.active = 1 THEN "active" ELSE "archived" END'
                : '"active"');
        $sortExpression = $this->hasColumn('projects', 'lk_sort_order')
            ? 'p.lk_sort_order'
            : 'p.id';

        $sql = 'SELECT p.id,
                       ' . $nameExpression . ' AS name,
                       ' . $descriptionExpression . ' AS description,
                       ' . $statusExpression . ' AS status,
                       ' . $sortExpression . ' AS sort_order,
                       (SELECT COUNT(*) FROM project_sites ps
                         WHERE ps.project_id = p.id AND ps.status <> "archived") AS site_count
                  FROM projects p
                  INNER JOIN project_client_links pcl ON pcl.project_id = p.id
                 WHERE pcl.client_id = :client_id
              ORDER BY CASE WHEN ' . $statusExpression . ' = "active" THEN 0 ELSE 1 END,
                       ' . $sortExpression . ' ASC,
                       p.id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['client_id' => $clientId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['sort_order'] = (int) ($row['sort_order'] ?? 0);
            $row['site_count'] = (int) ($row['site_count'] ?? 0);
            $row['sites'] = $this->sites((int) $row['id']);
        }
        unset($row);
        return $rows;
    }

    public function saveProject(array $data): int
    {
        $clientId = max(0, (int) ($data['client_id'] ?? 0));
        $this->requireClient($clientId, true);
        $id = max(0, (int) ($data['id'] ?? 0));
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Укажите название проекта.');
        }
        $description = $this->nullableText($data['description'] ?? null, 10000);
        $status = trim((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            $status = 'active';
        }
        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));

        if ($id > 0) {
            $this->requireProjectForClient($id, $clientId);
            $before = $this->projectSnapshot($id);
            $sets = [];
            $params = ['id' => $id];
            if ($this->hasColumn('projects', 'name')) {
                $sets[] = 'name = :name';
                $params['name'] = mb_substr($name, 0, 190);
            }
            if ($this->hasColumn('projects', 'title')) {
                $sets[] = 'title = :title';
                $params['title'] = mb_substr($name, 0, 255);
            }
            if ($this->hasColumn('projects', 'lk_description')) {
                $sets[] = 'lk_description = :description';
                $params['description'] = $description;
            }
            if ($this->hasColumn('projects', 'lk_status')) {
                $sets[] = 'lk_status = :status';
                $params['status'] = $status;
            }
            if ($this->hasColumn('projects', 'lk_sort_order')) {
                $sets[] = 'lk_sort_order = :sort_order';
                $params['sort_order'] = $sortOrder;
            }
            if ($this->hasColumn('projects', 'active')) {
                $sets[] = 'active = :active';
                $params['active'] = $status === 'archived' ? 0 : 1;
            }
            if ($this->hasColumn('projects', 'updated_at')) {
                $sets[] = 'updated_at = CURRENT_TIMESTAMP';
            }
            if ($sets === []) {
                throw new RuntimeException('В таблице проектов нет доступных полей для изменения.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = :id'
            );
            $stmt->execute($params);
            $this->audit('project_updated', 'project', $id, $before, $this->projectSnapshot($id));
            return $id;
        }

        $id = $this->insertAdaptiveProject($name, $description, $status, $sortOrder);
        $this->pdo->prepare(
            'INSERT INTO project_client_links (project_id, client_id, created_at)
             VALUES (:project_id, :client_id, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE client_id = VALUES(client_id)'
        )->execute(['project_id' => $id, 'client_id' => $clientId]);
        $this->audit('project_created', 'project', $id, null, $this->projectSnapshot($id));
        return $id;
    }

    public function archiveProject(int $clientId, int $projectId): void
    {
        $this->requireClient($clientId, true);
        $this->requireProjectForClient($projectId, $clientId);
        $before = $this->projectSnapshot($projectId);
        $sets = [];
        if ($this->hasColumn('projects', 'lk_status')) {
            $sets[] = 'lk_status = "archived"';
        }
        if ($this->hasColumn('projects', 'active')) {
            $sets[] = 'active = 0';
        }
        if ($this->hasColumn('projects', 'updated_at')) {
            $sets[] = 'updated_at = CURRENT_TIMESTAMP';
        }
        if ($sets !== []) {
            $this->pdo->exec(
                'UPDATE projects SET ' . implode(', ', $sets) . ' WHERE id = ' . (int) $projectId
            );
        }
        $this->pdo->prepare(
            'UPDATE project_sites SET status = "archived", updated_at = CURRENT_TIMESTAMP
             WHERE project_id = :project_id'
        )->execute(['project_id' => $projectId]);
        $this->audit('project_archived', 'project', $projectId, $before, $this->projectSnapshot($projectId));
    }

    public function sites(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM project_sites
              WHERE project_id = :project_id
              ORDER BY CASE WHEN status = "active" THEN 0 WHEN status = "paused" THEN 1 ELSE 2 END,
                       sort_order ASC,
                       id ASC'
        );
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row = $this->normalizeSite($row);
        }
        unset($row);
        return $rows;
    }

    public function saveSite(array $data): int
    {
        $clientId = max(0, (int) ($data['client_id'] ?? 0));
        $projectId = max(0, (int) ($data['project_id'] ?? 0));
        $this->requireClient($clientId, true);
        $this->requireProjectForClient($projectId, $clientId);

        $id = max(0, (int) ($data['id'] ?? 0));
        $name = trim((string) ($data['name'] ?? ''));
        $url = $this->normalizeUrl((string) ($data['url'] ?? ''));
        if ($name === '' || $url === '') {
            throw new RuntimeException('Укажите название и URL сайта.');
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '') {
            throw new RuntimeException('Не удалось определить домен сайта.');
        }
        $status = trim((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            $status = 'active';
        }
        $metrika = $this->identifierList($data['metrika_counter_ids'] ?? []);
        $webmaster = $this->identifierList($data['webmaster_host_ids'] ?? []);
        $sortOrder = max(0, (int) ($data['sort_order'] ?? 0));
        $notes = $this->nullableText($data['notes'] ?? null, 10000);

        $payload = [
            'project_id' => $projectId,
            'name' => mb_substr($name, 0, 190),
            'url' => mb_substr(rtrim($url, '/'), 0, 1000),
            'host' => mb_substr($host, 0, 255),
            'status' => $status,
            'metrika_counter_ids_json' => json_encode($metrika, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'webmaster_host_ids_json' => json_encode($webmaster, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'sort_order' => $sortOrder,
            'notes' => $notes,
        ];

        if ($id > 0) {
            $before = $this->siteSnapshot($id, $projectId);
            if (!$before) {
                throw new RuntimeException('Сайт проекта не найден.');
            }
            $stmt = $this->pdo->prepare(
                'UPDATE project_sites SET
                    name = :name,
                    url = :url,
                    host = :host,
                    status = :status,
                    metrika_counter_ids_json = :metrika_counter_ids_json,
                    webmaster_host_ids_json = :webmaster_host_ids_json,
                    sort_order = :sort_order,
                    notes = :notes,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND project_id = :project_id'
            );
            $stmt->execute($payload + ['id' => $id]);
            $this->audit('site_updated', 'site', $id, $before, $this->siteSnapshot($id, $projectId));
            return $id;
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO project_sites
             (project_id, name, url, host, status,
              metrika_counter_ids_json, webmaster_host_ids_json,
              source_type, source_id, sort_order, notes, created_at, updated_at)
             VALUES
             (:project_id, :name, :url, :host, :status,
              :metrika_counter_ids_json, :webmaster_host_ids_json,
              "manual", NULL, :sort_order, :notes, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                host = VALUES(host),
                status = VALUES(status),
                metrika_counter_ids_json = VALUES(metrika_counter_ids_json),
                webmaster_host_ids_json = VALUES(webmaster_host_ids_json),
                sort_order = VALUES(sort_order),
                notes = VALUES(notes),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute($payload);
        $id = (int) $this->pdo->lastInsertId();
        if ($id <= 0) {
            $find = $this->pdo->prepare(
                'SELECT id FROM project_sites WHERE project_id = :project_id AND url = :url LIMIT 1'
            );
            $find->execute(['project_id' => $projectId, 'url' => $payload['url']]);
            $id = (int) $find->fetchColumn();
        }
        $this->audit('site_created', 'site', $id, null, $this->siteSnapshot($id, $projectId));
        return $id;
    }

    public function archiveSite(int $clientId, int $projectId, int $siteId): void
    {
        $this->requireClient($clientId, true);
        $this->requireProjectForClient($projectId, $clientId);
        $before = $this->siteSnapshot($siteId, $projectId);
        if (!$before) {
            throw new RuntimeException('Сайт проекта не найден.');
        }
        $this->pdo->prepare(
            'UPDATE project_sites SET status = "archived", updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND project_id = :project_id'
        )->execute(['id' => $siteId, 'project_id' => $projectId]);
        $this->audit('site_archived', 'site', $siteId, $before, $this->siteSnapshot($siteId, $projectId));
    }

    public function reorderSites(int $clientId, int $projectId, array $siteIds): void
    {
        $this->requireClient($clientId, true);
        $this->requireProjectForClient($projectId, $clientId);
        $ids = array_values(array_unique(array_filter(array_map('intval', $siteIds))));
        $stmt = $this->pdo->prepare(
            'UPDATE project_sites SET sort_order = :sort_order, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id AND project_id = :project_id'
        );
        foreach ($ids as $index => $siteId) {
            $stmt->execute([
                'sort_order' => ($index + 1) * 10,
                'id' => $siteId,
                'project_id' => $projectId,
            ]);
        }
        $this->audit('sites_reordered', 'project', $projectId, null, $ids);
    }

    private function requireClient(int $clientId, bool $write): array
    {
        if ($clientId <= 0) {
            throw new RuntimeException('Клиент не выбран.');
        }
        $user = $this->access->currentUser();
        $role = (string) ($user['role'] ?? 'client');
        $client = $this->findClient($clientId);
        if (!$client) {
            throw new RuntimeException('Клиент не найден.');
        }

        $allowed = in_array($role, ['administrator', 'moderator'], true);
        if ($role === 'manager') {
            $allowed = (int) ($client['manager_user_id'] ?? 0) === (int) $user['id'];
        }
        if ($role === 'client') {
            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*) FROM client_users WHERE client_id = :client_id AND user_id = :user_id'
            );
            $stmt->execute(['client_id' => $clientId, 'user_id' => (int) $user['id']]);
            $allowed = (int) $stmt->fetchColumn() > 0 && !$write;
        }
        if (!$allowed) {
            throw new ClientStructureDeniedException('Нет доступа к выбранному клиенту.');
        }
        return $client;
    }

    private function requireProjectForClient(int $projectId, int $clientId): void
    {
        if ($projectId <= 0) {
            throw new RuntimeException('Проект не выбран.');
        }
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM project_client_links
             WHERE project_id = :project_id AND client_id = :client_id'
        );
        $stmt->execute(['project_id' => $projectId, 'client_id' => $clientId]);
        if ((int) $stmt->fetchColumn() <= 0) {
            throw new ClientStructureDeniedException('Проект не относится к выбранному клиенту.');
        }
    }

    private function managerOptions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, email, role
             FROM users
             WHERE role IN ("administrator", "moderator", "manager")
               AND account_status = "active"
             ORDER BY name ASC, id ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        return $rows;
    }

    private function clientUserOptions(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, name, email, role
             FROM users
             WHERE role = "client" AND account_status = "active"
             ORDER BY name ASC, id ASC'
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);
        return $rows;
    }

    private function insertAdaptiveProject(
        string $name,
        ?string $description,
        string $status,
        int $sortOrder
    ): int {
        $columns = $this->columnMeta('projects');
        $insertColumns = [];
        $values = [];
        $params = [];

        foreach ($columns as $column => $meta) {
            if (str_contains(strtolower((string) ($meta['Extra'] ?? '')), 'auto_increment')) {
                continue;
            }
            $value = null;
            $literal = null;
            $known = true;
            switch ($column) {
                case 'name':
                case 'title':
                    $value = mb_substr($name, 0, $column === 'name' ? 190 : 255);
                    break;
                case 'site_url':
                    $value = '';
                    break;
                case 'active':
                case 'enabled':
                    $value = $status === 'archived' ? 0 : 1;
                    break;
                case 'lk_description':
                    $value = $description;
                    break;
                case 'lk_status':
                    $value = $status;
                    break;
                case 'lk_sort_order':
                    $value = $sortOrder;
                    break;
                case 'created_at':
                case 'updated_at':
                    $literal = 'CURRENT_TIMESTAMP';
                    break;
                case 'goal_ids_json':
                    $literal = 'JSON_ARRAY()';
                    break;
                default:
                    $known = false;
                    break;
            }

            $required = strtoupper((string) ($meta['Null'] ?? 'YES')) === 'NO'
                && ($meta['Default'] ?? null) === null;
            if (!$known && !$required) {
                continue;
            }
            if (!$known && $required) {
                $type = strtolower((string) ($meta['Type'] ?? ''));
                if (str_contains($type, 'int') || str_contains($type, 'decimal') || str_contains($type, 'float')) {
                    $value = 0;
                } elseif (str_contains($type, 'json')) {
                    $literal = 'JSON_OBJECT()';
                } elseif (str_contains($type, 'date') || str_contains($type, 'time')) {
                    $literal = 'CURRENT_TIMESTAMP';
                } else {
                    $value = '';
                }
            }

            $insertColumns[] = '`' . str_replace('`', '', $column) . '`';
            if ($literal !== null) {
                $values[] = $literal;
            } else {
                $key = 'v_' . count($params);
                $values[] = ':' . $key;
                $params[$key] = $value;
            }
        }

        if ($insertColumns === []) {
            throw new RuntimeException('Не удалось подготовить поля нового проекта.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (' . implode(', ', $insertColumns) . ')
             VALUES (' . implode(', ', $values) . ')'
        );
        $stmt->execute($params);
        $id = (int) $this->pdo->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('Не удалось создать проект.');
        }
        return $id;
    }

    private function projectNameExpression(string $alias): string
    {
        $parts = [];
        foreach (['name', 'title', 'domain', 'site_url'] as $column) {
            if ($this->hasColumn('projects', $column)) {
                $parts[] = 'NULLIF(' . $alias . '.`' . $column . '`, "")';
            }
        }
        $parts[] = 'CONCAT("Проект #", ' . $alias . '.id)';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function findClient(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeClient($row) : null;
    }

    private function projectSnapshot(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function siteSnapshot(int $id, int $projectId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM project_sites WHERE id = :id AND project_id = :project_id LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'project_id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeSite($row) : null;
    }

    private function normalizeClient(array $row): array
    {
        foreach (['id', 'manager_user_id', 'project_count', 'site_count'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        return $row;
    }

    private function normalizeSite(array $row): array
    {
        foreach (['id', 'project_id', 'source_id', 'sort_order'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        foreach (['metrika_counter_ids_json', 'webmaster_host_ids_json'] as $key) {
            $decoded = is_string($row[$key] ?? null)
                ? json_decode((string) $row[$key], true)
                : $row[$key] ?? [];
            $target = $key === 'metrika_counter_ids_json'
                ? 'metrika_counter_ids'
                : 'webmaster_host_ids';
            $row[$target] = is_array($decoded) ? array_values($decoded) : [];
        }
        return $row;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
    }

    private function identifierList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/u', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $item = trim((string) $item);
            if ($item !== '') {
                $result[$item] = mb_substr($item, 0, 500);
            }
        }
        return array_values($result);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function hasColumn(string $table, string $column): bool
    {
        return isset($this->columnMeta($table)[$column]);
    }

    private function columnMeta(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $stmt = $this->pdo->prepare(
            'SELECT COLUMN_NAME AS Field,
                    COLUMN_TYPE AS Type,
                    IS_NULLABLE AS `Null`,
                    COLUMN_DEFAULT AS `Default`,
                    EXTRA AS Extra
               FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name
              ORDER BY ORDINAL_POSITION'
        );
        $stmt->execute(['table_name' => $table]);
        $result = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $result[(string) $row['Field']] = $row;
        }
        return $this->columns[$table] = $result;
    }

    private function audit(
        string $action,
        string $entityType,
        int $entityId,
        mixed $before,
        mixed $after
    ): void {
        $user = $this->access->currentUser();
        $stmt = $this->pdo->prepare(
            'INSERT INTO client_structure_changes
             (user_id, action_key, entity_type, entity_id, before_json, after_json, created_at)
             VALUES
             (:user_id, :action_key, :entity_type, :entity_id, :before_json, :after_json, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'user_id' => (int) $user['id'],
            'action_key' => mb_substr($action, 0, 80),
            'entity_type' => mb_substr($entityType, 0, 40),
            'entity_id' => $entityId > 0 ? $entityId : null,
            'before_json' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }
}
