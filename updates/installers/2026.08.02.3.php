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
if (!hash_equals('1eb9393db39a9c80a9e8dd0a445b5597fc85043fad0a06c8b0b81366adb85a66', hash('sha256', $compressed))) {
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
if (!hash_equals('95cdd4bbfee59b79474177a7fd4f4334a94919209e3b20aba2ae1145596495e1', hash('sha256', $core))) {
    fwrite(STDERR, "ОШИБКА: не совпала SHA-256 установщика.\n");
    exit(1);
}

$temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-180203-');
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
