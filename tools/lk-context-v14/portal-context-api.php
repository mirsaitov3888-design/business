<?php
declare(strict_types=1);

use SeoAnalytics\Services\PortalContextDeniedException;
use SeoAnalytics\Services\PortalContextService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function portalContextJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function portalContextInput(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        portalContextJson(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $decoded;
}

function portalContextSameOrigin(): void
{
    if (
        strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))
        !== 'xmlhttprequest'
    ) {
        portalContextJson([
            'error' => 'Запрос отклонён проверкой безопасности.',
        ], 403);
    }

    $host = strtolower(
        preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))
        ?? ''
    );
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin === '') {
        return;
    }
    $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
    if ($host === '' || $originHost === '' || $host !== $originHost) {
        portalContextJson([
            'error' => 'Источник запроса не совпадает с порталом.',
        ], 403);
    }
}

try {
    $service = new PortalContextService();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = trim((string) ($_GET['action'] ?? 'context'));

    if ($method === 'GET' && $action === 'context') {
        $context = $service->context(
            isset($_GET['client_id']) ? (int) $_GET['client_id'] : null,
            isset($_GET['project_id']) ? (int) $_GET['project_id'] : null,
            true
        );
        portalContextJson(['ok' => true, 'context' => $context]);
    }

    if ($method === 'GET' && $action === 'sites') {
        $projectId = max(0, (int) ($_GET['project_id'] ?? 0));
        if ($projectId <= 0) {
            $context = $service->context(null, null, true);
            $projectId = (int) ($context['selected_project_id'] ?? 0);
        }
        portalContextJson([
            'ok' => true,
            'project_id' => $projectId,
            'sites' => $projectId > 0
                ? $service->sitesForProject($projectId)
                : [],
        ]);
    }

    if ($method !== 'POST' || $action !== 'select') {
        portalContextJson(['error' => 'Действие не поддерживается.'], 405);
    }

    portalContextSameOrigin();
    $input = portalContextInput();
    $clientId = max(0, (int) ($input['client_id'] ?? 0));
    $projectId = max(0, (int) ($input['project_id'] ?? 0));

    if ($projectId <= 0) {
        portalContextJson(['error' => 'Выберите проект.'], 422);
    }

    $service->requireProject($projectId);
    $context = $service->context(
        $clientId > 0 ? $clientId : null,
        $projectId,
        true
    );

    portalContextJson([
        'ok' => true,
        'context' => $context,
        'message' => 'Контекст проекта сохранён.',
    ]);
} catch (PortalContextDeniedException $exception) {
    portalContextJson(['error' => $exception->getMessage()], 403);
} catch (RuntimeException $exception) {
    portalContextJson(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    portalContextJson([
        'error' => 'Не удалось определить контекст личного кабинета.',
        'details' => $exception->getMessage(),
    ], 500);
}
