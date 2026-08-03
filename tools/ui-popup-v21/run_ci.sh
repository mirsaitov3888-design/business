#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/ui-hotfix-v20/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
index_before="$(sha256sum "$root/index.php" | cut -d' ' -f1)"
clients_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')"
projects_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')"
sites_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')"

python3 tools/system-updates/build_ui_popup_v21.py
php -l updates/installers/2026.08.03.21.php
grep -q "const UI_POPUP_FIX_VERSION = '2026.08.03.21'" \
  updates/installers/2026.08.03.21.php
grep -q 'PORTAL_UI_POPUP_FIX_V180321' \
  updates/installers/2026.08.03.21.php
grep -q 'LK2_MODAL_DIRECT_CLOSE_V21' \
  updates/installers/2026.08.03.21.php

fake_node=/tmp/lk3-old-node
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.21.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.21.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.21.php"
)

php -l "$root/index.php"
node --check "$root/assets/app.js"

test "$(grep -c 'PORTAL_UI_POPUP_FIX_V180321' "$root/assets/app.css")" -eq 1
test "$(grep -c 'LK2_MODAL_DIRECT_CLOSE_V21' "$root/assets/app.js")" -eq 1
! grep -q "if (action === 'close-modal') return closeModal();" "$root/assets/app.js"
grep -q "target.classList.contains('lk2-modal-backdrop')" "$root/assets/app.js"
grep -q 'event.target !== target' "$root/assets/app.js"
grep -q 'input\[type="checkbox"\]' "$root/assets/app.css"
grep -q 'white-space: normal !important' "$root/assets/app.css"
grep -q 'overflow-x: hidden !important' "$root/assets/app.css"
grep -q 'assets/app.css?v=2026080321' "$root/index.php"
grep -q 'assets/app.js?v=2026080321' "$root/index.php"
! grep -q 'assets/app.css?v=2026080320' "$root/index.php"
! grep -q 'assets/app.js?v=2026080320' "$root/index.php"

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')" = "$clients_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')" = "$projects_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')" = "$sites_before"

# index.php меняется только из-за версии ресурсов.
test "$(sha256sum "$root/index.php" | cut -d' ' -f1)" != "$index_before"

echo 'Popup layout and LK2 modal hotfix v21 full integration passed.'
