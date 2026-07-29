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
        'user_agent' => 'Mirsaitov Reports Installer/7.2',
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

function patchStep7Source(
    string $source,
    string $needle,
    string $replacement,
    string $label
): string {
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
            "Не удалось подготовить {$label}: найдено замен {$count}.\n"
        );
        exit(1);
    }

    return $source;
}

$source = patchStep7Source(
    $source,
    <<<'OLD'
    $jsFragment = step7Download($baseUrl . 'reports_step7.js');
    $cssFragment = step7Download($baseUrl . 'reports_step7.css');
OLD,
    <<<'NEW'
    $jsFragment = step7Download($baseUrl . 'reports_step7.js');
    $jsFragment .= "\n" . step7Download(
        $baseUrl . 'reports_step7_results.js'
    );
    $jsFragment .= "\n    reportsStep7RenderTrafficSegments([]);\n";
    $cssFragment = step7Download($baseUrl . 'reports_step7.css');
NEW,
    'JavaScript рекламного шаблона'
);

$additionalPatches = <<<'PHPBLOCK'
    $repository = step7ReplaceOnce(
        $repository,
        "        \$groups = [];\n"
        . "        \$creatives = [];\n"
        . "        \$trafficSegments = [];",
        "        \$groups = [];\n"
        . "        \$creatives = [];\n"
        . "        \$trafficSegments = [];\n"
        . "        \$advertisingResults = '';",
        'инициализация результатов рекламы'
    );

    $repository = step7ReplaceOnce(
        $repository,
        "            } elseif (\$detail['detail_type'] === 'traffic_segment') {\n"
        . "                \$trafficSegments[] = \$payload;\n"
        . "            } elseif (\$detail['detail_type'] === 'creative') {",
        "            } elseif (\$detail['detail_type'] === 'traffic_segment') {\n"
        . "                \$trafficSegments[] = \$payload;\n"
        . "            } elseif (\$detail['detail_type'] === 'advertising_results') {\n"
        . "                \$advertisingResults = (string) (\$payload['html'] ?? '');\n"
        . "            } elseif (\$detail['detail_type'] === 'creative') {",
        'чтение результатов рекламы'
    );

    $repository = step7ReplaceOnce(
        $repository,
        "        \$report['traffic_segments'] = \$trafficSegments;\n"
        . "        \$report['creatives'] = \$creatives;",
        "        \$report['traffic_segments'] = \$trafficSegments;\n"
        . "        \$report['advertising_results'] = \$advertisingResults;\n"
        . "        \$report['creatives'] = \$creatives;",
        'результаты рекламы в отчёте'
    );

    $repository = step7ReplaceOnce(
        $repository,
        "        \$trafficSegments = \$this->sanitizeTrafficSegments(is_array(\$data['traffic_segments'] ?? null) ? \$data['traffic_segments'] : [], \$allowedKeys);\n"
        . "        \$creatives = \$this->sanitizeCreatives(is_array(\$data['creatives'] ?? null) ? \$data['creatives'] : [], \$allowedKeys);",
        "        \$trafficSegments = \$this->sanitizeTrafficSegments(is_array(\$data['traffic_segments'] ?? null) ? \$data['traffic_segments'] : [], \$allowedKeys);\n"
        . "        \$advertisingResults = self::rich(\$data['advertising_results'] ?? '');\n"
        . "        \$creatives = \$this->sanitizeCreatives(is_array(\$data['creatives'] ?? null) ? \$data['creatives'] : [], \$allowedKeys);",
        'очистка результатов рекламы'
    );

    $advertisingResultsInsert = <<<'RESULTSBLOCK'
            if ($advertisingResults !== '') {
                $insertDetail->execute([
                    'report_id' => $reportId,
                    'detail_type' => 'advertising_results',
                    'channel_key' => 'all',
                    'title' => 'Полученные результаты',
                    'payload_json' => json_encode(
                        ['html' => $advertisingResults],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'sort_order' => 0,
                ]);
            }
RESULTSBLOCK;

    $repository = step7InsertBefore(
        $repository,
        "            foreach (\$creatives as \$creative) {",
        $advertisingResultsInsert,
        'сохранение результатов рекламы'
    );

    $api = step7ReplaceOnce(
        $api,
        "            'traffic_segments' => \$trafficSegments,\n"
        . "            'creatives' => \$creatives,",
        "            'traffic_segments' => \$trafficSegments,\n"
        . "            'advertising_results' => (string) (\$input['advertising_results'] ?? ''),\n"
        . "            'creatives' => \$creatives,",
        'передача результатов рекламы в репозиторий'
    );

PHPBLOCK;

$source = patchStep7Source(
    $source,
    <<<'OLD'
    $js = step7InsertBefore(
        $js,
        '    async function loadDashboard() {',
OLD,
    $additionalPatches . <<<'NEW'
    $js = step7InsertBefore(
        $js,
        '    async function loadDashboard() {',
NEW,
    'хранение результатов рекламы'
);

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
