<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use DateTimeImmutable;
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
        @set_time_limit(300);

        $link = $this->repository->link($projectId);

        if (!$link) {
            throw new RuntimeException(
                'Сначала свяжите проект с проектом Битрикс24.'
            );
        }

        $warnings = [];
        $groupId = (int) $link['bitrix_group_id'];
        $tag = trim((string) ($link['report_tag'] ?? ''));

        $rawAllTasks = $this->client->tasks(
            $groupId,
            '',
            500
        );

        if (count($rawAllTasks) >= 500) {
            $warnings[] = 'Получены первые 500 задач проекта. Для более крупных проектов потребуется фоновая постраничная синхронизация.';
        }

        $rawTaggedTasks = [];

        if ($tag !== '') {
            try {
                $rawTaggedTasks = $this->client->tasks(
                    $groupId,
                    $tag,
                    500
                );
            } catch (\Throwable $exception) {
                $warnings[] = 'Битрикс24 не смог применить фильтр по тегу «'
                    . $tag
                    . '»: '
                    . $exception->getMessage()
                    . '. Показаны все задачи выбранного периода.';
            }
        }

        $filterMode = 'all';
        $rawSelectedTasks = $rawAllTasks;

        if ($tag !== '' && $rawTaggedTasks !== []) {
            $filterMode = 'tag';
            $rawSelectedTasks = $rawTaggedTasks;
        } elseif ($tag !== '') {
            $filterMode = 'fallback';
            $warnings[] = 'Задачи с тегом «'
                . $tag
                . '» не найдены. Показаны все задачи проекта, относящиеся к выбранному периоду.';
        }

        $normalizedTasks = [];

        foreach ($rawSelectedTasks as $rawTask) {
            $task = $this->normalizeTask($rawTask);

            if ($task['id'] <= 0) {
                continue;
            }

            if (
                $filterMode === 'tag'
                && $tag !== ''
                && $task['tags'] === []
            ) {
                $task['tags'] = [$tag];
            }

            $normalizedTasks[$task['id']] = $task;
        }

        $elapsedMap = $this->client->elapsedItemsBatch(
            array_keys($normalizedTasks),
            $dateFrom,
            $dateTo
        );

        $tasks = [];
        $elapsedItems = [];
        $userIds = [];

        foreach ($normalizedTasks as $taskId => $task) {
            $rows = $elapsedMap[$taskId] ?? [];
            $taskElapsed = [];

            foreach ($rows as $row) {
                $item = $this->normalizeElapsed($row, $taskId);

                if ($item['id'] <= 0) {
                    continue;
                }

                $taskElapsed[] = $item;
            }

            $relevantByDate = $this->taskTouchesPeriod(
                $task,
                $dateFrom,
                $dateTo
            );

            if ($taskElapsed === [] && !$relevantByDate) {
                continue;
            }

            if ($task['responsible_id'] > 0) {
                $userIds[] = $task['responsible_id'];
            }

            foreach ($taskElapsed as $item) {
                $elapsedItems[] = $item;

                if ($item['user_id'] > 0) {
                    $userIds[] = $item['user_id'];
                }
            }

            $tasks[] = $task;
        }

        $userMap = $this->loadUsers(
            $userIds,
            $warnings
        );

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

        if ($filterMode === 'tag') {
            $warnings[] = 'Найдено задач с тегом «'
                . $tag
                . '»: '
                . count($rawTaggedTasks)
                . '; в выбранный период попало: '
                . count($tasks)
                . '.';
        } elseif ($filterMode === 'all') {
            $warnings[] = 'Тег не указан. Показаны все задачи проекта за выбранный период: '
                . count($tasks)
                . ' из '
                . count($rawAllTasks)
                . '.';
        } else {
            $warnings[] = 'Всего задач в проекте: '
                . count($rawAllTasks)
                . '; в выбранный период попало: '
                . count($tasks)
                . '.';
        }

        $this->repository->replaceCache(
            $projectId,
            $tasks,
            $elapsedItems,
            $dateFrom,
            $dateTo,
            [
                'warnings' => $warnings,
                'filter_mode' => $filterMode,
                'project_tasks_count' => count($rawAllTasks),
                'tagged_tasks_count' => count($rawTaggedTasks),
                'period_tasks_count' => count($tasks),
            ]
        );

        return [
            'link' => $link,
            'warnings' => array_values(array_unique($warnings)),
            'diagnostics' => [
                'filter_mode' => $filterMode,
                'project_tasks_count' => count($rawAllTasks),
                'tagged_tasks_count' => count($rawTaggedTasks),
                'period_tasks_count' => count($tasks),
                'elapsed_entries_count' => count($elapsedItems),
            ],
            'preview' => $this->repository->preview(
                $projectId,
                $dateFrom,
                $dateTo
            ),
        ];
    }

    private function taskTouchesPeriod(
        array $task,
        string $dateFrom,
        string $dateTo
    ): bool {
        $from = new DateTimeImmutable(
            $dateFrom . ' 00:00:00'
        );
        $to = new DateTimeImmutable(
            $dateTo . ' 23:59:59'
        );

        foreach ([
            'created_date',
            'changed_date',
            'closed_date',
        ] as $key) {
            $raw = trim((string) ($task[$key] ?? ''));

            if ($raw === '') {
                continue;
            }

            try {
                $date = new DateTimeImmutable($raw);
            } catch (\Throwable) {
                continue;
            }

            if ($date >= $from && $date <= $to) {
                return true;
            }
        }

        return false;
    }

    private function normalizeTask(array $raw): array
    {
        $id = (int) $this->value(
            $raw,
            ['id', 'ID'],
            0
        );
        $statusNumber = (int) $this->value(
            $raw,
            [
                'status',
                'STATUS',
                'realStatus',
                'REAL_STATUS',
            ],
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
                [
                    'responsibleLastName',
                    'RESPONSIBLE_LAST_NAME',
                ],
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
                [
                    'timeSpentInLogs',
                    'TIME_SPENT_IN_LOGS',
                ],
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

    private function normalizeElapsed(
        array $raw,
        int $taskId
    ): array {
        $seconds = (int) $this->value(
            $raw,
            ['SECONDS', 'seconds'],
            0
        );

        if ($seconds <= 0) {
            $minutes = (int) $this->value(
                $raw,
                ['MINUTES', 'minutes'],
                0
            );
            $seconds = max(0, $minutes * 60);
        }

        return [
            'id' => (int) $this->value(
                $raw,
                ['ID', 'id'],
                0
            ),
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
            'seconds' => $seconds,
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

    private function loadUsers(
        array $ids,
        array &$warnings
    ): array {
        try {
            $rows = $this->client->users($ids);
        } catch (\Throwable $exception) {
            $warnings[] = 'Не удалось получить имена сотрудников: '
                . $exception->getMessage();
            return [];
        }

        $result = [];

        foreach ($rows as $row) {
            $id = (int) $this->value(
                $row,
                ['ID', 'id'],
                0
            );

            if ($id <= 0) {
                continue;
            }

            $name = trim(implode(' ', array_filter([
                (string) $this->value(
                    $row,
                    ['NAME', 'name'],
                    ''
                ),
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
