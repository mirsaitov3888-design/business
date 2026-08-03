<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

const UI_POPUP_FIX_VERSION = '2026.08.03.21';
const UI_POPUP_FIX_MARKER = 'PORTAL_UI_POPUP_FIX_V180321';
const UI_POPUP_FIX_ASSET_VERSION = '2026080321';

function ui21out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function ui21read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function ui21write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный файл: ' . $temporary);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить файл: ' . $path);
    }
}

function ui21lint(string $path): void
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
            'Ошибка PHP-синтаксиса в ' . $path . ':\n' . implode("\n", $output)
        );
    }
}

function ui21payload(): array
{
    $encoded = 'eyJjc3MiOnsic2hhMjU2IjoiYzhjMzRjMjdmNjM5MTYwMmRjZWJlM2NhN2Q5NzBiNzQ5NTRkM2NkODE1MTMyNmNhMDFmOTk0MDhlODE5ODA4MSIsImNvbnRlbnQiOiJDaThxSUZCUFVsUkJURjlWU1Y5UVQxQlZVRjlHU1ZoZlZqRTRNRE15TVNBcUx3b3VZakU1TFdOdmJuUmhZM1F0WTJGeVpDQStJR2x1Y0hWMFczUjVjR1U5SW1Ob1pXTnJZbTk0SWwwc0NpNWlNVGt0Y0hKdmFtVmpkQzFqWVhKa0lENGdhVzV3ZFhSYmRIbHdaVDBpWTJobFkydGliM2dpWFN3S0xtSXhPUzF3Y21sdFlYSjVMV05vYjJsalpTQStJR2x1Y0hWMFczUjVjR1U5SW5KaFpHbHZJbDBnZXdvZ0lDQWdZWEJ3WldGeVlXNWpaVG9nWVhWMGJ5QWhhVzF3YjNKMFlXNTBPd29nSUNBZ2QybGtkR2c2SURFMmNIZ2dJV2x0Y0c5eWRHRnVkRHNLSUNBZ0lHMXBiaTEzYVdSMGFEb2dNVFp3ZUNBaGFXMXdiM0owWVc1ME93b2dJQ0FnYldGNExYZHBaSFJvT2lBeE5uQjRJQ0ZwYlhCdmNuUmhiblE3Q2lBZ0lDQm9aV2xuYUhRNklERTJjSGdnSVdsdGNHOXlkR0Z1ZERzS0lDQWdJRzFwYmkxb1pXbG5hSFE2SURFMmNIZ2dJV2x0Y0c5eWRHRnVkRHNLSUNBZ0lHMWhlQzFvWldsbmFIUTZJREUyY0hnZ0lXbHRjRzl5ZEdGdWREc0tJQ0FnSUdac1pYZzZJREFnTUNBeE5uQjRJQ0ZwYlhCdmNuUmhiblE3Q2lBZ0lDQndZV1JrYVc1bk9pQXdJQ0ZwYlhCdmNuUmhiblE3Q2lBZ0lDQnRZWEpuYVc0NklESndlQ0F3SURBZ0lXbHRjRzl5ZEdGdWREc0tJQ0FnSUdKdmNtUmxjaTF5WVdScGRYTTZJREp3ZURzS2ZRb0tMbUl4T1Mxd2NtbHRZWEo1TFdOb2IybGpaU0ErSUdsdWNIVjBXM1I1Y0dVOUluSmhaR2x2SWwwZ2V3b2dJQ0FnWW05eVpHVnlMWEpoWkdsMWN6b2dOVEFsT3dwOUNnb3VZakU1TFdOdmJuUmhZM1F0WTJGeVpDd0tMbUl4T1Mxd2NtOXFaV04wTFdOaGNtUWdld29nSUNBZ2JXbHVMWGRwWkhSb09pQXdJQ0ZwYlhCdmNuUmhiblE3Q2lBZ0lDQnZkbVZ5Wm14dmR6b2dhR2xrWkdWdUlDRnBiWEJ2Y25SaGJuUTdDbjBLQ2k1aU1Ua3RZMjl1ZEdGamRDMXRZV2x1TEFvdVlqRTVMWEJ5YjJwbFkzUXRZMkZ5WkNBK0lITndZVzRnZXdvZ0lDQWdaR2x6Y0d4aGVUb2daM0pwWkNBaGFXMXdiM0owWVc1ME93b2dJQ0FnYldsdUxYZHBaSFJvT2lBd0lDRnBiWEJ2Y25SaGJuUTdDaUFnSUNCM2FXUjBhRG9nWVhWMGJ5QWhhVzF3YjNKMFlXNTBPd29nSUNBZ1pteGxlRG9nTVNBeElHRjFkRzhnSVdsdGNHOXlkR0Z1ZERzS0lDQWdJR2RoY0RvZ05IQjRJQ0ZwYlhCdmNuUmhiblE3Q24wS0NpNWlNVGt0WTI5dWRHRmpkQzF0WVdsdUlITjBjbTl1Wnl3S0xtSXhPUzF3Y205cVpXTjBMV05oY21RZ2MzUnliMjVuTEFvdVlqRTVMV052Ym5SaFkzUXRiV0ZwYmlCemJXRnNiQ3dLTG1JeE9TMXdjbTlxWldOMExXTmhjbVFnYzIxaGJHd2dld29nSUNBZ1pHbHpjR3hoZVRvZ1lteHZZMnNnSVdsdGNHOXlkR0Z1ZERzS0lDQWdJSGRwWkhSb09pQmhkWFJ2SUNGcGJYQnZjblJoYm5RN0NpQWdJQ0J0YVc0dGQybGtkR2c2SURBZ0lXbHRjRzl5ZEdGdWREc0tJQ0FnSUc5MlpYSm1iRzkzT2lCMmFYTnBZbXhsSUNGcGJYQnZjblJoYm5RN0NpQWdJQ0JqYjJ4dmNqb2dJekZrTWprek9TQWhhVzF3YjNKMFlXNTBPd29nSUNBZ2RHVjRkQzF2ZG1WeVpteHZkem9nWTJ4cGNDQWhhVzF3YjNKMFlXNTBPd29nSUNBZ2QyaHBkR1V0YzNCaFkyVTZJRzV2Y20xaGJDQWhhVzF3YjNKMFlXNTBPd29nSUNBZ2IzWmxjbVpzYjNjdGQzSmhjRG9nWVc1NWQyaGxjbVVnSVdsdGNHOXlkR0Z1ZERzS0lDQWdJSGR2Y21RdFluSmxZV3M2SUc1dmNtMWhiQ0FoYVcxd2IzSjBZVzUwT3dwOUNnb3VZakU1TFdOdmJuUmhZM1F0YldGcGJpQnpiV0ZzYkN3S0xtSXhPUzF3Y205cVpXTjBMV05oY21RZ2MyMWhiR3dnZXdvZ0lDQWdZMjlzYjNJNklDTTJOamN3T0RVZ0lXbHRjRzl5ZEdGdWREc0tJQ0FnSUd4cGJtVXRhR1ZwWjJoME9pQXhMalFnSVdsdGNHOXlkR0Z1ZERzS2ZRb0tMbUl4T1MxamIyNTBZV04wTFd4cGMzUXNDaTVpTVRrdGNISnZhbVZqZEMxc2FYTjBJSHNLSUNBZ0lHOTJaWEptYkc5M0xYZzZJR2hwWkdSbGJpQWhhVzF3YjNKMFlXNTBPd3A5Q2dvdVlqRTVMWEJ5YjJwbFkzUXRiR2x6ZENCN0NpQWdJQ0JuY21sa0xYUmxiWEJzWVhSbExXTnZiSFZ0Ym5NNklISmxjR1ZoZENneUxDQnRhVzV0WVhnb01Dd2dNV1p5S1NrZ0lXbHRjRzl5ZEdGdWREc0tmUW9LTG1JeE9TMXdjbWx0WVhKNUxXTm9iMmxqWlNCN0NpQWdJQ0JtYkdWNE9pQXdJREFnWVhWMGJ5QWhhVzF3YjNKMFlXNTBPd29nSUNBZ2QybGtkR2c2SUdGMWRHOGdJV2x0Y0c5eWRHRnVkRHNLSUNBZ0lIZG9hWFJsTFhOd1lXTmxPaUJ1YjNkeVlYQWdJV2x0Y0c5eWRHRnVkRHNLZlFvS1FHMWxaR2xoSUNodFlYZ3RkMmxrZEdnNklEYzJNSEI0S1NCN0NpQWdJQ0F1WWpFNUxYQnliMnBsWTNRdGJHbHpkQ0I3Q2lBZ0lDQWdJQ0FnWjNKcFpDMTBaVzF3YkdGMFpTMWpiMngxYlc1ek9pQXhabklnSVdsdGNHOXlkR0Z1ZERzS0lDQWdJSDBLZlFvPSJ9fQ==';
    $decoded = base64_decode($encoded, true);
    if (!is_string($decoded) || hash('sha256', $decoded) !== '5beeb7777fe6755151f3f5cb06ffd264db02f41e9273a694c2ce7a2ba624d098') {
        throw new RuntimeException('Повреждён пакет исправления popup-окон.');
    }
    $payload = json_decode($decoded, true);
    if (!is_array($payload)) {
        throw new RuntimeException('Не удалось декодировать пакет исправления popup-окон.');
    }

    $result = [];
    foreach ($payload as $key => $item) {
        $content = base64_decode((string) ($item['content'] ?? ''), true);
        if (!is_string($content) || hash('sha256', $content) !== (string) ($item['sha256'] ?? '')) {
            throw new RuntimeException('Повреждён компонент popup-hotfix: ' . $key);
        }
        $result[$key] = $content;
    }
    return $result;
}

function ui21bustAsset(string $content, string $asset): array
{
    $pattern = '#(' . preg_quote($asset, '#') . ')(?:\?[^"\'\s<>]*)?#i';
    $updated = preg_replace_callback(
        $pattern,
        static fn(array $match): string =>
            $match[1] . '?v=' . UI_POPUP_FIX_ASSET_VERSION,
        $content,
        -1,
        $count
    );
    if (!is_string($updated)) {
        throw new RuntimeException('Не удалось обновить версию ресурса ' . $asset . '.');
    }
    return [$updated, (int) $count];
}
$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$appJsPath = $root . '/assets/app.js';
$appCssPath = $root . '/assets/app.css';

foreach ([$indexPath, $appJsPath, $appCssPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Не найден обязательный файл: ' . $path);
    }
}

$appJsBefore = ui21read($appJsPath);
if (!str_contains($appJsBefore, 'PORTAL_UI_HOTFIX_V180320')) {
    throw new RuntimeException(
        'Версия ' . UI_POPUP_FIX_VERSION
        . ' требует установленную 2026.08.03.20.'
    );
}

$components = ui21payload();
$backupDirectory = $root . '/storage/backups/ui-popup-v21-'
    . date('Ymd-His') . '-' . bin2hex(random_bytes(3));
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию popup-hotfix.');
}

$tracked = [
    'index.php',
    'assets/app.js',
    'assets/app.css',
];
foreach ($tracked as $relative) {
    $source = $root . '/' . $relative;
    $destination = $backupDirectory . '/' . $relative;
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог резервной копии.');
    }
    if (!copy($source, $destination)) {
        throw new RuntimeException('Не удалось сохранить резервную копию: ' . $relative);
    }
}

try {
    $appJs = $appJsBefore;
    $oldClose = "        if (action === 'close-modal') return closeModal();";
    $newClose = <<<'JS'
        if (action === 'close-modal') {
            /* LK2_MODAL_DIRECT_CLOSE_V21 */
            if (
                target.classList.contains('lk2-modal-backdrop')
                && event.target !== target
            ) {
                return;
            }
            return closeModal();
        }
JS;

    if (!str_contains($appJs, 'LK2_MODAL_DIRECT_CLOSE_V21')) {
        $count = substr_count($appJs, $oldClose);
        if ($count !== 1) {
            throw new RuntimeException(
                'Не удалось однозначно найти обработчик закрытия LK.2: '
                . $count
            );
        }
        $appJs = str_replace($oldClose, $newClose, $appJs, $replaceCount);
        if ($replaceCount !== 1) {
            throw new RuntimeException('Не удалось заменить обработчик закрытия LK.2.');
        }
        ui21write($appJsPath, $appJs);
    }

    $appCss = ui21read($appCssPath);
    if (!str_contains($appCss, UI_POPUP_FIX_MARKER)) {
        $appCss = rtrim($appCss) . PHP_EOL . PHP_EOL
            . trim($components['css']) . PHP_EOL;
        ui21write($appCssPath, $appCss);
    }

    $index = ui21read($indexPath);
    [$index, $cssCount] = ui21bustAsset($index, 'assets/app.css');
    [$index, $jsCount] = ui21bustAsset($index, 'assets/app.js');
    if ($cssCount <= 0 || $jsCount <= 0) {
        throw new RuntimeException(
            'Не удалось найти ссылки на assets/app.css и assets/app.js в index.php.'
        );
    }
    ui21write($indexPath, $index);

    ui21lint($indexPath);

    if (function_exists('exec')) {
        $probeOutput = [];
        $probeCode = 0;
        exec(
            'node -e ' . escapeshellarg(
                "const value = null; value?.x; const y = value ?? 1;"
            ) . ' 2>&1',
            $probeOutput,
            $probeCode
        );
        if ($probeCode === 0) {
            $nodeOutput = [];
            $nodeCode = 0;
            exec(
                'node --check ' . escapeshellarg($appJsPath) . ' 2>&1',
                $nodeOutput,
                $nodeCode
            );
            if ($nodeCode !== 0) {
                throw new RuntimeException(
                    'Ошибка JavaScript popup-hotfix: ' . implode("\n", $nodeOutput)
                );
            }
        } else {
            ui21out(
                'Проверка JavaScript через Node.js пропущена: '
                . 'серверная версия Node.js устарела.'
            );
        }
    }

    $finalJs = ui21read($appJsPath);
    $finalCss = ui21read($appCssPath);
    $finalIndex = ui21read($indexPath);

    if (substr_count($finalJs, 'LK2_MODAL_DIRECT_CLOSE_V21') !== 1) {
        throw new RuntimeException('Обработчик модального окна LK.2 подключён некорректно.');
    }
    if (substr_count($finalCss, UI_POPUP_FIX_MARKER) !== 1) {
        throw new RuntimeException('Стили popup-hotfix подключены некорректно.');
    }
    if (!str_contains($finalIndex, 'assets/app.css?v=' . UI_POPUP_FIX_ASSET_VERSION)) {
        throw new RuntimeException('Версия app.css не обновлена.');
    }
    if (!str_contains($finalIndex, 'assets/app.js?v=' . UI_POPUP_FIX_ASSET_VERSION)) {
        throw new RuntimeException('Версия app.js не обновлена.');
    }
    if (str_contains($finalJs, $oldClose)) {
        throw new RuntimeException('Старый обработчик закрытия LK.2 остался в app.js.');
    }

    ui21out('Popup-hotfix установлен.');
    ui21out('- названия контактов и проектов Bitrix24 снова видимы;');
    ui21out('- чекбоксы и радиокнопки больше не растягивают карточки;');
    ui21out('- описания проектов переносятся на несколько строк;');
    ui21out('- окно «Добавить проект» не закрывается при клике по полям;');
    ui21out('- закрытие LK.2 работает только по кнопке или прямому клику по фону;');
    ui21out('- app.css и app.js получили версию ' . UI_POPUP_FIX_ASSET_VERSION . ';');
    ui21out('- резервная копия: ' . $backupDirectory . '.');
} catch (Throwable $exception) {
    foreach ($tracked as $relative) {
        $target = $root . '/' . $relative;
        $backup = $backupDirectory . '/' . $relative;
        if (is_file($backup)) {
            @copy($backup, $target);
        }
    }
    fwrite(STDERR, 'ОШИБКА: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Файлы восстановлены из резервной копии.' . PHP_EOL);
    exit(1);
}
