from pathlib import Path

path = Path('.github/workflows/validate-p0-5.yml')
text = path.read_text(encoding='utf-8')

old_bootstrap = """          <?php
          declare(strict_types=1);
          require_once __DIR__ . '/Services/Bitrix24Client.php';

          namespace SeoAnalytics\\Core;
"""
new_bootstrap = """          <?php
          declare(strict_types=1);
          namespace SeoAnalytics\\Core;

          require_once __DIR__ . '/Services/Bitrix24Client.php';
"""

if text.count(old_bootstrap) != 1:
    raise SystemExit('Bootstrap namespace block not found exactly once')
text = text.replace(old_bootstrap, new_bootstrap, 1)

old_path = "dirname(__DIR__, 2) . '/storage/test.sqlite'"
new_path = "dirname(__DIR__) . '/storage/test.sqlite'"
if text.count(old_path) != 1:
    raise SystemExit('SQLite path not found exactly once')
text = text.replace(old_path, new_path, 1)

path.write_text(text, encoding='utf-8')
print('P0.5 validation fixture corrected')
