<?php
declare(strict_types=1);

$root = getenv('BITRIX_CLIENT_FIXTURE_ROOT') ?: '';
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Bitrix client fixture root is missing.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Core\Database;
use SeoAnalytics\Repositories\Bitrix24Repository;
use SeoAnalytics\Services\Bitrix24ClientOnboardingService;
use SeoAnalytics\Services\Bitrix24DirectoryService;
use SeoAnalytics\Services\ClientStructureService;
use SeoAnalytics\Services\PortalAccessService;

$caller = static function (string $method, array $params): array {
    if ($method === 'crm.company.list') {
        return [
            'result' => [
                [
                    'ID' => '501',
                    'TITLE' => 'Мир сайтов',
                    'PHONE' => [['VALUE' => '+7 3952 000-001']],
                    'EMAIL' => [['VALUE' => 'office@mirsaitov.test']],
                ],
                [
                    'ID' => '502',
                    'TITLE' => 'Про Крепёж',
                    'PHONE' => [['VALUE' => '+7 3952 000-002']],
                    'EMAIL' => [['VALUE' => 'info@prokrepezh.test']],
                ],
            ],
            'total' => 2,
        ];
    }
    if ($method === 'crm.contact.list') {
        $companyId = (int) ($params['filter']['COMPANY_ID'] ?? 0);
        if ($companyId === 501) {
            return [
                'result' => [
                    [
                        'ID' => '601',
                        'COMPANY_ID' => '501',
                        'NAME' => 'Максим',
                        'LAST_NAME' => 'Золин',
                        'PHONE' => [['VALUE' => '+7 900 000-00-01']],
                        'EMAIL' => [['VALUE' => 'maxim@mirsaitov.test']],
                    ],
                    [
                        'ID' => '602',
                        'COMPANY_ID' => '501',
                        'NAME' => 'Анна',
                        'LAST_NAME' => 'Иванова',
                        'PHONE' => [['VALUE' => '+7 900 000-00-02']],
                        'EMAIL' => [['VALUE' => 'anna@mirsaitov.test']],
                    ],
                ],
                'total' => 2,
            ];
        }
        return ['result' => [], 'total' => 0];
    }
    if ($method === 'sonet_group.get') {
        return [
            'result' => [
                [
                    'ID' => '701',
                    'NAME' => 'Мир сайтов — основной сайт',
                    'DESCRIPTION' => 'Основной сайт агентства',
                ],
                [
                    'ID' => '702',
                    'NAME' => 'HR Мир сайтов',
                    'DESCRIPTION' => 'HR-направление агентства',
                ],
                [
                    'ID' => '703',
                    'NAME' => 'Чужой проект',
                    'DESCRIPTION' => 'Проект другого клиента',
                ],
            ],
            'total' => 3,
        ];
    }
    throw new RuntimeException('Unexpected Bitrix method: ' . $method);
};

$access = new PortalAccessService();
$directory = new Bitrix24DirectoryService(null, $caller(...));
$clientStructure = new ClientStructureService($access);
$service = new Bitrix24ClientOnboardingService(
    $directory,
    $clientStructure,
    $access,
    new Bitrix24Repository()
);
$pdo = Database::pdo();

PortalAccessService::$testUserId = 1;
$catalog = $service->catalog(501, null);
$recommended = array_map(
    'intval',
    $catalog['directory']['recommended_project_ids'] ?? []
);
if (!in_array(701, $recommended, true) || !in_array(702, $recommended, true)) {
    throw new RuntimeException('Bitrix project recommendations are incomplete.');
}

$created = $service->save([
    'company_id' => 501,
    'contact_ids' => [601, 602],
    'primary_contact_id' => 602,
    'project_ids' => [701, 702],
    'manager_user_id' => 2,
    'status' => 'active',
    'notes' => 'Создано из Bitrix24',
]);
$clientId = (int) ($created['client_id'] ?? 0);
if ($clientId <= 0) {
    throw new RuntimeException('Bitrix client was not created.');
}

$clientRow = $pdo->query(
    'SELECT * FROM clients WHERE id = ' . $clientId
)->fetch(PDO::FETCH_ASSOC);
if ((int) ($clientRow['bitrix_company_id'] ?? 0) !== 501) {
    throw new RuntimeException('Bitrix company ID was not saved.');
}
if (($clientRow['contact_email'] ?? '') !== 'anna@mirsaitov.test') {
    throw new RuntimeException('Primary Bitrix contact was not applied.');
}
if ((int) ($clientRow['manager_user_id'] ?? 0) !== 2) {
    throw new RuntimeException('Local responsible manager was not saved.');
}

$contactCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM client_bitrix_contacts '
    . 'WHERE client_id = ' . $clientId . ' AND active = 1'
)->fetchColumn();
$primaryCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM client_bitrix_contacts '
    . 'WHERE client_id = ' . $clientId
    . ' AND active = 1 AND is_primary = 1 '
    . 'AND bitrix_contact_id = 602'
)->fetchColumn();
if ($contactCount !== 2 || $primaryCount !== 1) {
    throw new RuntimeException('Bitrix contacts were not synchronized.');
}

$selectionCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM client_bitrix_projects '
    . 'WHERE client_id = ' . $clientId . ' AND active = 1'
)->fetchColumn();
$linkCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM bitrix24_project_links '
    . 'WHERE bitrix_company_id = 501'
)->fetchColumn();
if ($selectionCount !== 2 || $linkCount !== 2) {
    throw new RuntimeException('Multiple Bitrix projects were not linked.');
}

$projectId = (int) $pdo->query(
    'SELECT project_id FROM client_bitrix_projects '
    . 'WHERE client_id = ' . $clientId
    . ' AND bitrix_group_id = 701 LIMIT 1'
)->fetchColumn();
if ($projectId <= 0) {
    throw new RuntimeException('Local project for Bitrix group 701 is missing.');
}

$pdo->prepare(
    'INSERT INTO project_sites
     (project_id, name, url, host, status,
      metrika_counter_ids_json, webmaster_host_ids_json,
      source_type, source_id, sort_order, notes, created_at, updated_at)
     VALUES
     (:project_id, "Сохраняемый сайт", "https://kept.example",
      "kept.example", "active", JSON_ARRAY(98765),
      JSON_ARRAY("https:kept.example:443"), "manual", NULL,
      10, "Не удалять", NOW(), NOW())'
)->execute(['project_id' => $projectId]);

$updated = $service->save([
    'client_id' => $clientId,
    'company_id' => 501,
    'contact_ids' => [601],
    'primary_contact_id' => 601,
    'project_ids' => [701],
    'manager_user_id' => 2,
    'status' => 'active',
    'notes' => 'Повторная синхронизация',
]);
if ((int) ($updated['client_id'] ?? 0) !== $clientId) {
    throw new RuntimeException('Client ID changed during repeat synchronization.');
}

$keptSite = (int) $pdo->query(
    'SELECT COUNT(*) FROM project_sites '
    . 'WHERE project_id = ' . $projectId
    . ' AND host = "kept.example" '
    . 'AND metrika_counter_ids_json = JSON_ARRAY(98765)'
)->fetchColumn();
$inactiveProject = (int) $pdo->query(
    'SELECT COUNT(*) FROM client_bitrix_projects '
    . 'WHERE client_id = ' . $clientId
    . ' AND bitrix_group_id = 702 AND active = 0'
)->fetchColumn();
if ($keptSite !== 1 || $inactiveProject !== 1) {
    throw new RuntimeException('Local sites or inactive project state were lost.');
}

$repeat = $service->save([
    'company_id' => 501,
    'contact_ids' => [601],
    'primary_contact_id' => 601,
    'project_ids' => [701],
    'manager_user_id' => 2,
    'status' => 'active',
]);
if ((int) ($repeat['client_id'] ?? 0) !== $clientId) {
    throw new RuntimeException('Company synchronization created a duplicate client.');
}
$duplicateClients = (int) $pdo->query(
    'SELECT COUNT(*) FROM clients WHERE bitrix_company_id = 501'
)->fetchColumn();
if ($duplicateClients !== 1) {
    throw new RuntimeException('Duplicate Bitrix clients were created.');
}

$pdo->exec(
    'INSERT INTO project_client_links (project_id, client_id, created_at)
     VALUES (201, 20, NOW())
     ON DUPLICATE KEY UPDATE client_id = 20'
);
(new Bitrix24Repository())->saveLink([
    'project_id' => 201,
    'bitrix_group_id' => 703,
    'bitrix_group_name' => 'Чужой проект',
    'bitrix_company_id' => 502,
    'bitrix_company_name' => 'Про Крепёж',
    'report_tag' => 'client_report',
]);

try {
    $service->save([
        'client_id' => $clientId,
        'company_id' => 501,
        'contact_ids' => [601],
        'primary_contact_id' => 601,
        'project_ids' => [701, 703],
        'manager_user_id' => 2,
        'status' => 'active',
    ]);
    throw new RuntimeException('Foreign Bitrix project was linked to the client.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'другим клиентом')) {
        throw $exception;
    }
}

PortalAccessService::$testUserId = 2;
try {
    $service->save([
        'company_id' => 502,
        'contact_ids' => [],
        'project_ids' => [703],
        'manager_user_id' => 2,
        'status' => 'active',
    ]);
    throw new RuntimeException('Manager created an unassigned client.');
} catch (RuntimeException $exception) {
    if (!str_contains($exception->getMessage(), 'администратор')) {
        throw $exception;
    }
}

PortalAccessService::$testUserId = 1;
$mapping = $service->mapping($clientId);
if ((int) $mapping['company_id'] !== 501) {
    throw new RuntimeException('Saved Bitrix mapping cannot be loaded.');
}
if (array_map('intval', $mapping['bitrix_group_ids']) !== [701]) {
    throw new RuntimeException('Active Bitrix project selection is incorrect.');
}

echo "Bitrix-first client onboarding tests passed.\n";
