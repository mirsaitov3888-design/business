<?php
declare(strict_types=1);

use SeoAnalytics\Services\Bitrix24ClientOnboardingService;
use SeoAnalytics\Services\ClientStructureDeniedException;
use SeoAnalytics\Services\PortalAccessService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function b19Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function b19Input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        b19Json(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $decoded;
}

function b19SameOrigin(): void
{
    if (
        strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))
        !== 'xmlhttprequest'
    ) {
        b19Json(['error' => 'Запрос отклонён проверкой безопасности.'], 403);
    }
    $host = strtolower(preg_replace(
        '/:\d+$/',
        '',
        (string) ($_SERVER['HTTP_HOST'] ?? '')
    ) ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === '' || $originHost === '' || $host !== $originHost) {
            b19Json(['error' => 'Источник запроса не совпадает с порталом.'], 403);
        }
    }
}

try {
    $access = new PortalAccessService();
    $access->requireRoles(['administrator', 'moderator', 'manager']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $service = new Bitrix24ClientOnboardingService();
    $action = trim((string) ($_GET['action'] ?? 'catalog'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        if ($action === 'catalog') {
            $companyId = max(0, (int) ($_GET['company_id'] ?? 0));
            $clientId = max(0, (int) ($_GET['client_id'] ?? 0));
            b19Json([
                'ok' => true,
                'data' => $service->catalog(
                    $companyId > 0 ? $companyId : null,
                    $clientId > 0 ? $clientId : null
                ),
            ]);
        }
        if ($action === 'mapping') {
            $clientId = max(0, (int) ($_GET['client_id'] ?? 0));
            if ($clientId <= 0) {
                b19Json(['error' => 'Клиент не указан.'], 422);
            }
            b19Json([
                'ok' => true,
                'data' => $service->mapping($clientId),
            ]);
        }
        b19Json(['error' => 'Неизвестное действие.'], 404);
    }

    if ($method !== 'POST') {
        b19Json(['error' => 'Метод не поддерживается.'], 405);
    }
    b19SameOrigin();
    $input = b19Input();

    if ($action === 'save') {
        b19Json([
            'ok' => true,
            'message' => 'Клиент и проекты синхронизированы с Bitrix24.',
            'data' => $service->save($input),
        ]);
    }

    b19Json(['error' => 'Неизвестное действие.'], 404);
} catch (ClientStructureDeniedException $exception) {
    b19Json(['error' => $exception->getMessage()], 403);
} catch (RuntimeException $exception) {
    b19Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    b19Json([
        'error' => 'Внутренняя ошибка синхронизации с Bitrix24.',
        'details' => $exception->getMessage(),
    ], 500);
}
