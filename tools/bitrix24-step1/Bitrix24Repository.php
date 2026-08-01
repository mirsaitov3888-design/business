<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class Bitrix24Repository
{
    public function link(int $projectId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM bitrix24_project_links
             WHERE project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        foreach ([
            'id',
            'project_id',
            'bitrix_group_id',
            'bitrix_company_id',
        ] as $key) {
            $row[$key] = $row[$key] === null
                ? null
                : (int) $row[$key];
        }

        return $row;
    }

    public function saveLink(array $data): array
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        $groupId = (int) ($data['bitrix_group_id'] ?? 0);
        $groupName = trim((string) ($data['bitrix_group_name'] ?? ''));
        $companyId = (int) ($data['bitrix_company_id'] ?? 0);
        $companyName = trim((string) ($data['bitrix_company_name'] ?? ''));
        $reportTag = trim((string) ($data['report_tag'] ?? 'client_report'));

        if ($projectId <= 0 || $groupId <= 0 || $groupName === '') {
            throw new RuntimeException(
                'Выберите проект Битрикс24.'
            );
        }

        if ($reportTag === '') {
            $reportTag = 'client_report';
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO bitrix24_project_links
             (
                project_id,
                bitrix_group_id,
                bitrix_group_name,
                bitrix_company_id,
                bitrix_company_name,
                report_tag,
                created_at,
                updated_at
             )
             VALUES
             (
                :project_id,
                :bitrix_group_id,
                :bitrix_group_name,
                :bitrix_company_id,
                :bitrix_company_name,
                :report_tag,
                NOW(),
                NOW()
             )
             ON DUPLICATE KEY UPDATE
                bitrix_group_id = VALUES(bitrix_group_id),
                bitrix_group_name = VALUES(bitrix_group_name),
                bitrix_company_id = VALUES(bitrix_company_id),
                bitrix_company_name = VALUES(bitrix_company_name),
                report_tag = VALUES(report_tag),
                updated_at = NOW()'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'bitrix_group_id' => $groupId,
            'bitrix_group_name' => mb_substr($groupName, 0, 255),
            'bitrix_company_id' => $companyId > 0 ? $companyId : null,
            'bitrix_company_name' => $companyName !== ''
                ? mb_substr($companyName, 0, 255)
                : null,
            'report_tag' => mb_substr($reportTag, 0, 100),
        ]);

        return $this->link($projectId) ?? [];
    }

    public function replaceCache(
        int $projectId,
        array $tasks,
        array $elapsedItems,
        string $dateFrom,
        string $dateTo,
        array $meta
    ): void {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare(
                'DELETE FROM bitrix24_task_cache
                 WHERE project_id = :project_id'
            )->execute(['project_id' => $projectId]);
            $pdo->prepare(
                'DELETE FROM bitrix24_elapsed_cache
                 WHERE project_id = :project_id'
            )->execute(['project_id' => $projectId]);

            $taskInsert = $pdo->prepare(
                'INSERT INTO bitrix24_task_cache
                 (
                    project_id,
                    bitrix_task_id,
                    bitrix_group_id,
                    title,
                    status,
                    responsible_id,
                    responsible_name,
                    tags_json,
                    created_date,
                    changed_date,
                    closed_date,
                    time_spent_seconds,
                    raw_json,
                    synced_at
                 )
                 VALUES
                 (
                    :project_id,
                    :bitrix_task_id,
                    :bitrix_group_id,
                    :title,
                    :status,
                    :responsible_id,
                    :responsible_name,
                    :tags_json,
                    :created_date,
                    :changed_date,
                    :closed_date,
                    :time_spent_seconds,
                    :raw_json,
                    NOW()
                 )'
            );

            foreach ($tasks as $task) {
                $taskInsert->execute([
                    'project_id' => $projectId,
                    'bitrix_task_id' => (int) ($task['id'] ?? 0),
                    'bitrix_group_id' => (int) ($task['group_id'] ?? 0),
                    'title' => mb_substr(
                        (string) ($task['title'] ?? ''),
                        0,
                        1000
                    ),
                    'status' => (string) ($task['status'] ?? ''),
                    'responsible_id' => (int) ($task['responsible_id'] ?? 0) ?: null,
                    'responsible_name' => ($task['responsible_name'] ?? '') !== ''
                        ? mb_substr((string) $task['responsible_name'], 0, 255)
                        : null,
                    'tags_json' => json_encode(
                        $task['tags'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                    'created_date' => $this->nullableDateTime(
                        $task['created_date'] ?? null
                    ),
                    'changed_date' => $this->nullableDateTime(
                        $task['changed_date'] ?? null
                    ),
                    'closed_date' => $this->nullableDateTime(
                        $task['closed_date'] ?? null
                    ),
                    'time_spent_seconds' => (int) (
                        $task['time_spent_seconds'] ?? 0
                    ),
                    'raw_json' => json_encode(
                        $task['raw'] ?? $task,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            }

            $elapsedInsert = $pdo->prepare(
                'INSERT INTO bitrix24_elapsed_cache
                 (
                    project_id,
                    bitrix_task_id,
                    bitrix_elapsed_id,
                    user_id,
                    user_name,
                    seconds,
                    comment_text,
                    created_date,
                    date_start,
                    date_stop,
                    raw_json,
                    synced_at
                 )
                 VALUES
                 (
                    :project_id,
                    :bitrix_task_id,
                    :bitrix_elapsed_id,
                    :user_id,
                    :user_name,
                    :seconds,
                    :comment_text,
                    :created_date,
                    :date_start,
                    :date_stop,
                    :raw_json,
                    NOW()
                 )'
            );

            foreach ($elapsedItems as $item) {
                $elapsedInsert->execute([
                    'project_id' => $projectId,
                    'bitrix_task_id' => (int) ($item['task_id'] ?? 0),
                    'bitrix_elapsed_id' => (int) ($item['id'] ?? 0),
                    'user_id' => (int) ($item['user_id'] ?? 0) ?: null,
                    'user_name' => ($item['user_name'] ?? '') !== ''
                        ? mb_substr((string) $item['user_name'], 0, 255)
                        : null,
                    'seconds' => max(0, (int) ($item['seconds'] ?? 0)),
                    'comment_text' => ($item['comment_text'] ?? '') !== ''
                        ? mb_substr((string) $item['comment_text'], 0, 5000)
                        : null,
                    'created_date' => $this->nullableDateTime(
                        $item['created_date'] ?? null
                    ),
                    'date_start' => $this->nullableDateTime(
                        $item['date_start'] ?? null
                    ),
                    'date_stop' => $this->nullableDateTime(
                        $item['date_stop'] ?? null
                    ),
                    'raw_json' => json_encode(
                        $item['raw'] ?? $item,
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ]);
            }

            $runInsert = $pdo->prepare(
                'INSERT INTO bitrix24_sync_runs
                 (
                    project_id,
                    date_from,
                    date_to,
                    tasks_count,
                    elapsed_count,
                    elapsed_seconds,
                    warnings_json,
                    created_at
                 )
                 VALUES
                 (
                    :project_id,
                    :date_from,
                    :date_to,
                    :tasks_count,
                    :elapsed_count,
                    :elapsed_seconds,
                    :warnings_json,
                    NOW()
                 )'
            );
            $runInsert->execute([
                'project_id' => $projectId,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'tasks_count' => count($tasks),
                'elapsed_count' => count($elapsedItems),
                'elapsed_seconds' => array_sum(array_map(
                    static fn(array $item): int =>
                        (int) ($item['seconds'] ?? 0),
                    $elapsedItems
                )),
                'warnings_json' => json_encode(
                    $meta['warnings'] ?? [],
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function preview(
        int $projectId,
        string $dateFrom,
        string $dateTo
    ): array {
        $stmt = Database::pdo()->prepare(
            'SELECT
                t.bitrix_task_id,
                t.title,
                t.status,
                t.responsible_name,
                t.tags_json,
                t.closed_date,
                COALESCE(SUM(e.seconds), 0) AS period_seconds,
                COUNT(e.id) AS elapsed_entries
             FROM bitrix24_task_cache t
             LEFT JOIN bitrix24_elapsed_cache e
                ON e.project_id = t.project_id
               AND e.bitrix_task_id = t.bitrix_task_id
               AND DATE(e.created_date) BETWEEN :date_from AND :date_to
             WHERE t.project_id = :project_id
             GROUP BY
                t.id,
                t.bitrix_task_id,
                t.title,
                t.status,
                t.responsible_name,
                t.tags_json,
                t.closed_date
             ORDER BY period_seconds DESC, t.bitrix_task_id DESC'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
        $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $totalSeconds = 0;
        $withTime = 0;

        foreach ($tasks as &$task) {
            $task['bitrix_task_id'] = (int) $task['bitrix_task_id'];
            $task['period_seconds'] = (int) $task['period_seconds'];
            $task['elapsed_entries'] = (int) $task['elapsed_entries'];
            $task['tags'] = json_decode(
                (string) $task['tags_json'],
                true
            ) ?: [];
            unset($task['tags_json']);
            $totalSeconds += $task['period_seconds'];

            if ($task['period_seconds'] > 0) {
                $withTime++;
            }
        }
        unset($task);

        $runStmt = Database::pdo()->prepare(
            'SELECT *
             FROM bitrix24_sync_runs
             WHERE project_id = :project_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $runStmt->execute(['project_id' => $projectId]);
        $lastRun = $runStmt->fetch(PDO::FETCH_ASSOC) ?: null;

        if (is_array($lastRun)) {
            foreach ([
                'id',
                'project_id',
                'tasks_count',
                'elapsed_count',
                'elapsed_seconds',
            ] as $key) {
                $lastRun[$key] = (int) $lastRun[$key];
            }
            $lastRun['warnings'] = json_decode(
                (string) $lastRun['warnings_json'],
                true
            ) ?: [];
            unset($lastRun['warnings_json']);
        }

        return [
            'summary' => [
                'tasks_count' => count($tasks),
                'tasks_with_time' => $withTime,
                'elapsed_seconds' => $totalSeconds,
                'elapsed_entries' => array_sum(array_column(
                    $tasks,
                    'elapsed_entries'
                )),
            ],
            'tasks' => $tasks,
            'last_sync' => $lastRun,
        ];
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($value))->format(
                'Y-m-d H:i:s'
            );
        } catch (\Throwable) {
            return null;
        }
    }
}
