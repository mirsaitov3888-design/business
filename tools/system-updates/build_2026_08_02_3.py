from __future__ import annotations

import base64
import hashlib
import json
import re
import textwrap
import zlib
from pathlib import Path

source_path = Path('updates/installers/2026.08.02.2.php')
source = source_path.read_text(encoding='utf-8')
match = re.search(r"\$payload = <<<'PAYLOAD'\n(.*?)\nPAYLOAD;", source, re.S)
if not match:
    raise SystemExit('Embedded payload not found')

compressed_old = base64.b64decode(''.join(match.group(1).split()), validate=True)
core = zlib.decompress(compressed_old).decode('utf-8')

lines = core.splitlines(keepends=True)
escaped_occurrences = 0
for index, line in enumerate(lines):
    stripped = line.lstrip()
    if stripped.startswith('"                    ${') or stripped.startswith('. "                    ${'):
        escaped_occurrences += line.count('${')
        lines[index] = line.replace('${', r'\${')
core = ''.join(lines)

if escaped_occurrences != 5:
    raise SystemExit(f'Expected to escape 5 JavaScript template expressions, got {escaped_occurrences}')
if '"                    ${' in core or '. "                    ${' in core:
    raise SystemExit('Unescaped JavaScript template expression remains in a PHP double-quoted string')

fallback_old = "(string) ($current['version'] ?? '2026.08.02.2')"
fallback_new = "(string) ($current['version'] ?? '2026.08.02.3')"
if core.count(fallback_old) != 1:
    raise SystemExit('Version fallback marker not found exactly once')
core = core.replace(fallback_old, fallback_new)

core_bytes = core.encode('utf-8')
compressed = zlib.compress(core_bytes, 9)
core_sha = hashlib.sha256(core_bytes).hexdigest()
compressed_sha = hashlib.sha256(compressed).hexdigest()
payload = '\n'.join(textwrap.wrap(base64.b64encode(compressed).decode('ascii'), 120))

loader = re.sub(
    r"(\$payload = <<<'PAYLOAD'\n).*?(\nPAYLOAD;)",
    lambda m: m.group(1) + payload + m.group(2),
    source,
    count=1,
    flags=re.S,
)
loader = loader.replace(
    hashlib.sha256(compressed_old).hexdigest(),
    compressed_sha,
    1,
)
old_core_sha_match = re.search(
    r"hash_equals\('([0-9a-f]{64})', hash\('sha256', \$core\)\)",
    loader,
)
if not old_core_sha_match:
    raise SystemExit('Core SHA marker not found')
loader = loader.replace(old_core_sha_match.group(1), core_sha, 1)
loader = loader.replace('mirsaitov-update-180202-', 'mirsaitov-update-180203-', 1)

output_path = Path('updates/installers/2026.08.02.3.php')
output_path.write_text(loader, encoding='utf-8')
installer_sha = hashlib.sha256(loader.encode('utf-8')).hexdigest()

changelog = [
    'Исправлена ошибка Undefined constant row в установщике автоматической диагностики',
    'JavaScript-шаблоны в PHP-установщике теперь безопасно экранируются',
    'После успешной установки патча автоматически запускается единый диагностический агент',
    'В карточке обновления отображается результат диагностики, количество ошибок и предупреждений',
    'Полный отчёт открывается ссылкой в существующем разделе диагностики без дублирования',
    'Для каждого обновления сохраняется отдельный исторический HTML/JSON-отчёт',
    'Ошибка запуска диагностики фиксируется отдельно и не откатывает успешно установленный патч',
]
release = {
    'version': '2026.08.02.3',
    'title': 'Автоматическая диагностика после обновлений — исправленный установщик',
    'released_at': '2026-08-02',
    'installer_path': 'updates/installers/2026.08.02.3.php',
    'changelog': changelog,
}
manifest = {
    'channel': 'stable',
    'latest': {
        'version': release['version'],
        'title': release['title'],
        'released_at': release['released_at'],
        'changelog': changelog,
        'installer_url': 'https://raw.githubusercontent.com/mirsaitov3888-design/business/main/updates/installers/2026.08.02.3.php',
        'sha256': installer_sha,
    },
}
Path('updates/release.json').write_text(
    json.dumps(release, ensure_ascii=False, indent=2) + '\n',
    encoding='utf-8',
)
Path('updates/manifest.json').write_text(
    json.dumps(manifest, ensure_ascii=False, indent=2) + '\n',
    encoding='utf-8',
)

validation_path = Path('.github/workflows/validate-post-update-diagnostics.yml')
validation = validation_path.read_text(encoding='utf-8')
validation = validation.replace('2026.08.02.2.php', '2026.08.02.3.php')
validation = validation.replace('2026.08.02.2-core.php', '2026.08.02.3-core.php')
validation = validation.replace(
    '782ae1133f2068abc3db8b551466e665bf92bc4761919c15bb4d7333ef0ffdfb',
    compressed_sha,
)
validation = validation.replace(
    '9a906bacfb9556efc2f4c3646d974a2eca732168c62fc6688e48467575711246',
    core_sha,
)
assertion_anchor = "          Path('/tmp/2026.08.02.3-core.php').write_bytes(core)\n"
assertion_block = (
    "          core_text = core.decode('utf-8')\n"
    "          if '\"                    ${' in core_text or '. \"                    ${' in core_text:\n"
    "              raise SystemExit('Unescaped JavaScript template expression in PHP string')\n"
    + assertion_anchor
)
if assertion_anchor not in validation:
    raise SystemExit('Validation insertion anchor not found')
validation = validation.replace(assertion_anchor, assertion_block, 1)
validation_path.write_text(validation, encoding='utf-8')

checksums_dir = Path('tools/system-updates/2026.08.02.3')
checksums_dir.mkdir(parents=True, exist_ok=True)
(checksums_dir / 'checksums.txt').write_text(
    f'installer_sha256={installer_sha}\n'
    f'compressed_sha256={compressed_sha}\n'
    f'core_sha256={core_sha}\n',
    encoding='utf-8',
)

print(f'installer_sha256={installer_sha}')
print(f'compressed_sha256={compressed_sha}')
print(f'core_sha256={core_sha}')
