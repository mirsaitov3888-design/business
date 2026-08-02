<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function r24out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function r24read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function r24write(string $path, string $content): void
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

function r24lint(string $path): void
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

function r24download(string $url, string $sha): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 120,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Cumulative Update/2026.08.02.5',
            'header' => "Cache-Control: no-cache, no-store\r\nPragma: no-cache\r\n",
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $separator = str_contains($url, '?') ? '&' : '?';
    $body = file_get_contents($url . $separator . 'v=' . rawurlencode((string) microtime(true)), false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Не удалось загрузить компонент: ' . $url);
    }
    if (!hash_equals(strtolower($sha), hash('sha256', $body))) {
        throw new RuntimeException('Не совпала SHA-256 компонента: ' . $url);
    }
    return $body;
}

function r24runPrevious(string $root): void
{
    $workerPath = $root . '/app/Services/SystemUpdateWorker.php';
    $worker = is_file($workerPath) ? (string) file_get_contents($workerPath) : '';
    if (str_contains($worker, 'SYSTEM_UPDATE_DIAGNOSTICS_V180202')) {
        return;
    }

    $url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.02.3.php';
    $sha = '69a05f93fce3fc5f1b8e6164f18fcf8e3b5498d805f4933149d01e395d088e91';
    $body = r24download($url, $sha);
    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-180203-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Не удалось создать временный файл версии 2026.08.02.3.');
    }
    file_put_contents($temporary, $body, LOCK_EX);
    @chmod($temporary, 0600);
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
    @unlink($temporary);
    foreach ($output as $line) {
        r24out($line);
    }
    if ($code !== 0) {
        throw new RuntimeException('Не удалось применить обязательную версию 2026.08.02.3.');
    }
}

function r24dedupeNav(string $html): string
{
    $seen = [];
    $pattern = '~[ \t]*<button\b[^>]*\bclass\s*=\s*["\'][^"\']*\bnav-link\b[^"\']*["\'][^>]*>.*?</button>[ \t]*(?:\r?\n)?~is';
    $result = preg_replace_callback($pattern, static function (array $match) use (&$seen): string {
        $block = $match[0];
        if (!preg_match('~\bdata-section\s*=\s*["\']([^"\']+)["\']~i', $block, $sectionMatch)) {
            return $block;
        }
        $section = strtolower(trim($sectionMatch[1]));
        if ($section === '') {
            return $block;
        }
        if (isset($seen[$section])) {
            return '';
        }
        $seen[$section] = true;
        return $block;
    }, $html);
    return is_string($result) ? $result : $html;
}


function r25resolveOldUpdateNotifications(string $root): void
{
    try {
        $bootstrap = $root . '/app/bootstrap.php';
        if (!is_file($bootstrap)) {
            return;
        }

        require_once $bootstrap;
        $pdo = \SeoAnalytics\Core\Database::pdo();
        if (!$pdo->query("SHOW TABLES LIKE 'notifications'")->fetchColumn()) {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE notifications
             SET status = 'resolved',
                 resolved_at = COALESCE(resolved_at, NOW())
             WHERE status = 'open'
               AND title LIKE :title_prefix"
        );
        $stmt->execute([
            'title_prefix' => 'Ошибка системного обновления %',
        ]);

        if ($stmt->rowCount() > 0) {
            r24out(
                'Закрыто уведомлений о прошлых ошибках обновления: '
                . $stmt->rowCount() . '.'
            );
        }
    } catch (Throwable $exception) {
        r24out(
            'Предупреждение: старое уведомление не удалось закрыть автоматически: '
            . $exception->getMessage()
        );
    }
}

$root = getcwd() ?: '';
$servicePath = $root . '/app/Services/SystemUpdateService.php';
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';

foreach ([$servicePath, $indexPath, $jsPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

r24runPrevious($root);

$service = r24read($servicePath);
$index = r24read($indexPath);
$js = r24read($jsPath);

$alreadyInstalled = str_contains($service, 'SYSTEM_UPDATE_MANIFEST_NO_CACHE_V180204')
    && str_contains($js, 'SYSTEM_UPDATE_PROGRESS_SELECTION_V180205')
    && str_contains($js, 'NAV_RUNTIME_DEDUPE_V180204');
if ($alreadyInstalled) {
    r24out('Исправление обновлений и навигации уже установлено.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/update-cache-navigation-v5-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}
$backupFiles = [
    $servicePath => $backupDirectory . '/SystemUpdateService.php',
    $indexPath => $backupDirectory . '/index.php',
    $jsPath => $backupDirectory . '/app.js',
];
foreach ($backupFiles as $source => $destination) {
    if (!copy($source, $destination)) {
        throw new RuntimeException('Не удалось сохранить резервную копию: ' . basename($source));
    }
}

try {
    if (!str_contains($service, 'SYSTEM_UPDATE_MANIFEST_NO_CACHE_V180204')) {
        $needle = "        \$body = \$this->download(\$url, 30, 1024 * 1024);\n";
        $replacement = "        /* SYSTEM_UPDATE_MANIFEST_NO_CACHE_V180204 */\n"
            . "        \$separator = str_contains(\$url, '?') ? '&' : '?';\n"
            . "        \$url .= \$separator . '_=' . rawurlencode((string) microtime(true));\n"
            . "        \$body = \$this->download(\$url, 30, 1024 * 1024);\n";
        $service = str_replace($needle, $replacement, $service, $count);
        if ($count !== 1) {
            throw new RuntimeException('Не удалось включить обновление манифеста без кэша.');
        }

    }

    $index = r24dedupeNav($index);

    if (!str_contains($js, 'SYSTEM_UPDATE_PROGRESS_SELECTION_V180205')) {
        $js .= <<<'JSPATCH'

/* SYSTEM_UPDATE_PROGRESS_SELECTION_V180205 */
(() => {
    const installProgressSelectionWrapper = () => {
        if (typeof systemUpdatesRenderProgress !== 'function') return;
        if (systemUpdatesRenderProgress.__selectionWrappedV180205) return;

        const original = systemUpdatesRenderProgress;
        const wrapped = function(rows) {
            const list = Array.isArray(rows) ? [...rows] : [];
            if (!list.length) return original.call(this, list);

            const active = list.find(item => [
                'queued',
                'installing',
                'rollback_queued',
                'rolling_back'
            ].includes(String(item?.status || '')));

            const successful = list.find(item => [
                'installed',
                'rolled_back'
            ].includes(String(item?.status || '')));

            const preferred = active || successful || list[0];
            const ordered = preferred
                ? [preferred, ...list.filter(item => item !== preferred)]
                : list;

            return original.call(this, ordered);
        };

        wrapped.__selectionWrappedV180205 = true;
        try {
            systemUpdatesRenderProgress = wrapped;
        } catch (_) {
        }
    };

    installProgressSelectionWrapper();
    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            installProgressSelectionWrapper,
            {once: true}
        );
    }
})();
JSPATCH;
    }

    if (!str_contains($js, 'NAV_RUNTIME_DEDUPE_V180204')) {
        $patch = <<<'JSPATCH'

/* NAV_RUNTIME_DEDUPE_V180204 */
(() => {
    function dedupeNavigation() {
        const seen = new Set();
        document.querySelectorAll('.sidebar .nav-link[data-section]').forEach(item => {
            const section = String(item.dataset.section || '').trim();
            if (!section) return;
            if (seen.has(section)) {
                item.remove();
                return;
            }
            seen.add(section);
        });
    }

    let scheduled = false;
    const schedule = () => {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
            scheduled = false;
            dedupeNavigation();
        });
    };

    const boot = () => {
        dedupeNavigation();
        new MutationObserver(schedule).observe(document.body, {
            childList: true,
            subtree: true
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
JSPATCH;
        $js .= $patch;
    }

    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=180205', $index) ?? $index;

    r24write($servicePath, $service);
    r24write($indexPath, $index);
    r24write($jsPath, $js);
    r24lint($servicePath);
    r24lint($indexPath);

    r25resolveOldUpdateNotifications($root);

    r24out('Кэш обновлений, верхняя карточка и дубли меню исправлены.');
    r24out('- применена автоматическая диагностика после обновлений;');
    r24out('- манифест теперь запрашивается с уникальным параметром и no-cache;');
    r24out('- верхняя карточка показывает активную или последнюю успешную операцию;');
    r24out('- повторяющиеся пункты меню удалены из HTML и контролируются в браузере;');
    r24out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $source) {
        if (is_file($source)) {
            @copy($source, $destination);
        }
    }
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
