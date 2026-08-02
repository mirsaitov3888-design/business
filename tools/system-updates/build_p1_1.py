from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.02.11"
MARKER = "P1_SALES_V180211"

root = Path.cwd()
component_root = root / "tools" / "p1-step1"

sources = {
    "app/Repositories/SalesRepository.php": component_root / "SalesRepository.php",
    "app/Services/SalesImportService.php": component_root / "SalesImportService.php",
    "app/Services/ZipArchivePolyfill.php": component_root / "ZipArchivePolyfill.php",
    "p1-api.php": component_root / "p1-api.php",
    "assets/p1-sales.js": component_root / "p1-sales.js",
    "assets/p1-sales.css": component_root / "p1-sales.css",
    "__schema_fragment__": component_root / "schema.sqlfrag",
}

payloads: dict[str, dict[str, str]] = {}
for destination, source in sources.items():
    content = source.read_text(encoding="utf-8")

    if destination == "assets/p1-sales.js":
        old = "const response = await fetch(`/p1-api.php?action=${encodeURIComponent(action)}`, {"
        new = """const actionParts = String(action).split('&');
        const actionName = actionParts.shift() || 'context';
        const extraQuery = actionParts.length ? `&${actionParts.join('&')}` : '';
        const response = await fetch(`/p1-api.php?action=${encodeURIComponent(actionName)}${extraQuery}`, {"""
        if content.count(old) != 1:
            raise SystemExit("P1 request URL marker not found exactly once")
        content = content.replace(old, new, 1)

    if destination == "app/Services/SalesImportService.php":
        needle = "    private function readXlsx(string $path): array\n    {\n"
        guard = """    private function readXlsx(string $path): array
    {
        if (!function_exists('simplexml_load_string')) {
            throw new RuntimeException('Для XLSX на сервере требуется расширение SimpleXML.');
        }
"""
        if content.count(needle) != 1:
            raise SystemExit("P1 XLSX method marker not found exactly once")
        content = content.replace(needle, guard, 1)

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

const P11_VERSION = '{VERSION}';
const P11_MARKER = '{MARKER}';

function p11out(string $message = ''): void
{{
    fwrite(STDOUT, $message . PHP_EOL);
}}

function p11read(string $path): string
{{
    $content = file_get_contents($path);
    if (!is_string($content)) {{
        throw new RuntimeException('Не удалось прочитать файл: ' . $path);
    }}
    return $content;
}}

function p11write(string $path, string $content): void
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

function p11lint(string $path): void
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

function p11tableExists(PDO $pdo, string $table): bool
{{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
    );
    $stmt->execute(['table_name' => $table]);
    return (int) $stmt->fetchColumn() > 0;
}}

function p11decodePayloads(): array
{{
    $encoded = <<<'PAYLOAD'
{payload_b64}
PAYLOAD;
    $json = base64_decode(preg_replace('/\\s+/', '', $encoded) ?? '', true);
    if (!is_string($json) || $json === '') {{
        throw new RuntimeException('Не удалось декодировать пакет P1.1.');
    }}
    if (!hash_equals('{payload_sha}', hash('sha256', $json))) {{
        throw new RuntimeException('Не совпала SHA-256 пакета P1.1.');
    }}
    $payloads = json_decode($json, true);
    if (!is_array($payloads)) {{
        throw new RuntimeException('Некорректный пакет P1.1.');
    }}
    $result = [];
    foreach ($payloads as $path => $payload) {{
        if (!is_array($payload)) {{
            throw new RuntimeException('Некорректный компонент: ' . $path);
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
$required = [
    $root . '/app/bootstrap.php',
    $root . '/index.php',
    $root . '/sql/schema.sql',
];
foreach ($required as $path) {{
    if (!is_file($path)) {{
        throw new RuntimeException('Не найден файл проекта: ' . $path);
    }}
}}

require_once $root . '/app/bootstrap.php';
$pdo = \\SeoAnalytics\\Core\\Database::pdo();
$tablesExisted = [
    'sales_records' => p11tableExists($pdo, 'sales_records'),
    'sales_import_batches' => p11tableExists($pdo, 'sales_import_batches'),
];

$payloads = p11decodePayloads();
$schemaFragment = $payloads['__schema_fragment__'] ?? '';
unset($payloads['__schema_fragment__']);

$indexPath = $root . '/index.php';
$schemaPath = $root . '/sql/schema.sql';
$tracked = array_keys($payloads);
$tracked[] = 'index.php';
$tracked[] = 'sql/schema.sql';

$backupDirectory = $root . '/storage/backups/p1-sales-step1-' . date('Ymd-His');
if (!mkdir($backupDirectory, 0700, true) && !is_dir($backupDirectory)) {{
    throw new RuntimeException('Не удалось создать резервную копию P1.1.');
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
        p11write($root . '/' . $relative, $content);
    }}

    $index = p11read($indexPath);
    if (!str_contains($index, 'P1_SALES_ASSETS_V180211')) {{
        $headCount = 0;
        $index = str_replace(
            '</head>',
            "    <!-- P1_SALES_ASSETS_V180211 -->\\n"
            . "    <link rel=\\"stylesheet\\" href=\\"/assets/p1-sales.css?v=180211\\">\\n"
            . '</head>',
            $index,
            $headCount
        );
        if ($headCount !== 1) {{
            throw new RuntimeException('Не удалось подключить стили P1.1.');
        }}

        $bodyCount = 0;
        $index = str_replace(
            '</body>',
            "    <script defer src=\\"/assets/p1-sales.js?v=180211\\"></script>\\n"
            . '</body>',
            $index,
            $bodyCount
        );
        if ($bodyCount !== 1) {{
            throw new RuntimeException('Не удалось подключить JavaScript P1.1.');
        }}
        p11write($indexPath, $index);
    }}

    $schema = p11read($schemaPath);
    if (!str_contains($schema, 'P1_SALES_SCHEMA_V180211')) {{
        $schema = rtrim($schema) . PHP_EOL . PHP_EOL . trim($schemaFragment) . PHP_EOL;
        p11write($schemaPath, $schema);
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
        $root . '/app/Repositories/SalesRepository.php',
        $root . '/app/Services/SalesImportService.php',
        $root . '/app/Services/ZipArchivePolyfill.php',
        $root . '/p1-api.php',
        $root . '/index.php',
    ] as $phpPath) {{
        p11lint($phpPath);
    }}

    if (!p11tableExists($pdo, 'sales_records') || !p11tableExists($pdo, 'sales_import_batches')) {{
        throw new RuntimeException('Таблицы P1.1 не созданы.');
    }}

    p11out('P1.1 — раздел продаж и экономики установлен.');
    p11out('- добавлен ручной ввод продаж и сделок;');
    p11out('- добавлен импорт CSV и XLSX до 5000 строк;');
    p11out('- одинаковые записи автоматически пропускаются по fingerprint;');
    p11out('- добавлены сводка, журнал, редактирование и удаление записей;');
    p11out('- источник данных подготовлен для CPL, CPQL, стоимости договора, ROAS и ROMI;');
    p11out('- резервная копия: ' . $backupDirectory . ';');
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
        if (!$tablesExisted['sales_records'] && p11tableExists($pdo, 'sales_records')) {{
            $pdo->exec('DROP TABLE sales_records');
        }}
        if (!$tablesExisted['sales_import_batches'] && p11tableExists($pdo, 'sales_import_batches')) {{
            $pdo->exec('DROP TABLE sales_import_batches');
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
