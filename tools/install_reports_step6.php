<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function step6Out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function step6Root(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/assets/app.js')
        || !is_file($root . '/assets/app.css')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function step6Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function step6Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Reports Installer/6.0',
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

function step6InsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    $position = strpos($content, $needle);

    if ($position === false) {
        throw new RuntimeException(
            "Не найдена точка изменения: {$label}"
        );
    }

    return substr($content, 0, $position)
        . $insertion
        . $needle
        . substr($content, $position + strlen($needle));
}

function step6Write(string $path, string $content): void
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

function step6Backup(
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

        if (!is_dir($directory)) {
            mkdir($directory, 0700, true);
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException(
                "Не удалось сохранить копию {$relativePath}"
            );
        }
    }

    return $manifest;
}

function step6Rollback(
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

function step6LintPhp(string $path): void
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

$root = step6Root();
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';

$indexOriginal = step6Read($indexPath);
$jsOriginal = step6Read($jsPath);
$cssOriginal = step6Read($cssPath);

if (
    !str_contains($jsOriginal, 'REPORTS_STEP4_JS')
    || !str_contains($jsOriginal, 'REPORTS_STEP5_JS')
) {
    throw new RuntimeException(
        'Сначала установите автоматическое заполнение и графики — шаги 4 и 5.'
    );
}

if (str_contains($jsOriginal, 'REPORTS_STEP6_JS')) {
    step6Out('Исправления интерфейса SEO уже установлены.');
    exit(0);
}

$paths = [
    'index.php',
    'assets/app.js',
    'assets/app.css',
];
$backupDirectory = $root
    . '/storage/backups/reports-step6-'
    . date('Ymd-His');
$manifest = step6Backup(
    $root,
    $paths,
    $backupDirectory
);

step6Out("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/reports-step6/';

try {
    $jsFragment = step6Download(
        $baseUrl . 'reports_step6.js'
    );
    $cssFragment = step6Download(
        $baseUrl . 'reports_step6.css'
    );

    $index = $indexOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;

    $js = step6InsertBefore(
        $js,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript исправлений SEO-интерфейса'
    );

    $css .= "\n" . $cssFragment . "\n";

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=13',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=13',
        $index
    ) ?? $index;

    step6Write($indexPath, $index);
    step6Write($jsPath, $js);
    step6Write($cssPath, $css);

    step6LintPhp($indexPath);

    step6Out('');
    step6Out('Исправления SEO-интерфейса установлены.');
    step6Out('- проценты округляются до сотых;');
    step6Out('- описание SEO-шаблона занимает всю ширину;');
    step6Out('- кнопка автозагрузки перенесена вниз и растянута;');
    step6Out('- управление секциями объединено в одну кнопку;');
    step6Out('- стрелки выровнены по центру.');
    step6Out('');
    step6Out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    step6Rollback(
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
