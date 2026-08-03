#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/site-onboarding-v23/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
clients_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')"
projects_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')"
sites_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')"

python3 tools/system-updates/build_local_management_v24.py
php -l updates/installers/2026.08.03.24.php
grep -q "const LOCAL_MANAGEMENT_VERSION = '2026.08.03.24'" \
  updates/installers/2026.08.03.24.php
grep -q 'LOCAL_STRUCTURE_MANAGEMENT_V180324' \
  updates/installers/2026.08.03.24.php
grep -q 'FINANCE_MENU_BOOTSTRAP_V180324' \
  updates/installers/2026.08.03.24.php

fake_node=/tmp/lk3-old-node
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.24.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.24.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.24.php"
)

php -l "$root/index.php"
php -l "$root/app/Services/Bitrix24Client.php"
php -l "$root/app/Services/Bitrix24SafetyPolicy.php"
php -l "$root/app/Services/LocalStructureAdminService.php"
php -l "$root/local-structure-api.php"
node --check "$root/assets/app.js"

test "$(grep -c 'LOCAL_STRUCTURE_MANAGEMENT_V180324' "$root/assets/app.js")" -eq 1
test "$(grep -c 'LOCAL_STRUCTURE_MANAGEMENT_V180324' "$root/assets/app.css")" -eq 1
test "$(grep -c 'FINANCE_MENU_BOOTSTRAP_V180324' "$root/assets/app.js")" -eq 1
grep -q 'Финансы и экономика' "$root/assets/app.js"
grep -q 'getClientRects().length' "$root/assets/app.js"
grep -q 'Bitrix24SafetyPolicy::assertAllowed' "$root/app/Services/Bitrix24Client.php"
grep -q 'assets/app.css?v=2026080324' "$root/index.php"
grep -q 'assets/app.js?v=2026080324' "$root/index.php"
! grep -q 'assets/app.css?v=2026080323' "$root/index.php"
! grep -q 'assets/app.js?v=2026080323' "$root/index.php"

mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW TABLES LIKE 'local_structure_deletions'" | grep -q local_structure_deletions

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')" = "$clients_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')" = "$projects_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')" = "$sites_before"

LOCAL_MANAGEMENT_FIXTURE_ROOT="$root" \
  php tools/local-management-v24/test_service.php

echo 'Finance menu and local-only structure management v24 integration passed.'
