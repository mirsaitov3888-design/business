<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function step7Out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function step7Root(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/api.php')
        || !is_file($root . '/assets/app.js')
        || !is_file($root . '/assets/app.css')
        || !is_file($root . '/app/Repositories/ReportRepository.php')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function step7Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function step7Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Reports Installer/7.0',
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

function step7ReplaceOnce(
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

function step7InsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return step7ReplaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function step7Write(string $path, string $content): void
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

function step7Backup(
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

function step7Rollback(
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

function step7LintPhp(string $path): void
{
    if (!function_exists('exec')) {
        step7Out("Предупреждение: PHP-lint пропущен для {$path}");
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

$root = step7Root();
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$repositoryPath = $root . '/app/Repositories/ReportRepository.php';
$servicePath = $root . '/app/Services/AdvertisingAutoFillService.php';

$indexOriginal = step7Read($indexPath);
$apiOriginal = step7Read($apiPath);
$jsOriginal = step7Read($jsPath);
$cssOriginal = step7Read($cssPath);
$repositoryOriginal = step7Read($repositoryPath);

if (
    !str_contains($indexOriginal, 'REPORTS_STEP3')
    || !str_contains($jsOriginal, 'REPORTS_STEP6_JS')
) {
    throw new RuntimeException(
        'Сначала установите SEO-шаблон и исправления интерфейса — шаги 3–6.'
    );
}

if (
    str_contains($indexOriginal, 'REPORTS_STEP7_ADVERTISING')
    || str_contains($jsOriginal, 'REPORTS_STEP7_JS')
) {
    step7Out('Разделение рекламного и SEO-шаблона уже установлено.');
    exit(0);
}

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'app/Repositories/ReportRepository.php',
    'app/Services/AdvertisingAutoFillService.php',
];
$backupDirectory = $root
    . '/storage/backups/reports-step7-'
    . date('Ymd-His');
$manifest = step7Backup(
    $root,
    $paths,
    $backupDirectory
);

step7Out("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/reports-step7/';

try {
    $intro = step7Download($baseUrl . 'advertising_intro.html');
    $jsFragment = step7Download($baseUrl . 'reports_step7.js');
    $cssFragment = step7Download($baseUrl . 'reports_step7.css');
    $service = step7Download($baseUrl . 'AdvertisingAutoFillService.php');
    $apiFragment = step7Download($baseUrl . 'advertising_autofill_api.phpfrag');

    $index = $indexOriginal;
    $api = $apiOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;
    $repository = $repositoryOriginal;

    $index = step7InsertBefore(
        $index,
        '                            <div id="advertisingReportHeader" class="reports-section-head">',
        $intro,
        'рекламный шаблон'
    );

    $api = step7ReplaceOnce(
        $api,
        'use SeoAnalytics\Services\SeoAutoFillService;',
        "use SeoAnalytics\\Services\\SeoAutoFillService;\n"
        . 'use SeoAnalytics\Services\AdvertisingAutoFillService;',
        'импорт AdvertisingAutoFillService'
    );

    $api = step7InsertBefore(
        $api,
        "        if (\$action === 'reports_list') {",
        $apiFragment,
        'API рекламной автозагрузки'
    );

    $repository = step7ReplaceOnce(
        $repository,
        "        \$groups = [];\n        \$creatives = [];",
        "        \$groups = [];\n        \$creatives = [];\n        \$trafficSegments = [];",
        'инициализация UTM-сегментов'
    );

    $repository = step7ReplaceOnce(
        $repository,
        "            if (\$detail['detail_type'] === 'campaign_group') {\n"
        . "                \$payload['calculated'] = self::calculateChannel(\$payload);\n"
        . "                \$groups[] = \$payload;\n"
        . "            } elseif (\$detail['detail_type'] === 'creative') {\n"
        . "                \$creatives[] = \$payload;\n"
        . "            }",
        "            if (\$detail['detail_type'] === 'campaign_group') {\n"
        . "                \$payload['calculated'] = self::calculateChannel(\$payload);\n"
        . "                \$groups[] = \$payload;\n"
        . "            } elseif (\$detail['detail_type'] === 'traffic_segment') {\n"
        . "                \$trafficSegments[] = \$payload;\n"
        . "            } elseif (\$detail['detail_type'] === 'creative') {\n"
        . "                \$creatives[] = \$payload;\n"
        . "            }",
        'чтение UTM-сегментов'
    );

    $repository = step7ReplaceOnce(
        $repository,
        "        \$report['campaign_groups'] = \$groups;\n"
        . "        \$report['creatives'] = \$creatives;",
        "        \$report['campaign_groups'] = \$groups;\n"
        . "        \$report['traffic_segments'] = \$trafficSegments;\n"
        . "        \$report['creatives'] = \$creatives;",
        'UTM-сегменты в отчёте'
    );

    $repository = step7ReplaceOnce(
        $repository,
        "        \$groups = \$this->sanitizeGroups(is_array(\$data['campaign_groups'] ?? null) ? \$data['campaign_groups'] : [], \$allowedKeys);\n"
        . "        \$creatives = \$this->sanitizeCreatives(is_array(\$data['creatives'] ?? null) ? \$data['creatives'] : [], \$allowedKeys);",
        "        \$groups = \$this->sanitizeGroups(is_array(\$data['campaign_groups'] ?? null) ? \$data['campaign_groups'] : [], \$allowedKeys);\n"
        . "        \$trafficSegments = \$this->sanitizeTrafficSegments(is_array(\$data['traffic_segments'] ?? null) ? \$data['traffic_segments'] : [], \$allowedKeys);\n"
        . "        \$creatives = \$this->sanitizeCreatives(is_array(\$data['creatives'] ?? null) ? \$data['creatives'] : [], \$allowedKeys);",
        'очистка UTM-сегментов'
    );

    $trafficInsertLoop = <<<'PHPBLOCK'
            foreach ($trafficSegments as $segment) {
                $insertDetail->execute([
                    'report_id' => $reportId,
                    'detail_type' => 'traffic_segment',
                    'channel_key' => $segment['channel_key'],
                    'title' => $segment['title'],
                    'payload_json' => json_encode($segment, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sort_order' => $segment['sort_order'],
                ]);
            }
PHPBLOCK;

    $repository = step7InsertBefore(
        $repository,
        "            foreach (\$creatives as \$creative) {",
        $trafficInsertLoop,
        'сохранение UTM-сегментов'
    );

    $trafficSanitizer = <<<'PHPBLOCK'
    private function sanitizeTrafficSegments(array $segments, array $allowedKeys): array
    {
        $result = [];

        foreach (array_slice($segments, 0, 300) as $index => $segment) {
            if (!is_array($segment)) {
                continue;
            }

            $channel = (string) ($segment['channel_key'] ?? '');
            $title = self::plain($segment['title'] ?? '', 190);
            $utmSource = self::plain($segment['utm_source'] ?? '', 300);
            $utmCampaign = self::plain($segment['utm_campaign'] ?? '', 500);
            $landing = self::plain($segment['landing'] ?? '', 1000);

            if (
                !in_array($channel, $allowedKeys, true)
                || ($title === '' && $utmSource === '' && $utmCampaign === '' && $landing === '')
            ) {
                continue;
            }

            $result[] = [
                'channel_key' => $channel,
                'title' => $title === '' ? 'Источник трафика' : $title,
                'utm_source' => $utmSource,
                'utm_medium' => self::plain($segment['utm_medium'] ?? '', 300),
                'utm_campaign' => $utmCampaign,
                'landing' => $landing,
                'visits' => self::nullableInteger($segment['visits'] ?? null),
                'users' => self::nullableInteger($segment['users'] ?? null),
                'leads' => self::nullableInteger($segment['leads'] ?? null),
                'bounce_rate' => self::nullablePercent($segment['bounce_rate'] ?? null),
                'previous_visits' => self::nullableInteger($segment['previous_visits'] ?? null),
                'previous_leads' => self::nullableInteger($segment['previous_leads'] ?? null),
                'sort_order' => $index,
            ];
        }

        return $result;
    }

PHPBLOCK;

    $repository = step7InsertBefore(
        $repository,
        '    private function sanitizeCreatives(array $creatives, array $allowedKeys): array',
        $trafficSanitizer,
        'санитизация UTM-сегментов'
    );

    $api = step7ReplaceOnce(
        $api,
        "        \$groups = is_array(\$input['campaign_groups'] ?? null) ? array_values(\$input['campaign_groups']) : [];\n"
        . "        \$creatives = is_array(\$input['creatives'] ?? null) ? array_values(\$input['creatives']) : [];",
        "        \$groups = is_array(\$input['campaign_groups'] ?? null) ? array_values(\$input['campaign_groups']) : [];\n"
        . "        \$trafficSegments = is_array(\$input['traffic_segments'] ?? null) ? array_values(\$input['traffic_segments']) : [];\n"
        . "        \$creatives = is_array(\$input['creatives'] ?? null) ? array_values(\$input['creatives']) : [];",
        'получение UTM-сегментов из формы'
    );

    $api = step7ReplaceOnce(
        $api,
        "        if (count(\$channels) > 10 || count(\$groups) > 100 || count(\$creatives) > 100) {",
        "        if (count(\$channels) > 10 || count(\$groups) > 100 || count(\$trafficSegments) > 300 || count(\$creatives) > 100) {",
        'лимит элементов отчёта'
    );

    $api = step7ReplaceOnce(
        $api,
        "            'campaign_groups' => \$groups,\n            'creatives' => \$creatives,",
        "            'campaign_groups' => \$groups,\n            'traffic_segments' => \$trafficSegments,\n            'creatives' => \$creatives,",
        'передача UTM-сегментов в репозиторий'
    );

    $api = step7ReplaceOnce(
        $api,
        "            'campaign_groups' => count(\$groups),\n            'creatives' => count(\$creatives),",
        "            'campaign_groups' => count(\$groups),\n            'traffic_segments' => count(\$trafficSegments),\n            'creatives' => count(\$creatives),",
        'аудит UTM-сегментов'
    );

    $js = step7InsertBefore(
        $js,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript рекламного шаблона'
    );

    $css .= "\n" . $cssFragment . "\n";

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=14',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=14',
        $index
    ) ?? $index;

    step7Write($servicePath, $service);
    step7Write($repositoryPath, $repository);
    step7Write($indexPath, $index);
    step7Write($apiPath, $api);
    step7Write($jsPath, $js);
    step7Write($cssPath, $css);

    step7LintPhp($servicePath);
    step7LintPhp($repositoryPath);
    step7LintPhp($indexPath);
    step7LintPhp($apiPath);

    require $root . '/app/bootstrap.php';

    if (!class_exists(\SeoAnalytics\Services\AdvertisingAutoFillService::class)) {
        throw new RuntimeException(
            'Класс AdvertisingAutoFillService не загрузился.'
        );
    }

    step7Out('');
    step7Out('Рекламный и SEO-шаблоны разделены.');
    step7Out('- SEO-блоки больше не показываются в рекламном отчёте;');
    step7Out('- добавлены главные рекламные показатели;');
    step7Out('- добавлена UTM-разбивка по сайту, Марквизу и другим посадочным;');
    step7Out('- выбранный отчёт выделяется в истории;');
    step7Out('- редакторские блоки идут на всю ширину и растягиваются;');
    step7Out('- Метрика автоматически заполняет заявки и UTM-разбивку;');
    step7Out('- SEO-шаблон и его данные не изменялись.');
    step7Out('');
    step7Out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    step7Rollback(
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
