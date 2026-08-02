#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/lk-context-v15/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
index_sha="$(sha256sum "$root/index.php" | cut -d' ' -f1)"

python3 tools/system-updates/build_lk2_v16.py
php -l updates/installers/2026.08.02.16.php
grep -q "const LK2_VERSION = '2026.08.02.16'" updates/installers/2026.08.02.16.php
grep -q 'LK2_CLIENT_STRUCTURE_BUNDLED_V180216' updates/installers/2026.08.02.16.php

(
  cd "$root"
  LK2_COMPONENT_ROOT="$workspace/tools/lk2-v16" \
    php "$workspace/updates/installers/2026.08.02.16.php"
)
(
  cd "$root"
  LK2_COMPONENT_ROOT="$workspace/tools/lk2-v16" \
    php "$workspace/updates/installers/2026.08.02.16.php"
)

test "$(sha256sum "$root/index.php" | cut -d' ' -f1)" = "$index_sha"
test "$(grep -c 'LK2_CLIENT_STRUCTURE_BUNDLED_V180216' "$root/assets/app.js")" -eq 1
test "$(grep -c 'LK2_CLIENT_STRUCTURE_BUNDLED_V180216' "$root/assets/app.css")" -eq 1
grep -q 'LK2_CLIENT_STRUCTURE_SCHEMA_V180216' "$root/sql/schema.sql"

php -l "$root/app/Services/ClientStructureService.php"
php -l "$root/client-structure-api.php"
node --check "$root/assets/app.js"

mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW TABLES LIKE 'client_structure_changes'" | grep -q client_structure_changes
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM projects LIKE 'lk_status'" | grep -q lk_status
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM project_sites LIKE 'sort_order'" | grep -q sort_order
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM project_sites LIKE 'notes'" | grep -q notes

LK2_FIXTURE_ROOT="$root" php tools/lk2-v16/test_service.php

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='portal' AND TABLE_NAME='project_sites' AND COLUMN_NAME='sort_order'")" -eq 1

echo 'LK2 full integration passed.'
