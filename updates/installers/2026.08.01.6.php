<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function sc6read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function sc6replace(string $content, string $needle, string $replacement, string $label): string
{
    $count = 0;
    $content = str_replace($needle, $replacement, $content, $count);
    if ($count !== 1) {
        throw new RuntimeException("Не удалось изменить {$label}: найдено замен {$count}.");
    }
    return $content;
}

function sc6write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temporary}");
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

function sc6lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("Ошибка PHP-синтаксиса в {$path}:\n" . implode("\n", $output));
    }
}

$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';

foreach ([$indexPath, $jsPath, $cssPath] as $path) {
    if (!is_file($path)) {
        throw new RuntimeException('Не найден файл проекта: ' . $path);
    }
}

$index = sc6read($indexPath);
$js = sc6read($jsPath);
$css = sc6read($cssPath);

if (str_contains($index, 'SUPPORT_CHAT_MENU_V1') || str_contains($js, 'SUPPORT_CHAT_MENU_V1_JS')) {
    echo "Чат с поддержкой уже добавлен.\n";
    exit(0);
}

$backupDirectory = $root . '/storage/backups/support-chat-menu-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}
copy($indexPath, $backupDirectory . '/index.php');
copy($jsPath, $backupDirectory . '/app.js');
copy($cssPath, $backupDirectory . '/app.css');

try {
    $settingsButton = '                <button class="nav-link" data-section="settings">Настройки</button>';
    $chatButton = <<<'HTML'
                <!-- SUPPORT_CHAT_MENU_V1 -->
                <button
                    type="button"
                    class="nav-link support-chat-menu-link"
                    id="supportChatMenuButton"
                    data-support-chat-url="https://mirsaitov.bitrix24.ru/online/servise"
                    aria-label="Открыть чат с поддержкой"
                >
                    <span>Чат с поддержкой</span>
                    <i aria-hidden="true"></i>
                </button>
HTML;

    $index = sc6replace(
        $index,
        $settingsButton,
        $chatButton . "\n" . $settingsButton,
        'пункт меню чата с поддержкой'
    );

    $jsPatch = <<<'JS'
    /* SUPPORT_CHAT_MENU_V1_JS */
    $('#supportChatMenuButton')?.addEventListener('click', event => {
        const button = event.currentTarget;
        const url = String(button.dataset.supportChatUrl || '').trim();
        if (!url) {
            alert('Ссылка на чат с поддержкой не настроена.');
            return;
        }

        const width = Math.min(500, Math.max(380, window.screen.availWidth - 40));
        const height = Math.min(820, Math.max(620, window.screen.availHeight - 80));
        const left = Math.max(0, window.screenX + window.outerWidth - width - 24);
        const top = Math.max(0, window.screenY + 32);
        const popup = window.open(
            url,
            'mirsaitovSupportChat',
            [
                'popup=yes',
                `width=${width}`,
                `height=${height}`,
                `left=${left}`,
                `top=${top}`,
                'resizable=yes',
                'scrollbars=yes'
            ].join(',')
        );

        if (popup) {
            popup.focus();
            return;
        }

        window.open(url, '_blank', 'noopener,noreferrer');
    });

JS;

    $js = sc6replace(
        $js,
        '    async function loadDashboard() {',
        $jsPatch . '    async function loadDashboard() {',
        'JavaScript чата с поддержкой'
    );

    $css .= <<<'CSS'

/* SUPPORT_CHAT_MENU_V1_CSS */
.support-chat-menu-link {
    display: flex !important;
    align-items: center;
    justify-content: space-between;
    gap: 10px;
}

.support-chat-menu-link i {
    width: 8px;
    height: 8px;
    flex: 0 0 8px;
    border-radius: 50%;
    background: #12b76a;
    box-shadow: 0 0 0 4px rgba(18, 183, 106, 0.14);
}

.support-chat-menu-link:hover i,
.support-chat-menu-link:focus-visible i {
    box-shadow: 0 0 0 6px rgba(18, 183, 106, 0.18);
}
CSS;

    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=24', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=24', $index) ?? $index;

    sc6write($indexPath, $index);
    sc6write($jsPath, $js);
    sc6write($cssPath, $css);

    sc6lint($indexPath);

    echo "Чат с поддержкой добавлен в меню.\n";
    echo "Ссылка: https://mirsaitov.bitrix24.ru/online/servise\n";
    echo "Чат открывается в компактном отдельном окне.\n";
    echo "Резервная копия: {$backupDirectory}\n";
} catch (Throwable $exception) {
    @copy($backupDirectory . '/index.php', $indexPath);
    @copy($backupDirectory . '/app.js', $jsPath);
    @copy($backupDirectory . '/app.css', $cssPath);
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
