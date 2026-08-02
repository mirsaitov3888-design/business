<?php
declare(strict_types=1);

use SeoAnalytics\Services\PortalContextDeniedException;
use SeoAnalytics\Services\ProjectSourceService;

require __DIR__ . '/app/bootstrap.php';

function lk3Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function lk3Body(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return $_POST;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function lk3SameOrigin(): void
{
    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value === '') {
            continue;
        }
        $sourceHost = strtolower((string) parse_url($value, PHP_URL_HOST));
        if ($sourceHost !== '' && $host !== '' && $sourceHost !== $host) {
            lk3Json(['error' => 'Запрос отклонён.'], 403);
        }
    }
}

try {
    $action = trim((string) ($_GET['action'] ?? 'context'));
    $service = new ProjectSourceService();

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($action === 'context') {
            lk3Json(['context' => $service->context(false)]);
        }
        lk3Json(['error' => 'Неизвестное действие.'], 404);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        lk3Json(['error' => 'Метод не поддерживается.'], 405);
    }

    lk3SameOrigin();
    $data = lk3Body();

    if ($action === 'save_source') {
        $id = $service->saveSource($data);
        lk3Json([
            'ok' => true,
            'id' => $id,
            'context' => $service->context(false),
        ]);
    }

    if ($action === 'save_report_scope') {
        $service->saveReportScope(
            (int) ($data['report_id'] ?? 0),
            (int) ($data['project_id'] ?? 0),
            is_array($data['site_ids'] ?? null) ? $data['site_ids'] : []
        );
        lk3Json(['ok' => true]);
    }

    if ($action === 'save_goal_scope') {
        $siteId = array_key_exists('site_id', $data)
            && (int) $data['site_id'] > 0
            ? (int) $data['site_id']
            : null;
        $service->saveGoalScope(
            (int) ($data['goal_id'] ?? 0),
            (int) ($data['project_id'] ?? 0),
            $siteId
        );
        lk3Json(['ok' => true]);
    }

    lk3Json(['error' => 'Неизвестное действие.'], 404);
} catch (PortalContextDeniedException $exception) {
    lk3Json(['error' => $exception->getMessage()], 403);
} catch (RuntimeException $exception) {
    lk3Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    error_log('LK3 sources API: ' . $exception->getMessage());
    lk3Json(['error' => 'Внутренняя ошибка сервера.'], 500);
}
