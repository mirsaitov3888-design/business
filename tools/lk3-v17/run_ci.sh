#!/usr/bin/env bash
set -euo pipefail

workspace="${GITHUB_WORKSPACE:-$(pwd)}"

bash tools/lk2-v16/run_ci.sh

root=/tmp/lk15/data/www/seo-test.mirsaitov.pw
index_sha="$(sha256sum "$root/index.php" | cut -d' ' -f1)"
mkdir -p "$root/app/Repositories"

cat > "$root/app/Repositories/ProjectRepository.php" <<'PHP'
<?php
declare(strict_types=1);
namespace SeoAnalytics\Repositories;
use SeoAnalytics\Core\Database;
final class ProjectRepository
{
    public function firstActive(): ?array
    {
        $row = Database::pdo()->query(
            'SELECT * FROM projects WHERE active = 1 ORDER BY id ASC LIMIT 1'
        )->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function goalIds(array $project): array
    {
        $value = $project['goal_ids_json'] ?? null;
        if (is_string($value)) {
            $value = json_decode($value, true);
        }
        return is_array($value)
            ? array_values(array_filter(array_map('intval', $value)))
            : [];
    }
}
PHP

mysql -h127.0.0.1 -uroot -proot portal <<'SQL'
CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    title VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS conversion_goals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    source_system VARCHAR(30) NOT NULL DEFAULT 'manual',
    external_id VARCHAR(190) NOT NULL,
    name VARCHAR(255) NOT NULL,
    classification VARCHAR(30) NOT NULL DEFAULT 'unclassified',
    active TINYINT(1) NOT NULL DEFAULT 1,
    notes TEXT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_conversion_goal_external (
        project_id, source_system, external_id
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO reports (id, title) VALUES (7001, 'LK3 test report');
INSERT IGNORE INTO conversion_goals
(id, project_id, source_system, external_id, name,
 classification, active, created_by, updated_by)
VALUES
(8001, 102, 'metrika', 'goal-8001', 'LK3 test goal',
 'lead', 1, 1, 1);
SQL

export LK3_REAL_NODE="$(command -v node)"
export LK3_FAKE_NODE_DIR=/tmp/lk3-old-node
rm -rf "$LK3_FAKE_NODE_DIR"
mkdir -p "$LK3_FAKE_NODE_DIR"
cat > "$LK3_FAKE_NODE_DIR/node" <<'NODE'
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
chmod +x "$LK3_FAKE_NODE_DIR/node"

python3 tools/system-updates/build_lk3_v17.py
php -l updates/installers/2026.08.02.17.php
grep -q "const LK3_VERSION = '2026.08.02.17'" updates/installers/2026.08.02.17.php
grep -q 'LK3_SELECTED_PROJECT_V180217' updates/installers/2026.08.02.17.php

(
  cd "$root"
  PATH="$LK3_FAKE_NODE_DIR:$PATH" \
    php "$workspace/updates/installers/2026.08.02.17.php"
)
(
  cd "$root"
  PATH="$LK3_FAKE_NODE_DIR:$PATH" \
    php "$workspace/updates/installers/2026.08.02.17.php"
)

test "$(sha256sum "$root/index.php" | cut -d' ' -f1)" = "$index_sha"
test "$(grep -c 'LK3_SELECTED_PROJECT_V180217' "$root/app/Repositories/ProjectRepository.php")" -eq 1
test "$(grep -c 'LK3_PROJECT_SOURCES_BUNDLED_V180217' "$root/assets/app.js")" -eq 1
test "$(grep -c 'LK3_PROJECT_SOURCES_BUNDLED_V180217' "$root/assets/app.css")" -eq 1
grep -q 'LK3_PROJECT_SOURCES_SCHEMA_V180217' "$root/sql/schema.sql"

php -l "$root/app/Repositories/ProjectRepository.php"
php -l "$root/app/Services/ProjectSourceService.php"
php -l "$root/project-sources-api.php"
"$LK3_REAL_NODE" --check "$root/assets/app.js"

mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW TABLES LIKE 'project_source_links'" | grep -q project_source_links
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW TABLES LIKE 'report_site_links'" | grep -q report_site_links
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM conversion_goals LIKE 'site_id'" | grep -q site_id
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM conversion_goals LIKE 'scope_type'" | grep -q scope_type
mysql -N -h127.0.0.1 -uroot -proot portal -e \
  "SHOW COLUMNS FROM reports LIKE 'project_id'" | grep -q project_id

LK3_FIXTURE_ROOT="$root" php tools/lk3-v17/test_service.php

echo 'LK3A full integration passed.'
