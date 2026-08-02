from __future__ import annotations

import hashlib
from pathlib import Path

root = Path.cwd()
source_path = root / "updates" / "installers" / "2026.08.02.11.php"
target_path = root / "updates" / "installers" / "2026.08.02.12.php"

text = source_path.read_text(encoding="utf-8")

text = text.replace(
    "const P11_VERSION = '2026.08.02.11';",
    "const P11_VERSION = '2026.08.02.12';",
    1,
)

old_paths = """$indexPath = $root . '/index.php';
$schemaPath = $root . '/sql/schema.sql';
$tracked = array_keys($payloads);
$tracked[] = 'index.php';
$tracked[] = 'sql/schema.sql';
"""
new_paths = """$indexPath = $root . '/index.php';
$schemaPath = $root . '/sql/schema.sql';
$appJsPath = $root . '/assets/app.js';
$appCssPath = $root . '/assets/app.css';
foreach ([$appJsPath, $appCssPath] as $assetPath) {
    if (!is_file($assetPath)) {
        throw new RuntimeException('Не найден основной ресурс портала: ' . $assetPath);
    }
}
$tracked = array_keys($payloads);
$tracked[] = 'assets/app.js';
$tracked[] = 'assets/app.css';
$tracked[] = 'sql/schema.sql';
"""
if text.count(old_paths) != 1:
    raise SystemExit("P1.1 tracked paths block not found exactly once")
text = text.replace(old_paths, new_paths, 1)

old_injection = """    $index = p11read($indexPath);
    if (!str_contains($index, 'P1_SALES_ASSETS_V180211')) {
        $headCount = 0;
        $index = str_replace(
            '</head>',
            "    <!-- P1_SALES_ASSETS_V180211 -->\\n"
            . "    <link rel=\\\"stylesheet\\\" href=\\\"/assets/p1-sales.css?v=180211\\\">\\n"
            . '</head>',
            $index,
            $headCount
        );
        if ($headCount !== 1) {
            throw new RuntimeException('Не удалось подключить стили P1.1.');
        }

        $bodyCount = 0;
        $index = str_replace(
            '</body>',
            "    <script defer src=\\\"/assets/p1-sales.js?v=180211\\\"></script>\\n"
            . '</body>',
            $index,
            $bodyCount
        );
        if ($bodyCount !== 1) {
            throw new RuntimeException('Не удалось подключить JavaScript P1.1.');
        }
        p11write($indexPath, $index);
    }
"""
new_injection = """    $p1Css = $payloads['assets/p1-sales.css'] ?? '';
    $p1Js = $payloads['assets/p1-sales.js'] ?? '';
    if ($p1Css === '' || $p1Js === '') {
        throw new RuntimeException('В пакете P1.1 отсутствуют ресурсы интерфейса.');
    }

    $appCss = p11read($appCssPath);
    if (!str_contains($appCss, 'P1_SALES_BUNDLED_V180212')) {
        $appCss = rtrim($appCss)
            . PHP_EOL . PHP_EOL
            . '/* P1_SALES_BUNDLED_V180212 */'
            . PHP_EOL
            . trim($p1Css)
            . PHP_EOL;
        p11write($appCssPath, $appCss);
    }

    $appJs = p11read($appJsPath);
    if (!str_contains($appJs, 'P1_SALES_BUNDLED_V180212')) {
        $appJs = rtrim($appJs)
            . PHP_EOL . PHP_EOL
            . '/* P1_SALES_BUNDLED_V180212 */'
            . PHP_EOL
            . trim($p1Js)
            . PHP_EOL;
        p11write($appJsPath, $appJs);
    }
"""
if text.count(old_injection) != 1:
    raise SystemExit("P1.1 HTML asset injection block not found exactly once")
text = text.replace(old_injection, new_injection, 1)

old_lint = """        $root . '/p1-api.php',
        $root . '/index.php',
"""
new_lint = """        $root . '/p1-api.php',
        $root . '/index.php',
"""
# Keep index lint for regression detection even though it is not modified.
if text.count(old_lint) != 1:
    raise SystemExit("P1.1 lint block not found exactly once")
text = text.replace(old_lint, new_lint, 1)

text = text.replace(
    "p11out('P1.1 — раздел продаж и экономики установлен.');",
    "p11out('P1.1 — раздел продаж и экономики установлен (hotfix ресурсов).');",
    1,
)
text = text.replace(
    "p11out('- добавлен ручной ввод продаж и сделок;');",
    "p11out('- интерфейс подключён через основные app.css и app.js без изменения index.php;');\n    p11out('- добавлен ручной ввод продаж и сделок;');",
    1,
)

# The hotfix must not depend on the old HTML marker.
if "Не удалось подключить стили P1.1" in text or "</head>" in text or "</body>" in text:
    raise SystemExit("Brittle HTML asset injection remains in hotfix")

# Verify the new rollback coverage and markers are present.
for marker in [
    "assets/app.js",
    "assets/app.css",
    "P1_SALES_BUNDLED_V180212",
    "2026.08.02.12",
]:
    if marker not in text:
        raise SystemExit(f"Required hotfix marker missing: {marker}")

target_path.write_text(text, encoding="utf-8")
print(hashlib.sha256(target_path.read_bytes()).hexdigest())
