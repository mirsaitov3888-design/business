<?php
declare(strict_types=1);

$root = getenv('LK_FIXTURE_ROOT') ?: '';
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('LK_FIXTURE_ROOT is not configured.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Services\PortalAccessService;
use SeoAnalytics\Services\PortalContextDeniedException;
use SeoAnalytics\Services\PortalContextService;

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

PortalAccessService::$testUserId = 1;
$admin = new PortalContextService(new PortalAccessService());
$adminContext = $admin->context(10, 101, true);
assertTrue(count($adminContext['clients']) === 2, 'Admin clients failed');
assertTrue(count($adminContext['all_projects']) === 4, 'Admin projects failed');
assertTrue($adminContext['selected_project_id'] === 101, 'Admin selection failed');
assertTrue(count($adminContext['sites']) === 2, 'Project sites failed');
assertTrue($adminContext['ui']['show_client_selector'], 'Admin selector failed');

PortalAccessService::$testUserId = 2;
$manager = new PortalContextService(new PortalAccessService());
$managerContext = $manager->context(10, 102, true);
assertTrue(count($managerContext['clients']) === 1, 'Manager clients failed');
assertTrue(count($managerContext['all_projects']) === 2, 'Manager projects failed');
assertTrue($managerContext['selected_project_id'] === 102, 'Manager selection failed');
try {
    $manager->requireProject(201);
    throw new RuntimeException('Manager foreign project was allowed');
} catch (PortalContextDeniedException) {
}

PortalAccessService::$testUserId = 3;
$client = new PortalContextService(new PortalAccessService());
$clientContext = $client->context(10, 101, true);
assertTrue(count($clientContext['clients']) === 1, 'Client account failed');
assertTrue(count($clientContext['all_projects']) === 2, 'Client projects failed');
assertTrue(!$clientContext['ui']['show_client_selector'], 'Client selector must be hidden');
assertTrue($clientContext['ui']['show_project_selector'], 'Client project selector missing');
try {
    $client->requireProject(201);
    throw new RuntimeException('Client foreign project was allowed');
} catch (PortalContextDeniedException) {
}

PortalAccessService::$testUserId = 4;
$managerB = new PortalContextService(new PortalAccessService());
$managerBContext = $managerB->context(20, 201, true);
assertTrue(count($managerBContext['all_projects']) === 1, 'Manager B scope failed');
assertTrue($managerBContext['selected_project_id'] === 201, 'Manager B selection failed');

$stored = (int) SeoAnalytics\Core\Database::pdo()
    ->query('SELECT COUNT(*) FROM user_portal_context')
    ->fetchColumn();
assertTrue($stored === 4, 'Stored contexts failed');

echo "LK context role tests passed.\n";
