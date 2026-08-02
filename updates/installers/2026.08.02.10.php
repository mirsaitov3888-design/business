<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function p05out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function p05read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }
    return $content;
}

function p05write(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать каталог: ' . $directory);
    }

    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException('Не удалось записать временный файл: ' . $temporary);
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить файл: ' . $path);
    }
}

function p05lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }

    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1',
        $output,
        $code
    );
    if ($code !== 0) {
        throw new RuntimeException(
            'Ошибка PHP-синтаксиса в ' . $path . ':\n' . implode("\n", $output)
        );
    }
}

function p05runAcceptance(string $root, string $servicePath): array
{
    try {
        require_once $root . '/app/bootstrap.php';
        require_once $servicePath;
        $service = new \SeoAnalytics\Services\Bitrix24AcceptanceService();
        return $service->run();
    } catch (Throwable $exception) {
        return [
            'status' => 'warning',
            'generated_at' => date(DATE_ATOM),
            'errors' => [],
            'warnings' => [
                'Автоматическую приёмку не удалось выполнить: '
                . $exception->getMessage(),
            ],
            'counts' => [],
            'links' => [],
        ];
    }
}

$root = getcwd() ?: '';
$bootstrapPath = $root . '/app/bootstrap.php';
$clientPath = $root . '/app/Services/Bitrix24Client.php';
$syncPath = $root . '/app/Services/Bitrix24SyncService.php';
$acceptancePath = $root . '/app/Services/Bitrix24AcceptanceService.php';
$cliPath = $root . '/bin/bitrix24_acceptance.php';

foreach ([$bootstrapPath, $clientPath, $syncPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$sync = p05read($syncPath);
$alreadyInstalled = str_contains($sync, 'BITRIX24_ACCEPTANCE_P05_V180210')
    && is_file($acceptancePath)
    && is_file($cliPath);

if ($alreadyInstalled) {
    $report = p05runAcceptance($root, $acceptancePath);
    p05out('P0.5 уже установлен; приёмка Битрикс24 выполнена повторно.');
    p05out('- статус: ' . (string) ($report['status'] ?? 'unknown') . ';');
    p05out('- ошибок: ' . count($report['errors'] ?? []) . ';');
    p05out('- предупреждений: ' . count($report['warnings'] ?? []) . ';');
    p05out('- отчёт: ' . $root . '/storage/system-audits/bitrix24-acceptance-latest.json;');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/p0-bitrix24-acceptance-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$paths = [$syncPath, $acceptancePath, $cliPath];
$existed = [];
foreach ($paths as $path) {
    if (is_file($path)) {
        $existed[$path] = true;
        if (!copy($path, $backupDirectory . '/' . basename($path))) {
            throw new RuntimeException('Не удалось сохранить резервную копию: ' . $path);
        }
    }
}

$acceptanceService = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use SeoAnalytics\Core\Database;

final class Bitrix24AcceptanceService
{
    private const VERSION = '2026.08.02.10';
    private const TASK_LIMIT = 5000;
    private const ELAPSED_SAMPLE_LIMIT = 100;

    public function __construct(
        private readonly Bitrix24Client $client = new Bitrix24Client(),
        private readonly PDO $pdo = new PDO('sqlite::memory:')
    ) {
    }

    public static function create(): self
    {
        return new self(new Bitrix24Client(), Database::pdo());
    }

    public function run(): array
    {
        $startedAt = microtime(true);
        $errors = [];
        $warnings = [];
        $counts = [
            'projects' => 0,
            'companies' => 0,
            'linked_projects' => 0,
            'tasks' => 0,
            'tagged_tasks' => 0,
            'elapsed_sampled_tasks' => 0,
            'elapsed_entries' => 0,
            'elapsed_seconds' => 0,
            'users' => 0,
        ];

        $profileOk = false;
        try {
            $profile = $this->client->profile();
            $profileOk = is_array($profile) && $profile !== [];
            if (!$profileOk) {
                $warnings[] = 'Метод profile вернул пустой ответ.';
            }
        } catch (\Throwable $exception) {
            $errors[] = 'Профиль Битрикс24: ' . $exception->getMessage();
        }

        try {
            $projects = $this->client->projects();
            $counts['projects'] = count($projects);
        } catch (\Throwable $exception) {
            $errors[] = 'Проекты Битрикс24: ' . $exception->getMessage();
        }

        try {
            $companies = $this->client->companies();
            $counts['companies'] = count($companies);
        } catch (\Throwable $exception) {
            $errors[] = 'Компании Битрикс24: ' . $exception->getMessage();
        }

        $links = [];
        if (!$this->tableExists('bitrix24_project_links')) {
            $warnings[] = 'Таблица связей проектов Битрикс24 отсутствует.';
        } else {
            $stmt = $this->pdo->query(
                'SELECT project_id, bitrix_group_id, bitrix_group_name,
                        bitrix_company_id, bitrix_company_name, report_tag
                 FROM bitrix24_project_links
                 ORDER BY project_id ASC
                 LIMIT 50'
            );
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $counts['linked_projects'] = count($rows);

            if ($rows === []) {
                $warnings[] = 'В портале пока нет связанных проектов Битрикс24.';
            }

            foreach ($rows as $row) {
                $links[] = $this->acceptLink($row, $counts, $warnings);
            }
        }

        $status = $errors !== []
            ? 'error'
            : ($warnings !== [] ? 'warning' : 'ok');

        $report = [
            'version' => self::VERSION,
            'generated_at' => date(DATE_ATOM),
            'duration_seconds' => round(microtime(true) - $startedAt, 3),
            'status' => $status,
            'profile_ok' => $profileOk,
            'portal_host' => $this->client->portalHost(),
            'limits' => [
                'tasks_per_project' => self::TASK_LIMIT,
                'elapsed_sample_tasks_per_link' => self::ELAPSED_SAMPLE_LIMIT,
            ],
            'counts' => $counts,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'links' => $links,
        ];

        $this->saveReport($report);
        return $report;
    }

    private function acceptLink(array $row, array &$counts, array &$warnings): array
    {
        $projectId = (int) ($row['project_id'] ?? 0);
        $groupId = (int) ($row['bitrix_group_id'] ?? 0);
        $tag = trim((string) ($row['report_tag'] ?? ''));
        $result = [
            'project_id' => $projectId,
            'group_id' => $groupId,
            'group_name' => (string) ($row['bitrix_group_name'] ?? ''),
            'company_id' => ($row['bitrix_company_id'] ?? null) === null
                ? null
                : (int) $row['bitrix_company_id'],
            'company_name' => (string) ($row['bitrix_company_name'] ?? ''),
            'tag' => $tag,
            'tasks_count' => 0,
            'tagged_tasks_count' => 0,
            'truncated' => false,
            'elapsed_api_verified' => false,
            'elapsed_sampled_tasks' => 0,
            'elapsed_entries' => 0,
            'elapsed_seconds' => 0,
            'users_count' => 0,
            'period' => $this->period($projectId),
            'error' => null,
        ];

        if ($groupId <= 0) {
            $result['error'] = 'Не указан ID проекта Битрикс24.';
            return $result;
        }

        try {
            $tasks = $this->client->tasks($groupId, '', self::TASK_LIMIT);
            $result['tasks_count'] = count($tasks);
            $result['truncated'] = count($tasks) >= self::TASK_LIMIT;
            $counts['tasks'] += count($tasks);

            if ($result['truncated']) {
                $warnings[] = 'Проект #' . $groupId
                    . ' достиг лимита ' . self::TASK_LIMIT
                    . ' задач; требуется фоновая синхронизация сверх лимита.';
            }

            $tagged = $tag === ''
                ? []
                : array_values(array_filter(
                    $tasks,
                    fn(array $task): bool => $this->hasTag($task, $tag)
                ));
            $result['tagged_tasks_count'] = count($tagged);
            $counts['tagged_tasks'] += count($tagged);

            if ($tag !== '' && $tagged === []) {
                $warnings[] = 'В проекте #' . $groupId
                    . ' локально не найден тег «' . $tag . '».';
            }

            $taskIds = [];
            $responsibleIds = [];
            foreach ($tasks as $task) {
                $id = (int) $this->value($task, ['id', 'ID'], 0);
                if ($id > 0) {
                    $taskIds[] = $id;
                }
                $responsibleId = (int) $this->value(
                    $task,
                    ['responsibleId', 'RESPONSIBLE_ID'],
                    0
                );
                if ($responsibleId > 0) {
                    $responsibleIds[] = $responsibleId;
                }
            }

            $sampleIds = array_slice(
                array_values(array_unique($taskIds)),
                0,
                self::ELAPSED_SAMPLE_LIMIT
            );
            $period = $result['period'];
            $elapsed = $this->client->elapsedItemsBatch(
                $sampleIds,
                $period['date_from'],
                $period['date_to']
            );
            $result['elapsed_api_verified'] = true;
            $result['elapsed_sampled_tasks'] = count($sampleIds);
            $counts['elapsed_sampled_tasks'] += count($sampleIds);

            $elapsedUserIds = [];
            foreach ($elapsed as $items) {
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    $result['elapsed_entries']++;
                    $seconds = (int) $this->value(
                        $item,
                        ['SECONDS', 'seconds'],
                        0
                    );
                    if ($seconds <= 0) {
                        $seconds = max(0, (int) $this->value(
                            $item,
                            ['MINUTES', 'minutes'],
                            0
                        ) * 60);
                    }
                    $result['elapsed_seconds'] += max(0, $seconds);
                    $userId = (int) $this->value(
                        $item,
                        ['USER_ID', 'userId'],
                        0
                    );
                    if ($userId > 0) {
                        $elapsedUserIds[] = $userId;
                    }
                }
            }
            $counts['elapsed_entries'] += $result['elapsed_entries'];
            $counts['elapsed_seconds'] += $result['elapsed_seconds'];

            $userIds = array_values(array_unique([
                ...$responsibleIds,
                ...$elapsedUserIds,
            ]));
            $users = $this->client->users($userIds);
            $result['users_count'] = count($users);
            $counts['users'] += count($users);
        } catch (\Throwable $exception) {
            $result['error'] = $exception->getMessage();
        }

        return $result;
    }

    private function period(int $projectId): array
    {
        if ($projectId > 0 && $this->tableExists('bitrix24_sync_runs')) {
            $stmt = $this->pdo->prepare(
                'SELECT date_from, date_to
                 FROM bitrix24_sync_runs
                 WHERE project_id = :project_id
                 ORDER BY id DESC
                 LIMIT 1'
            );
            $stmt->execute(['project_id' => $projectId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (is_array($row) && !empty($row['date_from']) && !empty($row['date_to'])) {
                return [
                    'date_from' => (string) $row['date_from'],
                    'date_to' => (string) $row['date_to'],
                    'source' => 'last_sync',
                ];
            }
        }

        return [
            'date_from' => date('Y-m-01'),
            'date_to' => date('Y-m-d'),
            'source' => 'current_month',
        ];
    }

    private function hasTag(array $task, string $expected): bool
    {
        $expected = mb_strtolower(trim($expected));
        if ($expected === '') {
            return false;
        }

        $tags = $this->value($task, ['tags', 'TAGS'], []);
        if (is_string($tags)) {
            $tags = preg_split('/[,;]+/u', $tags) ?: [];
        }
        if (!is_array($tags)) {
            return false;
        }

        foreach ($tags as $tag) {
            if (is_array($tag)) {
                $tag = $tag['name']
                    ?? $tag['title']
                    ?? $tag['NAME']
                    ?? $tag['TITLE']
                    ?? '';
            }
            if (mb_strtolower(trim((string) $tag)) === $expected) {
                return true;
            }
        }
        return false;
    }

    private function value(array $data, array $keys, mixed $default = null): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data)) {
                return $data[$key];
            }
        }
        return $default;
    }

    private function tableExists(string $table): bool
    {
        try {
            $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
            if ($driver === 'sqlite') {
                $stmt = $this->pdo->prepare(
                    "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name=:name"
                );
                $stmt->execute(['name' => $table]);
                return (int) $stmt->fetchColumn() > 0;
            }

            $stmt = $this->pdo->prepare(
                'SELECT COUNT(*)
                 FROM information_schema.TABLES
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = :table_name'
            );
            $stmt->execute(['table_name' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        } catch (\Throwable) {
            return false;
        }
    }

    private function saveReport(array $report): void
    {
        $root = dirname(__DIR__, 2);
        $directory = $root . '/storage/system-audits';
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }

        $json = json_encode(
            $report,
            JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_SLASHES
            | JSON_PRETTY_PRINT
        ) . PHP_EOL;
        file_put_contents(
            $directory . '/bitrix24-acceptance-latest.json',
            $json,
            LOCK_EX
        );
        file_put_contents(
            $directory . '/bitrix24-acceptance-' . date('Ymd-His') . '.json',
            $json,
            LOCK_EX
        );
    }
}
PHPFILE;

$cli = <<<'PHPFILE'
<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require_once dirname(__DIR__) . '/app/Services/Bitrix24AcceptanceService.php';

$service = \SeoAnalytics\Services\Bitrix24AcceptanceService::create();
$report = $service->run();

echo json_encode(
    $report,
    JSON_UNESCAPED_UNICODE
    | JSON_UNESCAPED_SLASHES
    | JSON_PRETTY_PRINT
) . PHP_EOL;

exit(($report['status'] ?? 'error') === 'error' ? 1 : 0);
PHPFILE;

try {
    $originalSync = $sync;

    if (!str_contains($sync, 'BITRIX24_ACCEPTANCE_P05_V180210')) {
        $sync = str_replace(
            "final class Bitrix24SyncService\n{",
            "final class Bitrix24SyncService\n{\n    /* BITRIX24_ACCEPTANCE_P05_V180210 */",
            $sync,
            $markerCount
        );
        if ($markerCount !== 1) {
            throw new RuntimeException('Не удалось установить маркер P0.5.');
        }

        $sync = preg_replace(
            '~(\$rawAllTasks\s*=\s*\$this->client->tasks\(\s*\$groupId\s*,\s*\'\'\s*,\s*)500(\s*\);)~s',
            '${1}5000${2}',
            $sync,
            1,
            $limitCount
        );
        if (!is_string($sync) || $limitCount !== 1) {
            throw new RuntimeException('Не удалось увеличить лимит задач проекта.');
        }

        $sync = str_replace(
            'if (count($rawAllTasks) >= 500) {',
            'if (count($rawAllTasks) >= 5000) {',
            $sync,
            $warningLimitCount
        );
        $sync = str_replace(
            'Получены первые 500 задач проекта. Для более крупных проектов потребуется фоновая постраничная синхронизация.',
            'Получены первые 5000 задач проекта. Для более крупных проектов потребуется отдельная фоновая синхронизация.',
            $sync,
            $warningTextCount
        );
        if ($warningLimitCount !== 1 || $warningTextCount !== 1) {
            throw new RuntimeException('Не удалось обновить предупреждение лимита задач.');
        }

        $serverTagPattern = '~        \$rawTaggedTasks = \[\];\n\n        if \(\$tag !== \'\'\) \{.*?\n        \}\n\n        \$filterMode = \'all\';~s';
        $serverTagReplacement = <<<'PHPBLOCK'
        $rawTaggedTasks = [];

        if ($tag !== '') {
            $expectedTag = mb_strtolower($tag);
            foreach ($rawAllTasks as $rawTask) {
                $taskTags = $this->normalizeTags(
                    $this->value($rawTask, ['tags', 'TAGS'], [])
                );
                foreach ($taskTags as $taskTag) {
                    if (mb_strtolower($taskTag) === $expectedTag) {
                        $rawTaggedTasks[] = $rawTask;
                        break;
                    }
                }
            }
        }

        $filterMode = 'all';
PHPBLOCK;
        $syncUpdated = preg_replace(
            $serverTagPattern,
            $serverTagReplacement,
            $sync,
            1,
            $tagBlockCount
        );
        if (!is_string($syncUpdated) || $tagBlockCount !== 1) {
            throw new RuntimeException('Не удалось включить локальную фильтрацию тегов.');
        }
        $sync = $syncUpdated;
    }

    if ($sync === $originalSync && !str_contains($sync, 'BITRIX24_ACCEPTANCE_P05_V180210')) {
        throw new RuntimeException('Код синхронизации не был обновлён.');
    }

    p05write($syncPath, $sync);
    p05write($acceptancePath, $acceptanceService);
    p05write($cliPath, $cli);
    @chmod($cliPath, 0700);

    p05lint($syncPath);
    p05lint($acceptancePath);
    p05lint($cliPath);

    $report = p05runAcceptance($root, $acceptancePath);

    p05out('P0.5 — синхронизация и приёмка Битрикс24 установлены.');
    p05out('- лимит задач одного проекта: 5000;');
    p05out('- фильтрация тегов: локальная, без зависимости от серверного фильтра;');
    p05out('- профиль доступен: ' . (!empty($report['profile_ok']) ? 'да' : 'нет') . ';');
    p05out('- проектов Битрикс24: ' . (int) ($report['counts']['projects'] ?? 0) . ';');
    p05out('- компаний Битрикс24: ' . (int) ($report['counts']['companies'] ?? 0) . ';');
    p05out('- связанных проектов портала: ' . (int) ($report['counts']['linked_projects'] ?? 0) . ';');
    p05out('- проверено задач: ' . (int) ($report['counts']['tasks'] ?? 0) . ';');
    p05out('- проверено записей времени: ' . (int) ($report['counts']['elapsed_entries'] ?? 0) . ';');
    p05out('- статус приёмки: ' . (string) ($report['status'] ?? 'unknown') . ';');
    p05out('- ошибок приёмки: ' . count($report['errors'] ?? []) . ';');
    p05out('- предупреждений приёмки: ' . count($report['warnings'] ?? []) . ';');
    p05out('- отчёт: ' . $root . '/storage/system-audits/bitrix24-acceptance-latest.json;');
    p05out('- резервная копия: ' . $backupDirectory . ';');
} catch (Throwable $exception) {
    foreach ($paths as $path) {
        $backup = $backupDirectory . '/' . basename($path);
        if (is_file($backup)) {
            @copy($backup, $path);
        } elseif (!isset($existed[$path])) {
            @unlink($path);
        }
    }
    fwrite(
        STDERR,
        'ОШИБКА: ' . $exception->getMessage()
        . PHP_EOL
        . 'Файлы восстановлены из резервной копии.'
        . PHP_EOL
    );
    exit(1);
}
