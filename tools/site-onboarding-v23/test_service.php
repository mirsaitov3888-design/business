<?php
declare(strict_types=1);

$root = getenv('SITE_ONBOARDING_FIXTURE_ROOT') ?: '';
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Site onboarding fixture root is missing.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Core\Database;
use SeoAnalytics\Services\ClientStructureService;
use SeoAnalytics\Services\PortalAccessService;
use SeoAnalytics\Services\SiteOnboardingService;

$pdo = Database::pdo();
PortalAccessService::$testUserId = 1;

$clientId = (int) $pdo->query(
    'SELECT id FROM clients WHERE bitrix_company_id = 501 ORDER BY id ASC LIMIT 1'
)->fetchColumn();
$projectId = (int) $pdo->query(
    'SELECT project_id FROM client_bitrix_projects
     WHERE client_id = ' . $clientId . ' AND bitrix_group_id = 701 LIMIT 1'
)->fetchColumn();
if ($clientId <= 0 || $projectId <= 0) {
    throw new RuntimeException('Bitrix client fixture is incomplete.');
}

$companyWeb = [
    ['ID' => '9001', 'VALUE' => 'https://existing.example', 'VALUE_TYPE' => 'WORK'],
];
$updates = [];
$caller = static function (string $method, array $params) use (&$companyWeb, &$updates): array {
    if ($method === 'crm.company.get') {
        return [
            'result' => [
                'ID' => '501',
                'TITLE' => 'Мир сайтов',
                'WEB' => $companyWeb,
            ],
        ];
    }
    if ($method === 'crm.company.update') {
        $updates[] = $params;
        $companyWeb = array_values($params['fields']['WEB'] ?? []);
        return ['result' => true];
    }
    throw new RuntimeException('Unexpected Bitrix method: ' . $method);
};

$access = new PortalAccessService();
$clients = new ClientStructureService($access);
$service = new SiteOnboardingService($clients, $access, null, $caller(...));

$context = $service->context($clientId, $projectId, 0);
if (($context['bitrix']['websites'] ?? []) !== ['https://existing.example']) {
    throw new RuntimeException('Bitrix company websites were not loaded.');
}

$created = $service->save([
    'client_id' => $clientId,
    'project_id' => $projectId,
    'name' => 'Новый сайт из портала',
    'url' => 'new-site.example',
    'status' => 'active',
    'sort_order' => 40,
    'metrika_counter_ids' => ['12345678', '87654321'],
    'webmaster_host_ids' => ['https:new-site.example:443'],
    'direct_enabled' => true,
    'direct_client_login' => 'client-login',
    'direct_campaign_ids' => ['1001', '1002'],
    'sync_to_bitrix' => true,
    'notes' => 'Единая настройка',
]);
$siteId = (int) ($created['site_id'] ?? 0);
if ($siteId <= 0) {
    throw new RuntimeException('Site was not created.');
}
if (($created['bitrix']['status'] ?? '') !== 'synced'
    || !($created['bitrix']['added'] ?? false)) {
    throw new RuntimeException('New site URL was not appended to Bitrix24.');
}
if (count($updates) !== 1) {
    throw new RuntimeException('Bitrix company update was not called exactly once.');
}
$updatedUrls = array_map(
    static fn(array $row): string => (string) ($row['VALUE'] ?? ''),
    $updates[0]['fields']['WEB'] ?? []
);
if (!in_array('https://existing.example', $updatedUrls, true)
    || !in_array('https://new-site.example', $updatedUrls, true)) {
    throw new RuntimeException('Bitrix WEB values were replaced instead of appended.');
}

$site = $pdo->query(
    'SELECT * FROM project_sites WHERE id = ' . $siteId
)->fetch(PDO::FETCH_ASSOC);
if (!is_array($site)
    || json_decode((string) $site['metrika_counter_ids_json'], true) !== [12345678, 87654321]
    || json_decode((string) $site['webmaster_host_ids_json'], true) !== ['https:new-site.example:443']) {
    throw new RuntimeException('Site tool identifiers were not saved.');
}

$activeSources = $pdo->query(
    'SELECT source_type, external_id, settings_json
     FROM project_source_links
     WHERE project_id = ' . $projectId . '
       AND site_id = ' . $siteId . '
       AND status <> "archived"
     ORDER BY source_type, external_id'
)->fetchAll(PDO::FETCH_ASSOC);
$sourceTypes = array_count_values(array_column($activeSources, 'source_type'));
if (($sourceTypes['yandex_metrika'] ?? 0) !== 2
    || ($sourceTypes['yandex_webmaster'] ?? 0) !== 1
    || ($sourceTypes['yandex_direct'] ?? 0) !== 1
    || ($sourceTypes['bitrix24_company_web'] ?? 0) !== 1) {
    throw new RuntimeException('Unified source links are incomplete.');
}
$directRow = null;
foreach ($activeSources as $row) {
    if ($row['source_type'] === 'yandex_direct') {
        $directRow = $row;
        break;
    }
}
$directSettings = json_decode((string) ($directRow['settings_json'] ?? ''), true);
if (($directRow['external_id'] ?? '') !== 'client-login'
    || ($directSettings['campaign_ids'] ?? []) !== [1001, 1002]) {
    throw new RuntimeException('Direct client or campaigns were not saved.');
}

$repeat = $service->save([
    'site_id' => $siteId,
    'client_id' => $clientId,
    'project_id' => $projectId,
    'name' => 'Новый сайт из портала',
    'url' => 'https://new-site.example',
    'status' => 'active',
    'metrika_counter_ids' => [12345678],
    'webmaster_host_ids' => ['https:new-site.example:443'],
    'direct_enabled' => false,
    'sync_to_bitrix' => true,
]);
if ((int) ($repeat['site_id'] ?? 0) !== $siteId || count($updates) !== 1) {
    throw new RuntimeException('Repeat save duplicated site or Bitrix website.');
}
$activeDirect = (int) $pdo->query(
    'SELECT COUNT(*) FROM project_source_links
     WHERE project_id = ' . $projectId . '
       AND site_id = ' . $siteId . '
       AND source_type = "yandex_direct"
       AND status <> "archived"'
)->fetchColumn();
if ($activeDirect !== 0) {
    throw new RuntimeException('Disabled Direct source remained active.');
}

$existing = $service->save([
    'client_id' => $clientId,
    'project_id' => $projectId,
    'name' => 'Существующий сайт Bitrix24',
    'url' => 'https://existing.example/',
    'status' => 'active',
    'sync_to_bitrix' => true,
]);
if (($existing['bitrix']['added'] ?? true) !== false || count($updates) !== 1) {
    throw new RuntimeException('Existing Bitrix URL was added again.');
}

$failureCaller = static function (string $method, array $params): array {
    if ($method === 'crm.company.get') {
        return ['result' => ['ID' => '501', 'TITLE' => 'Мир сайтов', 'WEB' => []]];
    }
    if ($method === 'crm.company.update') {
        throw new RuntimeException('Недостаточно прав на изменение компании.');
    }
    throw new RuntimeException('Unexpected Bitrix method.');
};
$failureService = new SiteOnboardingService(
    new ClientStructureService($access),
    $access,
    null,
    $failureCaller(...)
);
$failure = $failureService->save([
    'client_id' => $clientId,
    'project_id' => $projectId,
    'name' => 'Локальный сайт при ошибке Bitrix24',
    'url' => 'https://local-only.example',
    'status' => 'active',
    'metrika_counter_ids' => [333],
    'sync_to_bitrix' => true,
]);
if (($failure['bitrix']['status'] ?? '') !== 'error'
    || (int) ($failure['site_id'] ?? 0) <= 0) {
    throw new RuntimeException('Bitrix failure removed the locally saved site.');
}
$failureSiteCount = (int) $pdo->query(
    'SELECT COUNT(*) FROM project_sites
     WHERE project_id = ' . $projectId . '
       AND host = "local-only.example"'
)->fetchColumn();
if ($failureSiteCount !== 1) {
    throw new RuntimeException('Local site was not preserved after Bitrix failure.');
}

echo "Unified site onboarding tests passed.\n";
