from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.02.12"
MARKER = "P1_GOALS_V180212"

root = Path.cwd()
component_root = root / "tools" / "p1-step2"
sources = {
    "app/Repositories/ConversionGoalRepository.php": component_root / "ConversionGoalRepository.php",
    "p1-goals-api.php": component_root / "p1-goals-api.php",
    "assets/p1-goals.js": component_root / "p1-goals.js",
    "assets/p1-goals.css": component_root / "p1-goals.css",
    "__schema_fragment__": component_root / "schema.sqlfrag",
}

payloads: dict[str, dict[str, str]] = {}
for destination, source in sources.items():
    content = source.read_text(encoding="utf-8")

    if destination == "app/Repositories/ConversionGoalRepository.php":
        old = """                name = VALUES(name),
                active = VALUES(active),
"""
        new = """                name = VALUES(name),
                classification = VALUES(classification),
                active = VALUES(active),
"""
        if content.count(old) != 1:
            raise SystemExit("P1.2 duplicate update marker not found exactly once")
        content = content.replace(old, new, 1)

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

const P12_VERSION = '{VERSION}';
const P12_MARKER = '{MARKER}';

function p12iOut(string $message = ''): void
{{
    fwrite(STDOUT, $message . PHP_EOL);
}}

function p12iRead(string $path): string
{{
    $content = file_get_contents($path);
    if (!is_string($content)) {{
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }}
    return $content;
}}

function p12iWrite(string $path, string $content): void
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

function p12iLint(string $path): void
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

function p12iTableExists(PDO $pdo, string $table): bool
{{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}}

function p12iDecode(): array
{{
    $encoded = <<<'PAYLOAD'
{payload_b64}
PAYLOAD;
    $json = base64_decode(preg_replace('/\\s+/', '', $encoded) ?? '', true);
    if (!is_string($json) || $json === '') {{
        throw new RuntimeException('Не удалось декодировать пакет P1.2.');
    }}
    if (!hash_equals('{payload_sha}', hash('sha256', $json))) {{
        throw new RuntimeException('Не совпала SHA-256 пакета P1.2.');
    }}
    $payloads = json_decode($json, true);
    if (!is_array($payloads)) {{
        throw new RuntimeException('Некорректный пакет P1.2.');
    }}
    $result = [];
    foreach ($payloads as $path => $payload) {{
        if (!is_array($payload)) {{
            throw new RuntimeException('Некорректный компонент P1.2: ' . $path);
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

$root = getcwd() ?: '';
$indexPath = $root . '/index.php';
$schemaPath = $root . '/sql/schema.sql';
$p1JsPath = $root . '/assets/p1-sales.js';

foreach ([
    $root . '/app/bootstrap.php',
    $indexPath,
    $schemaPath,
    $root . '/p1-api.php',
    $p1JsPath,
] as $required) {{
    if (!is_file($required)) {{
        throw new RuntimeException(
            'P1.2 требует установленный P1.1. Не найден файл: ' . $required
        );
    }}
}}

if (!str_contains(p12iRead($indexPath), 'P1_SALES_ASSETS_V180211')) {{
    throw new RuntimeException('P1.2 требует установленный интерфейс P1.1.');
}}

require_once $root . '/app/bootstrap.php';
$pdo = \\SeoAnalytics\\Core\\Database::pdo();
if (!p12iTableExists($pdo, 'sales_records')) {{
    throw new RuntimeException('P1.2 требует таблицы P1.1.');
}}

$tableState = [
    'conversion_goals' => p12iTableExists($pdo, 'conversion_goals'),
    'conversion_goal_changes' => p12iTableExists($pdo, 'conversion_goal_changes'),
];

$payloads = p12iDecode();
$schemaFragment = $payloads['__schema_fragment__'] ?? '';
unset($payloads['__schema_fragment__']);

$tracked = array_keys($payloads);
$tracked[] = 'index.php';
$tracked[] = 'sql/schema.sql';
$backupDirectory = $root . '/storage/backups/p1-goals-step2-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {{
    throw new RuntimeException('Не удалось создать резервную копию P1.2.');
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
        throw new RuntimeException('Не удалось создать каталог резервной копии: ' . $directory);
    }}
    if (!copy($source, $destination)) {{
        throw new RuntimeException('Не удалось сохранить резервную копию: ' . $relative);
    }}
}}

try {{
    foreach ($payloads as $relative => $content) {{
        p12iWrite($root . '/' . $relative, $content);
    }}

    $index = p12iRead($indexPath);
    if (!str_contains($index, 'P1_GOALS_ASSETS_V180212')) {{
        $headCount = 0;
        $index = str_replace(
            '</head>',
            "    <!-- P1_GOALS_ASSETS_V180212 -->\\n"
            . "    <link rel=\\"stylesheet\\" href=\\"/assets/p1-goals.css?v=180212\\">\\n"
            . '</head>',
            $index,
            $headCount
        );
        if ($headCount !== 1) {{
            throw new RuntimeException('Не удалось подключить стили P1.2.');
        }}

        $bodyCount = 0;
        $index = str_replace(
            '</body>',
            "    <script defer src=\\"/assets/p1-goals.js?v=180212\\"></script>\\n"
            . '</body>',
            $index,
            $bodyCount
        );
        if ($bodyCount !== 1) {{
            throw new RuntimeException('Не удалось подключить JavaScript P1.2.');
        }}
        p12iWrite($indexPath, $index);
    }}

    $schema = p12iRead($schemaPath);
    if (!str_contains($schema, 'P1_GOALS_SCHEMA_V180212')) {{
        $schema = rtrim($schema) . PHP_EOL . PHP_EOL . trim($schemaFragment) . PHP_EOL;
        p12iWrite($schemaPath, $schema);
    }}

    $sql = preg_replace('/^\\s*--.*$/m', '', $schemaFragment) ?? $schemaFragment;
    $statements = preg_split('/;\\s*(?=CREATE TABLE)/i', trim($sql)) ?: [];
    foreach ($statements as $statement) {{
        $statement = trim($statement);
        if ($statement !== '') {{
            $pdo->exec(rtrim($statement, "; \\t\\r\\n"));
        }}
    }}

    foreach ([
        $root . '/app/Repositories/ConversionGoalRepository.php',
        $root . '/p1-goals-api.php',
        $root . '/index.php',
    ] as $phpPath) {{
        p12iLint($phpPath);
    }}

    if (!p12iTableExists($pdo, 'conversion_goals') || !p12iTableExists($pdo, 'conversion_goal_changes')) {{
        throw new RuntimeException('Таблицы P1.2 не созданы.');
    }}

    p12iOut('P1.2 — классификация целей установлена.');
    p12iOut('- добавлены классы: лид, вспомогательная и микроконверсия;');
    p12iOut('- не классифицированные цели исключены из будущих экономических расчётов;');
    p12iOut('- добавлена синхронизация ID целей из настроек проекта;');
    p12iOut('- каждое изменение классификации сохраняется в журнале;');
    p12iOut('- интерфейс встроен вкладкой в раздел «Продажи и экономика»;');
    p12iOut('- резервная копия: ' . $backupDirectory . ';');
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
        if (!$tableState['conversion_goal_changes'] && p12iTableExists($pdo, 'conversion_goal_changes')) {{
            $pdo->exec('DROP TABLE conversion_goal_changes');
        }}
        if (!$tableState['conversion_goals'] && p12iTableExists($pdo, 'conversion_goals')) {{
            $pdo->exec('DROP TABLE conversion_goals');
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
