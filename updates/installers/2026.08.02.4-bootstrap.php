<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

$url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.02.4.php';
$separator = str_contains($url, '?') ? '&' : '?';
$context = stream_context_create([
    'http' => [
        'timeout' => 120,
        'follow_location' => 1,
        'user_agent' => 'Mirsaitov Update Bootstrap/2026.08.02.4',
        'header' => "Cache-Control: no-cache, no-store\r\nPragma: no-cache\r\n",
    ],
    'https' => [
        'verify_peer' => true,
        'verify_peer_name' => true,
    ],
]);

$body = file_get_contents(
    $url . $separator . 'v=' . rawurlencode((string) microtime(true)),
    false,
    $context
);

if (!is_string($body) || $body === '') {
    fwrite(STDERR, "ОШИБКА: не удалось загрузить основной установщик.\n");
    exit(1);
}

$gitBlobSha = sha1('blob ' . strlen($body) . "\0" . $body);
if (!hash_equals('eb328269c84235a8c19e2446320ebb609ef191e8', $gitBlobSha)) {
    fwrite(STDERR, "ОШИБКА: не совпала контрольная сумма основного установщика.\n");
    exit(1);
}

$temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-180204-');
if (!is_string($temporary)) {
    fwrite(STDERR, "ОШИБКА: не удалось создать временный файл.\n");
    exit(1);
}

try {
    if (file_put_contents($temporary, $body, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать основной установщик.');
    }
    @chmod($temporary, 0600);

    $lintOutput = [];
    $lintCode = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1',
        $lintOutput,
        $lintCode
    );
    if ($lintCode !== 0) {
        throw new RuntimeException(
            "Ошибка PHP-синтаксиса основного установщика:\n" . implode("\n", $lintOutput)
        );
    }

    $output = [];
    $code = 0;
    exec(
        'cd ' . escapeshellarg(getcwd() ?: '.')
        . ' && ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($temporary)
        . ' 2>&1',
        $output,
        $code
    );

    foreach ($output as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }

    exit($code);
} catch (Throwable $exception) {
    fwrite(STDERR, 'ОШИБКА: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    @unlink($temporary);
}
