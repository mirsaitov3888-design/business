<?php
declare(strict_types=1);

use SeoAnalytics\Services\ClientStructureDeniedException;
use SeoAnalytics\Services\PortalContextDeniedException;
use SeoAnalytics\Services\SiteOnboardingService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function so23Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function so23Input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        so23Json(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $decoded;
}

function so23SameOrigin(): void
{
    if (
        strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))
        !== 'xmlhttprequest'
    ) {
        so23Json(['error' => 'Запрос отклонён проверкой безопасности.'], 403);
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
            so23Json(['error' => 'Источник запроса не совпадает с порталом.'], 403);
        }
    }
}

try {
    $service = new SiteOnboardingService();
    $action = trim((string) ($_GET['action'] ?? 'context'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET' && $action === 'context') {
        $clientId = max(0, (int) ($_GET['client_id'] ?? 0));
        $projectId = max(0, (int) ($_GET['project_id'] ?? 0));
        $siteId = max(0, (int) ($_GET['site_id'] ?? 0));
        if ($clientId <= 0 || $projectId <= 0) {
            so23Json(['error' => 'Клиент или проект не указан.'], 422);
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        so23Json([
            'ok' => true,
            'data' => $service->context($clientId, $projectId, $siteId),
        ]);
    }

    if ($method !== 'POST') {
        so23Json(['error' => 'Метод не поддерживается.'], 405);
    }
    so23SameOrigin();
    $input = so23Input();

    if ($action === 'save') {
        $result = $service->save($input);
        $warning = trim((string) ($result['bitrix']['warning'] ?? ''));
        so23Json([
            'ok' => true,
            'message' => $warning === ''
                ? 'Сайт и подключения сохранены.'
                : 'Сайт сохранён, но Bitrix24 не обновлён: ' . $warning,
            'warning' => $warning !== '' ? $warning : null,
            'data' => $result,
        ]);
    }

    so23Json(['error' => 'Неизвестное действие.'], 404);
} catch (ClientStructureDeniedException|PortalContextDeniedException $exception) {
    so23Json(['error' => $exception->getMessage()], 403);
} catch (RuntimeException $exception) {
    so23Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    so23Json([
        'error' => 'Внутренняя ошибка подключения сайта.',
        'details' => $exception->getMessage(),
    ], 500);
}
