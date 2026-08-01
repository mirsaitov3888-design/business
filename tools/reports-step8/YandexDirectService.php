<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

final class YandexDirectService
{
    public function __construct(
        private readonly YandexDirectClient $client = new YandexDirectClient()
    ) {
    }

    public function configured(): bool
    {
        return $this->client->configured();
    }

    public function verify(): array
    {
        return $this->client->clientsGet();
    }

    public function load(
        string $dateFrom,
        string $dateTo,
        ?string $comparisonDateFrom = null,
        ?string $comparisonDateTo = null
    ): array {
        if (!$this->configured()) {
            return [
                'configured' => false,
                'current' => $this->emptyPeriod(),
                'comparison' => null,
            ];
        }

        $current = $this->loadPeriod($dateFrom, $dateTo);
        $comparison = null;

        if ($comparisonDateFrom !== null && $comparisonDateTo !== null) {
            $comparison = $this->loadPeriod(
                $comparisonDateFrom,
                $comparisonDateTo
            );
        }

        return [
            'configured' => true,
            'current' => $current,
            'comparison' => $comparison,
        ];
    }

    private function loadPeriod(string $dateFrom, string $dateTo): array
    {
        $campaignRows = $this->client->report(
            $this->reportDefinition(
                $dateFrom,
                $dateTo,
                'CAMPAIGN_PERFORMANCE_REPORT',
                [
                    'CampaignId',
                    'CampaignName',
                    'Impressions',
                    'Clicks',
                    'Cost',
                ],
                'campaign'
            )
        );

        $adGroupRows = $this->client->report(
            $this->reportDefinition(
                $dateFrom,
                $dateTo,
                'ADGROUP_PERFORMANCE_REPORT',
                [
                    'CampaignId',
                    'CampaignName',
                    'AdGroupId',
                    'AdGroupName',
                    'Impressions',
                    'Clicks',
                    'Cost',
                ],
                'adgroup'
            )
        );

        $campaigns = array_map(
            fn(array $row): array => $this->normalizeCampaign($row),
            $campaignRows
        );
        $adGroups = array_map(
            fn(array $row): array => $this->normalizeAdGroup($row),
            $adGroupRows
        );

        return [
            'totals' => $this->sumRows($campaigns),
            'campaigns' => $campaigns,
            'ad_groups' => $adGroups,
        ];
    }

    private function reportDefinition(
        string $dateFrom,
        string $dateTo,
        string $reportType,
        array $fieldNames,
        string $suffix
    ): array {
        return [
            'SelectionCriteria' => [
                'DateFrom' => $dateFrom,
                'DateTo' => $dateTo,
            ],
            'FieldNames' => $fieldNames,
            'ReportName' => sprintf(
                'seo_analytics_%s_%s_%s_%s',
                $suffix,
                str_replace('-', '', $dateFrom),
                str_replace('-', '', $dateTo),
                substr(hash('sha256', microtime(true) . random_int(1, PHP_INT_MAX)), 0, 8)
            ),
            'ReportType' => $reportType,
            'DateRangeType' => 'CUSTOM_DATE',
            'Format' => 'TSV',
            'IncludeVAT' => 'YES',
            'IncludeDiscount' => 'NO',
        ];
    }

    private function normalizeCampaign(array $row): array
    {
        return [
            'campaign_id' => (int) ($row['CampaignId'] ?? 0),
            'campaign_name' => trim((string) ($row['CampaignName'] ?? '')),
            'impressions' => $this->integer($row['Impressions'] ?? 0),
            'clicks' => $this->integer($row['Clicks'] ?? 0),
            'spend' => $this->decimal($row['Cost'] ?? 0),
        ];
    }

    private function normalizeAdGroup(array $row): array
    {
        return [
            'campaign_id' => (int) ($row['CampaignId'] ?? 0),
            'campaign_name' => trim((string) ($row['CampaignName'] ?? '')),
            'ad_group_id' => (int) ($row['AdGroupId'] ?? 0),
            'ad_group_name' => trim((string) ($row['AdGroupName'] ?? '')),
            'impressions' => $this->integer($row['Impressions'] ?? 0),
            'clicks' => $this->integer($row['Clicks'] ?? 0),
            'spend' => $this->decimal($row['Cost'] ?? 0),
        ];
    }

    private function sumRows(array $rows): array
    {
        $totals = [
            'impressions' => 0,
            'clicks' => 0,
            'spend' => 0.0,
        ];

        foreach ($rows as $row) {
            $totals['impressions'] += (int) ($row['impressions'] ?? 0);
            $totals['clicks'] += (int) ($row['clicks'] ?? 0);
            $totals['spend'] += (float) ($row['spend'] ?? 0);
        }

        $totals['ctr'] = $totals['impressions'] > 0
            ? ($totals['clicks'] / $totals['impressions']) * 100
            : null;
        $totals['cpc'] = $totals['clicks'] > 0
            ? $totals['spend'] / $totals['clicks']
            : null;

        return $totals;
    }

    private function emptyPeriod(): array
    {
        return [
            'totals' => [
                'impressions' => 0,
                'clicks' => 0,
                'spend' => 0.0,
                'ctr' => null,
                'cpc' => null,
            ],
            'campaigns' => [],
            'ad_groups' => [],
        ];
    }

    private function integer(mixed $value): int
    {
        return max(0, (int) str_replace(' ', '', (string) $value));
    }

    private function decimal(mixed $value): float
    {
        $normalized = str_replace(
            [' ', ','],
            ['', '.'],
            trim((string) $value)
        );

        return max(0, (float) $normalized);
    }
}
