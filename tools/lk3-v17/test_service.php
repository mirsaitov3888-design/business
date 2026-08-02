<?php
declare(strict_types=1);

$root = getenv('LK3_FIXTURE_ROOT') ?: '';
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('LK3 fixture root is missing.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Core\Database;
use SeoAnalytics\Repositories\ProjectRepository;
use SeoAnalytics\Services\PortalAccessService;
use SeoAnalytics\Services\PortalContextDeniedException;
use SeoAnalytics\Services\PortalContextService;
use SeoAnalytics\Services\ProjectSourceService;

PortalAccessService::$testUserId = 1;
$contextService = new PortalContextService(new PortalAccessService());
$selected = $contextService->context(10, 102, true);
if ((int) $selected['selected_project_id'] !== 102) {
    throw new RuntimeException('Project 102 was not selected.');
}

$project = (new ProjectRepository())->firstActive();
if ((int) ($project['id'] ?? 0) !== 102) {
    throw new RuntimeException('Legacy repository did not use selected project.');
}
if (empty($project['__portal_context'])) {
    throw new RuntimeException('Legacy selected project has no context marker.');
}
if (!is_array($project['project_sites'] ?? null)) {
    throw new RuntimeException('Legacy selected project has no sites.');
}

$service = new ProjectSourceService(
    new PortalAccessService(),
    new PortalContextService(new PortalAccessService())
);
$context = $service->context(false);
if ((int) $context['selected_project_id'] !== 102) {
    throw new RuntimeException('Source service lost selected project.');
}
$sites = $context['sites'] ?? [];
if (count($sites) < 1) {
    throw new RuntimeException('Selected project sites are missing.');
}
$siteId = (int) $sites[0]['id'];

$sourceId = $service->saveSource([
    'project_id' => 102,
    'site_id' => $siteId,
    'source_type' => 'direct_campaign',
    'external_id' => 'campaign-102',
    'settings' => ['label' => 'Project 102 campaign'],
]);
if ($sourceId <= 0) {
    throw new RuntimeException('Project source was not saved.');
}

$service->saveReportScope(7001, 102, [$siteId]);
$service->saveGoalScope(8001, 102, $siteId);

$reportLinks = (int) Database::pdo()->query(
    'SELECT COUNT(*) FROM report_site_links WHERE report_id = 7001'
)->fetchColumn();
if ($reportLinks !== 1) {
    throw new RuntimeException('Report site scope was not saved.');
}
$goal = Database::pdo()->query(
    'SELECT site_id, scope_type FROM conversion_goals WHERE id = 8001'
)->fetch(PDO::FETCH_ASSOC);
if ((int) ($goal['site_id'] ?? 0) !== $siteId || ($goal['scope_type'] ?? '') !== 'site') {
    throw new RuntimeException('Goal site scope was not saved.');
}

PortalAccessService::$testUserId = 2;
$managerContext = (new PortalContextService(new PortalAccessService()))
    ->context(10, 102, true);
if ((int) $managerContext['selected_project_id'] !== 102) {
    throw new RuntimeException('Manager cannot use assigned project.');
}
try {
    (new ProjectSourceService())->saveSource([
        'project_id' => 201,
        'site_id' => 0,
        'source_type' => 'direct_campaign',
        'external_id' => 'foreign-campaign',
    ]);
    throw new RuntimeException('Manager saved a source for foreign project.');
} catch (PortalContextDeniedException) {
}

PortalAccessService::$testUserId = 3;
try {
    (new ProjectSourceService())->saveSource([
        'project_id' => 101,
        'site_id' => 0,
        'source_type' => 'manual',
        'external_id' => 'client-write',
    ]);
    throw new RuntimeException('Client was allowed to change sources.');
} catch (RuntimeException) {
}

PortalAccessService::$testUserId = 999;
$fallback = (new ProjectRepository())->firstActive();
if ((int) ($fallback['id'] ?? 0) !== 101) {
    throw new RuntimeException('System fallback of firstActive was broken.');
}

echo "LK3 project source tests passed.\n";
