<?php
declare(strict_types=1);

$root = getenv('LOCAL_MANAGEMENT_FIXTURE_ROOT') ?: '';
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Local management fixture root is missing.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Core\Database;
use SeoAnalytics\Services\Bitrix24SafetyPolicy;
use SeoAnalytics\Services\ClientStructureService;
use SeoAnalytics\Services\LocalStructureAdminService;
use SeoAnalytics\Services\PortalAccessService;

$pdo = Database::pdo();
PortalAccessService::$testUserId = 1;
$access = new PortalAccessService();
$clients = new ClientStructureService($access);
$service = new LocalStructureAdminService($access);

Bitrix24SafetyPolicy::assertAllowed('crm.company.update', ['id' => 1]);
foreach ([
    ['crm.company.delete', []],
    ['sonet_group.remove', []],
    ['batch', ['cmd' => ['a' => 'crm.item.delete?id=1']]],
] as [$method, $params]) {
    try {
        Bitrix24SafetyPolicy::assertAllowed($method, $params);
        throw new RuntimeException('Destructive Bitrix method was allowed: ' . $method);
    } catch (RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'запрещ')) {
            throw $exception;
        }
    }
}

$sourceClientId = (int) $pdo->query('SELECT id FROM clients ORDER BY id ASC LIMIT 1')->fetchColumn();
$targetClientId = $clients->saveClient([
    'name' => 'Клиент для локального переноса',
    'status' => 'active',
    'manager_user_id' => 1,
    'contact_name' => 'Тест',
    'contact_email' => 'move@example.test',
]);
$moveProjectId = $clients->saveProject([
    'client_id' => $sourceClientId,
    'name' => 'Локально переносимый проект',
    'description' => 'Bitrix24 не менять',
    'status' => 'active',
]);

$pdo->prepare(
    'INSERT INTO bitrix24_project_links
     (project_id, bitrix_group_id, bitrix_group_name,
      bitrix_company_id, bitrix_company_name, report_tag,
      created_at, updated_at)
     VALUES
     (:project_id, 99001, "Внешний проект", 501, "Внешняя компания",
      "client_report", NOW(), NOW())
     ON DUPLICATE KEY UPDATE updated_at = NOW()'
)->execute(['project_id' => $moveProjectId]);

$moved = $service->moveProject([
    'project_id' => $moveProjectId,
    'target_client_id' => $targetClientId,
]);
if (!($moved['changed'] ?? false)) {
    throw new RuntimeException('Local project was not moved.');
}
$linkedClient = (int) $pdo->query(
    'SELECT client_id FROM project_client_links WHERE project_id = ' . $moveProjectId
)->fetchColumn();
if ($linkedClient !== $targetClientId) {
    throw new RuntimeException('Project client link was not updated locally.');
}
$externalGroup = (int) $pdo->query(
    'SELECT bitrix_group_id FROM bitrix24_project_links WHERE project_id = ' . $moveProjectId
)->fetchColumn();
if ($externalGroup !== 99001) {
    throw new RuntimeException('External Bitrix project identifier was lost.');
}

$siteId = $clients->saveSite([
    'client_id' => $targetClientId,
    'project_id' => $moveProjectId,
    'name' => 'Удаляемый локальный сайт',
    'url' => 'https://delete-local.example',
    'status' => 'active',
    'metrika_counter_ids' => [111],
    'webmaster_host_ids' => ['https:delete-local.example:443'],
]);
$pdo->prepare(
    'INSERT INTO project_source_links
     (project_id, site_id, source_type, external_id,
      settings_json, status, created_at, updated_at)
     VALUES
     (:project_id, :site_id, "yandex_metrika", "111",
      JSON_OBJECT(), "active", NOW(), NOW())'
)->execute([
    'project_id' => $moveProjectId,
    'site_id' => $siteId,
]);

$service->deleteSite([
    'project_id' => $moveProjectId,
    'site_id' => $siteId,
    'confirmation' => 'Удаляемый локальный сайт',
]);
if ((int) $pdo->query('SELECT COUNT(*) FROM project_sites WHERE id = ' . $siteId)->fetchColumn() !== 0) {
    throw new RuntimeException('Local site was not deleted.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM project_source_links WHERE site_id = ' . $siteId)->fetchColumn() !== 0) {
    throw new RuntimeException('Local site sources were not deleted.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM bitrix24_project_links WHERE project_id = ' . $moveProjectId)->fetchColumn() !== 1) {
    throw new RuntimeException('Site deletion changed Bitrix project link.');
}

$deleteProjectId = $clients->saveProject([
    'client_id' => $targetClientId,
    'name' => 'Удаляемый локальный проект',
    'description' => 'Удаляется только из портала',
    'status' => 'active',
]);
$deleteSiteId = $clients->saveSite([
    'client_id' => $targetClientId,
    'project_id' => $deleteProjectId,
    'name' => 'Сайт удаляемого проекта',
    'url' => 'https://project-delete.example',
    'status' => 'active',
]);
$pdo->prepare(
    'INSERT INTO bitrix24_project_links
     (project_id, bitrix_group_id, bitrix_group_name,
      bitrix_company_id, bitrix_company_name, report_tag,
      created_at, updated_at)
     VALUES
     (:project_id, 99002, "Внешний удалять нельзя", 501,
      "Компания Bitrix", "client_report", NOW(), NOW())'
)->execute(['project_id' => $deleteProjectId]);

$service->deleteProject([
    'project_id' => $deleteProjectId,
    'confirmation' => 'Удаляемый локальный проект',
]);
if ((int) $pdo->query('SELECT COUNT(*) FROM projects WHERE id = ' . $deleteProjectId)->fetchColumn() !== 0) {
    throw new RuntimeException('Local project was not deleted.');
}
if ((int) $pdo->query('SELECT COUNT(*) FROM project_sites WHERE id = ' . $deleteSiteId)->fetchColumn() !== 0) {
    throw new RuntimeException('Sites of deleted local project remain.');
}

$auditActions = $pdo->query(
    'SELECT action_key FROM local_structure_deletions ORDER BY id ASC'
)->fetchAll(PDO::FETCH_COLUMN);
foreach (['project_client_changed', 'site_deleted_locally', 'project_deleted_locally'] as $action) {
    if (!in_array($action, $auditActions, true)) {
        throw new RuntimeException('Missing local audit action: ' . $action);
    }
}

echo "Local-only structure management tests passed.\n";
