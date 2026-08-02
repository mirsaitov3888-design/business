from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.02.14"
MARKER = "LK_CONTEXT_V180214"

root = Path.cwd()
component_root = root / "tools" / "lk-context-v14"


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f"{label}: expected one match, found {count}")
    return text.replace(old, new, 1)


service = (component_root / "PortalContextService.php").read_text(encoding="utf-8")
service = replace_once(
    service,
    ". ' ORDER BY COALESCE(c.name, \"\") ASC, p.id ASC';",
    ". ($hasLinks\n"
    "                ? ' ORDER BY COALESCE(c.name, \\\"\\\") ASC, p.id ASC'\n"
    "                : ' ORDER BY p.id ASC');",
    "context project ordering",
)

sales_api = (root / "tools" / "p1-step1" / "p1-api.php").read_text(encoding="utf-8")
sales_api = replace_once(
    sales_api,
    "use SeoAnalytics\\Repositories\\ProjectRepository;\n",
    "use SeoAnalytics\\Services\\PortalContextDeniedException;\n"
    "use SeoAnalytics\\Services\\PortalContextService;\n",
    "sales API context imports",
)
sales_api = replace_once(
    sales_api,
    "    $access = new PortalAccessService();\n"
    "    $user = $access->requireRoles(['administrator', 'moderator', 'manager']);\n"
    "    $project = (new ProjectRepository())->firstActive();\n\n"
    "    if (!$project) {\n"
    "        p1Json(['error' => 'Сначала настройте активный проект.'], 422);\n"
    "    }\n\n"
    "    $projectId = (int) ($project['id'] ?? 0);\n"
    "    if ($projectId <= 0) {\n"
    "        p1Json(['error' => 'Не удалось определить активный проект.'], 422);\n"
    "    }\n",
    "    $access = new PortalAccessService();\n"
    "    $user = $access->requireRoles([\n"
    "        'administrator', 'moderator', 'manager', 'client',\n"
    "    ]);\n"
    "    $portalContext = (new PortalContextService($access))->context(\n"
    "        null,\n"
    "        null,\n"
    "        true\n"
    "    );\n"
    "    $project = $portalContext['selected_project'] ?? null;\n"
    "    $projectId = (int) ($portalContext['selected_project_id'] ?? 0);\n\n"
    "    if (!$project || $projectId <= 0) {\n"
    "        p1Json(['error' => 'Выберите доступный проект в верхнем фильтре.'], 422);\n"
    "    }\n",
    "sales API active project block",
)
sales_api = replace_once(
    sales_api,
    "            'role' => (string) ($user['role'] ?? ''),\n"
    "            'channels' => SalesRepository::CHANNELS,",
    "            'role' => (string) ($user['role'] ?? ''),\n"
    "            'portal_context' => $portalContext,\n"
    "            'sites' => $portalContext['sites'] ?? [],\n"
    "            'channels' => SalesRepository::CHANNELS,",
    "sales API context response",
)
sales_api = replace_once(
    sales_api,
    "    p1SameOrigin();\n\n"
    "    if ($action === 'save') {",
    "    p1SameOrigin();\n\n"
    "    if (($user['role'] ?? '') === 'client') {\n"
    "        p1Json(['error' => 'Клиентский доступ работает только на просмотр.'], 403);\n"
    "    }\n\n"
    "    if ($action === 'save') {",
    "sales API client write guard",
)
sales_api = replace_once(
    sales_api,
    "} catch (RuntimeException $exception) {\n"
    "    p1Json(['error' => $exception->getMessage()], 422);",
    "} catch (PortalContextDeniedException $exception) {\n"
    "    p1Json(['error' => $exception->getMessage()], 403);\n"
    "} catch (RuntimeException $exception) {\n"
    "    p1Json(['error' => $exception->getMessage()], 422);",
    "sales API denied catch",
)

goals_api = (root / "tools" / "p1-step2" / "p1-goals-api.php").read_text(encoding="utf-8")
goals_api = replace_once(
    goals_api,
    "use SeoAnalytics\\Repositories\\ProjectRepository;\n",
    "use SeoAnalytics\\Services\\PortalContextDeniedException;\n"
    "use SeoAnalytics\\Services\\PortalContextService;\n",
    "goals API context imports",
)
goals_api = replace_once(
    goals_api,
    "    $access = new PortalAccessService();\n"
    "    $user = $access->requireRoles(['administrator', 'moderator', 'manager']);\n"
    "    $project = (new ProjectRepository())->firstActive();\n\n"
    "    if (!$project) {\n"
    "        p12Json(['error' => 'Сначала настройте активный проект.'], 422);\n"
    "    }\n\n"
    "    $projectId = (int) ($project['id'] ?? 0);\n"
    "    if ($projectId <= 0) {\n"
    "        p12Json(['error' => 'Не удалось определить активный проект.'], 422);\n"
    "    }\n",
    "    $access = new PortalAccessService();\n"
    "    $user = $access->requireRoles([\n"
    "        'administrator', 'moderator', 'manager', 'client',\n"
    "    ]);\n"
    "    $portalContext = (new PortalContextService($access))->context(\n"
    "        null,\n"
    "        null,\n"
    "        true\n"
    "    );\n"
    "    $project = $portalContext['selected_project'] ?? null;\n"
    "    $projectId = (int) ($portalContext['selected_project_id'] ?? 0);\n\n"
    "    if (!$project || $projectId <= 0) {\n"
    "        p12Json(['error' => 'Выберите доступный проект в верхнем фильтре.'], 422);\n"
    "    }\n",
    "goals API active project block",
)
goals_api = replace_once(
    goals_api,
    "            'role' => (string) ($user['role'] ?? ''),\n"
    "            'classifications' => ConversionGoalRepository::CLASSIFICATIONS,",
    "            'role' => (string) ($user['role'] ?? ''),\n"
    "            'portal_context' => $portalContext,\n"
    "            'sites' => $portalContext['sites'] ?? [],\n"
    "            'classifications' => ConversionGoalRepository::CLASSIFICATIONS,",
    "goals API context response",
)
goals_api = replace_once(
    goals_api,
    "    p12SameOrigin();\n\n"
    "    if ($action === 'save') {",
    "    p12SameOrigin();\n\n"
    "    if (($user['role'] ?? '') === 'client') {\n"
    "        p12Json(['error' => 'Клиентский доступ работает только на просмотр.'], 403);\n"
    "    }\n\n"
    "    if ($action === 'save') {",
    "goals API client write guard",
)
goals_api = replace_once(
    goals_api,
    "} catch (RuntimeException $exception) {\n"
    "    p12Json(['error' => $exception->getMessage()], 422);",
    "} catch (PortalContextDeniedException $exception) {\n"
    "    p12Json(['error' => $exception->getMessage()], 403);\n"
    "} catch (RuntimeException $exception) {\n"
    "    p12Json(['error' => $exception->getMessage()], 422);",
    "goals API denied catch",
)

sources: dict[str, str] = {
    "app/Services/PortalContextService.php": service,
    "portal-context-api.php": (component_root / "portal-context-api.php").read_text(encoding="utf-8"),
    "p1-api.php": sales_api,
    "p1-goals-api.php": goals_api,
    "__context_js__": (component_root / "portal-context.js").read_text(encoding="utf-8"),
    "__context_css__": (component_root / "portal-context.css").read_text(encoding="utf-8"),
    "__schema_fragment__": (component_root / "schema.sqlfrag").read_text(encoding="utf-8"),
}

payloads: dict[str, dict[str, str]] = {}
for destination, content in sources.items():
    raw = content.encode("utf-8")
    payloads[destination] = {
        "sha256": hashlib.sha256(raw).hexdigest(),
        "content": base64.b64encode(raw).decode("ascii"),
    }

payload_json = json.dumps(payloads, ensure_ascii=False, separators=(",", ":"))
payload_b64 = base64.b64encode(payload_json.encode("utf-8")).decode("ascii")
payload_sha = hashlib.sha256(payload_json.encode("utf-8")).hexdigest()

installer = f'''<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {{
    http_response_code(403);
    exit("Запустите через PHP CLI.\\n");
}}

const LK_CONTEXT_VERSION = '{VERSION}';
const LK_CONTEXT_MARKER = '{MARKER}';

function lk14out(string $message = ''): void
{{
    fwrite(STDOUT, $message . PHP_EOL);
}}

function lk14read(string $path): string
{{
    $content = file_get_contents($path);
    if (!is_string($content)) {{
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }}
    return $content;
}}

function lk14write(string $path, string $content): void
{{
    $directory = dirname($path);
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {{
        throw new RuntimeException('Не удалось создать каталог: ' . $directory);
    }}
    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
    if (file_put_contents($temporary, $content, LOCK_EX) === false) {{
        throw new RuntimeException('Не удалось записать временный файл: ' . $temporary);
    }}
    if (!rename($temporary, $path)) {{
        @unlink($temporary);
        throw new RuntimeException('Не удалось заменить файл: ' . $path);
    }}
}}

function lk14lint(string $path): void
{{
    if (!function_exists('exec')) {{
        return;
    }}
    $output = [];
    $code = 0;
    exec(
        escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1',
        $output,
        $code
    );
    if ($code !== 0) {{
        throw new RuntimeException(
            'Ошибка PHP-синтаксиса в ' . $path . ':\\n' . implode("\\n", $output)
        );
    }}
}}

function lk14tableExists(PDO $pdo, string $table): bool
{{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}}

function lk14columnExists(PDO $pdo, string $table, string $column): bool
{{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = DATABASE() '
        . 'AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);
    return (int) $stmt->fetchColumn() > 0;
}}

function lk14decode(): array
{{
    $encoded = <<<'PAYLOAD'
{payload_b64}
PAYLOAD;
    $json = base64_decode(preg_replace('/\\s+/', '', $encoded) ?? '', true);
    if (!is_string($json) || $json === '') {{
        throw new RuntimeException('Не удалось декодировать пакет ЛК.');
    }}
    if (!hash_equals('{payload_sha}', hash('sha256', $json))) {{
        throw new RuntimeException('Не совпала SHA-256 пакета ЛК.');
    }}
    $payloads = json_decode($json, true);
    if (!is_array($payloads)) {{
        throw new RuntimeException('Некорректный пакет ЛК.');
    }}
    $result = [];
    foreach ($payloads as $path => $payload) {{
        if (!is_array($payload)) {{
            throw new RuntimeException('Некорректный компонент ЛК: ' . $path);
        }}
        $content = base64_decode((string) ($payload['content'] ?? ''), true);
        if (!is_string($content)) {{
            throw new RuntimeException('Не удалось декодировать компонент: ' . $path);
        }}
        if (!hash_equals((string) ($payload['sha256'] ?? ''), hash('sha256', $content))) {{
            throw new RuntimeException('Не совпала SHA-256 компонента: ' . $path);
        }}
        $result[(string) $path] = $content;
    }}
    return $result;
}}

function lk14normalizeSite(string $url, string $fallbackName): ?array
{{
    $url = trim($url);
    if ($url === '') {{
        return null;
    }}
    if (!preg_match('#^https?://#i', $url)) {{
        $url = 'https://' . ltrim($url, '/');
    }}
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    if ($host === '') {{
        return null;
    }}
    $url = rtrim($url, '/');
    $name = trim($fallbackName);
    if ($name === '') {{
        $name = $host;
    }}
    return [
        'url' => mb_substr($url, 0, 1000),
        'host' => mb_substr($host, 0, 255),
        'name' => mb_substr($name, 0, 190),
    ];
}}

function lk14insertSite(
    PDO $pdo,
    int $projectId,
    string $url,
    string $name,
    string $sourceType,
    ?int $sourceId
): bool {{
    if ($projectId <= 0) {{
        return false;
    }}
    $site = lk14normalizeSite($url, $name);
    if ($site === null) {{
        return false;
    }}
    $stmt = $pdo->prepare(
        'INSERT INTO project_sites
         (project_id, name, url, host, status, source_type, source_id,
          created_at, updated_at)
         VALUES
         (:project_id, :name, :url, :host, "active", :source_type,
          :source_id, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            name = CASE WHEN name = "" THEN VALUES(name) ELSE name END,
            host = VALUES(host),
            updated_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        'project_id' => $projectId,
        'name' => $site['name'],
        'url' => $site['url'],
        'host' => $site['host'],
        'source_type' => $sourceType,
        'source_id' => $sourceId,
    ]);
    return $stmt->rowCount() > 0;
}}

function lk14migrateSites(PDO $pdo): array
{{
    $result = [
        'projects' => 0,
        'site_monitors' => 0,
        'monitored_sites' => 0,
    ];

    if (lk14columnExists($pdo, 'projects', 'site_url')) {{
        $nameColumn = lk14columnExists($pdo, 'projects', 'name')
            ? 'name'
            : (lk14columnExists($pdo, 'projects', 'title') ? 'title' : 'site_url');
        $rows = $pdo->query(
            'SELECT id, site_url, ' . $nameColumn . ' AS site_name '
            . 'FROM projects WHERE site_url IS NOT NULL AND site_url <> ""'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {{
            if (lk14insertSite(
                $pdo,
                (int) $row['id'],
                (string) $row['site_url'],
                (string) ($row['site_name'] ?? ''),
                'project',
                (int) $row['id']
            )) {{
                $result['projects']++;
            }}
        }}
    }}

    if (
        lk14tableExists($pdo, 'site_monitors')
        && lk14columnExists($pdo, 'site_monitors', 'project_id')
        && lk14columnExists($pdo, 'site_monitors', 'url')
    ) {{
        $rows = $pdo->query(
            'SELECT id, project_id, url FROM site_monitors '
            . 'WHERE url IS NOT NULL AND url <> ""'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {{
            if (lk14insertSite(
                $pdo,
                (int) $row['project_id'],
                (string) $row['url'],
                '',
                'site_monitor',
                (int) $row['id']
            )) {{
                $result['site_monitors']++;
            }}
        }}
    }}

    if (
        lk14tableExists($pdo, 'monitored_sites')
        && lk14columnExists($pdo, 'monitored_sites', 'project_id')
        && lk14columnExists($pdo, 'monitored_sites', 'base_url')
    ) {{
        $hasName = lk14columnExists($pdo, 'monitored_sites', 'name');
        $rows = $pdo->query(
            'SELECT id, project_id, base_url, '
            . ($hasName ? 'name' : 'base_url')
            . ' AS site_name FROM monitored_sites '
            . 'WHERE base_url IS NOT NULL AND base_url <> ""'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {{
            if (lk14insertSite(
                $pdo,
                (int) $row['project_id'],
                (string) $row['base_url'],
                (string) ($row['site_name'] ?? ''),
                'monitored_site',
                (int) $row['id']
            )) {{
                $result['monitored_sites']++;
            }}
        }}
    }}

    return $result;
}}

$root = getcwd() ?: '';
$required = [
    $root . '/app/bootstrap.php',
    $root . '/app/Services/PortalAccessService.php',
    $root . '/assets/app.js',
    $root . '/assets/app.css',
    $root . '/p1-api.php',
    $root . '/p1-goals-api.php',
    $root . '/sql/schema.sql',
];
foreach ($required as $path) {{
    if (!is_file($path)) {{
        throw new RuntimeException('Не найден обязательный файл: ' . $path);
    }}
}}

$appJsPath = $root . '/assets/app.js';
$appCssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
if (!str_contains(lk14read($appJsPath), 'P1_SALES_BUNDLED_V180212')) {{
    throw new RuntimeException('Версия 2026.08.02.14 требует установленный P1.1 hotfix.');
}}
if (!str_contains(lk14read($appJsPath), 'P1_GOALS_BUNDLED_V180213')) {{
    throw new RuntimeException('Версия 2026.08.02.14 требует установленный P1.2.');
}}

require_once $root . '/app/bootstrap.php';
$pdo = \\SeoAnalytics\\Core\\Database::pdo();
$tableState = [
    'project_sites' => lk14tableExists($pdo, 'project_sites'),
    'user_portal_context' => lk14tableExists($pdo, 'user_portal_context'),
];

$payloads = lk14decode();
$contextJs = $payloads['__context_js__'] ?? '';
$contextCss = $payloads['__context_css__'] ?? '';
$schemaFragment = $payloads['__schema_fragment__'] ?? '';
unset(
    $payloads['__context_js__'],
    $payloads['__context_css__'],
    $payloads['__schema_fragment__']
);

$tracked = array_keys($payloads);
$tracked[] = 'assets/app.js';
$tracked[] = 'assets/app.css';
$tracked[] = 'sql/schema.sql';
$tracked = array_values(array_unique($tracked));

$backupDirectory = $root . '/storage/backups/lk-context-v14-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {{
    throw new RuntimeException('Не удалось создать резервную копию ЛК.');
}}

$manifest = [];
foreach ($tracked as $relative) {{
    $source = $root . '/' . $relative;
    $manifest[$relative] = is_file($source);
    if (!is_file($source)) {{
        continue;
    }}
    $destination = $backupDirectory . '/' . $relative;
    $directory = dirname($destination);
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {{
        throw new RuntimeException('Не удалось создать каталог резервной копии.');
    }}
    if (!copy($source, $destination)) {{
        throw new RuntimeException('Не удалось сохранить резервную копию: ' . $relative);
    }}
}}

try {{
    foreach ($payloads as $relative => $content) {{
        lk14write($root . '/' . $relative, $content);
    }}

    $appJs = lk14read($appJsPath);
    if (!str_contains($appJs, 'LK_CONTEXT_BUNDLED_V180214')) {{
        $appJs = rtrim($appJs) . PHP_EOL . PHP_EOL
            . '/* LK_CONTEXT_BUNDLED_V180214 */' . PHP_EOL
            . trim($contextJs) . PHP_EOL;
        lk14write($appJsPath, $appJs);
    }}

    $appCss = lk14read($appCssPath);
    if (!str_contains($appCss, 'LK_CONTEXT_BUNDLED_V180214')) {{
        $appCss = rtrim($appCss) . PHP_EOL . PHP_EOL
            . '/* LK_CONTEXT_BUNDLED_V180214 */' . PHP_EOL
            . trim($contextCss) . PHP_EOL;
        lk14write($appCssPath, $appCss);
    }}

    $schema = lk14read($schemaPath);
    if (!str_contains($schema, 'LK_CONTEXT_SCHEMA_V180214')) {{
        $schema = rtrim($schema) . PHP_EOL . PHP_EOL
            . trim($schemaFragment) . PHP_EOL;
        lk14write($schemaPath, $schema);
    }}

    $sql = preg_replace('/^\\s*--.*$/m', '', $schemaFragment) ?? $schemaFragment;
    $statements = preg_split('/;\\s*(?=CREATE TABLE)/i', trim($sql)) ?: [];
    foreach ($statements as $statement) {{
        $statement = trim($statement);
        if ($statement !== '') {{
            $pdo->exec(rtrim($statement, "; \\t\\r\\n"));
        }}
    }}

    $migration = lk14migrateSites($pdo);

    foreach ([
        $root . '/app/Services/PortalContextService.php',
        $root . '/portal-context-api.php',
        $root . '/p1-api.php',
        $root . '/p1-goals-api.php',
    ] as $phpPath) {{
        lk14lint($phpPath);
    }}

    if (function_exists('exec')) {{
        $node = [];
        $nodeCode = 0;
        exec('node --check ' . escapeshellarg($appJsPath) . ' 2>&1', $node, $nodeCode);
        if ($nodeCode !== 0) {{
            throw new RuntimeException(
                'Ошибка JavaScript после установки: ' . implode("\\n", $node)
            );
        }}
    }}

    if (!lk14tableExists($pdo, 'project_sites')) {{
        throw new RuntimeException('Таблица project_sites не создана.');
    }}
    if (!lk14tableExists($pdo, 'user_portal_context')) {{
        throw new RuntimeException('Таблица user_portal_context не создана.');
    }}
    if (substr_count(lk14read($appJsPath), 'LK_CONTEXT_BUNDLED_V180214') !== 1) {{
        throw new RuntimeException('Глобальный JavaScript ЛК подключён некорректно.');
    }}
    if (substr_count(lk14read($appCssPath), 'LK_CONTEXT_BUNDLED_V180214') !== 1) {{
        throw new RuntimeException('Глобальные стили ЛК подключены некорректно.');
    }}

    $siteCount = (int) $pdo->query('SELECT COUNT(*) FROM project_sites')->fetchColumn();
    lk14out('ЛК: единый контекст клиента, проекта и сайтов установлен.');
    lk14out('- администратор и менеджер получают фильтр клиента и проекта;');
    lk14out('- клиент работает внутри своего ЛК и выбирает только доступный проект;');
    lk14out('- сделки и цели переключены с firstActive() на выбранный проект;');
    lk14out('- клиентский доступ к сделкам и целям работает только на просмотр;');
    lk14out('- создан единый реестр сайтов проекта: ' . $siteCount . ';');
    lk14out('- миграция из проектов: ' . $migration['projects'] . ';');
    lk14out('- миграция из site_monitors: ' . $migration['site_monitors'] . ';');
    lk14out('- миграция из monitored_sites: ' . $migration['monitored_sites'] . ';');
    lk14out('- резервная копия: ' . $backupDirectory . ';');
}} catch (Throwable $exception) {{
    foreach ($manifest as $relative => $existed) {{
        $target = $root . '/' . $relative;
        $backup = $backupDirectory . '/' . $relative;
        if ($existed && is_file($backup)) {{
            @copy($backup, $target);
        }} elseif (!$existed && is_file($target)) {{
            @unlink($target);
        }}
    }}

    try {{
        if (!$tableState['user_portal_context'] && lk14tableExists($pdo, 'user_portal_context')) {{
            $pdo->exec('DROP TABLE user_portal_context');
        }}
        if (!$tableState['project_sites'] && lk14tableExists($pdo, 'project_sites')) {{
            $pdo->exec('DROP TABLE project_sites');
        }}
    }} catch (Throwable) {{
    }}

    fwrite(STDERR, 'ОШИБКА: ' . $exception->getMessage() . PHP_EOL);
    fwrite(STDERR, 'Файлы восстановлены из резервной копии.' . PHP_EOL);
    exit(1);
}}
'''

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(output.read_bytes()).hexdigest())
