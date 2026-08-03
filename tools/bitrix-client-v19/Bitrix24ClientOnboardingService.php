<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;
use SeoAnalytics\Repositories\Bitrix24Repository;

final class Bitrix24ClientOnboardingService
{
    private PDO $pdo;

    public function __construct(
        private readonly Bitrix24DirectoryService $directory = new Bitrix24DirectoryService(),
        private readonly ClientStructureService $clients = new ClientStructureService(),
        private readonly PortalAccessService $access = new PortalAccessService(),
        private readonly Bitrix24Repository $bitrix = new Bitrix24Repository()
    ) {
        $this->pdo = Database::pdo();
    }

    public function catalog(
        ?int $companyId = null,
        ?int $clientId = null
    ): array {
        $user = $this->access->requireRoles([
            'administrator',
            'moderator',
            'manager',
        ]);

        $mapping = $clientId !== null && $clientId > 0
            ? $this->mapping($clientId, $user)
            : $this->emptyMapping();

        if (($companyId ?? 0) <= 0 && ($mapping['company_id'] ?? 0) > 0) {
            $companyId = (int) $mapping['company_id'];
        }

        return [
            'directory' => $this->directory->catalog($companyId),
            'local_context' => $this->clients->context(),
            'mapping' => $mapping,
        ];
    }

    public function save(array $data): array
    {
        $user = $this->access->currentUser();
        $role = (string) ($user['role'] ?? 'client');
        $clientId = max(0, (int) ($data['client_id'] ?? 0));
        $companyId = max(0, (int) ($data['company_id'] ?? 0));

        if ($companyId <= 0) {
            throw new RuntimeException('Выберите компанию из Bitrix24.');
        }
        if (
            $clientId <= 0
            && !in_array($role, ['administrator', 'moderator'], true)
        ) {
            throw new RuntimeException(
                'Создавать нового клиента может администратор или модератор.'
            );
        }

        $company = $this->directory->company($companyId);
        $contactIds = is_array($data['contact_ids'] ?? null)
            ? $data['contact_ids']
            : [];
        $projectIds = is_array($data['project_ids'] ?? null)
            ? $data['project_ids']
            : [];
        $contacts = $this->directory->selectedContacts(
            $companyId,
            $contactIds
        );
        $projects = $this->directory->selectedProjects($projectIds);
        $primaryContactId = max(0, (int) ($data['primary_contact_id'] ?? 0));
        $primaryContact = null;

        foreach ($contacts as $contact) {
            if ((int) $contact['id'] === $primaryContactId) {
                $primaryContact = $contact;
                break;
            }
        }
        if ($primaryContactId > 0 && $primaryContact === null) {
            throw new RuntimeException(
                'Основной контакт должен быть выбран в списке контактов компании.'
            );
        }
        $primaryContact ??= $contacts[0] ?? null;

        $existingCompanyClientId = $this->clientIdByCompany($companyId);
        if ($clientId <= 0 && $existingCompanyClientId > 0) {
            $clientId = $existingCompanyClientId;
        } elseif (
            $clientId > 0
            && $existingCompanyClientId > 0
            && $existingCompanyClientId !== $clientId
        ) {
            throw new RuntimeException(
                'Эта компания Bitrix24 уже связана с другим клиентом портала.'
            );
        }

        $status = trim((string) ($data['status'] ?? 'active'));
        if (!in_array($status, ['active', 'paused', 'archived'], true)) {
            $status = 'active';
        }
        $managerId = max(0, (int) ($data['manager_user_id'] ?? 0));
        $contactName = trim((string) ($primaryContact['name'] ?? ''));
        $email = trim((string) (
            $primaryContact['email']
            ?? $company['email']
            ?? ''
        ));
        $phone = trim((string) (
            $primaryContact['phone']
            ?? $company['phone']
            ?? ''
        ));

        $this->pdo->beginTransaction();
        try {
            $clientId = $this->clients->saveClient([
                'id' => $clientId,
                'name' => (string) $company['title'],
                'status' => $status,
                'manager_user_id' => $managerId,
                'contact_name' => $contactName,
                'contact_email' => $email,
                'contact_phone' => $phone,
                'notes' => (string) ($data['notes'] ?? ''),
            ]);

            $this->saveCompanySnapshot($clientId, $company);
            $this->replaceContacts(
                $clientId,
                $contacts,
                (int) ($primaryContact['id'] ?? 0)
            );
            $localProjectIds = $this->saveProjects(
                $clientId,
                $company,
                $projects,
                (string) ($data['report_tag'] ?? '')
            );

            $this->pdo->commit();

            return [
                'client_id' => $clientId,
                'company_id' => $companyId,
                'contact_ids' => array_values(array_map(
                    static fn(array $contact): int => (int) $contact['id'],
                    $contacts
                )),
                'project_ids' => $localProjectIds,
                'bitrix_group_ids' => array_values(array_map(
                    static fn(array $project): int => (int) $project['id'],
                    $projects
                )),
                'client' => $this->clients->client($clientId),
            ];
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function mapping(int $clientId, ?array $user = null): array
    {
        $user ??= $this->access->currentUser();
        $client = $this->clients->client($clientId);
        $clientRow = $client['client'] ?? [];
        $companyId = (int) ($clientRow['bitrix_company_id'] ?? 0);

        $contactStmt = $this->pdo->prepare(
            'SELECT bitrix_contact_id, is_primary
             FROM client_bitrix_contacts
             WHERE client_id = :client_id AND active = 1
             ORDER BY is_primary DESC, id ASC'
        );
        $contactStmt->execute(['client_id' => $clientId]);
        $contactRows = $contactStmt->fetchAll(PDO::FETCH_ASSOC);

        $projectStmt = $this->pdo->prepare(
            'SELECT bitrix_group_id, project_id
             FROM client_bitrix_projects
             WHERE client_id = :client_id AND active = 1
             ORDER BY bitrix_group_name ASC, id ASC'
        );
        $projectStmt->execute(['client_id' => $clientId]);
        $projectRows = $projectStmt->fetchAll(PDO::FETCH_ASSOC);

        return [
            'client_id' => $clientId,
            'company_id' => $companyId,
            'company_name' => (string) (
                $clientRow['bitrix_company_name']
                ?? $clientRow['name']
                ?? ''
            ),
            'contact_ids' => array_values(array_map(
                static fn(array $row): int => (int) $row['bitrix_contact_id'],
                $contactRows
            )),
            'primary_contact_id' => (int) (
                $contactRows[0]['is_primary'] ?? 0
            ) === 1
                ? (int) $contactRows[0]['bitrix_contact_id']
                : 0,
            'bitrix_group_ids' => array_values(array_map(
                static fn(array $row): int => (int) $row['bitrix_group_id'],
                $projectRows
            )),
            'local_project_ids' => array_values(array_map(
                static fn(array $row): int => (int) $row['project_id'],
                $projectRows
            )),
            'status' => (string) ($clientRow['status'] ?? 'active'),
            'manager_user_id' => (int) ($clientRow['manager_user_id'] ?? 0),
            'notes' => (string) ($clientRow['notes'] ?? ''),
            'synced_at' => $clientRow['bitrix_synced_at'] ?? null,
        ];
    }

    private function saveCompanySnapshot(int $clientId, array $company): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE clients
             SET bitrix_company_id = :company_id,
                 bitrix_company_name = :company_name,
                 bitrix_company_snapshot_json = :snapshot,
                 bitrix_synced_at = NOW(),
                 updated_at = NOW()
             WHERE id = :client_id'
        );
        $stmt->execute([
            'client_id' => $clientId,
            'company_id' => (int) $company['id'],
            'company_name' => mb_substr((string) $company['title'], 0, 255),
            'snapshot' => $this->json($company['raw'] ?? $company),
        ]);
    }

    private function replaceContacts(
        int $clientId,
        array $contacts,
        int $primaryContactId
    ): void {
        $this->pdo->prepare(
            'UPDATE client_bitrix_contacts
             SET active = 0, is_primary = 0, updated_at = NOW()
             WHERE client_id = :client_id'
        )->execute(['client_id' => $clientId]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO client_bitrix_contacts
             (client_id, bitrix_contact_id, is_primary, active,
              name, phone, email, raw_json, synced_at, created_at, updated_at)
             VALUES
             (:client_id, :contact_id, :is_primary, 1,
              :name, :phone, :email, :raw_json, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                is_primary = VALUES(is_primary),
                active = 1,
                name = VALUES(name),
                phone = VALUES(phone),
                email = VALUES(email),
                raw_json = VALUES(raw_json),
                synced_at = NOW(),
                updated_at = NOW()'
        );
        foreach ($contacts as $contact) {
            $contactId = (int) $contact['id'];
            $stmt->execute([
                'client_id' => $clientId,
                'contact_id' => $contactId,
                'is_primary' => $contactId === $primaryContactId ? 1 : 0,
                'name' => mb_substr((string) $contact['name'], 0, 255),
                'phone' => $this->nullable($contact['phone'] ?? null, 100),
                'email' => $this->nullable($contact['email'] ?? null, 255),
                'raw_json' => $this->json($contact['raw'] ?? $contact),
            ]);
        }
    }

    private function saveProjects(
        int $clientId,
        array $company,
        array $projects,
        string $reportTag
    ): array {
        $this->pdo->prepare(
            'UPDATE client_bitrix_projects
             SET active = 0, updated_at = NOW()
             WHERE client_id = :client_id'
        )->execute(['client_id' => $clientId]);

        $selectionStmt = $this->pdo->prepare(
            'INSERT INTO client_bitrix_projects
             (client_id, project_id, bitrix_group_id, bitrix_group_name,
              active, synced_at, created_at, updated_at)
             VALUES
             (:client_id, :project_id, :group_id, :group_name,
              1, NOW(), NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                project_id = VALUES(project_id),
                bitrix_group_name = VALUES(bitrix_group_name),
                active = 1,
                synced_at = NOW(),
                updated_at = NOW()'
        );

        $result = [];
        foreach ($projects as $project) {
            $groupId = (int) $project['id'];
            $localProjectId = $this->localProjectIdByGroup($groupId);

            if ($localProjectId > 0) {
                $linkedClientId = $this->linkedClientId($localProjectId);
                if ($linkedClientId > 0 && $linkedClientId !== $clientId) {
                    throw new RuntimeException(
                        'Проект Bitrix24 «' . (string) $project['name']
                        . '» уже связан с другим клиентом портала.'
                    );
                }
                $this->ensureProjectClientLink($localProjectId, $clientId);
                $this->clients->saveProject([
                    'id' => $localProjectId,
                    'client_id' => $clientId,
                    'name' => (string) $project['name'],
                    'description' => (string) ($project['description'] ?? ''),
                    'status' => 'active',
                ]);
            } else {
                $localProjectId = $this->clients->saveProject([
                    'client_id' => $clientId,
                    'name' => (string) $project['name'],
                    'description' => (string) ($project['description'] ?? ''),
                    'status' => 'active',
                ]);
            }

            $this->bitrix->saveLink([
                'project_id' => $localProjectId,
                'bitrix_group_id' => $groupId,
                'bitrix_group_name' => (string) $project['name'],
                'bitrix_company_id' => (int) $company['id'],
                'bitrix_company_name' => (string) $company['title'],
                'report_tag' => trim($reportTag) !== ''
                    ? trim($reportTag)
                    : 'client_report',
            ]);

            $selectionStmt->execute([
                'client_id' => $clientId,
                'project_id' => $localProjectId,
                'group_id' => $groupId,
                'group_name' => mb_substr((string) $project['name'], 0, 255),
            ]);
            $result[] = $localProjectId;
        }
        return array_values(array_unique(array_map('intval', $result)));
    }

    private function clientIdByCompany(int $companyId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM clients
             WHERE bitrix_company_id = :company_id
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute(['company_id' => $companyId]);
        return (int) $stmt->fetchColumn();
    }

    private function localProjectIdByGroup(int $groupId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT project_id FROM bitrix24_project_links
             WHERE bitrix_group_id = :group_id
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute(['group_id' => $groupId]);
        return (int) $stmt->fetchColumn();
    }

    private function linkedClientId(int $projectId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT client_id FROM project_client_links
             WHERE project_id = :project_id LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        return (int) $stmt->fetchColumn();
    }

    private function ensureProjectClientLink(int $projectId, int $clientId): void
    {
        $this->pdo->prepare(
            'INSERT INTO project_client_links
             (project_id, client_id, created_at)
             VALUES (:project_id, :client_id, NOW())
             ON DUPLICATE KEY UPDATE client_id = VALUES(client_id)'
        )->execute([
            'project_id' => $projectId,
            'client_id' => $clientId,
        ]);
    }

    private function emptyMapping(): array
    {
        return [
            'client_id' => 0,
            'company_id' => 0,
            'company_name' => '',
            'contact_ids' => [],
            'primary_contact_id' => 0,
            'bitrix_group_ids' => [],
            'local_project_ids' => [],
            'status' => 'active',
            'manager_user_id' => 0,
            'notes' => '',
            'synced_at' => null,
        ];
    }

    private function nullable(mixed $value, int $limit): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $limit);
    }

    private function json(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        if (!is_string($json)) {
            throw new RuntimeException('Не удалось сохранить снимок Bitrix24.');
        }
        return $json;
    }
}
