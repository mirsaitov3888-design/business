<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Запустите через PHP CLI.\n");
}

function up13out(string $message = ''): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function up13read(string $path): string
{
    $content = file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }
    return $content;
}

function up13write(string $path, string $content): void
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

function up13replace(
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $count = 0;
    $updated = str_replace($needle, $replacement, $content, $count);
    if ($count !== 1) {
        throw new RuntimeException(
            "Не удалось изменить {$label}: найдено замен {$count}."
        );
    }
    return $updated;
}

function up13lint(string $path): void
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
            "Ошибка PHP-синтаксиса в {$path}:\n" . implode("\n", $output)
        );
    }
}

function up13applyPreviousRelease(string $root): void
{
    $indexPath = $root . '/index.php';
    $index = is_file($indexPath) ? (string) file_get_contents($indexPath) : '';
    if (str_contains($index, 'AVAILABILITY_LEGACY_REMOVED_V12')) {
        return;
    }

    $url = 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.01.12-cumulative.php';
    $expectedSha = '5ea97fddbe592d965a8582c7df40f37dc090dc742b19662936c45e80b6072564';
    $context = stream_context_create([
        'http' => [
            'timeout' => 90,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Update Chain/13',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $body = file_get_contents($url, false, $context);
    if (!is_string($body) || $body === '') {
        throw new RuntimeException('Не удалось загрузить обязательную версию 2026.08.01.12.');
    }
    if (!hash_equals($expectedSha, hash('sha256', $body))) {
        throw new RuntimeException('Не совпала SHA-256 обязательной версии 2026.08.01.12.');
    }

    $temporary = tempnam(sys_get_temp_dir(), 'mirsaitov-update-12-');
    if (!is_string($temporary)) {
        throw new RuntimeException('Не удалось создать временный файл версии 2026.08.01.12.');
    }
    file_put_contents($temporary, $body, LOCK_EX);
    $output = [];
    $code = 0;
    exec(
        'cd ' . escapeshellarg($root)
        . ' && ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($temporary)
        . ' 2>&1',
        $output,
        $code
    );
    @unlink($temporary);
    if ($code !== 0) {
        throw new RuntimeException(
            "Не удалось применить версию 2026.08.01.12:\n" . implode("\n", $output)
        );
    }
}

$root = getcwd() ?: '';
if (!is_file($root . '/index.php') || !is_file($root . '/app/bootstrap.php')) {
    throw new RuntimeException('Запустите установщик из корня проекта.');
}

up13applyPreviousRelease($root);

$indexPath = $root . '/index.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$repositoryPath = $root . '/app/Repositories/SystemUpdateRepository.php';
$workerPath = $root . '/app/Services/SystemUpdateWorker.php';

foreach ([$indexPath, $jsPath, $cssPath, $schemaPath, $repositoryPath, $workerPath] as $required) {
    if (!is_file($required)) {
        throw new RuntimeException('Не найден файл проекта: ' . $required);
    }
}

$index = up13read($indexPath);
$js = up13read($jsPath);
$css = up13read($cssPath);
$schema = up13read($schemaPath);
$repository = up13read($repositoryPath);
$worker = up13read($workerPath);

if (
    str_contains($js, 'SYSTEM_UPDATE_PROGRESS_V13')
    && str_contains($repository, 'public function setProgress(')
) {
    up13out('Живой прогресс установки обновлений уже установлен.');
    exit(0);
}

$backupDirectory = $root . '/storage/backups/system-update-progress-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {
    throw new RuntimeException('Не удалось создать резервную копию.');
}

$backupFiles = [
    $indexPath => 'index.php',
    $jsPath => 'app.js',
    $cssPath => 'app.css',
    $schemaPath => 'schema.sql',
    $repositoryPath => 'SystemUpdateRepository.php',
    $workerPath => 'SystemUpdateWorker.php',
];
foreach ($backupFiles as $source => $name) {
    if (!copy($source, $backupDirectory . '/' . $name)) {
        throw new RuntimeException("Не удалось сохранить резервную копию {$name}");
    }
}

try {
    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();
    $columns = [
        'progress_percent' => 'TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER status',
        'progress_step' => 'VARCHAR(80) NULL AFTER progress_percent',
        'progress_message' => 'VARCHAR(1000) NULL AFTER progress_step',
        'progress_updated_at' => 'DATETIME NULL AFTER progress_message',
    ];
    foreach ($columns as $name => $definition) {
        $stmt = $pdo->prepare('SHOW COLUMNS FROM system_updates LIKE :column_name');
        $stmt->execute(['column_name' => $name]);
        if (!$stmt->fetch()) {
            $pdo->exec('ALTER TABLE system_updates ADD COLUMN ' . $name . ' ' . $definition);
        }
    }

    if (!str_contains($schema, 'progress_percent TINYINT')) {
        $schema = up13replace(
            $schema,
            "    status VARCHAR(40) NOT NULL DEFAULT 'queued',\n",
            "    status VARCHAR(40) NOT NULL DEFAULT 'queued',\n"
            . "    progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,\n"
            . "    progress_step VARCHAR(80) NULL,\n"
            . "    progress_message VARCHAR(1000) NULL,\n"
            . "    progress_updated_at DATETIME NULL,\n",
            'схему прогресса обновлений'
        );
    }

    if (!str_contains($repository, "\$row['progress_percent']")) {
        $repository = up13replace(
            $repository,
            "            \$row['id'] = (int) \$row['id'];\n",
            "            \$row['id'] = (int) \$row['id'];\n"
            . "            \$row['progress_percent'] = max(0, min(100, (int) (\$row['progress_percent'] ?? 0)));\n",
            'нормализацию прогресса в истории'
        );
    }

    if (!str_contains($repository, 'public function setProgress(')) {
        $progressMethod = <<<'PHPBLOCK'

    public function setProgress(
        int $id,
        int $percent,
        string $step,
        string $message
    ): void {
        $stmt = Database::pdo()->prepare(
            'UPDATE system_updates
             SET progress_percent = :progress_percent,
                 progress_step = :progress_step,
                 progress_message = :progress_message,
                 progress_updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'progress_percent' => max(0, min(100, $percent)),
            'progress_step' => mb_substr(trim($step), 0, 80),
            'progress_message' => mb_substr(trim($message), 0, 1000),
        ]);
    }
PHPBLOCK;
        $repository = up13replace(
            $repository,
            "\n    private function hasActiveJob(): bool\n",
            $progressMethod . "\n    private function hasActiveJob(): bool\n",
            'метод сохранения прогресса'
        );
    }

    $worker = up13replace(
        $worker,
        "        \$this->repository->markStarted(\$id, 'installing');\n        \$log = \$this->line('Начинаем установку версии ' . \$job['version']);\n        \$backupPath = null;\n",
        "        \$this->repository->markStarted(\$id, 'installing');\n"
        . "        \$progress = 5;\n"
        . "        \$this->repository->setProgress(\$id, \$progress, 'preparing', 'Подготавливаем установку обновления.');\n"
        . "        \$log = \$this->line('Начинаем установку версии ' . \$job['version']);\n"
        . "        \$backupPath = null;\n",
        'начальный прогресс установки'
    );

    $worker = up13replace(
        $worker,
        "            \$this->service->validateRelease(\$release);\n            \$currentManifest = \$this->service->manifest();\n",
        "            \$progress = 12;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'manifest', 'Проверяем описание и источник обновления.');\n"
        . "            \$this->service->validateRelease(\$release);\n"
        . "            \$currentManifest = \$this->service->manifest();\n",
        'проверку манифеста'
    );

    $worker = up13replace(
        $worker,
        "            \$installer = \$this->service->download(\n",
        "            \$progress = 22;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'download', 'Загружаем установщик из разрешённого репозитория.');\n"
        . "            \$installer = \$this->service->download(\n",
        'этап загрузки установщика'
    );

    $worker = up13replace(
        $worker,
        "            \$actualSha = hash('sha256', \$installer);\n\n            if (!hash_equals",
        "            \$actualSha = hash('sha256', \$installer);\n"
        . "            \$progress = 32;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'checksum', 'Сверяем контрольную сумму SHA-256.');\n\n"
        . "            if (!hash_equals",
        'этап проверки SHA-256'
    );

    $worker = up13replace(
        $worker,
        "            \$this->lint(\$runtimePath);\n            \$log .= \$this->line('PHP-синтаксис установщика корректен.');\n\n            \$backupPath = \$this->createBackup(\n",
        "            \$progress = 40;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'lint', 'Проверяем PHP-синтаксис установщика.');\n"
        . "            \$this->lint(\$runtimePath);\n"
        . "            \$log .= \$this->line('PHP-синтаксис установщика корректен.');\n\n"
        . "            \$progress = 48;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'backup', 'Создаём полную резервную копию проекта.');\n"
        . "            \$backupPath = \$this->createBackup(\n",
        'этапы lint и резервной копии'
    );

    $worker = up13replace(
        $worker,
        "            \$this->repository->appendLog(\$id, \$log);\n            \$log = '';\n\n            [\$exitCode, \$output] = \$this->execute([\n",
        "            \$this->repository->appendLog(\$id, \$log);\n"
        . "            \$log = '';\n\n"
        . "            \$progress = 62;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'install', 'Применяем изменения обновления.');\n"
        . "            [\$exitCode, \$output] = \$this->execute([\n",
        'этап применения обновления'
    );

    $worker = up13replace(
        $worker,
        "            \$this->healthCheck();\n            \$log .= \$this->line('Проверка PHP-файлов после обновления пройдена.');\n            \$this->writeVersion((string) \$job['version']);\n",
        "            \$progress = 84;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'healthcheck', 'Проверяем работоспособность системы после установки.');\n"
        . "            \$this->healthCheck();\n"
        . "            \$log .= \$this->line('Проверка PHP-файлов после обновления пройдена.');\n"
        . "            \$progress = 95;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'version', 'Фиксируем новую версию системы.');\n"
        . "            \$this->writeVersion((string) \$job['version']);\n",
        'финальную проверку и запись версии'
    );

    $worker = up13replace(
        $worker,
        "            \$this->repository->markFinished(\n                \$id,\n                'installed',\n                \$log,\n                \$backupPath\n            );\n",
        "            \$this->repository->markFinished(\n"
        . "                \$id,\n"
        . "                'installed',\n"
        . "                \$log,\n"
        . "                \$backupPath\n"
        . "            );\n"
        . "            \$this->repository->setProgress(\$id, 100, 'completed', 'Обновление успешно установлено.');\n",
        'завершение установки'
    );

    $worker = up13replace(
        $worker,
        "            \$this->repository->markFinished(\n                \$id,\n                'failed',\n                \$log,\n                \$backupPath\n            );\n",
        "            \$this->repository->markFinished(\n"
        . "                \$id,\n"
        . "                'failed',\n"
        . "                \$log,\n"
        . "                \$backupPath\n"
        . "            );\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'failed', 'Ошибка: ' . \$exception->getMessage());\n",
        'ошибку установки'
    );

    $worker = up13replace(
        $worker,
        "        \$this->repository->markStarted(\$id, 'rolling_back');\n        \$log = \$this->line('Начинаем откат версии ' . \$job['version']);\n",
        "        \$this->repository->markStarted(\$id, 'rolling_back');\n"
        . "        \$progress = 10;\n"
        . "        \$this->repository->setProgress(\$id, \$progress, 'rollback_preparing', 'Подготавливаем откат обновления.');\n"
        . "        \$log = \$this->line('Начинаем откат версии ' . \$job['version']);\n",
        'начальный прогресс отката'
    );

    $worker = up13replace(
        $worker,
        "            \$currentBackup = \$this->createBackup(\n",
        "            \$progress = 30;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'rollback_backup', 'Сохраняем текущее состояние перед откатом.');\n"
        . "            \$currentBackup = \$this->createBackup(\n",
        'резервную копию перед откатом'
    );

    $worker = up13replace(
        $worker,
        "            \$quarantine = \$this->restoreBackup(\$backupPath, 'rollback-source');\n",
        "            \$progress = 58;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'rollback_restore', 'Восстанавливаем выбранную резервную копию.');\n"
        . "            \$quarantine = \$this->restoreBackup(\$backupPath, 'rollback-source');\n",
        'восстановление резервной копии'
    );

    $worker = up13replace(
        $worker,
        "            \$this->healthCheck();\n            \$log .= \$this->line('Проверка после отката пройдена.');\n\n            \$this->repository->markFinished(\n                \$id,\n                'rolled_back',\n                \$log,\n                \$currentBackup\n            );\n",
        "            \$progress = 86;\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'rollback_healthcheck', 'Проверяем систему после отката.');\n"
        . "            \$this->healthCheck();\n"
        . "            \$log .= \$this->line('Проверка после отката пройдена.');\n\n"
        . "            \$this->repository->markFinished(\n"
        . "                \$id,\n"
        . "                'rolled_back',\n"
        . "                \$log,\n"
        . "                \$currentBackup\n"
        . "            );\n"
        . "            \$this->repository->setProgress(\$id, 100, 'rollback_completed', 'Откат успешно завершён.');\n",
        'завершение отката'
    );

    $worker = up13replace(
        $worker,
        "            \$this->repository->markFinished(\n                \$id,\n                'rollback_failed',\n                \$log,\n                null\n            );\n",
        "            \$this->repository->markFinished(\n"
        . "                \$id,\n"
        . "                'rollback_failed',\n"
        . "                \$log,\n"
        . "                null\n"
        . "            );\n"
        . "            \$this->repository->setProgress(\$id, \$progress, 'rollback_failed', 'Ошибка отката: ' . \$exception->getMessage());\n",
        'ошибку отката'
    );

    if (!str_contains($js, 'SYSTEM_UPDATE_PROGRESS_V13')) {
        $jsPatch = <<<'JSPATCH'
    /* SYSTEM_UPDATE_PROGRESS_V13 */
    const systemUpdateInstallStages = [
        [0, 'queued', 'В очереди'],
        [5, 'preparing', 'Подготовка'],
        [12, 'manifest', 'Проверка манифеста'],
        [22, 'download', 'Загрузка установщика'],
        [32, 'checksum', 'Проверка SHA-256'],
        [40, 'lint', 'Проверка PHP'],
        [48, 'backup', 'Резервная копия'],
        [62, 'install', 'Установка'],
        [84, 'healthcheck', 'Проверка системы'],
        [95, 'version', 'Фиксация версии'],
        [100, 'completed', 'Готово']
    ];
    const systemUpdateRollbackStages = [
        [0, 'rollback_queued', 'В очереди'],
        [10, 'rollback_preparing', 'Подготовка'],
        [30, 'rollback_backup', 'Сохранение текущей версии'],
        [58, 'rollback_restore', 'Восстановление копии'],
        [86, 'rollback_healthcheck', 'Проверка системы'],
        [100, 'rollback_completed', 'Готово']
    ];

    function systemUpdatesEnsureProgressRoot() {
        let root = $('#systemUpdatesProgress');
        if (root) return root;
        const versionBlock = document.querySelector('.updates-current-panel .updates-version-block');
        if (!versionBlock) return null;
        root = document.createElement('div');
        root.id = 'systemUpdatesProgress';
        root.className = 'updates-progress-card';
        versionBlock.insertAdjacentElement('afterend', root);
        return root;
    }

    function systemUpdatesElapsed(row) {
        const startRaw = row.started_at || row.created_at;
        if (!startRaw) return '—';
        const start = new Date(String(startRaw).replace(' ', 'T'));
        const end = row.finished_at
            ? new Date(String(row.finished_at).replace(' ', 'T'))
            : new Date();
        if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) return '—';
        const seconds = Math.max(0, Math.round((end.getTime() - start.getTime()) / 1000));
        if (seconds < 60) return `${seconds} сек.`;
        const minutes = Math.floor(seconds / 60);
        const rest = seconds % 60;
        if (minutes < 60) return `${minutes} мин. ${rest} сек.`;
        const hours = Math.floor(minutes / 60);
        return `${hours} ч. ${minutes % 60} мин.`;
    }

    function systemUpdatesRenderProgress(rows) {
        const root = systemUpdatesEnsureProgressRoot();
        if (!root) return;
        const activeStatuses = ['queued', 'installing', 'rollback_queued', 'rolling_back'];
        const active = rows.find(row => activeStatuses.includes(row.status));
        const row = active || rows[0] || null;

        if (!row) {
            root.className = 'updates-progress-card is-empty';
            root.innerHTML = '<strong>Текущих операций нет</strong><span>Прогресс появится после запуска обновления или отката.</span>';
            return;
        }

        const failed = ['failed', 'rollback_failed'].includes(row.status);
        const success = ['installed', 'rolled_back'].includes(row.status);
        const percent = success
            ? 100
            : Math.max(0, Math.min(100, Number(row.progress_percent || 0)));
        const rollback = row.action_type === 'rollback';
        const stages = rollback ? systemUpdateRollbackStages : systemUpdateInstallStages;
        const step = String(row.progress_step || (rollback ? 'rollback_queued' : 'queued'));
        const message = String(row.progress_message || systemUpdateStatusLabels[row.status] || row.status || 'Ожидаем worker');

        root.className = `updates-progress-card${failed ? ' is-error' : success ? ' is-success' : active ? ' is-active' : ''}`;
        root.innerHTML = `
            <div class="updates-progress-head">
                <div>
                    <span>${active ? 'Текущая операция' : 'Последняя операция'}</span>
                    <strong>${escapeHtml(row.title || `Версия ${row.version}`)}</strong>
                </div>
                <em>${escapeHtml(`${percent}%`)}</em>
            </div>
            <div class="updates-progress-track" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${percent}">
                <i style="width:${percent}%"></i>
            </div>
            <div class="updates-progress-meta">
                <strong>${escapeHtml(message)}</strong>
                <span>Начало: ${escapeHtml(systemUpdatesDate(row.started_at || row.created_at))} · Длительность: ${escapeHtml(systemUpdatesElapsed(row))}</span>
            </div>
            <div class="updates-progress-steps">
                ${stages.map(([threshold, key, label]) => {
                    const isFailedStep = failed && key === step;
                    const isCurrent = !success && key === step;
                    const isDone = success || percent > Number(threshold) || (percent === Number(threshold) && !isCurrent && !isFailedStep);
                    const className = isFailedStep ? 'is-error' : isCurrent ? 'is-current' : isDone ? 'is-done' : '';
                    return `<span class="${className}"><i></i>${escapeHtml(label)}</span>`;
                }).join('')}
            </div>`;
    }

JSPATCH;
        $js = up13replace(
            $js,
            "    function systemUpdatesHasActiveJob(rows) {\n",
            $jsPatch . "    function systemUpdatesHasActiveJob(rows) {\n",
            'интерфейс прогресса обновлений'
        );
    }

    $js = up13replace(
        $js,
        "            systemUpdatesRenderHistory(state.history || []);\n            systemUpdatesManagePolling(state.history || []);\n",
        "            systemUpdatesRenderHistory(state.history || []);\n"
        . "            systemUpdatesRenderProgress(state.history || []);\n"
        . "            systemUpdatesManagePolling(state.history || []);\n",
        'отрисовку прогресса'
    );
    $js = str_replace(
        "                5000\n            );",
        "                2000\n            );",
        $js
    );

    if (!str_contains($css, 'SYSTEM_UPDATE_PROGRESS_V13_CSS')) {
        $css .= <<<'CSS'

/* SYSTEM_UPDATE_PROGRESS_V13_CSS */
.updates-progress-card {
    display: grid;
    gap: 11px;
    margin: 0 0 16px;
    padding: 15px;
    border: 1px solid #b9cdfd;
    border-radius: 13px;
    background: #f5f8ff;
}

.updates-progress-card.is-empty {
    gap: 4px;
    border-color: var(--line);
    background: #f8fafc;
    color: var(--muted);
}

.updates-progress-card.is-success {
    border-color: #a6e0bd;
    background: #ecfdf3;
}

.updates-progress-card.is-error {
    border-color: #f5b7b1;
    background: #fef3f2;
}

.updates-progress-head,
.updates-progress-meta {
    display: flex;
    justify-content: space-between;
    gap: 14px;
    align-items: flex-start;
}

.updates-progress-head > div,
.updates-progress-meta {
    min-width: 0;
}

.updates-progress-head span,
.updates-progress-meta span {
    display: block;
    color: var(--muted);
    font-size: 11px;
    line-height: 1.45;
}

.updates-progress-head strong,
.updates-progress-meta strong {
    display: block;
    margin-top: 3px;
    line-height: 1.4;
}

.updates-progress-head em {
    flex: 0 0 auto;
    color: #175cd3;
    font-size: 22px;
    font-style: normal;
    font-weight: 800;
}

.updates-progress-card.is-success .updates-progress-head em {
    color: #067647;
}

.updates-progress-card.is-error .updates-progress-head em,
.updates-progress-card.is-error .updates-progress-meta strong {
    color: #b42318;
}

.updates-progress-track {
    height: 10px;
    overflow: hidden;
    border-radius: 999px;
    background: #dbe5fb;
}

.updates-progress-track i {
    display: block;
    height: 100%;
    border-radius: inherit;
    background: #2563eb;
    transition: width .35s ease;
}

.updates-progress-card.is-success .updates-progress-track {
    background: #ccebd7;
}

.updates-progress-card.is-success .updates-progress-track i {
    background: #12b76a;
}

.updates-progress-card.is-error .updates-progress-track {
    background: #f8d5d1;
}

.updates-progress-card.is-error .updates-progress-track i {
    background: #f04438;
}

.updates-progress-meta {
    align-items: flex-end;
}

.updates-progress-meta span {
    flex: 0 0 auto;
    text-align: right;
}

.updates-progress-steps {
    display: flex;
    gap: 7px;
    overflow-x: auto;
    padding: 3px 0 2px;
    scrollbar-width: thin;
}

.updates-progress-steps span {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    flex: 0 0 auto;
    padding: 5px 8px;
    border-radius: 999px;
    background: #fff;
    color: #667085;
    font-size: 10px;
    white-space: nowrap;
}

.updates-progress-steps span i {
    width: 7px;
    height: 7px;
    border-radius: 50%;
    background: #d0d5dd;
}

.updates-progress-steps span.is-done {
    color: #067647;
}

.updates-progress-steps span.is-done i {
    background: #12b76a;
}

.updates-progress-steps span.is-current {
    background: #e8efff;
    color: #175cd3;
    font-weight: 800;
}

.updates-progress-steps span.is-current i {
    background: #2563eb;
    box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
}

.updates-progress-steps span.is-error {
    background: #fee4e2;
    color: #b42318;
    font-weight: 800;
}

.updates-progress-steps span.is-error i {
    background: #f04438;
}

@media (max-width: 650px) {
    .updates-progress-head,
    .updates-progress-meta {
        align-items: stretch;
        flex-direction: column;
    }

    .updates-progress-meta span {
        text-align: left;
    }
}
CSS;
    }

    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=33', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=33', $index) ?? $index;

    up13write($repositoryPath, $repository);
    up13write($workerPath, $worker);
    up13write($jsPath, $js);
    up13write($cssPath, $css);
    up13write($schemaPath, $schema);
    up13write($indexPath, $index);

    up13lint($repositoryPath);
    up13lint($workerPath);
    up13lint($indexPath);

    up13out('Живой прогресс системных обновлений установлен.');
    up13out('- реальный процент сохраняется в базе;');
    up13out('- отображается текущий этап установки или отката;');
    up13out('- показаны пройденные и ожидающие этапы;');
    up13out('- отображаются время начала и длительность;');
    up13out('- активная операция обновляется каждые 2 секунды;');
    up13out('- ошибки выделяются красным с фактической причиной.');
    up13out('Резервная копия: ' . $backupDirectory);
} catch (Throwable $exception) {
    foreach ($backupFiles as $destination => $name) {
        $source = $backupDirectory . '/' . $name;
        if (is_file($source)) {
            @copy($source, $destination);
        }
    }
    fwrite(
        STDERR,
        "ОШИБКА: {$exception->getMessage()}\nФайлы восстановлены из резервной копии.\n"
    );
    exit(1);
}
