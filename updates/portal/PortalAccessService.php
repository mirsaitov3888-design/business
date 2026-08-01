<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class PortalAccessService
{
    public function currentUserId(): int
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $candidates = [
            $_SESSION['user_id'] ?? null,
            $_SESSION['auth_user_id'] ?? null,
            $_SESSION['uid'] ?? null,
            $_SESSION['id'] ?? null,
            is_array($_SESSION['user'] ?? null) ? ($_SESSION['user']['id'] ?? null) : null,
            is_array($_SESSION['auth_user'] ?? null) ? ($_SESSION['auth_user']['id'] ?? null) : null,
        ];

        foreach ($candidates as $candidate) {
            $id = (int) $candidate;
            if ($id > 0) {
                return $id;
            }
        }

        $recursiveId = $this->findUserIdInSession($_SESSION ?? []);
        if ($recursiveId > 0) {
            return $recursiveId;
        }

        $authClass = '\\SeoAnalytics\\Core\\Auth';
        if (class_exists($authClass)) {
            foreach ([
                'id', 'userId', 'currentUserId', 'user', 'currentUser',
                'getUser', 'authenticatedUser', 'profile', 'me',
            ] as $method) {
                if (!method_exists($authClass, $method)) {
                    continue;
                }
                try {
                    $reflection = new \ReflectionMethod($authClass, $method);
                    if (!$reflection->isStatic() || $reflection->getNumberOfRequiredParameters() > 0) {
                        continue;
                    }
                    $result = $authClass::$method();
                    if (is_array($result)) {
                        $result = $result['id'] ?? $result['user_id'] ?? null;
                    } elseif (is_object($result)) {
                        $result = $result->id ?? $result->user_id ?? null;
                    }
                    $id = (int) $result;
                    if ($id > 0) {
                        return $id;
                    }
                } catch (\Throwable) {
                }
            }
        }

        $email = $this->findEmailInSession($_SESSION ?? []);
        if ($email !== '') {
            $stmt = Database::pdo()->prepare(
                'SELECT id FROM users WHERE LOWER(email) = LOWER(:email) LIMIT 1'
            );
            $stmt->execute(['email' => $email]);
            $id = (int) $stmt->fetchColumn();
            if ($id > 0) {
                return $id;
            }
        }

        // Безопасный миграционный fallback для старой одно-пользовательской установки.
        $rows = Database::pdo()->query(
            "SELECT id FROM users WHERE account_status = 'active' ORDER BY id LIMIT 2"
        )->fetchAll(PDO::FETCH_COLUMN);
        if (count($rows) === 1) {
            return (int) $rows[0];
        }

        return 0;
    }

    public function currentUser(): array
    {
        $id = $this->currentUserId();
        if ($id <= 0) {
            throw new RuntimeException('Не удалось определить текущего пользователя.');
        }

        $stmt = Database::pdo()->prepare(
            'SELECT id, name, email, role, account_status FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            throw new RuntimeException('Текущий пользователь не найден.');
        }

        $user['id'] = (int) $user['id'];
        $user['role'] = $this->normalizeRole((string) ($user['role'] ?? 'administrator'));
        $user['account_status'] = (string) ($user['account_status'] ?? 'active');
        return $user;
    }

    public function role(): string
    {
        return (string) $this->currentUser()['role'];
    }

    public function normalizeRole(string $role): string
    {
        $role = strtolower(trim($role));
        return match ($role) {
            'administrator', 'admin', 'superadmin', 'super_admin', 'owner', 'root' => 'administrator',
            'moderator', 'moder' => 'moderator',
            'manager', 'specialist', 'employee' => 'manager',
            'client', 'customer', 'user' => 'client',
            default => 'client',
        };
    }

    public function requireRoles(array $roles): array
    {
        $user = $this->currentUser();
        if (!in_array($user['role'], $roles, true)) {
            $this->deny();
        }
        return $user;
    }

    public function canManageClients(): bool
    {
        return in_array($this->role(), ['administrator', 'moderator'], true);
    }

    public function accessibleClientIds(): array
    {
        $user = $this->currentUser();
        $pdo = Database::pdo();

        if (in_array($user['role'], ['administrator', 'moderator'], true)) {
            return array_map('intval', $pdo->query('SELECT id FROM clients')->fetchAll(PDO::FETCH_COLUMN));
        }

        if ($user['role'] === 'manager') {
            $stmt = $pdo->prepare('SELECT id FROM clients WHERE manager_user_id = :user_id');
            $stmt->execute(['user_id' => $user['id']]);
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $stmt = $pdo->prepare('SELECT client_id FROM client_users WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $user['id']]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function canViewClient(int $clientId): bool
    {
        return $clientId > 0 && in_array($clientId, $this->accessibleClientIds(), true);
    }

    public function requireClient(int $clientId): void
    {
        if (!$this->canViewClient($clientId)) {
            $this->deny('Нет доступа к выбранному клиенту.');
        }
    }

    public function visibleSections(): array
    {
        return match ($this->role()) {
            'administrator' => ['dashboard', 'analyst', 'webmaster', 'reports', 'clients', 'notifications', 'bitrix24', 'system-updates', 'support-reports', 'site-monitoring', 'settings'],
            'moderator' => ['dashboard', 'analyst', 'webmaster', 'reports', 'clients', 'notifications', 'support-reports', 'site-monitoring'],
            'manager' => ['dashboard', 'analyst', 'webmaster', 'reports', 'clients', 'notifications', 'support-reports', 'site-monitoring'],
            default => ['dashboard', 'reports', 'notifications', 'support-reports', 'site-monitoring'],
        };
    }

    public function permissions(): array
    {
        return [
            'manage_clients' => $this->canManageClients(),
            'view_assigned_clients' => in_array($this->role(), ['administrator', 'moderator', 'manager'], true),
            'create_notifications' => in_array($this->role(), ['administrator', 'moderator', 'manager'], true),
            'manage_system' => $this->role() === 'administrator',
        ];
    }

    public function guardAction(string $action): void
    {
        $action = trim($action);
        if ($action === '') {
            return;
        }

        $adminOnly = preg_match(
            '/^(system_update|system_updates|bitrix24_(save|test|disconnect|settings)|monitoring_save_notification_settings)/',
            $action
        ) === 1;
        if ($adminOnly && $this->role() !== 'administrator') {
            $this->deny();
        }

        if (in_array($action, ['save_project', 'delete_project'], true)) {
            $this->requireRoles(['administrator', 'moderator']);
        }
    }

    private function findUserIdInSession(mixed $value, int $depth = 0): int
    {
        if ($depth > 4 || !is_array($value)) {
            return 0;
        }
        foreach ($value as $key => $item) {
            $normalizedKey = strtolower((string) $key);
            if (in_array($normalizedKey, ['user_id', 'userid', 'uid', 'id'], true)) {
                $id = (int) $item;
                if ($id > 0) {
                    return $id;
                }
            }
            if (is_array($item)) {
                $id = $this->findUserIdInSession($item, $depth + 1);
                if ($id > 0) {
                    return $id;
                }
            }
        }
        return 0;
    }

    private function findEmailInSession(mixed $value, int $depth = 0): string
    {
        if ($depth > 4 || !is_array($value)) {
            return '';
        }
        foreach ($value as $key => $item) {
            if (strtolower((string) $key) === 'email' && is_string($item) && filter_var($item, FILTER_VALIDATE_EMAIL)) {
                return trim($item);
            }
            if (is_array($item)) {
                $email = $this->findEmailInSession($item, $depth + 1);
                if ($email !== '') {
                    return $email;
                }
            }
        }
        return '';
    }

    private function deny(string $message = 'Недостаточно прав для выполнения операции.'): never
    {
        $securityClass = '\\SeoAnalytics\\Core\\Security';
        if (class_exists($securityClass) && method_exists($securityClass, 'json')) {
            $securityClass::json(['error' => $message], 403);
        }
        throw new RuntimeException($message);
    }
}
