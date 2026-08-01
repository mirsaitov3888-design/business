<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function b24s3Out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function b24s3Root(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/assets/app.css')
        || !is_file($root . '/app/Services/Bitrix24Client.php')
        || !is_file($root . '/app/Services/Bitrix24SyncService.php')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function b24s3Read(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function b24s3Download(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Bitrix24 Installer/3.0',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $content = file_get_contents($url, false, $context);

    if (!is_string($content) || $content === '') {
        throw new RuntimeException("Не удалось загрузить {$url}");
    }

    return $content;
}

function b24s3ReplaceOnce(
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $count = 0;
    $content = str_replace(
        $needle,
        $replacement,
        $content,
        $count
    );

    if ($count !== 1) {
        throw new RuntimeException(
            "Не удалось изменить {$label}: найдено замен {$count}."
        );
    }

    return $content;
}

function b24s3Write(string $path, string $content): void
{
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temporary}");
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

function b24s3Lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }

    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY)
        . ' -l '
        . escapeshellarg($path)
        . ' 2>&1',
        $output,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            "Ошибка синтаксиса в {$path}:\n"
            . implode("\n", $output)
        );
    }
}

$root = b24s3Root();
$clientPath = $root . '/app/Services/Bitrix24Client.php';
$syncPath = $root . '/app/Services/Bitrix24SyncService.php';
$cssPath = $root . '/assets/app.css';
$indexPath = $root . '/index.php';

$clientCurrent = b24s3Read($clientPath);
$syncCurrent = b24s3Read($syncPath);
$cssCurrent = b24s3Read($cssPath);
$indexCurrent = b24s3Read($indexPath);

if (str_contains($clientCurrent, 'BITRIX24_STEP3_TAG_FILTER')) {
    b24s3Out('Исправление тегов Битрикс24 уже установлено.');
    exit(0);
}

$backupDirectory = $root
    . '/storage/backups/bitrix24-step3-'
    . date('Ymd-His');

if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

foreach ([
    $clientPath => 'Bitrix24Client.php',
    $syncPath => 'Bitrix24SyncService.php',
    $cssPath => 'app.css',
    $indexPath => 'index.php',
] as $source => $name) {
    if (!copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException("Не удалось сохранить копию {$name}");
    }
}

$base = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/bitrix24-step2/';

try {
    $client = b24s3Download($base . 'Bitrix24Client.php');
    $sync = b24s3Download($base . 'Bitrix24SyncService.php');

    $client = b24s3ReplaceOnce(
        $client,
        '    public function tasks(int $groupId, int $limit = 500): array',
        "    /* BITRIX24_STEP3_TAG_FILTER */\n"
        . "    public function tasks(\n"
        . "        int \$groupId,\n"
        . "        string \$tag = '',\n"
        . "        int \$limit = 500\n"
        . "    ): array",
        'сигнатуру загрузки задач'
    );

    $client = b24s3ReplaceOnce(
        $client,
        "        \$result = [];\n        \$start = 0;\n\n        do {\n"
        . "            \$response = \$this->call('tasks.task.list', [",
        "        \$result = [];\n"
        . "        \$start = 0;\n"
        . "        \$filter = ['GROUP_ID' => \$groupId];\n\n"
        . "        if (trim(\$tag) !== '') {\n"
        . "            \$filter['TAG'] = trim(\$tag);\n"
        . "        }\n\n"
        . "        do {\n"
        . "            \$response = \$this->call('tasks.task.list', [",
        'фильтр задач'
    );

    $client = b24s3ReplaceOnce(
        $client,
        "                'filter' => ['GROUP_ID' => \$groupId],",
        "                'filter' => \$filter,",
        'TAG в запросе tasks.task.list'
    );

    $oldBlock = <<<'PHPBLOCK'
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
PHPBLOCK;

    $newBlock = <<<'PHPBLOCK'
        $warnings = [];
        $tag = trim((string) ($link['report_tag'] ?? ''));

        // Битрикс24 официально поддерживает TAG в filter tasks.task.list.
        // Фильтруем на стороне портала, потому что некоторые порталы
        // не возвращают TAGS в списке даже при наличии поля в select.
        $rawTaggedTasks = $this->client->tasks(
            (int) $link['bitrix_group_id'],
            $tag,
            500
        );

        if (count($rawTaggedTasks) >= 500) {
            $warnings[] = 'Получены первые 500 задач с выбранным тегом. Для более крупных проектов потребуется фоновая синхронизация.';
        }

        $tasks = [];

        foreach ($rawTaggedTasks as $rawTask) {
            $task = $this->normalizeTask($rawTask);

            if ($task['id'] <= 0) {
                continue;
            }

            // Если TAGS не пришёл в ответе списка, серверный фильтр уже
            // подтвердил наличие тега — сохраняем его для интерфейса.
            if ($tag !== '' && $task['tags'] === []) {
                $task['tags'] = [$tag];
            }

            $tasks[] = $task;
        }

        $projectTasksCount = null;

        if ($tag !== '' && $tasks === []) {
            try {
                $allRows = $this->client->tasks(
                    (int) $link['bitrix_group_id'],
                    '',
                    500
                );
                $projectTasksCount = count($allRows);
            } catch (\Throwable) {
                $projectTasksCount = null;
            }

            $warnings[] = 'Фильтр Битрикс24 не нашёл задач с тегом «'
                . $tag
                . '» в выбранном проекте.'
                . ($projectTasksCount !== null
                    ? ' Всего доступных задач проекта: ' . $projectTasksCount . '.'
                    : '')
                . ' Проверьте, что задача действительно привязана к выбранному проекту, а не только содержит его название.';
        } elseif ($tag !== '') {
            $warnings[] = 'Битрикс24 вернул задач с тегом «'
                . $tag
                . '»: '
                . count($tasks)
                . '.';
        }
PHPBLOCK;

    $sync = b24s3ReplaceOnce(
        $sync,
        $oldBlock,
        $newBlock,
        'серверную фильтрацию тегов'
    );

    $sync = str_replace(
        "                'project_tasks_count' => count(\$allTasks),",
        "                'project_tasks_count' => \$projectTasksCount,",
        $sync
    );
    $sync = str_replace(
        "                'project_tasks_count' => count(\$allTasks),",
        "                'project_tasks_count' => \$projectTasksCount,",
        $sync
    );

    $warningCss = <<<'CSS'

/* BITRIX24_STEP3_WARNING */
#bitrix24Message.alert-warning,
.alert.alert-warning {
    display: block;
    padding: 14px 16px;
    border: 1px solid #f1d38a;
    border-radius: 12px;
    background: #fffbeb;
    color: #854d0e;
    line-height: 1.55;
}

#bitrix24Message.alert-success {
    display: block;
}
CSS;

    $css = str_contains($cssCurrent, 'BITRIX24_STEP3_WARNING')
        ? $cssCurrent
        : $cssCurrent . $warningCss;

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=17',
        $indexCurrent
    ) ?? $indexCurrent;

    b24s3Write($clientPath, $client);
    b24s3Write($syncPath, $sync);
    b24s3Write($cssPath, $css);
    b24s3Write($indexPath, $index);

    b24s3Lint($clientPath);
    b24s3Lint($syncPath);
    b24s3Lint($indexPath);

    b24s3Out('Исправление тегов Битрикс24 установлено.');
    b24s3Out('- тег фильтруется средствами API Битрикс24;');
    b24s3Out('- TAGS больше не обязателен в ответе списка;');
    b24s3Out('- предупреждения выделяются жёлтым;');
    b24s3Out('- сообщение различает отсутствие тега и другую привязку проекта.');
    b24s3Out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    @copy($backupDirectory . '/Bitrix24Client.php', $clientPath);
    @copy($backupDirectory . '/Bitrix24SyncService.php', $syncPath);
    @copy($backupDirectory . '/app.css', $cssPath);
    @copy($backupDirectory . '/index.php', $indexPath);

    fwrite(
        STDERR,
        "ОШИБКА: {$exception->getMessage()}\n"
        . "Файлы восстановлены из резервной копии.\n"
    );
    exit(1);
}
