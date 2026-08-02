<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class ConversionGoalRepository
{
    public const CLASSIFICATIONS = [
        'unclassified' => 'Не классифицирована',
        'lead' => 'Лид',
        'assisted' => 'Вспомогательная конверсия',
        'micro' => 'Микроконверсия',
    ];

    public const SOURCES = [
        'metrika' => 'Яндекс Метрика',
        'direct' => 'Яндекс Директ',
        'crm' => 'CRM',
        'manual' => 'Вручную',
        'other' => 'Другое',
    ];

    public function list(int $projectId, bool $includeInactive = true): array
    {
        $sql = 'SELECT
                    g.*,
                    creator.name AS creator_name,
                    updater.name AS updater_name
                FROM conversion_goals g
                INNER JOIN users creator ON creator.id = g.created_by
                INNER JOIN users updater ON updater.id = g.updated_by
                WHERE g.project_id = :project_id';
        if (!$includeInactive) {
            $sql .= ' AND g.active = 1';
        }
        $sql .= ' ORDER BY
                    FIELD(g.classification, "lead", "assisted", "micro", "unclassified"),
                    g.active DESC,
                    g.name ASC,
                    g.id ASC';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row = $this->normalizeStored($row);
        }
        unset($row);

        return $rows;
    }

    public function counts(int $projectId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                COUNT(*) AS total,
                SUM(active = 1) AS active,
                SUM(active = 1 AND classification = "lead") AS lead,
                SUM(active = 1 AND classification = "assisted") AS assisted,
                SUM(active = 1 AND classification = "micro") AS micro,
                SUM(active = 1 AND classification = "unclassified") AS unclassified
             FROM conversion_goals
             WHERE project_id = :project_id'
        );
        $stmt->execute(['project_id' => $projectId]);
        $counts = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ([
            'total',
            'active',
            'lead',
            'assisted',
            'micro',
            'unclassified',
        ] as $key) {
            $counts[$key] = (int) ($counts[$key] ?? 0);
        }

        return $counts;
    }

    public function save(array $data, int $userId, int $projectId): int
    {
        if ($projectId <= 0 || $userId <= 0) {
            throw new RuntimeException('Не удалось определить проект или пользователя.');
        }

        $goal = $this->sanitize($data);
        $id = max(0, (int) ($data['id'] ?? 0));
        $pdo = Database::pdo();

        if ($id > 0) {
            $before = $this->find($id, $projectId);
            if (!$before) {
                throw new RuntimeException('Цель не найдена.');
            }

            $stmt = $pdo->prepare(
                'UPDATE conversion_goals SET
                    source_system = :source_system,
                    external_id = :external_id,
                    name = :name,
                    classification = :classification,
                    active = :active,
                    notes = :notes,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id AND project_id = :project_id'
            );
            $stmt->execute($goal + [
                'updated_by' => $userId,
                'id' => $id,
                'project_id' => $projectId,
            ]);
            $after = $this->find($id, $projectId);
            $this->recordChange(
                $id,
                $projectId,
                $userId,
                'updated',
                $before,
                $after
            );
            return $id;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO conversion_goals
             (
                project_id,
                source_system,
                external_id,
                name,
                classification,
                active,
                notes,
                created_by,
                updated_by,
                created_at,
                updated_at
             )
             VALUES
             (
                :project_id,
                :source_system,
                :external_id,
                :name,
                :classification,
                :active,
                :notes,
                :created_by,
                :updated_by,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                active = VALUES(active),
                notes = CASE
                    WHEN VALUES(notes) IS NULL THEN notes
                    ELSE VALUES(notes)
                END,
                updated_by = VALUES(updated_by),
                updated_at = CURRENT_TIMESTAMP'
        );
        $stmt->execute($goal + [
            'project_id' => $projectId,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);

        $newId = (int) $pdo->lastInsertId();
        if ($newId <= 0) {
            $existing = $pdo->prepare(
                'SELECT id FROM conversion_goals
                 WHERE project_id = :project_id
                   AND source_system = :source_system
                   AND external_id = :external_id
                 LIMIT 1'
            );
            $existing->execute([
                'project_id' => $projectId,
                'source_system' => $goal['source_system'],
                'external_id' => $goal['external_id'],
            ]);
            $newId = (int) $existing->fetchColumn();
        }

        if ($newId <= 0) {
            throw new RuntimeException('Не удалось сохранить цель.');
        }

        $this->recordChange(
            $newId,
            $projectId,
            $userId,
            'created',
            null,
            $this->find($newId, $projectId)
        );

        return $newId;
    }

    public function delete(int $id, int $projectId, int $userId): bool
    {
        $before = $this->find($id, $projectId);
        if (!$before) {
            return false;
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'DELETE FROM conversion_goals
                 WHERE id = :id AND project_id = :project_id'
            );
            $stmt->execute(['id' => $id, 'project_id' => $projectId]);
            if ($stmt->rowCount() <= 0) {
                $pdo->rollBack();
                return false;
            }

            $this->recordChange(
                $id,
                $projectId,
                $userId,
                'deleted',
                $before,
                null,
                $pdo
            );
            $pdo->commit();
            return true;
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function syncProjectGoals(int $projectId, int $userId): array
    {
        $pdo = Database::pdo();
        if (!$this->columnExists('projects', 'goal_ids_json')) {
            return [
                'available' => false,
                'found' => 0,
                'created' => 0,
                'updated' => 0,
                'message' => 'В таблице projects отсутствует поле goal_ids_json.',
            ];
        }

        $stmt = $pdo->prepare(
            'SELECT goal_ids_json FROM projects WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $projectId]);
        $raw = $stmt->fetchColumn();
        $decoded = is_string($raw) && trim($raw) !== ''
            ? json_decode($raw, true)
            : [];

        $goals = $this->extractGoals($decoded);
        $created = 0;
        $updated = 0;

        foreach ($goals as $goal) {
            $existing = $pdo->prepare(
                'SELECT id, name FROM conversion_goals
                 WHERE project_id = :project_id
                   AND source_system = "metrika"
                   AND external_id = :external_id
                 LIMIT 1'
            );
            $existing->execute([
                'project_id' => $projectId,
                'external_id' => $goal['external_id'],
            ]);
            $row = $existing->fetch(PDO::FETCH_ASSOC);

            $id = $this->save([
                'id' => is_array($row) ? (int) $row['id'] : 0,
                'source_system' => 'metrika',
                'external_id' => $goal['external_id'],
                'name' => $goal['name'],
                'classification' => is_array($row)
                    ? (string) ($this->find((int) $row['id'], $projectId)['classification'] ?? 'unclassified')
                    : 'unclassified',
                'active' => true,
                'notes' => null,
            ], $userId, $projectId);

            if (is_array($row)) {
                $updated++;
            } elseif ($id > 0) {
                $created++;
            }
        }

        return [
            'available' => true,
            'found' => count($goals),
            'created' => $created,
            'updated' => $updated,
            'message' => count($goals) > 0
                ? 'Цели проекта синхронизированы.'
                : 'В проекте пока не указаны цели Метрики.',
        ];
    }

    public function find(int $id, int $projectId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM conversion_goals
             WHERE id = :id AND project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'project_id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeStored($row) : null;
    }

    private function sanitize(array $data): array
    {
        $source = (string) ($data['source_system'] ?? 'manual');
        if (!isset(self::SOURCES[$source])) {
            $source = 'other';
        }

        $classification = (string) ($data['classification'] ?? 'unclassified');
        if (!isset(self::CLASSIFICATIONS[$classification])) {
            $classification = 'unclassified';
        }

        $externalId = $this->text($data['external_id'] ?? '', 190);
        if ($externalId === '') {
            throw new RuntimeException('Укажите ID или ключ цели.');
        }

        $name = $this->text($data['name'] ?? '', 255);
        if ($name === '') {
            $name = 'Цель ' . $externalId;
        }

        return [
            'source_system' => $source,
            'external_id' => $externalId,
            'name' => $name,
            'classification' => $classification,
            'active' => $this->boolean($data['active'] ?? true) ? 1 : 0,
            'notes' => $this->nullableText($data['notes'] ?? null, 5000),
        ];
    }

    private function extractGoals(mixed $value): array
    {
        $found = [];
        $walk = function (mixed $item) use (&$walk, &$found): void {
            if (is_scalar($item) && !is_bool($item)) {
                $id = trim((string) $item);
                if ($id !== '') {
                    $found[$id] = [
                        'external_id' => mb_substr($id, 0, 190),
                        'name' => 'Цель #' . mb_substr($id, 0, 190),
                    ];
                }
                return;
            }

            if (!is_array($item)) {
                return;
            }

            $candidate = $item['id']
                ?? $item['goal_id']
                ?? $item['goalId']
                ?? $item['external_id']
                ?? null;
            if ($candidate !== null && !is_array($candidate)) {
                $id = trim((string) $candidate);
                if ($id !== '') {
                    $name = trim((string) (
                        $item['name']
                        ?? $item['title']
                        ?? $item['goal_name']
                        ?? ('Цель #' . $id)
                    ));
                    $found[$id] = [
                        'external_id' => mb_substr($id, 0, 190),
                        'name' => mb_substr($name !== '' ? $name : ('Цель #' . $id), 0, 255),
                    ];
                    return;
                }
            }

            foreach ($item as $nested) {
                $walk($nested);
            }
        };
        $walk($value);
        return array_values($found);
    }

    private function recordChange(
        int $goalId,
        int $projectId,
        int $userId,
        string $action,
        ?array $before,
        ?array $after,
        ?PDO $pdo = null
    ): void {
        $pdo ??= Database::pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO conversion_goal_changes
             (
                goal_id,
                project_id,
                user_id,
                action_key,
                before_json,
                after_json,
                created_at
             )
             VALUES
             (
                :goal_id,
                :project_id,
                :user_id,
                :action_key,
                :before_json,
                :after_json,
                CURRENT_TIMESTAMP
             )'
        );
        $stmt->execute([
            'goal_id' => $goalId,
            'project_id' => $projectId,
            'user_id' => $userId,
            'action_key' => $action,
            'before_json' => $before === null
                ? null
                : json_encode($before, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'after_json' => $after === null
                ? null
                : json_encode($after, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function normalizeStored(array $row): array
    {
        foreach (['id', 'project_id', 'created_by', 'updated_by', 'active'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = (int) $row[$key];
            }
        }
        $row['classification_name'] = self::CLASSIFICATIONS[$row['classification']]
            ?? $row['classification'];
        $row['source_name'] = self::SOURCES[$row['source_system']]
            ?? $row['source_system'];
        return $row;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(
            mb_strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'y', 'да', 'д', 'on'],
            true
        );
    }

    private function text(mixed $value, int $limit): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $limit);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = $this->text($value, $limit);
        return $value === '' ? null : $value;
    }
}
