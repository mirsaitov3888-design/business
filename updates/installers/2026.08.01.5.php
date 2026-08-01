<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function sr5read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function sr5replace(string $content, string $needle, string $replacement, string $label): string
{
    $count = 0;
    $content = str_replace($needle, $replacement, $content, $count);
    if ($count !== 1) {
        throw new RuntimeException("Не удалось изменить {$label}: найдено замен {$count}.");
    }
    return $content;
}

function sr5write(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать каталог {$directory}");
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temporary}");
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

function sr5lint(string $path): void
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
$supportServicePath = $root . '/app/Services/SupportReportService.php';
$resultServicePath = $root . '/app/Services/Bitrix24TaskResultService.php';

foreach ([$indexPath, $jsPath, $cssPath, $supportServicePath, $root . '/app/Services/Bitrix24Client.php'] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$index = sr5read($indexPath);
$js = sr5read($jsPath);
$css = sr5read($cssPath);
$supportServiceOriginal = sr5read($supportServicePath);

if (str_contains($js, 'SUPPORT_REPORT_TASK_RESULTS_V5')) {
    echo "Подстановка результатов задач и расчёт сверх тарифа уже установлены.\n";
    exit(0);
}

$backupDirectory = $root . '/storage/backups/support-report-results-v5-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$backupFiles = [
    $indexPath => 'index.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
    $supportServicePath => 'SupportReportService.php',
];
foreach ($backupFiles as $source => $name) {
    if (!copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException("Не удалось сохранить резервную копию {$name}");
    }
}

$resultService = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

final class Bitrix24TaskResultService
{
    public function __construct(
        private readonly Bitrix24Client $client = new Bitrix24Client()
    ) {
    }

    /**
     * @return array{descriptions: array<int, string>, warnings: array<int, string>}
     */
    public function descriptions(array $taskIds): array
    {
        $taskIds = array_values(array_unique(array_filter(
            array_map('intval', $taskIds),
            static fn(int $id): bool => $id > 0
        )));
        $descriptions = [];
        $warnings = [];

        foreach ($taskIds as $taskId) {
            $descriptions[$taskId] = '';
        }

        foreach (array_chunk($taskIds, 50) as $chunk) {
            $commands = [];
            foreach ($chunk as $taskId) {
                $commands['result_' . $taskId] = 'tasks.task.result.list?'
                    . http_build_query(['taskId' => $taskId], '', '&', PHP_QUERY_RFC3986);
            }

            try {
                $response = $this->client->call('batch', [
                    'halt' => 0,
                    'cmd' => $commands,
                ]);
                $batch = is_array($response['result'] ?? null)
                    ? $response['result']
                    : [];
                $batchRows = is_array($batch['result'] ?? null)
                    ? $batch['result']
                    : [];
                $batchErrors = is_array($batch['result_error'] ?? null)
                    ? $batch['result_error']
                    : [];

                foreach ($chunk as $taskId) {
                    $key = 'result_' . $taskId;
                    if (isset($batchErrors[$key])) {
                        try {
                            $descriptions[$taskId] = $this->singleDescription($taskId);
                        } catch (\Throwable $exception) {
                            $warnings[] = 'Не удалось получить результат задачи #'
                                . $taskId . ': ' . $exception->getMessage();
                        }
                        continue;
                    }
                    $descriptions[$taskId] = $this->pickDescription(
                        $this->rows($batchRows[$key] ?? [])
                    );
                }
            } catch (\Throwable $exception) {
                foreach ($chunk as $taskId) {
                    try {
                        $descriptions[$taskId] = $this->singleDescription($taskId);
                    } catch (\Throwable $taskException) {
                        $warnings[] = 'Не удалось получить результат задачи #'
                            . $taskId . ': ' . $taskException->getMessage();
                    }
                }
            }
        }

        return [
            'descriptions' => $descriptions,
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    private function singleDescription(int $taskId): string
    {
        $response = $this->client->call('tasks.task.result.list', [
            'taskId' => $taskId,
        ]);
        return $this->pickDescription(
            $this->rows($response['result'] ?? [])
        );
    }

    private function rows(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        if (is_array($value['items'] ?? null)) {
            return $value['items'];
        }
        if (is_array($value['results'] ?? null)) {
            return $value['results'];
        }
        return array_is_list($value) ? $value : [];
    }

    private function pickDescription(array $rows): string
    {
        usort($rows, static function (array $left, array $right): int {
            $leftDate = (string) ($left['updatedAt'] ?? $left['UPDATED_AT'] ?? $left['createdAt'] ?? $left['CREATED_AT'] ?? '');
            $rightDate = (string) ($right['updatedAt'] ?? $right['UPDATED_AT'] ?? $right['createdAt'] ?? $right['CREATED_AT'] ?? '');
            if ($leftDate !== $rightDate) {
                return strcmp($rightDate, $leftDate);
            }
            return (int) ($right['id'] ?? $right['ID'] ?? 0)
                <=> (int) ($left['id'] ?? $left['ID'] ?? 0);
        });

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $text = (string) (
                $row['formattedText']
                ?? $row['FORMATTED_TEXT']
                ?? $row['text']
                ?? $row['TEXT']
                ?? ''
            );
            $text = $this->plainText($text);
            if ($text !== '') {
                return $text;
            }
        }

        return '';
    }

    private function plainText(string $value): string
    {
        $value = preg_replace('#<br\s*/?>#iu', "\n", $value) ?? $value;
        $value = preg_replace('#</(?:p|div|li|h[1-6])>#iu', "\n", $value) ?? $value;
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\[(?:\/?)(?:b|i|u|s|url|quote|code|color|size)(?:=[^\]]+)?\]/iu', '', $value) ?? $value;
        $value = preg_replace('/[ \t]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\h*\R\h*/u', "\n", $value) ?? $value;
        $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?? $value;
        return trim($value);
    }
}
PHPFILE;

$supportService = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use SeoAnalytics\Repositories\Bitrix24Repository;

final class SupportReportService
{
    public function __construct(
        private readonly Bitrix24SyncService $sync = new Bitrix24SyncService(),
        private readonly Bitrix24Repository $bitrixRepository = new Bitrix24Repository(),
        private readonly Bitrix24TaskResultService $resultService = new Bitrix24TaskResultService()
    ) {
    }

    public function syncAndBuild(int $projectId, string $dateFrom, string $dateTo): array
    {
        $result = $this->sync->sync($projectId, $dateFrom, $dateTo);
        return $this->withTaskResults($this->mapPreview(
            $result['preview'] ?? [],
            $result['warnings'] ?? []
        ));
    }

    public function buildFromCache(int $projectId, string $dateFrom, string $dateTo): array
    {
        return $this->withTaskResults($this->mapPreview(
            $this->bitrixRepository->preview($projectId, $dateFrom, $dateTo),
            []
        ));
    }

    private function withTaskResults(array $data): array
    {
        $taskIds = array_values(array_filter(array_map(
            static fn(array $task): int => (int) ($task['bitrix_task_id'] ?? 0),
            $data['tasks'] ?? []
        )));
        $result = $this->resultService->descriptions($taskIds);
        $descriptions = $result['descriptions'] ?? [];
        $filled = 0;

        foreach ($data['tasks'] as &$task) {
            $taskId = (int) ($task['bitrix_task_id'] ?? 0);
            $description = trim((string) ($descriptions[$taskId] ?? ''));
            if ($description !== '') {
                $task['client_result'] = $description;
                $filled++;
            }
        }
        unset($task);

        $data['warnings'] = array_values(array_unique([
            ...($data['warnings'] ?? []),
            ...($result['warnings'] ?? []),
        ]));
        $data['result_descriptions_count'] = $filled;

        return $data;
    }

    private function mapPreview(array $preview, array $warnings): array
    {
        $tasks = [];
        foreach (($preview['tasks'] ?? []) as $task) {
            $tasks[] = [
                'bitrix_task_id' => (int) ($task['bitrix_task_id'] ?? 0),
                'title' => (string) ($task['title'] ?? ''),
                'status' => (string) ($task['status'] ?? ''),
                'responsible_name' => (string) ($task['responsible_name'] ?? ''),
                'tags' => is_array($task['tags'] ?? null) ? $task['tags'] : [],
                'elapsed_seconds' => (int) ($task['period_seconds'] ?? 0),
                'elapsed_entries' => (int) ($task['elapsed_entries'] ?? 0),
                'included' => true,
                'client_result' => '',
            ];
        }

        return [
            'tasks' => $tasks,
            'summary' => $preview['summary'] ?? [],
            'last_sync' => $preview['last_sync'] ?? null,
            'warnings' => array_values(array_unique(array_map('strval', $warnings))),
        ];
    }
}
PHPFILE;

$oldSummary = <<<'JS'
        const summary = supportSummaryData();
        root.innerHTML = [
            ['Задач в отчёте', number.format(summary.tasks_count), 'Выбраны менеджером'],
            ['Трудозатраты', supportHours(summary.elapsed_seconds), 'По записям Битрикс24'],
            ['Доступно часов', number.format(summary.available_hours), 'Тариф + перенос'],
            ['Осталось', number.format(summary.remaining_hours), 'После выполненных работ'],
            ['Сверх тарифа', number.format(summary.extra_hours), 'Дополнительные часы']
        ].map(([title, value, note]) => `<article class="metric-card"><span>${escapeHtml(title)}</span><strong>${escapeHtml(String(value))}</strong><small>${escapeHtml(note)}</small></article>`).join('');
JS;

$newSummary = <<<'JS'
        const summary = supportSummaryData();
        const extraRate = supportDecimal($('#supportReportForm')?.elements.extra_hour_rate.value);
        const extraCost = summary.extra_hours * extraRate;
        root.innerHTML = [
            ['Задач в отчёте', number.format(summary.tasks_count), 'Выбраны менеджером'],
            ['Трудозатраты', supportHours(summary.elapsed_seconds), 'По записям Битрикс24'],
            ['Доступно часов', number.format(summary.available_hours), 'Тариф + перенос'],
            ['Осталось', number.format(summary.remaining_hours), 'После выполненных работ'],
            ['Дополнительные часы', number.format(summary.extra_hours), 'Сверх тарифа'],
            ['Стоимость сверх тарифа', reportMoney(extraCost), extraCost > 0 ? `${number.format(extraRate)} ₽/час` : 'Перерасхода нет']
        ].map(([title, value, note]) => `<article class="metric-card"><span>${escapeHtml(title)}</span><strong>${escapeHtml(String(value))}</strong><small>${escapeHtml(note)}</small></article>`).join('');
JS;

$oldPreviewKpi = <<<'JS'
                    ['Трудозатраты', supportHours(summary.elapsed_seconds), 'Фактическое время'],
                    ['Доступно часов', number.format(summary.available_hours), 'Тариф + перенос'],
                    ['Осталось', number.format(summary.remaining_hours), 'Неиспользованный остаток'],
                    ['Сверх тарифа', number.format(summary.extra_hours), extraCost > 0 ? reportMoney(extraCost) : 'Дополнительные работы']
JS;

$newPreviewKpi = <<<'JS'
                    ['Трудозатраты', supportHours(summary.elapsed_seconds), 'Фактическое время'],
                    ['Доступно часов', number.format(summary.available_hours), 'Тариф + перенос'],
                    ['Осталось', number.format(summary.remaining_hours), 'Неиспользованный остаток'],
                    ['Дополнительные часы', number.format(summary.extra_hours), 'Сверх тарифа'],
                    ['Стоимость сверх тарифа', reportMoney(extraCost), extraCost > 0 ? `${number.format(Number(payload.extra_hour_rate || 0))} ₽/час` : 'Перерасхода нет']
JS;

$oldSyncMessage = <<<'JS'
            const warnings = result.data?.warnings || [];
            message.className = warnings.length ? 'alert alert-warning' : 'alert alert-success';
            message.textContent = warnings.length ? `Данные загружены. ${warnings.join(' · ')}` : 'Задачи и трудозатраты загружены.';
JS;

$newSyncMessage = <<<'JS'
            const warnings = result.data?.warnings || [];
            const descriptionsCount = Number(result.data?.result_descriptions_count || 0);
            const descriptionsNote = descriptionsCount > 0
                ? ` Описания результатов подставлены: ${number.format(descriptionsCount)}.`
                : ' Закреплённые результаты задач не найдены; поля оставлены для ручного заполнения.';
            message.className = warnings.length ? 'alert alert-warning' : 'alert alert-success';
            message.textContent = (warnings.length
                ? `Данные загружены. ${warnings.join(' · ')}`
                : 'Задачи и трудозатраты загружены.') + descriptionsNote;
JS;

try {
    $js = sr5replace($js, $oldSummary, $newSummary, 'расчёт стоимости сверх тарифа');
    $js = sr5replace($js, $oldPreviewKpi, $newPreviewKpi, 'KPI предпросмотра');
    $js = sr5replace($js, $oldSyncMessage, $newSyncMessage, 'сообщение о результатах задач');
    $js = str_replace('    /* SUPPORT_REPORTS_MODULE_JS */', "    /* SUPPORT_REPORTS_MODULE_JS */\n    /* SUPPORT_REPORT_TASK_RESULTS_V5 */", $js);

    $css = sr5replace(
        $css,
        '.support-report-summary { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; }',
        '.support-report-summary { display:grid; grid-template-columns:repeat(6,minmax(0,1fr)); gap:10px; }',
        'сетку KPI отчёта'
    );
    $css .= <<<'CSS'

/* SUPPORT_REPORT_TASK_RESULTS_V5 */
.support-report-preview .report-kpi-grid {
    grid-template-columns: repeat(5, minmax(0, 1fr));
}
@media (max-width: 1200px) {
    .support-report-preview .report-kpi-grid {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }
}
@media (max-width: 760px) {
    .support-report-preview .report-kpi-grid {
        grid-template-columns: 1fr 1fr;
    }
}
CSS;

    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=22', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=22', $index) ?? $index;

    sr5write($resultServicePath, $resultService);
    sr5write($supportServicePath, $supportService);
    sr5write($jsPath, $js);
    sr5write($cssPath, $css);
    sr5write($indexPath, $index);

    sr5lint($resultServicePath);
    sr5lint($supportServicePath);
    sr5lint($indexPath);

    echo "Результаты задач Битрикс24 подключены к отчёту поддержки.\n";
    echo "- последний закреплённый результат подставляется в описание для клиента;\n";
    echo "- пустые результаты можно заполнить вручную;\n";
    echo "- добавлен расчёт стоимости сверх тарифа;\n";
    echo "- стоимость показана рядом с дополнительными часами.\n";
    echo "Резервная копия: {$backupDirectory}\n";
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $name) {
        @copy($backupDirectory . '/' . $name, $destination);
    }
    @unlink($resultServicePath);
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
