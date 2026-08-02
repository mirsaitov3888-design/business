#!/usr/bin/env bash
set -euo pipefail

export LK_REAL_NODE="$(command -v node)"
export LK_FAKE_NODE_DIR=/tmp/lk15-old-node
export LK_FIRST_LOG=/tmp/lk15-first-install.log

rm -rf "$LK_FAKE_NODE_DIR" /tmp/lk15 "$LK_FIRST_LOG"
mkdir -p "$LK_FAKE_NODE_DIR"
cat > "$LK_FAKE_NODE_DIR/node" <<'NODE'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
  --version)
    echo 'v10.24.1'
    exit 0
    ;;
  -e)
    echo 'SyntaxError: Unexpected token .' >&2
    exit 1
    ;;
  --check)
    echo 'old Node.js must not be used for the final portal JavaScript check' >&2
    exit 1
    ;;
  *)
    exit 1
    ;;
esac
NODE
chmod +x "$LK_FAKE_NODE_DIR/node"

python3 - <<'PY'
from pathlib import Path

source = Path('tools/lk-context-v14/run_ci.sh')
text = source.read_text(encoding='utf-8')
text = text.replace(
    'python3 tools/system-updates/build_lk_context_v14_fixed.py',
    'python3 tools/system-updates/build_lk_context_v15.py',
)
text = text.replace('2026.08.02.14', '2026.08.02.15')
text = text.replace('/tmp/lk14', '/tmp/lk15')
text = text.replace(
    "LK context v14 integration passed.",
    "LK context v15 old-Node integration passed.",
)

installer_call = '(cd "$root" && php "$workspace/updates/installers/2026.08.02.15.php")'
if text.count(installer_call) != 2:
    raise SystemExit(
        f'installer calls: expected 2, found {text.count(installer_call)}'
    )
first = (
    '(cd "$root" && PATH="$LK_FAKE_NODE_DIR:$PATH" '
    'php "$workspace/updates/installers/2026.08.02.15.php" '
    '| tee "$LK_FIRST_LOG")'
)
second = (
    '(cd "$root" && PATH="$LK_FAKE_NODE_DIR:$PATH" '
    'php "$workspace/updates/installers/2026.08.02.15.php")'
)
text = text.replace(installer_call, first, 1)
text = text.replace(installer_call, second, 1)
text = text.replace(
    'node --check "$root/assets/app.js"',
    '"$LK_REAL_NODE" --check "$root/assets/app.js"',
    1,
)
text = text.replace(
    second,
    second + "\ngrep -q 'Проверка JavaScript через Node.js пропущена' \"$LK_FIRST_LOG\"",
    1,
)

runtime = Path('/tmp/run_lk_context_v15_generated.sh')
runtime.write_text(text, encoding='utf-8')
runtime.chmod(0o755)
PY

bash /tmp/run_lk_context_v15_generated.sh
