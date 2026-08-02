<?php
declare(strict_types=1);

use SeoAnalytics\Core\Auth;
use SeoAnalytics\Repositories\ProjectRepository;
use SeoAnalytics\Repositories\SalesRepository;
use SeoAnalytics\Services\PortalAccessService;
use SeoAnalytics\Services\SalesImportService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function p1Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function p1Input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        p1Json(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $decoded;
}

function p1SameOrigin(): void
{
    $requestedWith = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    if ($requestedWith !== 'xmlhttprequest') {
        p1Json(['error' => 'Запрос отклонён проверкой безопасности.'], 403);
    }

    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === '' || $originHost === '' || $originHost !== $host) {
            p1Json(['error' => 'Источник запроса не совпадает с порталом.'], 403);
        }
    }
}

function p1Dates(array $source): array
{
    $from = trim((string) ($source['date_from'] ?? date('Y-m-01')));
    $to = trim((string) ($source['date_to'] ?? date('Y-m-d')));
    foreach ([$from, $to] as $date) {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if (!$parsed instanceof DateTimeImmutable || $parsed->format('Y-m-d') !== $date) {
            p1Json(['error' => 'Укажите корректный период.'], 422);
        }
    }
    if ($from > $to) {
        p1Json(['error' => 'Дата начала периода позже даты окончания.'], 422);
    }
    return [$from, $to];
}

function p1Audit(string $event, array $context): void
{
    if (class_exists(Auth::class) && method_exists(Auth::class, 'audit')) {
        try {
            Auth::audit($event, $context);
        } catch (Throwable) {
        }
    }
}

try {
    $access = new PortalAccessService();
    $user = $access->requireRoles(['administrator', 'moderator', 'manager']);
    $project = (new ProjectRepository())->firstActive();

    if (!$project) {
        p1Json(['error' => 'Сначала настройте активный проект.'], 422);
    }

    $projectId = (int) ($project['id'] ?? 0);
    if ($projectId <= 0) {
        p1Json(['error' => 'Не удалось определить активный проект.'], 422);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $action = trim((string) ($_GET['action'] ?? 'context'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $repository = new SalesRepository();

    if ($action === 'template') {
        $content = (new SalesImportService())->templateCsv();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="sales-import-template.csv"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    if ($action === 'context') {
        p1Json([
            'ok' => true,
            'project' => [
                'id' => $projectId,
                'name' => (string) ($project['name'] ?? $project['title'] ?? ('Проект #' . $projectId)),
            ],
            'role' => (string) ($user['role'] ?? ''),
            'channels' => SalesRepository::CHANNELS,
            'statuses' => SalesRepository::STATUSES,
            'capabilities' => [
                'csv' => true,
                'xlsx' => class_exists(ZipArchive::class),
                'max_rows' => 5000,
                'max_file_mb' => 10,
            ],
            'defaults' => [
                'date_from' => date('Y-m-01'),
                'date_to' => date('Y-m-d'),
                'occurred_at' => date('Y-m-d'),
            ],
        ]);
    }

    if ($action === 'list') {
        [$dateFrom, $dateTo] = p1Dates($_GET);
        $batchesStmt = SeoAnalytics\Core\Database::pdo()->prepare(
            'SELECT
                id,
                original_name,
                file_type,
                rows_total,
                rows_imported,
                rows_skipped,
                rows_failed,
                created_at
             FROM sales_import_batches
             WHERE project_id = :project_id
             ORDER BY id DESC
             LIMIT 20'
        );
        $batchesStmt->execute(['project_id' => $projectId]);
        $batches = $batchesStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($batches as &$batch) {
            foreach (['id', 'rows_total', 'rows_imported', 'rows_skipped', 'rows_failed'] as $key) {
                $batch[$key] = (int) $batch[$key];
            }
        }
        unset($batch);

        p1Json([
            'ok' => true,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'records' => $repository->list($projectId, $dateFrom, $dateTo),
            'summary' => $repository->summary($projectId, $dateFrom, $dateTo),
            'imports' => $batches,
        ]);
    }

    if ($method !== 'POST') {
        p1Json(['error' => 'Метод не поддерживается.'], 405);
    }
    p1SameOrigin();

    if ($action === 'save') {
        $input = p1Input();
        $id = $repository->save($input, (int) $user['id'], $projectId);
        p1Audit('p1_sales_record_saved', [
            'project_id' => $projectId,
            'sales_record_id' => $id,
            'status' => $input['status'] ?? null,
        ]);
        p1Json([
            'ok' => true,
            'id' => $id,
            'message' => 'Запись продажи сохранена.',
        ]);
    }

    if ($action === 'delete') {
        $input = p1Input();
        $id = max(0, (int) ($input['id'] ?? 0));
        if ($id <= 0 || !$repository->delete($id, $projectId)) {
            p1Json(['error' => 'Запись не найдена.'], 404);
        }
        p1Audit('p1_sales_record_deleted', [
            'project_id' => $projectId,
            'sales_record_id' => $id,
        ]);
        p1Json(['ok' => true, 'message' => 'Запись удалена.']);
    }

    if ($action === 'import') {
        $parsed = (new SalesImportService())->parseUpload(
            is_array($_FILES['sales_file'] ?? null)
                ? $_FILES['sales_file']
                : []
        );
        $result = $repository->import(
            $parsed['rows'],
            [
                'original_name' => $parsed['original_name'],
                'file_type' => $parsed['file_type'],
                'headers' => $parsed['headers'],
                'column_map' => $parsed['column_map'],
                'truncated' => $parsed['truncated'],
                'errors' => $parsed['errors'],
            ],
            (int) $user['id'],
            $projectId
        );
        p1Audit('p1_sales_imported', [
            'project_id' => $projectId,
            'batch_id' => $result['batch_id'],
            'rows_imported' => $result['rows_imported'],
            'rows_skipped' => $result['rows_skipped'],
            'rows_failed' => $result['rows_failed'],
        ]);
        p1Json([
            'ok' => true,
            'result' => $result,
            'message' => 'Импорт завершён.',
        ]);
    }

    p1Json(['error' => 'Неизвестное действие.'], 404);
} catch (RuntimeException $exception) {
    p1Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    p1Json([
        'error' => 'Внутренняя ошибка раздела P1.',
        'details' => $exception->getMessage(),
    ], 500);
}
