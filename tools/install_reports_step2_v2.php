<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

$sourceUrl = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/e64f827c25e32bc1c7fb73b1771b18fb2f6658bc/tools/install_reports_step2.php';
$context = stream_context_create([
    'http' => [
        'timeout' => 30,
        'follow_location' => 1,
        'user_agent' => 'Mirsaitov Reports Installer/2.0',
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

$needle = '$reportsJs = <<\'JS\'';
$replacement = '$reportsJs = <<<\'JS\'';
$count = 0;
$source = str_replace($needle, $replacement, $source, $count);

if ($count !== 1) {
    fwrite(STDERR, "Не удалось применить синтаксическое исправление: найдено замен {$count}.\n");
    exit(1);
}

$runtimePath = __DIR__ . '/install_reports_step2_runtime.php';

if (file_put_contents($runtimePath, $source, LOCK_EX) === false) {
    fwrite(STDERR, "Не удалось записать исправленный установщик.\n");
    exit(1);
}

register_shutdown_function(static function () use ($runtimePath): void {
    if (is_file($runtimePath)) {
        @unlink($runtimePath);
    }
});

require $runtimePath;
