<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function cumulativeOut(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function cumulativeDownload(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Cumulative Updater/1.0',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = file_get_contents($url, false, $context);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Не удалось загрузить компонент обновления: ' . $url);
    }
    return $content;
}

function cumulativeRun(string $version, string $markerPath, string $marker): void
{
    if (is_file($markerPath)) {
        $current = file_get_contents($markerPath);
        if (is_string($current) && str_contains($current, $marker)) {
            cumulativeOut("Компонент {$version} уже установлен.");
            return;
        }
    }

    $url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/'
        . rawurlencode($version) . '.php';
    $installer = cumulativeDownload($url);
    $runtime = sys_get_temp_dir() . '/mirsaitov-update-' . $version . '-' . bin2hex(random_bytes(5)) . '.php';

    if (file_put_contents($runtime, $installer, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось подготовить временный установщик ' . $version);
    }
    @chmod($runtime, 0600);

    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($runtime) . ' 2>&1',
        $output,
        $code
    );
    @unlink($runtime);

    foreach ($output as $line) {
        cumulativeOut($line);
    }

    if ($code !== 0) {
        throw new RuntimeException('Компонент ' . $version . ' завершился с кодом ' . $code . '.');
    }
}

$root = getcwd() ?: '';
if (!is_file($root . '/index.php') || !is_file($root . '/assets/app.js')) {
    throw new RuntimeException('Не удалось определить корень проекта.');
}

cumulativeOut('Устанавливаем накопительное обновление интерфейса и мониторинга.');

$monitoringService = $root . '/app/Services/SiteMonitoringService.php';
if (is_file($monitoringService)) {
    cumulativeRun(
        '2026.08.01.8',
        $monitoringService,
        'MONITORING_ASYNC_QUEUE_HOTFIX'
    );
} else {
    cumulativeOut('Модуль мониторинга не найден — аварийный патч мониторинга пропущен.');
}

cumulativeRun(
    '2026.08.01.9',
    $root . '/assets/app.js',
    'GLOBAL_DATA_STATE_V1'
);

cumulativeOut('');
cumulativeOut('Накопительное обновление установлено.');
cumulativeOut('- мониторинг выполняет первичные аудиты в фоне;');
cumulativeOut('- параллельные monitoring worker заблокированы;');
cumulativeOut('- все разделы показывают 0 и «Загрузка данных» до получения ответа;');
cumulativeOut('- пустые ответы и ошибки имеют отдельные состояния.');
