#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/ui-popup-v21/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
clients_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')"
projects_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')"
sites_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')"

python3 tools/system-updates/build_navigation_state_v22.py
php -l updates/installers/2026.08.03.22.php
grep -q "const NAVIGATION_STATE_VERSION = '2026.08.03.22'" \
  updates/installers/2026.08.03.22.php
grep -q 'PORTAL_NAVIGATION_STATE_V180322' \
  updates/installers/2026.08.03.22.php

fake_node=/tmp/lk3-old-node
for iteration in 1 2 3; do
  (
    cd "$root"
    PATH="$fake_node:$PATH" \
      php "$workspace/updates/installers/2026.08.03.22.php"
  )
done

php -l "$root/index.php"
node --check "$root/assets/app.js"

test "$(grep -c 'PORTAL_NAVIGATION_STATE_V180322' "$root/assets/app.js")" -eq 1
grep -q 'seoAnalytics.activeSection.v22' "$root/assets/app.js"
grep -q "section: 'p1-sales'" "$root/assets/app.js"
grep -q "label: 'Продажи и экономика'" "$root/assets/app.js"
grep -q 'localStorage.setItem(STORAGE_KEY, section)' "$root/assets/app.js"
grep -q 'history.replaceState' "$root/assets/app.js"
grep -q 'window.addEventListener.*beforeunload' "$root/assets/app.js"
grep -q 'Проекты Bitrix24 (\${selected})' "$root/assets/app.js"
grep -q 'input\[name="project_ids\[\]"\]:checked' "$root/assets/app.js"
grep -q 'node.remove()' "$root/assets/app.js"
grep -q 'location.reload()' "$root/assets/app.js"
grep -q 'assets/app.css?v=2026080322' "$root/index.php"
grep -q 'assets/app.js?v=2026080322' "$root/index.php"
! grep -q 'assets/app.css?v=2026080321' "$root/index.php"
! grep -q 'assets/app.js?v=2026080321' "$root/index.php"

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')" = "$clients_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')" = "$projects_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')" = "$sites_before"

echo 'Unified navigation and active section state v22 integration passed.'
