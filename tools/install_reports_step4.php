<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function step4Out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function step4Root(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/api.php')
        || !is_file($root . '/assets/app.js')
        || !is_file($root . '/assets/app.css')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function step4Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function step4Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov SEO Reports Installer/4.0',
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

function step4ReplaceOnce(
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $position = strpos($content, $needle);

    if ($position === false) {
        throw new RuntimeException(
            "Не найдена точка изменения: {$label}"
        );
    }

    return substr($content, 0, $position)
        . $replacement
        . substr($content, $position + strlen($needle));
}

function step4InsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return step4ReplaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function step4Write(string $path, string $content): void
{
    $directory = dirname($path);

    if (
        !is_dir($directory)
        && !mkdir($directory, 0775, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            "Не удалось создать каталог {$directory}"
        );
    }

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

function step4Backup(
    string $root,
    array $paths,
    string $backupDirectory
): array {
    if (
        !mkdir($backupDirectory, 0700, true)
        && !is_dir($backupDirectory)
    ) {
        throw new RuntimeException(
            'Не удалось создать резервную копию.'
        );
    }

    $manifest = [];

    foreach ($paths as $relativePath) {
        $source = $root . '/' . $relativePath;
        $exists = is_file($source);
        $manifest[$relativePath] = $exists;

        if (!$exists) {
            continue;
        }

        $destination = $backupDirectory . '/' . $relativePath;
        $directory = dirname($destination);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                "Не удалось создать каталог копии {$directory}"
            );
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException(
                "Не удалось сохранить копию {$relativePath}"
            );
        }
    }

    return $manifest;
}

function step4Rollback(
    string $root,
    string $backupDirectory,
    array $manifest
): void {
    foreach ($manifest as $relativePath => $existed) {
        $destination = $root . '/' . $relativePath;

        if ($existed) {
            $source = $backupDirectory . '/' . $relativePath;

            if (is_file($source)) {
                @copy($source, $destination);
            }
        } elseif (is_file($destination)) {
            @unlink($destination);
        }
    }
}

function step4LintPhp(string $path): void
{
    if (!function_exists('exec')) {
        step4Out("Предупреждение: PHP-lint пропущен для {$path}");
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

$root = step4Root();
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$servicePath = $root . '/app/Services/SeoAutoFillService.php';

$indexOriginal = step4Read($indexPath);
$apiOriginal = step4Read($apiPath);
$jsOriginal = step4Read($jsPath);
$cssOriginal = step4Read($cssPath);

if (
    !str_contains($indexOriginal, 'REPORTS_STEP3')
    || !str_contains($jsOriginal, 'REPORTS_STEP3_JS')
) {
    throw new RuntimeException(
        'Сначала установите отдельный SEO-шаблон — шаг 3.'
    );
}

if (str_contains($jsOriginal, 'REPORTS_STEP4_JS')) {
    step4Out('Автоматическое заполнение SEO уже установлено.');
    exit(0);
}

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'app/Services/SeoAutoFillService.php',
];
$backupDirectory = $root
    . '/storage/backups/reports-step4-'
    . date('Ymd-His');
$manifest = step4Backup(
    $root,
    $paths,
    $backupDirectory
);

step4Out("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/reports-step4/';

try {
    $service = step4Download(
        $baseUrl . 'SeoAutoFillService.php'
    );
    $apiFragment = step4Download(
        $baseUrl . 'seo_autofill_api.phpfrag'
    );
    $jsFragment = step4Download(
        $baseUrl . 'seo_autofill.js'
    );
    $cssFragment = step4Download(
        $baseUrl . 'seo_autofill.css'
    );

    $index = $indexOriginal;
    $api = $apiOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;

    $api = step4ReplaceOnce(
        $api,
        'use SeoAnalytics\Services\WebmasterService;',
        "use SeoAnalytics\\Services\\WebmasterService;\n"
        . 'use SeoAnalytics\Services\SeoAutoFillService;',
        'импорт SeoAutoFillService'
    );

    $api = step4InsertBefore(
        $api,
        "        if (\$action === 'reports_list') {",
        $apiFragment,
        'GET API автоматического SEO-отчёта'
    );

    $js = step4InsertBefore(
        $js,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript автоматического заполнения SEO'
    );

    $css .= "\n" . $cssFragment . "\n";

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=11',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=11',
        $index
    ) ?? $index;

    step4Write($servicePath, $service);
    step4Write($indexPath, $index);
    step4Write($apiPath, $api);
    step4Write($jsPath, $js);
    step4Write($cssPath, $css);

    step4LintPhp($servicePath);
    step4LintPhp($indexPath);
    step4LintPhp($apiPath);

    require $root . '/app/bootstrap.php';

    if (!class_exists(\SeoAnalytics\Services\SeoAutoFillService::class)) {
        throw new RuntimeException(
            'Класс SeoAutoFillService не загрузился.'
        );
    }

    step4Out('');
    step4Out('Автоматическое заполнение SEO-отчёта установлено.');
    step4Out('- текущий и выбранный прошлый период;');
    step4Out('- органический трафик из Метрики;');
    step4Out('- основные заявки по выбранным целям;');
    step4Out('- показы, клики, позиции и запросы из Вебмастера;');
    step4Out('- посадочные страницы и диагностика;');
    step4Out('- финансы и текстовые блоки не перезаписываются.');
    step4Out('');
    step4Out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    step4Rollback(
        $root,
        $backupDirectory,
        $manifest
    );

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
