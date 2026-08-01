<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function mh8out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function mh8read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function mh8replace(string $content, string $needle, string $replacement, string $label): string
{
    $count = 0;
    $content = str_replace($needle, $replacement, $content, $count);
    if ($count !== 1) {
        throw new RuntimeException("Не удалось изменить {$label}: найдено замен {$count}.");
    }
    return $content;
}

function mh8write(string $path, string $content): void
{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать каталог {$directory}");
    }
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temporary}");
    }
    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

function mh8lint(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }
    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        throw new RuntimeException("Ошибка PHP-синтаксиса в {$path}:\n" . implode("\n", $output));
    }
}

$root = getcwd() ?: '';
$servicePath = $root . '/app/Services/SiteMonitoringService.php';
$repositoryPath = $root . '/app/Repositories/MonitoringRepository.php';
$workerPath = $root . '/bin/site_monitor_worker.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$indexPath = $root . '/index.php';

foreach ([$servicePath, $repositoryPath, $workerPath, $apiPath, $jsPath, $indexPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$service = mh8read($servicePath);
$repository = mh8read($repositoryPath);
$api = mh8read($apiPath);
$js = mh8read($jsPath);
$index = mh8read($indexPath);

if (str_contains($service, 'MONITORING_ASYNC_QUEUE_HOTFIX')) {
    mh8out('Асинхронная очередь мониторинга уже установлена.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/monitoring-async-hotfix-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$backupFiles = [
    $servicePath => 'SiteMonitoringService.php',
    $repositoryPath => 'MonitoringRepository.php',
    $workerPath => 'site_monitor_worker.php',
    $apiPath => 'api.php',
    $jsPath => 'app.js',
    $indexPath => 'index.php',
];
foreach ($backupFiles as $source => $name) {
    if (!copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException("Не удалось сохранить резервную копию {$name}");
    }
}

try {
    $oldSave = <<<'PHPBLOCK'
        $normalized = $this->normalizeSiteInput($input, $projectId);
        $isNew = (int) ($normalized['id'] ?? 0) <= 0;
        $id = $this->repository->saveSite($normalized);
        $site = $this->repository->site($id, $projectId);

        if (!$site) {
            throw new RuntimeException('Сайт сохранён, но не найден после сохранения.');
        }

        $initial = null;
        if ($isNew || !empty($input['run_initial_audit'])) {
            $availability = $this->checkAvailabilityBySite($site, true);
            $site = $this->repository->site($id, $projectId) ?? $site;
            $audit = $this->auditSiteBySite($site, 'initial');
            $initial = [
                'availability' => $availability,
                'audit' => $audit,
            ];
        }

        return [
            'site' => $this->repository->site($id, $projectId),
            'initial' => $initial,
        ];
PHPBLOCK;

    $newSave = <<<'PHPBLOCK'
        /* MONITORING_ASYNC_QUEUE_HOTFIX */
        $normalized = $this->normalizeSiteInput($input, $projectId);
        $isNew = (int) ($normalized['id'] ?? 0) <= 0;
        $id = $this->repository->saveSite($normalized);
        $site = $this->repository->site($id, $projectId);

        if (!$site) {
            throw new RuntimeException('Сайт сохранён, но не найден после сохранения.');
        }

        $queueInitialAudit = $isNew || !empty($input['run_initial_audit']);
        if ($queueInitialAudit) {
            $this->repository->queueSiteCheck($id, true);
        }

        return [
            'site' => $this->repository->site($id, $projectId),
            'initial' => null,
            'queued_initial_audit' => $queueInitialAudit,
        ];
PHPBLOCK;
    $service = mh8replace($service, $oldSave, $newSave, 'синхронный первичный аудит');

    $service = mh8replace(
        $service,
        "        \$audited = 0;\n        \$errors = [];",
        "        \$audited = 0;\n        \$auditBudget = 2;\n        \$deferredAudits = 0;\n        \$errors = [];",
        'лимит фоновых аудитов'
    );

    $oldAuditLoop = <<<'PHPBLOCK'
                if ($auditDue) {
                    $fresh = $this->repository->site((int) $site['id']) ?? $site;
                    $this->auditSiteBySite($fresh, 'scheduled');
                    $audited++;
                }
PHPBLOCK;
    $newAuditLoop = <<<'PHPBLOCK'
                if ($auditDue && $audited < $auditBudget) {
                    $fresh = $this->repository->site((int) $site['id']) ?? $site;
                    $this->auditSiteBySite($fresh, 'scheduled');
                    $audited++;
                } elseif ($auditDue) {
                    $deferredAudits++;
                }
PHPBLOCK;
    $service = mh8replace($service, $oldAuditLoop, $newAuditLoop, 'бюджет аудитов worker');

    $service = mh8replace(
        $service,
        "            'audited' => \$audited,\n            'errors' => array_slice(\$errors, 0, 20),",
        "            'audited' => \$audited,\n            'deferred_audits' => \$deferredAudits,\n            'errors' => array_slice(\$errors, 0, 20),",
        'результат worker'
    );

    $queueMethod = <<<'PHPBLOCK'

    public function queueSiteCheck(int $siteId, bool $withAudit): void
    {
        $sql = 'UPDATE monitored_sites SET
                    last_checked_at = NULL,
                    updated_at = NOW()';
        if ($withAudit) {
            $sql .= ', last_audit_at = NULL';
        }
        $sql .= ' WHERE id = :id';

        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute(['id' => $siteId]);
    }
PHPBLOCK;
    $repository = mh8replace(
        $repository,
        "\n    public function deleteSite(int \$id, int \$projectId): void\n",
        $queueMethod . "\n    public function deleteSite(int \$id, int \$projectId): void\n",
        'метод постановки сайта в очередь'
    );
    $repository = mh8replace(
        $repository,
        "             LIMIT 100'",
        "             LIMIT 20'",
        'лимит сайтов за один запуск worker'
    );

    $api = mh8replace(
        $api,
        "            'message' => 'Сайт сохранён. Первичная проверка выполнена.',",
        "            'message' => !empty(\$result['queued_initial_audit'])\n                ? 'Сайт сохранён. Первичный аудит поставлен в фоновую очередь.'\n                : 'Настройки сайта сохранены.',",
        'сообщение API после сохранения сайта'
    );

    $js = mh8replace(
        $js,
        "        monitoringShowMessage('', form.elements.run_initial_audit.checked\n            ? 'Сохраняем сайт и выполняем первичный аудит. Это может занять до минуты…'\n            : 'Сохраняем настройки сайта…');",
        "        monitoringShowMessage('', form.elements.run_initial_audit.checked\n            ? 'Сохраняем сайт и ставим первичный аудит в фоновую очередь…'\n            : 'Сохраняем настройки сайта…');",
        'сообщение формы мониторинга'
    );

    $worker = <<<'PHPFILE'
<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите worker через PHP CLI.\n");
}

$root = realpath(dirname(__DIR__));
if (!is_string($root) || !is_file($root . '/app/bootstrap.php')) {
    fwrite(STDERR, "Не удалось определить корень проекта.\n");
    exit(1);
}

$lockPath = dirname($root, 2) . '/site-monitor-worker.lock';
$lock = fopen($lockPath, 'c+');
if (!is_resource($lock)) {
    fwrite(STDERR, "Не удалось открыть блокировку worker.\n");
    exit(1);
}

if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . "] Предыдущий monitoring worker ещё работает. Запуск пропущен.\n");
    exit(0);
}

require $root . '/app/bootstrap.php';

try {
    $service = new \SeoAnalytics\Services\SiteMonitoringService();
    $result = $service->runDueChecks();
    fwrite(STDOUT, '[' . date('Y-m-d H:i:s') . '] ' . json_encode(
        $result,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    ) . PHP_EOL);
} catch (Throwable $exception) {
    try {
        (new \SeoAnalytics\Repositories\MonitoringRepository())->setWorkerState(
            'heartbeat',
            [
                'status' => 'error',
                'finished_at' => date(DATE_ATOM),
                'error' => $exception->getMessage(),
            ]
        );
    } catch (Throwable) {
    }
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
PHPFILE;

    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=27', $index) ?? $index;

    mh8write($servicePath, $service);
    mh8write($repositoryPath, $repository);
    mh8write($workerPath, $worker);
    mh8write($apiPath, $api);
    mh8write($jsPath, $js);
    mh8write($indexPath, $index);

    mh8lint($servicePath);
    mh8lint($repositoryPath);
    mh8lint($workerPath);
    mh8lint($apiPath);
    mh8lint($indexPath);

    mh8out('Асинхронный мониторинг установлен.');
    mh8out('- добавление сайта больше не запускает тяжёлый аудит в браузере;');
    mh8out('- первичная проверка уходит в фоновую очередь;');
    mh8out('- одновременно работает только один monitoring worker;');
    mh8out('- за запуск обрабатывается не более 20 сайтов и 2 полных аудитов;');
    mh8out('- остальные аудиты автоматически переносятся на следующие запуски.');
    mh8out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $name) {
        @copy($backupDirectory . '/' . $name, $destination);
    }
    fwrite(STDERR, "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n");
    exit(1);
}
