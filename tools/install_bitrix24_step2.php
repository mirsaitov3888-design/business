<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("Run via PHP CLI.\n");
}

$root = realpath(dirname(__DIR__));
if (!is_string($root)) {
    throw new RuntimeException('Project root not found.');
}

$clientPath = $root . '/app/Services/Bitrix24Client.php';
$syncPath = $root . '/app/Services/Bitrix24SyncService.php';

if (!is_file($clientPath) || !is_file($syncPath)) {
    throw new RuntimeException('Install Bitrix24 step 1 first.');
}

$backup = $root . '/storage/backups/bitrix24-step2-' . date('Ymd-His');
if (!mkdir($backup, 0700, true) && !is_dir($backup)) {
    throw new RuntimeException('Cannot create backup directory.');
}
copy($clientPath, $backup . '/Bitrix24Client.php');
copy($syncPath, $backup . '/Bitrix24SyncService.php');

$base = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/tools/bitrix24-step2/';
$context = stream_context_create([
    'http' => ['timeout' => 60, 'follow_location' => 1],
    'https' => ['verify_peer' => true, 'verify_peer_name' => true],
]);

try {
    $client = file_get_contents($base . 'Bitrix24Client.php', false, $context);
    $sync = file_get_contents($base . 'Bitrix24SyncService.php', false, $context);

    if (!is_string($client) || !is_string($sync) || $client === '' || $sync === '') {
        throw new RuntimeException('Cannot download update files.');
    }

    file_put_contents($clientPath, $client, LOCK_EX);
    file_put_contents($syncPath, $sync, LOCK_EX);

    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($clientPath) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException(implode("\n", $output));
    }
    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($syncPath) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException(implode("\n", $output));
    }

    echo "Bitrix24 sync fix installed.\n";
    echo "Backup: {$backup}\n";
} catch (Throwable $e) {
    copy($backup . '/Bitrix24Client.php', $clientPath);
    copy($backup . '/Bitrix24SyncService.php', $syncPath);
    fwrite(STDERR, "ERROR: {$e->getMessage()}\nFiles restored.\n");
    exit(1);
}
