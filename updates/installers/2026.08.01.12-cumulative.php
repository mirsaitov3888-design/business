<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function av12cDownload(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 90,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Update 2026.08.01.12',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = file_get_contents($url, false, $context);
    if (!is_string($content) || $content === '') {
        throw new RuntimeException('Не удалось загрузить компонент обновления: ' . $url);
    }
    return $content;
}

function av12cRun(string $content, string $label, string $root): void
{
    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Не удалось создать временный файл для ' . $label . '.');
    }
    file_put_contents($temporary, $content, LOCK_EX);
    @chmod($temporary, 0600);

    $lintOutput = [];
    $lintCode = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($temporary) . ' 2>&1', $lintOutput, $lintCode);
    if ($lintCode !== 0) {
        @unlink($temporary);
        throw new RuntimeException('Ошибка синтаксиса ' . $label . ': ' . implode("\n", $lintOutput));
    }

    $output = [];
    $code = 0;
    $command = 'cd ' . escapeshellarg($root)
        . ' && ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($temporary)
        . ' 2>&1';
    exec($command, $output, $code);
    @unlink($temporary);

    foreach ($output as $line) {
        fwrite(STDOUT, $line . PHP_EOL);
    }
    if ($code !== 0) {
        throw new RuntimeException($label . ' завершился с кодом ' . $code . '.');
    }
}

$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';

if (!is_file($indexPath) || !is_file($jsPath)) {
    throw new RuntimeException('Не удалось определить корень проекта.');
}

$base = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/';
$js = (string) file_get_contents($jsPath);

if (!str_contains($js, 'GLOBAL_LAYOUT_CONTEXT_V11')) {
    fwrite(STDOUT, "Применяем обязательные изменения версии 2026.08.01.11...\n");
    av12cRun(
        av12cDownload($base . '2026.08.01.11.php'),
        'обновление 2026.08.01.11',
        $root
    );
} else {
    fwrite(STDOUT, "Версия 2026.08.01.11 уже применена.\n");
}

fwrite(STDOUT, "Удаляем старый модуль доступности...\n");
av12cRun(
    av12cDownload($base . '2026.08.01.12.php'),
    'очистка старого модуля доступности',
    $root
);

// Удаляем оставшуюся запись старого раздела из карты хлебных крошек.
$js = (string) file_get_contents($jsPath);
$js = str_replace(
    "        availability: ['Сервис', 'Техническая поддержка', 'Доступность сайта'],\n",
    '',
    $js
);
$js = str_replace(
    "        availability: ['Сервис', 'Техническая поддержка', 'Доступность сайта'],\r\n",
    '',
    $js
);
file_put_contents($jsPath, $js, LOCK_EX);

fwrite(STDOUT, "Последовательное обновление 2026.08.01.11 → 2026.08.01.12 завершено.\n");
