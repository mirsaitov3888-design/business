#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/local-management-v24/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
clients_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')"
projects_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')"
sites_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')"

python3 tools/system-updates/build_local_bitrix_links_v25.py
php -l updates/installers/2026.08.03.25.php
grep -q "const LOCAL_LINKS_VERSION = '2026.08.03.25'" \
  updates/installers/2026.08.03.25.php
grep -q 'LOCAL_BITRIX_LINKS_V180325' \
  updates/installers/2026.08.03.25.php

fake_node=/tmp/lk3-old-node
for run in 1 2 3; do
  (
    cd "$root"
    PATH="$fake_node:$PATH" \
      php "$workspace/updates/installers/2026.08.03.25.php"
  )
done

php -l "$root/index.php"
php -l "$root/app/Services/LocalBitrixLinkService.php"
php -l "$root/local-bitrix-links-api.php"
php -l "$root/app/Services/Bitrix24ClientOnboardingService.php"
node --check "$root/assets/app.js"

test "$(grep -c 'LOCAL_BITRIX_LINKS_V180325' "$root/assets/app.js")" -eq 1
test "$(grep -c 'LOCAL_BITRIX_LINKS_V180325' "$root/assets/app.css")" -eq 1
grep -q 'LOCAL_BITRIX_PROJECT_LOOKUP_V180325' \
  "$root/app/Services/Bitrix24ClientOnboardingService.php"
grep -q 'INNER JOIN projects p ON p.id = bpl.project_id' \
  "$root/app/Services/Bitrix24ClientOnboardingService.php"
grep -q 'data-lk2-action="new-project"' "$root/assets/app.js"
grep -q "delete button.dataset.lk2Action" "$root/assets/app.js"
grep -q 'assets/app.css?v=2026080325' "$root/index.php"
grep -q 'assets/app.js?v=2026080325' "$root/index.php"

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')" = "$clients_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')" = "$projects_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')" = "$sites_before"

LOCAL_LINKS_FIXTURE_ROOT="$root" \
  php tools/local-bitrix-links-v25/test_service.php

echo 'Local Bitrix links and project form v25 integration passed.'
