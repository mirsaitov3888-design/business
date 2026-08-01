<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function b24u4download(string $url): string
{
    $context = stream_context_create([
        'http' => ['timeout' => 90, 'follow_location' => 1, 'user_agent' => 'Mirsaitov Bitrix24 Update/4.0'],
        'https' => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    $content = file_get_contents($url, false, $context);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Не удалось загрузить компонент: ' . $url);
    }
    return $content;
}

function b24u4write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать ' . $temporary);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить ' . $path);
    }
}

function b24u4lint(string $path): void
{
    if (!function_exists('exec')) return;
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("Ошибка PHP-синтаксиса в {$path}:\n" . implode("\n", $output));
    }
}

$root = getcwd() ?: '';
$clientPath = $root . '/app/Services/Bitrix24Client.php';
$syncPath = $root . '/app/Services/Bitrix24SyncService.php';
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';

foreach ([$clientPath, $syncPath, $indexPath, $jsPath, $cssPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$backupDirectory = $root . '/storage/backups/bitrix24-flexible-selection-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$files = [
    $clientPath => 'Bitrix24Client.php',
    $syncPath => 'Bitrix24SyncService.php',
    $indexPath => 'index.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
];
foreach ($files as $source => $name) {
    if (!copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException('Не удалось сохранить копию ' . $name);
    }
}

$base = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/tools/bitrix24-step4/';

try {
    $client = b24u4download($base . 'Bitrix24Client.php');
    $sync = b24u4download($base . 'Bitrix24SyncService.php');
    $index = (string) file_get_contents($indexPath);
    $js = (string) file_get_contents($jsPath);
    $css = (string) file_get_contents($cssPath);

    $index = str_replace(
        'Проекты, компании, задачи с тегом для клиентского отчёта и фактические трудозатраты.',
        'Проекты, компании, задачи за выбранный период и фактические трудозатраты. Тег используется только как дополнительный фильтр.',
        $index
    );
    $index = str_replace(
        '<span>Тег задач для клиентского отчёта</span>',
        '<span>Тег задач для клиентского отчёта (необязательно)</span>',
        $index
    );
    $index = str_replace(
        'В выборку попадают задачи связанного проекта с указанным тегом. Время считается по отдельным записям трудозатрат, дата которых входит в выбранный период.',
        'Сначала загружаются все доступные задачи связанного проекта за выбранный период. Если тег найден, список сужается до задач с этим тегом. Если тег не найден, задачи периода остаются в отчёте.',
        $index
    );
    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=22', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=22', $index) ?? $index;

    $js = str_replace("['Задач с тегом',", "['Задач в отчёте',", $js);
    $js = str_replace(
        'Задачи с выбранным тегом пока не синхронизированы или не найдены.',
        'Задачи за выбранный период пока не синхронизированы или не найдены.',
        $js
    );

    if (!str_contains($css, 'BITRIX24_FLEXIBLE_SELECTION_V4')) {
        $css .= <<<'CSS'

/* BITRIX24_FLEXIBLE_SELECTION_V4 */
#bitrix24Message.alert-warning,
#supportReportMessage.alert-warning {
    display: block !important;
    padding: 14px 16px !important;
    border: 1px solid #f1d38a !important;
    border-radius: 12px !important;
    background: #fffbeb !important;
    color: #854d0e !important;
    line-height: 1.55 !important;
}
CSS;
    }

    b24u4write($clientPath, $client);
    b24u4write($syncPath, $sync);
    b24u4write($indexPath, $index);
    b24u4write($jsPath, $js);
    b24u4write($cssPath, $css);

    b24u4lint($clientPath);
    b24u4lint($syncPath);
    b24u4lint($indexPath);

    echo "Гибкая выборка задач Битрикс24 установлена.\n";
    echo "- сначала загружаются все доступные задачи выбранного проекта;\n";
    echo "- тег используется только для сужения списка;\n";
    echo "- если тег не найден, задачи выбранного периода остаются в отчёте;\n";
    echo "- завершённые задачи не исключаются;\n";
    echo "- привязка к компании не влияет на выборку задач.\n";
    echo "Резервная копия: {$backupDirectory}\n";
} catch (Throwable $exception) {
    foreach ($files as $destination => $name) {
        @copy($backupDirectory . '/' . $name, $destination);
    }
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
