<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class MonitoringRepository
{
    public function sitesByProject(int $projectId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT s.*,
                    (
                        SELECT COUNT(*)
                        FROM monitor_incidents i
                        WHERE i.site_id = s.id AND i.status = \'open\'
                    ) AS open_incidents,
                    (
                        SELECT COUNT(*)
                        FROM monitor_events e
                        WHERE e.site_id = s.id
                          AND e.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
                    ) AS events_7d
             FROM monitored_sites s
             WHERE s.project_id = :project_id
             ORDER BY s.is_active DESC, s.name ASC'
        );
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row = $this->normalizeSite($row);
            $row['uptime_30d'] = $this->uptime((int) $row['id'], 30);
        }
        unset($row);

        return $rows;
    }

    public function site(int $id, ?int $projectId = null): ?array
    {
        $sql = 'SELECT * FROM monitored_sites WHERE id = :id';
        $params = ['id' => $id];

        if ($projectId !== null) {
            $sql .= ' AND project_id = :project_id';
            $params['project_id'] = $projectId;
        }

        $sql .= ' LIMIT 1';
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeSite($row) : null;
    }

    public function saveSite(array $data): int
    {
        $id = (int) ($data['id'] ?? 0);
        $projectId = (int) ($data['project_id'] ?? 0);
        $name = trim((string) ($data['name'] ?? ''));
        $baseUrl = trim((string) ($data['base_url'] ?? ''));
        $host = trim((string) ($data['host'] ?? ''));

        if ($projectId <= 0 || $name === '' || $baseUrl === '' || $host === '') {
            throw new RuntimeException('Не заполнены обязательные поля сайта.');
        }

        $params = [
            'project_id' => $projectId,
            'name' => mb_substr($name, 0, 190),
            'base_url' => mb_substr($baseUrl, 0, 1000),
            'host' => mb_substr($host, 0, 255),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'check_interval_minutes' => max(5, min(1440, (int) ($data['check_interval_minutes'] ?? 5))),
            'slow_threshold_ms' => max(500, min(60000, (int) ($data['slow_threshold_ms'] ?? 3000))),
            'notify_email' => !empty($data['notify_email']) ? 1 : 0,
            'notify_telegram' => !empty($data['notify_telegram']) ? 1 : 0,
            'technical_email' => $this->nullableString($data['technical_email'] ?? null, 255),
            'marketing_email' => $this->nullableString($data['marketing_email'] ?? null, 255),
            'technical_telegram_chat' => $this->nullableString($data['technical_telegram_chat'] ?? null, 100),
            'marketing_telegram_chat' => $this->nullableString($data['marketing_telegram_chat'] ?? null, 100),
            'expected_metrika_ids' => $this->normalizeExpectedMetrika($data['expected_metrika_ids'] ?? ''),
        ];

        if ($id > 0) {
            $check = Database::pdo()->prepare(
                'SELECT id FROM monitored_sites WHERE id = :id AND project_id = :project_id'
            );
            $check->execute(['id' => $id, 'project_id' => $projectId]);

            if (!$check->fetchColumn()) {
                throw new RuntimeException('Сайт не найден.');
            }

            $stmt = Database::pdo()->prepare(
                'UPDATE monitored_sites SET
                    name = :name,
                    base_url = :base_url,
                    host = :host,
                    is_active = :is_active,
                    check_interval_minutes = :check_interval_minutes,
                    slow_threshold_ms = :slow_threshold_ms,
                    notify_email = :notify_email,
                    notify_telegram = :notify_telegram,
                    technical_email = :technical_email,
                    marketing_email = :marketing_email,
                    technical_telegram_chat = :technical_telegram_chat,
                    marketing_telegram_chat = :marketing_telegram_chat,
                    expected_metrika_ids = :expected_metrika_ids,
                    updated_at = NOW()
                 WHERE id = :id AND project_id = :project_id'
            );
            $stmt->execute($params + ['id' => $id]);
            return $id;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO monitored_sites
             (project_id, name, base_url, host, is_active,
              check_interval_minutes, slow_threshold_ms,
              notify_email, notify_telegram,
              technical_email, marketing_email,
              technical_telegram_chat, marketing_telegram_chat,
              expected_metrika_ids, created_at, updated_at)
             VALUES
             (:project_id, :name, :base_url, :host, :is_active,
              :check_interval_minutes, :slow_threshold_ms,
              :notify_email, :notify_telegram,
              :technical_email, :marketing_email,
              :technical_telegram_chat, :marketing_telegram_chat,
              :expected_metrika_ids, NOW(), NOW())'
        );
        $stmt->execute($params);

        return (int) Database::pdo()->lastInsertId();
    }

    public function deleteSite(int $id, int $projectId): void
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM monitored_sites WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute(['id' => $id, 'project_id' => $projectId]);
    }

    public function dueSites(): array
    {
        $rows = Database::pdo()->query(
            'SELECT *
             FROM monitored_sites
             WHERE is_active = 1
               AND (
                    last_checked_at IS NULL
                    OR last_checked_at <= DATE_SUB(
                        NOW(),
                        INTERVAL check_interval_minutes MINUTE
                    )
               )
             ORDER BY COALESCE(last_checked_at, \'1970-01-01\') ASC
             LIMIT 100'
        )->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $row): array => $this->normalizeSite($row), $rows);
    }

    public function addAvailability(
        int $siteId,
        bool $isUp,
        ?int $httpCode,
        ?int $responseMs,
        int $attempts,
        ?string $finalUrl,
        ?string $errorText
    ): int {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO monitor_availability_checks
             (site_id, checked_at, is_up, http_code, response_ms,
              attempts, final_url, error_text)
             VALUES
             (:site_id, NOW(), :is_up, :http_code, :response_ms,
              :attempts, :final_url, :error_text)'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'is_up' => $isUp ? 1 : 0,
            'http_code' => $httpCode,
            'response_ms' => $responseMs,
            'attempts' => $attempts,
            'final_url' => $this->nullableString($finalUrl, 1000),
            'error_text' => $this->nullableString($errorText, 5000),
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function updateAvailabilityState(
        int $siteId,
        string $status,
        ?int $httpCode,
        ?int $responseMs,
        int $failures
    ): void {
        $stmt = Database::pdo()->prepare(
            'UPDATE monitored_sites SET
                last_status = :last_status,
                last_http_code = :last_http_code,
                last_response_ms = :last_response_ms,
                consecutive_failures = :consecutive_failures,
                last_checked_at = NOW(),
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $siteId,
            'last_status' => $status,
            'last_http_code' => $httpCode,
            'last_response_ms' => $responseMs,
            'consecutive_failures' => max(0, $failures),
        ]);
    }

    public function saveAudit(int $siteId, array $audit, string $runType): int
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO monitor_audits
             (site_id, run_type, http_code, final_url,
              title, description, h1, h1_count, canonical,
              meta_robots, x_robots_tag, indexing_allowed, indexing_reason,
              robots_status, robots_hash, robots_summary,
              sitemap_url, sitemap_status, sitemap_hash,
              favicon_url, favicon_status,
              metrika_ids_json, webvisor_enabled,
              ssl_valid, ssl_expires_at, ssl_days_left,
              dns_json, dns_hash,
              domain_name, domain_registered_at, domain_expires_at,
              domain_days_left, domain_status,
              summary_json, created_at)
             VALUES
             (:site_id, :run_type, :http_code, :final_url,
              :title, :description, :h1, :h1_count, :canonical,
              :meta_robots, :x_robots_tag, :indexing_allowed, :indexing_reason,
              :robots_status, :robots_hash, :robots_summary,
              :sitemap_url, :sitemap_status, :sitemap_hash,
              :favicon_url, :favicon_status,
              :metrika_ids_json, :webvisor_enabled,
              :ssl_valid, :ssl_expires_at, :ssl_days_left,
              :dns_json, :dns_hash,
              :domain_name, :domain_registered_at, :domain_expires_at,
              :domain_days_left, :domain_status,
              :summary_json, NOW())'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'run_type' => mb_substr($runType, 0, 30),
            'http_code' => $audit['http_code'] ?? null,
            'final_url' => $this->nullableString($audit['final_url'] ?? null, 1000),
            'title' => $audit['title'] ?? null,
            'description' => $audit['description'] ?? null,
            'h1' => $audit['h1'] ?? null,
            'h1_count' => max(0, (int) ($audit['h1_count'] ?? 0)),
            'canonical' => $this->nullableString($audit['canonical'] ?? null, 1000),
            'meta_robots' => $this->nullableString($audit['meta_robots'] ?? null, 500),
            'x_robots_tag' => $this->nullableString($audit['x_robots_tag'] ?? null, 500),
            'indexing_allowed' => !empty($audit['indexing_allowed']) ? 1 : 0,
            'indexing_reason' => $audit['indexing_reason'] ?? null,
            'robots_status' => $audit['robots_status'] ?? null,
            'robots_hash' => $this->nullableString($audit['robots_hash'] ?? null, 64),
            'robots_summary' => $audit['robots_summary'] ?? null,
            'sitemap_url' => $this->nullableString($audit['sitemap_url'] ?? null, 1000),
            'sitemap_status' => $audit['sitemap_status'] ?? null,
            'sitemap_hash' => $this->nullableString($audit['sitemap_hash'] ?? null, 64),
            'favicon_url' => $this->nullableString($audit['favicon_url'] ?? null, 1000),
            'favicon_status' => $audit['favicon_status'] ?? null,
            'metrika_ids_json' => json_encode($audit['metrika_ids'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'webvisor_enabled' => array_key_exists('webvisor_enabled', $audit)
                ? ($audit['webvisor_enabled'] === null ? null : (!empty($audit['webvisor_enabled']) ? 1 : 0))
                : null,
            'ssl_valid' => array_key_exists('ssl_valid', $audit)
                ? ($audit['ssl_valid'] === null ? null : (!empty($audit['ssl_valid']) ? 1 : 0))
                : null,
            'ssl_expires_at' => $this->nullableDateTime($audit['ssl_expires_at'] ?? null),
            'ssl_days_left' => $audit['ssl_days_left'] ?? null,
            'dns_json' => json_encode($audit['dns'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'dns_hash' => $this->nullableString($audit['dns_hash'] ?? null, 64),
            'domain_name' => $this->nullableString($audit['domain_name'] ?? null, 255),
            'domain_registered_at' => $this->nullableDateTime($audit['domain_registered_at'] ?? null),
            'domain_expires_at' => $this->nullableDateTime($audit['domain_expires_at'] ?? null),
            'domain_days_left' => $audit['domain_days_left'] ?? null,
            'domain_status' => $this->nullableString($audit['domain_status'] ?? null, 100),
            'summary_json' => json_encode($audit['summary'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        Database::pdo()->prepare(
            'UPDATE monitored_sites SET last_audit_at = NOW(), updated_at = NOW() WHERE id = :id'
        )->execute(['id' => $siteId]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function latestAudit(int $siteId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM monitor_audits WHERE site_id = :site_id ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['site_id' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeAudit($row) : null;
    }

    public function previousAudit(int $siteId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM monitor_audits WHERE site_id = :site_id ORDER BY id DESC LIMIT 1,1'
        );
        $stmt->execute(['site_id' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->normalizeAudit($row) : null;
    }

    public function openIncident(
        int $siteId,
        string $type,
        string $category,
        string $severity,
        string $title,
        string $details
    ): array {
        $existing = $this->findOpenIncident($siteId, $type);

        if ($existing) {
            Database::pdo()->prepare(
                'UPDATE monitor_incidents SET details = :details, severity = :severity, updated_at = NOW() WHERE id = :id'
            )->execute([
                'id' => $existing['id'],
                'details' => mb_substr($details, 0, 10000),
                'severity' => $severity,
            ]);
            return $this->incident((int) $existing['id']) ?? $existing;
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO monitor_incidents
             (site_id, incident_type, category, severity, status,
              title, details, started_at, created_at, updated_at)
             VALUES
             (:site_id, :incident_type, :category, :severity, \'open\',
              :title, :details, NOW(), NOW(), NOW())'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'incident_type' => mb_substr($type, 0, 80),
            'category' => mb_substr($category, 0, 30),
            'severity' => mb_substr($severity, 0, 20),
            'title' => mb_substr($title, 0, 255),
            'details' => mb_substr($details, 0, 10000),
        ]);

        return $this->incident((int) Database::pdo()->lastInsertId()) ?? [];
    }

    public function resolveIncident(int $siteId, string $type, string $details = ''): ?array
    {
        $incident = $this->findOpenIncident($siteId, $type);
        if (!$incident) {
            return null;
        }

        $stmt = Database::pdo()->prepare(
            'UPDATE monitor_incidents SET
                status = \'resolved\',
                details = CASE WHEN :details <> \'\' THEN CONCAT(COALESCE(details, \'\'), "\n", :details) ELSE details END,
                resolved_at = NOW(),
                duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $incident['id'],
            'details' => mb_substr($details, 0, 10000),
        ]);

        return $this->incident((int) $incident['id']);
    }

    public function findOpenIncident(int $siteId, string $type): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM monitor_incidents
             WHERE site_id = :site_id AND incident_type = :incident_type AND status = \'open\'
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['site_id' => $siteId, 'incident_type' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeIncident($row) : null;
    }

    public function incident(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM monitor_incidents WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeIncident($row) : null;
    }

    public function recordEvent(
        int $siteId,
        string $eventKey,
        string $category,
        string $severity,
        mixed $oldValue,
        mixed $newValue,
        string $message
    ): array {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO monitor_events
             (site_id, event_key, category, severity, old_value, new_value, message, created_at)
             VALUES
             (:site_id, :event_key, :category, :severity, :old_value, :new_value, :message, NOW())'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'event_key' => mb_substr($eventKey, 0, 120),
            'category' => mb_substr($category, 0, 30),
            'severity' => mb_substr($severity, 0, 20),
            'old_value' => $this->encodeValue($oldValue),
            'new_value' => $this->encodeValue($newValue),
            'message' => mb_substr($message, 0, 10000),
        ]);

        return $this->event((int) Database::pdo()->lastInsertId()) ?? [];
    }

    public function event(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT e.*, s.name AS site_name, s.base_url,
                    s.notify_email, s.notify_telegram,
                    s.technical_email, s.marketing_email,
                    s.technical_telegram_chat, s.marketing_telegram_chat
             FROM monitor_events e
             INNER JOIN monitored_sites s ON s.id = e.site_id
             WHERE e.id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->normalizeEvent($row) : null;
    }

    public function markEventNotified(int $id): void
    {
        Database::pdo()->prepare(
            'UPDATE monitor_events SET notified_at = NOW() WHERE id = :id'
        )->execute(['id' => $id]);
    }

    public function addNotificationLog(
        int $eventId,
        string $channel,
        ?string $recipient,
        string $status,
        ?string $error
    ): void {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO monitor_notification_log
             (event_id, channel, recipient, status, error_text, created_at)
             VALUES
             (:event_id, :channel, :recipient, :status, :error_text, NOW())'
        );
        $stmt->execute([
            'event_id' => $eventId,
            'channel' => mb_substr($channel, 0, 30),
            'recipient' => $this->nullableString($recipient, 255),
            'status' => mb_substr($status, 0, 30),
            'error_text' => $this->nullableString($error, 5000),
        ]);
    }

    public function incidents(int $projectId, ?int $siteId = null, int $limit = 200): array
    {
        $sql = 'SELECT i.*, s.name AS site_name, s.base_url
                FROM monitor_incidents i
                INNER JOIN monitored_sites s ON s.id = i.site_id
                WHERE s.project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($siteId !== null && $siteId > 0) {
            $sql .= ' AND i.site_id = :site_id';
            $params['site_id'] = $siteId;
        }
        $sql .= ' ORDER BY (i.status = \'open\') DESC, i.started_at DESC LIMIT ' . max(1, min(1000, $limit));
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->normalizeIncident($row), $rows);
    }

    public function events(int $projectId, ?int $siteId = null, int $limit = 300): array
    {
        $sql = 'SELECT e.*, s.name AS site_name, s.base_url
                FROM monitor_events e
                INNER JOIN monitored_sites s ON s.id = e.site_id
                WHERE s.project_id = :project_id';
        $params = ['project_id' => $projectId];
        if ($siteId !== null && $siteId > 0) {
            $sql .= ' AND e.site_id = :site_id';
            $params['site_id'] = $siteId;
        }
        $sql .= ' ORDER BY e.created_at DESC LIMIT ' . max(1, min(1000, $limit));
        $stmt = Database::pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $row): array => $this->normalizeEvent($row), $rows);
    }

    public function availabilitySeries(int $siteId, int $days = 7): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT id, checked_at, is_up, http_code, response_ms, attempts, error_text
             FROM monitor_availability_checks
             WHERE site_id = :site_id
               AND checked_at >= DATE_SUB(NOW(), INTERVAL :days DAY)
             ORDER BY checked_at ASC
             LIMIT 5000'
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':days', max(1, min(90, $days)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            foreach (['id', 'is_up', 'http_code', 'response_ms', 'attempts'] as $key) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        unset($row);

        return $rows;
    }

    public function uptime(int $siteId, int $days = 30): ?float
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) AS total, SUM(is_up) AS up_count
             FROM monitor_availability_checks
             WHERE site_id = :site_id
               AND checked_at >= DATE_SUB(NOW(), INTERVAL :days DAY)'
        );
        $stmt->bindValue(':site_id', $siteId, PDO::PARAM_INT);
        $stmt->bindValue(':days', max(1, min(365, $days)), PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total = (int) ($row['total'] ?? 0);
        if ($total <= 0) {
            return null;
        }
        return round(((int) ($row['up_count'] ?? 0) / $total) * 100, 3);
    }

    public function dashboard(int $projectId): array
    {
        $sites = $this->sitesByProject($projectId);
        $siteIds = array_column($sites, 'id');
        $openIncidents = 0;
        $up = 0;
        $down = 0;
        $unknown = 0;
        $avgResponse = [];

        foreach ($sites as $site) {
            $openIncidents += (int) ($site['open_incidents'] ?? 0);
            if ($site['last_status'] === 'up') {
                $up++;
            } elseif ($site['last_status'] === 'down') {
                $down++;
            } else {
                $unknown++;
            }
            if ($site['last_response_ms'] !== null) {
                $avgResponse[] = (int) $site['last_response_ms'];
            }
        }

        return [
            'summary' => [
                'sites_count' => count($sites),
                'up_count' => $up,
                'down_count' => $down,
                'unknown_count' => $unknown,
                'open_incidents' => $openIncidents,
                'average_response_ms' => $avgResponse === [] ? null : (int) round(array_sum($avgResponse) / count($avgResponse)),
            ],
            'sites' => $sites,
            'recent_incidents' => $this->incidents($projectId, null, 20),
            'recent_events' => $this->events($projectId, null, 30),
            'worker' => $this->workerStatus(),
        ];
    }

    public function detail(int $siteId, int $projectId): array
    {
        $site = $this->site($siteId, $projectId);
        if (!$site) {
            throw new RuntimeException('Сайт не найден.');
        }

        return [
            'site' => $site,
            'audit' => $this->latestAudit($siteId),
            'availability' => $this->availabilitySeries($siteId, 7),
            'uptime_30d' => $this->uptime($siteId, 30),
            'incidents' => $this->incidents($projectId, $siteId, 100),
            'events' => $this->events($projectId, $siteId, 150),
        ];
    }

    public function setWorkerState(string $key, mixed $value): void
    {
        $stmt = Database::pdo()->prepare(
            'INSERT INTO monitor_worker_state (state_key, state_value, updated_at)
             VALUES (:state_key, :state_value, NOW())
             ON DUPLICATE KEY UPDATE state_value = VALUES(state_value), updated_at = NOW()'
        );
        $stmt->execute([
            'state_key' => mb_substr($key, 0, 100),
            'state_value' => $this->encodeValue($value),
        ]);
    }

    public function workerStatus(): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM monitor_worker_state WHERE state_key = \'heartbeat\' LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$row) {
            return ['status' => 'unknown', 'updated_at' => null, 'details' => null];
        }

        $updated = strtotime((string) $row['updated_at']);
        $status = $updated !== false && $updated >= time() - 900 ? 'ok' : 'stale';
        return [
            'status' => $status,
            'updated_at' => $row['updated_at'],
            'details' => $this->decodeValue($row['state_value']),
        ];
    }

    public function cleanup(): void
    {
        Database::pdo()->exec(
            'DELETE FROM monitor_availability_checks WHERE checked_at < DATE_SUB(NOW(), INTERVAL 180 DAY)'
        );
        Database::pdo()->exec(
            'DELETE FROM monitor_events WHERE created_at < DATE_SUB(NOW(), INTERVAL 730 DAY)'
        );
    }

    private function normalizeSite(array $row): array
    {
        foreach ([
            'id', 'project_id', 'is_active', 'check_interval_minutes',
            'slow_threshold_ms', 'notify_email', 'notify_telegram',
            'last_http_code', 'last_response_ms', 'consecutive_failures',
            'open_incidents', 'events_7d',
        ] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        $row['expected_metrika_ids_array'] = $this->expectedMetrikaArray((string) ($row['expected_metrika_ids'] ?? ''));
        return $row;
    }

    private function normalizeAudit(array $row): array
    {
        foreach ([
            'id', 'site_id', 'http_code', 'h1_count', 'indexing_allowed',
            'robots_status', 'sitemap_status', 'favicon_status',
            'webvisor_enabled', 'ssl_valid', 'ssl_days_left', 'domain_days_left',
        ] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        $row['metrika_ids'] = json_decode((string) ($row['metrika_ids_json'] ?? '[]'), true) ?: [];
        $row['dns'] = json_decode((string) ($row['dns_json'] ?? '[]'), true) ?: [];
        $row['summary'] = json_decode((string) ($row['summary_json'] ?? '[]'), true) ?: [];
        unset($row['metrika_ids_json'], $row['dns_json'], $row['summary_json']);
        return $row;
    }

    private function normalizeIncident(array $row): array
    {
        foreach (['id', 'site_id', 'duration_seconds'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        return $row;
    }

    private function normalizeEvent(array $row): array
    {
        foreach (['id', 'site_id', 'notify_email', 'notify_telegram'] as $key) {
            if (array_key_exists($key, $row)) {
                $row[$key] = $row[$key] === null ? null : (int) $row[$key];
            }
        }
        $row['old_value_decoded'] = $this->decodeValue($row['old_value'] ?? null);
        $row['new_value_decoded'] = $this->decodeValue($row['new_value'] ?? null);
        return $row;
    }

    private function nullableString(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private function nullableDateTime(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeExpectedMetrika(mixed $value): ?string
    {
        $ids = $this->expectedMetrikaArray((string) $value);
        return $ids === [] ? null : implode(',', $ids);
    }

    private function expectedMetrikaArray(string $value): array
    {
        preg_match_all('/\d{4,20}/', $value, $matches);
        $ids = array_values(array_unique($matches[0] ?? []));
        sort($ids, SORT_NATURAL);
        return $ids;
    }

    private function encodeValue(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value)) {
            return mb_substr($value, 0, 50000);
        }
        $encoded = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? mb_substr($encoded, 0, 50000) : null;
    }

    private function decodeValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return null;
        }
        $decoded = json_decode((string) $value, true);
        return json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
    }
}
