<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use DateTimeImmutable;
use SeoAnalytics\Repositories\ProjectRepository;

final class SeoAutoFillService
{
    private const ORGANIC_FILTER = "ym:s:lastSignTrafficSource=='organic'";

    private const PROBLEM_NAMES = [
        'NO_SITEMAPS' => 'Не найден Sitemap',
        'NO_ROBOTS_TXT' => 'Не найден robots.txt',
        'ROBOTS_TXT_ERROR' => 'Ошибка robots.txt',
        'NO_METRIKA_COUNTER' => 'Проблема со счётчиком Метрики',
        'NO_REGIONS' => 'Не задан регион сайта',
        'NOT_IN_SPRAV' => 'Нет регистрации в Яндекс Бизнесе',
        'NOT_MOBILE_FRIENDLY' => 'Сайт не оптимизирован для мобильных',
        'FAVICON_PROBLEM' => 'Не найден favicon',
        'BIG_FAVICON_ABSENT' => 'Нет крупного favicon',
        'SOFT_404' => 'Некорректные страницы 404',
        'TOO_MANY_DOMAINS_ON_SEARCH' => 'В поиске обнаружены поддомены',
    ];

    public function __construct(
        private readonly YandexMetrikaClient $metrika = new YandexMetrikaClient(),
        private readonly WebmasterService $webmaster = new WebmasterService()
    ) {
    }

    public function build(
        array $project,
        string $dateFrom,
        string $dateTo,
        ?string $comparisonDateFrom = null,
        ?string $comparisonDateTo = null
    ): array {
        @set_time_limit(180);

        $warnings = [];
        $goalIds = ProjectRepository::goalIds($project);

        if ($goalIds === []) {
            $warnings[] = 'В настройках проекта не выбраны основные цели. Заявки и конверсия SEO не заполнялись.';
        }

        $currentMetrika = $this->loadMetrikaPeriod(
            $project,
            $dateFrom,
            $dateTo,
            $goalIds,
            $warnings,
            'текущий период'
        );

        $comparisonMetrika = null;
        $comparisonWebmaster = null;

        if ($comparisonDateFrom !== null && $comparisonDateTo !== null) {
            $comparisonMetrika = $this->loadMetrikaPeriod(
                $project,
                $comparisonDateFrom,
                $comparisonDateTo,
                $goalIds,
                $warnings,
                'период сравнения'
            );

            if (
                $this->periodDays($dateFrom, $dateTo)
                !== $this->periodDays($comparisonDateFrom, $comparisonDateTo)
            ) {
                $warnings[] = 'Текущий и сравнительный периоды имеют разную продолжительность. Динамику нужно интерпретировать осторожно.';
            }
        }

        $currentWebmaster = $this->loadWebmasterPeriod(
            $project,
            $dateFrom,
            $dateTo,
            $warnings,
            'текущий период'
        );

        if ($comparisonDateFrom !== null && $comparisonDateTo !== null) {
            $comparisonWebmaster = $this->loadWebmasterPeriod(
                $project,
                $comparisonDateFrom,
                $comparisonDateTo,
                $warnings,
                'период сравнения'
            );
        }

        $metrics = $this->buildMetrics(
            $currentMetrika,
            $currentWebmaster,
            $goalIds !== []
        );

        $comparison = $this->buildComparisonMetrics(
            $comparisonMetrika,
            $comparisonWebmaster,
            $goalIds !== []
        );

        $warnings[] = 'ИКС, количество страниц в поиске и исключённых страниц загружаются как текущий снимок Вебмастера. Исторические значения для этих карточек не подставляются.';
        $warnings[] = 'В текущем отчёте популярных запросов Вебмастер не возвращает целевую страницу. Поле страницы у запросов остаётся пустым.';
        $warnings[] = 'Средняя позиция посадочной страницы не вычисляется по данным Метрики и остаётся пустой.';

        return [
            'metrics' => $metrics,
            'comparison' => $comparison,
            'trend' => $this->buildTrend(
                $currentMetrika,
                $comparisonMetrika,
                $goalIds !== []
            ),
            'sources' => $this->buildSources(
                $currentMetrika,
                $comparisonMetrika,
                $goalIds !== []
            ),
            'queries' => $this->buildQueries(
                $currentWebmaster,
                $comparisonWebmaster
            ),
            'pages' => $this->buildPages(
                $currentMetrika,
                $comparisonMetrika,
                $goalIds !== []
            ),
            'issues' => $this->buildIssues($currentWebmaster),
            'warnings' => array_values(array_unique($warnings)),
            'quality' => $this->collectQuality([
                'current_metrika' => $currentMetrika,
                'comparison_metrika' => $comparisonMetrika,
            ]),
            'sources_used' => [
                'Яндекс Метрика',
                'Яндекс Вебмастер',
            ],
            'goal_ids' => $goalIds,
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'comparison_date_from' => $comparisonDateFrom,
                'comparison_date_to' => $comparisonDateTo,
            ],
        ];
    }

    private function loadMetrikaPeriod(
        array $project,
        string $dateFrom,
        string $dateTo,
        array $goalIds,
        array &$warnings,
        string $periodLabel
    ): array {
        $counterId = (int) $project['counter_id'];
        $goalFilter = $this->goalFilter($goalIds);

        $reports = [
            'summary' => $this->safeMetrika(
                fn() => $this->metrika->report(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    [
                        'ym:s:visits',
                        'ym:s:users',
                        'ym:s:bounceRate',
                    ],
                    [],
                    self::ORGANIC_FILTER,
                    null,
                    1
                ),
                $warnings,
                "Метрика: основные показатели, {$periodLabel}"
            ),
            'daily' => $this->safeMetrika(
                fn() => $this->metrika->report(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    [
                        'ym:s:visits',
                        'ym:s:users',
                    ],
                    ['ym:s:date'],
                    self::ORGANIC_FILTER,
                    'ym:s:date',
                    1000
                ),
                $warnings,
                "Метрика: динамика, {$periodLabel}"
            ),
            'sources' => $this->safeMetrika(
                fn() => $this->metrika->report(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    [
                        'ym:s:visits',
                        'ym:s:users',
                        'ym:s:bounceRate',
                    ],
                    ['ym:s:lastSignSearchEngineRoot'],
                    self::ORGANIC_FILTER,
                    '-ym:s:visits',
                    100
                ),
                $warnings,
                "Метрика: поисковые системы, {$periodLabel}"
            ),
            'pages' => $this->safeMetrika(
                fn() => $this->metrika->report(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    [
                        'ym:s:visits',
                        'ym:s:users',
                        'ym:s:bounceRate',
                    ],
                    ['ym:s:startURLPath'],
                    self::ORGANIC_FILTER,
                    '-ym:s:visits',
                    300
                ),
                $warnings,
                "Метрика: страницы входа, {$periodLabel}"
            ),
        ];

        if ($goalFilter === null) {
            $reports['leads_summary'] = null;
            $reports['leads_daily'] = null;
            $reports['leads_sources'] = null;
            $reports['leads_pages'] = null;

            return $reports;
        }

        $reports['leads_summary'] = $this->safeMetrika(
            fn() => $this->metrika->report(
                $counterId,
                $dateFrom,
                $dateTo,
                ['ym:s:visits'],
                [],
                $goalFilter,
                null,
                1
            ),
            $warnings,
            "Метрика: основные заявки, {$periodLabel}"
        );

        $reports['leads_daily'] = $this->safeMetrika(
            fn() => $this->metrika->report(
                $counterId,
                $dateFrom,
                $dateTo,
                ['ym:s:visits'],
                ['ym:s:date'],
                $goalFilter,
                'ym:s:date',
                1000
            ),
            $warnings,
            "Метрика: динамика заявок, {$periodLabel}"
        );

        $reports['leads_sources'] = $this->safeMetrika(
            fn() => $this->metrika->report(
                $counterId,
                $dateFrom,
                $dateTo,
                ['ym:s:visits'],
                ['ym:s:lastSignSearchEngineRoot'],
                $goalFilter,
                '-ym:s:visits',
                100
            ),
            $warnings,
            "Метрика: заявки по поисковым системам, {$periodLabel}"
        );

        $reports['leads_pages'] = $this->safeMetrika(
            fn() => $this->metrika->report(
                $counterId,
                $dateFrom,
                $dateTo,
                ['ym:s:visits'],
                ['ym:s:startURLPath'],
                $goalFilter,
                '-ym:s:visits',
                300
            ),
            $warnings,
            "Метрика: заявки по страницам, {$periodLabel}"
        );

        return $reports;
    }

    private function loadWebmasterPeriod(
        array $project,
        string $dateFrom,
        string $dateTo,
        array &$warnings,
        string $periodLabel
    ): ?array {
        try {
            return $this->webmaster->dashboard(
                $project,
                $dateFrom,
                $dateTo,
                'ALL'
            );
        } catch (\Throwable $exception) {
            $warnings[] = "Вебмастер ({$periodLabel}): {$exception->getMessage()}";

            return null;
        }
    }

    private function safeMetrika(
        callable $loader,
        array &$warnings,
        string $label
    ): ?array {
        try {
            $result = $loader();

            return is_array($result) ? $result : null;
        } catch (\Throwable $exception) {
            $warnings[] = "{$label}: {$exception->getMessage()}";

            return null;
        }
    }

    private function goalFilter(array $goalIds): ?string
    {
        if ($goalIds === []) {
            return null;
        }

        $conditions = array_map(
            static fn(int $goalId): string =>
                "ym:s:goal{$goalId}IsReached=='Yes'",
            $goalIds
        );

        return self::ORGANIC_FILTER
            . ' AND ('
            . implode(' OR ', $conditions)
            . ')';
    }

    private function buildMetrics(
        array $metrika,
        ?array $webmaster,
        bool $hasGoals
    ): array {
        $summary = $metrika['summary'] ?? null;
        $wmSummary = $webmaster['summary'] ?? [];
        $wmTotals = $webmaster['history']['totals'] ?? [];

        return [
            'organic_visits' => $this->metricTotal(
                $summary,
                'ym:s:visits'
            ),
            'organic_users' => $this->metricTotal(
                $summary,
                'ym:s:users'
            ),
            'search_impressions' => $this->nullableNumber(
                $wmTotals['shows'] ?? null
            ),
            'search_clicks' => $this->nullableNumber(
                $wmTotals['clicks'] ?? null
            ),
            'avg_position' => $this->nullableNumber(
                $wmTotals['avg_show_position'] ?? null
            ),
            'bounce_rate' => $this->metricTotal(
                $summary,
                'ym:s:bounceRate'
            ),
            'leads' => $hasGoals
                ? $this->metricTotal(
                    $metrika['leads_summary'] ?? null,
                    'ym:s:visits'
                )
                : null,
            'pages_in_search' => $this->nullableNumber(
                $wmSummary['searchable_pages_count'] ?? null
            ),
            'excluded_pages' => $this->nullableNumber(
                $wmSummary['excluded_pages_count'] ?? null
            ),
            'sqi' => $this->nullableNumber(
                $wmSummary['sqi'] ?? null
            ),
        ];
    }

    private function buildComparisonMetrics(
        ?array $metrika,
        ?array $webmaster,
        bool $hasGoals
    ): array {
        if ($metrika === null && $webmaster === null) {
            return [
                'organic_visits' => null,
                'organic_users' => null,
                'search_impressions' => null,
                'search_clicks' => null,
                'avg_position' => null,
                'bounce_rate' => null,
                'leads' => null,
                'pages_in_search' => null,
                'excluded_pages' => null,
                'sqi' => null,
            ];
        }

        $summary = $metrika['summary'] ?? null;
        $wmTotals = $webmaster['history']['totals'] ?? [];

        return [
            'organic_visits' => $this->metricTotal(
                $summary,
                'ym:s:visits'
            ),
            'organic_users' => $this->metricTotal(
                $summary,
                'ym:s:users'
            ),
            'search_impressions' => $this->nullableNumber(
                $wmTotals['shows'] ?? null
            ),
            'search_clicks' => $this->nullableNumber(
                $wmTotals['clicks'] ?? null
            ),
            'avg_position' => $this->nullableNumber(
                $wmTotals['avg_show_position'] ?? null
            ),
            'bounce_rate' => $this->metricTotal(
                $summary,
                'ym:s:bounceRate'
            ),
            'leads' => $hasGoals
                ? $this->metricTotal(
                    $metrika['leads_summary'] ?? null,
                    'ym:s:visits'
                )
                : null,
            'pages_in_search' => null,
            'excluded_pages' => null,
            'sqi' => null,
        ];
    }

    private function buildTrend(
        array $current,
        ?array $previous,
        bool $hasGoals
    ): array {
        $currentRows = $this->rowsByDimension(
            $current['daily'] ?? null,
            'ym:s:date'
        );
        $previousRows = $this->rowsByDimension(
            $previous['daily'] ?? null,
            'ym:s:date'
        );
        $currentLeadRows = $this->rowsByDimension(
            $current['leads_daily'] ?? null,
            'ym:s:date'
        );
        $previousLeadRows = $this->rowsByDimension(
            $previous['leads_daily'] ?? null,
            'ym:s:date'
        );

        $currentValues = array_values($currentRows);
        $previousValues = array_values($previousRows);
        $currentLeadValues = array_values($currentLeadRows);
        $previousLeadValues = array_values($previousLeadRows);
        $result = [];

        foreach ($currentValues as $index => $entry) {
            $previousEntry = $previousValues[$index] ?? null;
            $currentLeadEntry = $currentLeadValues[$index] ?? null;
            $previousLeadEntry = $previousLeadValues[$index] ?? null;

            $result[] = [
                'label' => $this->formatDateLabel($entry['dimension']),
                'visits_current' => $this->rowNumber(
                    $entry['row'],
                    'ym:s:visits'
                ),
                'visits_previous' => $previousEntry === null
                    ? null
                    : $this->rowNumber(
                        $previousEntry['row'],
                        'ym:s:visits'
                    ),
                'users_current' => $this->rowNumber(
                    $entry['row'],
                    'ym:s:users'
                ),
                'users_previous' => $previousEntry === null
                    ? null
                    : $this->rowNumber(
                        $previousEntry['row'],
                        'ym:s:users'
                    ),
                'leads_current' => $hasGoals
                    ? ($currentLeadEntry === null
                        ? 0
                        : $this->rowNumber(
                            $currentLeadEntry['row'],
                            'ym:s:visits'
                        ))
                    : null,
                'leads_previous' => $hasGoals
                    ? ($previousLeadEntry === null
                        ? null
                        : $this->rowNumber(
                            $previousLeadEntry['row'],
                            'ym:s:visits'
                        ))
                    : null,
            ];
        }

        return $result;
    }

    private function buildSources(
        array $current,
        ?array $previous,
        bool $hasGoals
    ): array {
        $currentRows = $this->rowsByDimension(
            $current['sources'] ?? null,
            'ym:s:lastSignSearchEngineRoot'
        );
        $previousRows = $this->rowsByDimension(
            $previous['sources'] ?? null,
            'ym:s:lastSignSearchEngineRoot'
        );
        $currentLeads = $this->rowsByDimension(
            $current['leads_sources'] ?? null,
            'ym:s:lastSignSearchEngineRoot'
        );
        $previousLeads = $this->rowsByDimension(
            $previous['leads_sources'] ?? null,
            'ym:s:lastSignSearchEngineRoot'
        );

        $result = [];

        foreach ($currentRows as $key => $entry) {
            $previousEntry = $previousRows[$key] ?? null;
            $currentLeadEntry = $currentLeads[$key] ?? null;
            $previousLeadEntry = $previousLeads[$key] ?? null;

            $result[] = [
                'name' => $entry['dimension'],
                'visits' => $this->rowNumber(
                    $entry['row'],
                    'ym:s:visits'
                ),
                'users' => $this->rowNumber(
                    $entry['row'],
                    'ym:s:users'
                ),
                'leads' => $hasGoals
                    ? ($currentLeadEntry === null
                        ? 0
                        : $this->rowNumber(
                            $currentLeadEntry['row'],
                            'ym:s:visits'
                        ))
                    : null,
                'bounce_rate' => $this->rowNumber(
                    $entry['row'],
                    'ym:s:bounceRate'
                ),
                'previous_visits' => $previousEntry === null
                    ? null
                    : $this->rowNumber(
                        $previousEntry['row'],
                        'ym:s:visits'
                    ),
                'previous_users' => $previousEntry === null
                    ? null
                    : $this->rowNumber(
                        $previousEntry['row'],
                        'ym:s:users'
                    ),
                'previous_leads' => $hasGoals
                    ? ($previousLeadEntry === null
                        ? null
                        : $this->rowNumber(
                            $previousLeadEntry['row'],
                            'ym:s:visits'
                        ))
                    : null,
            ];
        }

        return $result;
    }

    private function buildPages(
        array $current,
        ?array $previous,
        bool $hasGoals
    ): array {
        $currentRows = $this->rowsByDimension(
            $current['pages'] ?? null,
            'ym:s:startURLPath'
        );
        $previousRows = $this->rowsByDimension(
            $previous['pages'] ?? null,
            'ym:s:startURLPath'
        );
        $currentLeads = $this->rowsByDimension(
            $current['leads_pages'] ?? null,
            'ym:s:startURLPath'
        );

        $result = [];

        foreach ($currentRows as $key => $entry) {
            $previousEntry = $previousRows[$key] ?? null;
            $leadEntry = $currentLeads[$key] ?? null;

            $result[] = [
                'page' => $entry['dimension'],
                'visits_current' => $this->rowNumber(
                    $entry['row'],
                    'ym:s:visits'
                ),
                'visits_previous' => $previousEntry === null
                    ? null
                    : $this->rowNumber(
                        $previousEntry['row'],
                        'ym:s:visits'
                    ),
                'leads' => $hasGoals
                    ? ($leadEntry === null
                        ? 0
                        : $this->rowNumber(
                            $leadEntry['row'],
                            'ym:s:visits'
                        ))
                    : null,
                'bounce_rate' => $this->rowNumber(
                    $entry['row'],
                    'ym:s:bounceRate'
                ),
                'avg_position' => null,
            ];
        }

        return $result;
    }

    private function buildQueries(
        ?array $current,
        ?array $previous
    ): array {
        $currentRows = $current['queries']['rows'] ?? [];
        $previousRows = $previous['queries']['rows'] ?? [];
        $previousMap = [];

        foreach ($previousRows as $row) {
            $query = trim((string) ($row['query_text'] ?? ''));

            if ($query !== '') {
                $previousMap[mb_strtolower($query)] = $row;
            }
        }

        $result = [];
        $seen = [];

        foreach ($currentRows as $row) {
            $query = trim((string) ($row['query_text'] ?? ''));

            if ($query === '') {
                continue;
            }

            $key = mb_strtolower($query);
            $previousRow = $previousMap[$key] ?? null;
            $seen[$key] = true;

            $result[] = [
                'query' => $query,
                'position_current' => $this->nullableNumber(
                    $row['avg_show_position'] ?? null
                ),
                'position_previous' => $this->nullableNumber(
                    $previousRow['avg_show_position'] ?? null
                ),
                'impressions' => $this->nullableNumber(
                    $row['shows'] ?? null
                ),
                'clicks' => $this->nullableNumber(
                    $row['clicks'] ?? null
                ),
                'page' => '',
            ];
        }

        foreach ($previousMap as $key => $row) {
            if (isset($seen[$key])) {
                continue;
            }

            $query = trim((string) ($row['query_text'] ?? ''));

            if ($query === '') {
                continue;
            }

            $result[] = [
                'query' => $query,
                'position_current' => null,
                'position_previous' => $this->nullableNumber(
                    $row['avg_show_position'] ?? null
                ),
                'impressions' => 0,
                'clicks' => 0,
                'page' => '',
            ];
        }

        return array_slice($result, 0, 500);
    }

    private function buildIssues(?array $webmaster): array
    {
        $rows = $webmaster['diagnostics']['rows'] ?? [];
        $result = [];

        foreach ($rows as $row) {
            $type = trim((string) ($row['type'] ?? ''));
            $severity = strtoupper(
                trim((string) ($row['severity'] ?? 'WARNING'))
            );
            $updated = trim(
                (string) ($row['last_state_update'] ?? '')
            );

            $result[] = [
                'severity' => $this->mapSeverity($severity),
                'title' => self::PROBLEM_NAMES[$type]
                    ?? ($type !== '' ? $type : 'Проблема Вебмастера'),
                'comment' => $updated === ''
                    ? 'Источник: Яндекс Вебмастер'
                    : 'Обновлено: ' . mb_substr($updated, 0, 10),
            ];
        }

        if ($result === []) {
            $result[] = [
                'severity' => 'ok',
                'title' => 'Активные проблемы не найдены',
                'comment' => 'Яндекс Вебмастер не вернул активных диагностических проблем.',
            ];
        }

        return $result;
    }

    private function mapSeverity(string $severity): string
    {
        return match ($severity) {
            'FATAL', 'CRITICAL' => 'critical',
            'RECOMMENDATION', 'RECOMMENDATIONS' => 'recommendation',
            'OK' => 'ok',
            default => 'warning',
        };
    }

    private function metricTotal(?array $report, string $metric): ?float
    {
        if ($report === null) {
            return null;
        }

        $metrics = $report['query']['metrics'] ?? [];
        $totals = $report['totals'] ?? [];
        $index = array_search($metric, $metrics, true);

        if ($index === false || !array_key_exists($index, $totals)) {
            return null;
        }

        return $this->nullableNumber($totals[$index]);
    }

    private function rowNumber(array $row, string $key): ?float
    {
        return $this->nullableNumber($row[$key] ?? null);
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        return (float) $value;
    }

    private function rowsByDimension(
        ?array $report,
        string $dimension
    ): array {
        $result = [];

        foreach (($report['rows'] ?? []) as $row) {
            $name = $this->dimensionName($row[$dimension] ?? null);

            if ($name === '') {
                continue;
            }

            $result[mb_strtolower($name)] = [
                'dimension' => $name,
                'row' => $row,
            ];
        }

        return $result;
    }

    private function dimensionName(mixed $value): string
    {
        if (is_array($value)) {
            return trim((string) (
                $value['name']
                ?? $value['id']
                ?? ''
            ));
        }

        return trim((string) $value);
    }

    private function formatDateLabel(string $value): string
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof DateTimeImmutable
            ? $date->format('d.m.Y')
            : $value;
    }

    private function periodDays(string $dateFrom, string $dateTo): int
    {
        $from = new DateTimeImmutable($dateFrom);
        $to = new DateTimeImmutable($dateTo);

        return (int) $from->diff($to)->days + 1;
    }

    private function collectQuality(array $periods): array
    {
        $result = [];

        foreach ($periods as $periodName => $period) {
            if (!is_array($period)) {
                continue;
            }

            foreach ($period as $reportName => $report) {
                if (!is_array($report)) {
                    continue;
                }

                $quality = $report['quality'] ?? [];

                if ($quality !== []) {
                    $result[$periodName . '.' . $reportName] = $quality;
                }
            }
        }

        return $result;
    }
}
