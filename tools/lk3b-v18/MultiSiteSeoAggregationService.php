<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use Closure;
use SeoAnalytics\Core\Database;

final class MultiSiteSeoAggregationService
{
    private readonly Closure $builder;

    public function __construct(
        ?callable $builder = null,
        private readonly ProjectSourceService $sources = new ProjectSourceService()
    ) {
        $this->builder = $builder instanceof Closure
            ? $builder
            : Closure::fromCallable(
                $builder
                    ?? static fn(
                        array $project,
                        string $dateFrom,
                        string $dateTo,
                        ?string $comparisonDateFrom,
                        ?string $comparisonDateTo
                    ): array => (new SeoAutoFillService())->build(
                        $project,
                        $dateFrom,
                        $dateTo,
                        $comparisonDateFrom,
                        $comparisonDateTo
                    )
            );
    }

    public function build(
        string $dateFrom,
        string $dateTo,
        ?string $comparisonDateFrom = null,
        ?string $comparisonDateTo = null
    ): array {
        @set_time_limit(300);

        $context = $this->sources->context(false);
        $project = $context['selected_project'] ?? [];
        $projectId = (int) ($context['selected_project_id'] ?? 0);
        $clientId = (int) ($context['selected_client_id'] ?? 0);
        $manifest = is_array($context['source_manifest'] ?? null)
            ? $context['source_manifest']
            : [];
        $units = $this->sourceUnits($project, $manifest);
        $results = [];
        $failures = [];

        foreach ($units as $unit) {
            try {
                $data = ($this->builder)(
                    $unit['project'],
                    $dateFrom,
                    $dateTo,
                    $comparisonDateFrom,
                    $comparisonDateTo
                );
                $results[] = [
                    'unit' => $unit,
                    'data' => is_array($data) ? $data : [],
                    'status' => $this->unitHasData($unit, $data)
                        ? 'success'
                        : 'empty',
                ];
                $this->saveSnapshot(
                    $clientId,
                    $projectId,
                    $unit,
                    $dateFrom,
                    $dateTo,
                    $comparisonDateFrom,
                    $comparisonDateTo,
                    'success',
                    is_array($data) ? $data : [],
                    null
                );
            } catch (\Throwable $exception) {
                $failure = [
                    'unit' => $unit,
                    'message' => $exception->getMessage(),
                ];
                $failures[] = $failure;
                $this->saveSnapshot(
                    $clientId,
                    $projectId,
                    $unit,
                    $dateFrom,
                    $dateTo,
                    $comparisonDateFrom,
                    $comparisonDateTo,
                    'failed',
                    [],
                    $exception->getMessage()
                );
            }
        }

        $aggregate = $this->aggregate($results);
        $successful = count(array_filter(
            $results,
            static fn(array $result): bool =>
                ($result['status'] ?? '') === 'success'
        ));
        $status = $successful === 0
            ? 'failed'
            : (($failures !== [] || $successful < count($units))
                ? 'partial'
                : 'complete');

        return $aggregate + [
            'aggregation_status' => $status,
            'project' => [
                'client_id' => $clientId,
                'project_id' => $projectId,
                'name' => (string) (
                    $project['name']
                    ?? $project['title']
                    ?? ('Проект #' . $projectId)
                ),
            ],
            'source_units' => array_map(
                fn(array $result): array => $this->publicUnit(
                    $result['unit'],
                    (string) ($result['status'] ?? 'empty'),
                    $result['data']['warnings'] ?? []
                ),
                $results
            ),
            'failed_units' => array_map(
                fn(array $failure): array => $this->publicUnit(
                    $failure['unit'],
                    'failed',
                    [$failure['message']]
                ),
                $failures
            ),
            'sites' => $this->siteDetails($results, $failures),
            'source_manifest' => $manifest,
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'comparison_date_from' => $comparisonDateFrom,
                'comparison_date_to' => $comparisonDateTo,
            ],
        ];
    }

    public function sourceUnits(array $project, array $manifest): array
    {
        $units = [];
        $counterIndex = [];
        $hostIndex = [];

        foreach ($manifest as $site) {
            $siteId = (int) ($site['site_id'] ?? 0);
            $siteName = (string) ($site['site_name'] ?? '');
            $siteUrl = (string) ($site['site_url'] ?? '');
            $counters = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array($site['metrika_counter_ids'] ?? null)
                    ? $site['metrika_counter_ids']
                    : []
            ))));
            $hosts = array_values(array_unique(array_filter(array_map(
                static fn(mixed $value): string => trim((string) $value),
                is_array($site['webmaster_host_ids'] ?? null)
                    ? $site['webmaster_host_ids']
                    : []
            ))));

            foreach ($counters as $counterId) {
                if (isset($counterIndex[$counterId])) {
                    $index = $counterIndex[$counterId];
                    $units[$index]['owner_site_ids'][] = $siteId;
                    $units[$index]['owner_site_names'][] = $siteName;
                    continue;
                }
                $hostId = '';
                foreach ($hosts as $candidate) {
                    if (!isset($hostIndex[$candidate])) {
                        $hostId = $candidate;
                        break;
                    }
                }
                $unit = $this->unit(
                    $project,
                    $siteId,
                    $siteName,
                    $siteUrl,
                    $counterId,
                    $hostId
                );
                $units[] = $unit;
                $index = array_key_last($units);
                $counterIndex[$counterId] = $index;
                if ($hostId !== '') {
                    $hostIndex[$hostId] = $index;
                }
            }

            foreach ($hosts as $hostId) {
                if (isset($hostIndex[$hostId])) {
                    $index = $hostIndex[$hostId];
                    if (!in_array(
                        $siteId,
                        $units[$index]['owner_site_ids'],
                        true
                    )) {
                        $units[$index]['owner_site_ids'][] = $siteId;
                        $units[$index]['owner_site_names'][] = $siteName;
                    }
                    continue;
                }
                $unit = $this->unit(
                    $project,
                    $siteId,
                    $siteName,
                    $siteUrl,
                    0,
                    $hostId
                );
                $units[] = $unit;
                $hostIndex[$hostId] = array_key_last($units);
            }
        }

        return array_values(array_map(
            static function (array $unit): array {
                $unit['owner_site_ids'] = array_values(array_unique(array_filter(
                    array_map('intval', $unit['owner_site_ids'])
                )));
                $unit['owner_site_names'] = array_values(array_unique(array_filter(
                    array_map('strval', $unit['owner_site_names'])
                )));
                return $unit;
            },
            $units
        ));
    }

    private function unit(
        array $project,
        int $siteId,
        string $siteName,
        string $siteUrl,
        int $counterId,
        string $hostId
    ): array {
        $virtual = $project;
        $virtual['site_id'] = $siteId;
        $virtual['site_name'] = $siteName;
        $virtual['site_url'] = $siteUrl;
        $virtual['counter_id'] = $counterId;
        $virtual['webmaster_host_id'] = $hostId;
        $virtual['host_id'] = $hostId;
        $virtual['__multisite_source'] = true;

        return [
            'site_id' => $siteId,
            'site_name' => $siteName,
            'site_url' => $siteUrl,
            'counter_id' => $counterId,
            'webmaster_host_id' => $hostId,
            'owner_site_ids' => [$siteId],
            'owner_site_names' => [$siteName],
            'project' => $virtual,
        ];
    }

    private function aggregate(array $results): array
    {
        $currentMetrics = [];
        $comparisonMetrics = [];
        $trend = [];
        $sources = [];
        $pages = [];
        $queries = [];
        $issues = [];
        $warnings = [];
        $quality = [];
        $sourcesUsed = [];
        $goalIds = [];

        foreach ($results as $index => $result) {
            $unit = $result['unit'];
            $data = $result['data'];
            $prefix = $this->unitLabel($unit);
            $hasMetrika = (int) ($unit['counter_id'] ?? 0) > 0;
            $hasWebmaster = trim((string) (
                $unit['webmaster_host_id'] ?? ''
            )) !== '';

            if ($hasMetrika) {
                $this->mergeMetricSet(
                    $currentMetrics,
                    $data['metrics'] ?? [],
                    'metrika'
                );
                $this->mergeMetricSet(
                    $comparisonMetrics,
                    $data['comparison'] ?? [],
                    'metrika'
                );
                $this->mergeTrend($trend, $data['trend'] ?? []);
                $this->mergeSources($sources, $data['sources'] ?? []);
                $this->mergePages($pages, $data['pages'] ?? [], $unit);
            }
            if ($hasWebmaster) {
                $this->mergeMetricSet(
                    $currentMetrics,
                    $data['metrics'] ?? [],
                    'webmaster'
                );
                $this->mergeMetricSet(
                    $comparisonMetrics,
                    $data['comparison'] ?? [],
                    'webmaster'
                );
                $this->mergeQueries($queries, $data['queries'] ?? [], $unit);
                $this->mergeIssues($issues, $data['issues'] ?? [], $unit);
            }

            foreach (($data['warnings'] ?? []) as $warning) {
                $warnings[] = $prefix . ': ' . (string) $warning;
            }
            foreach (($data['sources_used'] ?? []) as $source) {
                $sourcesUsed[] = (string) $source;
            }
            foreach (($data['goal_ids'] ?? []) as $goalId) {
                $goalIds[] = (int) $goalId;
            }
            $quality[$prefix . '#' . $index] = $data['quality'] ?? [];
        }

        $this->finalizeMetricSet($currentMetrics);
        $this->finalizeMetricSet($comparisonMetrics);
        $this->finalizeSources($sources);
        $this->finalizeQueries($queries);

        return [
            'metrics' => $currentMetrics,
            'comparison' => $comparisonMetrics,
            'trend' => array_values($trend),
            'sources' => array_values($sources),
            'pages' => array_slice(array_values($pages), 0, 500),
            'queries' => array_slice(array_values($queries), 0, 500),
            'issues' => $issues !== [] ? $issues : [[
                'severity' => 'ok',
                'title' => 'Активные проблемы не найдены',
                'comment' => 'Источники выбранного проекта не вернули проблем.',
            ]],
            'warnings' => array_values(array_unique($warnings)),
            'quality' => $quality,
            'sources_used' => array_values(array_unique($sourcesUsed)),
            'goal_ids' => array_values(array_unique(array_filter($goalIds))),
        ];
    }

    private function mergeMetricSet(
        array &$target,
        array $metrics,
        string $sourceType
    ): void {
        $allowed = $sourceType === 'metrika'
            ? ['organic_visits', 'organic_users', 'bounce_rate', 'leads']
            : [
                'search_impressions', 'search_clicks', 'avg_position',
                'pages_in_search', 'excluded_pages', 'sqi',
            ];

        foreach ($allowed as $key) {
            $value = $this->number($metrics[$key] ?? null);
            if ($value === null) {
                continue;
            }
            if ($key === 'bounce_rate') {
                $weight = max(0.0, $this->number(
                    $metrics['organic_visits'] ?? null
                ) ?? 0.0);
                $target['__bounce_numerator'] =
                    ($target['__bounce_numerator'] ?? 0.0)
                    + ($value * $weight);
                $target['__bounce_weight'] =
                    ($target['__bounce_weight'] ?? 0.0) + $weight;
                continue;
            }
            if ($key === 'avg_position') {
                $weight = max(0.0, $this->number(
                    $metrics['search_impressions'] ?? null
                ) ?? 0.0);
                $target['__position_numerator'] =
                    ($target['__position_numerator'] ?? 0.0)
                    + ($value * $weight);
                $target['__position_weight'] =
                    ($target['__position_weight'] ?? 0.0) + $weight;
                continue;
            }
            if ($key === 'sqi') {
                $target['__sqi_values'][] = $value;
                continue;
            }
            $target[$key] = ($target[$key] ?? 0.0) + $value;
        }
    }

    private function finalizeMetricSet(array &$metrics): void
    {
        $metrics['bounce_rate'] = ($metrics['__bounce_weight'] ?? 0.0) > 0
            ? ($metrics['__bounce_numerator'] / $metrics['__bounce_weight'])
            : null;
        $metrics['avg_position'] = ($metrics['__position_weight'] ?? 0.0) > 0
            ? ($metrics['__position_numerator'] / $metrics['__position_weight'])
            : null;
        $metrics['sqi'] = null;
        unset(
            $metrics['__bounce_numerator'],
            $metrics['__bounce_weight'],
            $metrics['__position_numerator'],
            $metrics['__position_weight'],
            $metrics['__sqi_values']
        );

        foreach ([
            'organic_visits', 'organic_users', 'search_impressions',
            'search_clicks', 'bounce_rate', 'leads', 'avg_position',
            'pages_in_search', 'excluded_pages', 'sqi',
        ] as $key) {
            $metrics[$key] ??= null;
        }
    }

    private function mergeTrend(array &$target, array $rows): void
    {
        foreach ($rows as $row) {
            $label = trim((string) ($row['label'] ?? ''));
            if ($label === '') {
                continue;
            }
            $target[$label] ??= [
                'label' => $label,
                'visits_current' => 0.0,
                'visits_previous' => null,
                'users_current' => 0.0,
                'users_previous' => null,
                'leads_current' => null,
                'leads_previous' => null,
            ];
            foreach ([
                'visits_current', 'users_current', 'leads_current',
                'visits_previous', 'users_previous', 'leads_previous',
            ] as $key) {
                $value = $this->number($row[$key] ?? null);
                if ($value === null) {
                    continue;
                }
                $target[$label][$key] =
                    ($target[$label][$key] ?? 0.0) + $value;
            }
        }
    }

    private function mergeSources(array &$target, array $rows): void
    {
        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $key = mb_strtolower($name);
            $target[$key] ??= [
                'name' => $name,
                'visits' => 0.0,
                'users' => 0.0,
                'leads' => null,
                'bounce_rate' => null,
                'previous_visits' => null,
                'previous_users' => null,
                'previous_leads' => null,
                '__bounce_numerator' => 0.0,
                '__bounce_weight' => 0.0,
            ];
            foreach ([
                'visits', 'users', 'leads',
                'previous_visits', 'previous_users', 'previous_leads',
            ] as $field) {
                $value = $this->number($row[$field] ?? null);
                if ($value !== null) {
                    $target[$key][$field] =
                        ($target[$key][$field] ?? 0.0) + $value;
                }
            }
            $bounce = $this->number($row['bounce_rate'] ?? null);
            $visits = $this->number($row['visits'] ?? null) ?? 0.0;
            if ($bounce !== null && $visits > 0) {
                $target[$key]['__bounce_numerator'] += $bounce * $visits;
                $target[$key]['__bounce_weight'] += $visits;
            }
        }
    }

    private function finalizeSources(array &$rows): void
    {
        foreach ($rows as &$row) {
            $row['bounce_rate'] = $row['__bounce_weight'] > 0
                ? $row['__bounce_numerator'] / $row['__bounce_weight']
                : null;
            unset($row['__bounce_numerator'], $row['__bounce_weight']);
        }
        unset($row);
    }

    private function mergePages(array &$target, array $rows, array $unit): void
    {
        foreach ($rows as $row) {
            $page = trim((string) ($row['page'] ?? ''));
            if ($page === '') {
                continue;
            }
            $key = (int) $unit['site_id'] . '|' . $page;
            $target[$key] = $row + [
                'site_id' => (int) $unit['site_id'],
                'site_name' => (string) $unit['site_name'],
                'site_url' => (string) $unit['site_url'],
            ];
        }
    }

    private function mergeQueries(array &$target, array $rows, array $unit): void
    {
        foreach ($rows as $row) {
            $query = trim((string) ($row['query'] ?? ''));
            if ($query === '') {
                continue;
            }
            $key = mb_strtolower($query);
            $target[$key] ??= [
                'query' => $query,
                'position_current' => null,
                'position_previous' => null,
                'impressions' => 0.0,
                'clicks' => 0.0,
                'page' => '',
                'site_ids' => [],
                'site_names' => [],
                '__position_numerator' => 0.0,
                '__position_weight' => 0.0,
                '__previous_position_values' => [],
            ];
            $impressions = $this->number($row['impressions'] ?? null) ?? 0.0;
            $clicks = $this->number($row['clicks'] ?? null) ?? 0.0;
            $position = $this->number($row['position_current'] ?? null);
            $previousPosition = $this->number(
                $row['position_previous'] ?? null
            );
            $target[$key]['impressions'] += $impressions;
            $target[$key]['clicks'] += $clicks;
            if ($position !== null) {
                $weight = max(1.0, $impressions);
                $target[$key]['__position_numerator'] += $position * $weight;
                $target[$key]['__position_weight'] += $weight;
            }
            if ($previousPosition !== null) {
                $target[$key]['__previous_position_values'][] = $previousPosition;
            }
            $target[$key]['site_ids'][] = (int) $unit['site_id'];
            $target[$key]['site_names'][] = (string) $unit['site_name'];
        }
    }

    private function finalizeQueries(array &$rows): void
    {
        foreach ($rows as &$row) {
            $row['position_current'] = $row['__position_weight'] > 0
                ? $row['__position_numerator'] / $row['__position_weight']
                : null;
            $previous = $row['__previous_position_values'];
            $row['position_previous'] = $previous !== []
                ? array_sum($previous) / count($previous)
                : null;
            $row['site_ids'] = array_values(array_unique($row['site_ids']));
            $row['site_names'] = array_values(array_unique(array_filter(
                $row['site_names']
            )));
            unset(
                $row['__position_numerator'],
                $row['__position_weight'],
                $row['__previous_position_values']
            );
        }
        unset($row);
        uasort(
            $rows,
            static fn(array $a, array $b): int =>
                ((float) ($b['impressions'] ?? 0))
                <=> ((float) ($a['impressions'] ?? 0))
        );
    }

    private function mergeIssues(array &$target, array $rows, array $unit): void
    {
        foreach ($rows as $row) {
            if (($row['severity'] ?? '') === 'ok') {
                continue;
            }
            $row['site_id'] = (int) $unit['site_id'];
            $row['site_name'] = (string) $unit['site_name'];
            $row['comment'] = trim(
                (string) ($row['comment'] ?? '')
                . ' · Сайт: ' . (string) $unit['site_name']
            );
            $target[] = $row;
        }
    }

    private function siteDetails(array $results, array $failures): array
    {
        $details = [];
        foreach ($results as $result) {
            $unit = $result['unit'];
            $details[] = [
                'site_id' => (int) $unit['site_id'],
                'site_name' => (string) $unit['site_name'],
                'site_url' => (string) $unit['site_url'],
                'owner_site_ids' => $unit['owner_site_ids'],
                'counter_id' => (int) $unit['counter_id'],
                'webmaster_host_id' => (string) $unit['webmaster_host_id'],
                'status' => (string) ($result['status'] ?? 'empty'),
                'metrics' => $result['data']['metrics'] ?? [],
                'comparison' => $result['data']['comparison'] ?? [],
                'warnings' => $result['data']['warnings'] ?? [],
                'sqi' => $result['data']['metrics']['sqi'] ?? null,
            ];
        }
        foreach ($failures as $failure) {
            $unit = $failure['unit'];
            $details[] = [
                'site_id' => (int) $unit['site_id'],
                'site_name' => (string) $unit['site_name'],
                'site_url' => (string) $unit['site_url'],
                'owner_site_ids' => $unit['owner_site_ids'],
                'counter_id' => (int) $unit['counter_id'],
                'webmaster_host_id' => (string) $unit['webmaster_host_id'],
                'status' => 'failed',
                'metrics' => [],
                'comparison' => [],
                'warnings' => [(string) $failure['message']],
                'sqi' => null,
            ];
        }
        return $details;
    }

    private function unitHasData(array $unit, mixed $data): bool
    {
        if (!is_array($data)) {
            return false;
        }
        $metrics = is_array($data['metrics'] ?? null)
            ? $data['metrics']
            : [];
        $expected = (int) ($unit['counter_id'] ?? 0) > 0
            ? ['organic_visits', 'organic_users', 'leads']
            : ['search_impressions', 'search_clicks', 'pages_in_search'];
        foreach ($expected as $key) {
            if ($this->number($metrics[$key] ?? null) !== null) {
                return true;
            }
        }
        return false;
    }

    private function publicUnit(
        array $unit,
        string $status,
        array $warnings
    ): array {
        return [
            'site_id' => (int) $unit['site_id'],
            'site_name' => (string) $unit['site_name'],
            'site_url' => (string) $unit['site_url'],
            'owner_site_ids' => $unit['owner_site_ids'],
            'owner_site_names' => $unit['owner_site_names'],
            'counter_id' => (int) $unit['counter_id'],
            'webmaster_host_id' => (string) $unit['webmaster_host_id'],
            'status' => $status,
            'warnings' => array_values(array_map('strval', $warnings)),
        ];
    }

    private function unitLabel(array $unit): string
    {
        $source = [];
        if ((int) ($unit['counter_id'] ?? 0) > 0) {
            $source[] = 'Метрика ' . (int) $unit['counter_id'];
        }
        if ((string) ($unit['webmaster_host_id'] ?? '') !== '') {
            $source[] = 'Вебмастер ' . (string) $unit['webmaster_host_id'];
        }
        return trim((string) ($unit['site_name'] ?? 'Сайт'))
            . ($source !== [] ? ' (' . implode(', ', $source) . ')' : '');
    }

    private function number(mixed $value): ?float
    {
        return $value !== null && $value !== '' && is_numeric($value)
            ? (float) $value
            : null;
    }

    private function saveSnapshot(
        int $clientId,
        int $projectId,
        array $unit,
        string $dateFrom,
        string $dateTo,
        ?string $comparisonDateFrom,
        ?string $comparisonDateTo,
        string $status,
        array $payload,
        ?string $error
    ): void {
        try {
            $pdo = Database::pdo();
            $stmt = $pdo->prepare(
                'INSERT INTO project_source_snapshots
                 (client_id, project_id, site_id, source_type, external_id,
                  date_from, date_to, comparison_date_from,
                  comparison_date_to, status, normalized_json,
                  error_text, fetched_at, created_at, updated_at)
                 VALUES
                 (:client_id, :project_id, :site_id, :source_type, :external_id,
                  :date_from, :date_to, :comparison_date_from,
                  :comparison_date_to, :status, :normalized_json,
                  :error_text, NOW(), NOW(), NOW())
                 ON DUPLICATE KEY UPDATE
                    client_id = VALUES(client_id),
                    site_id = VALUES(site_id),
                    status = VALUES(status),
                    normalized_json = VALUES(normalized_json),
                    error_text = VALUES(error_text),
                    fetched_at = NOW(),
                    updated_at = NOW()'
            );
            $stmt->execute([
                'client_id' => $clientId > 0 ? $clientId : null,
                'project_id' => $projectId,
                'site_id' => (int) ($unit['site_id'] ?? 0),
                'source_type' => 'seo_multisite_unit',
                'external_id' => mb_substr(
                    'counter:' . (int) ($unit['counter_id'] ?? 0)
                    . '|host:' . (string) ($unit['webmaster_host_id'] ?? ''),
                    0,
                    190
                ),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'comparison_date_from' => $comparisonDateFrom,
                'comparison_date_to' => $comparisonDateTo,
                'status' => $status,
                'normalized_json' => json_encode(
                    $payload,
                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
                'error_text' => $error,
            ]);
        } catch (\Throwable) {
            // Сохранение снимка не должно скрыть уже загруженные данные.
        }
    }
}
