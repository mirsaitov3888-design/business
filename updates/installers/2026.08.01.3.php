<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function sr3out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function sr3read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function sr3replace(string $content, string $needle, string $replacement, string $label): string
{
    $count = 0;
    $content = str_replace($needle, $replacement, $content, $count);
    if ($count !== 1) {
        throw new RuntimeException("Не удалось изменить {$label}: найдено замен {$count}.");
    }
    return $content;
}

function sr3insertBefore(string $content, string $needle, string $insertion, string $label): string
{
    return sr3replace($content, $needle, $insertion . $needle, $label);
}

function sr3write(string $path, string $content): void
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

function sr3lint(string $path): void
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
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$repositoryPath = $root . '/app/Repositories/SupportReportRepository.php';
$servicePath = $root . '/app/Services/SupportReportService.php';

foreach ([$indexPath, $apiPath, $jsPath, $cssPath, $schemaPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$index = sr3read($indexPath);
$api = sr3read($apiPath);
$js = sr3read($jsPath);
$css = sr3read($cssPath);
$schema = sr3read($schemaPath);

if (str_contains($index, 'SUPPORT_REPORTS_MODULE') || str_contains($js, 'SUPPORT_REPORTS_MODULE_JS')) {
    sr3out('Модуль отчётов по поддержке уже установлен.');
    exit(0);
}

$repository = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class SupportReportRepository
{
    public function listByProject(int $projectId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                r.*,
                u.name AS author_name,
                u.email AS author_email,
                COUNT(t.id) AS tasks_count,
                COALESCE(SUM(CASE WHEN t.included = 1 THEN t.elapsed_seconds ELSE 0 END), 0) AS elapsed_seconds
             FROM support_reports r
             INNER JOIN users u ON u.id = r.created_by
             LEFT JOIN support_report_tasks t ON t.support_report_id = r.id
             WHERE r.project_id = :project_id
             GROUP BY r.id, u.name, u.email
             ORDER BY r.updated_at DESC
             LIMIT 100'
        );
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            foreach (['id', 'project_id', 'created_by', 'tasks_count', 'elapsed_seconds'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            foreach (['included_hours', 'carried_hours', 'monthly_fee', 'extra_hour_rate'] as $key) {
                $row[$key] = (float) $row[$key];
            }
            $row['author_name'] = $row['author_name'] ?: $row['author_email'];
            unset($row['author_email']);
        }
        unset($row);

        return $rows;
    }

    public function find(int $id, int $projectId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, u.name AS author_name, u.email AS author_email
             FROM support_reports r
             INNER JOIN users u ON u.id = r.created_by
             WHERE r.id = :id AND r.project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'project_id' => $projectId]);
        $report = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$report) {
            return null;
        }

        foreach (['id', 'project_id', 'created_by'] as $key) {
            $report[$key] = (int) $report[$key];
        }
        foreach (['included_hours', 'carried_hours', 'monthly_fee', 'extra_hour_rate'] as $key) {
            $report[$key] = (float) $report[$key];
        }
        $report['author_name'] = $report['author_name'] ?: $report['author_email'];
        unset($report['author_email']);

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM support_report_tasks
             WHERE support_report_id = :report_id
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute(['report_id' => $id]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($tasks as &$task) {
            foreach (['id', 'support_report_id', 'bitrix_task_id', 'elapsed_seconds', 'elapsed_entries', 'included', 'sort_order'] as $key) {
                $task[$key] = (int) $task[$key];
            }
            $task['tags'] = json_decode((string) $task['tags_json'], true) ?: [];
            unset($task['tags_json']);
        }
        unset($task);
        $report['tasks'] = $tasks;
        $report['summary'] = $this->summary($tasks, $report);

        return $report;
    }

    public function save(array $data, int $userId): int
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        $reportId = (int) ($data['id'] ?? 0);
        if ($projectId <= 0 || $userId <= 0) {
            throw new RuntimeException('Не удалось определить проект или пользователя.');
        }

        $title = trim((string) ($data['title'] ?? ''));
        $dateFrom = (string) ($data['date_from'] ?? '');
        $dateTo = (string) ($data['date_to'] ?? '');
        if ($title === '') {
            $title = 'Отчёт по поддержке ' . $dateFrom . '—' . $dateTo;
        }
        $status = in_array(($data['status'] ?? ''), ['draft', 'review', 'approved', 'sent', 'archive'], true)
            ? (string) $data['status']
            : 'draft';
        $audience = in_array(($data['audience'] ?? ''), ['owner', 'marketer', 'sales', 'client'], true)
            ? (string) $data['audience']
            : 'client';

        $tasks = $this->sanitizeTasks(is_array($data['tasks'] ?? null) ? $data['tasks'] : []);
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $params = [
                'project_id' => $projectId,
                'created_by' => $userId,
                'title' => mb_substr($title, 0, 190),
                'status' => $status,
                'audience' => $audience,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'included_hours' => $this->decimal($data['included_hours'] ?? 0),
                'carried_hours' => $this->decimal($data['carried_hours'] ?? 0),
                'monthly_fee' => $this->decimal($data['monthly_fee'] ?? 0),
                'extra_hour_rate' => $this->decimal($data['extra_hour_rate'] ?? 0),
                'results_html' => $this->rich($data['results_html'] ?? ''),
                'next_plan_html' => $this->rich($data['next_plan_html'] ?? ''),
                'recommendations_html' => $this->rich($data['recommendations_html'] ?? ''),
                'notes_html' => $this->rich($data['notes_html'] ?? ''),
            ];

            if ($reportId > 0) {
                $check = $pdo->prepare('SELECT id FROM support_reports WHERE id = :id AND project_id = :project_id');
                $check->execute(['id' => $reportId, 'project_id' => $projectId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Отчёт не найден.');
                }
                $stmt = $pdo->prepare(
                    'UPDATE support_reports SET
                        title = :title,
                        status = :status,
                        audience = :audience,
                        date_from = :date_from,
                        date_to = :date_to,
                        included_hours = :included_hours,
                        carried_hours = :carried_hours,
                        monthly_fee = :monthly_fee,
                        extra_hour_rate = :extra_hour_rate,
                        results_html = :results_html,
                        next_plan_html = :next_plan_html,
                        recommendations_html = :recommendations_html,
                        notes_html = :notes_html,
                        updated_at = NOW()
                     WHERE id = :id AND project_id = :project_id'
                );
                $stmt->execute($params + ['id' => $reportId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO support_reports
                     (project_id, created_by, title, status, audience, date_from, date_to,
                      included_hours, carried_hours, monthly_fee, extra_hour_rate,
                      results_html, next_plan_html, recommendations_html, notes_html,
                      created_at, updated_at)
                     VALUES
                     (:project_id, :created_by, :title, :status, :audience, :date_from, :date_to,
                      :included_hours, :carried_hours, :monthly_fee, :extra_hour_rate,
                      :results_html, :next_plan_html, :recommendations_html, :notes_html,
                      NOW(), NOW())'
                );
                $stmt->execute($params);
                $reportId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM support_report_tasks WHERE support_report_id = :id')
                ->execute(['id' => $reportId]);

            $insert = $pdo->prepare(
                'INSERT INTO support_report_tasks
                 (support_report_id, bitrix_task_id, title, status, responsible_name,
                  tags_json, elapsed_seconds, elapsed_entries, included, client_result, sort_order)
                 VALUES
                 (:support_report_id, :bitrix_task_id, :title, :status, :responsible_name,
                  :tags_json, :elapsed_seconds, :elapsed_entries, :included, :client_result, :sort_order)'
            );
            foreach ($tasks as $task) {
                $insert->execute([
                    'support_report_id' => $reportId,
                    'bitrix_task_id' => $task['bitrix_task_id'] ?: null,
                    'title' => $task['title'],
                    'status' => $task['status'],
                    'responsible_name' => $task['responsible_name'],
                    'tags_json' => json_encode($task['tags'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'elapsed_seconds' => $task['elapsed_seconds'],
                    'elapsed_entries' => $task['elapsed_entries'],
                    'included' => $task['included'],
                    'client_result' => $task['client_result'],
                    'sort_order' => $task['sort_order'],
                ]);
            }

            $pdo->commit();
            return $reportId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    private function sanitizeTasks(array $tasks): array
    {
        $result = [];
        foreach (array_slice($tasks, 0, 500) as $index => $task) {
            if (!is_array($task)) {
                continue;
            }
            $title = trim((string) ($task['title'] ?? ''));
            if ($title === '') {
                continue;
            }
            $tags = is_array($task['tags'] ?? null) ? array_values(array_map('strval', $task['tags'])) : [];
            $result[] = [
                'bitrix_task_id' => max(0, (int) ($task['bitrix_task_id'] ?? 0)),
                'title' => mb_substr(strip_tags($title), 0, 1000),
                'status' => mb_substr(strip_tags((string) ($task['status'] ?? '')), 0, 100),
                'responsible_name' => mb_substr(strip_tags((string) ($task['responsible_name'] ?? '')), 0, 255),
                'tags' => array_slice($tags, 0, 30),
                'elapsed_seconds' => max(0, (int) ($task['elapsed_seconds'] ?? 0)),
                'elapsed_entries' => max(0, (int) ($task['elapsed_entries'] ?? 0)),
                'included' => !empty($task['included']) ? 1 : 0,
                'client_result' => mb_substr(trim((string) ($task['client_result'] ?? '')), 0, 10000),
                'sort_order' => $index,
            ];
        }
        return $result;
    }

    private function summary(array $tasks, array $report): array
    {
        $included = array_values(array_filter($tasks, static fn(array $task): bool => (int) $task['included'] === 1));
        $seconds = array_sum(array_column($included, 'elapsed_seconds'));
        $entries = array_sum(array_column($included, 'elapsed_entries'));
        $availableHours = (float) $report['included_hours'] + (float) $report['carried_hours'];
        $usedHours = $seconds / 3600;
        return [
            'tasks_count' => count($included),
            'elapsed_entries' => $entries,
            'elapsed_seconds' => $seconds,
            'used_hours' => $usedHours,
            'available_hours' => $availableHours,
            'remaining_hours' => max(0, $availableHours - $usedHours),
            'extra_hours' => max(0, $usedHours - $availableHours),
        ];
    }

    private function decimal(mixed $value): float
    {
        return max(0, (float) str_replace([' ', ','], ['', '.'], trim((string) $value)));
    }

    private function rich(mixed $value): string
    {
        $html = trim((string) $value);
        return mb_substr(strip_tags(
            $html,
            '<p><br><strong><b><em><i><u><h2><h3><h4><ul><ol><li><a><img><blockquote><div><span>'
        ), 0, 100000);
    }
}
PHPFILE;

$service = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use SeoAnalytics\Repositories\Bitrix24Repository;

final class SupportReportService
{
    public function __construct(
        private readonly Bitrix24SyncService $sync = new Bitrix24SyncService(),
        private readonly Bitrix24Repository $bitrixRepository = new Bitrix24Repository()
    ) {
    }

    public function syncAndBuild(int $projectId, string $dateFrom, string $dateTo): array
    {
        $result = $this->sync->sync($projectId, $dateFrom, $dateTo);
        return $this->mapPreview(
            $result['preview'] ?? [],
            $result['warnings'] ?? []
        );
    }

    public function buildFromCache(int $projectId, string $dateFrom, string $dateTo): array
    {
        return $this->mapPreview(
            $this->bitrixRepository->preview($projectId, $dateFrom, $dateTo),
            []
        );
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

$section = <<<'HTML'
        <!-- SUPPORT_REPORTS_MODULE -->
        <section id="section-support-reports" class="section">
            <div class="support-reports-toolbar">
                <div>
                    <p class="eyebrow">Клиентская отчётность</p>
                    <h2>Отчёт по технической поддержке</h2>
                    <p class="muted">Задачи Битрикс24, фактические трудозатраты, использование тарифа и итоговый клиентский отчёт.</p>
                </div>
                <button type="button" class="button button-primary" id="supportReportNew">Новый отчёт</button>
            </div>

            <?php if (!$project): ?>
                <div class="empty-state"><h2>Сначала добавьте проект</h2><p>Отчёт создаётся для активного проекта.</p></div>
            <?php else: ?>
                <div id="supportReportMessage"></div>
                <div class="support-reports-layout">
                    <article class="panel support-reports-history-panel">
                        <div class="panel-head"><div><p class="eyebrow">История</p><h2>Сохранённые отчёты</h2></div></div>
                        <div id="supportReportsHistory" class="support-reports-history"><span class="muted">История ещё не загружена.</span></div>
                    </article>

                    <article class="panel support-report-editor-panel">
                        <div class="panel-head"><div><p class="eyebrow">Редактор</p><h2 id="supportReportEditorTitle">Новый отчёт</h2></div></div>
                        <form id="supportReportForm" class="settings-form">
                            <input type="hidden" name="report_id" value="0">
                            <input type="hidden" name="project_id" value="<?= (int) $project['id'] ?>">

                            <div class="form-grid support-report-main-grid">
                                <label class="support-report-title"><span>Название отчёта</span><input name="title" maxlength="190" placeholder="Поддержка за июль 2026"></label>
                                <label><span>Получатель</span><select name="audience"><option value="client">Клиент</option><option value="owner">Собственник</option><option value="marketer">Маркетолог</option><option value="sales">РОП</option></select></label>
                                <label><span>Статус</span><select name="status"><option value="draft">Черновик</option><option value="review">На проверке</option><option value="approved">Утверждён</option><option value="sent">Отправлен</option><option value="archive">Архив</option></select></label>
                                <label><span>Период с</span><input type="date" name="date_from" value="<?= $dateFrom ?>" required></label>
                                <label><span>Период по</span><input type="date" name="date_to" value="<?= $yesterday ?>" required></label>
                            </div>

                            <section class="support-sync-box">
                                <div><strong>Задачи и время из Битрикс24</strong><p class="muted">Система синхронизирует связанный проект и подставит задачи, попавшие в выбранный период.</p></div>
                                <button type="button" class="button button-primary" id="supportReportSync">Загрузить из Битрикс24</button>
                            </section>

                            <section class="support-tariff-box">
                                <div class="reports-section-head"><div><strong>Тариф поддержки</strong><p class="muted">Пока значения заполняются вручную. Следующим шагом свяжем их с договором в Битрикс24.</p></div></div>
                                <div class="form-grid support-tariff-grid">
                                    <label><span>Включено часов</span><input type="number" min="0" step="0.01" name="included_hours"></label>
                                    <label><span>Перенесено часов</span><input type="number" min="0" step="0.01" name="carried_hours"></label>
                                    <label><span>Стоимость тарифа, ₽</span><input type="number" min="0" step="0.01" name="monthly_fee"></label>
                                    <label><span>Дополнительный час, ₽</span><input type="number" min="0" step="0.01" name="extra_hour_rate"></label>
                                </div>
                            </section>

                            <div id="supportReportSummary" class="support-report-summary"></div>

                            <section class="support-tasks-box">
                                <div class="channel-subsection-head"><div><strong>Выполненные задачи</strong><p class="muted">Снимите галочку с внутренних задач. Клиентское описание можно отредактировать.</p></div><button type="button" class="button" id="supportAddManualTask">Добавить вручную</button></div>
                                <div id="supportReportTasks" class="support-report-tasks"></div>
                            </section>

                            <div class="support-rich-grid">
                                <div class="rich-field" data-support-rich="results_html"><span class="rich-field-label">Итоги месяца</span><div class="rich-editor-shell"><div class="rich-toolbar"><button type="button" data-command="bold"><strong>B</strong></button><button type="button" data-command="italic"><em>I</em></button><button type="button" data-command="insertUnorderedList">• Список</button><button type="button" data-command="insertOrderedList">1. Список</button></div><div class="rich-editor" contenteditable="true" data-support-editor="results_html" data-placeholder="Ключевые результаты месяца..."></div></div></div>
                                <div class="rich-field" data-support-rich="next_plan_html"><span class="rich-field-label">План следующего периода</span><div class="rich-editor-shell"><div class="rich-toolbar"><button type="button" data-command="bold"><strong>B</strong></button><button type="button" data-command="italic"><em>I</em></button><button type="button" data-command="insertUnorderedList">• Список</button><button type="button" data-command="insertOrderedList">1. Список</button></div><div class="rich-editor" contenteditable="true" data-support-editor="next_plan_html" data-placeholder="Что планируется выполнить дальше..."></div></div></div>
                                <div class="rich-field" data-support-rich="recommendations_html"><span class="rich-field-label">Рекомендации</span><div class="rich-editor-shell"><div class="rich-toolbar"><button type="button" data-command="bold"><strong>B</strong></button><button type="button" data-command="italic"><em>I</em></button><button type="button" data-command="insertUnorderedList">• Список</button><button type="button" data-command="insertOrderedList">1. Список</button></div><div class="rich-editor" contenteditable="true" data-support-editor="recommendations_html" data-placeholder="Рекомендации клиенту..."></div></div></div>
                                <div class="rich-field" data-support-rich="notes_html"><span class="rich-field-label">Комментарии и ограничения</span><div class="rich-editor-shell"><div class="rich-toolbar"><button type="button" data-command="bold"><strong>B</strong></button><button type="button" data-command="italic"><em>I</em></button><button type="button" data-command="insertUnorderedList">• Список</button><button type="button" data-command="insertOrderedList">1. Список</button></div><div class="rich-editor" contenteditable="true" data-support-editor="notes_html" data-placeholder="Внутренние или клиентские пояснения..."></div></div></div>
                            </div>

                            <div class="report-actions"><button type="submit" class="button button-primary">Сохранить черновик</button><button type="button" class="button" id="supportReportPreviewButton">Предпросмотр</button></div>
                        </form>
                    </article>
                </div>

                <article id="supportReportPreview" class="panel support-report-preview hidden">
                    <div class="panel-head"><div><p class="eyebrow">Предпросмотр</p><h2>Отчёт по технической поддержке</h2></div><button type="button" class="button" id="supportReportPrint">Печать / сохранить PDF</button></div>
                    <div id="supportReportPreviewContent"></div>
                </article>
            <?php endif; ?>
        </section>
HTML;

$getApi = <<<'PHPAPI'
        // SUPPORT_REPORTS_MODULE_GET
        if ($action === 'support_reports_list') {
            $project = $projectRepository->firstActive();
            if (!$project) {
                Security::json(['error' => 'Проект не настроен.'], 422);
            }
            Security::json([
                'reports' => (new SupportReportRepository())->listByProject((int) $project['id']),
            ]);
        }

        if ($action === 'support_report_get') {
            $project = $projectRepository->firstActive();
            if (!$project) {
                Security::json(['error' => 'Проект не настроен.'], 422);
            }
            $report = (new SupportReportRepository())->find(
                (int) ($_GET['id'] ?? 0),
                (int) $project['id']
            );
            if (!$report) {
                Security::json(['error' => 'Отчёт не найден.'], 404);
            }
            Security::json(['report' => $report]);
        }

PHPAPI;

$postApi = <<<'PHPAPI'
    // SUPPORT_REPORTS_MODULE_POST
    if ($action === 'support_report_sync') {
        Security::requireCsrf($input['csrf_token'] ?? null);
        $project = $projectRepository->firstActive();
        if (!$project) {
            Security::json(['error' => 'Проект не настроен.'], 422);
        }
        [$dateFrom, $dateTo] = validateDates(
            trim((string) ($input['date_from'] ?? '')),
            trim((string) ($input['date_to'] ?? ''))
        );
        $data = (new SupportReportService())->syncAndBuild(
            (int) $project['id'],
            $dateFrom,
            $dateTo
        );
        Security::json(['data' => $data]);
    }

    if ($action === 'support_report_save') {
        Security::requireCsrf($input['csrf_token'] ?? null);
        $project = $projectRepository->firstActive();
        if (!$project) {
            Security::json(['error' => 'Проект не настроен.'], 422);
        }
        [$dateFrom, $dateTo] = validateDates(
            trim((string) ($input['date_from'] ?? '')),
            trim((string) ($input['date_to'] ?? ''))
        );
        $repository = new SupportReportRepository();
        $id = $repository->save([
            'id' => (int) ($input['id'] ?? 0),
            'project_id' => (int) $project['id'],
            'title' => (string) ($input['title'] ?? ''),
            'status' => (string) ($input['status'] ?? 'draft'),
            'audience' => (string) ($input['audience'] ?? 'client'),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'included_hours' => $input['included_hours'] ?? 0,
            'carried_hours' => $input['carried_hours'] ?? 0,
            'monthly_fee' => $input['monthly_fee'] ?? 0,
            'extra_hour_rate' => $input['extra_hour_rate'] ?? 0,
            'results_html' => (string) ($input['results_html'] ?? ''),
            'next_plan_html' => (string) ($input['next_plan_html'] ?? ''),
            'recommendations_html' => (string) ($input['recommendations_html'] ?? ''),
            'notes_html' => (string) ($input['notes_html'] ?? ''),
            'tasks' => is_array($input['tasks'] ?? null) ? $input['tasks'] : [],
        ], Auth::id());
        $report = $repository->find($id, (int) $project['id']);
        Auth::audit('support_report_saved', [
            'project_id' => (int) $project['id'],
            'support_report_id' => $id,
            'tasks_count' => count($report['tasks'] ?? []),
        ]);
        Security::json([
            'ok' => true,
            'report' => $report,
            'message' => 'Отчёт по поддержке сохранён.',
        ]);
    }

PHPAPI;

$jsFragment = <<<'JS'
    /* SUPPORT_REPORTS_MODULE_JS */
    let supportReportsLoaded = false;
    let supportReportTasks = [];
    let supportReportActiveId = 0;

    function supportHours(seconds) {
        const total = Math.max(0, Number(seconds || 0));
        const hours = Math.floor(total / 3600);
        const minutes = Math.round((total % 3600) / 60);
        return `${hours}:${String(minutes).padStart(2, '0')}`;
    }

    function supportDecimal(value) {
        const parsed = Number(String(value ?? '').replace(',', '.'));
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }

    function supportEditorValue(name) {
        return $(`[data-support-editor="${name}"]`)?.innerHTML.trim() || '';
    }

    function supportSetEditor(name, value) {
        const editor = $(`[data-support-editor="${name}"]`);
        if (editor) editor.innerHTML = value || '';
    }

    function supportTaskRow(task = {}) {
        const tags = Array.isArray(task.tags) ? task.tags : [];
        return `
            <article class="support-task-row" data-support-task data-bitrix-task-id="${Number(task.bitrix_task_id || 0)}">
                <label class="support-task-check"><input type="checkbox" data-support-field="included" ${task.included === false || task.included === 0 ? '' : 'checked'}><span>В отчёт</span></label>
                <div class="support-task-main">
                    <strong>${escapeHtml(task.title || 'Новая задача')}</strong>
                    <div class="support-task-meta"><span>${escapeHtml(task.status || '—')}</span><span>${escapeHtml(task.responsible_name || '—')}</span><span>${escapeHtml(supportHours(task.elapsed_seconds || 0))}</span></div>
                    <div class="support-task-tags">${tags.map(tag => `<span>${escapeHtml(String(tag))}</span>`).join('')}</div>
                </div>
                <label class="support-task-result"><span>Описание результата для клиента</span><textarea rows="3" data-support-field="client_result" placeholder="Что сделано и какой результат получен">${escapeHtml(task.client_result || '')}</textarea></label>
                <input type="hidden" data-support-field="title" value="${escapeHtml(task.title || 'Новая задача')}">
                <input type="hidden" data-support-field="status" value="${escapeHtml(task.status || '')}">
                <input type="hidden" data-support-field="responsible_name" value="${escapeHtml(task.responsible_name || '')}">
                <input type="hidden" data-support-field="elapsed_seconds" value="${Number(task.elapsed_seconds || 0)}">
                <input type="hidden" data-support-field="elapsed_entries" value="${Number(task.elapsed_entries || 0)}">
                <button type="button" class="button button-danger-small" data-remove-support-task>Удалить</button>
            </article>`;
    }

    function supportRenderTasks(tasks) {
        supportReportTasks = tasks || [];
        const root = $('#supportReportTasks');
        if (!root) return;
        root.innerHTML = supportReportTasks.length
            ? supportReportTasks.map(supportTaskRow).join('')
            : '<div class="reports-empty"><strong>Задач пока нет</strong><span>Загрузите их из Битрикс24 или добавьте вручную.</span></div>';
        supportRenderSummary();
    }

    function supportReadTasks() {
        return $$('[data-support-task]').map((row, index) => {
            const field = name => row.querySelector(`[data-support-field="${name}"]`);
            const meta = row.querySelectorAll('.support-task-meta span');
            return {
                bitrix_task_id: Number(row.dataset.bitrixTaskId || 0),
                title: field('title')?.value || row.querySelector('.support-task-main strong')?.textContent || 'Задача',
                status: field('status')?.value || meta[0]?.textContent || '',
                responsible_name: field('responsible_name')?.value || meta[1]?.textContent || '',
                tags: Array.from(row.querySelectorAll('.support-task-tags span')).map(item => item.textContent || ''),
                elapsed_seconds: Number(field('elapsed_seconds')?.value || 0),
                elapsed_entries: Number(field('elapsed_entries')?.value || 0),
                included: Boolean(field('included')?.checked),
                client_result: field('client_result')?.value.trim() || '',
                sort_order: index
            };
        });
    }

    function supportSummaryData() {
        const form = $('#supportReportForm');
        const tasks = supportReadTasks().filter(task => task.included);
        const seconds = tasks.reduce((sum, task) => sum + Number(task.elapsed_seconds || 0), 0);
        const entries = tasks.reduce((sum, task) => sum + Number(task.elapsed_entries || 0), 0);
        const includedHours = supportDecimal(form?.elements.included_hours.value);
        const carriedHours = supportDecimal(form?.elements.carried_hours.value);
        const available = includedHours + carriedHours;
        const used = seconds / 3600;
        return {
            tasks_count: tasks.length,
            elapsed_entries: entries,
            elapsed_seconds: seconds,
            used_hours: used,
            available_hours: available,
            remaining_hours: Math.max(0, available - used),
            extra_hours: Math.max(0, used - available)
        };
    }

    function supportRenderSummary() {
        const root = $('#supportReportSummary');
        if (!root) return;
        const summary = supportSummaryData();
        root.innerHTML = [
            ['Задач в отчёте', number.format(summary.tasks_count), 'Выбраны менеджером'],
            ['Трудозатраты', supportHours(summary.elapsed_seconds), 'По записям Битрикс24'],
            ['Доступно часов', number.format(summary.available_hours), 'Тариф + перенос'],
            ['Осталось', number.format(summary.remaining_hours), 'После выполненных работ'],
            ['Сверх тарифа', number.format(summary.extra_hours), 'Дополнительные часы']
        ].map(([title, value, note]) => `<article class="metric-card"><span>${escapeHtml(title)}</span><strong>${escapeHtml(String(value))}</strong><small>${escapeHtml(note)}</small></article>`).join('');
    }

    function supportCollectPayload() {
        const form = $('#supportReportForm');
        if (!form) throw new Error('Форма отчёта не найдена.');
        return {
            csrf_token: csrf,
            id: Number(form.elements.report_id.value || 0),
            project_id: Number(form.elements.project_id.value || 0),
            title: form.elements.title.value.trim(),
            audience: form.elements.audience.value,
            status: form.elements.status.value,
            date_from: form.elements.date_from.value,
            date_to: form.elements.date_to.value,
            included_hours: supportDecimal(form.elements.included_hours.value),
            carried_hours: supportDecimal(form.elements.carried_hours.value),
            monthly_fee: supportDecimal(form.elements.monthly_fee.value),
            extra_hour_rate: supportDecimal(form.elements.extra_hour_rate.value),
            results_html: supportEditorValue('results_html'),
            next_plan_html: supportEditorValue('next_plan_html'),
            recommendations_html: supportEditorValue('recommendations_html'),
            notes_html: supportEditorValue('notes_html'),
            tasks: supportReadTasks()
        };
    }

    function supportResetForm() {
        const form = $('#supportReportForm');
        if (!form) return;
        form.reset();
        form.elements.report_id.value = '0';
        form.elements.audience.value = 'client';
        form.elements.status.value = 'draft';
        const dates = currentDates();
        form.elements.date_from.value = dates.date1 || '';
        form.elements.date_to.value = dates.date2 || '';
        ['results_html', 'next_plan_html', 'recommendations_html', 'notes_html'].forEach(name => supportSetEditor(name, ''));
        supportReportActiveId = 0;
        $('#supportReportEditorTitle').textContent = 'Новый отчёт';
        supportRenderTasks([]);
        $('#supportReportPreview')?.classList.add('hidden');
        supportMarkActive();
    }

    function supportFillForm(report) {
        const form = $('#supportReportForm');
        if (!form) return;
        form.elements.report_id.value = String(report.id || 0);
        form.elements.title.value = report.title || '';
        form.elements.audience.value = report.audience || 'client';
        form.elements.status.value = report.status || 'draft';
        form.elements.date_from.value = report.date_from || '';
        form.elements.date_to.value = report.date_to || '';
        form.elements.included_hours.value = report.included_hours || '';
        form.elements.carried_hours.value = report.carried_hours || '';
        form.elements.monthly_fee.value = report.monthly_fee || '';
        form.elements.extra_hour_rate.value = report.extra_hour_rate || '';
        supportSetEditor('results_html', report.results_html || '');
        supportSetEditor('next_plan_html', report.next_plan_html || '');
        supportSetEditor('recommendations_html', report.recommendations_html || '');
        supportSetEditor('notes_html', report.notes_html || '');
        supportReportActiveId = Number(report.id || 0);
        $('#supportReportEditorTitle').textContent = report.title || 'Редактирование отчёта';
        supportRenderTasks(report.tasks || []);
        supportMarkActive();
    }

    function supportRenderHistory(reports) {
        const root = $('#supportReportsHistory');
        if (!root) return;
        if (!reports.length) {
            root.innerHTML = '<div class="reports-empty"><strong>Отчётов пока нет</strong><span>Создайте первый черновик.</span></div>';
            return;
        }
        root.innerHTML = reports.map(report => `
            <button type="button" class="support-history-item" data-support-report-id="${report.id}">
                <span class="report-list-top"><strong>${escapeHtml(report.title)}</strong><em class="report-status report-status-${escapeHtml(report.status)}">${escapeHtml(reportStatusLabels?.[report.status] || report.status)}</em></span>
                <span class="report-list-meta">${escapeHtml(reportDate(report.date_from))}—${escapeHtml(reportDate(report.date_to))}</span>
                <span class="report-list-kpi">Задач: ${number.format(report.tasks_count || 0)} · Время: ${escapeHtml(supportHours(report.elapsed_seconds || 0))}</span>
            </button>`).join('');
        supportMarkActive();
    }

    function supportMarkActive() {
        $$('.support-history-item').forEach(item => item.classList.toggle('is-active', Number(item.dataset.supportReportId || 0) === supportReportActiveId));
    }

    async function supportLoadHistory(force = false) {
        if (supportReportsLoaded && !force) return;
        const result = await api('/api.php?action=support_reports_list');
        supportRenderHistory(result.reports || []);
        supportReportsLoaded = true;
    }

    function supportRenderPreview(payload) {
        const root = $('#supportReportPreviewContent');
        const panel = $('#supportReportPreview');
        if (!root || !panel) return;
        const summary = supportSummaryData();
        const tasks = (payload.tasks || []).filter(task => task.included);
        const extraCost = summary.extra_hours * Number(payload.extra_hour_rate || 0);
        const rich = (title, value) => value ? `<section class="report-preview-text"><h3>${escapeHtml(title)}</h3><div class="report-rich-content">${reportCleanRichHtml(value)}</div></section>` : '';
        root.innerHTML = `
            <header class="report-document-head"><p class="eyebrow">Мир сайтов</p><h1>${escapeHtml(payload.title || 'Отчёт по технической поддержке')}</h1><div class="report-document-meta"><span>Период: ${escapeHtml(reportDate(payload.date_from))}—${escapeHtml(reportDate(payload.date_to))}</span><span>Задач: ${number.format(tasks.length)}</span><span>Трудозатраты: ${escapeHtml(supportHours(summary.elapsed_seconds))}</span></div></header>
            <div class="report-kpi-grid">
                ${[
                    ['Трудозатраты', supportHours(summary.elapsed_seconds), 'Фактическое время'],
                    ['Доступно часов', number.format(summary.available_hours), 'Тариф + перенос'],
                    ['Осталось', number.format(summary.remaining_hours), 'Неиспользованный остаток'],
                    ['Сверх тарифа', number.format(summary.extra_hours), extraCost > 0 ? reportMoney(extraCost) : 'Дополнительные работы']
                ].map(([title, value, note]) => `<article class="report-kpi-card"><span class="report-kpi-title">${escapeHtml(title)}</span><strong>${escapeHtml(String(value))}</strong><small>${escapeHtml(String(note))}</small></article>`).join('')}
            </div>
            <section class="report-preview-section"><div class="reports-section-head"><div><strong>Выполненные работы</strong><p class="muted">Задачи и фактические трудозатраты за период.</p></div></div><div class="table-scroll"><table class="data-table support-preview-table"><thead><tr><th>Задача</th><th>Результат</th><th>Исполнитель</th><th class="num">Время</th></tr></thead><tbody>${tasks.map(task => `<tr><td><strong>${escapeHtml(task.title)}</strong><small class="report-source-badge">${escapeHtml(task.status || '')}</small></td><td>${escapeHtml(task.client_result || '—')}</td><td>${escapeHtml(task.responsible_name || '—')}</td><td class="num">${escapeHtml(supportHours(task.elapsed_seconds || 0))}</td></tr>`).join('')}</tbody></table></div></section>
            <div class="report-preview-text-grid">${rich('Итоги месяца', payload.results_html)}${rich('План следующего периода', payload.next_plan_html)}${rich('Рекомендации', payload.recommendations_html)}${rich('Комментарии и ограничения', payload.notes_html)}</div>`;
        panel.classList.remove('hidden');
        panel.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    $('.nav-link[data-section="support-reports"]')?.addEventListener('click', () => supportLoadHistory(true));
    $('#supportReportNew')?.addEventListener('click', supportResetForm);
    $('#supportReportsHistory')?.addEventListener('click', async event => {
        const item = event.target.closest('[data-support-report-id]');
        if (!item) return;
        try {
            const result = await api(`/api.php?action=support_report_get&id=${encodeURIComponent(item.dataset.supportReportId)}`);
            supportFillForm(result.report);
            supportRenderPreview(result.report);
        } catch (error) {
            const message = $('#supportReportMessage');
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    });

    $('#supportReportSync')?.addEventListener('click', async event => {
        const form = $('#supportReportForm');
        const message = $('#supportReportMessage');
        const button = event.currentTarget;
        if (!form.elements.date_from.value || !form.elements.date_to.value) {
            message.className = 'alert alert-error';
            message.textContent = 'Укажите период отчёта.';
            return;
        }
        button.disabled = true;
        message.className = 'alert alert-info';
        message.textContent = 'Загружаем задачи и трудозатраты из Битрикс24…';
        try {
            const result = await api('/api.php?action=support_report_sync', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf, date_from: form.elements.date_from.value, date_to: form.elements.date_to.value})
            });
            supportRenderTasks(result.data?.tasks || []);
            const warnings = result.data?.warnings || [];
            message.className = warnings.length ? 'alert alert-warning' : 'alert alert-success';
            message.textContent = warnings.length ? `Данные загружены. ${warnings.join(' · ')}` : 'Задачи и трудозатраты загружены.';
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        } finally {
            button.disabled = false;
        }
    });

    $('#supportReportForm')?.addEventListener('input', supportRenderSummary);
    $('#supportAddManualTask')?.addEventListener('click', () => {
        const root = $('#supportReportTasks');
        if (root?.querySelector('.reports-empty')) root.innerHTML = '';
        root?.insertAdjacentHTML('beforeend', supportTaskRow({title: 'Новая задача', included: true}));
        supportRenderSummary();
    });
    $('#supportReportTasks')?.addEventListener('click', event => {
        const button = event.target.closest('[data-remove-support-task]');
        if (button) {
            button.closest('[data-support-task]')?.remove();
            supportRenderSummary();
        }
    });
    $('#supportReportForm')?.addEventListener('click', event => {
        const button = event.target.closest('.rich-toolbar button');
        if (!button) return;
        event.preventDefault();
        const editor = button.closest('[data-support-rich]')?.querySelector('[data-support-editor]');
        if (!editor) return;
        editor.focus();
        document.execCommand(button.dataset.command, false, null);
    });
    $('#supportReportPreviewButton')?.addEventListener('click', () => {
        try { supportRenderPreview(supportCollectPayload()); }
        catch (error) { const message = $('#supportReportMessage'); message.className = 'alert alert-error'; message.textContent = error.message; }
    });
    $('#supportReportPrint')?.addEventListener('click', () => window.print());
    $('#supportReportForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const message = $('#supportReportMessage');
        const submit = event.currentTarget.querySelector('button[type="submit"]');
        submit.disabled = true;
        try {
            const payload = supportCollectPayload();
            const result = await api('/api.php?action=support_report_save', {method: 'POST', body: JSON.stringify(payload)});
            supportFillForm(result.report);
            supportRenderPreview(result.report);
            supportReportsLoaded = false;
            await supportLoadHistory(true);
            message.className = 'alert alert-success';
            message.textContent = result.message;
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        } finally {
            submit.disabled = false;
        }
    });

    if ($('#supportReportForm')) supportResetForm();
JS;

$cssFragment = <<<'CSS'

/* SUPPORT_REPORTS_MODULE_CSS */
.support-reports-toolbar { display:flex; justify-content:space-between; align-items:flex-end; gap:18px; margin-bottom:18px; }
.support-reports-layout { display:grid; grid-template-columns:330px minmax(0,1fr); gap:18px; align-items:start; }
.support-reports-history-panel { padding:16px; }
.support-reports-history { display:grid; gap:9px; max-height:760px; overflow:auto; }
.support-history-item { width:100%; border:1px solid var(--line); background:#f8fafc; color:var(--text); padding:12px; border-radius:12px; text-align:left; transition:.15s ease; }
.support-history-item:hover { background:white; border-color:#b8cbff; }
.support-history-item.is-active { border-color:#1463ff; background:#eef4ff; box-shadow:0 0 0 3px rgba(20,99,255,.1); }
.support-report-title { grid-column:span 2; }
.support-sync-box, .support-tariff-box, .support-tasks-box { padding:16px; border:1px solid var(--line); border-radius:14px; background:#fbfcfe; }
.support-sync-box { display:grid; gap:12px; }
.support-sync-box p { margin:5px 0 0; }
.support-sync-box .button { width:100%; }
.support-report-summary { display:grid; grid-template-columns:repeat(5,minmax(0,1fr)); gap:10px; }
.support-report-tasks { display:grid; gap:10px; }
.support-task-row { display:grid; grid-template-columns:auto minmax(220px,.9fr) minmax(280px,1.4fr) auto; gap:12px; align-items:start; padding:13px; border:1px solid var(--line); border-radius:12px; background:white; }
.support-task-check { display:flex; align-items:center; gap:6px; padding-top:4px; font-size:12px; color:var(--muted); }
.support-task-main { min-width:0; }
.support-task-main strong { display:block; line-height:1.35; }
.support-task-meta { display:flex; flex-wrap:wrap; gap:6px 10px; margin-top:7px; color:var(--muted); font-size:11px; }
.support-task-tags { display:flex; flex-wrap:wrap; gap:5px; margin-top:7px; }
.support-task-tags span { padding:3px 6px; border-radius:999px; background:#eef2f6; color:#475467; font-size:9px; }
.support-task-result { display:grid; gap:6px; color:var(--muted); font-size:12px; }
.support-task-result textarea { min-height:92px; resize:vertical; }
.support-rich-grid { display:grid; grid-template-columns:minmax(0,1fr); gap:16px; }
.support-rich-grid .rich-editor { min-height:180px; resize:vertical; overflow:auto; }
.support-report-preview { margin-top:18px; }
.support-preview-table { min-width:900px; }
@media (max-width:1200px) { .support-reports-layout{grid-template-columns:1fr;} .support-report-summary{grid-template-columns:repeat(3,minmax(0,1fr));} .support-task-row{grid-template-columns:auto minmax(0,1fr);} .support-task-result{grid-column:2;} }
@media (max-width:760px) { .support-reports-toolbar{align-items:stretch;flex-direction:column;} .support-report-title{grid-column:auto;} .support-report-summary{grid-template-columns:1fr 1fr;} .support-task-row{grid-template-columns:1fr;} .support-task-result{grid-column:auto;} }
@media print { .sidebar,.topbar,.support-reports-toolbar,.support-reports-layout,#supportReportMessage,#supportReportPreview .panel-head .button{display:none!important;} .section{display:none!important;} #section-support-reports{display:block!important;} #supportReportPreview,#supportReportPreview.hidden{display:block!important;margin:0;padding:0;border:0;box-shadow:none;} }
CSS;

$schemaFragment = <<<'SQL'
-- SUPPORT_REPORTS_MODULE_SCHEMA
CREATE TABLE IF NOT EXISTS support_reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    title VARCHAR(190) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    audience VARCHAR(30) NOT NULL DEFAULT 'client',
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    included_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    carried_hours DECIMAL(10,2) NOT NULL DEFAULT 0,
    monthly_fee DECIMAL(18,2) NOT NULL DEFAULT 0,
    extra_hour_rate DECIMAL(18,2) NOT NULL DEFAULT 0,
    results_html MEDIUMTEXT NULL,
    next_plan_html MEDIUMTEXT NULL,
    recommendations_html MEDIUMTEXT NULL,
    notes_html MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_support_reports_project (project_id, updated_at),
    CONSTRAINT fk_support_reports_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
    CONSTRAINT fk_support_reports_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_report_tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    support_report_id BIGINT UNSIGNED NOT NULL,
    bitrix_task_id BIGINT UNSIGNED NULL,
    title VARCHAR(1000) NOT NULL,
    status VARCHAR(100) NULL,
    responsible_name VARCHAR(255) NULL,
    tags_json TEXT NOT NULL,
    elapsed_seconds BIGINT UNSIGNED NOT NULL DEFAULT 0,
    elapsed_entries INT UNSIGNED NOT NULL DEFAULT 0,
    included TINYINT(1) NOT NULL DEFAULT 1,
    client_result TEXT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_support_report_tasks_report (support_report_id, sort_order),
    CONSTRAINT fk_support_report_tasks_report FOREIGN KEY (support_report_id) REFERENCES support_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;

$backupDirectory = $root . '/storage/backups/support-reports-module-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}
$files = [
    $indexPath => 'index.php',
    $apiPath => 'api.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
    $schemaPath => 'schema.sql',
];
foreach ($files as $source => $name) {
    copy($source, $backupDirectory . '/' . $name);
}

try {
    $settingsButton = '                <button class="nav-link" data-section="settings">Настройки</button>';
    $index = sr3replace(
        $index,
        $settingsButton,
        "                <button class=\"nav-link\" data-section=\"support-reports\">Отчёты поддержки</button>\n" . $settingsButton,
        'пункт меню отчётов поддержки'
    );
    $index = sr3insertBefore(
        $index,
        '        <section id="section-settings" class="section">',
        $section . "\n",
        'раздел отчётов поддержки'
    );
    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=21', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=21', $index) ?? $index;

    $api = sr3replace(
        $api,
        'use SeoAnalytics\Repositories\ProjectRepository;',
        "use SeoAnalytics\\Repositories\\ProjectRepository;\n"
        . 'use SeoAnalytics\Repositories\SupportReportRepository;' . "\n"
        . 'use SeoAnalytics\Services\SupportReportService;',
        'импорты отчётов поддержки'
    );

    $unknownNeedles = [
        "        Security::json(['error' => 'Неизвестное действие.'], 404);",
        "        Security::json([\n            'error' => 'Неизвестное действие.'\n        ], 404);",
    ];
    $inserted = false;
    foreach ($unknownNeedles as $needle) {
        if (str_contains($api, $needle)) {
            $api = sr3insertBefore($api, $needle, $getApi, 'GET API отчётов поддержки');
            $inserted = true;
            break;
        }
    }
    if (!$inserted) {
        throw new RuntimeException('Не найдена точка вставки GET API.');
    }
    $api = sr3insertBefore($api, "    if (\$action === 'save_project') {", $postApi, 'POST API отчётов поддержки');

    $js = sr3insertBefore($js, '    async function loadDashboard() {', $jsFragment . "\n", 'JavaScript отчётов поддержки');
    $css .= "\n" . $cssFragment . "\n";
    if (!str_contains($schema, 'SUPPORT_REPORTS_MODULE_SCHEMA')) {
        $schema .= "\n\n" . $schemaFragment . "\n";
    }

    sr3write($repositoryPath, $repository);
    sr3write($servicePath, $service);
    sr3write($indexPath, $index);
    sr3write($apiPath, $api);
    sr3write($jsPath, $js);
    sr3write($cssPath, $css);
    sr3write($schemaPath, $schema);

    sr3lint($repositoryPath);
    sr3lint($servicePath);
    sr3lint($indexPath);
    sr3lint($apiPath);

    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();
    $statements = array_filter(array_map('trim', preg_split('/;\s*(?:\r?\n|$)/', $schemaFragment) ?: []));
    foreach ($statements as $statement) {
        $statement = preg_replace('/^--[^\n]*\n/', '', $statement) ?? $statement;
        if (trim($statement) !== '') {
            $pdo->exec($statement);
        }
    }

    sr3out('Модуль отчётов по технической поддержке установлен.');
    sr3out('- отдельная история отчётов;');
    sr3out('- загрузка задач и времени из Битрикс24;');
    sr3out('- ручной выбор задач для клиента;');
    sr3out('- расчёт остатка и перерасхода часов;');
    sr3out('- клиентские формулировки и рекомендации;');
    sr3out('- предпросмотр и печать в PDF.');
    sr3out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    @copy($backupDirectory . '/index.php', $indexPath);
    @copy($backupDirectory . '/api.php', $apiPath);
    @copy($backupDirectory . '/app.js', $jsPath);
    @copy($backupDirectory . '/app.css', $cssPath);
    @copy($backupDirectory . '/schema.sql', $schemaPath);
    @unlink($repositoryPath);
    @unlink($servicePath);
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
