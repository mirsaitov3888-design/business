#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/navigation-v22/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
clients_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')"
projects_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')"
sites_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')"
sources_before="$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_source_links')"

python3 tools/system-updates/build_site_onboarding_v23.py
php -l updates/installers/2026.08.03.23.php
grep -q "const SITE_ONBOARDING_VERSION = '2026.08.03.23'" \
  updates/installers/2026.08.03.23.php
grep -q 'SITE_ONBOARDING_V180323' \
  updates/installers/2026.08.03.23.php

fake_node=/tmp/lk3-old-node
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.23.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.23.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.23.php"
)

php -l "$root/index.php"
php -l "$root/app/Services/SiteOnboardingService.php"
php -l "$root/site-onboarding-api.php"
node --check "$root/assets/app.js"

test "$(grep -c 'SITE_ONBOARDING_V180323' "$root/assets/app.js")" -eq 1
test "$(grep -c 'SITE_ONBOARDING_V180323' "$root/assets/app.css")" -eq 1
grep -q 'site-onboarding-api.php' "$root/assets/app.js"
grep -q 'data-lk2-action="new-site"' "$root/assets/app.js"
grep -q 'crm.company.update' "$root/app/Services/SiteOnboardingService.php"
grep -q 'bitrix24_company_web' "$root/app/Services/SiteOnboardingService.php"
grep -q 'yandex_metrika' "$root/app/Services/SiteOnboardingService.php"
grep -q 'yandex_direct' "$root/app/Services/SiteOnboardingService.php"
grep -q 'yandex_webmaster' "$root/app/Services/SiteOnboardingService.php"
grep -q 'assets/app.css?v=2026080323' "$root/index.php"
grep -q 'assets/app.js?v=2026080323' "$root/index.php"
! grep -q 'assets/app.css?v=2026080322' "$root/index.php"
! grep -q 'assets/app.js?v=2026080322' "$root/index.php"

test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM clients')" = "$clients_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM projects')" = "$projects_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')" = "$sites_before"
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_source_links')" = "$sources_before"

SITE_ONBOARDING_FIXTURE_ROOT="$root" \
  php tools/site-onboarding-v23/test_service.php

echo 'Unified site onboarding v23 full integration passed.'
