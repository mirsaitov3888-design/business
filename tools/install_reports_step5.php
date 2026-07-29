<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function step5Out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function step5Root(): string
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

function step5Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function step5Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Reports Installer/5.0',
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

function step5ReplaceOnce(
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

function step5InsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return step5ReplaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function step5Write(string $path, string $content): void
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

function step5Backup(
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

function step5Rollback(
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

function step5LintPhp(string $path): void
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

$root = step5Root();
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';

$indexOriginal = step5Read($indexPath);
$jsOriginal = step5Read($jsPath);
$cssOriginal = step5Read($cssPath);

if (
    !str_contains($jsOriginal, 'REPORTS_STEP3_JS')
    || !str_contains($jsOriginal, 'REPORTS_STEP4_JS')
) {
    throw new RuntimeException(
        'Сначала установите SEO-шаблон и автоматическое заполнение.'
    );
}

if (str_contains($jsOriginal, 'REPORTS_STEP5_JS')) {
    step5Out('Доработка интерфейса и графиков уже установлена.');
    exit(0);
}

$paths = [
    'index.php',
    'assets/app.js',
    'assets/app.css',
];
$backupDirectory = $root
    . '/storage/backups/reports-step5-'
    . date('Ymd-His');
$manifest = step5Backup(
    $root,
    $paths,
    $backupDirectory
);

step5Out("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/reports-step5/';

try {
    $fragment = step5Download(
        $baseUrl . 'reports_step5.js'
    );
    $cssFragment = step5Download(
        $baseUrl . 'reports_step5.css'
    );

    $fragment = str_replace(
        <<<'OLD'
                [
                    previousPositions.filter(value => value <= 3).length,
                    previousPositions.filter(value => value <= 10).length,
                    previousPositions.filter(value => value <= 30).length
                ]
OLD,
        <<<'NEW'
                payload.comparison_date_from && payload.comparison_date_to
                    ? [
                        previousPositions.filter(value => value <= 3).length,
                        previousPositions.filter(value => value <= 10).length,
                        previousPositions.filter(value => value <= 30).length
                    ]
                    : [null, null, null]
NEW,
        $fragment
    );

    $index = $indexOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;

    $positionHelpers = <<<'JS'
    function seoPositionKey(key) {
        return ['avg_position', 'position_current', 'position_previous'].includes(key);
    }

    function seoPositionInput(value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }

        const parsed = Number(value);

        if (!Number.isFinite(parsed)) {
            return '';
        }

        return String(Math.round(parsed * 100) / 100);
    }

    function seoFormatPosition(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        const parsed = Number(value);

        if (!Number.isFinite(parsed)) {
            return '—';
        }

        return new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(parsed);
    }

JS;

    $js = step5InsertBefore(
        $js,
        '    function seoSet(selector, data = {}) {',
        $positionHelpers,
        'форматирование средней позиции'
    );

    $js = step5ReplaceOnce(
        $js,
        '            input.value = seoInput(data[key]);',
        "            input.value = seoPositionKey(key)\n"
        . "                ? seoPositionInput(data[key])\n"
        . "                : seoInput(data[key]);",
        'округление позиций в полях'
    );

    $js = step5ReplaceOnce(
        $js,
        'value="${escapeHtml(seoInput(row.position_current))}"',
        'value="${escapeHtml(seoPositionInput(row.position_current))}"',
        'позиция запроса сейчас'
    );

    $js = step5ReplaceOnce(
        $js,
        'value="${escapeHtml(seoInput(row.position_previous))}"',
        'value="${escapeHtml(seoPositionInput(row.position_previous))}"',
        'позиция запроса ранее'
    );

    $js = step5ReplaceOnce(
        $js,
        'value="${escapeHtml(seoInput(row.avg_position))}"',
        'value="${escapeHtml(seoPositionInput(row.avg_position))}"',
        'позиция страницы'
    );

    $js = step5ReplaceOnce(
        $js,
        "reportKpiCard('Средняя позиция', metrics.avg_position || 0, previous.avg_position, value => number.format(value || 0), 'Меньше — лучше', 'lower'),",
        "reportKpiCard('Средняя позиция', metrics.avg_position, previous.avg_position, seoFormatPosition, 'Меньше — лучше', 'lower'),",
        'карточка средней позиции'
    );

    $js = step5ReplaceOnce(
        $js,
        "<td class=\"num\">\${row.position_current ?? '—'}</td>",
        "<td class=\"num\">\${seoFormatPosition(row.position_current)}</td>",
        'позиция сейчас в предпросмотре'
    );

    $js = step5ReplaceOnce(
        $js,
        "<td class=\"num\">\${row.position_previous ?? '—'}</td>",
        "<td class=\"num\">\${seoFormatPosition(row.position_previous)}</td>",
        'позиция ранее в предпросмотре'
    );

    $js = step5ReplaceOnce(
        $js,
        "<td class=\"num\">\${row.avg_position ?? '—'}</td>",
        "<td class=\"num\">\${seoFormatPosition(row.avg_position)}</td>",
        'позиция страницы в предпросмотре'
    );

    $js = step5InsertBefore(
        $js,
        '    async function loadDashboard() {',
        $fragment . "\n",
        'графики и сворачиваемые блоки'
    );

    $css .= "\n" . $cssFragment . "\n";

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=12',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=12',
        $index
    ) ?? $index;

    step5Write($indexPath, $index);
    step5Write($jsPath, $js);
    step5Write($cssPath, $css);

    step5LintPhp($indexPath);

    step5Out('');
    step5Out('Доработка отчётов установлена.');
    step5Out('- средняя позиция округляется до сотых;');
    step5Out('- SEO-блоки и редакторские поля сворачиваются;');
    step5Out('- добавлены графики SEO-трафика, заявок и позиций;');
    step5Out('- добавлены графики переходов, заявок и продаж по рекламе;');
    step5Out('- выбранный прошлый период отображается на графиках.');
    step5Out('');
    step5Out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    step5Rollback(
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
