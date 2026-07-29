<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

$sourceUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/'
    . '6d8734d2f40f0b094e8a9a68983f677c4b9b7320/'
    . 'tools/install_reports_step7.php';

$context = stream_context_create([
    'http' => [
        'timeout' => 60,
        'follow_location' => 1,
        'user_agent' => 'Mirsaitov Reports Installer/7.1',
    ],
    'https' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$source = file_get_contents($sourceUrl, false, $context);

if (!is_string($source) || $source === '') {
    fwrite(STDERR, "Не удалось загрузить основной установщик.\n");
    exit(1);
}

$needle = <<<'PHPBLOCK'
    $jsFragment = step7Download($baseUrl . 'reports_step7.js');
PHPBLOCK;

$replacement = <<<'PHPBLOCK'
    $jsFragment = step7Download($baseUrl . 'reports_step7.js');
    $jsFragment .= "\n    reportsStep7RenderTrafficSegments([]);\n";
PHPBLOCK;

$count = 0;
$source = str_replace(
    $needle,
    $replacement,
    $source,
    $count
);

if ($count !== 1) {
    fwrite(
        STDERR,
        "Не удалось подготовить исправленный установщик.\n"
    );
    exit(1);
}

$runtimePath = __DIR__ . '/install_reports_step7_runtime.php';

if (file_put_contents($runtimePath, $source, LOCK_EX) === false) {
    fwrite(STDERR, "Не удалось записать временный установщик.\n");
    exit(1);
}

register_shutdown_function(
    static function () use ($runtimePath): void {
        if (is_file($runtimePath)) {
            @unlink($runtimePath);
        }
    }
);

require $runtimePath;
