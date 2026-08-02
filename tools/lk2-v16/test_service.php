<?php
declare(strict_types=1);

$root = (string) getenv('LK2_FIXTURE_ROOT');
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('LK2 fixture root is missing.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Core\Database;
use SeoAnalytics\Services\ClientStructureDeniedException;
use SeoAnalytics\Services\ClientStructureService;
use SeoAnalytics\Services\PortalAccessService;

PortalAccessService::$testUserId = 1;
$admin = new ClientStructureService(new PortalAccessService());
$context = $admin->context();
if (($context['permissions']['create_client'] ?? false) !== true) {
    throw new RuntimeException('Administrator create permission failed.');
}
if (count($context['clients'] ?? []) < 2) {
    throw new RuntimeException('Administrator client list failed.');
}

$clientId = $admin->saveClient([
    'name' => 'Компания LK2',
    'status' => 'active',
    'manager_user_id' => 2,
    'contact_name' => 'Иван Клиентский',
    'contact_email' => 'client-lk2@example.test',
    'contact_phone' => '+7 900 000-00-00',
    'notes' => 'Тест общей структуры',
]);
if ($clientId <= 0) {
    throw new RuntimeException('Client creation failed.');
}
$admin->saveClientUsers($clientId, [3]);

$projectMain = $admin->saveProject([
    'client_id' => $clientId,
    'name' => 'Основной сайт',
    'description' => 'Главный маркетинговый проект',
    'status' => 'active',
    'sort_order' => 10,
]);
$projectHr = $admin->saveProject([
    'client_id' => $clientId,
    'name' => 'HR',
    'description' => 'Карьерный проект',
    'status' => 'active',
    'sort_order' => 20,
]);
if ($projectMain <= 0 || $projectHr <= 0 || $projectMain === $projectHr) {
    throw new RuntimeException('Project creation failed.');
}

$siteMain = $admin->saveSite([
    'client_id' => $clientId,
    'project_id' => $projectMain,
    'name' => 'Основной домен',
    'url' => 'https://company-lk2.example',
    'status' => 'active',
    'metrika_counter_ids' => ['111111', '222222'],
    'webmaster_host_ids' => ['https:company-lk2.example:443'],
    'sort_order' => 20,
    'notes' => 'Основной источник данных',
]);
$siteRegion = $admin->saveSite([
    'client_id' => $clientId,
    'project_id' => $projectMain,
    'name' => 'Региональный домен',
    'url' => 'https://irkutsk.company-lk2.example',
    'status' => 'active',
    'metrika_counter_ids' => '333333',
    'webmaster_host_ids' => 'https:irkutsk.company-lk2.example:443',
    'sort_order' => 10,
]);
$siteHr = $admin->saveSite([
    'client_id' => $clientId,
    'project_id' => $projectHr,
    'name' => 'Карьерный сайт',
    'url' => 'https://hr.company-lk2.example',
    'status' => 'active',
    'metrika_counter_ids' => '444444',
    'webmaster_host_ids' => 'https:hr.company-lk2.example:443',
]);
if (min($siteMain, $siteRegion, $siteHr) <= 0) {
    throw new RuntimeException('Site creation failed.');
}

$detail = $admin->client($clientId);
if (count($detail['projects']) !== 2) {
    throw new RuntimeException('Client projects count failed.');
}
$main = null;
foreach ($detail['projects'] as $project) {
    if ((int) $project['id'] === $projectMain) {
        $main = $project;
    }
}
if (!is_array($main) || count($main['sites']) !== 2) {
    throw new RuntimeException('Project sites count failed.');
}
if (($main['sites'][0]['metrika_counter_ids'] ?? []) !== ['333333']) {
    throw new RuntimeException('Site source normalization or initial ordering failed.');
}

$admin->reorderSites($clientId, $projectMain, [$siteMain, $siteRegion]);
$detail = $admin->client($clientId);
foreach ($detail['projects'] as $project) {
    if ((int) $project['id'] === $projectMain
        && (int) ($project['sites'][0]['id'] ?? 0) !== $siteMain) {
        throw new RuntimeException('Site ordering failed.');
    }
}

PortalAccessService::$testUserId = 2;
$manager = new ClientStructureService(new PortalAccessService());
$managerClients = $manager->clients();
$managerClientIds = array_map(static fn(array $row): int => (int) $row['id'], $managerClients);
if (!in_array($clientId, $managerClientIds, true)) {
    throw new RuntimeException('Assigned manager access failed.');
}
$manager->saveClient([
    'id' => $clientId,
    'name' => 'Компания LK2 обновлена',
    'contact_name' => 'Новый контакт',
    'contact_email' => 'new-contact@example.test',
    'contact_phone' => '+7 901 000-00-00',
    'notes' => 'Обновлено менеджером',
]);

PortalAccessService::$testUserId = 4;
$foreignManager = new ClientStructureService(new PortalAccessService());
try {
    $foreignManager->client($clientId);
    throw new RuntimeException('Foreign manager was allowed.');
} catch (ClientStructureDeniedException) {
}

PortalAccessService::$testUserId = 3;
$clientUser = new ClientStructureService(new PortalAccessService());
$clientView = $clientUser->client($clientId);
if ((int) ($clientView['client']['id'] ?? 0) !== $clientId) {
    throw new RuntimeException('Client user read access failed.');
}
try {
    $clientUser->saveClient([
        'id' => $clientId,
        'name' => 'Forbidden change',
    ]);
    throw new RuntimeException('Client user write access was allowed.');
} catch (ClientStructureDeniedException) {
}

PortalAccessService::$testUserId = 1;
$admin = new ClientStructureService(new PortalAccessService());
$admin->archiveSite($clientId, $projectMain, $siteRegion);
$archived = Database::pdo()->prepare(
    'SELECT status FROM project_sites WHERE id = :id AND project_id = :project_id'
);
$archived->execute(['id' => $siteRegion, 'project_id' => $projectMain]);
if ($archived->fetchColumn() !== 'archived') {
    throw new RuntimeException('Site archive failed.');
}

$changes = (int) Database::pdo()->query(
    'SELECT COUNT(*) FROM client_structure_changes'
)->fetchColumn();
if ($changes < 10) {
    throw new RuntimeException('Client structure audit failed.');
}

$links = (int) Database::pdo()->prepare(
    'SELECT COUNT(*) FROM project_client_links WHERE client_id = :client_id'
)->execute(['client_id' => $clientId]);

fwrite(STDOUT, "LK2 service integration passed.\n");
