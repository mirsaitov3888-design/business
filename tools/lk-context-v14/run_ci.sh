#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"
root=/tmp/lk14/data/www/seo-test.mirsaitov.pw
rm -rf /tmp/lk14
mkdir -p \
  "$root/app/Core" \
  "$root/app/Services" \
  "$root/app/Repositories" \
  "$root/assets" \
  "$root/sql" \
  "$root/storage/backups"

python3 tools/system-updates/build_lk_context_v14_fixed.py
php -l updates/installers/2026.08.02.14.php
grep -q "const LK_CONTEXT_VERSION = '2026.08.02.14'" updates/installers/2026.08.02.14.php
grep -q 'LK_CONTEXT_BUNDLED_V180214' updates/installers/2026.08.02.14.php

cat > "$root/index.php" <<'PHP'
<?php declare(strict_types=1); ?>
<main id="portal-root"></main>
PHP
sha256sum "$root/index.php" | cut -d' ' -f1 > /tmp/lk14-index.sha

cat > "$root/assets/app.js" <<'JS'
window.showSection = () => {};
/* P1_SALES_BUNDLED_V180212 */
/* P1_GOALS_BUNDLED_V180213 */
JS
cat > "$root/assets/app.css" <<'CSS'
:root { --line: #ddd; --text: #111; --muted: #667; }
/* P1_SALES_BUNDLED_V180212 */
/* P1_GOALS_BUNDLED_V180213 */
CSS
printf '%s\n' '-- base schema' > "$root/sql/schema.sql"
printf '%s\n' '<?php declare(strict_types=1);' > "$root/p1-api.php"
printf '%s\n' '<?php declare(strict_types=1);' > "$root/p1-goals-api.php"

cat > "$root/app/bootstrap.php" <<'PHP'
<?php
declare(strict_types=1);
spl_autoload_register(static function (string $class): void {
    $prefix = 'SeoAnalytics\\';
    if (!str_starts_with($class, $prefix)) return;
    $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
    $path = __DIR__ . '/' . $relative . '.php';
    if (is_file($path)) require_once $path;
});
PHP

cat > "$root/app/Core/Database.php" <<'PHP'
<?php
declare(strict_types=1);
namespace SeoAnalytics\Core;
final class Database
{
    public static function pdo(): \PDO
    {
        static $pdo;
        if (!$pdo instanceof \PDO) {
            $pdo = new \PDO(
                'mysql:host=127.0.0.1;port=3306;dbname=portal;charset=utf8mb4',
                'root',
                'root',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );
        }
        return $pdo;
    }
}
PHP

cat > "$root/app/Services/PortalAccessService.php" <<'PHP'
<?php
declare(strict_types=1);
namespace SeoAnalytics\Services;
use SeoAnalytics\Core\Database;
final class PortalAccessService
{
    public static int $testUserId = 1;
    public function currentUser(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, name, email, role, account_status FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => self::$testUserId]);
        $user = $stmt->fetch();
        if (!$user) throw new \RuntimeException('User not found');
        $user['id'] = (int) $user['id'];
        return $user;
    }
    public function requireRoles(array $roles): array
    {
        $user = $this->currentUser();
        if (!in_array($user['role'], $roles, true)) {
            throw new \RuntimeException('Denied');
        }
        return $user;
    }
}
PHP

mysql -h127.0.0.1 -uroot -proot portal < tools/lk-context-v14/test_fixture.sql

(cd "$root" && php "$workspace/updates/installers/2026.08.02.14.php")
(cd "$root" && php "$workspace/updates/installers/2026.08.02.14.php")

test "$(sha256sum "$root/index.php" | cut -d' ' -f1)" = "$(cat /tmp/lk14-index.sha)"
test "$(grep -c 'LK_CONTEXT_BUNDLED_V180214' "$root/assets/app.js")" -eq 1
test "$(grep -c 'LK_CONTEXT_BUNDLED_V180214' "$root/assets/app.css")" -eq 1
grep -q 'LK_CONTEXT_V180214' "$root/assets/app.js"
grep -q 'LK_CONTEXT_SCHEMA_V180214' "$root/sql/schema.sql"
grep -q 'PortalContextService' "$root/p1-api.php"
grep -q 'PortalContextService' "$root/p1-goals-api.php"
! grep -q 'firstActive()' "$root/p1-api.php"
! grep -q 'firstActive()' "$root/p1-goals-api.php"
grep -q 'Клиентский доступ работает только на просмотр' "$root/p1-api.php"
grep -q 'Клиентский доступ работает только на просмотр' "$root/p1-goals-api.php"

php -l "$root/app/Services/PortalContextService.php"
php -l "$root/portal-context-api.php"
php -l "$root/p1-api.php"
php -l "$root/p1-goals-api.php"
node --check "$root/assets/app.js"

mysql -N -h127.0.0.1 -uroot -proot portal -e "SHOW TABLES LIKE 'project_sites'" | grep -q project_sites
mysql -N -h127.0.0.1 -uroot -proot portal -e "SHOW TABLES LIKE 'user_portal_context'" | grep -q user_portal_context
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites')" -eq 6
test "$(mysql -N -h127.0.0.1 -uroot -proot portal -e 'SELECT COUNT(*) FROM project_sites WHERE project_id = 101')" -eq 2

LK_FIXTURE_ROOT="$root" php tools/lk-context-v14/test_context.php

echo 'LK context v14 integration passed.'
