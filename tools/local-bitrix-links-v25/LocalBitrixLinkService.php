<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class LocalBitrixLinkService
{
    private PDO $pdo;

    public function __construct(
        private readonly PortalAccessService $access = new PortalAccessService(),
        private readonly ClientStructureService $structure = new ClientStructureService()
    ) {
        $this->pdo = Database::pdo();
    }

    public function context(): array
    {
        $this->requireAdministrator();

        $companyLinks = $this->pdo->query(
            'SELECT c.id AS client_id, c.name AS client_name, c.status,
                    c.bitrix_company_id, c.bitrix_company_name,
                    c.bitrix_synced_at,
                    (SELECT COUNT(*) FROM project_client_links pcl
                     WHERE pcl.client_id = c.id) AS projects_count,
                    (SELECT COUNT(*) FROM project_sites ps
                     INNER JOIN project_client_links pcl2
                        ON pcl2.project_id = ps.project_id
                     WHERE pcl2.client_id = c.id) AS sites_count
             FROM clients c
             WHERE c.bitrix_company_id IS NOT NULL
             ORDER BY c.name ASC, c.id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);

        foreach ($companyLinks as &$row) {
            $row['client_id'] = (int) $row['client_id'];
            $row['bitrix_company_id'] = (int) $row['bitrix_company_id'];
            $row['projects_count'] = (int) $row['projects_count'];
            $row['sites_count'] = (int) $row['sites_count'];
        }
        unset($row);

        $projectLinks = [];
        if ($this->tableExists('bitrix24_project_links')) {
            $projectLinks = $this->pdo->query(
                'SELECT bpl.id AS link_id, bpl.project_id,
                        bpl.bitrix_group_id, bpl.bitrix_group_name,
                        bpl.bitrix_company_id, bpl.bitrix_company_name,
                        p.name AS project_name, p.status AS project_status,
                        pcl.client_id, c.name AS client_name,
                        c.status AS client_status,
                        CASE WHEN p.id IS NULL THEN 1 ELSE 0 END AS orphan_project
                 FROM bitrix24_project_links bpl
                 LEFT JOIN projects p ON p.id = bpl.project_id
                 LEFT JOIN project_client_links pcl ON pcl.project_id = bpl.project_id
                 LEFT JOIN clients c ON c.id = pcl.client_id
                 ORDER BY COALESCE(c.name, ""),
                          COALESCE(p.name, bpl.bitrix_group_name), bpl.id'
            )->fetchAll(PDO::FETCH_ASSOC);
        }

        foreach ($projectLinks as &$row) {
            foreach ([
                'link_id', 'project_id', 'bitrix_group_id',
                'bitrix_company_id', 'client_id', 'orphan_project',
            ] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        unset($row);

        $clients = $this->pdo->query(
            'SELECT id, name, status
             FROM clients
             WHERE status <> "archived"
             ORDER BY name ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($clients as &$row) {
            $row['id'] = (int) $row['id'];
        }
        unset($row);

        return [
            'company_links' => $companyLinks,
            'project_links' => $projectLinks,
            'clients' => $clients,
            'storage' => [
                'company' => 'clients.bitrix_company_id',
                'contacts' => 'client_bitrix_contacts',
                'project_selection' => 'client_bitrix_projects',
                'project_link' => 'bitrix24_project_links',
            ],
            'policy' => [
                'bitrix_delete_allowed' => false,
                'local_detach_only' => true,
            ],
        ];
    }

    public function createLocalProject(array $data): array
    {
        $this->requireAdministrator();
        $clientId = max(0, (int) ($data['client_id'] ?? 0));
        $name = trim((string) ($data['name'] ?? ''));
        $description = trim((string) ($data['description'] ?? ''));
        $status = trim((string) ($data['status'] ?? 'active'));

        if ($clientId <= 0) {
            throw new RuntimeException('Клиент не выбран.');
        }
        if ($name === '') {
            throw new RuntimeException('Укажите название проекта.');
        }
        if (!in_array($status, ['active', 'paused'], true)) {
            $status = 'active';
        }
        $client = $this->client($clientId);
        if ((string) ($client['status'] ?? '') === 'archived') {
            throw new RuntimeException('Нельзя добавить проект архивному клиенту.');
        }

        $projectId = $this->structure->saveProject([
            'id' => 0,
            'client_id' => $clientId,
            'name' => $name,
            'description' => $description,
            'status' => $status,
        ]);

        return [
            'project_id' => $projectId,
            'client_id' => $clientId,
            'message' => 'Проект создан только в портале. Bitrix24 не изменялся.',
        ];
    }

    public function detachClient(array $data): array
    {
        $user = $this->requireAdministrator();
        $clientId = max(0, (int) ($data['client_id'] ?? 0));
        $confirmation = trim((string) ($data['confirmation'] ?? ''));
        $client = $this->client($clientId);
        $name = (string) $client['name'];
        if ($confirmation !== $name) {
            throw new RuntimeException('Для отвязки введите точное название клиента.');
        }

        $projectIds = $this->projectIdsForClient($clientId);
        $snapshot = [
            'client' => $client,
            'contacts' => $this->rows('client_bitrix_contacts', 'client_id', $clientId),
            'client_projects' => $this->rows('client_bitrix_projects', 'client_id', $clientId),
            'project_links' => $this->rowsByProjectIds('bitrix24_project_links', $projectIds),
        ];

        $this->pdo->beginTransaction();
        try {
            if ($this->tableExists('client_bitrix_contacts')) {
                $this->pdo->prepare(
                    'DELETE FROM client_bitrix_contacts WHERE client_id = :client_id'
                )->execute(['client_id' => $clientId]);
            }
            if ($this->tableExists('client_bitrix_projects')) {
                $this->pdo->prepare(
                    'DELETE FROM client_bitrix_projects WHERE client_id = :client_id'
                )->execute(['client_id' => $clientId]);
            }
            if ($projectIds !== [] && $this->tableExists('bitrix24_project_links')) {
                $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
                $this->pdo->prepare(
                    'DELETE FROM bitrix24_project_links WHERE project_id IN ('
                    . $placeholders . ')'
                )->execute($projectIds);
            }

            $sets = [
                'bitrix_company_id = NULL',
                'bitrix_company_name = NULL',
                'bitrix_company_snapshot_json = NULL',
                'bitrix_synced_at = NULL',
            ];
            if ($this->columnExists('clients', 'updated_at')) {
                $sets[] = 'updated_at = NOW()';
            }
            $this->pdo->prepare(
                'UPDATE clients SET ' . implode(', ', $sets) . ' WHERE id = :client_id'
            )->execute(['client_id' => $clientId]);

            $this->writeAudit(
                (int) $user['id'],
                'bitrix_client_links_detached_locally',
                'client',
                $clientId,
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
            'client_id' => $clientId,
            'message' => 'Связи клиента с Bitrix24 удалены только из портала. Bitrix24 не изменялся.',
        ];
    }

    public function detachProject(array $data): array
    {
        $user = $this->requireAdministrator();
        $projectId = max(0, (int) ($data['project_id'] ?? 0));
        $confirmation = trim((string) ($data['confirmation'] ?? ''));
        $project = $this->project($projectId);
        $name = (string) $project['name'];
        if ($confirmation !== $name) {
            throw new RuntimeException('Для отвязки введите точное название проекта.');
        }

        $snapshot = [
            'project' => $project,
            'client_projects' => $this->rows('client_bitrix_projects', 'project_id', $projectId),
            'project_links' => $this->rows('bitrix24_project_links', 'project_id', $projectId),
        ];

        $this->pdo->beginTransaction();
        try {
            foreach (['client_bitrix_projects', 'bitrix24_project_links'] as $table) {
                if ($this->tableExists($table) && $this->columnExists($table, 'project_id')) {
                    $this->pdo->prepare(
                        'DELETE FROM `' . $table . '` WHERE project_id = :project_id'
                    )->execute(['project_id' => $projectId]);
                }
            }
            $this->writeAudit(
                (int) $user['id'],
                'bitrix_project_links_detached_locally',
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
            'message' => 'Связь проекта с Bitrix24 удалена только из портала. Проект в Bitrix24 сохранён.',
        ];
    }

    private function client(int $clientId): array
    {
        if ($clientId <= 0) {
            throw new RuntimeException('Клиент не выбран.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Клиент не найден.');
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    private function project(int $projectId): array
    {
        if ($projectId <= 0) {
            throw new RuntimeException('Проект не выбран.');
        }
        $stmt = $this->pdo->prepare('SELECT * FROM projects WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Проект не найден.');
        }
        $row['id'] = (int) $row['id'];
        $row['name'] = trim((string) ($row['name'] ?? $row['title'] ?? ('Проект #' . $projectId)));
        return $row;
    }

    private function projectIdsForClient(int $clientId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT project_id FROM project_client_links WHERE client_id = :client_id'
        );
        $stmt->execute(['client_id' => $clientId]);
        return array_values(array_unique(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN))));
    }

    private function rows(string $table, string $column, int $value): array
    {
        if (!$this->tableExists($table) || !$this->columnExists($table, $column)) {
            return [];
        }
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `' . $table . '` WHERE `' . $column . '` = :value'
        );
        $stmt->execute(['value' => $value]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function rowsByProjectIds(string $table, array $projectIds): array
    {
        if ($projectIds === [] || !$this->tableExists($table)
            || !$this->columnExists($table, 'project_id')) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `' . $table . '` WHERE project_id IN (' . $placeholders . ')'
        );
        $stmt->execute($projectIds);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function writeAudit(
        int $userId,
        string $action,
        string $entityType,
        int $entityId,
        array $snapshot
    ): void {
        if (!$this->tableExists('local_structure_deletions')) {
            throw new RuntimeException('Таблица локального аудита не установлена.');
        }
        $json = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Не удалось сохранить аудит локальной операции.');
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO local_structure_deletions
             (action_key, entity_type, entity_id, snapshot_json,
              created_by, created_at)
             VALUES (:action_key, :entity_type, :entity_id, :snapshot_json,
                     :created_by, NOW())'
        );
        $stmt->execute([
            'action_key' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'snapshot_json' => $json,
            'created_by' => $userId,
        ]);
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

    private function requireAdministrator(): array
    {
        return $this->access->requireRoles(['administrator', 'moderator']);
    }
}
