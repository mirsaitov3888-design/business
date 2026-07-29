<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use SeoAnalytics\Repositories\ProjectRepository;

final class AdvertisingAutoFillService
{
    private const DIMENSIONS = [
        'ym:s:lastUTMSource',
        'ym:s:lastUTMMedium',
        'ym:s:lastUTMCampaign',
        'ym:s:startURLDomain',
        'ym:s:startURLPath',
    ];

    public function __construct(
        private readonly YandexMetrikaClient $metrika = new YandexMetrikaClient()
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
            $warnings[] = 'В настройках проекта не выбраны основные цели. Заявки не заполнялись.';
        }

        $current = $this->loadPeriod(
            $project,
            $dateFrom,
            $dateTo,
            $goalIds,
            $warnings,
            'текущий период'
        );

        $previous = null;

        if ($comparisonDateFrom !== null && $comparisonDateTo !== null) {
            $previous = $this->loadPeriod(
                $project,
                $comparisonDateFrom,
                $comparisonDateTo,
                $goalIds,
                $warnings,
                'период сравнения'
            );
        }

        $currentSegments = $this->segmentsFromReports(
            $current['traffic'] ?? null,
            $current['leads'] ?? null,
            $goalIds !== []
        );
        $previousSegments = $this->segmentsFromReports(
            $previous['traffic'] ?? null,
            $previous['leads'] ?? null,
            $goalIds !== []
        );

        $trafficSegments = $this->mergePeriods(
            $currentSegments,
            $previousSegments
        );

        $channels = $this->buildChannels($trafficSegments);

        if ($trafficSegments === []) {
            $warnings[] = 'В Метрике не найдены визиты с распознанными UTM-метками для поддерживаемых рекламных каналов.';
        }

        $warnings[] = 'Яндекс Директ в этом шаге не запрашивался: расходы, показы и клики не перезаписывались. Эти показатели сохраняются из ручного ввода до подключения Direct API.';

        return [
            'channels' => $channels,
            'traffic_segments' => $trafficSegments,
            'warnings' => array_values(array_unique($warnings)),
            'sources_used' => ['Яндекс Метрика'],
            'direct_status' => 'not_loaded',
            'goal_ids' => $goalIds,
            'quality' => $this->collectQuality([$current, $previous]),
            'period' => [
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'comparison_date_from' => $comparisonDateFrom,
                'comparison_date_to' => $comparisonDateTo,
            ],
        ];
    }

    private function loadPeriod(
        array $project,
        string $dateFrom,
        string $dateTo,
        array $goalIds,
        array &$warnings,
        string $periodLabel
    ): array {
        $counterId = (int) ($project['counter_id'] ?? 0);

        if ($counterId <= 0) {
            throw new \RuntimeException('У проекта не указан счётчик Метрики.');
        }

        $traffic = $this->safeReport(
            fn(): array => $this->metrika->report(
                $counterId,
                $dateFrom,
                $dateTo,
                ['ym:s:visits', 'ym:s:users', 'ym:s:bounceRate'],
                self::DIMENSIONS,
                null,
                '-ym:s:visits',
                10000
            ),
            $warnings,
            "Метрика: UTM-разбивка, {$periodLabel}"
        );

        $leads = null;
        $goalFilter = $this->goalFilter($goalIds);

        if ($goalFilter !== null) {
            $leads = $this->safeReport(
                fn(): array => $this->metrika->report(
                    $counterId,
                    $dateFrom,
                    $dateTo,
                    ['ym:s:visits'],
                    self::DIMENSIONS,
                    $goalFilter,
                    '-ym:s:visits',
                    10000
                ),
                $warnings,
                "Метрика: заявки по UTM, {$periodLabel}"
            );
        }

        return [
            'traffic' => $traffic,
            'leads' => $leads,
        ];
    }

    private function safeReport(
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

        return '(' . implode(' OR ', $conditions) . ')';
    }

    private function segmentsFromReports(
        ?array $trafficReport,
        ?array $leadReport,
        bool $hasGoals
    ): array {
        $leadMap = [];

        foreach (($leadReport['rows'] ?? []) as $row) {
            $signature = $this->rowSignature($row);

            if ($signature !== null) {
                $leadMap[$signature] = $this->rowNumber(
                    $row,
                    'ym:s:visits'
                ) ?? 0;
            }
        }

        $result = [];

        foreach (($trafficReport['rows'] ?? []) as $row) {
            $source = $this->dimension($row, 'ym:s:lastUTMSource');
            $medium = $this->dimension($row, 'ym:s:lastUTMMedium');
            $campaign = $this->dimension($row, 'ym:s:lastUTMCampaign');
            $domain = $this->dimension($row, 'ym:s:startURLDomain');
            $path = $this->dimension($row, 'ym:s:startURLPath');
            $channel = $this->channelKey($source, $medium);

            if ($channel === null) {
                continue;
            }

            $signature = $this->signature(
                $channel,
                $source,
                $medium,
                $campaign,
                $domain,
                $path
            );

            $result[$signature] = [
                'channel_key' => $channel,
                'title' => $this->destinationTitle($domain, $path),
                'utm_source' => $source,
                'utm_medium' => $medium,
                'utm_campaign' => $campaign,
                'landing' => trim($domain . $path),
                'visits' => $this->rowNumber($row, 'ym:s:visits') ?? 0,
                'users' => $this->rowNumber($row, 'ym:s:users') ?? 0,
                'leads' => $hasGoals ? ($leadMap[$signature] ?? 0) : null,
                'bounce_rate' => $this->roundPercent(
                    $this->rowNumber($row, 'ym:s:bounceRate')
                ),
            ];
        }

        return $result;
    }

    private function mergePeriods(array $current, array $previous): array
    {
        $keys = array_values(array_unique([
            ...array_keys($current),
            ...array_keys($previous),
        ]));
        $result = [];

        foreach ($keys as $index => $key) {
            $currentRow = $current[$key] ?? null;
            $previousRow = $previous[$key] ?? null;
            $base = $currentRow ?? $previousRow;

            if (!is_array($base)) {
                continue;
            }

            $result[] = [
                'channel_key' => $base['channel_key'],
                'title' => $base['title'],
                'utm_source' => $base['utm_source'],
                'utm_medium' => $base['utm_medium'],
                'utm_campaign' => $base['utm_campaign'],
                'landing' => $base['landing'],
                'visits' => $currentRow['visits'] ?? 0,
                'users' => $currentRow['users'] ?? 0,
                'leads' => $currentRow['leads'] ?? null,
                'bounce_rate' => $currentRow['bounce_rate'] ?? null,
                'previous_visits' => $previousRow['visits'] ?? null,
                'previous_leads' => $previousRow['leads'] ?? null,
                'sort_order' => $index,
            ];
        }

        usort(
            $result,
            static fn(array $a, array $b): int =>
                ((int) ($b['visits'] ?? 0))
                <=> ((int) ($a['visits'] ?? 0))
        );

        return array_slice($result, 0, 300);
    }

    private function buildChannels(array $segments): array
    {
        $keys = ['direct', 'vk', 'avito', '2gis', 'yandex_business'];
        $result = [];

        foreach ($keys as $key) {
            $rows = array_values(array_filter(
                $segments,
                static fn(array $row): bool =>
                    $row['channel_key'] === $key
            ));
            $leadValues = array_column($rows, 'leads');
            $previousValues = array_column($rows, 'previous_leads');
            $hasLeads = array_filter(
                $leadValues,
                static fn(mixed $value): bool => $value !== null
            ) !== [];
            $hasPrevious = array_filter(
                $previousValues,
                static fn(mixed $value): bool => $value !== null
            ) !== [];

            $result[] = [
                'channel_key' => $key,
                'leads' => $hasLeads
                    ? (int) array_sum(array_map(
                        static fn(mixed $value): int => (int) ($value ?? 0),
                        $leadValues
                    ))
                    : null,
                'previous_leads' => $hasPrevious
                    ? (int) array_sum(array_map(
                        static fn(mixed $value): int => (int) ($value ?? 0),
                        $previousValues
                    ))
                    : null,
            ];
        }

        return $result;
    }

    private function rowSignature(array $row): ?string
    {
        $source = $this->dimension($row, 'ym:s:lastUTMSource');
        $medium = $this->dimension($row, 'ym:s:lastUTMMedium');
        $campaign = $this->dimension($row, 'ym:s:lastUTMCampaign');
        $domain = $this->dimension($row, 'ym:s:startURLDomain');
        $path = $this->dimension($row, 'ym:s:startURLPath');
        $channel = $this->channelKey($source, $medium);

        return $channel === null
            ? null
            : $this->signature(
                $channel,
                $source,
                $medium,
                $campaign,
                $domain,
                $path
            );
    }

    private function signature(
        string $channel,
        string $source,
        string $medium,
        string $campaign,
        string $domain,
        string $path
    ): string {
        return mb_strtolower(implode('|', [
            $channel,
            $source,
            $medium,
            $campaign,
            $domain,
            $path,
        ]));
    }

    private function channelKey(string $source, string $medium): ?string
    {
        $value = mb_strtolower(trim($source . ' ' . $medium));

        if ($value === '') {
            return null;
        }
        if (str_contains($value, '2gis') || str_contains($value, '2gis')) {
            return '2gis';
        }
        if (str_contains($value, 'avito')) {
            return 'avito';
        }
        if (str_contains($value, 'vk') || str_contains($value, 'vkontakte')) {
            return 'vk';
        }
        if (
            str_contains($value, 'yandex_business')
            || str_contains($value, 'yandex_maps')
            || str_contains($value, 'yandex map')
            || str_contains($value, 'business.yandex')
        ) {
            return 'yandex_business';
        }
        if (
            str_contains($value, 'yandex')
            || preg_match('/(^|\s)ya($|\s)/u', $value)
            || str_contains($value, 'direct')
        ) {
            return 'direct';
        }

        return null;
    }

    private function destinationTitle(string $domain, string $path): string
    {
        $value = mb_strtolower($domain . $path);

        if (str_contains($value, 'marquiz')) {
            return 'Марквиз';
        }
        if (str_contains($value, 'quiz') || str_contains($value, 'квиз')) {
            return 'Квиз';
        }

        return 'Сайт';
    }

    private function dimension(array $row, string $key): string
    {
        $value = $row[$key] ?? null;

        if (is_array($value)) {
            return trim((string) (
                $value['name']
                ?? $value['id']
                ?? ''
            ));
        }

        return trim((string) $value);
    }

    private function rowNumber(array $row, string $key): ?float
    {
        $value = $row[$key] ?? null;

        return is_numeric($value) ? (float) $value : null;
    }

    private function roundPercent(?float $value): ?float
    {
        return $value === null ? null : round($value, 2);
    }

    private function collectQuality(array $periods): array
    {
        $result = [];

        foreach ($periods as $periodIndex => $period) {
            if (!is_array($period)) {
                continue;
            }

            foreach ($period as $reportName => $report) {
                if (!is_array($report) || ($report['quality'] ?? []) === []) {
                    continue;
                }

                $result[$periodIndex . '.' . $reportName] = $report['quality'];
            }
        }

        return $result;
    }
}
