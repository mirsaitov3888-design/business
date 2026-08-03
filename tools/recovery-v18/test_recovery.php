<?php
declare(strict_types=1);

$root = getenv('RECOVERY_FIXTURE_ROOT') ?: '';
if ($root === '' || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Recovery fixture root is missing.');
}
require $root . '/app/bootstrap.php';

use SeoAnalytics\Core\Database;
use SeoAnalytics\Services\PortalAccessService;
use SeoAnalytics\Services\PortalContextService;
use SeoAnalytics\Services\ProjectSourceService;

$pdo = Database::pdo();

$siteStmt = $pdo->prepare(
    'SELECT metrika_counter_ids_json, webmaster_host_ids_json
     FROM project_sites
     WHERE project_id = 101 AND host = :host
     LIMIT 1'
);
$siteStmt->execute(['host' => 'a-sub.example']);
$site = $siteStmt->fetch(PDO::FETCH_ASSOC);
if (!$site) {
    throw new RuntimeException('Recovery test site was not found.');
}
$metrika = json_decode((string) $site['metrika_counter_ids_json'], true);
$webmaster = json_decode((string) $site['webmaster_host_ids_json'], true);
if (!in_array(44444, array_map('intval', is_array($metrika) ? $metrika : []), true)) {
    throw new RuntimeException('Legacy Metrika counter was not restored.');
}
if (!in_array('https:a-sub.example:443', is_array($webmaster) ? $webmaster : [], true)) {
    throw new RuntimeException('Legacy Webmaster host was not restored.');
}

PortalAccessService::$testUserId = 1;
$context = new PortalContextService(new PortalAccessService());
$selected = $context->context(10, 101, true);
if ((int) ($selected['selected_project_id'] ?? 0) !== 101) {
    throw new RuntimeException('Project 101 was not selected.');
}
$legacy = (new ProjectSourceService())->selectedLegacyProject();
if ((int) ($legacy['counter_id'] ?? 0) !== 44444) {
    throw new RuntimeException('Selected legacy project still uses counter 0.');
}
if (!in_array(44444, array_map('intval', $legacy['metrika_counter_ids'] ?? []), true)) {
    throw new RuntimeException('Selected project has no aggregate counter list.');
}

$direct = (int) $pdo->query(
    'SELECT COUNT(*) FROM project_source_links
     WHERE project_id = 101
       AND site_id = 0
       AND source_type = "yandex_direct_account"
       AND status = "active"'
)->fetchColumn();
if ($direct !== 1) {
    throw new RuntimeException('Direct account was not linked to the only client project.');
}

$audit = (int) $pdo->query(
    'SELECT COUNT(*) FROM portal_recovery_audit
     WHERE release_version = "2026.08.03.18"'
)->fetchColumn();
if ($audit <= 0) {
    throw new RuntimeException('Recovery audit is empty.');
}

if (!is_file($root . '/storage/system-audits/portal-recovery-v18-latest.json')) {
    throw new RuntimeException('Recovery JSON report was not created.');
}

echo "Portal recovery assertions passed.\n";
