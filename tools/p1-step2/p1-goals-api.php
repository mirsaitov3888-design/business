<?php
declare(strict_types=1);

use SeoAnalytics\Core\Auth;
use SeoAnalytics\Repositories\ConversionGoalRepository;
use SeoAnalytics\Repositories\ProjectRepository;
use SeoAnalytics\Services\PortalAccessService;

require __DIR__ . '/app/bootstrap.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function p12Json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
    exit;
}

function p12Input(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        p12Json(['error' => 'Некорректный JSON-запрос.'], 400);
    }
    return $decoded;
}

function p12SameOrigin(): void
{
    if (strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) !== 'xmlhttprequest') {
        p12Json(['error' => 'Запрос отклонён проверкой безопасности.'], 403);
    }

    $host = strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?? '');
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
    if ($origin !== '') {
        $originHost = strtolower((string) parse_url($origin, PHP_URL_HOST));
        if ($host === '' || $originHost === '' || $host !== $originHost) {
            p12Json(['error' => 'Источник запроса не совпадает с порталом.'], 403);
        }
    }
}

function p12Audit(string $event, array $context): void
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
        p12Json(['error' => 'Сначала настройте активный проект.'], 422);
    }

    $projectId = (int) ($project['id'] ?? 0);
    if ($projectId <= 0) {
        p12Json(['error' => 'Не удалось определить активный проект.'], 422);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $repository = new ConversionGoalRepository();
    $action = trim((string) ($_GET['action'] ?? 'context'));
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

    if ($action === 'context') {
        p12Json([
            'ok' => true,
            'project' => [
                'id' => $projectId,
                'name' => (string) ($project['name'] ?? $project['title'] ?? ('Проект #' . $projectId)),
            ],
            'role' => (string) ($user['role'] ?? ''),
            'classifications' => ConversionGoalRepository::CLASSIFICATIONS,
            'sources' => ConversionGoalRepository::SOURCES,
        ]);
    }

    if ($action === 'list') {
        p12Json([
            'ok' => true,
            'goals' => $repository->list($projectId, true),
            'counts' => $repository->counts($projectId),
        ]);
    }

    if ($method !== 'POST') {
        p12Json(['error' => 'Метод не поддерживается.'], 405);
    }
    p12SameOrigin();

    if ($action === 'save') {
        $input = p12Input();
        $id = $repository->save($input, (int) $user['id'], $projectId);
        p12Audit('p1_goal_classification_saved', [
            'project_id' => $projectId,
            'conversion_goal_id' => $id,
            'classification' => $input['classification'] ?? null,
        ]);
        p12Json([
            'ok' => true,
            'id' => $id,
            'message' => 'Классификация цели сохранена.',
        ]);
    }

    if ($action === 'delete') {
        $input = p12Input();
        $id = max(0, (int) ($input['id'] ?? 0));
        if ($id <= 0 || !$repository->delete($id, $projectId, (int) $user['id'])) {
            p12Json(['error' => 'Цель не найдена.'], 404);
        }
        p12Audit('p1_goal_classification_deleted', [
            'project_id' => $projectId,
            'conversion_goal_id' => $id,
        ]);
        p12Json(['ok' => true, 'message' => 'Цель удалена.']);
    }

    if ($action === 'sync') {
        $result = $repository->syncProjectGoals($projectId, (int) $user['id']);
        p12Audit('p1_goal_classification_synced', [
            'project_id' => $projectId,
            'found' => $result['found'] ?? 0,
            'created' => $result['created'] ?? 0,
            'updated' => $result['updated'] ?? 0,
        ]);
        p12Json([
            'ok' => true,
            'result' => $result,
            'message' => (string) ($result['message'] ?? 'Синхронизация завершена.'),
        ]);
    }

    p12Json(['error' => 'Неизвестное действие.'], 404);
} catch (RuntimeException $exception) {
    p12Json(['error' => $exception->getMessage()], 422);
} catch (Throwable $exception) {
    p12Json([
        'error' => 'Внутренняя ошибка классификации целей.',
        'details' => $exception->getMessage(),
    ], 500);
}
