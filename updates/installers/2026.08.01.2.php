<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function u2replace(string $content, string $needle, string $replacement, string $label): string
{
    $count = 0;
    $content = str_replace($needle, $replacement, $content, $count);

    if ($count !== 1) {
        throw new RuntimeException("Не удалось изменить {$label}: найдено замен {$count}.");
    }

    return $content;
}

function u2write(string $path, string $content): void
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

$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';

if (!is_file($indexPath) || !is_file($jsPath) || !is_file($cssPath)) {
    throw new RuntimeException('Не удалось определить файлы проекта.');
}

$index = (string) file_get_contents($indexPath);
$js = (string) file_get_contents($jsPath);
$css = (string) file_get_contents($cssPath);

if (str_contains($js, 'SYSTEM_UPDATES_CRON_NOTICE_V2')) {
    echo "Оформление статуса Cron worker уже установлено.\n";
    exit(0);
}

$index = u2replace(
    $index,
    '<span class="muted">Worker проверяет очередь каждую минуту</span>',
    '<span id="systemUpdatesCronNotice" class="updates-cron-notice positive"><strong>Cron worker установлен</strong><span>Очередь обновлений проверяется автоматически каждую минуту.</span></span>',
    'уведомление Cron worker'
);

$jsPatch = <<<'JS'
    /* SYSTEM_UPDATES_CRON_NOTICE_V2 */
    const systemUpdatesShowMessageBase = systemUpdatesShowMessage;
    systemUpdatesShowMessage = function(type, text) {
        systemUpdatesShowMessageBase(type, text);
        const notice = $('#systemUpdatesCronNotice');
        if (!notice) return;

        if (type === 'error') {
            notice.className = 'updates-cron-notice negative';
            notice.innerHTML = `<strong>Ошибка Cron worker</strong><span>${escapeHtml(text || 'Worker не отвечает.')}</span>`;
        } else {
            notice.className = 'updates-cron-notice positive';
            notice.innerHTML = '<strong>Cron worker установлен</strong><span>Очередь обновлений проверяется автоматически каждую минуту.</span>';
        }
    };

JS;

$js = u2replace(
    $js,
    '    async function loadDashboard() {',
    $jsPatch . '    async function loadDashboard() {',
    'JavaScript уведомления Cron worker'
);

$css .= <<<'CSS'

/* SYSTEM_UPDATES_CRON_NOTICE_V2 */
.updates-cron-notice {
    display: grid;
    gap: 2px;
    min-width: 290px;
    padding: 10px 13px;
    border: 1px solid;
    border-radius: 11px;
    font-size: 12px;
    line-height: 1.4;
}

.updates-cron-notice strong {
    font-size: 12px;
}

.updates-cron-notice.positive {
    border-color: #a6e0bd;
    background: #ecfdf3;
    color: #067647;
}

.updates-cron-notice.negative {
    border-color: #f5b7b1;
    background: #fef3f2;
    color: #b42318;
}

@media (max-width: 760px) {
    .updates-history-panel .panel-head {
        align-items: stretch;
        flex-direction: column;
    }

    .updates-cron-notice {
        min-width: 0;
        width: 100%;
    }
}
CSS;

$index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=20', $index) ?? $index;
$index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=20', $index) ?? $index;

u2write($indexPath, $index);
u2write($jsPath, $js);
u2write($cssPath, $css);

echo "Статус Cron worker оформлен уведомлением.\n";
echo "Рабочее состояние — зелёное, ошибка — красная.\n";
