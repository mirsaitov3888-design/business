<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use DOMDocument;
use DOMElement;
use DOMNode;
use SeoAnalytics\Core\Database;

final class SeoReportRepository
{
    private const METRIC_KEYS = [
        'organic_visits',
        'organic_users',
        'search_impressions',
        'search_clicks',
        'avg_position',
        'bounce_rate',
        'leads',
        'pages_in_search',
        'excluded_pages',
        'sqi',
    ];

    private const FINANCE_KEYS = [
        'seo_cost',
        'qualified_leads',
        'contracts',
        'contract_amount',
        'paid_revenue',
        'gross_margin_percent',
    ];

    public function save(array $data, int $userId): int
    {
        $baseRepository = new ReportRepository();
        $reportId = $baseRepository->save([
            'id' => (int) ($data['id'] ?? 0),
            'project_id' => (int) ($data['project_id'] ?? 0),
            'title' => (string) ($data['title'] ?? ''),
            'report_type' => 'seo',
            'audience' => (string) ($data['audience'] ?? 'owner'),
            'status' => (string) ($data['status'] ?? 'draft'),
            'date_from' => (string) ($data['date_from'] ?? ''),
            'date_to' => (string) ($data['date_to'] ?? ''),
            'comparison_date_from' => $data['comparison_date_from'] ?? null,
            'comparison_date_to' => $data['comparison_date_to'] ?? null,
            'work_done' => (string) ($data['work_done'] ?? ''),
            'next_plan' => (string) ($data['next_plan'] ?? ''),
            'recommendations' => (string) ($data['recommendations'] ?? ''),
            'notes' => (string) ($data['notes'] ?? ''),
            'channels' => [],
            'campaign_groups' => [],
            'creatives' => [],
        ], $userId);

        $payload = $this->sanitizePayload(
            is_array($data['seo_data'] ?? null) ? $data['seo_data'] : []
        );

        $stmt = Database::pdo()->prepare(
            'INSERT INTO report_seo_data
             (report_id, payload_json, created_at, updated_at)
             VALUES (:report_id, :payload_json, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                payload_json = VALUES(payload_json),
                updated_at = NOW()'
        );
        $stmt->execute([
            'report_id' => $reportId,
            'payload_json' => json_encode(
                $payload,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
        ]);

        return $reportId;
    }

    public function findData(int $reportId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT payload_json
             FROM report_seo_data
             WHERE report_id = :report_id
             LIMIT 1'
        );
        $stmt->execute(['report_id' => $reportId]);
        $raw = $stmt->fetchColumn();

        if (!is_string($raw) || $raw === '') {
            return $this->emptyPayload();
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded)
            ? $this->sanitizePayload($decoded)
            : $this->emptyPayload();
    }

    private function sanitizePayload(array $data): array
    {
        $metrics = [];
        $comparison = [];

        foreach (self::METRIC_KEYS as $key) {
            $metrics[$key] = $this->nullableNumber(
                $data['metrics'][$key] ?? null
            );
            $comparison[$key] = $this->nullableNumber(
                $data['comparison'][$key] ?? null
            );
        }

        return [
            'metrics' => $metrics,
            'comparison' => $comparison,
            'trend' => $this->sanitizeTrend($data['trend'] ?? []),
            'sources' => $this->sanitizeSources($data['sources'] ?? []),
            'queries' => $this->sanitizeQueries($data['queries'] ?? []),
            'pages' => $this->sanitizePages($data['pages'] ?? []),
            'issues' => $this->sanitizeIssues($data['issues'] ?? []),
            'finance' => $this->sanitizeFinance($data['finance'] ?? []),
            'finance_comparison' => $this->sanitizeFinance(
                $data['finance_comparison'] ?? []
            ),
            'results_html' => $this->sanitizeRich(
                $data['results_html'] ?? ''
            ),
        ];
    }

    private function sanitizeTrend(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];

        foreach (array_slice($rows, 0, 120) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $label = $this->plain($row['label'] ?? '', 60);

            if ($label === '') {
                continue;
            }

            $result[] = [
                'label' => $label,
                'visits_current' => $this->nullableNumber($row['visits_current'] ?? null),
                'visits_previous' => $this->nullableNumber($row['visits_previous'] ?? null),
                'users_current' => $this->nullableNumber($row['users_current'] ?? null),
                'users_previous' => $this->nullableNumber($row['users_previous'] ?? null),
                'leads_current' => $this->nullableNumber($row['leads_current'] ?? null),
                'leads_previous' => $this->nullableNumber($row['leads_previous'] ?? null),
            ];
        }

        return $result;
    }

    private function sanitizeSources(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];

        foreach (array_slice($rows, 0, 50) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $name = $this->plain($row['name'] ?? '', 120);

            if ($name === '') {
                continue;
            }

            $result[] = [
                'name' => $name,
                'visits' => $this->nullableNumber($row['visits'] ?? null),
                'users' => $this->nullableNumber($row['users'] ?? null),
                'leads' => $this->nullableNumber($row['leads'] ?? null),
                'bounce_rate' => $this->nullableNumber($row['bounce_rate'] ?? null),
                'previous_visits' => $this->nullableNumber($row['previous_visits'] ?? null),
                'previous_users' => $this->nullableNumber($row['previous_users'] ?? null),
                'previous_leads' => $this->nullableNumber($row['previous_leads'] ?? null),
            ];
        }

        return $result;
    }

    private function sanitizeQueries(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];

        foreach (array_slice($rows, 0, 1000) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $query = $this->plain($row['query'] ?? '', 500);

            if ($query === '') {
                continue;
            }

            $result[] = [
                'query' => $query,
                'position_current' => $this->nullableNumber($row['position_current'] ?? null),
                'position_previous' => $this->nullableNumber($row['position_previous'] ?? null),
                'impressions' => $this->nullableNumber($row['impressions'] ?? null),
                'clicks' => $this->nullableNumber($row['clicks'] ?? null),
                'page' => $this->plain($row['page'] ?? '', 1000),
            ];
        }

        return $result;
    }

    private function sanitizePages(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $result = [];

        foreach (array_slice($rows, 0, 500) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $page = $this->plain($row['page'] ?? '', 1000);

            if ($page === '') {
                continue;
            }

            $result[] = [
                'page' => $page,
                'visits_current' => $this->nullableNumber($row['visits_current'] ?? null),
                'visits_previous' => $this->nullableNumber($row['visits_previous'] ?? null),
                'leads' => $this->nullableNumber($row['leads'] ?? null),
                'bounce_rate' => $this->nullableNumber($row['bounce_rate'] ?? null),
                'avg_position' => $this->nullableNumber($row['avg_position'] ?? null),
            ];
        }

        return $result;
    }

    private function sanitizeIssues(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $allowedSeverities = ['critical', 'warning', 'recommendation', 'ok'];
        $result = [];

        foreach (array_slice($rows, 0, 200) as $row) {
            if (!is_array($row)) {
                continue;
            }

            $title = $this->plain($row['title'] ?? '', 500);

            if ($title === '') {
                continue;
            }

            $severity = (string) ($row['severity'] ?? 'warning');

            if (!in_array($severity, $allowedSeverities, true)) {
                $severity = 'warning';
            }

            $result[] = [
                'severity' => $severity,
                'title' => $title,
                'comment' => $this->plain($row['comment'] ?? '', 3000),
            ];
        }

        return $result;
    }

    private function sanitizeFinance(mixed $data): array
    {
        $data = is_array($data) ? $data : [];
        $result = [];

        foreach (self::FINANCE_KEYS as $key) {
            $result[$key] = $this->nullableNumber($data[$key] ?? null);
        }

        return $result;
    }

    private function emptyPayload(): array
    {
        return [
            'metrics' => array_fill_keys(self::METRIC_KEYS, null),
            'comparison' => array_fill_keys(self::METRIC_KEYS, null),
            'trend' => [],
            'sources' => [],
            'queries' => [],
            'pages' => [],
            'issues' => [],
            'finance' => array_fill_keys(self::FINANCE_KEYS, null),
            'finance_comparison' => array_fill_keys(self::FINANCE_KEYS, null),
            'results_html' => '',
        ];
    }

    private function nullableNumber(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $normalized = str_replace(
            [' ', ','],
            ['', '.'],
            trim((string) $value)
        );

        if (!is_numeric($normalized)) {
            return null;
        }

        return max(0, (float) $normalized);
    }

    private function plain(mixed $value, int $limit): string
    {
        return mb_substr(
            trim(strip_tags((string) $value)),
            0,
            $limit
        );
    }

    private function sanitizeRich(mixed $value): string
    {
        $html = trim((string) $value);

        if ($html === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            return mb_substr(
                strip_tags(
                    $html,
                    '<p><br><strong><b><em><i><u><h2><h3><h4><ul><ol><li><a><img><blockquote><div><span>'
                ),
                0,
                100000
            );
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="seo-rich-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $dom->getElementById('seo-rich-root');

        if (!$root) {
            return '';
        }

        $this->sanitizeRichChildren($root);
        $result = '';

        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }

        return mb_substr($result, 0, 100000);
    }

    private function sanitizeRichChildren(DOMNode $parent): void
    {
        $allowed = [
            'p', 'br', 'strong', 'b', 'em', 'i', 'u',
            'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a',
            'img', 'blockquote', 'div', 'span',
        ];
        $children = [];

        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->hasChildNodes()) {
                $this->sanitizeRichChildren($child);
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);

            if (!in_array($tag, $allowed, true)) {
                while ($child->firstChild) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);
                continue;
            }

            $attributes = [];

            foreach ($child->attributes as $attribute) {
                $attributes[] = $attribute->name;
            }

            $href = $child->getAttribute('href');
            $source = $child->getAttribute('src');

            foreach ($attributes as $attribute) {
                $child->removeAttribute($attribute);
            }

            if ($tag === 'a' && preg_match('#^(https?://|mailto:|/)#i', $href)) {
                $child->setAttribute('href', mb_substr($href, 0, 1000));
                $child->setAttribute('target', '_blank');
                $child->setAttribute('rel', 'noopener noreferrer');
            }

            if ($tag === 'img') {
                if (!str_starts_with($source, '/uploads/report-media/')) {
                    $parent->removeChild($child);
                    continue;
                }

                $child->setAttribute('src', $source);
                $child->setAttribute('alt', 'Изображение SEO-отчёта');
                $child->setAttribute('loading', 'lazy');
            }
        }
    }
}
