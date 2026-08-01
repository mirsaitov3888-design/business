<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;

final class Bitrix24Client
{
    private string $webhookBase;

    public function __construct(?string $webhookBase = null)
    {
        $this->webhookBase = $webhookBase !== null
            ? $this->normalizeWebhook($webhookBase)
            : $this->loadWebhook();
    }

    public function profile(): array
    {
        return $this->call('profile');
    }

    public function projects(): array
    {
        $result = [];
        $start = 0;

        do {
            $response = $this->call('sonet_group.get', [
                'ORDER' => ['NAME' => 'ASC'],
                'FILTER' => [
                    'ACTIVE' => 'Y',
                    'CLOSED' => 'N',
                ],
                'start' => $start,
            ]);
            $rows = is_array($response['result'] ?? null)
                ? $response['result']
                : [];
            $result = [...$result, ...$rows];
            $start += 50;
            $total = (int) ($response['total'] ?? count($result));
        } while (
            $rows !== []
            && count($result) < $total
            && $start < 5000
        );

        return $result;
    }

    public function companies(): array
    {
        $result = [];
        $start = 0;

        do {
            $response = $this->call('crm.item.list', [
                'entityTypeId' => 4,
                'select' => [
                    'id',
                    'title',
                    'assignedById',
                    'createdTime',
                    'updatedTime',
                ],
                'order' => ['title' => 'ASC'],
                'start' => $start,
            ]);
            $rows = $response['result']['items'] ?? [];
            $rows = is_array($rows) ? $rows : [];
            $result = [...$result, ...$rows];
            $start += 50;
            $total = (int) ($response['total'] ?? count($result));
        } while (
            $rows !== []
            && count($result) < $total
            && $start < 5000
        );

        return $result;
    }

    /**
     * Загружает задачи проекта. Если указан тег, Битрикс24 пытается
     * отфильтровать задачи на своей стороне. SyncService всегда отдельно
     * загружает общий список, чтобы при отсутствии совпадений выполнить
     * безопасный возврат ко всем задачам выбранного периода.
     */
    public function tasks(
        int $groupId,
        string $tag = '',
        int $limit = 500
    ): array {
        $result = [];
        $start = 0;
        $filter = ['GROUP_ID' => $groupId];

        if (trim($tag) !== '') {
            $filter['TAG'] = trim($tag);
        }

        do {
            $response = $this->call('tasks.task.list', [
                'order' => ['ID' => 'ASC'],
                'filter' => $filter,
                'select' => [
                    'ID',
                    'TITLE',
                    'STATUS',
                    'REAL_STATUS',
                    'GROUP_ID',
                    'TAGS',
                    'RESPONSIBLE_ID',
                    'RESPONSIBLE_NAME',
                    'RESPONSIBLE_LAST_NAME',
                    'CREATED_DATE',
                    'CHANGED_DATE',
                    'CLOSED_DATE',
                    'TIME_SPENT_IN_LOGS',
                    'ALLOW_TIME_TRACKING',
                ],
                'params' => [
                    'WITH_TIMER_INFO' => true,
                    'WITH_RESULT_INFO' => true,
                ],
                'start' => $start,
            ]);

            $rows = $response['result']['tasks'] ?? [];
            $rows = is_array($rows) ? $rows : [];

            foreach ($rows as $row) {
                if (count($result) >= $limit) {
                    break 2;
                }

                $result[] = $row;
            }

            $start += 50;
            $total = (int) ($response['total'] ?? count($result));
        } while (
            $rows !== []
            && count($result) < $total
            && $start < 5000
        );

        return $result;
    }

    public function elapsedItems(
        int $taskId,
        string $dateFrom,
        string $dateTo
    ): array {
        $result = [];
        $page = 1;

        do {
            $response = $this->call('task.elapseditem.getlist', [
                'TASKID' => $taskId,
                'ORDER' => ['ID' => 'ASC'],
                'FILTER' => [
                    '>=CREATED_DATE' => $dateFrom . 'T00:00:00',
                    '<=CREATED_DATE' => $dateTo . 'T23:59:59',
                ],
                'SELECT' => [
                    'ID',
                    'TASK_ID',
                    'USER_ID',
                    'COMMENT_TEXT',
                    'SECONDS',
                    'MINUTES',
                    'CREATED_DATE',
                    'DATE_START',
                    'DATE_STOP',
                ],
                'PARAMS' => [
                    'NAV_PARAMS' => [
                        'nPageSize' => 50,
                        'iNumPage' => $page,
                    ],
                ],
            ]);

            $rows = is_array($response['result'] ?? null)
                ? $response['result']
                : [];
            $result = [...$result, ...$rows];
            $total = (int) ($response['total'] ?? count($result));
            $page++;
        } while (
            $rows !== []
            && count($result) < $total
            && $page <= 100
        );

        return $result;
    }

    /**
     * Получает записи времени пакетами до 50 задач. Если метод для
     * конкретной задачи вернул ошибку или данных больше первой страницы,
     * повторяет запрос обычным постраничным способом.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public function elapsedItemsBatch(
        array $taskIds,
        string $dateFrom,
        string $dateTo
    ): array {
        $taskIds = array_values(array_unique(array_filter(
            array_map('intval', $taskIds),
            static fn(int $id): bool => $id > 0
        )));
        $result = [];

        foreach ($taskIds as $taskId) {
            $result[$taskId] = [];
        }

        foreach (array_chunk($taskIds, 50) as $chunk) {
            $commands = [];

            foreach ($chunk as $taskId) {
                $params = [
                    'TASKID' => $taskId,
                    'ORDER' => ['ID' => 'ASC'],
                    'FILTER' => [
                        '>=CREATED_DATE' => $dateFrom . 'T00:00:00',
                        '<=CREATED_DATE' => $dateTo . 'T23:59:59',
                    ],
                    'SELECT' => [
                        'ID',
                        'TASK_ID',
                        'USER_ID',
                        'COMMENT_TEXT',
                        'SECONDS',
                        'MINUTES',
                        'CREATED_DATE',
                        'DATE_START',
                        'DATE_STOP',
                    ],
                    'PARAMS' => [
                        'NAV_PARAMS' => [
                            'nPageSize' => 50,
                            'iNumPage' => 1,
                        ],
                    ],
                ];
                $commands['task_' . $taskId] =
                    'task.elapseditem.getlist?'
                    . http_build_query(
                        $params,
                        '',
                        '&',
                        PHP_QUERY_RFC3986
                    );
            }

            try {
                $response = $this->call('batch', [
                    'halt' => 0,
                    'cmd' => $commands,
                ]);
                $batch = is_array($response['result'] ?? null)
                    ? $response['result']
                    : [];
                $batchRows = is_array($batch['result'] ?? null)
                    ? $batch['result']
                    : [];
                $batchTotals = is_array($batch['result_total'] ?? null)
                    ? $batch['result_total']
                    : [];
                $batchErrors = is_array($batch['result_error'] ?? null)
                    ? $batch['result_error']
                    : [];

                foreach ($chunk as $taskId) {
                    $key = 'task_' . $taskId;
                    $rows = $batchRows[$key] ?? [];
                    $rows = is_array($rows) ? $rows : [];
                    $total = (int) (
                        $batchTotals[$key]
                        ?? count($rows)
                    );

                    if (
                        isset($batchErrors[$key])
                        || $total > count($rows)
                    ) {
                        $result[$taskId] = $this->elapsedItems(
                            $taskId,
                            $dateFrom,
                            $dateTo
                        );
                    } else {
                        $result[$taskId] = $rows;
                    }
                }
            } catch (\Throwable) {
                foreach ($chunk as $taskId) {
                    $result[$taskId] = $this->elapsedItems(
                        $taskId,
                        $dateFrom,
                        $dateTo
                    );
                }
            }
        }

        return $result;
    }

    public function users(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));

        if ($ids === []) {
            return [];
        }

        $result = [];

        foreach (array_chunk($ids, 50) as $chunk) {
            $response = $this->call('user.get', [
                'FILTER' => ['@ID' => $chunk],
            ]);
            $rows = is_array($response['result'] ?? null)
                ? $response['result']
                : [];
            $result = [...$result, ...$rows];
        }

        return $result;
    }

    public function call(string $method, array $params = []): array
    {
        $url = $this->webhookBase
            . rawurlencode($method)
            . '.json';
        $payload = json_encode(
            $params,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );

        if (!is_string($payload)) {
            throw new RuntimeException(
                'Не удалось подготовить запрос Битрикс24.'
            );
        }

        $ch = curl_init($url);

        if ($ch === false) {
            throw new RuntimeException(
                'Не удалось инициализировать запрос Битрикс24.'
            );
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 120,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_ENCODING => '',
        ]);

        $body = curl_exec($ch);
        $httpCode = (int) curl_getinfo(
            $ch,
            CURLINFO_RESPONSE_CODE
        );
        $error = curl_error($ch);
        curl_close($ch);

        if (!is_string($body)) {
            throw new RuntimeException(
                'Ошибка соединения с Битрикс24: '
                . ($error !== '' ? $error : 'нет ответа')
            );
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(
                'Битрикс24 вернул некорректный ответ.'
            );
        }

        if ($httpCode >= 400 || isset($decoded['error'])) {
            $description = (string) (
                $decoded['error_description']
                ?? $decoded['error']
                ?? "HTTP {$httpCode}"
            );

            throw new RuntimeException(
                "Битрикс24 {$method}: {$description}"
            );
        }

        return $decoded;
    }

    public function portalHost(): string
    {
        $host = parse_url(
            $this->webhookBase,
            PHP_URL_HOST
        );

        return is_string($host) ? $host : '';
    }

    private function loadWebhook(): string
    {
        $candidates = [
            dirname(__DIR__, 4) . '/bitrix24-config.php',
            dirname(__DIR__, 3) . '/bitrix24-config.php',
            dirname(__DIR__, 2) . '/bitrix24-config.php',
        ];

        foreach ($candidates as $path) {
            if (!is_file($path)) {
                continue;
            }

            $config = require $path;
            $url = is_array($config)
                ? (string) ($config['webhook_url'] ?? '')
                : '';

            if ($url !== '') {
                return $this->normalizeWebhook($url);
            }
        }

        throw new RuntimeException(
            'Интеграция Битрикс24 не настроена.'
        );
    }

    private function normalizeWebhook(string $url): string
    {
        $url = trim($url);

        if (!preg_match(
            '#^https://[a-z0-9.-]+/rest/\d+/[a-z0-9_-]+/?$#i',
            $url
        )) {
            throw new RuntimeException(
                'Некорректный URL входящего вебхука Битрикс24.'
            );
        }

        return rtrim($url, '/') . '/';
    }
}
