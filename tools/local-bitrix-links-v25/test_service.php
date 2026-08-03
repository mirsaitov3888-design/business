<?php
declare(strict_types=1);

$root = getenv('LOCAL_LINKS_FIXTURE_ROOT') ?: '';
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Local links fixture root is missing.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Core\Database;
use SeoAnalytics\Services\ClientStructureService;
use SeoAnalytics\Services\LocalBitrixLinkService;
use SeoAnalytics\Services\PortalAccessService;

$pdo = Database::pdo();
PortalAccessService::$testUserId = 1;
$access = new PortalAccessService();
$structure = new ClientStructureService($access);
$service = new LocalBitrixLinkService($access, $structure);

$clientId = $structure->saveClient([
    'name' => 'ПроКрепёж — локальный конфликт',
    'status' => 'active',
    'manager_user_id' => 1,
    'contact_name' => 'Тестовый контакт',
]);
$pdo->prepare(
    'UPDATE clients SET bitrix_company_id = 88001,
                        bitrix_company_name = "ПроКрепёж",
                        bitrix_synced_at = NOW()
     WHERE id = :id'
)->execute(['id' => $clientId]);

$projectId = $structure->saveProject([
    'client_id' => $clientId,
    'name' => 'ПроКрепёж',
    'description' => 'Локальный проект с внешней связью',
    'status' => 'active',
]);
$siteId = $structure->saveSite([
    'client_id' => $clientId,
    'project_id' => $projectId,
    'name' => 'Сайт ПроКрепёж',
    'url' => 'https://prokrepezh-test.example',
    'status' => 'active',
]);

$pdo->prepare(
    'INSERT INTO client_bitrix_contacts
     (client_id, bitrix_contact_id, is_primary, active,
      name, synced_at, created_at, updated_at)
     VALUES (:client_id, 88101, 1, 1, "Контакт", NOW(), NOW(), NOW())'
)->execute(['client_id' => $clientId]);
$pdo->prepare(
    'INSERT INTO client_bitrix_projects
     (client_id, project_id, bitrix_group_id, bitrix_group_name,
      active, synced_at, created_at, updated_at)
     VALUES (:client_id, :project_id, 88201, "ПроКрепёж",
             1, NOW(), NOW(), NOW())'
)->execute(['client_id' => $clientId, 'project_id' => $projectId]);
$pdo->prepare(
    'INSERT INTO bitrix24_project_links
     (project_id, bitrix_group_id, bitrix_group_name,
      bitrix_company_id, bitrix_company_name, report_tag,
      created_at, updated_at)
     VALUES (:project_id, 88201, "ПроКрепёж", 88001,
             "ПроКрепёж", "client_report", NOW(), NOW())'
)->execute(['project_id' => $projectId]);

$context = $service->context();
$companyRows = array_values(array_filter(
    $context['company_links'],
    static fn(array $row): bool => (int) $row['client_id'] === $clientId
));
$projectRows = array_values(array_filter(
    $context['project_links'],
    static fn(array $row): bool => (int) $row['project_id'] === $projectId
));
if (count($companyRows) !== 1 || count($projectRows) !== 1) {
    throw new RuntimeException('Local Bitrix links are not visible in context.');
}

$service->detachClient([
    'client_id' => $clientId,
    'confirmation' => 'ПроКрепёж — локальный конфликт',
]);
if ((int) $pdo->query(
    'SELECT COUNT(*) FROM clients WHERE id = ' . $clientId
    . ' AND bitrix_company_id IS NOT NULL'
)->fetchColumn() !== 0) {
    throw new RuntimeException('Bitrix company link was not released locally.');
}
foreach (['client_bitrix_contacts', 'client_bitrix_projects'] as $table) {
    if ((int) $pdo->query(
        'SELECT COUNT(*) FROM ' . $table . ' WHERE client_id = ' . $clientId
    )->fetchColumn() !== 0) {
        throw new RuntimeException('Local mapping remains in ' . $table);
    }
}
if ((int) $pdo->query(
    'SELECT COUNT(*) FROM bitrix24_project_links WHERE project_id = ' . $projectId
)->fetchColumn() !== 0) {
    throw new RuntimeException('Project Bitrix link was not released with client.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM projects WHERE id = ' . $projectId)->fetchColumn() !== 1
    || (int) $pdo->query('SELECT COUNT(*) FROM project_sites WHERE id = ' . $siteId)->fetchColumn() !== 1) {
    throw new RuntimeException('Local project or site was deleted during detach.');
}

$newProject = $service->createLocalProject([
    'client_id' => $clientId,
    'name' => 'Проект из рабочей формы',
    'description' => 'Создан только в портале',
    'status' => 'active',
]);
$newProjectId = (int) ($newProject['project_id'] ?? 0);
if ($newProjectId <= 0) {
    throw new RuntimeException('Local project form service did not create a project.');
}
if ((int) $pdo->query(
    'SELECT COUNT(*) FROM bitrix24_project_links WHERE project_id = ' . $newProjectId
)->fetchColumn() !== 0) {
    throw new RuntimeException('Local project creation unexpectedly linked Bitrix24.');
}

$pdo->prepare(
    'INSERT INTO client_bitrix_projects
     (client_id, project_id, bitrix_group_id, bitrix_group_name,
      active, synced_at, created_at, updated_at)
     VALUES (:client_id, :project_id, 88301, "Отдельная связь",
             1, NOW(), NOW(), NOW())'
)->execute(['client_id' => $clientId, 'project_id' => $newProjectId]);
$pdo->prepare(
    'INSERT INTO bitrix24_project_links
     (project_id, bitrix_group_id, bitrix_group_name,
      bitrix_company_id, bitrix_company_name, report_tag,
      created_at, updated_at)
     VALUES (:project_id, 88301, "Отдельная связь", 88001,
             "ПроКрепёж", "client_report", NOW(), NOW())'
)->execute(['project_id' => $newProjectId]);
$service->detachProject([
    'project_id' => $newProjectId,
    'confirmation' => 'Проект из рабочей формы',
]);
if ((int) $pdo->query(
    'SELECT COUNT(*) FROM client_bitrix_projects WHERE project_id = ' . $newProjectId
)->fetchColumn() !== 0
    || (int) $pdo->query(
        'SELECT COUNT(*) FROM bitrix24_project_links WHERE project_id = ' . $newProjectId
    )->fetchColumn() !== 0) {
    throw new RuntimeException('Project mapping was not detached locally.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM projects WHERE id = ' . $newProjectId)->fetchColumn() !== 1) {
    throw new RuntimeException('Local project was deleted during mapping detach.');
}

$audit = $pdo->query(
    'SELECT action_key FROM local_structure_deletions ORDER BY id DESC LIMIT 10'
)->fetchAll(PDO::FETCH_COLUMN);
foreach ([
    'bitrix_client_links_detached_locally',
    'bitrix_project_links_detached_locally',
] as $action) {
    if (!in_array($action, $audit, true)) {
        throw new RuntimeException('Missing local link audit: ' . $action);
    }
}

echo "Local Bitrix link repair tests passed.\n";
