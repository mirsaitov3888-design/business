from __future__ import annotations

import re
from pathlib import Path

component_path = Path('tools/p1-step2/ConversionGoalRepository.php')
component = component_path.read_text(encoding='utf-8')

alias_replacements = {
    'SUM(active = 1 AND classification = "lead") AS lead,':
        'SUM(active = 1 AND classification = "lead") AS lead_count,',
    'SUM(active = 1 AND classification = "assisted") AS assisted,':
        'SUM(active = 1 AND classification = "assisted") AS assisted_count,',
    'SUM(active = 1 AND classification = "micro") AS micro,':
        'SUM(active = 1 AND classification = "micro") AS micro_count,',
    'SUM(active = 1 AND classification = "unclassified") AS unclassified':
        'SUM(active = 1 AND classification = "unclassified") AS unclassified_count',
}
for old, new in alias_replacements.items():
    count = component.count(old)
    if count != 1:
        raise SystemExit(f'MySQL alias patch failed for {old!r}: found {count}')
    component = component.replace(old, new, 1)

old_counts = """        foreach ([
            'total',
            'active',
            'lead',
            'assisted',
            'micro',
            'unclassified',
        ] as $key) {
            $counts[$key] = (int) ($counts[$key] ?? 0);
        }

        return $counts;
"""
new_counts = """        $counts = [
            'total' => (int) ($counts['total'] ?? 0),
            'active' => (int) ($counts['active'] ?? 0),
            'lead' => (int) ($counts['lead_count'] ?? 0),
            'assisted' => (int) ($counts['assisted_count'] ?? 0),
            'micro' => (int) ($counts['micro_count'] ?? 0),
            'unclassified' => (int) ($counts['unclassified_count'] ?? 0),
        ];

        return $counts;
"""
if component.count(old_counts) != 1:
    raise SystemExit('Goal count normalization block was not found exactly once')
component = component.replace(old_counts, new_counts, 1)
component_path.write_text(component, encoding='utf-8')

path = Path('tools/system-updates/build_p1_2.py')
text = path.read_text(encoding='utf-8')


def replace_once(old: str, new: str, label: str) -> None:
    global text
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'{label}: expected one match, found {count}')
    text = text.replace(old, new, 1)


replace_once(
    'VERSION = "2026.08.02.12"',
    'VERSION = "2026.08.02.13"',
    'version',
)
replace_once(
    'MARKER = "P1_GOALS_V180212"',
    'MARKER = "P1_GOALS_V180213"',
    'marker',
)
replace_once(
    '    content = source.read_text(encoding="utf-8")\n\n'
    '    if destination == "app/Repositories/ConversionGoalRepository.php":',
    '    content = source.read_text(encoding="utf-8")\n'
    '    content = content.replace("P1_GOALS_V180212", "P1_GOALS_V180213")\n'
    '    content = content.replace("P1_GOALS_SCHEMA_V180212", "P1_GOALS_SCHEMA_V180213")\n\n'
    '    if destination == "app/Repositories/ConversionGoalRepository.php":',
    'component markers',
)
replace_once(
    "$indexPath = $root . '/index.php';\n"
    "$schemaPath = $root . '/sql/schema.sql';\n"
    "$p1JsPath = $root . '/assets/p1-sales.js';",
    "$schemaPath = $root . '/sql/schema.sql';\n"
    "$appJsPath = $root . '/assets/app.js';\n"
    "$appCssPath = $root . '/assets/app.css';\n"
    "$p1JsPath = $appJsPath;\n"
    "$p1CssPath = $appCssPath;",
    'asset paths',
)
replace_once(
    "    $indexPath,\n"
    "    $schemaPath,\n"
    "    $root . '/p1-api.php',\n"
    "    $p1JsPath,",
    "    $schemaPath,\n"
    "    $root . '/p1-api.php',\n"
    "    $p1JsPath,\n"
    "    $p1CssPath,",
    'required files',
)
replace_once(
    "if (!str_contains(p12iRead($indexPath), 'P1_SALES_ASSETS_V180211')) {{\n"
    "    throw new RuntimeException('P1.2 требует установленный интерфейс P1.1.');\n"
    "}}",
    "if (\n"
    "    !str_contains(p12iRead($appJsPath), 'P1_SALES_BUNDLED_V180212')\n"
    "    || !str_contains(p12iRead($appCssPath), 'P1_SALES_BUNDLED_V180212')\n"
    ") {{\n"
    "    throw new RuntimeException('P1.2 требует установленный исправленный интерфейс P1.1 версии 2026.08.02.12.');\n"
    "}}",
    'P1.1 dependency marker',
)
replace_once(
    "$tracked[] = 'index.php';\n$tracked[] = 'sql/schema.sql';",
    "$tracked[] = 'assets/app.js';\n"
    "$tracked[] = 'assets/app.css';\n"
    "$tracked[] = 'sql/schema.sql';",
    'tracked assets',
)

new_injection = """    $appCss = p12iRead($appCssPath);
    if (!str_contains($appCss, 'P1_GOALS_BUNDLED_V180213')) {{
        $goalCss = $payloads['assets/p1-goals.css'] ?? '';
        $appCss = rtrim($appCss) . PHP_EOL . PHP_EOL
            . '/* P1_GOALS_BUNDLED_V180213 */' . PHP_EOL
            . trim($goalCss) . PHP_EOL;
        p12iWrite($appCssPath, $appCss);
    }}

    $appJs = p12iRead($appJsPath);
    if (!str_contains($appJs, 'P1_GOALS_BUNDLED_V180213')) {{
        $goalJs = $payloads['assets/p1-goals.js'] ?? '';
        $appJs = rtrim($appJs) . PHP_EOL . PHP_EOL
            . '/* P1_GOALS_BUNDLED_V180213 */' . PHP_EOL
            . trim($goalJs) . PHP_EOL;
        p12iWrite($appJsPath, $appJs);
    }}
"""
pattern = re.compile(
    r"    \$index = p12iRead\(\$indexPath\);\n.*?"
    r"(?=    \$schema = p12iRead\(\$schemaPath\);)",
    re.DOTALL,
)
text, count = pattern.subn(new_injection, text, count=1)
if count != 1:
    raise SystemExit(f'asset injection block: expected one match, found {count}')

replace_once(
    "        $root . '/p1-goals-api.php',\n"
    "        $root . '/index.php',",
    "        $root . '/p1-goals-api.php',",
    'PHP lint list',
)
replace_once(
    "'P1_GOALS_SCHEMA_V180212'",
    "'P1_GOALS_SCHEMA_V180213'",
    'schema runtime marker',
)

path.write_text(text, encoding='utf-8')
print('P1.2 builder prepared for version 2026.08.02.13')
