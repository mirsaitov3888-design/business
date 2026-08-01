<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function gdsRead(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function gdsWrite(string $path, string $content): void
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

function gdsLint(string $path): void
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

foreach ([$indexPath, $jsPath, $cssPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$index = gdsRead($indexPath);
$js = gdsRead($jsPath);
$css = gdsRead($cssPath);

if (str_contains($js, 'GLOBAL_DATA_STATE_V1')) {
    echo "Единые состояния загрузки уже установлены.\n";
    exit(0);
}

$backupDirectory = $root . '/storage/backups/global-data-state-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}
copy($indexPath, $backupDirectory . '/index.php');
copy($jsPath, $backupDirectory . '/app.js');
copy($cssPath, $backupDirectory . '/app.css');

$jsPatch = <<<'JS'

/* GLOBAL_DATA_STATE_V1 */
(() => {
    const nativeFetch = window.fetch.bind(window);
    const sectionState = new Map();
    let requestId = 0;

    function visibleSection() {
        const sections = Array.from(document.querySelectorAll('.section'));
        return sections.find(section => {
            if (section.hidden) return false;
            const style = window.getComputedStyle(section);
            return style.display !== 'none' && style.visibility !== 'hidden';
        }) || sections[0] || null;
    }

    function ensureStateBox(section) {
        if (!section) return null;
        let box = section.querySelector(':scope > .global-data-state');
        if (box) return box;

        box = document.createElement('div');
        box.className = 'global-data-state hidden';
        box.setAttribute('role', 'status');
        box.setAttribute('aria-live', 'polite');

        const anchor = section.firstElementChild;
        if (anchor && anchor.nextSibling) {
            section.insertBefore(box, anchor.nextSibling);
        } else {
            section.prepend(box);
        }
        return box;
    }

    function setState(section, type, title, text = '') {
        const box = ensureStateBox(section);
        if (!box) return;
        box.className = `global-data-state ${type}`;
        box.innerHTML = `
            <span class="global-data-state-icon" aria-hidden="true"></span>
            <span class="global-data-state-copy">
                <strong>${escapeState(title)}</strong>
                ${text ? `<small>${escapeState(text)}</small>` : ''}
            </span>`;
    }

    function hideState(section) {
        const box = ensureStateBox(section);
        if (box) box.className = 'global-data-state hidden';
    }

    function escapeState(value) {
        return String(value ?? '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function isPlaceholderValue(value) {
        const normalized = String(value ?? '').trim().toLowerCase();
        return normalized === ''
            || normalized === '—'
            || normalized === '-'
            || normalized === '…'
            || normalized === 'нет данных'
            || normalized === 'загрузка';
    }

    function prepareMetrics(section) {
        section.querySelectorAll('.metric-card').forEach(card => {
            const value = card.querySelector('strong');
            const note = card.querySelector('small');
            if (!value || !isPlaceholderValue(value.textContent)) return;

            value.textContent = '0';
            value.classList.add('global-data-placeholder-value');
            card.classList.add('global-data-placeholder');

            if (note) {
                if (!note.dataset.globalOriginalText) {
                    note.dataset.globalOriginalText = note.textContent || '';
                }
                note.textContent = 'Загрузка данных';
            }
        });
    }

    function prepareTables(section) {
        section.querySelectorAll('table').forEach(table => {
            const body = table.tBodies[0];
            if (!body) return;
            const rows = Array.from(body.rows);
            const hasMeaningfulRow = rows.some(row => {
                if (row.classList.contains('global-data-loading-row')) return false;
                const text = (row.textContent || '').trim().toLowerCase();
                return text !== ''
                    && !text.includes('не загруж')
                    && !text.includes('пока не')
                    && !text.includes('нет данных')
                    && !text.includes('настройте');
            });
            if (hasMeaningfulRow) return;

            const columns = Math.max(1, table.tHead?.rows?.[0]?.cells?.length || 1);
            body.innerHTML = `
                <tr class="global-data-loading-row">
                    <td colspan="${columns}">
                        <div class="global-data-inline-state loading">
                            <span class="global-data-inline-spinner"></span>
                            <span>Загрузка данных</span>
                        </div>
                    </td>
                </tr>`;
        });
    }

    function prepareCharts(section) {
        section.querySelectorAll('[id*="Chart"], .chart-container, .chart-shell, .report-chart').forEach(chart => {
            if (chart.querySelector('svg, canvas, img, .global-data-chart-state')) return;
            if ((chart.textContent || '').trim() !== '') return;
            chart.innerHTML = `
                <div class="global-data-chart-state loading">
                    <span class="global-data-inline-spinner"></span>
                    <strong>0</strong>
                    <small>Загрузка данных</small>
                </div>`;
        });
    }

    function prepareSection(section) {
        if (!section) return;
        section.classList.add('global-data-is-loading');
        prepareMetrics(section);
        prepareTables(section);
        prepareCharts(section);
        setState(section, 'loading', 'Загрузка данных', 'Получаем актуальные показатели из подключённых источников.');
    }

    function finalizePlaceholders(section, emptyText = 'Данных за выбранный период нет') {
        let hasEmpty = false;

        section.querySelectorAll('.global-data-placeholder').forEach(card => {
            hasEmpty = true;
            card.classList.remove('global-data-placeholder');
            const value = card.querySelector('strong');
            const note = card.querySelector('small');
            value?.classList.remove('global-data-placeholder-value');
            if (value && isPlaceholderValue(value.textContent)) value.textContent = '0';
            if (note) note.textContent = emptyText;
        });

        section.querySelectorAll('.global-data-loading-row').forEach(row => {
            hasEmpty = true;
            const state = row.querySelector('.global-data-inline-state');
            if (state) {
                state.className = 'global-data-inline-state empty';
                state.innerHTML = `<span class="global-data-inline-empty">0</span><span>${escapeState(emptyText)}</span>`;
            }
            row.classList.remove('global-data-loading-row');
            row.classList.add('global-data-empty-row');
        });

        section.querySelectorAll('.global-data-chart-state.loading').forEach(state => {
            hasEmpty = true;
            state.className = 'global-data-chart-state empty';
            state.innerHTML = `<strong>0</strong><small>${escapeState(emptyText)}</small>`;
        });

        section.classList.remove('global-data-is-loading');
        return hasEmpty;
    }

    function begin(section) {
        if (!section) return 0;
        const current = sectionState.get(section) || {pending: 0, errors: []};
        current.pending += 1;
        sectionState.set(section, current);
        if (current.pending === 1) prepareSection(section);
        return ++requestId;
    }

    function finish(section, errorText = '') {
        if (!section) return;
        const current = sectionState.get(section) || {pending: 1, errors: []};
        if (errorText) current.errors.push(errorText);
        current.pending = Math.max(0, current.pending - 1);
        sectionState.set(section, current);

        if (current.pending > 0) return;

        if (current.errors.length) {
            finalizePlaceholders(section, 'Данные не получены');
            setState(
                section,
                'error',
                'Ошибка загрузки данных',
                current.errors[current.errors.length - 1]
            );
            current.errors = [];
            return;
        }

        const hasEmpty = finalizePlaceholders(section);
        if (hasEmpty) {
            setState(section, 'empty', 'Данных пока нет', 'Показатели будут обновлены после получения данных от источника.');
        } else {
            hideState(section);
        }
    }

    function isApiRequest(input) {
        const url = typeof input === 'string'
            ? input
            : (input && typeof input.url === 'string' ? input.url : '');
        return url.includes('api.php');
    }

    window.fetch = async function(...args) {
        if (!isApiRequest(args[0])) {
            return nativeFetch(...args);
        }

        const section = visibleSection();
        begin(section);

        try {
            const response = await nativeFetch(...args);
            let errorText = '';

            if (!response.ok) {
                errorText = `HTTP ${response.status}`;
            } else {
                try {
                    const contentType = response.headers.get('content-type') || '';
                    if (contentType.includes('application/json')) {
                        const payload = await response.clone().json();
                        if (payload && payload.error) errorText = String(payload.error);
                    }
                } catch (_) {
                }
            }

            finish(section, errorText);
            return response;
        } catch (error) {
            finish(section, error?.message || 'Не удалось получить данные.');
            throw error;
        }
    };

    const observer = new MutationObserver(() => {
        const section = visibleSection();
        const current = section ? sectionState.get(section) : null;
        if (section && current?.pending > 0) {
            prepareMetrics(section);
            prepareTables(section);
            prepareCharts(section);
        }
    });

    function boot() {
        observer.observe(document.body, {childList: true, subtree: true});
        document.querySelectorAll('.section').forEach(ensureStateBox);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
JS;

$cssPatch = <<<'CSS'

/* GLOBAL_DATA_STATE_V1 */
.global-data-state {
    display: flex;
    align-items: center;
    gap: 11px;
    margin: 0 0 16px;
    padding: 12px 14px;
    border: 1px solid;
    border-radius: 12px;
    line-height: 1.35;
}

.global-data-state.hidden {
    display: none;
}

.global-data-state-copy {
    display: grid;
    gap: 2px;
}

.global-data-state-copy strong {
    font-size: 13px;
}

.global-data-state-copy small {
    font-size: 11px;
    opacity: .85;
}

.global-data-state-icon,
.global-data-inline-spinner {
    width: 16px;
    height: 16px;
    flex: 0 0 16px;
    border: 2px solid currentColor;
    border-right-color: transparent;
    border-radius: 50%;
}

.global-data-state.loading,
.global-data-inline-state.loading,
.global-data-chart-state.loading {
    color: #175cd3;
    background: #eff8ff;
    border-color: #b2ddff;
}

.global-data-state.loading .global-data-state-icon,
.global-data-inline-state.loading .global-data-inline-spinner,
.global-data-chart-state.loading .global-data-inline-spinner {
    animation: global-data-spin .8s linear infinite;
}

.global-data-state.empty,
.global-data-inline-state.empty,
.global-data-chart-state.empty {
    color: #475467;
    background: #f8fafc;
    border-color: #d0d5dd;
}

.global-data-state.error {
    color: #b42318;
    background: #fef3f2;
    border-color: #fecdca;
}

.global-data-state.error .global-data-state-icon {
    border: 0;
    border-radius: 50%;
    background: currentColor;
    position: relative;
}

.global-data-state.error .global-data-state-icon::after {
    content: '!';
    position: absolute;
    inset: 0;
    display: grid;
    place-items: center;
    color: white;
    font-size: 11px;
    font-weight: 800;
}

.global-data-placeholder {
    position: relative;
    overflow: hidden;
}

.global-data-placeholder::after {
    content: '';
    position: absolute;
    inset: 0;
    transform: translateX(-100%);
    background: linear-gradient(90deg, transparent, rgba(255,255,255,.55), transparent);
    animation: global-data-shimmer 1.35s infinite;
    pointer-events: none;
}

.global-data-placeholder-value {
    opacity: .55;
}

.global-data-inline-state,
.global-data-chart-state {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 9px;
    min-height: 76px;
    padding: 16px;
    border: 1px dashed;
    border-radius: 10px;
    font-size: 12px;
}

.global-data-inline-empty {
    display: grid;
    place-items: center;
    width: 26px;
    height: 26px;
    border-radius: 50%;
    background: #eaecf0;
    color: #475467;
    font-weight: 700;
}

.global-data-chart-state {
    min-height: 180px;
    flex-direction: column;
}

.global-data-chart-state strong {
    font-size: 28px;
}

.global-data-chart-state small {
    font-size: 11px;
}

@keyframes global-data-spin {
    to { transform: rotate(360deg); }
}

@keyframes global-data-shimmer {
    100% { transform: translateX(100%); }
}

@media (prefers-reduced-motion: reduce) {
    .global-data-state-icon,
    .global-data-inline-spinner,
    .global-data-placeholder::after {
        animation: none !important;
    }
}
CSS;

try {
    gdsWrite($jsPath, $js . $jsPatch . PHP_EOL);
    gdsWrite($cssPath, $css . $cssPatch . PHP_EOL);

    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=24', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=24', $index) ?? $index;
    gdsWrite($indexPath, $index);

    gdsLint($indexPath);

    echo "Единые состояния загрузки установлены.\n";
    echo "- показатели до получения данных отображаются как 0;\n";
    echo "- во время запроса показывается «Загрузка данных»;\n";
    echo "- пустой ответ отображается как «Данных за выбранный период нет»;\n";
    echo "- ошибки загрузки выделяются красным;\n";
    echo "- правило применяется ко всем разделам и новым модулям.\n";
    echo "Резервная копия: {$backupDirectory}\n";
} catch (Throwable $exception) {
    @copy($backupDirectory . '/index.php', $indexPath);
    @copy($backupDirectory . '/app.js', $jsPath);
    @copy($backupDirectory . '/app.css', $cssPath);
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
