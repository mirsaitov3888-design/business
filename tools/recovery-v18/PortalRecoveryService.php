<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use SeoAnalytics\Core\Database;

final class PortalRecoveryService
{
    private PDO $pdo;
    private string $root;
    private array $columns = [];
    private array $events = [];

    public function __construct(?PDO $pdo = null, ?string $root = null)
    {
        $this->pdo = $pdo ?? Database::pdo();
        $this->root = $root ?? (getcwd() ?: dirname(__DIR__, 2));
    }

    public function run(): array
    {
        $this->ensureAuditTable();

        $summary = [
            'sites_scanned' => 0,
            'sites_updated' => 0,
            'metrika_ids_restored' => 0,
            'webmaster_ids_restored' => 0,
            'ambiguous_sites' => 0,
            'direct_project_linked' => false,
            'direct_assignment_ambiguous' => false,
        ];

        if ($this->tableExists('project_sites')) {
            $summary = array_merge($summary, $this->repairProjectSites());
        }

        $direct = $this->repairDirectProjectLink();
        $summary['direct_project_linked'] = $direct['linked'];
        $summary['direct_assignment_ambiguous'] = $direct['ambiguous'];

        $report = [
            'version' => '2026.08.03.18',
            'generated_at' => date(DATE_ATOM),
            'summary' => $summary,
            'events' => $this->events,
        ];
        $this->writeReport($report);

        return $report;
    }

    private function repairProjectSites(): array
    {
        $sites = $this->pdo->query(
            'SELECT * FROM project_sites ORDER BY project_id ASC, id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        $candidates = $this->legacyCandidates();

        $result = [
            'sites_scanned' => count($sites),
            'sites_updated' => 0,
            'metrika_ids_restored' => 0,
            'webmaster_ids_restored' => 0,
            'ambiguous_sites' => 0,
        ];

        $update = $this->pdo->prepare(
            'UPDATE project_sites
             SET metrika_counter_ids_json = :metrika,
                 webmaster_host_ids_json = :webmaster,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id'
        );

        foreach ($sites as $site) {
            $host = $this->normalizeHost(
                (string) ($site['host'] ?? $site['url'] ?? '')
            );
            if ($host === '') {
                $this->record('site_skipped', 'site', (int) $site['id'], [
                    'reason' => 'empty_host',
                ]);
                continue;
            }

            $matches = $candidates[$host] ?? [];
            $matches = array_values(array_filter(
                $matches,
                static fn(array $row): bool =>
                    ($row['metrika'] ?? []) !== []
                    || ($row['webmaster'] ?? []) !== []
            ));

            if ($matches === []) {
                $this->record('site_no_legacy_source', 'site', (int) $site['id'], [
                    'host' => $host,
                ]);
                continue;
            }

            $signatures = [];
            foreach ($matches as $match) {
                $signature = json_encode([
                    array_values($match['metrika'] ?? []),
                    array_values($match['webmaster'] ?? []),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $signatures[(string) $signature] = true;
            }
            if (count($signatures) > 1) {
                $result['ambiguous_sites']++;
                $this->record('site_source_ambiguous', 'site', (int) $site['id'], [
                    'host' => $host,
                    'candidate_count' => count($matches),
                    'candidates' => array_map(
                        static fn(array $row): array => [
                            'table' => $row['table'],
                            'row_id' => $row['row_id'],
                            'metrika' => $row['metrika'],
                            'webmaster' => $row['webmaster'],
                        ],
                        $matches
                    ),
                ]);
                continue;
            }

            $legacyMetrika = [];
            $legacyWebmaster = [];
            foreach ($matches as $match) {
                $legacyMetrika = array_merge(
                    $legacyMetrika,
                    $match['metrika'] ?? []
                );
                $legacyWebmaster = array_merge(
                    $legacyWebmaster,
                    $match['webmaster'] ?? []
                );
            }
            $legacyMetrika = $this->uniqueIntegers($legacyMetrika);
            $legacyWebmaster = $this->uniqueStrings($legacyWebmaster);

            $currentMetrika = $this->uniqueIntegers(
                $this->jsonList($site['metrika_counter_ids_json'] ?? null)
            );
            $currentWebmaster = $this->uniqueStrings(
                $this->jsonList($site['webmaster_host_ids_json'] ?? null)
            );
            $mergedMetrika = $this->uniqueIntegers(array_merge(
                $currentMetrika,
                $legacyMetrika
            ));
            $mergedWebmaster = $this->uniqueStrings(array_merge(
                $currentWebmaster,
                $legacyWebmaster
            ));

            if (
                $mergedMetrika === $currentMetrika
                && $mergedWebmaster === $currentWebmaster
            ) {
                $this->record('site_sources_already_current', 'site', (int) $site['id'], [
                    'host' => $host,
                ]);
                continue;
            }

            $update->execute([
                'id' => (int) $site['id'],
                'metrika' => json_encode(
                    $mergedMetrika,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'webmaster' => json_encode(
                    $mergedWebmaster,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ]);

            $result['sites_updated']++;
            $result['metrika_ids_restored'] += count(array_diff(
                $mergedMetrika,
                $currentMetrika
            ));
            $result['webmaster_ids_restored'] += count(array_diff(
                $mergedWebmaster,
                $currentWebmaster
            ));
            $this->record('site_sources_restored', 'site', (int) $site['id'], [
                'host' => $host,
                'before' => [
                    'metrika' => $currentMetrika,
                    'webmaster' => $currentWebmaster,
                ],
                'after' => [
                    'metrika' => $mergedMetrika,
                    'webmaster' => $mergedWebmaster,
                ],
                'matched_tables' => array_values(array_unique(array_column(
                    $matches,
                    'table'
                ))),
            ]);
        }

        return $result;
    }

    private function legacyCandidates(): array
    {
        $tables = ['projects', 'site_monitors', 'monitored_sites'];
        $result = [];

        foreach ($tables as $table) {
            if (!$this->tableExists($table)) {
                continue;
            }
            $columns = $this->columns($table);
            $urlColumn = $this->firstColumn($columns, [
                'site_url', 'url', 'domain', 'host',
            ]);
            if ($urlColumn === null) {
                continue;
            }

            $rows = $this->pdo->query(
                'SELECT * FROM `' . $table . '`'
            )->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $host = $this->normalizeHost((string) ($row[$urlColumn] ?? ''));
                if ($host === '') {
                    continue;
                }
                $candidate = [
                    'table' => $table,
                    'row_id' => (int) ($row['id'] ?? 0),
                    'metrika' => $this->collectValues($row, [
                        'counter_id',
                        'metrika_counter_id',
                        'metrika_counter_ids_json',
                    ], true),
                    'webmaster' => $this->collectValues($row, [
                        'webmaster_host_id',
                        'webmaster_id',
                        'webmaster_host_ids_json',
                    ], false),
                ];
                $result[$host][] = $candidate;
            }
        }

        return $result;
    }

    private function repairDirectProjectLink(): array
    {
        $answer = ['linked' => false, 'ambiguous' => false];
        if (!$this->tableExists('project_source_links')) {
            return $answer;
        }

        $configPath = dirname($this->root, 2) . '/yandex-direct-config.php';
        if (!is_file($configPath)) {
            $this->record('direct_config_missing', 'project', null, []);
            return $answer;
        }

        $loaded = require $configPath;
        $config = is_array($loaded) ? $loaded : [];
        $configured = trim((string) ($config['token'] ?? '')) !== '';
        if (!$configured) {
            $this->record('direct_config_empty', 'project', null, []);
            return $answer;
        }

        $projects = $this->pdo->query(
            'SELECT DISTINCT project_id
             FROM project_client_links
             ORDER BY project_id ASC'
        )->fetchAll(PDO::FETCH_COLUMN);
        $projects = array_values(array_unique(array_filter(array_map(
            'intval',
            $projects
        ), static fn(int $id): bool => $id > 0)));

        if (count($projects) !== 1) {
            $answer['ambiguous'] = count($projects) > 1;
            $this->record('direct_project_ambiguous', 'project', null, [
                'project_ids' => $projects,
            ]);
            return $answer;
        }

        $projectId = $projects[0];
        $clientLogin = trim((string) ($config['client_login'] ?? ''));
        $externalId = $clientLogin !== '' ? $clientLogin : 'default-account';
        $stmt = $this->pdo->prepare(
            'INSERT INTO project_source_links
             (project_id, site_id, source_type, external_id,
              settings_json, status, created_at, updated_at)
             VALUES
             (:project_id, 0, "yandex_direct_account", :external_id,
              :settings_json, "active", NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                settings_json = VALUES(settings_json),
                status = "active",
                updated_at = NOW()'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'external_id' => mb_substr($externalId, 0, 190),
            'settings_json' => json_encode([
                'configured' => true,
                'client_login' => $clientLogin,
                'migrated_by' => '2026.08.03.18',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $answer['linked'] = true;
        $this->record('direct_project_linked', 'project', $projectId, [
            'client_login' => $clientLogin,
        ]);
        return $answer;
    }

    private function collectValues(
        array $row,
        array $keys,
        bool $integers
    ): array {
        $values = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $row)) {
                continue;
            }
            $value = $row[$key];
            if (is_string($value) && str_ends_with($key, '_json')) {
                $value = $this->jsonList($value);
            }
            if (!is_array($value)) {
                $value = [$value];
            }
            $values = array_merge($values, $value);
        }
        return $integers
            ? $this->uniqueIntegers($values)
            : $this->uniqueStrings($values);
    }

    private function normalizeHost(string $value): string
    {
        $value = trim(mb_strtolower($value));
        if ($value === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $value)) {
            $value = 'https://' . ltrim($value, '/');
        }
        $host = (string) parse_url($value, PHP_URL_HOST);
        $host = preg_replace('/^www\./i', '', mb_strtolower($host)) ?? '';
        return trim($host, '.');
    }

    private function jsonList(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }
        if (!is_string($value) || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true);
        return is_array($decoded) ? array_values($decoded) : [];
    }

    private function uniqueIntegers(array $values): array
    {
        $result = array_values(array_unique(array_filter(array_map(
            'intval',
            $values
        ), static fn(int $id): bool => $id > 0)));
        sort($result, SORT_NUMERIC);
        return $result;
    }

    private function uniqueStrings(array $values): array
    {
        $result = array_values(array_unique(array_filter(array_map(
            static fn(mixed $value): string => trim((string) $value),
            $values
        ), static fn(string $value): bool => $value !== '' && $value !== '0')));
        sort($result, SORT_STRING);
        return $result;
    }

    private function ensureAuditTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS portal_recovery_audit (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                release_version VARCHAR(40) NOT NULL,
                event_key VARCHAR(80) NOT NULL,
                entity_type VARCHAR(40) NOT NULL,
                entity_id BIGINT UNSIGNED NULL,
                details_json MEDIUMTEXT NULL,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                KEY idx_portal_recovery_event (release_version, event_key),
                KEY idx_portal_recovery_entity (entity_type, entity_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function record(
        string $event,
        string $entityType,
        ?int $entityId,
        array $details
    ): void {
        $payload = [
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details' => $details,
        ];
        $this->events[] = $payload;

        $stmt = $this->pdo->prepare(
            'INSERT INTO portal_recovery_audit
             (release_version, event_key, entity_type, entity_id, details_json, created_at)
             VALUES
             ("2026.08.03.18", :event_key, :entity_type, :entity_id, :details_json, NOW())'
        );
        $stmt->execute([
            'event_key' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'details_json' => json_encode(
                $details,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);
    }

    private function writeReport(array $report): void
    {
        $directory = $this->root . '/storage/system-audits';
        if (!is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
        file_put_contents(
            $directory . '/portal-recovery-v18-latest.json',
            json_encode(
                $report,
                JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRETTY_PRINT
            ) . PHP_EOL,
            LOCK_EX
        );
    }

    private function firstColumn(array $columns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (isset($columns[$candidate])) {
                return $candidate;
            }
        }
        return null;
    }

    private function columns(string $table): array
    {
        if (isset($this->columns[$table])) {
            return $this->columns[$table];
        }
        $stmt = $this->pdo->query('SHOW COLUMNS FROM `' . $table . '`');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['Field']] = $row;
        }
        return $this->columns[$table] = $result;
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
