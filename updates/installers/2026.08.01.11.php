<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function ui11out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function ui11read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function ui11write(string $path, string $content): void
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

function ui11lint(string $path): void
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

function ui11dedupeNav(string $html): string
{
    $seenSections = [];
    $seenIds = [];
    $pattern = '~[ \t]*<button\b[^>]*>.*?</button>[ \t]*(?:\r?\n)?~is';

    $result = preg_replace_callback($pattern, static function (array $match) use (&$seenSections, &$seenIds): string {
        $block = $match[0];
        $isNav = preg_match('~\bclass\s*=\s*["\'][^"\']*\bnav-link\b[^"\']*["\']~i', $block) === 1;
        if (!$isNav) {
            return $block;
        }

        if (preg_match('~\bdata-section\s*=\s*["\']([^"\']+)["\']~i', $block, $sectionMatch)) {
            $section = strtolower(trim($sectionMatch[1]));
            if (isset($seenSections[$section])) {
                return '';
            }
            $seenSections[$section] = true;
        }

        if (preg_match('~\bid\s*=\s*["\']([^"\']+)["\']~i', $block, $idMatch)) {
            $id = trim($idMatch[1]);
            if ($id === 'supportChatMenuButton') {
                if (isset($seenIds[$id])) {
                    return '';
                }
                $seenIds[$id] = true;
            }
        }

        return $block;
    }, $html);

    return is_string($result) ? $result : $html;
}

function ui11sectionEnd(string $html, int $start): ?int
{
    $fragment = substr($html, $start);
    if (!is_string($fragment)) {
        return null;
    }
    preg_match_all('~</?section\b[^>]*>~i', $fragment, $tokens, PREG_OFFSET_CAPTURE);
    $depth = 0;
    foreach ($tokens[0] ?? [] as $token) {
        $markup = (string) $token[0];
        $offset = (int) $token[1];
        if (str_starts_with(strtolower($markup), '</section')) {
            $depth--;
            if ($depth === 0) {
                return $start + $offset + strlen($markup);
            }
        } else {
            $depth++;
        }
    }
    return null;
}

function ui11dedupeSections(string $html, array $ids): string
{
    foreach ($ids as $id) {
        $quoted = preg_quote($id, '~');
        $pattern = '~<section\b[^>]*\bid\s*=\s*["\']' . $quoted . '["\'][^>]*>~i';
        while (true) {
            preg_match_all($pattern, $html, $matches, PREG_OFFSET_CAPTURE);
            if (count($matches[0] ?? []) <= 1) {
                break;
            }
            $start = (int) $matches[0][1][1];
            $end = ui11sectionEnd($html, $start);
            if ($end === null || $end <= $start) {
                break;
            }
            $html = substr($html, 0, $start) . substr($html, $end);
        }
    }
    return $html;
}

function ui11ensureCron(string $workerPath, string $logPath): bool
{
    if (!function_exists('exec')) {
        return false;
    }

    $check = [];
    $checkCode = 0;
    exec('command -v crontab 2>/dev/null', $check, $checkCode);
    if ($checkCode !== 0 || trim(implode('', $check)) === '') {
        return false;
    }

    $current = [];
    $currentCode = 0;
    exec('crontab -l 2>/dev/null', $current, $currentCode);
    $filtered = [];
    foreach ($current as $line) {
        if (str_contains($line, $workerPath)) {
            continue;
        }
        $filtered[] = $line;
    }

    $filtered[] = '*/5 * * * * '
        . escapeshellarg(PHP_BINARY)
        . ' '
        . escapeshellarg($workerPath)
        . ' >> '
        . escapeshellarg($logPath)
        . ' 2>&1';

    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-monitor-cron-');
    if (!is_string($temporary)) {
        return false;
    }
    file_put_contents($temporary, implode(PHP_EOL, $filtered) . PHP_EOL, LOCK_EX);
    $installOutput = [];
    $installCode = 0;
    exec('crontab ' . escapeshellarg($temporary) . ' 2>&1', $installOutput, $installCode);
    @unlink($temporary);

    return $installCode === 0;
}

$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$repositoryPath = $root . '/app/Repositories/MonitoringRepository.php';
$workerPath = $root . '/bin/site_monitor_worker.php';
$dataRoot = dirname($root, 2);
$workerLogPath = $dataRoot . '/site-monitor-worker.log';

foreach ([$indexPath, $jsPath, $cssPath, $repositoryPath, $workerPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$index = ui11read($indexPath);
$js = ui11read($jsPath);
$css = ui11read($cssPath);
$repository = ui11read($repositoryPath);

if (str_contains($js, 'GLOBAL_LAYOUT_CONTEXT_V11')) {
    ui11out('Исправление интерфейса уже установлено.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/layout-worker-hotfix-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$backupFiles = [
    $indexPath => 'index.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
    $repositoryPath => 'MonitoringRepository.php',
];
foreach ($backupFiles as $source => $name) {
    if (!copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException("Не удалось сохранить резервную копию {$name}");
    }
}

try {
    $index = ui11dedupeNav($index);
    $index = ui11dedupeSections($index, [
        'section-system-updates',
        'section-support-reports',
        'section-site-monitoring',
        'section-bitrix24',
    ]);

    $workerStatusPattern = '~    public function workerStatus\(\): array\s*\{.*?\n    \}\n\n    public function cleanup\(\): void~s';
    $workerStatusReplacement = <<<'PHPBLOCK'
    public function workerStatus(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT *, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS age_seconds
             FROM monitor_worker_state
             WHERE state_key = \'heartbeat\'
             LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return [
                'status' => 'unknown',
                'updated_at' => null,
                'age_seconds' => null,
                'details' => null,
            ];
        }

        $details = $this->decodeValue($row['state_value']);
        $details = is_array($details) ? $details : [];
        $age = max(0, (int) ($row['age_seconds'] ?? 0));
        $detailStatus = (string) ($details['status'] ?? '');

        if ($age > 900) {
            $status = 'stale';
        } elseif ($detailStatus === 'error') {
            $status = 'error';
        } elseif ($detailStatus === 'warning') {
            $status = 'warning';
        } else {
            $status = 'ok';
        }

        return [
            'status' => $status,
            'updated_at' => $row['updated_at'],
            'age_seconds' => $age,
            'details' => $details,
        ];
    }

    public function cleanup(): void
PHPBLOCK;
    $repositoryUpdated = preg_replace(
        $workerStatusPattern,
        $workerStatusReplacement,
        $repository,
        -1,
        $repositoryReplacements
    );
    if (!is_string($repositoryUpdated) || $repositoryReplacements < 1) {
        throw new RuntimeException('Не удалось обновить диагностику monitoring worker.');
    }
    $repository = $repositoryUpdated;

    $workerRenderPattern = '~    function monitoringRenderWorker\(worker = \{\}\) \{.*?\n    \}\n\n    function monitoringRenderSites~s';
    $workerRenderReplacement = <<<'JSBLOCK'
    function monitoringRenderWorker(worker = {}) {
        const root = $('#monitoringWorkerStatus');
        if (!root) return;
        const details = worker.details || {};
        const status = worker.status || 'unknown';
        const age = Number(worker.age_seconds || 0);
        const errors = Array.isArray(details.errors) ? details.errors : [];

        root.className = `monitoring-worker ${
            status === 'ok' ? 'positive' :
            status === 'warning' ? 'warning' :
            ['error', 'stale'].includes(status) ? 'negative' : 'unknown'
        }`;

        if (status === 'ok') {
            root.innerHTML = `<strong>Worker работает</strong><span>Последний запуск: ${escapeHtml(monitoringDate(worker.updated_at))}</span>`;
        } else if (status === 'warning') {
            root.innerHTML = `<strong>Worker работает с предупреждениями</strong><span>${escapeHtml(errors[0] || `Последний запуск: ${monitoringDate(worker.updated_at)}`)}</span>`;
        } else if (status === 'error') {
            root.innerHTML = `<strong>Ошибка worker</strong><span>${escapeHtml(details.error || 'Проверьте журнал monitoring worker.')}</span>`;
        } else if (status === 'stale') {
            const minutes = Math.max(1, Math.round(age / 60));
            root.innerHTML = `<strong>Worker не запускался</strong><span>Последняя активность ${minutes} мин. назад: ${escapeHtml(monitoringDate(worker.updated_at))}</span>`;
        } else {
            root.innerHTML = '<strong>Worker запускается</strong><span>Первый фоновый запуск ещё не зафиксирован.</span>';
        }
    }

    function monitoringRenderSites
JSBLOCK;
    $jsUpdated = preg_replace(
        $workerRenderPattern,
        $workerRenderReplacement,
        $js,
        -1,
        $workerRenderReplacements
    );
    if (!is_string($jsUpdated) || $workerRenderReplacements < 1) {
        throw new RuntimeException('Не удалось обновить отображение monitoring worker.');
    }
    $js = $jsUpdated;

    $layoutPatch = <<<'JSPATCH'
    /* GLOBAL_LAYOUT_CONTEXT_V11 */
    const serviceSectionLabels = {
        dashboard: ['Сервис', 'Обзор'],
        analyst: ['Сервис', 'AI-аналитик'],
        webmaster: ['Сервис', 'SEO', 'Яндекс Вебмастер'],
        reports: ['Сервис', 'Отчётность', 'Отчёты'],
        availability: ['Сервис', 'Техническая поддержка', 'Доступность сайта'],
        bitrix24: ['Сервис', 'Интеграции', 'Битрикс24'],
        'system-updates': ['Сервис', 'Система', 'Обновления'],
        'support-reports': ['Сервис', 'Техническая поддержка', 'Отчёты поддержки'],
        'site-monitoring': ['Сервис', 'Техническая поддержка', 'Мониторинг сайтов'],
        settings: ['Сервис', 'Настройки']
    };

    function serviceDedupeRuntime() {
        const seenSections = new Set();
        document.querySelectorAll('.sidebar .nav-link[data-section]').forEach(item => {
            const section = String(item.dataset.section || '').trim();
            if (!section) return;
            if (seenSections.has(section)) {
                item.remove();
                return;
            }
            seenSections.add(section);
        });

        const chatButtons = document.querySelectorAll('#supportChatMenuButton');
        chatButtons.forEach((item, index) => {
            if (index > 0) item.remove();
        });

        [
            'section-system-updates',
            'section-support-reports',
            'section-site-monitoring',
            'section-bitrix24'
        ].forEach(id => {
            document.querySelectorAll(`[id="${id}"]`).forEach((item, index) => {
                if (index > 0) item.remove();
            });
        });
    }

    function serviceMonitoringTabLabel() {
        const active = document.querySelector('#section-site-monitoring [data-monitoring-tab].is-active');
        return active ? String(active.textContent || '').trim() : '';
    }

    function serviceEnsureBreadcrumbs(sectionName) {
        const section = document.querySelector(`#section-${sectionName}`);
        if (!section) return;
        let breadcrumbs = section.querySelector(':scope > .service-breadcrumbs');
        if (!breadcrumbs) {
            breadcrumbs = document.createElement('nav');
            breadcrumbs.className = 'service-breadcrumbs';
            breadcrumbs.setAttribute('aria-label', 'Хлебные крошки');
            section.prepend(breadcrumbs);
        }

        const labels = [...(serviceSectionLabels[sectionName] || ['Сервис'])];
        if (sectionName === 'site-monitoring') {
            const tab = serviceMonitoringTabLabel();
            if (tab && tab !== 'Обзор') labels.push(tab);
        }

        breadcrumbs.innerHTML = labels.map((label, index) => {
            const last = index === labels.length - 1;
            if (index === 0 && !last) {
                return `<button type="button" data-breadcrumb-section="dashboard">${escapeHtml(label)}</button><i aria-hidden="true">/</i>`;
            }
            return `<span class="${last ? 'is-current' : ''}">${escapeHtml(label)}</span>${last ? '' : '<i aria-hidden="true">/</i>'}`;
        }).join('');
    }

    function serviceApplySectionContext(sectionName) {
        const name = String(sectionName || '').trim();
        if (!name) return;
        document.body.classList.toggle('service-hide-global-topbar', name === 'site-monitoring');
        document.body.dataset.activeServiceSection = name;
        serviceEnsureBreadcrumbs(name);
    }

    function serviceDetectActiveSection() {
        const activeNav = document.querySelector('.sidebar .nav-link.active[data-section], .sidebar .nav-link.is-active[data-section]');
        if (activeNav) return String(activeNav.dataset.section || '');

        const sections = Array.from(document.querySelectorAll('.section[id^="section-"]'));
        const visible = sections.find(item => {
            const style = window.getComputedStyle(item);
            return style.display !== 'none' && !item.hidden && !item.classList.contains('hidden');
        });
        return visible ? String(visible.id || '').replace(/^section-/, '') : 'dashboard';
    }

    serviceDedupeRuntime();
    setTimeout(() => serviceApplySectionContext(serviceDetectActiveSection()), 0);

    document.addEventListener('click', event => {
        const nav = event.target.closest('.sidebar .nav-link[data-section]');
        if (nav) {
            setTimeout(() => serviceApplySectionContext(nav.dataset.section), 0);
        }

        const breadcrumb = event.target.closest('[data-breadcrumb-section]');
        if (breadcrumb) {
            const sectionName = breadcrumb.dataset.breadcrumbSection;
            document.querySelector(`.sidebar .nav-link[data-section="${sectionName}"]`)?.click();
        }

        if (event.target.closest('#section-site-monitoring [data-monitoring-tab]')) {
            setTimeout(() => serviceEnsureBreadcrumbs('site-monitoring'), 0);
        }
    }, true);

    const serviceLayoutObserver = new MutationObserver(() => {
        serviceDedupeRuntime();
        serviceApplySectionContext(serviceDetectActiveSection());
    });
    const serviceSectionsRoot = document.querySelector('main') || document.body;
    serviceLayoutObserver.observe(serviceSectionsRoot, {
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'hidden']
    });

JSPATCH;

    $anchor = '    async function loadDashboard() {';
    $position = strpos($js, $anchor);
    if ($position === false) {
        throw new RuntimeException('Не найдена точка вставки хлебных крошек.');
    }
    $js = substr($js, 0, $position) . $layoutPatch . substr($js, $position);

    $css .= <<<'CSS'

/* GLOBAL_LAYOUT_CONTEXT_V11_CSS */
body.service-hide-global-topbar .topbar {
    display: none !important;
}

.service-breadcrumbs {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 7px;
    margin: 0 0 16px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.4;
}

.service-breadcrumbs button {
    padding: 0;
    border: 0;
    background: transparent;
    color: #2563eb;
    font: inherit;
    cursor: pointer;
}

.service-breadcrumbs button:hover,
.service-breadcrumbs button:focus-visible {
    text-decoration: underline;
}

.service-breadcrumbs i {
    color: #98a2b3;
    font-style: normal;
}

.service-breadcrumbs .is-current {
    color: var(--text);
    font-weight: 700;
}

.monitoring-worker.warning {
    border-color: #f1d38a;
    background: #fffbeb;
    color: #854d0e;
}

@media (max-width: 760px) {
    .service-breadcrumbs {
        margin-bottom: 12px;
    }
}
CSS;

    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=31', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=31', $index) ?? $index;

    ui11write($indexPath, $index);
    ui11write($jsPath, $js);
    ui11write($cssPath, $css);
    ui11write($repositoryPath, $repository);

    ui11lint($indexPath);
    ui11lint($repositoryPath);

    $cronInstalled = ui11ensureCron($workerPath, $workerLogPath);

    require $root . '/app/bootstrap.php';
    $repositoryInstance = new \SeoAnalytics\Repositories\MonitoringRepository();
    $repositoryInstance->setWorkerState('heartbeat', $cronInstalled
        ? [
            'status' => 'scheduled',
            'message' => 'Cron monitoring worker проверен и запланирован каждые 5 минут.',
        ]
        : [
            'status' => 'error',
            'error' => 'Не удалось автоматически настроить Cron monitoring worker.',
        ]
    );

    if ($cronInstalled && function_exists('exec')) {
        $command = 'nohup '
            . escapeshellarg(PHP_BINARY)
            . ' '
            . escapeshellarg($workerPath)
            . ' >> '
            . escapeshellarg($workerLogPath)
            . ' 2>&1 < /dev/null &';
        exec($command);
    }

    ui11out('Исправление интерфейса и worker установлено.');
    ui11out('- удалены дубли пунктов меню и разделов;');
    ui11out('- добавлены хлебные крошки;');
    ui11out('- на странице мониторинга скрыты общий заголовок и фильтры периода;');
    ui11out('- статус worker рассчитывается без ошибки часового пояса;');
    ui11out('- Cron monitoring worker проверен и восстановлен;');
    ui11out('- ошибки worker теперь показывают реальную причину.');
    ui11out('Cron: ' . ($cronInstalled ? 'настроен' : 'ошибка настройки'));
    ui11out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $name) {
        @copy($backupDirectory . '/' . $name, $destination);
    }
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
