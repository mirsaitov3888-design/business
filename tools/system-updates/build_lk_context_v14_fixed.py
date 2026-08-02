from __future__ import annotations

from pathlib import Path

source = Path('tools/system-updates/build_lk_context_v14.py')
text = source.read_text(encoding='utf-8')

old_order = (
    '"                ? \' ORDER BY COALESCE(c.name, \\\\\\\"\\\\\\\") ASC, p.id ASC\'\\n"'
)
new_order = (
    '"                ? \\" ORDER BY COALESCE(c.name, \'\') ASC, p.id ASC\\"\\n"'
)
if text.count(old_order) != 1:
    raise SystemExit(
        f'LK context SQL ordering marker: expected 1, found {text.count(old_order)}'
    )
text = text.replace(old_order, new_order, 1)

old_backup = (
    "$backupDirectory = $root . '/storage/backups/lk-context-v14-' . date('Ymd-His');"
)
new_backup = (
    "$backupDirectory = $root . '/storage/backups/lk-context-v14-' "
    ". date('Ymd-His') . '-' . bin2hex(random_bytes(3));"
)
if text.count(old_backup) != 1:
    raise SystemExit(
        f'LK context backup marker: expected 1, found {text.count(old_backup)}'
    )
text = text.replace(old_backup, new_backup, 1)

runtime = Path('/tmp/build_lk_context_v14_runtime.py')
runtime.write_text(text, encoding='utf-8')
exec(compile(text, str(runtime), 'exec'), {'__name__': '__main__'})
