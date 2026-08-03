<?php
declare(strict_types=1);

use SeoAnalytics\Services\ClientStructureDeniedException;
use SeoAnalytics\Services\LocalStructureAdminService;
use SeoAnalytics\Services\PortalAccessService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function lm24Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function lm24Input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        lm24Json(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $decoded;
}

function lm24SameOrigin(): void
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        lm24Json(['error' => 'Запрос отклонён проверкой безопасности.'], 403);
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === '' || $originHost === '' || $host !== $originHost) {
            lm24Json(['error' => 'Источник запроса не совпадает с порталом.'], 403);
        }
    }
}

try {
    $access = new PortalAccessService();
    $access->requireRoles(['administrator', 'moderator']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $service = new LocalStructureAdminService($access);
    $action = trim((string) ($_GET['action'] ?? 'context'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET' && $action === 'context') {
        $projectId = max(0, (int) ($_GET['project_id'] ?? 0));
        if ($projectId <= 0) {
            lm24Json(['error' => 'Проект не указан.'], 422);
        }
        lm24Json(['ok' => true, 'data' => $service->context($projectId)]);
    }

    if ($method !== 'POST') {
        lm24Json(['error' => 'Метод не поддерживается.'], 405);
    }
    lm24SameOrigin();
    $input = lm24Input();

    if ($action === 'move_project') {
        lm24Json(['ok' => true, 'data' => $service->moveProject($input)]);
    }
    if ($action === 'delete_site') {
        lm24Json(['ok' => true, 'data' => $service->deleteSite($input)]);
    }
    if ($action === 'delete_project') {
        lm24Json(['ok' => true, 'data' => $service->deleteProject($input)]);
    }

    lm24Json(['error' => 'Неизвестное действие.'], 404);
} catch (ClientStructureDeniedException $exception) {
    lm24Json(['error' => $exception->getMessage()], 403);
} catch (RuntimeException $exception) {
    lm24Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    lm24Json([
        'error' => 'Внутренняя ошибка локального управления структурой.',
        'details' => $exception->getMessage(),
    ], 500);
}
