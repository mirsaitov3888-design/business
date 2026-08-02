from __future__ import annotations

import subprocess
from pathlib import Path

VERSION_14 = "2026.08.02.14"
VERSION_15 = "2026.08.02.15"

subprocess.run(
    ["python3", "tools/system-updates/build_lk_context_v14_fixed.py"],
    check=True,
)

source = Path(f"updates/installers/{VERSION_14}.php")
destination = Path(f"updates/installers/{VERSION_15}.php")
text = source.read_text(encoding="utf-8")

version_old = f"const LK_CONTEXT_VERSION = '{VERSION_14}';"
version_new = f"const LK_CONTEXT_VERSION = '{VERSION_15}';"
if text.count(version_old) != 1:
    raise SystemExit(
        f"LK version marker: expected 1, found {text.count(version_old)}"
    )
text = text.replace(version_old, version_new, 1)
text = text.replace("lk-context-v14-", "lk-context-v15-")

node_start = "    if (function_exists('exec')) {\n        $node = [];\n"
node_end = "\n    if (!lk14tableExists($pdo, 'project_sites')) {"
start = text.find(node_start)
if start < 0:
    raise SystemExit("Node.js validation block start was not found")
end = text.find(node_end, start)
if end < 0:
    raise SystemExit("Node.js validation block end was not found")
old_block = text[start:end]
if "node --check" not in old_block or "Ошибка JavaScript после установки" not in old_block:
    raise SystemExit("Unexpected Node.js validation block")

new_block = r'''    if (function_exists('exec')) {
        $nodeProbe = [];
        $nodeProbeCode = 0;
        $probeScript = "const value = null; value?.property; const fallback = value ?? 'ok';";
        exec(
            'node -e ' . escapeshellarg($probeScript) . ' 2>&1',
            $nodeProbe,
            $nodeProbeCode
        );

        if ($nodeProbeCode === 0) {
            $node = [];
            $nodeCode = 0;
            exec(
                'node --check ' . escapeshellarg($appJsPath) . ' 2>&1',
                $node,
                $nodeCode
            );
            if ($nodeCode !== 0) {
                throw new RuntimeException(
                    'Ошибка JavaScript после установки: ' . implode("\n", $node)
                );
            }
        } else {
            lk14out(
                'Проверка JavaScript через Node.js пропущена: '
                . 'серверная версия Node.js не поддерживает современный синтаксис, '
                . 'который уже используется порталом.'
            );
        }
    }
'''

text = text[:start] + new_block + text[end:]
destination.write_text(text, encoding="utf-8")
print(destination)
