<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите worker через PHP CLI.\n");
}

$root = realpath(dirname(__DIR__));
if (!is_string($root) || !is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Не удалось определить корень проекта.\n");
    exit(1);
}

require $root . '/app/bootstrap.php';

try {
    $service = new \SeoAnalytics\Services\SiteMonitoringService();
    $result = $service->runDueChecks();
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
} catch (Throwable $exception) {
    try {
        (new \SeoAnalytics\Repositories\MonitoringRepository())->setWorkerState(
            'heartbeat',
            [
                'status' => 'error',
                'finished_at' => date(DATE_ATOM),
                'error' => $exception->getMessage(),
            ]
        );
    } catch (Throwable) {
    }
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
