<?php
declare(strict_types=1);

use SeoAnalytics\Services\ClientStructureDeniedException;
use SeoAnalytics\Services\LocalBitrixLinkService;
use SeoAnalytics\Services\PortalAccessService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function lb25Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lb25Input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        lb25Json(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $data;
}

function lb25SameOrigin(): void
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        lb25Json(['error' => 'Запрос отклонён проверкой безопасности.'], 403);
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === '' || $originHost === '' || $host !== $originHost) {
            lb25Json(['error' => 'Источник запроса не совпадает с порталом.'], 403);
        }
    }
}

try {
    (new PortalAccessService())->requireRoles(['administrator', 'moderator']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    $service = new LocalBitrixLinkService();
    $action = trim((string) ($_GET['action'] ?? 'context'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET' && $action === 'context') {
        lb25Json(['ok' => true, 'data' => $service->context()]);
    }
    if ($method !== 'POST') {
        lb25Json(['error' => 'Метод не поддерживается.'], 405);
    }
    lb25SameOrigin();
    $input = lb25Input();

    $result = match ($action) {
        'create_project' => $service->createLocalProject($input),
        'detach_client' => $service->detachClient($input),
        'detach_project' => $service->detachProject($input),
        default => throw new RuntimeException('Неизвестное действие.'),
    };
    lb25Json(['ok' => true, 'data' => $result]);
} catch (ClientStructureDeniedException $exception) {
    lb25Json(['error' => $exception->getMessage()], 403);
} catch (RuntimeException $exception) {
    lb25Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    lb25Json([
        'error' => 'Внутренняя ошибка локального управления связями.',
        'details' => $exception->getMessage(),
    ], 500);
}
