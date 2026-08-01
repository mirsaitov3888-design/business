<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function b24s4Out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function b24s4Root(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/assets/app.js')
        || !is_file($root . '/assets/app.css')
        || !is_file($root . '/app/Services/Bitrix24Client.php')
        || !is_file($root . '/app/Services/Bitrix24SyncService.php')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function b24s4Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function b24s4Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Bitrix24 Installer/4.0',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = file_get_contents($url, false, $context);

    if (!is_string($content) || $content === '') {
        throw new RuntimeException(
            "Не удалось загрузить компонент: {$url}"
        );
    }

    return $content;
}

function b24s4Write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException(
            "Не удалось записать {$temporary}"
        );
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException(
            "Не удалось заменить {$path}"
        );
    }
}

function b24s4Lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }

    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY)
        . ' -l '
        . escapeshellarg($path)
        . ' 2>&1',
        $output,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            "Ошибка PHP-синтаксиса в {$path}:\n"
            . implode("\n", $output)
        );
    }
}

function b24s4ReplaceOptional(
    string $content,
    string $needle,
    string $replacement
): string {
    return str_contains($content, $needle)
        ? str_replace($needle, $replacement, $content)
        : $content;
}

$root = b24s4Root();
$clientPath = $root . '/app/Services/Bitrix24Client.php';
$syncPath = $root . '/app/Services/Bitrix24SyncService.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$indexPath = $root . '/index.php';

$currentClient = b24s4Read($clientPath);
$currentSync = b24s4Read($syncPath);
$currentJs = b24s4Read($jsPath);
$currentCss = b24s4Read($cssPath);
$currentIndex = b24s4Read($indexPath);

if (str_contains($currentSync, 'filter_mode')) {
    b24s4Out('Режим автоматического возврата ко всем задачам уже установлен.');
    exit(0);
}

$backupDirectory = $root
    . '/storage/backups/bitrix24-step4-'
    . date('Ymd-His');

if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException(
        'Не удалось создать резервную копию.'
    );
}

$backupFiles = [
    $clientPath => 'Bitrix24Client.php',
    $syncPath => 'Bitrix24SyncService.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
    $indexPath => 'index.php',
];

foreach ($backupFiles as $source => $name) {
    if (!copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException(
            "Не удалось сохранить резервную копию {$name}"
        );
    }
}

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/bitrix24-step4/';

try {
    $client = b24s4Download(
        $baseUrl . 'Bitrix24Client.php'
    );
    $sync = b24s4Download(
        $baseUrl . 'Bitrix24SyncService.php'
    );

    $index = $currentIndex;
    $js = $currentJs;
    $css = $currentCss;

    $index = b24s4ReplaceOptional(
        $index,
        'Проекты, компании, задачи с тегом для клиентского отчёта и фактические трудозатраты.',
        'Проекты, компании, задачи за выбранный период и фактические трудозатраты. Тег используется как дополнительный фильтр.'
    );
    $index = b24s4ReplaceOptional(
        $index,
        '<span>Тег задач для клиентского отчёта</span>',
        '<span>Тег задач для клиентского отчёта (необязательно)</span>'
    );
    $index = b24s4ReplaceOptional(
        $index,
        '                                >\n                            </label>\n\n                            <button type="submit" class="button button-primary">',
        '                                >\n                                <small class="bitrix24-tag-hint">Если задачи с тегом найдены, в отчёте останутся только они. Если тег не найден или поле пустое, будут показаны все задачи выбранного периода.</small>\n                            </label>\n\n                            <button type="submit" class="button button-primary">'
    );
    $index = b24s4ReplaceOptional(
        $index,
        'В выборку попадают задачи связанного проекта с указанным тегом. Время считается по отдельным записям трудозатрат, дата которых входит в выбранный период.',
        'Сначала загружаются задачи связанного проекта за выбранный период. Если указанный тег найден, список автоматически сужается до задач с этим тегом. Время считается по отдельным записям трудозатрат.'
    );

    $js = b24s4ReplaceOptional(
        $js,
        "['Задач с тегом',",
        "['Задач в отчёте',"
    );
    $js = b24s4ReplaceOptional(
        $js,
        'Задачи с выбранным тегом пока не синхронизированы или не найдены.',
        'Задачи за выбранный период пока не синхронизированы или не найдены.'
    );

    $cssPatch = <<<'CSS'

/* BITRIX24_STEP4_FALLBACK_UI */
#bitrix24Message.alert-warning,
#section-bitrix24 #bitrix24Message.alert-warning,
.alert.alert-warning {
    display: block !important;
    padding: 14px 16px !important;
    border: 1px solid #f1d38a !important;
    border-radius: 12px !important;
    background: #fffbeb !important;
    color: #854d0e !important;
    line-height: 1.55 !important;
    white-space: normal;
}

.bitrix24-tag-hint {
    display: block;
    margin-top: 6px;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.45;
}
CSS;

    if (!str_contains($css, 'BITRIX24_STEP4_FALLBACK_UI')) {
        $css .= $cssPatch;
    }

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=18',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=18',
        $index
    ) ?? $index;

    b24s4Write($clientPath, $client);
    b24s4Write($syncPath, $sync);
    b24s4Write($jsPath, $js);
    b24s4Write($cssPath, $css);
    b24s4Write($indexPath, $index);

    b24s4Lint($clientPath);
    b24s4Lint($syncPath);
    b24s4Lint($indexPath);

    b24s4Out('');
    b24s4Out('Гибкая синхронизация задач Битрикс24 установлена.');
    b24s4Out('- без тега показываются все задачи выбранного периода;');
    b24s4Out('- при найденном теге список сужается до него;');
    b24s4Out('- при ненайденном теге включается автоматический возврат ко всем задачам периода;');
    b24s4Out('- трудозатраты запрашиваются пакетами и считаются по отдельным записям;');
    b24s4Out('- предупреждения выделяются жёлтой плашкой.');
    b24s4Out('');
    b24s4Out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $name) {
        @copy(
            $backupDirectory . '/' . $name,
            $destination
        );
    }

    fwrite(
        STDERR,
        PHP_EOL
        . 'ОШИБКА: '
        . $exception->getMessage()
        . PHP_EOL
        . 'Файлы восстановлены из резервной копии.'
        . PHP_EOL
    );
    exit(1);
}
