#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/bitrix-client-v19/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
cat > "$root/index.php" <<'PHP'
<?php declare(strict_types=1); ?>
<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <link rel="stylesheet" href="/assets/app.css?v=old-cache">
</head>
<body>
    <main id="app"></main>
    <script src="assets/app.js?cache=old-cache"></script>
</body>
</html>
PHP

python3 tools/system-updates/build_ui_hotfix_v20.py
php -l updates/installers/2026.08.03.20.php
grep -q "const UI_HOTFIX_VERSION = '2026.08.03.20'" \
  updates/installers/2026.08.03.20.php
grep -q 'PORTAL_UI_HOTFIX_V180320' \
  updates/installers/2026.08.03.20.php

fake_node=/tmp/lk3-old-node
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.20.php"
)
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.20.php"
)

php -l "$root/index.php"
node --check "$root/assets/app.js"

test "$(grep -c 'PORTAL_UI_HOTFIX_V180320' "$root/assets/app.js")" -eq 1
test "$(grep -c 'PORTAL_UI_HOTFIX_V180320' "$root/assets/app.css")" -eq 1
test "$(grep -o 'assets/app.css?v=2026080320' "$root/index.php" | wc -l)" -eq 1
test "$(grep -o 'assets/app.js?v=2026080320' "$root/index.php" | wc -l)" -eq 1
! grep -q 'old-cache' "$root/index.php"
grep -q "modal.removeAttribute('onclick')" "$root/assets/app.js"
grep -q "data-lk2-action=\\\"close-modal\\\"" "$root/assets/app.js"
grep -q 'portalUiHotfixV20Styles' "$root/assets/app.js"
grep -q '.lk2-client-structure .btn-primary' "$root/assets/app.css"
grep -q '.b19-modal .btn-primary' "$root/assets/app.css"

# Повторная установка должна только сохранить один маркер и тот же cache-busting URL.
(
  cd "$root"
  PATH="$fake_node:$PATH" \
    php "$workspace/updates/installers/2026.08.03.20.php"
)
test "$(grep -c 'PORTAL_UI_HOTFIX_V180320' "$root/assets/app.js")" -eq 1
test "$(grep -c 'PORTAL_UI_HOTFIX_V180320' "$root/assets/app.css")" -eq 1
test "$(grep -o 'assets/app.css?v=2026080320' "$root/index.php" | wc -l)" -eq 1
test "$(grep -o 'assets/app.js?v=2026080320' "$root/index.php" | wc -l)" -eq 1

echo 'Portal UI hotfix v20 integration passed.'
