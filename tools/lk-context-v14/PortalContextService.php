<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class PortalContextDeniedException extends RuntimeException
{
}

final class PortalContextService
{
    private const SESSION_CLIENT = 'portal_context_client_id';
    private const SESSION_PROJECT = 'portal_context_project_id';

    public function __construct(
        private readonly PortalAccessService $access = new PortalAccessService()
    ) {
    }

    public function context(
        ?int $requestedClientId = null,
        ?int $requestedProjectId = null,
        bool $persist = true
    ): array {
        $this->ensureSession();
        $user = $this->access->currentUser();
        $role = (string) $user['role'];
        $clients = $this->accessibleClients($user);
        $allProjects = $this->accessibleProjects($user);

        $stored = $this->storedSelection((int) $user['id']);
        $sessionClientId = (int) ($_SESSION[self::SESSION_CLIENT] ?? 0);
        $sessionProjectId = (int) ($_SESSION[self::SESSION_PROJECT] ?? 0);

        $candidateProjectIds = array_values(array_filter([
            max(0, (int) $requestedProjectId),
            $sessionProjectId,
            (int) ($stored['project_id'] ?? 0),
        ]));

        $selectedProject = null;
        foreach ($candidateProjectIds as $candidateId) {
            $selectedProject = $this->findById($allProjects, $candidateId);
            if ($selectedProject !== null) {
                break;
            }
        }

        $candidateClientId = max(0, (int) $requestedClientId);
        if ($candidateClientId <= 0) {
            $candidateClientId = $sessionClientId > 0
                ? $sessionClientId
                : (int) ($stored['client_id'] ?? 0);
        }

        if (
            $candidateClientId > 0
            && !$this->containsId($clients, $candidateClientId)
        ) {
            $candidateClientId = 0;
        }

        if ($selectedProject === null) {
            $filtered = $candidateClientId > 0
                ? array_values(array_filter(
                    $allProjects,
                    static fn(array $project): bool =>
                        (int) ($project['client_id'] ?? 0) === $candidateClientId
                ))
                : $allProjects;
            $selectedProject = $filtered[0] ?? $allProjects[0] ?? null;
        }

        $selectedProjectId = (int) ($selectedProject['id'] ?? 0);
        $selectedClientId = (int) ($selectedProject['client_id'] ?? 0);

        if ($selectedClientId <= 0 && $candidateClientId > 0) {
            $selectedClientId = $candidateClientId;
        }

        if ($role === 'client') {
            $selectedClientId = (int) ($clients[0]['id'] ?? $selectedClientId);
            if (
                $selectedProject !== null
                && (int) ($selectedProject['client_id'] ?? 0) !== $selectedClientId
            ) {
                $selectedProject = $this->firstProjectForClient(
                    $allProjects,
                    $selectedClientId
                );
                $selectedProjectId = (int) ($selectedProject['id'] ?? 0);
            }
        }

        $projectsForSelectedClient = $selectedClientId > 0
            ? array_values(array_filter(
                $allProjects,
                static fn(array $project): bool =>
                    (int) ($project['client_id'] ?? 0) === $selectedClientId
            ))
            : array_values(array_filter(
                $allProjects,
                static fn(array $project): bool =>
                    (int) ($project['client_id'] ?? 0) === 0
            ));

        if ($projectsForSelectedClient === [] && $allProjects !== []) {
            $projectsForSelectedClient = $allProjects;
        }

        $sites = $selectedProjectId > 0
            ? $this->sitesForProject($selectedProjectId, $user)
            : [];

        if ($persist && $selectedProjectId > 0) {
            $_SESSION[self::SESSION_CLIENT] = $selectedClientId;
            $_SESSION[self::SESSION_PROJECT] = $selectedProjectId;
            $this->persistSelection(
                (int) $user['id'],
                $selectedClientId,
                $selectedProjectId
            );
        }

        $showClientSelector = in_array(
            $role,
            ['administrator', 'moderator'],
            true
        ) || ($role === 'manager' && count($clients) > 1);

        return [
            'version' => '2026.08.02.14',
            'user' => [
                'id' => (int) $user['id'],
                'name' => (string) ($user['name'] ?? ''),
                'email' => (string) ($user['email'] ?? ''),
                'role' => $role,
            ],
            'clients' => $clients,
            'projects' => $projectsForSelectedClient,
            'all_projects' => $allProjects,
            'selected_client_id' => $selectedClientId,
            'selected_project_id' => $selectedProjectId,
            'selected_client' => $this->findById($clients, $selectedClientId),
            'selected_project' => $selectedProject,
            'sites' => $sites,
            'site_ids' => array_values(array_map(
                static fn(array $site): int => (int) $site['id'],
                $sites
            )),
            'ui' => [
                'show_client_selector' => $showClientSelector,
                'show_project_selector' => count($projectsForSelectedClient) > 1,
                'client_locked' => $role === 'client',
                'project_locked' => count($projectsForSelectedClient) <= 1,
                'has_context' => $selectedProjectId > 0,
            ],
            'warnings' => [
                'client_has_multiple_accounts' =>
                    $role === 'client' && count($clients) > 1,
                'project_without_client' =>
                    $selectedProjectId > 0 && $selectedClientId <= 0,
            ],
        ];
    }

    public function requireProject(
        int $projectId,
        ?array $user = null
    ): array {
        $user ??= $this->access->currentUser();
        $project = $this->findById(
            $this->accessibleProjects($user),
            $projectId
        );
        if ($project === null) {
            throw new PortalContextDeniedException(
                'Нет доступа к выбранному проекту.'
            );
        }
        return $project;
    }

    public function sitesForProject(
        int $projectId,
        ?array $user = null
    ): array {
        $this->requireProject($projectId, $user);
        if (!$this->tableExists('project_sites')) {
            return [];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT
                id,
                project_id,
                name,
                url,
                host,
                status,
                metrika_counter_ids_json,
                webmaster_host_ids_json,
                source_type,
                source_id,
                created_at,
                updated_at
             FROM project_sites
             WHERE project_id = :project_id
               AND status <> "archived"
             ORDER BY status = "active" DESC, name ASC, id ASC'
        );
        $stmt->execute(['project_id' => $projectId]);
        $sites = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($sites as &$site) {
            $site['id'] = (int) $site['id'];
            $site['project_id'] = (int) $site['project_id'];
            $site['source_id'] = $site['source_id'] === null
                ? null
                : (int) $site['source_id'];
            $site['metrika_counter_ids'] = $this->jsonList(
                $site['metrika_counter_ids_json'] ?? null
            );
            $site['webmaster_host_ids'] = $this->jsonList(
                $site['webmaster_host_ids_json'] ?? null
            );
            unset(
                $site['metrika_counter_ids_json'],
                $site['webmaster_host_ids_json']
            );
        }
        unset($site);

        return $sites;
    }

    public function accessibleClients(?array $user = null): array
    {
        $user ??= $this->access->currentUser();
        if (!$this->tableExists('clients')) {
            return [];
        }

        $role = (string) $user['role'];
        $pdo = Database::pdo();

        if (in_array($role, ['administrator', 'moderator'], true)) {
            $stmt = $pdo->query(
                'SELECT id, name, status, manager_user_id
                 FROM clients
                 WHERE status <> "archive"
                 ORDER BY status = "active" DESC, name ASC, id ASC'
            );
        } elseif ($role === 'manager') {
            $stmt = $pdo->prepare(
                'SELECT id, name, status, manager_user_id
                 FROM clients
                 WHERE manager_user_id = :user_id
                   AND status <> "archive"
                 ORDER BY status = "active" DESC, name ASC, id ASC'
            );
            $stmt->execute(['user_id' => (int) $user['id']]);
        } else {
            if (!$this->tableExists('client_users')) {
                return [];
            }
            $stmt = $pdo->prepare(
                'SELECT c.id, c.name, c.status, c.manager_user_id
                 FROM clients c
                 INNER JOIN client_users cu ON cu.client_id = c.id
                 WHERE cu.user_id = :user_id
                   AND c.status <> "archive"
                 ORDER BY c.status = "active" DESC, c.name ASC, c.id ASC'
            );
            $stmt->execute(['user_id' => (int) $user['id']]);
        }

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['manager_user_id'] = $row['manager_user_id'] === null
                ? null
                : (int) $row['manager_user_id'];
        }
        unset($row);
        return $rows;
    }

    public function accessibleProjects(?array $user = null): array
    {
        $user ??= $this->access->currentUser();
        if (!$this->tableExists('projects')) {
            return [];
        }

        $pdo = Database::pdo();
        $hasLinks = $this->tableExists('project_client_links');
        $activeCondition = $this->columnExists('projects', 'active')
            ? ' AND p.active = 1'
            : '';

        $select = 'SELECT p.*';
        $join = '';
        if ($hasLinks) {
            $select .= ', pcl.client_id, c.name AS client_name';
            $join = ' LEFT JOIN project_client_links pcl ON pcl.project_id = p.id
                      LEFT JOIN clients c ON c.id = pcl.client_id';
        } else {
            $select .= ', NULL AS client_id, NULL AS client_name';
        }

        $role = (string) $user['role'];
        $params = [];
        $where = ' WHERE 1 = 1' . $activeCondition;

        if (!in_array($role, ['administrator', 'moderator'], true)) {
            $clientIds = array_values(array_map(
                static fn(array $client): int => (int) $client['id'],
                $this->accessibleClients($user)
            ));
            if ($clientIds === [] || !$hasLinks) {
                return [];
            }
            $placeholders = [];
            foreach ($clientIds as $index => $clientId) {
                $key = 'client_' . $index;
                $placeholders[] = ':' . $key;
                $params[$key] = $clientId;
            }
            $where .= ' AND pcl.client_id IN ('
                . implode(', ', $placeholders)
                . ')';
        }

        $sql = $select
            . ' FROM projects p'
            . $join
            . $where
            . ' ORDER BY COALESCE(c.name, "") ASC, p.id ASC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['client_id'] = $row['client_id'] === null
                ? 0
                : (int) $row['client_id'];
            $row['name'] = $this->projectName($row);
            $row['site_count'] = $this->siteCount((int) $row['id']);
        }
        unset($row);

        return $rows;
    }

    private function storedSelection(int $userId): array
    {
        if (!$this->tableExists('user_portal_context')) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT client_id, project_id
             FROM user_portal_context
             WHERE user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    private function persistSelection(
        int $userId,
        int $clientId,
        int $projectId
    ): void {
        if (!$this->tableExists('user_portal_context')) {
            return;
        }
        $stmt = Database::pdo()->prepare(
            'INSERT INTO user_portal_context
             (user_id, client_id, project_id, updated_at)
             VALUES
             (:user_id, :client_id, :project_id, CURRENT_TIMESTAMP)
             ON DUPLICATE KEY UPDATE
                client_id = VALUES(client_id),
                project_id = VALUES(project_id),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute([
            'user_id' => $userId,
            'client_id' => $clientId > 0 ? $clientId : null,
            'project_id' => $projectId,
        ]);
    }

    private function firstProjectForClient(
        array $projects,
        int $clientId
    ): ?array {
        foreach ($projects as $project) {
            if ((int) ($project['client_id'] ?? 0) === $clientId) {
                return $project;
            }
        }
        return null;
    }

    private function projectName(array $project): string
    {
        foreach (['name', 'title', 'site_name', 'site_url'] as $key) {
            $value = trim((string) ($project[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }
        return 'Проект #' . (int) ($project['id'] ?? 0);
    }

    private function siteCount(int $projectId): int
    {
        if (!$this->tableExists('project_sites')) {
            return 0;
        }
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM project_sites
             WHERE project_id = :project_id
               AND status <> "archived"'
        );
        $stmt->execute(['project_id' => $projectId]);
        return (int) $stmt->fetchColumn();
    }

    private function containsId(array $rows, int $id): bool
    {
        return $this->findById($rows, $id) !== null;
    }

    private function findById(array $rows, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        foreach ($rows as $row) {
            if ((int) ($row['id'] ?? 0) === $id) {
                return $row;
            }
        }
        return null;
    }

    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function tableExists(string $table): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function ensureSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
