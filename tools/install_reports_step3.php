<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function step3Out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function step3Root(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/api.php')
        || !is_file($root . '/assets/app.js')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function step3Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function step3Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 40,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov SEO Reports Installer/3.0',
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

function step3ReplaceOnce(
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

function step3InsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return step3ReplaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function step3Write(string $path, string $content): void
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

function step3Backup(
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

function step3Rollback(
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

function step3LintPhp(string $path): void
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

$root = step3Root();
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$seoRepositoryPath = $root
    . '/app/Repositories/SeoReportRepository.php';

$indexOriginal = step3Read($indexPath);

if (!str_contains($indexOriginal, 'REPORTS_STEP2')) {
    throw new RuntimeException(
        'Сначала установите доработку отчётов — шаг 2.'
    );
}

if (str_contains($indexOriginal, 'REPORTS_STEP3')) {
    step3Out('Отдельный SEO-шаблон уже установлен.');
    exit(0);
}

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'sql/schema.sql',
    'app/Repositories/SeoReportRepository.php',
];
$backupDirectory = $root
    . '/storage/backups/reports-step3-'
    . date('Ymd-His');
$manifest = step3Backup(
    $root,
    $paths,
    $backupDirectory
);

step3Out("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/reports-step3/';

try {
    $seoRepository = step3Download(
        $baseUrl . 'SeoReportRepository.php'
    );
    $seoSection = step3Download(
        $baseUrl . 'seo_section.html'
    );
    $seoJs = step3Download(
        $baseUrl . 'seo_step3.js'
    );
    $seoCss = step3Download(
        $baseUrl . 'seo_step3.css'
    );
    $apiPost = step3Download(
        $baseUrl . 'seo_api_post.phpfrag'
    );
    $apiGetEnrich = step3Download(
        $baseUrl . 'seo_api_get_enrich.phpfrag'
    );

    $seoJs = str_replace(
        [
            "reportKpiCard('Стоимость SEO-лида', seoCpl || 0",
            "reportKpiCard('Стоимость договора', contractCost || 0",
            "reportKpiCard('ROAS SEO', roas || 0",
            "reportKpiCard('ROMI SEO', romi || 0",
        ],
        [
            "reportKpiCard('Стоимость SEO-лида', seoCpl",
            "reportKpiCard('Стоимость договора', contractCost",
            "reportKpiCard('ROAS SEO', roas",
            "reportKpiCard('ROMI SEO', romi",
        ],
        $seoJs
    );

    $index = $indexOriginal;
    $api = step3Read($apiPath);
    $js = step3Read($jsPath);
    $css = step3Read($cssPath);
    $schema = step3Read($schemaPath);

    $advertisingHeaderNeedle = <<<'HTML'
                            <div class="reports-section-head">
                                <div>
                                    <strong>Каналы и данные продаж</strong>
HTML;

    $advertisingHeaderReplacement = <<<'HTML'
                            <div id="advertisingReportHeader" class="reports-section-head">
                                <div>
                                    <strong>Каналы и данные продаж</strong>
HTML;

    $index = step3ReplaceOnce(
        $index,
        $advertisingHeaderNeedle,
        $advertisingHeaderReplacement,
        'заголовок рекламных каналов'
    );

    $index = step3InsertBefore(
        $index,
        '                            <!-- REPORTS_STEP2 -->',
        $seoSection,
        'SEO-редактор'
    );

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=10',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=10',
        $index
    ) ?? $index;

    $api = step3ReplaceOnce(
        $api,
        'use SeoAnalytics\Repositories\ReportRepository;',
        "use SeoAnalytics\\Repositories\\ReportRepository;\n"
        . 'use SeoAnalytics\Repositories\SeoReportRepository;',
        'импорт SeoReportRepository'
    );

    $api = step3InsertBefore(
        $api,
        "            Security::json([\n"
        . "                'report' => \$report,\n"
        . "            ]);",
        $apiGetEnrich,
        'добавление SEO-данных при открытии отчёта'
    );

    $api = step3InsertBefore(
        $api,
        '    // REPORTS_STEP1_API_POST',
        $apiPost,
        'API сохранения SEO-отчёта'
    );

    $js = step3ReplaceOnce(
        $js,
        "    function fillReportForm(report) {\n"
        . "        const form = \$('#reportForm');",
        "    function fillReportForm(report) {\n"
        . "        if (report.report_type === 'seo' && report.seo_data) {\n"
        . "            fillSeoReportForm(report);\n"
        . "            return;\n"
        . "        }\n"
        . "        const form = \$('#reportForm');",
        'маршрутизация SEO-редактора'
    );

    $js = step3ReplaceOnce(
        $js,
        "    function renderReportPreview(payload) {\n"
        . "        const root = \$('#reportPreviewContent');",
        "    function renderReportPreview(payload) {\n"
        . "        if (payload.report_type === 'seo' && payload.seo_data) {\n"
        . "            renderSeoReportPreview(payload);\n"
        . "            return;\n"
        . "        }\n"
        . "        const root = \$('#reportPreviewContent');",
        'маршрутизация SEO-предпросмотра'
    );

    $js = step3InsertBefore(
        $js,
        '    async function loadDashboard() {',
        $seoJs . "\n",
        'JavaScript SEO-шаблона'
    );

    $css .= "\n" . $seoCss . "\n";

    if (!str_contains($schema, 'REPORTS_STEP3_SCHEMA')) {
        $schema .= <<<'SQL'

-- REPORTS_STEP3_SCHEMA
CREATE TABLE IF NOT EXISTS report_seo_data (
    report_id BIGINT UNSIGNED NOT NULL,
    payload_json LONGTEXT NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (report_id),
    CONSTRAINT fk_report_seo_data_report
        FOREIGN KEY (report_id) REFERENCES reports(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    step3Write(
        $seoRepositoryPath,
        $seoRepository
    );
    step3Write($indexPath, $index);
    step3Write($apiPath, $api);
    step3Write($jsPath, $js);
    step3Write($cssPath, $css);
    step3Write($schemaPath, $schema);

    step3LintPhp($seoRepositoryPath);
    step3LintPhp($indexPath);
    step3LintPhp($apiPath);

    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS report_seo_data (
            report_id BIGINT UNSIGNED NOT NULL,
            payload_json LONGTEXT NOT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (report_id),
            CONSTRAINT fk_report_seo_data_report
                FOREIGN KEY (report_id) REFERENCES reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    step3Out('');
    step3Out('Отдельный SEO-шаблон установлен.');
    step3Out('- SEO больше не использует рекламную структуру;');
    step3Out('- добавлены трафик, поисковые системы и динамика;');
    step3Out('- добавлены позиции по ключевым фразам;');
    step3Out('- добавлены страницы и технические проблемы;');
    step3Out('- финансовые показатели перенесены в конец;');
    step3Out('- добавлен отдельный SEO-предпросмотр.');
    step3Out('');
    step3Out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    step3Rollback(
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
