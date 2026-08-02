<?php
declare(strict_types=1);

use SeoAnalytics\Services\ClientStructureDeniedException;
use SeoAnalytics\Services\ClientStructureService;
use SeoAnalytics\Services\PortalAccessService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function lk2Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function lk2Input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        lk2Json(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $decoded;
}

function lk2SameOrigin(): void
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        lk2Json(['error' => 'Запрос отклонён проверкой безопасности.'], 403);
    }
    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === '' || $originHost === '' || $host !== $originHost) {
            lk2Json(['error' => 'Источник запроса не совпадает с порталом.'], 403);
        }
    }
}

try {
    $access = new PortalAccessService();
    $user = $access->requireRoles([
        'administrator', 'moderator', 'manager', 'client',
    ]);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $service = new ClientStructureService($access);
    $action = trim((string) ($_GET['action'] ?? 'context'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($method === 'GET') {
        if ($action === 'context') {
            lk2Json(['ok' => true, 'context' => $service->context()]);
        }
        if ($action === 'client') {
            $clientId = max(0, (int) ($_GET['client_id'] ?? 0));
            lk2Json(['ok' => true, 'data' => $service->client($clientId)]);
        }
        lk2Json(['error' => 'Неизвестное действие.'], 404);
    }

    if ($method !== 'POST') {
        lk2Json(['error' => 'Метод не поддерживается.'], 405);
    }
    lk2SameOrigin();
    $input = lk2Input();

    if ($action === 'save_client') {
        $id = $service->saveClient($input);
        lk2Json(['ok' => true, 'id' => $id, 'message' => 'Карточка клиента сохранена.']);
    }
    if ($action === 'save_client_users') {
        $clientId = max(0, (int) ($input['client_id'] ?? 0));
        $service->saveClientUsers(
            $clientId,
            is_array($input['user_ids'] ?? null) ? $input['user_ids'] : []
        );
        lk2Json(['ok' => true, 'message' => 'Пользователи клиентского кабинета сохранены.']);
    }
    if ($action === 'save_project') {
        $id = $service->saveProject($input);
        lk2Json(['ok' => true, 'id' => $id, 'message' => 'Проект сохранён.']);
    }
    if ($action === 'archive_project') {
        $service->archiveProject(
            max(0, (int) ($input['client_id'] ?? 0)),
            max(0, (int) ($input['project_id'] ?? 0))
        );
        lk2Json(['ok' => true, 'message' => 'Проект перенесён в архив.']);
    }
    if ($action === 'save_site') {
        $id = $service->saveSite($input);
        lk2Json(['ok' => true, 'id' => $id, 'message' => 'Сайт проекта сохранён.']);
    }
    if ($action === 'archive_site') {
        $service->archiveSite(
            max(0, (int) ($input['client_id'] ?? 0)),
            max(0, (int) ($input['project_id'] ?? 0)),
            max(0, (int) ($input['site_id'] ?? 0))
        );
        lk2Json(['ok' => true, 'message' => 'Сайт перенесён в архив.']);
    }
    if ($action === 'reorder_sites') {
        $service->reorderSites(
            max(0, (int) ($input['client_id'] ?? 0)),
            max(0, (int) ($input['project_id'] ?? 0)),
            is_array($input['site_ids'] ?? null) ? $input['site_ids'] : []
        );
        lk2Json(['ok' => true, 'message' => 'Порядок сайтов сохранён.']);
    }

    lk2Json(['error' => 'Неизвестное действие.'], 404);
} catch (ClientStructureDeniedException $exception) {
    lk2Json(['error' => $exception->getMessage()], 403);
} catch (RuntimeException $exception) {
    lk2Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    lk2Json([
        'error' => 'Внутренняя ошибка управления клиентами.',
        'details' => $exception->getMessage(),
    ], 500);
}
