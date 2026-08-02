<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class ProjectSourceService
{
    public function __construct(
        private readonly PortalAccessService $access = new PortalAccessService(),
        private readonly PortalContextService $contextService = new PortalContextService()
    ) {
    }

    public function context(bool $persist = false): array
    {
        $context = $this->contextService->context(null, null, $persist);
        $projectId = (int) ($context['selected_project_id'] ?? 0);
        if ($projectId <= 0) {
            throw new RuntimeException('Выберите доступный проект.');
        }

        $sites = array_values(array_filter(
            $context['sites'] ?? [],
            static fn(array $site): bool =>
                (string) ($site['status'] ?? 'active') !== 'archived'
        ));

        return $context + [
            'source_manifest' => $this->sourceManifest($projectId, $sites),
            'legacy_projects' => $this->legacyProjects(
                $context['selected_project'] ?? [],
                $sites
            ),
            'report_scopes' => $this->reportScopes($projectId),
            'goal_scopes' => $this->goalScopes($projectId),
        ];
    }

    public function selectedLegacyProject(): array
    {
        $context = $this->context(false);
        $projects = $context['legacy_projects'] ?? [];
        if ($projects !== []) {
            return $projects[0];
        }

        $project = $context['selected_project'] ?? null;
        if (!is_array($project) || (int) ($project['id'] ?? 0) <= 0) {
            throw new RuntimeException('Выбранный проект не найден.');
        }
        $project['project_sites'] = $context['sites'] ?? [];
        $project['site_ids'] = $context['site_ids'] ?? [];
        $project['__portal_context'] = true;
        return $project;
    }

    public function legacyProjects(array $project, array $sites): array
    {
        $result = [];
        $seenCounters = [];
        $seenHosts = [];

        foreach ($sites as $site) {
            if ((string) ($site['status'] ?? 'active') !== 'active') {
                continue;
            }
            $counters = $this->idList(
                $site['metrika_counter_ids']
                    ?? $site['metrika_counter_ids_json']
                    ?? []
            );
            $hosts = $this->stringList(
                $site['webmaster_host_ids']
                    ?? $site['webmaster_host_ids_json']
                    ?? []
            );

            $counterId = 0;
            foreach ($counters as $candidate) {
                if (!isset($seenCounters[$candidate])) {
                    $counterId = $candidate;
                    $seenCounters[$candidate] = true;
                    break;
                }
            }

            $hostId = '';
            foreach ($hosts as $candidate) {
                if (!isset($seenHosts[$candidate])) {
                    $hostId = $candidate;
                    $seenHosts[$candidate] = true;
                    break;
                }
            }

            $legacy = $project;
            $legacy['site_id'] = (int) ($site['id'] ?? 0);
            $legacy['site_name'] = (string) ($site['name'] ?? '');
            $legacy['site_url'] = (string) ($site['url'] ?? '');
            $legacy['host'] = (string) ($site['host'] ?? '');
            $legacy['counter_id'] = $counterId;
            $legacy['webmaster_host_id'] = $hostId;
            $legacy['host_id'] = $hostId;
            $legacy['metrika_counter_ids'] = $counters;
            $legacy['webmaster_host_ids'] = $hosts;
            $legacy['project_sites'] = $sites;
            $legacy['site_ids'] = array_values(array_map(
                static fn(array $row): int => (int) ($row['id'] ?? 0),
                $sites
            ));
            $legacy['__portal_context'] = true;
            $result[] = $legacy;
        }

        return $result;
    }

    public function saveSource(array $data): int
    {
        $user = $this->access->requireRoles([
            'administrator', 'moderator', 'manager',
        ]);
        $projectId = (int) ($data['project_id'] ?? 0);
        $this->contextService->requireProject($projectId, $user);

        $siteId = max(0, (int) ($data['site_id'] ?? 0));
        if ($siteId > 0) {
            $allowed = array_column(
                $this->contextService->sitesForProject($projectId, $user),
                'id'
            );
            if (!in_array($siteId, array_map('intval', $allowed), true)) {
                throw new PortalContextDeniedException(
                    'Сайт не относится к выбранному проекту.'
                );
            }
        }

        $sourceType = trim((string) ($data['source_type'] ?? ''));
        $externalId = trim((string) ($data['external_id'] ?? ''));
        if ($sourceType === '' || $externalId === '') {
            throw new RuntimeException('Укажите тип и внешний ID источника.');
        }

        $settings = $data['settings'] ?? [];
        if (!is_array($settings)) {
            $settings = [];
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO project_source_links
             (project_id, site_id, source_type, external_id,
              settings_json, status, created_at, updated_at)
             VALUES
             (:project_id, :site_id, :source_type, :external_id,
              :settings_json, :status, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                settings_json = VALUES(settings_json),
                status = VALUES(status),
                updated_at = NOW()'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'site_id' => $siteId,
            'source_type' => mb_substr($sourceType, 0, 50),
            'external_id' => mb_substr($externalId, 0, 190),
            'settings_json' => json_encode(
                $settings,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'status' => in_array(
                (string) ($data['status'] ?? 'active'),
                ['active', 'paused', 'archived'],
                true
            ) ? (string) $data['status'] : 'active',
        ]);

        $id = (int) Database::pdo()->lastInsertId();
        if ($id > 0) {
            return $id;
        }
        $find = Database::pdo()->prepare(
            'SELECT id FROM project_source_links
             WHERE project_id = :project_id
               AND site_id = :site_id
               AND source_type = :source_type
               AND external_id = :external_id
             LIMIT 1'
        );
        $find->execute([
            'project_id' => $projectId,
            'site_id' => $siteId,
            'source_type' => $sourceType,
            'external_id' => $externalId,
        ]);
        return (int) $find->fetchColumn();
    }

    public function saveReportScope(int $reportId, int $projectId, array $siteIds): void
    {
        $user = $this->access->requireRoles([
            'administrator', 'moderator', 'manager',
        ]);
        $this->contextService->requireProject($projectId, $user);
        $allowedSites = array_map(
            'intval',
            array_column(
                $this->contextService->sitesForProject($projectId, $user),
                'id'
            )
        );
        $siteIds = array_values(array_unique(array_filter(array_map(
            'intval',
            $siteIds
        ))));
        foreach ($siteIds as $siteId) {
            if (!in_array($siteId, $allowedSites, true)) {
                throw new PortalContextDeniedException(
                    'В область отчёта передан сайт другого проекта.'
                );
            }
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'DELETE FROM report_site_links WHERE report_id = :report_id'
            )->execute(['report_id' => $reportId]);
            $insert = $pdo->prepare(
                'INSERT INTO report_site_links
                 (report_id, project_id, site_id, created_at)
                 VALUES (:report_id, :project_id, :site_id, NOW())'
            );
            foreach ($siteIds as $siteId) {
                $insert->execute([
                    'report_id' => $reportId,
                    'project_id' => $projectId,
                    'site_id' => $siteId,
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public function saveGoalScope(int $goalId, int $projectId, ?int $siteId): void
    {
        $user = $this->access->requireRoles([
            'administrator', 'moderator', 'manager',
        ]);
        $this->contextService->requireProject($projectId, $user);
        if ($siteId !== null && $siteId > 0) {
            $allowed = array_map(
                'intval',
                array_column(
                    $this->contextService->sitesForProject($projectId, $user),
                    'id'
                )
            );
            if (!in_array($siteId, $allowed, true)) {
                throw new PortalContextDeniedException(
                    'Цель нельзя привязать к сайту другого проекта.'
                );
            }
        }
        $stmt = Database::pdo()->prepare(
            'UPDATE conversion_goals
             SET site_id = :site_id,
                 scope_type = :scope_type,
                 updated_at = NOW()
             WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute([
            'id' => $goalId,
            'project_id' => $projectId,
            'site_id' => $siteId !== null && $siteId > 0 ? $siteId : null,
            'scope_type' => $siteId !== null && $siteId > 0
                ? 'site'
                : 'project',
        ]);
    }

    private function sourceManifest(int $projectId, array $sites): array
    {
        $rows = [];
        if ($this->tableExists('project_source_links')) {
            $stmt = Database::pdo()->prepare(
                'SELECT * FROM project_source_links
                 WHERE project_id = :project_id
                   AND status <> "archived"
                 ORDER BY site_id ASC, source_type ASC, id ASC'
            );
            $stmt->execute(['project_id' => $projectId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $result = [];
        foreach ($sites as $site) {
            $siteId = (int) ($site['id'] ?? 0);
            $metrika = $this->idList(
                $site['metrika_counter_ids']
                    ?? $site['metrika_counter_ids_json']
                    ?? []
            );
            $webmaster = $this->stringList(
                $site['webmaster_host_ids']
                    ?? $site['webmaster_host_ids_json']
                    ?? []
            );
            $result[] = [
                'site_id' => $siteId,
                'site_name' => (string) ($site['name'] ?? ''),
                'site_url' => (string) ($site['url'] ?? ''),
                'metrika_counter_ids' => $metrika,
                'webmaster_host_ids' => $webmaster,
                'sources' => array_values(array_filter(
                    $rows,
                    static fn(array $row): bool =>
                        (int) ($row['site_id'] ?? 0) === $siteId
                        || (int) ($row['site_id'] ?? 0) === 0
                )),
                'ready' => $metrika !== [] || $webmaster !== [],
            ];
        }
        return $result;
    }

    private function reportScopes(int $projectId): array
    {
        if (!$this->tableExists('report_site_links')) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT report_id, project_id, site_id
             FROM report_site_links
             WHERE project_id = :project_id
             ORDER BY report_id ASC, site_id ASC'
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function goalScopes(int $projectId): array
    {
        if (!$this->tableExists('conversion_goals')
            || !$this->columnExists('conversion_goals', 'scope_type')) {
            return [];
        }
        $stmt = Database::pdo()->prepare(
            'SELECT id, project_id, site_id, scope_type,
                    source_system, external_id, name, classification, active
             FROM conversion_goals
             WHERE project_id = :project_id
             ORDER BY active DESC, name ASC, id ASC'
        );
        $stmt->execute(['project_id' => $projectId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function idList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            'intval',
            $this->jsonList($value)
        ), static fn(int $id): bool => $id > 0)));
    }

    private function stringList(mixed $value): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => trim((string) $item),
            $this->jsonList($value)
        ), static fn(string $item): bool => $item !== '')));
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

    private function tableExists(string $table): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name'
        );
        $stmt->execute(['table_name' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function columnExists(string $table, string $column): bool
    {
        $stmt = Database::pdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = :table_name
               AND COLUMN_NAME = :column_name'
        );
        $stmt->execute([
            'table_name' => $table,
            'column_name' => $column,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }
}
