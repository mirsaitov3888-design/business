<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

$payload = <<<'PAYLOAD'
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
PAYLOAD;

$compressed = base64_decode(preg_replace('/\s+/', '', $payload) ?? '', true);
if (!is_string($compressed)) {
    fwrite(STDERR, "ОШИБКА: не удалось декодировать установщик.\n");
    exit(1);
}
if (!hash_equals('782ae1133f2068abc3db8b551466e665bf92bc4761919c15bb4d7333ef0ffdfb', hash('sha256', $compressed))) {
    fwrite(STDERR, "ОШИБКА: не совпала контрольная сумма сжатого установщика.\n");
    exit(1);
}
if (!function_exists('gzuncompress')) {
    fwrite(STDERR, "ОШИБКА: PHP собран без поддержки zlib.\n");
    exit(1);
}
$core = gzuncompress($compressed);
if (!is_string($core) || $core === '') {
    fwrite(STDERR, "ОШИБКА: не удалось распаковать установщик.\n");
    exit(1);
}
if (!hash_equals('9a906bacfb9556efc2f4c3646d974a2eca732168c62fc6688e48467575711246', hash('sha256', $core))) {
    fwrite(STDERR, "ОШИБКА: не совпала SHA-256 установщика.\n");
    exit(1);
}

$temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-180202-');
if (!is_string($temporary)) {
    fwrite(STDERR, "ОШИБКА: не удалось создать временный файл.\n");
    exit(1);
}

try {
    if (file_put_contents($temporary, $core, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный установщик.');
    }
    @chmod($temporary, 0600);

    $lintOutput = [];
    $lintCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1', $lintOutput, $lintCode);
    if ($lintCode !== 0) {
        throw new RuntimeException("Ошибка PHP-синтаксиса:\n" . implode("\n", $lintOutput));
    }

    $command = 'cd ' . escapeshellarg(getcwd() ?: '.')
        . ' && ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($temporary)
        . ' 2>&1';
    $output = [];
    $code = 0;
    exec($command, $output, $code);
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
