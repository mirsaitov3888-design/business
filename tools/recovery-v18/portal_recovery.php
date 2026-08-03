<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$root = dirname(__DIR__, 2);
require $root . '/app/bootstrap.php';

$report = (new \SeoAnalytics\Services\PortalRecoveryService(null, $root))->run();
$summary = $report['summary'] ?? [];

fwrite(STDOUT, "Восстановление структуры портала завершено.\n");
fwrite(STDOUT, '- сайтов проверено: ' . (int) ($summary['sites_scanned'] ?? 0) . ";\n");
fwrite(STDOUT, '- сайтов обновлено: ' . (int) ($summary['sites_updated'] ?? 0) . ";\n");
fwrite(STDOUT, '- ID Метрики восстановлено: ' . (int) ($summary['metrika_ids_restored'] ?? 0) . ";\n");
fwrite(STDOUT, '- ID Вебмастера восстановлено: ' . (int) ($summary['webmaster_ids_restored'] ?? 0) . ";\n");
fwrite(STDOUT, '- неоднозначных сайтов: ' . (int) ($summary['ambiguous_sites'] ?? 0) . ";\n");
fwrite(STDOUT, '- Директ привязан к проекту: ' . (!empty($summary['direct_project_linked']) ? 'да' : 'нет') . ".\n");
