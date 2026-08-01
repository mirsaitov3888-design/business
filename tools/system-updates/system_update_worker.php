<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Run via PHP CLI.');
}

$root = realpath(dirname(__DIR__));

if (!is_string($root) || !is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Project root not found.\n");
    exit(1);
}

require $root . '/app/bootstrap.php';

try {
    (new \SeoAnalytics\Services\SystemUpdateWorker())->run();
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        '[' . date('Y-m-d H:i:s') . '] '
        . $exception->getMessage()
        . PHP_EOL
    );
    exit(1);
}
