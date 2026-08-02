<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

$payload = <<<'PAYLOAD'
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
w0+MMHh8VpV8VdhxguFr/w8UJzgO
PAYLOAD;

$compressed = base64_decode(preg_replace('/\\s+/', '', $payload) ?? '', true);
if (!is_string($compressed)) {
    fwrite(STDERR, "ОШИБКА: не удалось декодировать установщик.\n");
    exit(1);
}
if (!hash_equals('aed833a69b547fe840b0724af193f8eb0577d77cf464bd44a40bade596266139', hash('sha256', $compressed))) {
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
if (!hash_equals('86f9dd839898a3a60ebbd11196019835b42fca68026359c958f01a3bc8f80551', hash('sha256', $core))) {
    fwrite(STDERR, "ОШИБКА: не совпала SHA-256 установщика.\n");
    exit(1);
}

$temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-180205-');
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
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1',
        $lintOutput,
        $lintCode
    );
    if ($lintCode !== 0) {
        throw new RuntimeException(
            "Ошибка PHP-синтаксиса:\n" . implode("\n", $lintOutput)
        );
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
