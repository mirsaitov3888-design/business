<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function up14out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function up14download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 120,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Update Fix/14',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $body = file_get_contents($url, false, $context);

    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Не удалось загрузить исправляемый установщик версии 2026.08.01.13.');
    }

    return $body;
}

function up14lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }

    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1',
        $output,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            "Ошибка PHP-синтаксиса исправленного установщика:\n"
            . implode("\n", $output)
        );
    }
}

$root = getcwd() ?: '';

if (!is_file($root . '/index.php') || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Запустите установщик из корня проекта.');
}

$repositoryPath = $root . '/app/Repositories/SystemUpdateRepository.php';
$jsPath = $root . '/assets/app.js';

if (
    is_file($repositoryPath)
    && is_file($jsPath)
    && str_contains((string) file_get_contents($repositoryPath), 'public function setProgress(')
    && str_contains((string) file_get_contents($jsPath), 'SYSTEM_UPDATE_PROGRESS_V13')
) {
    up14out('Живой прогресс обновлений уже установлен.');
    exit(0);
}

$url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.01.13.php';
$expectedSha = 'c58f3e900b2c895c440687e6fa63086773a199375b54c3089942ea845dcbf87f';
$installer = up14download($url);
$actualSha = hash('sha256', $installer);

if (!hash_equals($expectedSha, $actualSha)) {
    throw new RuntimeException(
        'Контрольная сумма исходного установщика 2026.08.01.13 не совпала.'
    );
}

$oldBlock = <<<'PHPBLOCK'
    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM system_updates LIKE :column_name');
        $stmt->execute(['column_name' => $name]);
        if (!$stmt->fetch()) {
            $pdo->exec('ALTER TABLE system_updates ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
PHPBLOCK;

$newBlock = <<<'PHPBLOCK'
    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*)
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => 'system_updates',
            'column_name' => $name,
        ]);
        if ((int) $stmt->fetchColumn() === 0) {
            $pdo->exec('ALTER TABLE system_updates ADD COLUMN ' . $name . ' ' . $definition);
        }
    }
PHPBLOCK;

$count = 0;
$installer = str_replace($oldBlock, $newBlock, $installer, $count);

if ($count !== 1) {
    throw new RuntimeException(
        'Не удалось применить исправление MySQL: проблемный блок найден '
        . $count
        . ' раз.'
    );
}

$temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-14-');

if (!is_string($temporary)) {
    throw new RuntimeException('Не удалось создать временный файл обновления.');
}

try {
    if (file_put_contents($temporary, $installer, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось сохранить исправленный установщик.');
    }

    @chmod($temporary, 0600);
    up14lint($temporary);

    $output = [];
    $code = 0;
    exec(
        'cd ' . escapeshellarg($root)
        . ' && ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($temporary)
        . ' 2>&1',
        $output,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            "Исправленный установщик завершился с кодом {$code}:\n"
            . implode("\n", $output)
        );
    }

    up14out('Исправление MySQL применено.');
    foreach ($output as $line) {
        up14out((string) $line);
    }
} finally {
    @unlink($temporary);
}
