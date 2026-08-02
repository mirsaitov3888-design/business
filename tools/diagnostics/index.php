<?php
declare(strict_types=1);

header('X-Robots-Tag: noindex, nofollow, noarchive', true);
header('Cache-Control: no-store, no-cache, must-revalidate');

$dataRoot = dirname(__DIR__, 3);
$agentRoot = $dataRoot . '/mirsaitov-diagnostics';
$configPath = $agentRoot . '/config.php';
$corePath = $agentRoot . '/agent.php';

if (!is_file($configPath) || !is_file($corePath)) {
    http_response_code(503);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"Unified diagnostic center is not installed"}';
    exit;
}

function md_public_session_has_identity(mixed $value, int $depth = 0, string $scope = 'root'): bool
{
    if ($depth > 5 || !is_array($value)) {
        return false;
    }

    foreach ($value as $key => $item) {
        $normalized = strtolower((string) $key);
        if (in_array($normalized, ['user_id', 'auth_user_id', 'userid', 'uid'], true)
            && (int) $item > 0) {
            return true;
        }
        if ($normalized === 'id'
            && in_array($scope, ['root', 'user', 'auth_user', 'profile'], true)
            && (int) $item > 0) {
            return true;
        }
        if ($normalized === 'email'
            && is_string($item)
            && filter_var($item, FILTER_VALIDATE_EMAIL)) {
            return true;
        }
        if (is_array($item)
            && md_public_session_has_identity($item, $depth + 1, $normalized)) {
            return true;
        }
    }

    return false;
}

function md_public_not_found(): never
{
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo '{"error":"Not found"}';
    exit;
}

$settings = require $configPath;
$provided = (string) ($_GET['token'] ?? ($_SERVER['HTTP_X_DIAGNOSTIC_TOKEN'] ?? ''));
$tokenAuthorized = is_array($settings)
    && isset($settings['token'])
    && $provided !== ''
    && hash_equals((string) $settings['token'], $provided);
$sessionAuthorized = false;

if (!$tokenAuthorized && is_array($settings)) {
    $cookieName = session_name();
    $hasSessionCookie = $cookieName !== '' && isset($_COOKIE[$cookieName]);

    if ($hasSessionCookie) {
        try {
            $bootstrap = rtrim((string) ($settings['site_root'] ?? ''), '/') . '/app/bootstrap.php';
            if (is_file($bootstrap)) {
                require_once $bootstrap;
                if (session_status() === PHP_SESSION_NONE) {
                    @session_start();
                }

                if (md_public_session_has_identity($_SESSION ?? [])) {
                    $accessClass = '\\SeoAnalytics\\Services\\PortalAccessService';
                    if (class_exists($accessClass)) {
                        $access = new $accessClass();
                        $access->requireRoles(['administrator']);
                        $sessionAuthorized = true;
                    }
                }
            }
        } catch (Throwable) {
            $sessionAuthorized = false;
        }
    }
}

if (!$tokenAuthorized && !$sessionAuthorized) {
    md_public_not_found();
}

require_once $corePath;

$format = strtolower((string) ($_GET['format'] ?? 'html'));
$deep = !isset($_GET['quick']);
$fresh = isset($_GET['fresh']);
$cacheDir = $agentRoot . '/reports';
$cacheFile = $cacheDir . '/latest.json';
$ttl = max(30, (int) ($settings['cache_ttl_seconds'] ?? 120));
$requestedReport = trim((string) ($_GET['report'] ?? ''));

if (!is_dir($cacheDir)) {
    @mkdir($cacheDir, 0700, true);
}

if ($requestedReport !== '') {
    $requestedReport = basename($requestedReport);
    if (preg_match('/\Aupdate-[0-9A-Za-z._-]+\.json\z/', $requestedReport) !== 1) {
        md_public_not_found();
    }

    $selectedPath = $cacheDir . '/' . $requestedReport;
    if (!is_file($selectedPath) || !is_readable($selectedPath)) {
        md_public_not_found();
    }

    $json = (string) file_get_contents($selectedPath);
    $report = json_decode($json, true);
    if (!is_array($report)) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo '{"error":"Diagnostic report is damaged"}';
        exit;
    }
} else {
    $useCache = !$fresh
        && is_file($cacheFile)
        && is_readable($cacheFile)
        && (time() - (int) filemtime($cacheFile) < $ttl);

    if ($useCache) {
        $json = (string) file_get_contents($cacheFile);
        $report = json_decode($json, true);
        if (!is_array($report)) {
            $report = md_report($settings, $deep);
            $json = md_json($report);
        }
    } else {
        $lock = @fopen($cacheDir . '/collector.lock', 'c');
        if (is_resource($lock) && !flock($lock, LOCK_EX | LOCK_NB)) {
            http_response_code(429);
            header('Content-Type: application/json; charset=utf-8');
            echo md_json(['error' => 'Диагностика уже выполняется.']);
            exit;
        }

        try {
            $report = md_report($settings, $deep);
            $json = md_json($report);
            @file_put_contents($cacheFile, $json, LOCK_EX);
            @chmod($cacheFile, 0600);
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    echo $json;
    exit;
}

if ($format === 'download') {
    header('Content-Type: application/json; charset=utf-8');
    header(
        'Content-Disposition: attachment; filename="mirsaitov-diagnostics-'
        . date('Ymd-His') . '.json"'
    );
    echo $json;
    exit;
}

$findings = is_array($report['findings'] ?? null) ? $report['findings'] : [];
$counts = ['error' => 0, 'warning' => 0, 'ok' => 0, 'info' => 0];
foreach ($findings as $finding) {
    $severity = (string) ($finding['severity'] ?? 'info');
    if (isset($counts[$severity])) {
        $counts[$severity]++;
    }
}

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function md_public_url(string $base, array $parameters): string
{
    $parameters = array_filter(
        $parameters,
        static fn(mixed $value): bool => $value !== null && $value !== ''
    );
    $query = http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);
    return $base . ($query === '' ? '' : '?' . $query);
}

$base = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/diagnostics/'), '?')
    ?: '/diagnostics/';
$authParameters = $tokenAuthorized
    ? ['token' => (string) $settings['token']]
    : [];
$currentReportParameters = $requestedReport !== ''
    ? ['report' => $requestedReport]
    : [];

$fullUrl = md_public_url($base, $authParameters + ['fresh' => 1]);
$quickUrl = md_public_url($base, $authParameters + ['quick' => 1, 'fresh' => 1]);
$jsonUrl = md_public_url(
    $base,
    $authParameters + $currentReportParameters + ['format' => 'json']
);
$downloadUrl = md_public_url(
    $base,
    $authParameters + $currentReportParameters + ['format' => 'download']
);
$latestUrl = md_public_url($base, $authParameters);
$context = is_array($report['context'] ?? null) ? $report['context'] : [];
?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow,noarchive">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Диагностика портала</title>
    <style>
        :root{color-scheme:light;font-family:Inter,Arial,sans-serif;background:#f4f6fa;color:#101828}
        *{box-sizing:border-box}body{margin:0}.wrap{max-width:1180px;margin:0 auto;padding:28px}
        h1{margin:0 0 8px;font-size:32px}.sub{color:#667085;margin-bottom:22px}
        .actions{display:flex;gap:10px;flex-wrap:wrap;margin:18px 0 24px}
        .btn{display:inline-block;padding:11px 16px;border-radius:10px;text-decoration:none;background:#155eef;color:white;font-weight:700}
        .btn.alt{background:white;color:#101828;border:1px solid #d0d5dd}
        .grid{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:20px}
        .card{background:white;border:1px solid #e4e7ec;border-radius:14px;padding:18px;box-shadow:0 2px 8px rgba(16,24,40,.04)}
        .num{font-size:30px;font-weight:800}.label{color:#667085;font-size:13px;margin-top:4px}
        .error{border-left:5px solid #d92d20}.warning{border-left:5px solid #f79009}.ok{border-left:5px solid #12b76a}.info{border-left:5px solid #2e90fa}
        .finding{margin-bottom:10px}.finding strong{display:block;margin-bottom:5px}.finding pre{white-space:pre-wrap;word-break:break-word;font-size:12px;background:#f9fafb;padding:10px;border-radius:8px}
        .context{margin:0 0 16px;padding:12px 14px;border:1px solid #b2ddff;border-radius:12px;background:#eff8ff;color:#175cd3;font-size:13px}
        details{background:white;border:1px solid #e4e7ec;border-radius:14px;margin:12px 0;padding:14px 18px}
        summary{cursor:pointer;font-weight:800}pre{white-space:pre-wrap;word-break:break-word;font-size:12px;line-height:1.45}
        .footer{color:#667085;font-size:13px;margin-top:22px}
        @media(max-width:800px){.grid{grid-template-columns:repeat(2,1fr)}.wrap{padding:18px}}
    </style>
</head>
<body>
<div class="wrap">
    <h1>Единая диагностика портала</h1>
    <div class="sub">
        Версия <?= h((string) ($report['agent']['version'] ?? '')) ?> ·
        сформировано <?= h((string) ($report['generated_at'] ?? '')) ?> ·
        <?= h((string) ($report['duration_seconds'] ?? '')) ?> сек.
    </div>

    <?php if (($context['trigger'] ?? '') === 'system_update'): ?>
        <div class="context">
            Автоматический отчёт после установки версии
            <strong><?= h((string) ($context['version'] ?? '')) ?></strong>.
        </div>
    <?php endif; ?>

    <div class="actions">
        <a class="btn" href="<?= h($fullUrl) ?>">Запустить полную проверку</a>
        <a class="btn alt" href="<?= h($quickUrl) ?>">Быстрая проверка</a>
        <?php if ($requestedReport !== ''): ?>
            <a class="btn alt" href="<?= h($latestUrl) ?>">Последний отчёт</a>
        <?php endif; ?>
        <a class="btn alt" href="<?= h($downloadUrl) ?>">Скачать JSON</a>
        <a class="btn alt" href="<?= h($jsonUrl) ?>">Открыть JSON</a>
    </div>

    <div class="grid">
        <div class="card error"><div class="num"><?= (int) $counts['error'] ?></div><div class="label">Критические ошибки</div></div>
        <div class="card warning"><div class="num"><?= (int) $counts['warning'] ?></div><div class="label">Предупреждения</div></div>
        <div class="card ok"><div class="num"><?= (int) $counts['ok'] ?></div><div class="label">Успешные проверки</div></div>
        <div class="card info"><div class="num"><?= (int) $counts['info'] ?></div><div class="label">Информация</div></div>
    </div>

    <?php foreach ($findings as $finding): ?>
        <?php $severity = (string) ($finding['severity'] ?? 'info'); ?>
        <div class="card finding <?= h($severity) ?>">
            <strong><?= h((string) ($finding['message'] ?? $finding['code'] ?? 'Результат')) ?></strong>
            <span><?= h((string) ($finding['code'] ?? '')) ?></span>
            <?php if (count($finding) > 3): ?><pre><?= h(md_json($finding)) ?></pre><?php endif; ?>
        </div>
    <?php endforeach; ?>

    <details>
        <summary>Рекомендации</summary>
        <pre><?= h(md_json($report['recommendations'] ?? [])) ?></pre>
    </details>
    <details>
        <summary>PHP и окружение</summary>
        <pre><?= h(md_json($report['runtime'] ?? [])) ?></pre>
    </details>
    <details>
        <summary>Файлы, резервные копии и обновления</summary>
        <pre><?= h(md_json($report['filesystem'] ?? [])) ?></pre>
    </details>
    <details>
        <summary>База данных</summary>
        <pre><?= h(md_json($report['database'] ?? [])) ?></pre>
    </details>
    <details>
        <summary>Битрикс24 и API</summary>
        <pre><?= h(md_json($report['bitrix24'] ?? [])) ?></pre>
    </details>
    <details>
        <summary>Проверка кода</summary>
        <pre><?= h(md_json($report['code'] ?? [])) ?></pre>
    </details>
    <details>
        <summary>Журналы ошибок</summary>
        <pre><?= h(md_json($report['logs'] ?? [])) ?></pre>
    </details>

    <div class="footer">
        Центр работает только на чтение. Пароли, токены и URL вебхуков маскируются.
        Последний отчёт сохраняется вне публичной директории.
    </div>
</div>
</body>
</html>
