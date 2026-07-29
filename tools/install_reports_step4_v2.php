<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

$sourceUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/'
    . '4628f319b977b27b164bd9c9c2230701d645cb94/'
    . 'tools/install_reports_step4.php';

$context = stream_context_create([
    'http' => [
        'timeout' => 60,
        'follow_location' => 1,
        'user_agent' => 'Mirsaitov SEO Reports Installer/4.1',
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

$needle = <<<'PHPBLOCK'
    $service = step4Download(
        $baseUrl . 'SeoAutoFillService.php'
    );
PHPBLOCK;

$replacement = <<<'PHPBLOCK'
    $service = step4Download(
        $baseUrl . 'SeoAutoFillService.php'
    );

    $serviceNeedle = <<<'SERVICEPATCH'
        $currentLeadValues = array_values($currentLeadRows);
        $previousLeadValues = array_values($previousLeadRows);
SERVICEPATCH;

    $serviceReplacement = <<<'SERVICEPATCH'
        $currentLeadValues = array_map(
            static fn(array $entry): ?array =>
                $currentLeadRows[mb_strtolower($entry['dimension'])]
                ?? null,
            $currentValues
        );
        $previousLeadValues = array_map(
            static fn(array $entry): ?array =>
                $previousLeadRows[mb_strtolower($entry['dimension'])]
                ?? null,
            $previousValues
        );
SERVICEPATCH;

    $servicePatchCount = 0;
    $service = str_replace(
        $serviceNeedle,
        $serviceReplacement,
        $service,
        $servicePatchCount
    );

    if ($servicePatchCount !== 1) {
        throw new RuntimeException(
            'Не удалось применить исправление сопоставления заявок по датам.'
        );
    }
PHPBLOCK;

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
        "Не удалось подготовить исправленный установщик.\n"
    );
    exit(1);
}

$runtimePath = __DIR__ . '/install_reports_step4_runtime.php';

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
