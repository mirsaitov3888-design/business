#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/lk3-v17/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
index_sha="$(sha256sum "$root/index.php" | cut -d' ' -f1)"

mysql -h127.0.0.1 -uroot -proot portal <<'SQL'
ALTER TABLE projects
    ADD COLUMN counter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN webmaster_host_id VARCHAR(255) NULL;
ALTER TABLE site_monitors
    ADD COLUMN counter_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    ADD COLUMN webmaster_host_id VARCHAR(255) NULL;

UPDATE projects
SET counter_id = 55555,
    webmaster_host_id = 'https:a-second.example:443'
WHERE id = 102;

UPDATE site_monitors
SET counter_id = 44444,
    webmaster_host_id = 'https:a-sub.example:443'
WHERE url = 'https://a-sub.example';

UPDATE project_sites
SET metrika_counter_ids_json = JSON_ARRAY(),
    webmaster_host_ids_json = JSON_ARRAY()
WHERE host IN ('a-sub.example', 'a-second.example');

DELETE FROM project_client_links WHERE project_id <> 101;
SQL

cat > /tmp/lk15/data/yandex-direct-config.php <<'PHP'
<?php
declare(strict_types=1);
return [
    'token' => 'fixture-token-never-published',
    'client_login' => 'fixture-client-login',
];
PHP
chmod 0600 /tmp/lk15/data/yandex-direct-config.php

python3 tools/system-updates/build_recovery_v18.py
php -l updates/installers/2026.08.03.18.php
grep -q "const PORTAL_RECOVERY_VERSION = '2026.08.03.18'" updates/installers/2026.08.03.18.php
grep -q 'PORTAL_RECOVERY_V18_CLIENT_ACTION' updates/installers/2026.08.03.18.php
grep -q 'PORTAL_RECOVERY_V18_SELECTED_SOURCE' updates/installers/2026.08.03.18.php

fake_node=/tmp/lk3-old-node
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.18.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.18.php"
)

test "$(sha256sum "$root/index.php" | cut -d' ' -f1)" = "$index_sha"
test "$(grep -c 'PORTAL_RECOVERY_V18_CLIENT_ACTION' "$root/client-structure-api.php")" -eq 1
test "$(grep -c 'PORTAL_RECOVERY_V18_SELECTED_SOURCE' "$root/app/Services/ProjectSourceService.php")" -eq 1
test "$(grep -c 'PORTAL_RECOVERY_V18' "$root/assets/app.js")" -eq 1

php -l "$root/client-structure-api.php"
php -l "$root/app/Services/ProjectSourceService.php"
php -l "$root/app/Services/PortalRecoveryService.php"
php -l "$root/bin/portal_recovery_v18.php"
node --check "$root/assets/app.js"

response="$(
  cd "$root"
  php -r '
    $_GET = ["action" => "client&client_id=10"];
    $_SERVER["REQUEST_METHOD"] = "GET";
    include "client-structure-api.php";
  ' 2>/dev/null
)"
printf '%s' "$response" | jq -e '.ok == true and .data.client.id == 10' >/dev/null

RECOVERY_FIXTURE_ROOT="$root" php tools/recovery-v18/test_recovery.php
(
  cd "$root"
  php bin/portal_recovery_v18.php
)
RECOVERY_FIXTURE_ROOT="$root" php tools/recovery-v18/test_recovery.php

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SELECT COUNT(*) FROM project_source_links WHERE project_id=101 AND source_type='yandex_direct_account'")" -eq 1

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='portal' AND TABLE_NAME LIKE 'project_sites_before_recovery_v18_%'")" -ge 1

echo 'Portal recovery v18 full integration passed.'
