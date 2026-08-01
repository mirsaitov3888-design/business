<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function availabilityOut(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function availabilityRoot(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/api.php')
        || !is_file($root . '/assets/app.js')
        || !is_file($root . '/assets/app.css')
        || !is_file($root . '/sql/schema.sql')
    ) {
        throw new RuntimeException(
            'Поместите установщик в каталог bin проекта.'
        );
    }

    return $root;
}

function availabilityRead(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function availabilityDownload(string $url): string
{
    $context = stream_context_create([
        'http' => [
            'timeout' => 60,
            'follow_location' => 1,
            'user_agent' => 'Mirsaitov Availability Installer/1.0',
        ],
        'https' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);

    $content = file_get_contents($url, false, $context);

    if (!is_string($content) || $content === '') {
        throw new RuntimeException(
            "Не удалось загрузить компонент: {$url}"
        );
    }

    return $content;
}

function availabilityReplaceOnce(
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $position = strpos($content, $needle);

    if ($position === false) {
        throw new RuntimeException(
            "Не найдена точка изменения: {$label}"
        );
    }

    return substr($content, 0, $position)
        . $replacement
        . substr($content, $position + strlen($needle));
}

function availabilityInsertBefore(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return availabilityReplaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function availabilityWrite(string $path, string $content): void
{
    $directory = dirname($path);

    if (
        !is_dir($directory)
        && !mkdir($directory, 0775, true)
        && !is_dir($directory)
    ) {
        throw new RuntimeException(
            "Не удалось создать каталог {$directory}"
        );
    }

    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException(
            "Не удалось записать {$temporary}"
        );
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException(
            "Не удалось заменить {$path}"
        );
    }
}

function availabilityBackup(
    string $root,
    array $paths,
    string $backupDirectory
): array {
    if (
        !mkdir($backupDirectory, 0700, true)
        && !is_dir($backupDirectory)
    ) {
        throw new RuntimeException(
            'Не удалось создать резервную копию.'
        );
    }

    $manifest = [];

    foreach ($paths as $relativePath) {
        $source = $root . '/' . $relativePath;
        $exists = is_file($source);
        $manifest[$relativePath] = $exists;

        if (!$exists) {
            continue;
        }

        $destination = $backupDirectory . '/' . $relativePath;
        $directory = dirname($destination);

        if (
            !is_dir($directory)
            && !mkdir($directory, 0700, true)
            && !is_dir($directory)
        ) {
            throw new RuntimeException(
                "Не удалось создать каталог копии {$directory}"
            );
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException(
                "Не удалось сохранить копию {$relativePath}"
            );
        }
    }

    return $manifest;
}

function availabilityRollback(
    string $root,
    string $backupDirectory,
    array $manifest
): void {
    foreach ($manifest as $relativePath => $existed) {
        $destination = $root . '/' . $relativePath;

        if ($existed) {
            $source = $backupDirectory . '/' . $relativePath;

            if (is_file($source)) {
                @copy($source, $destination);
            }
        } elseif (is_file($destination)) {
            @unlink($destination);
        }
    }
}

function availabilityLintPhp(string $path): void
{
    if (!function_exists('exec')) {
        availabilityOut("Предупреждение: PHP-lint пропущен для {$path}");
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
            "Ошибка PHP-синтаксиса в {$path}:\n"
            . implode("\n", $output)
        );
    }
}

$root = availabilityRoot();
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$repositoryPath = $root . '/app/Repositories/SiteMonitorRepository.php';
$servicePath = $root . '/app/Services/SiteMonitorService.php';
$cronPath = $root . '/bin/run_site_monitor.php';

$indexOriginal = availabilityRead($indexPath);
$apiOriginal = availabilityRead($apiPath);
$jsOriginal = availabilityRead($jsPath);
$cssOriginal = availabilityRead($cssPath);
$schemaOriginal = availabilityRead($schemaPath);

if (
    str_contains($indexOriginal, 'AVAILABILITY_MODULE')
    || str_contains($jsOriginal, 'AVAILABILITY_MODULE_JS')
) {
    availabilityOut('Модуль доступности сайта уже установлен.');
    exit(0);
}

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'sql/schema.sql',
    'app/Repositories/SiteMonitorRepository.php',
    'app/Services/SiteMonitorService.php',
    'bin/run_site_monitor.php',
];
$backupDirectory = $root
    . '/storage/backups/availability-module-'
    . date('Ymd-His');
$manifest = availabilityBackup(
    $root,
    $paths,
    $backupDirectory
);

availabilityOut("Резервная копия: {$backupDirectory}");

$baseUrl = 'https://raw.githubusercontent.com/'
    . 'mirsaitov3888-design/business/main/'
    . 'tools/availability/';

$schemaStatements = [];
$schemaStatements[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS site_monitors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    url VARCHAR(1000) NOT NULL,
    interval_minutes INT UNSIGNED NOT NULL DEFAULT 5,
    timeout_seconds INT UNSIGNED NOT NULL DEFAULT 15,
    slow_threshold_ms INT UNSIGNED NOT NULL DEFAULT 3000,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    current_status ENUM('unknown','up','degraded','down') NOT NULL DEFAULT 'unknown',
    last_checked_at DATETIME NULL,
    last_http_code INT NULL,
    last_response_ms INT NULL,
    ssl_expires_at DATETIME NULL,
    last_error TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_site_monitor_project (project_id),
    KEY idx_site_monitor_enabled (enabled),
    CONSTRAINT fk_site_monitor_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$schemaStatements[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS site_monitor_checks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id BIGINT UNSIGNED NOT NULL,
    checked_at DATETIME NOT NULL,
    status ENUM('up','degraded','down') NOT NULL,
    http_code INT NULL,
    response_ms INT NULL,
    final_url VARCHAR(1000) NULL,
    error_message TEXT NULL,
    ssl_expires_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_site_checks_monitor_date (monitor_id, checked_at),
    KEY idx_site_checks_status (monitor_id, status, checked_at),
    CONSTRAINT fk_site_checks_monitor
        FOREIGN KEY (monitor_id) REFERENCES site_monitors(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

$schemaStatements[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS site_monitor_incidents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    monitor_id BIGINT UNSIGNED NOT NULL,
    status ENUM('open','resolved') NOT NULL DEFAULT 'open',
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    duration_seconds BIGINT UNSIGNED NULL,
    first_error TEXT NULL,
    last_error TEXT NULL,
    failed_checks INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_site_incidents_monitor_date (monitor_id, started_at),
    KEY idx_site_incidents_open (monitor_id, status),
    CONSTRAINT fk_site_incidents_monitor
        FOREIGN KEY (monitor_id) REFERENCES site_monitors(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

try {
    $section = availabilityDownload($baseUrl . 'availability_section.html');
    $jsFragment = availabilityDownload($baseUrl . 'availability.js');
    $cssFragment = availabilityDownload($baseUrl . 'availability.css');
    $repository = availabilityDownload($baseUrl . 'SiteMonitorRepository.php');
    $service = availabilityDownload($baseUrl . 'SiteMonitorService.php');
    $cron = availabilityDownload($baseUrl . 'run_site_monitor.php');
    $apiGet = availabilityDownload($baseUrl . 'availability_api.phpfrag');
    $apiPost = availabilityDownload($baseUrl . 'availability_api_post.phpfrag');

    $index = $indexOriginal;
    $api = $apiOriginal;
    $js = $jsOriginal;
    $css = $cssOriginal;
    $schema = $schemaOriginal;

    $index = availabilityInsertBefore(
        $index,
        '                <button class="nav-link" data-section="settings">Настройки</button>',
        '                <button class="nav-link" data-section="availability">Доступность сайта</button>' . "\n",
        'пункт меню доступности'
    );

    $index = availabilityInsertBefore(
        $index,
        '        <section id="section-settings" class="section">',
        $section,
        'раздел доступности сайта'
    );

    $api = availabilityReplaceOnce(
        $api,
        'use SeoAnalytics\Services\WebmasterService;',
        "use SeoAnalytics\\Services\\WebmasterService;\n"
        . 'use SeoAnalytics\Services\SiteMonitorService;',
        'импорт SiteMonitorService'
    );

    $api = availabilityInsertBefore(
        $api,
        "        Security::json(['error' => 'Неизвестное действие.'], 404);",
        $apiGet,
        'GET API доступности'
    );

    $postMarker = str_contains($api, '    // REPORTS_STEP1_API_POST')
        ? '    // REPORTS_STEP1_API_POST'
        : "    if (\$action === 'save_report') {";

    $api = availabilityInsertBefore(
        $api,
        $postMarker,
        $apiPost,
        'POST API доступности'
    );

    $js = availabilityInsertBefore(
        $js,
        '    async function loadDashboard() {',
        $jsFragment . "\n",
        'JavaScript доступности сайта'
    );

    $css .= "\n" . $cssFragment . "\n";

    if (!str_contains($schema, 'AVAILABILITY_MODULE_SCHEMA')) {
        $schema .= "\n\n-- AVAILABILITY_MODULE_SCHEMA\n";

        foreach ($schemaStatements as $statement) {
            $schema .= $statement . ";\n\n";
        }
    }

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=16',
        $index
    ) ?? $index;
    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=16',
        $index
    ) ?? $index;

    availabilityWrite($repositoryPath, $repository);
    availabilityWrite($servicePath, $service);
    availabilityWrite($cronPath, $cron);
    availabilityWrite($indexPath, $index);
    availabilityWrite($apiPath, $api);
    availabilityWrite($jsPath, $js);
    availabilityWrite($cssPath, $css);
    availabilityWrite($schemaPath, $schema);
    @chmod($cronPath, 0755);

    availabilityLintPhp($repositoryPath);
    availabilityLintPhp($servicePath);
    availabilityLintPhp($cronPath);
    availabilityLintPhp($indexPath);
    availabilityLintPhp($apiPath);

    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();

    foreach ($schemaStatements as $statement) {
        $pdo->exec($statement);
    }

    $project = (new \SeoAnalytics\Repositories\ProjectRepository())
        ->firstActive();

    if ($project) {
        (new \SeoAnalytics\Repositories\SiteMonitorRepository())
            ->ensureForProject($project);
    }

    availabilityOut('');
    availabilityOut('Модуль доступности сайта установлен.');
    availabilityOut('- текущий статус и HTTP-код;');
    availabilityOut('- uptime за выбранный период;');
    availabilityOut('- среднее и P95 времени ответа;');
    availabilityOut('- срок действия SSL;');
    availabilityOut('- история проверок и инцидентов;');
    availabilityOut('- ручная проверка из интерфейса;');
    availabilityOut('- CLI-команда для запуска по Cron.');
    availabilityOut('');
    availabilityOut('Команда Cron каждые 5 минут:');
    availabilityOut('php ' . $cronPath);
    availabilityOut('');
    availabilityOut("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    availabilityRollback(
        $root,
        $backupDirectory,
        $manifest
    );

    fwrite(
        STDERR,
        PHP_EOL
        . 'ОШИБКА: '
        . $exception->getMessage()
        . PHP_EOL
        . 'Файлы восстановлены из резервной копии.'
        . PHP_EOL
    );

    exit(1);
}
