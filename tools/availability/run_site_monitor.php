<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите через PHP CLI.');
}

$root = realpath(dirname(__DIR__));

if (!is_string($root) || !is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Не найден корень проекта.\n");
    exit(1);
}

$lockPath = $root . '/storage/site-monitor.lock';
$lock = fopen($lockPath, 'c+');

if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "Проверка уже выполняется.\n");
    exit(0);
}

require $root . '/app/bootstrap.php';

$pdo = \SeoAnalytics\Core\Database::pdo();
$projects = $pdo->query(
    'SELECT * FROM projects WHERE active = 1 ORDER BY id ASC'
)->fetchAll();
$service = new \SeoAnalytics\Services\SiteMonitorService();
$failed = 0;

foreach ($projects as $project) {
    try {
        $result = $service->checkProject($project);
        fwrite(
            STDOUT,
            sprintf(
                "%s: %s, HTTP %s, %s мс\n",
                $project['name'],
                $result['status'],
                $result['http_code'] ?? '—',
                $result['response_ms'] ?? '—'
            )
        );
    } catch (Throwable $exception) {
        $failed++;
        fwrite(
            STDERR,
            $project['name'] . ': ' . $exception->getMessage() . PHP_EOL
        );
    }
}

flock($lock, LOCK_UN);
fclose($lock);

exit($failed > 0 ? 1 : 0);
