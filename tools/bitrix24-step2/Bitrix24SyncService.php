<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;
use SeoAnalytics\Repositories\Bitrix24Repository;

final class Bitrix24SyncService
{
    private const STATUS_LABELS = [
        2 => 'Ждёт выполнения',
        3 => 'Выполняется',
        4 => 'Ожидает контроля',
        5 => 'Завершена',
        6 => 'Отложена',
    ];

    public function __construct(
        private readonly Bitrix24Client $client = new Bitrix24Client(),
        private readonly Bitrix24Repository $repository = new Bitrix24Repository()
    ) {
    }

    public function sync(
        int $projectId,
        string $dateFrom,
        string $dateTo
    ): array {
        $link = $this->repository->link($projectId);

        if (!$link) {
            throw new RuntimeException(
                'Сначала свяжите проект с проектом Битрикс24.'
            );
        }

        $warnings = [];
        $tag = trim((string) ($link['report_tag'] ?? ''));
        $rawProjectTasks = $this->client->tasks(
            (int) $link['bitrix_group_id'],
            500
        );

        if (count($rawProjectTasks) >= 500) {
            $warnings[] = 'Получены первые 500 задач проекта. Для более крупных проектов потребуется фоновая синхронизация.';
        }

        $allTasks = [];

        foreach ($rawProjectTasks as $rawTask) {
            $task = $this->normalizeTask($rawTask);

            if ($task['id'] > 0) {
                $allTasks[] = $task;
            }
        }

        $tasks = $tag === ''
            ? $allTasks
            : array_values(array_filter(
                $allTasks,
                fn(array $task): bool => $this->hasTag(
                    $task['tags'],
                    $tag
                )
            ));

        if ($tag !== '' && $tasks === []) {
            $warnings[] = 'В проекте найдено задач: '
                . count($allTasks)
                . ', но ни у одной нет тега «'
                . $tag
                . '». Текст в названии задачи, например «Мир сайтов:», тегом не является. Добавьте тег через кнопку «Теги» в карточке задачи.';
        } elseif ($tag !== '') {
            $warnings[] = 'Всего задач в проекте: '
                . count($allTasks)
                . '; с тегом «'
                . $tag
                . '»: '
                . count($tasks)
                . '.';
        }

        $elapsedItems = [];
        $userIds = [];

        foreach ($tasks as $task) {
            if ($task['responsible_id'] > 0) {
                $userIds[] = $task['responsible_id'];
            }

            try {
                $rows = $this->client->elapsedItems(
                    $task['id'],
                    $dateFrom,
                    $dateTo
                );
            } catch (\Throwable $exception) {
                $warnings[] = 'Не удалось получить трудозатраты задачи #'
                    . $task['id']
                    . ': '
                    . $exception->getMessage();
                continue;
            }

            foreach ($rows as $row) {
                $item = $this->normalizeElapsed($row, $task['id']);

                if ($item['id'] <= 0) {
                    continue;
                }

                $elapsedItems[] = $item;

                if ($item['user_id'] > 0) {
                    $userIds[] = $item['user_id'];
                }
            }
        }

        $userMap = $this->loadUsers($userIds, $warnings);

        foreach ($tasks as &$task) {
            if (
                $task['responsible_name'] === ''
                && isset($userMap[$task['responsible_id']])
            ) {
                $task['responsible_name'] = $userMap[
                    $task['responsible_id']
                ];
            }
        }
        unset($task);

        foreach ($elapsedItems as &$item) {
            $item['user_name'] = $userMap[$item['user_id']]
                ?? ('Сотрудник #' . $item['user_id']);
        }
        unset($item);

        $this->repository->replaceCache(
            $projectId,
            $tasks,
            $elapsedItems,
            $dateFrom,
            $dateTo,
            [
                'warnings' => $warnings,
                'project_tasks_count' => count($allTasks),
                'tagged_tasks_count' => count($tasks),
            ]
        );

        return [
            'link' => $link,
            'warnings' => array_values(array_unique($warnings)),
            'diagnostics' => [
                'project_tasks_count' => count($allTasks),
                'tagged_tasks_count' => count($tasks),
                'elapsed_entries_count' => count($elapsedItems),
            ],
            'preview' => $this->repository->preview(
                $projectId,
                $dateFrom,
                $dateTo
            ),
        ];
    }

    private function hasTag(array $tags, string $expected): bool
    {
        $expected = mb_strtolower(trim($expected));

        foreach ($tags as $tag) {
            if (mb_strtolower(trim((string) $tag)) === $expected) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTask(array $raw): array
    {
        $id = (int) $this->value($raw, ['id', 'ID'], 0);
        $statusNumber = (int) $this->value(
            $raw,
            ['status', 'STATUS', 'realStatus', 'REAL_STATUS'],
            0
        );
        $tags = $this->normalizeTags(
            $this->value($raw, ['tags', 'TAGS'], [])
        );
        $responsibleName = trim(implode(' ', array_filter([
            (string) $this->value(
                $raw,
                ['responsibleName', 'RESPONSIBLE_NAME'],
                ''
            ),
            (string) $this->value(
                $raw,
                ['responsibleLastName', 'RESPONSIBLE_LAST_NAME'],
                ''
            ),
        ])));

        return [
            'id' => $id,
            'group_id' => (int) $this->value(
                $raw,
                ['groupId', 'GROUP_ID'],
                0
            ),
            'title' => trim((string) $this->value(
                $raw,
                ['title', 'TITLE'],
                'Без названия'
            )),
            'status' => self::STATUS_LABELS[$statusNumber]
                ?? ('Статус ' . $statusNumber),
            'responsible_id' => (int) $this->value(
                $raw,
                ['responsibleId', 'RESPONSIBLE_ID'],
                0
            ),
            'responsible_name' => $responsibleName,
            'tags' => $tags,
            'created_date' => $this->value(
                $raw,
                ['createdDate', 'CREATED_DATE'],
                null
            ),
            'changed_date' => $this->value(
                $raw,
                ['changedDate', 'CHANGED_DATE'],
                null
            ),
            'closed_date' => $this->value(
                $raw,
                ['closedDate', 'CLOSED_DATE'],
                null
            ),
            'time_spent_seconds' => (int) $this->value(
                $raw,
                ['timeSpentInLogs', 'TIME_SPENT_IN_LOGS'],
                0
            ),
            'raw' => $raw,
        ];
    }

    private function normalizeTags(mixed $tags): array
    {
        if (is_string($tags)) {
            $tags = preg_split('/[,;]+/u', $tags) ?: [];
        }

        if (!is_array($tags)) {
            return [];
        }

        $result = [];

        foreach ($tags as $tag) {
            if (is_array($tag)) {
                $tag = $tag['name']
                    ?? $tag['title']
                    ?? $tag['NAME']
                    ?? $tag['TITLE']
                    ?? '';
            }

            $tag = trim((string) $tag);

            if ($tag !== '') {
                $result[] = $tag;
            }
        }

        return array_values(array_unique($result));
    }

    private function normalizeElapsed(array $raw, int $taskId): array
    {
        return [
            'id' => (int) $this->value($raw, ['ID', 'id'], 0),
            'task_id' => (int) $this->value(
                $raw,
                ['TASK_ID', 'taskId'],
                $taskId
            ),
            'user_id' => (int) $this->value(
                $raw,
                ['USER_ID', 'userId'],
                0
            ),
            'user_name' => '',
            'seconds' => (int) $this->value(
                $raw,
                ['SECONDS', 'seconds'],
                0
            ),
            'comment_text' => trim((string) $this->value(
                $raw,
                ['COMMENT_TEXT', 'commentText'],
                ''
            )),
            'created_date' => $this->value(
                $raw,
                ['CREATED_DATE', 'createdDate'],
                null
            ),
            'date_start' => $this->value(
                $raw,
                ['DATE_START', 'dateStart'],
                null
            ),
            'date_stop' => $this->value(
                $raw,
                ['DATE_STOP', 'dateStop'],
                null
            ),
            'raw' => $raw,
        ];
    }

    private function loadUsers(array $ids, array &$warnings): array
    {
        try {
            $rows = $this->client->users($ids);
        } catch (\Throwable $exception) {
            $warnings[] = 'Не удалось получить имена сотрудников: '
                . $exception->getMessage();
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $id = (int) $this->value($row, ['ID', 'id'], 0);

            if ($id <= 0) {
                continue;
            }

            $name = trim(implode(' ', array_filter([
                (string) $this->value($row, ['NAME', 'name'], ''),
                (string) $this->value(
                    $row,
                    ['LAST_NAME', 'lastName'],
                    ''
                ),
            ])));
            $result[$id] = $name !== ''
                ? $name
                : ('Сотрудник #' . $id);
        }

        return $result;
    }

    private function value(
        array $data,
        array $keys,
        mixed $default = null
    ): mixed {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }

        return $default;
    }
}
