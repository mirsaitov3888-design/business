<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use DateTimeImmutable;
use SeoAnalytics\Core\Database;

final class SiteMonitorRepository
{
    public function ensureForProject(array $project): array
    {
        $projectId = (int) ($project['id'] ?? 0);
        $url = trim((string) ($project['site_url'] ?? ''));

        $stmt = Database::pdo()->prepare(
            'INSERT INTO site_monitors
             (project_id, url, interval_minutes, timeout_seconds,
              slow_threshold_ms, enabled, created_at, updated_at)
             VALUES
             (:project_id, :url, 5, 15, 3000, 1, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                url = VALUES(url),
                updated_at = NOW()'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'url' => $url,
        ]);

        return $this->findByProject($projectId)
            ?? throw new \RuntimeException('Не удалось создать монитор сайта.');
    }

    public function findByProject(int $projectId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM site_monitors
             WHERE project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute(['project_id' => $projectId]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function recordCheck(int $monitorId, array $check): int
    {
        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $monitorStmt = $pdo->prepare(
                'SELECT * FROM site_monitors WHERE id = :id FOR UPDATE'
            );
            $monitorStmt->execute(['id' => $monitorId]);
            $monitor = $monitorStmt->fetch();

            if (!$monitor) {
                throw new \RuntimeException('Монитор сайта не найден.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO site_monitor_checks
                 (monitor_id, checked_at, status, http_code, response_ms,
                  final_url, error_message, ssl_expires_at, created_at)
                 VALUES
                 (:monitor_id, NOW(), :status, :http_code, :response_ms,
                  :final_url, :error_message, :ssl_expires_at, NOW())'
            );
            $stmt->execute([
                'monitor_id' => $monitorId,
                'status' => $check['status'],
                'http_code' => $check['http_code'],
                'response_ms' => $check['response_ms'],
                'final_url' => $check['final_url'],
                'error_message' => $check['error_message'],
                'ssl_expires_at' => $check['ssl_expires_at'],
            ]);
            $checkId = (int) $pdo->lastInsertId();

            $stmt = $pdo->prepare(
                'UPDATE site_monitors SET
                    current_status = :status,
                    last_checked_at = NOW(),
                    last_http_code = :http_code,
                    last_response_ms = :response_ms,
                    ssl_expires_at = :ssl_expires_at,
                    last_error = :last_error,
                    updated_at = NOW()
                 WHERE id = :id'
            );
            $stmt->execute([
                'status' => $check['status'],
                'http_code' => $check['http_code'],
                'response_ms' => $check['response_ms'],
                'ssl_expires_at' => $check['ssl_expires_at'],
                'last_error' => $check['error_message'],
                'id' => $monitorId,
            ]);

            $incidentStmt = $pdo->prepare(
                'SELECT *
                 FROM site_monitor_incidents
                 WHERE monitor_id = :monitor_id
                   AND status = "open"
                 ORDER BY id DESC
                 LIMIT 1
                 FOR UPDATE'
            );
            $incidentStmt->execute(['monitor_id' => $monitorId]);
            $incident = $incidentStmt->fetch();

            if ($check['status'] === 'down') {
                if ($incident) {
                    $stmt = $pdo->prepare(
                        'UPDATE site_monitor_incidents SET
                            last_error = :last_error,
                            failed_checks = failed_checks + 1,
                            updated_at = NOW()
                         WHERE id = :id'
                    );
                    $stmt->execute([
                        'last_error' => $check['error_message'],
                        'id' => $incident['id'],
                    ]);
                } else {
                    $stmt = $pdo->prepare(
                        'INSERT INTO site_monitor_incidents
                         (monitor_id, status, started_at, first_error,
                          last_error, failed_checks, created_at, updated_at)
                         VALUES
                         (:monitor_id, "open", NOW(), :first_error,
                          :last_error, 1, NOW(), NOW())'
                    );
                    $stmt->execute([
                        'monitor_id' => $monitorId,
                        'first_error' => $check['error_message'],
                        'last_error' => $check['error_message'],
                    ]);
                }
            } elseif ($incident) {
                $stmt = $pdo->prepare(
                    'UPDATE site_monitor_incidents SET
                        status = "resolved",
                        ended_at = NOW(),
                        duration_seconds = TIMESTAMPDIFF(SECOND, started_at, NOW()),
                        updated_at = NOW()
                     WHERE id = :id'
                );
                $stmt->execute(['id' => $incident['id']]);
            }

            $pdo->commit();

            return $checkId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function dashboard(array $project, string $dateFrom, string $dateTo): array
    {
        $monitor = $this->ensureForProject($project);
        $monitorId = (int) $monitor['id'];
        $dateToExclusive = (new DateTimeImmutable($dateTo))
            ->modify('+1 day')
            ->format('Y-m-d');

        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM site_monitor_checks
             WHERE monitor_id = :monitor_id
               AND checked_at >= :date_from
               AND checked_at < :date_to
             ORDER BY checked_at ASC
             LIMIT 10000'
        );
        $stmt->execute([
            'monitor_id' => $monitorId,
            'date_from' => $dateFrom,
            'date_to' => $dateToExclusive,
        ]);
        $checks = $stmt->fetchAll();

        $responseValues = [];
        $up = 0;
        $degraded = 0;
        $down = 0;

        foreach ($checks as &$check) {
            $check['id'] = (int) $check['id'];
            $check['http_code'] = $check['http_code'] === null
                ? null
                : (int) $check['http_code'];
            $check['response_ms'] = $check['response_ms'] === null
                ? null
                : (int) $check['response_ms'];

            if ($check['response_ms'] !== null) {
                $responseValues[] = $check['response_ms'];
            }

            if ($check['status'] === 'up') {
                $up++;
            } elseif ($check['status'] === 'degraded') {
                $degraded++;
            } else {
                $down++;
            }
        }
        unset($check);

        sort($responseValues);
        $count = count($checks);
        $available = $up + $degraded;
        $p95 = null;

        if ($responseValues !== []) {
            $index = (int) ceil(count($responseValues) * 0.95) - 1;
            $p95 = $responseValues[max(0, min($index, count($responseValues) - 1))];
        }

        $stmt = Database::pdo()->prepare(
            'SELECT *,
                CASE
                    WHEN status = "open"
                    THEN TIMESTAMPDIFF(SECOND, started_at, NOW())
                    ELSE duration_seconds
                END AS effective_duration_seconds
             FROM site_monitor_incidents
             WHERE monitor_id = :monitor_id
               AND started_at < :date_to
               AND (ended_at IS NULL OR ended_at >= :date_from)
             ORDER BY started_at DESC
             LIMIT 100'
        );
        $stmt->execute([
            'monitor_id' => $monitorId,
            'date_from' => $dateFrom,
            'date_to' => $dateToExclusive,
        ]);
        $incidents = $stmt->fetchAll();

        $totalDowntime = 0;
        $longestIncident = 0;

        foreach ($incidents as &$incident) {
            $incident['id'] = (int) $incident['id'];
            $incident['failed_checks'] = (int) $incident['failed_checks'];
            $incident['effective_duration_seconds'] = (int) (
                $incident['effective_duration_seconds'] ?? 0
            );
            $totalDowntime += $incident['effective_duration_seconds'];
            $longestIncident = max(
                $longestIncident,
                $incident['effective_duration_seconds']
            );
        }
        unset($incident);

        $sslDays = null;

        if (!empty($monitor['ssl_expires_at'])) {
            $sslDays = (int) floor(
                ((new DateTimeImmutable((string) $monitor['ssl_expires_at']))->getTimestamp()
                - time()) / 86400
            );
        }

        return [
            'monitor' => [
                'id' => $monitorId,
                'url' => $monitor['url'],
                'current_status' => $monitor['current_status'],
                'last_checked_at' => $monitor['last_checked_at'],
                'last_http_code' => $monitor['last_http_code'] === null
                    ? null
                    : (int) $monitor['last_http_code'],
                'last_response_ms' => $monitor['last_response_ms'] === null
                    ? null
                    : (int) $monitor['last_response_ms'],
                'ssl_expires_at' => $monitor['ssl_expires_at'],
                'ssl_days_remaining' => $sslDays,
                'last_error' => $monitor['last_error'],
                'interval_minutes' => (int) $monitor['interval_minutes'],
                'slow_threshold_ms' => (int) $monitor['slow_threshold_ms'],
            ],
            'summary' => [
                'checks' => $count,
                'up_checks' => $up,
                'degraded_checks' => $degraded,
                'down_checks' => $down,
                'uptime_percent' => $count > 0
                    ? round(($available / $count) * 100, 4)
                    : null,
                'average_response_ms' => $responseValues !== []
                    ? (int) round(array_sum($responseValues) / count($responseValues))
                    : null,
                'p95_response_ms' => $p95,
                'incident_count' => count($incidents),
                'total_downtime_seconds' => $totalDowntime,
                'longest_incident_seconds' => $longestIncident,
            ],
            'history' => array_slice($checks, -500),
            'incidents' => $incidents,
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
            ],
        ];
    }
}
