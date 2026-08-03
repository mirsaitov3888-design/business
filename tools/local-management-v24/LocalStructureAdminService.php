<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class LocalStructureAdminService
{
    private PDO $pdo;

    public function __construct(
        private readonly PortalAccessService $access = new PortalAccessService()
    ) {
        $this->pdo = Database::pdo();
    }

    public function context(int $projectId): array
    {
        $this->requireAdministrator();
        $project = $this->project($projectId);
        $clientId = $this->clientIdForProject($projectId);
        $clients = $this->pdo->query(
            'SELECT id, name, status, bitrix_company_id, bitrix_company_name
             FROM clients
             WHERE status <> "archived"
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($clients as &$client) {
            $client['id'] = (int) $client['id'];
            $client['bitrix_company_id'] = $client['bitrix_company_id'] === null
                ? null
                : (int) $client['bitrix_company_id'];
        }
        unset($client);

        return [
            'project' => $project,
            'current_client_id' => $clientId,
            'clients' => $clients,
            'policy' => [
                'bitrix_delete_allowed' => false,
                'local_delete_only' => true,
            ],
        ];
    }

    public function moveProject(array $data): array
    {
        $user = $this->requireAdministrator();
        $projectId = max(0, (int) ($data['project_id'] ?? 0));
        $targetClientId = max(0, (int) ($data['target_client_id'] ?? 0));
        if ($projectId <= 0 || $targetClientId <= 0) {
            throw new RuntimeException('Выберите проект и нового клиента.');
        }

        $project = $this->project($projectId);
        $sourceClientId = $this->clientIdForProject($projectId);
        $targetClient = $this->client($targetClientId);
        if ($sourceClientId === $targetClientId) {
            return [
                'project_id' => $projectId,
                'client_id' => $targetClientId,
                'changed' => false,
                'message' => 'Проект уже относится к выбранному клиенту.',
            ];
        }

        $before = [
            'project' => $project,
            'source_client_id' => $sourceClientId,
            'target_client' => $targetClient,
            'bitrix_links' => $this->rowsByProject('bitrix24_project_links', $projectId),
            'client_bitrix_projects' => $this->rowsByProject('client_bitrix_projects', $projectId),
        ];

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO project_client_links (project_id, client_id, created_at)
                 VALUES (:project_id, :client_id, NOW())
                 ON DUPLICATE KEY UPDATE client_id = VALUES(client_id)'
            )->execute([
                'project_id' => $projectId,
                'client_id' => $targetClientId,
            ]);

            if ($this->tableExists('client_bitrix_projects')) {
                $this->pdo->prepare(
                    'UPDATE client_bitrix_projects
                     SET client_id = :client_id, updated_at = NOW()
                     WHERE project_id = :project_id'
                )->execute([
                    'client_id' => $targetClientId,
                    'project_id' => $projectId,
                ]);
            }

            if ($this->tableExists('bitrix24_project_links')) {
                $sets = [];
                $params = ['project_id' => $projectId];
                if ($this->columnExists('bitrix24_project_links', 'bitrix_company_id')) {
                    $sets[] = 'bitrix_company_id = :bitrix_company_id';
                    $params['bitrix_company_id'] = $targetClient['bitrix_company_id'] ?? null;
                }
                if ($this->columnExists('bitrix24_project_links', 'bitrix_company_name')) {
                    $sets[] = 'bitrix_company_name = :bitrix_company_name';
                    $params['bitrix_company_name'] = $targetClient['bitrix_company_name']
                        ?? $targetClient['name'];
                }
                if ($this->columnExists('bitrix24_project_links', 'updated_at')) {
                    $sets[] = 'updated_at = NOW()';
                }
                if ($sets !== []) {
                    $this->pdo->prepare(
                        'UPDATE bitrix24_project_links SET '
                        . implode(', ', $sets)
                        . ' WHERE project_id = :project_id'
                    )->execute($params);
                }
            }

            $this->writeAudit(
                (int) $user['id'],
                'project_client_changed',
                'project',
                $projectId,
                $before + ['new_client_id' => $targetClientId]
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'project_id' => $projectId,
            'client_id' => $targetClientId,
            'changed' => true,
            'message' => 'Клиент проекта изменён только в портале. Bitrix24 не изменялся.',
        ];
    }

    public function deleteSite(array $data): array
    {
        $user = $this->requireAdministrator();
        $projectId = max(0, (int) ($data['project_id'] ?? 0));
        $siteId = max(0, (int) ($data['site_id'] ?? 0));
        $confirmation = trim((string) ($data['confirmation'] ?? ''));
        if ($projectId <= 0 || $siteId <= 0) {
            throw new RuntimeException('Сайт не выбран.');
        }
        $site = $this->site($projectId, $siteId);
        if ($confirmation !== (string) $site['name']) {
            throw new RuntimeException('Для удаления введите точное название сайта.');
        }

        $snapshot = $this->siteSnapshot($projectId, $siteId);
        $this->pdo->beginTransaction();
        try {
            $this->deleteSiteRows($projectId, $siteId);
            $this->writeAudit(
                (int) $user['id'],
                'site_deleted_locally',
                'site',
                $siteId,
                $snapshot
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'site_id' => $siteId,
            'project_id' => $projectId,
            'message' => 'Сайт удалён только из портала. Bitrix24 не изменялся.',
        ];
    }

    public function deleteProject(array $data): array
    {
        $user = $this->requireAdministrator();
        $projectId = max(0, (int) ($data['project_id'] ?? 0));
        $confirmation = trim((string) ($data['confirmation'] ?? ''));
        if ($projectId <= 0) {
            throw new RuntimeException('Проект не выбран.');
        }
        $project = $this->project($projectId);
        if ($confirmation !== (string) $project['name']) {
            throw new RuntimeException('Для удаления введите точное название проекта.');
        }

        $snapshot = $this->projectSnapshot($projectId);
        $siteIds = array_map(
            'intval',
            $this->pdo->query(
                'SELECT id FROM project_sites WHERE project_id = ' . $projectId
            )->fetchAll(PDO::FETCH_COLUMN)
        );

        $this->pdo->beginTransaction();
        try {
            foreach ($siteIds as $siteId) {
                $this->deleteSiteRows($projectId, $siteId);
            }

            $this->deleteByProject('project_source_links', $projectId);
            $this->deleteByProject('report_site_links', $projectId);
            $this->deleteByProject('conversion_goals', $projectId);
            $this->deleteByProject('sales_records', $projectId);
            $this->deleteByProject('sales_import_batches', $projectId);
            $this->deleteByProject('client_bitrix_projects', $projectId);
            $this->deleteByProject('bitrix24_project_links', $projectId);
            $this->deleteByProject('project_client_links', $projectId);
            $this->deleteByProject('notifications', $projectId);
            $this->deleteByProject('project_source_snapshots', $projectId);

            $stmt = $this->pdo->prepare('DELETE FROM projects WHERE id = :project_id');
            $stmt->execute(['project_id' => $projectId]);
            if ($stmt->rowCount() !== 1) {
                throw new RuntimeException('Не удалось удалить локальный проект.');
            }

            $this->writeAudit(
                (int) $user['id'],
                'project_deleted_locally',
                'project',
                $projectId,
                $snapshot
            );
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }

        return [
            'project_id' => $projectId,
            'message' => 'Проект и его локальные данные удалены только из портала. Bitrix24 не изменялся.',
        ];
    }

    private function deleteSiteRows(int $projectId, int $siteId): void
    {
        $this->deleteBySite('project_source_links', $siteId);
        $this->deleteBySite('report_site_links', $siteId);
        $this->deleteBySite('project_source_snapshots', $siteId);
        $this->deleteBySite('notifications', $siteId);

        if ($this->tableExists('conversion_goals')
            && $this->columnExists('conversion_goals', 'site_id')) {
            $sets = ['site_id = NULL'];
            if ($this->columnExists('conversion_goals', 'scope_type')) {
                $sets[] = 'scope_type = "project"';
            }
            if ($this->columnExists('conversion_goals', 'updated_at')) {
                $sets[] = 'updated_at = NOW()';
            }
            $this->pdo->prepare(
                'UPDATE conversion_goals SET ' . implode(', ', $sets)
                . ' WHERE project_id = :project_id AND site_id = :site_id'
            )->execute([
                'project_id' => $projectId,
                'site_id' => $siteId,
            ]);
        }

        $stmt = $this->pdo->prepare(
            'DELETE FROM project_sites WHERE id = :site_id AND project_id = :project_id'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'project_id' => $projectId,
        ]);
        if ($stmt->rowCount() !== 1) {
            throw new RuntimeException('Не удалось удалить локальный сайт.');
        }
    }

    private function projectSnapshot(int $projectId): array
    {
        return [
            'project' => $this->project($projectId),
            'client_id' => $this->clientIdForProject($projectId),
            'sites' => $this->rowsByProject('project_sites', $projectId),
            'sources' => $this->rowsByProject('project_source_links', $projectId),
            'sales_records' => $this->rowsByProject('sales_records', $projectId),
            'sales_import_batches' => $this->rowsByProject('sales_import_batches', $projectId),
            'conversion_goals' => $this->rowsByProject('conversion_goals', $projectId),
            'bitrix_project_links' => $this->rowsByProject('bitrix24_project_links', $projectId),
            'bitrix_client_projects' => $this->rowsByProject('client_bitrix_projects', $projectId),
        ];
    }

    private function siteSnapshot(int $projectId, int $siteId): array
    {
        return [
            'site' => $this->site($projectId, $siteId),
            'sources' => $this->rowsBySite('project_source_links', $siteId),
            'report_links' => $this->rowsBySite('report_site_links', $siteId),
            'goals' => $this->rowsBySite('conversion_goals', $siteId),
        ];
    }

    private function project(int $projectId): array
    {
        $name = $this->projectNameExpression('p');
        $stmt = $this->pdo->prepare(
            'SELECT p.*, ' . $name . ' AS resolved_name
             FROM projects p WHERE p.id = :project_id LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Проект не найден.');
        }
        $row['id'] = (int) $row['id'];
        $row['name'] = (string) ($row['resolved_name'] ?? ('Проект #' . $projectId));
        unset($row['resolved_name']);
        return $row;
    }

    private function site(int $projectId, int $siteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM project_sites
             WHERE id = :site_id AND project_id = :project_id LIMIT 1'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'project_id' => $projectId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Сайт не найден.');
        }
        $row['id'] = (int) $row['id'];
        $row['project_id'] = (int) $row['project_id'];
        return $row;
    }

    private function client(int $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Клиент не найден.');
        }
        $row['id'] = (int) $row['id'];
        if (isset($row['bitrix_company_id']) && $row['bitrix_company_id'] !== null) {
            $row['bitrix_company_id'] = (int) $row['bitrix_company_id'];
        }
        return $row;
    }

    private function clientIdForProject(int $projectId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT client_id FROM project_client_links
             WHERE project_id = :project_id LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        $clientId = (int) $stmt->fetchColumn();
        if ($clientId <= 0) {
            throw new RuntimeException('У проекта отсутствует локальная привязка к клиенту.');
        }
        return $clientId;
    }

    private function requireAdministrator(): array
    {
        return $this->access->requireRoles(['administrator', 'moderator']);
    }

    private function rowsByProject(string $table, int $projectId): array
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, 'project_id')) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `' . str_replace('`', '', $table) . '`
             WHERE project_id = :project_id'
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rowsBySite(string $table, int $siteId): array
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, 'site_id')) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `' . str_replace('`', '', $table) . '`
             WHERE site_id = :site_id'
        );
        $stmt->execute(['site_id' => $siteId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function deleteByProject(string $table, int $projectId): void
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, 'project_id')) {
            return;
        }
        $this->pdo->prepare(
            'DELETE FROM `' . str_replace('`', '', $table) . '`
             WHERE project_id = :project_id'
        )->execute(['project_id' => $projectId]);
    }

    private function deleteBySite(string $table, int $siteId): void
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, 'site_id')) {
            return;
        }
        $this->pdo->prepare(
            'DELETE FROM `' . str_replace('`', '', $table) . '`
             WHERE site_id = :site_id'
        )->execute(['site_id' => $siteId]);
    }

    private function writeAudit(
        int $userId,
        string $action,
        string $entityType,
        int $entityId,
        array $snapshot
    ): void {
        $json = json_encode(
            $snapshot,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json)) {
            throw new RuntimeException('Не удалось создать аудит локальной операции.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO local_structure_deletions
             (user_id, action_key, entity_type, entity_id, snapshot_json, created_at)
             VALUES
             (:user_id, :action_key, :entity_type, :entity_id, :snapshot_json, NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'action_key' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'snapshot_json' => $json,
        ]);
    }

    private function projectNameExpression(string $alias): string
    {
        $parts = [];
        foreach (['name', 'title', 'domain', 'site_url'] as $column) {
            if ($this->columnExists('projects', $column)) {
                $parts[] = 'NULLIF(' . $alias . '.`' . $column . '`, "")';
            }
        }
        $parts[] = 'CONCAT("Проект #", ' . $alias . '.id)';
        return 'COALESCE(' . implode(', ', $parts) . ')';
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
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
}
