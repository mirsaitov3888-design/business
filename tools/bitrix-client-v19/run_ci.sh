#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/recovery-v18/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
index_sha="$(sha256sum "$root/index.php" | cut -d' ' -f1)"
mkdir -p "$root/app/Services" "$root/app/Repositories"
cp tools/bitrix24-step1/Bitrix24Client.php \
   "$root/app/Services/Bitrix24Client.php"
cp tools/bitrix24-step1/Bitrix24Repository.php \
   "$root/app/Repositories/Bitrix24Repository.php"
mysql -h127.0.0.1 -uroot -proot portal \
  < tools/bitrix24-step1/schema.sql

python3 tools/system-updates/build_bitrix_client_v19.py
php -l updates/installers/2026.08.03.19.php
grep -q "const BITRIX_CLIENT_VERSION = '2026.08.03.19'" \
  updates/installers/2026.08.03.19.php
grep -q 'BITRIX_CLIENT_ONBOARDING_V180319' \
  updates/installers/2026.08.03.19.php

fake_node=/tmp/lk3-old-node
if [ ! -x "$fake_node/node" ]; then
  mkdir -p "$fake_node"
  cat > "$fake_node/node" <<'NODE'
#!/usr/bin/env bash
set -euo pipefail
case "${1:-}" in
  -e)
    echo 'SyntaxError: Unexpected token .' >&2
    exit 1
    ;;
  --check)
    echo 'old Node must not validate modern portal JavaScript' >&2
    exit 1
    ;;
  --version)
    echo 'v10.24.1'
    exit 0
    ;;
  *)
    exit 1
    ;;
esac
NODE
  chmod +x "$fake_node/node"
fi

(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.19.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.19.php"
)

test "$(sha256sum "$root/index.php" | cut -d' ' -f1)" = "$index_sha"
test "$(grep -c 'BITRIX_CLIENT_ONBOARDING_V180319' "$root/assets/app.js")" -eq 1
test "$(grep -c 'BITRIX_CLIENT_ONBOARDING_V180319' "$root/assets/app.css")" -eq 1
grep -q 'BITRIX_CLIENT_ONBOARDING_SCHEMA_V180319' "$root/sql/schema.sql"
grep -q 'new URLSearchParams' "$root/assets/app.js"
! grep -q "catalog&client_id" "$root/assets/app.js"
! grep -q "catalog&company_id" "$root/assets/app.js"
grep -q "request('save', {}," "$root/assets/app.js"

php -l "$root/app/Services/Bitrix24DirectoryService.php"
php -l "$root/app/Services/Bitrix24ClientOnboardingService.php"
php -l "$root/bitrix24-client-api.php"
node --check "$root/assets/app.js"

mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW TABLES LIKE 'client_bitrix_contacts'" | grep -q client_bitrix_contacts
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW TABLES LIKE 'client_bitrix_projects'" | grep -q client_bitrix_projects
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM clients LIKE 'bitrix_company_id'" | grep -q bitrix_company_id
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM clients LIKE 'bitrix_synced_at'" | grep -q bitrix_synced_at

BITRIX_CLIENT_FIXTURE_ROOT="$root" \
  php tools/bitrix-client-v19/test_service.php

# Повторный тест после уже выполненной синхронизации подтверждает идемпотентность схемы.
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.19.php"
)

test "$(grep -c 'BITRIX_CLIENT_ONBOARDING_V180319' "$root/assets/app.js")" -eq 1
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SELECT COUNT(*) FROM clients WHERE bitrix_company_id=501")" -eq 1

echo 'Bitrix-first client onboarding v19 full integration passed.'
