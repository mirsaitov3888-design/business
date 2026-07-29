<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function out(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function rootPath(): string
{
    $root = realpath(dirname(__DIR__));

    if (
        !is_string($root)
        || !is_file($root . '/index.php')
        || !is_file($root . '/api.php')
    ) {
        throw new RuntimeException('Поместите установщик в каталог bin проекта.');
    }

    return $root;
}

function readFileStrict(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать {$path}");
    }

    return $content;
}

function writeFileAtomic(string $path, string $content): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException("Не удалось создать {$directory}");
    }

    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException("Не удалось записать {$temporary}");
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException("Не удалось заменить {$path}");
    }
}

function replaceOnce(string $content, string $needle, string $replacement, string $label): string
{
    $position = strpos($content, $needle);

    if ($position === false) {
        throw new RuntimeException("Не найдена точка изменения: {$label}");
    }

    return substr($content, 0, $position)
        . $replacement
        . substr($content, $position + strlen($needle));
}

function insertBeforeOnce(string $content, string $needle, string $insertion, string $label): string
{
    return replaceOnce($content, $needle, $insertion . $needle, $label);
}

function replaceBetween(
    string $content,
    string $startNeedle,
    string $endNeedle,
    string $replacement,
    string $label
): string {
    $start = strpos($content, $startNeedle);

    if ($start === false) {
        throw new RuntimeException("Не найдено начало блока: {$label}");
    }

    $end = strpos($content, $endNeedle, $start + strlen($startNeedle));

    if ($end === false) {
        throw new RuntimeException("Не найден конец блока: {$label}");
    }

    return substr($content, 0, $start)
        . $replacement
        . substr($content, $end);
}

function backup(string $root, array $paths, string $directory): array
{
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        throw new RuntimeException('Не удалось создать резервную копию.');
    }

    $manifest = [];

    foreach ($paths as $relative) {
        $source = $root . '/' . $relative;
        $manifest[$relative] = is_file($source);

        if (!$manifest[$relative]) {
            continue;
        }

        $destination = $directory . '/' . $relative;
        $destinationDirectory = dirname($destination);

        if (!is_dir($destinationDirectory)) {
            mkdir($destinationDirectory, 0700, true);
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException("Не удалось сохранить копию {$relative}");
        }
    }

    return $manifest;
}

function rollback(string $root, string $directory, array $manifest): void
{
    foreach ($manifest as $relative => $existed) {
        $destination = $root . '/' . $relative;

        if ($existed) {
            $source = $directory . '/' . $relative;

            if (is_file($source)) {
                @copy($source, $destination);
            }
        } elseif (is_file($destination)) {
            @unlink($destination);
        }
    }
}

function lintPhp(string $path): void
{
    if (!function_exists('exec')) {
        return;
    }

    $output = [];
    $code = 0;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);

    if ($code !== 0) {
        throw new RuntimeException("Ошибка синтаксиса в {$path}:\n" . implode("\n", $output));
    }
}

function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = :table_name
           AND COLUMN_NAME = :column_name'
    );
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    if ((int) $stmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
    }
}

$root = rootPath();
$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$repositoryPath = $root . '/app/Repositories/ReportRepository.php';

$indexOriginal = readFileStrict($indexPath);

if (!str_contains($indexOriginal, 'REPORTS_STEP1')) {
    throw new RuntimeException('Сначала установите модуль отчётов — шаг 1.');
}

if (str_contains($indexOriginal, 'REPORTS_STEP2')) {
    out('Доработка отчётов — шаг 2 — уже установлена.');
    exit(0);
}

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'sql/schema.sql',
    'app/Repositories/ReportRepository.php',
    'uploads/report-media/.htaccess',
];

$backupDirectory = $root . '/storage/backups/reports-step2-' . date('Ymd-His');
$manifest = backup($root, $paths, $backupDirectory);
out("Резервная копия: {$backupDirectory}");

$repositoryContent = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use DOMDocument;
use DOMElement;
use DOMNode;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class ReportRepository
{
    public const CHANNELS = [
        'direct' => 'Яндекс Директ',
        'vk' => 'VK Реклама',
        'avito' => 'Авито',
        '2gis' => '2ГИС',
        'yandex_business' => 'Яндекс Бизнес',
        'seo' => 'Органический поиск (SEO)',
    ];

    private const REPORT_TYPES = ['advertising_summary', 'seo'];
    private const AUDIENCES = ['owner', 'marketer', 'sales', 'client'];
    private const STATUSES = ['draft', 'review', 'approved', 'sent', 'archive'];
    private const SOURCE_TYPES = ['manual', 'import', 'api'];

    public function listByProject(int $projectId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                r.id, r.title, r.report_type, r.audience, r.status,
                r.date_from, r.date_to, r.created_at, r.updated_at,
                u.name AS author_name, u.email AS author_email,
                COALESCE(SUM(c.spend), 0) AS spend,
                COALESCE(SUM(c.leads), 0) AS leads,
                COALESCE(SUM(c.qualified_leads), 0) AS qualified_leads,
                COALESCE(SUM(c.contracts), 0) AS contracts,
                COALESCE(SUM(c.contract_amount), 0) AS contract_amount,
                COALESCE(SUM(c.paid_revenue), 0) AS paid_revenue
             FROM reports r
             INNER JOIN users u ON u.id = r.created_by
             LEFT JOIN report_channels c ON c.report_id = r.id
             WHERE r.project_id = :project_id
             GROUP BY
                r.id, r.title, r.report_type, r.audience, r.status,
                r.date_from, r.date_to, r.created_at, r.updated_at,
                u.name, u.email
             ORDER BY r.updated_at DESC
             LIMIT 200'
        );
        $stmt->execute(['project_id' => $projectId]);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            foreach (['id', 'leads', 'qualified_leads', 'contracts'] as $key) {
                $row[$key] = (int) $row[$key];
            }
            foreach (['spend', 'contract_amount', 'paid_revenue'] as $key) {
                $row[$key] = (float) $row[$key];
            }
            $row['author_name'] = $row['author_name'] ?: $row['author_email'];
            unset($row['author_email']);
            $row['calculated'] = [
                'cpl' => self::divide($row['spend'], $row['leads']),
                'cpql' => self::divide($row['spend'], $row['qualified_leads']),
                'cost_per_contract' => self::divide($row['spend'], $row['contracts']),
                'roas' => self::divide($row['paid_revenue'], $row['spend']),
            ];
        }
        unset($row);

        return $rows;
    }

    public function find(int $id, int $projectId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT r.*, u.name AS author_name, u.email AS author_email
             FROM reports r
             INNER JOIN users u ON u.id = r.created_by
             WHERE r.id = :id AND r.project_id = :project_id
             LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'project_id' => $projectId]);
        $report = $stmt->fetch();

        if (!$report) {
            return null;
        }

        foreach (['id', 'project_id', 'created_by'] as $key) {
            $report[$key] = (int) $report[$key];
        }
        $report['author_name'] = $report['author_name'] ?: $report['author_email'];
        unset($report['author_email']);

        $stmt = Database::pdo()->prepare(
            'SELECT * FROM report_channels
             WHERE report_id = :report_id
             ORDER BY FIELD(channel_key, "direct", "vk", "avito", "2gis", "yandex_business", "seo")'
        );
        $stmt->execute(['report_id' => $id]);
        $channels = array_map([self::class, 'normalizeStoredChannel'], $stmt->fetchAll());

        $stmt = Database::pdo()->prepare(
            'SELECT id, detail_type, channel_key, title, payload_json, sort_order
             FROM report_details
             WHERE report_id = :report_id
             ORDER BY detail_type, channel_key, sort_order, id'
        );
        $stmt->execute(['report_id' => $id]);

        $groups = [];
        $creatives = [];

        foreach ($stmt->fetchAll() as $detail) {
            $payload = json_decode((string) $detail['payload_json'], true);
            $payload = is_array($payload) ? $payload : [];
            $payload['id'] = (int) $detail['id'];
            $payload['channel_key'] = (string) $detail['channel_key'];
            $payload['title'] = (string) $detail['title'];
            $payload['sort_order'] = (int) $detail['sort_order'];

            if ($detail['detail_type'] === 'campaign_group') {
                $payload['calculated'] = self::calculateChannel($payload);
                $groups[] = $payload;
            } elseif ($detail['detail_type'] === 'creative') {
                $creatives[] = $payload;
            }
        }

        $report['channels'] = $channels;
        $report['campaign_groups'] = $groups;
        $report['creatives'] = $creatives;
        $report['summary'] = self::calculateSummary($channels, false);
        $report['comparison_summary'] = self::calculateSummary($channels, true);

        return $report;
    }

    public function save(array $data, int $userId): int
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        $reportId = (int) ($data['id'] ?? 0);
        $reportType = (string) ($data['report_type'] ?? '');

        if ($projectId <= 0 || $userId <= 0) {
            throw new RuntimeException('Не удалось определить проект или пользователя.');
        }
        if (!in_array($reportType, self::REPORT_TYPES, true)) {
            throw new RuntimeException('Неизвестный тип отчёта.');
        }

        $audience = in_array(($data['audience'] ?? ''), self::AUDIENCES, true)
            ? (string) $data['audience'] : 'owner';
        $status = in_array(($data['status'] ?? ''), self::STATUSES, true)
            ? (string) $data['status'] : 'draft';
        $dateFrom = (string) ($data['date_from'] ?? '');
        $dateTo = (string) ($data['date_to'] ?? '');
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            $title = ($reportType === 'seo' ? 'SEO-отчёт' : 'Сводный рекламный отчёт')
                . " {$dateFrom}—{$dateTo}";
        }

        $channels = $this->sanitizeChannels($reportType, is_array($data['channels'] ?? null) ? $data['channels'] : []);
        $allowedKeys = array_column($channels, 'channel_key');
        $groups = $this->sanitizeGroups(is_array($data['campaign_groups'] ?? null) ? $data['campaign_groups'] : [], $allowedKeys);
        $creatives = $this->sanitizeCreatives(is_array($data['creatives'] ?? null) ? $data['creatives'] : [], $allowedKeys);

        $payload = [
            'report_type' => $reportType,
            'audience' => $audience,
            'status' => $status,
            'title' => mb_substr($title, 0, 190),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'comparison_date_from' => self::nullableDate($data['comparison_date_from'] ?? null),
            'comparison_date_to' => self::nullableDate($data['comparison_date_to'] ?? null),
            'work_done' => self::rich($data['work_done'] ?? ''),
            'next_plan' => self::rich($data['next_plan'] ?? ''),
            'recommendations' => self::rich($data['recommendations'] ?? ''),
            'notes' => self::rich($data['notes'] ?? ''),
        ];

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            if ($reportId > 0) {
                $check = $pdo->prepare('SELECT id FROM reports WHERE id = :id AND project_id = :project_id');
                $check->execute(['id' => $reportId, 'project_id' => $projectId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Отчёт не найден или относится к другому проекту.');
                }

                $stmt = $pdo->prepare(
                    'UPDATE reports SET
                        report_type = :report_type, audience = :audience, status = :status,
                        title = :title, date_from = :date_from, date_to = :date_to,
                        comparison_date_from = :comparison_date_from,
                        comparison_date_to = :comparison_date_to,
                        work_done = :work_done, next_plan = :next_plan,
                        recommendations = :recommendations, notes = :notes,
                        updated_at = NOW()
                     WHERE id = :id AND project_id = :project_id'
                );
                $stmt->execute($payload + ['id' => $reportId, 'project_id' => $projectId]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO reports
                     (project_id, created_by, report_type, audience, status, title,
                      date_from, date_to, comparison_date_from, comparison_date_to,
                      work_done, next_plan, recommendations, notes, created_at, updated_at)
                     VALUES
                     (:project_id, :created_by, :report_type, :audience, :status, :title,
                      :date_from, :date_to, :comparison_date_from, :comparison_date_to,
                      :work_done, :next_plan, :recommendations, :notes, NOW(), NOW())'
                );
                $stmt->execute($payload + ['project_id' => $projectId, 'created_by' => $userId]);
                $reportId = (int) $pdo->lastInsertId();
            }

            $pdo->prepare('DELETE FROM report_channels WHERE report_id = :report_id')
                ->execute(['report_id' => $reportId]);

            $insertChannel = $pdo->prepare(
                'INSERT INTO report_channels
                 (report_id, channel_key, channel_name, source_type, comparison_json,
                  spend, impressions, clicks, leads, qualified_leads, meetings, offers,
                  contracts, contract_amount, paid_revenue, gross_margin_percent,
                  non_target, duplicates, unreachable, notes, created_at, updated_at)
                 VALUES
                 (:report_id, :channel_key, :channel_name, :source_type, :comparison_json,
                  :spend, :impressions, :clicks, :leads, :qualified_leads, :meetings, :offers,
                  :contracts, :contract_amount, :paid_revenue, :gross_margin_percent,
                  :non_target, :duplicates, :unreachable, :notes, NOW(), NOW())'
            );

            foreach ($channels as $channel) {
                $insertChannel->execute([
                    'report_id' => $reportId,
                    'channel_key' => $channel['channel_key'],
                    'channel_name' => $channel['channel_name'],
                    'source_type' => $channel['source_type'],
                    'comparison_json' => json_encode($channel['comparison'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'spend' => $channel['spend'],
                    'impressions' => $channel['impressions'],
                    'clicks' => $channel['clicks'],
                    'leads' => $channel['leads'],
                    'qualified_leads' => $channel['qualified_leads'],
                    'meetings' => $channel['meetings'],
                    'offers' => $channel['offers'],
                    'contracts' => $channel['contracts'],
                    'contract_amount' => $channel['contract_amount'],
                    'paid_revenue' => $channel['paid_revenue'],
                    'gross_margin_percent' => $channel['gross_margin_percent'],
                    'non_target' => $channel['non_target'],
                    'duplicates' => $channel['duplicates'],
                    'unreachable' => $channel['unreachable'],
                    'notes' => $channel['notes'],
                ]);
            }

            $pdo->prepare('DELETE FROM report_details WHERE report_id = :report_id')
                ->execute(['report_id' => $reportId]);
            $insertDetail = $pdo->prepare(
                'INSERT INTO report_details
                 (report_id, detail_type, channel_key, title, payload_json, sort_order, created_at, updated_at)
                 VALUES
                 (:report_id, :detail_type, :channel_key, :title, :payload_json, :sort_order, NOW(), NOW())'
            );

            foreach ($groups as $group) {
                $insertDetail->execute([
                    'report_id' => $reportId,
                    'detail_type' => 'campaign_group',
                    'channel_key' => $group['channel_key'],
                    'title' => $group['title'],
                    'payload_json' => json_encode($group, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sort_order' => $group['sort_order'],
                ]);
            }
            foreach ($creatives as $creative) {
                $insertDetail->execute([
                    'report_id' => $reportId,
                    'detail_type' => 'creative',
                    'channel_key' => $creative['channel_key'],
                    'title' => $creative['title'],
                    'payload_json' => json_encode($creative, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'sort_order' => $creative['sort_order'],
                ]);
            }

            $pdo->commit();
            return $reportId;
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    public static function calculateChannel(array $channel): array
    {
        $spend = (float) ($channel['spend'] ?? 0);
        $impressions = (int) ($channel['impressions'] ?? 0);
        $clicks = (int) ($channel['clicks'] ?? 0);
        $leads = (int) ($channel['leads'] ?? 0);
        $qualified = (int) ($channel['qualified_leads'] ?? 0);
        $contracts = (int) ($channel['contracts'] ?? 0);
        $revenue = (float) ($channel['paid_revenue'] ?? 0);
        $margin = $channel['gross_margin_percent'] ?? null;
        $margin = $margin === null ? null : (float) $margin;

        return [
            'ctr' => self::percent($clicks, $impressions),
            'cpc' => self::divide($spend, $clicks),
            'click_to_lead' => self::percent($leads, $clicks),
            'cpl' => self::divide($spend, $leads),
            'cpql' => self::divide($spend, $qualified),
            'cost_per_contract' => self::divide($spend, $contracts),
            'lead_to_contract' => self::percent($contracts, $leads),
            'average_contract' => self::divide((float) ($channel['contract_amount'] ?? 0), $contracts),
            'roas' => self::divide($revenue, $spend),
            'romi' => $spend > 0 && $margin !== null
                ? (($revenue * ($margin / 100) - $spend) / $spend) * 100
                : null,
        ];
    }

    public static function calculateSummary(array $channels, bool $comparison): array
    {
        $keys = [
            'spend', 'impressions', 'clicks', 'leads', 'qualified_leads',
            'meetings', 'offers', 'contracts', 'contract_amount', 'paid_revenue',
            'non_target', 'duplicates', 'unreachable',
        ];
        $summary = array_fill_keys($keys, 0);
        $available = !$comparison;
        $hasRevenue = false;
        $marginComplete = true;
        $grossProfit = 0.0;

        foreach ($channels as $channel) {
            $source = $comparison ? ($channel['comparison'] ?? []) : $channel;
            if ($comparison && self::hasComparisonData($source)) {
                $available = true;
            }
            foreach ($keys as $key) {
                $summary[$key] += (float) ($source[$key] ?? 0);
            }
            $revenue = (float) ($source['paid_revenue'] ?? 0);
            if ($revenue > 0) {
                $hasRevenue = true;
                if (($source['gross_margin_percent'] ?? null) === null) {
                    $marginComplete = false;
                } else {
                    $grossProfit += $revenue * ((float) $source['gross_margin_percent'] / 100);
                }
            }
        }

        foreach (['impressions', 'clicks', 'leads', 'qualified_leads', 'meetings', 'offers', 'contracts', 'non_target', 'duplicates', 'unreachable'] as $key) {
            $summary[$key] = (int) $summary[$key];
        }
        foreach (['spend', 'contract_amount', 'paid_revenue'] as $key) {
            $summary[$key] = (float) $summary[$key];
        }
        $summary['available'] = $available;
        $summary['calculated'] = self::calculateChannel($summary);
        $summary['calculated']['romi'] = $summary['spend'] > 0 && $hasRevenue && $marginComplete
            ? (($grossProfit - $summary['spend']) / $summary['spend']) * 100
            : null;

        return $summary;
    }

    private function sanitizeChannels(string $reportType, array $channels): array
    {
        $allowed = $reportType === 'seo'
            ? ['seo']
            : ['direct', 'vk', 'avito', '2gis', 'yandex_business'];
        $result = [];

        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }
            $key = (string) ($channel['channel_key'] ?? '');
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            $sourceType = in_array(($channel['source_type'] ?? ''), self::SOURCE_TYPES, true)
                ? (string) $channel['source_type'] : 'manual';
            $result[$key] = [
                'channel_key' => $key,
                'channel_name' => self::CHANNELS[$key],
                'source_type' => $sourceType,
                'spend' => self::decimal($channel['spend'] ?? 0),
                'impressions' => self::integer($channel['impressions'] ?? 0),
                'clicks' => self::integer($channel['clicks'] ?? 0),
                'leads' => self::integer($channel['leads'] ?? 0),
                'qualified_leads' => self::integer($channel['qualified_leads'] ?? 0),
                'meetings' => self::integer($channel['meetings'] ?? 0),
                'offers' => self::integer($channel['offers'] ?? 0),
                'contracts' => self::integer($channel['contracts'] ?? 0),
                'contract_amount' => self::decimal($channel['contract_amount'] ?? 0),
                'paid_revenue' => self::decimal($channel['paid_revenue'] ?? 0),
                'gross_margin_percent' => self::nullablePercent($channel['gross_margin_percent'] ?? null),
                'non_target' => self::integer($channel['non_target'] ?? 0),
                'duplicates' => self::integer($channel['duplicates'] ?? 0),
                'unreachable' => self::integer($channel['unreachable'] ?? 0),
                'notes' => self::plain($channel['notes'] ?? '', 20000),
                'comparison' => self::sanitizeComparison(is_array($channel['comparison'] ?? null) ? $channel['comparison'] : []),
            ];
        }

        foreach ($allowed as $key) {
            if (!isset($result[$key])) {
                $result[$key] = [
                    'channel_key' => $key, 'channel_name' => self::CHANNELS[$key],
                    'source_type' => 'manual', 'spend' => 0.0, 'impressions' => 0,
                    'clicks' => 0, 'leads' => 0, 'qualified_leads' => 0,
                    'meetings' => 0, 'offers' => 0, 'contracts' => 0,
                    'contract_amount' => 0.0, 'paid_revenue' => 0.0,
                    'gross_margin_percent' => null, 'non_target' => 0,
                    'duplicates' => 0, 'unreachable' => 0, 'notes' => '',
                    'comparison' => self::sanitizeComparison([]),
                ];
            }
        }

        return array_values($result);
    }

    private function sanitizeGroups(array $groups, array $allowedKeys): array
    {
        $result = [];
        foreach (array_slice($groups, 0, 100) as $index => $group) {
            if (!is_array($group)) {
                continue;
            }
            $channel = (string) ($group['channel_key'] ?? '');
            $title = self::plain($group['title'] ?? '', 190);
            if (!in_array($channel, $allowedKeys, true) || $title === '') {
                continue;
            }
            $result[] = [
                'channel_key' => $channel,
                'title' => $title,
                'campaign_names' => self::plain($group['campaign_names'] ?? '', 10000),
                'spend' => self::decimal($group['spend'] ?? 0),
                'impressions' => self::integer($group['impressions'] ?? 0),
                'clicks' => self::integer($group['clicks'] ?? 0),
                'leads' => self::integer($group['leads'] ?? 0),
                'qualified_leads' => self::integer($group['qualified_leads'] ?? 0),
                'contracts' => self::integer($group['contracts'] ?? 0),
                'contract_amount' => self::decimal($group['contract_amount'] ?? 0),
                'paid_revenue' => self::decimal($group['paid_revenue'] ?? 0),
                'notes' => self::plain($group['notes'] ?? '', 20000),
                'sort_order' => $index,
            ];
        }
        return $result;
    }

    private function sanitizeCreatives(array $creatives, array $allowedKeys): array
    {
        $result = [];
        foreach (array_slice($creatives, 0, 100) as $index => $creative) {
            if (!is_array($creative)) {
                continue;
            }
            $channel = (string) ($creative['channel_key'] ?? '');
            $imageUrl = trim((string) ($creative['image_url'] ?? ''));
            if (!in_array($channel, $allowedKeys, true) || !str_starts_with($imageUrl, '/uploads/report-media/')) {
                continue;
            }
            $result[] = [
                'channel_key' => $channel,
                'title' => self::plain($creative['title'] ?? 'Пример объявления', 190),
                'caption' => self::plain($creative['caption'] ?? '', 2000),
                'image_url' => $imageUrl,
                'source_type' => ($creative['source_type'] ?? '') === 'api' ? 'api' : 'manual',
                'external_id' => self::plain($creative['external_id'] ?? '', 190),
                'external_url' => self::safeUrl($creative['external_url'] ?? ''),
                'sort_order' => $index,
            ];
        }
        return $result;
    }

    private static function normalizeStoredChannel(array $channel): array
    {
        foreach (['id', 'report_id', 'impressions', 'clicks', 'leads', 'qualified_leads', 'meetings', 'offers', 'contracts', 'non_target', 'duplicates', 'unreachable'] as $key) {
            $channel[$key] = (int) ($channel[$key] ?? 0);
        }
        foreach (['spend', 'contract_amount', 'paid_revenue'] as $key) {
            $channel[$key] = (float) ($channel[$key] ?? 0);
        }
        $channel['gross_margin_percent'] = $channel['gross_margin_percent'] === null
            ? null : (float) $channel['gross_margin_percent'];
        $comparison = json_decode((string) ($channel['comparison_json'] ?? ''), true);
        $channel['comparison'] = self::sanitizeComparison(is_array($comparison) ? $comparison : []);
        unset($channel['comparison_json']);
        $channel['calculated'] = self::calculateChannel($channel);
        $channel['comparison_calculated'] = self::calculateChannel(array_map(
            static fn($value) => $value ?? 0,
            $channel['comparison']
        ));
        return $channel;
    }

    private static function sanitizeComparison(array $data): array
    {
        return [
            'spend' => self::nullableDecimal($data['spend'] ?? null),
            'impressions' => self::nullableInteger($data['impressions'] ?? null),
            'clicks' => self::nullableInteger($data['clicks'] ?? null),
            'leads' => self::nullableInteger($data['leads'] ?? null),
            'qualified_leads' => self::nullableInteger($data['qualified_leads'] ?? null),
            'meetings' => self::nullableInteger($data['meetings'] ?? null),
            'offers' => self::nullableInteger($data['offers'] ?? null),
            'contracts' => self::nullableInteger($data['contracts'] ?? null),
            'contract_amount' => self::nullableDecimal($data['contract_amount'] ?? null),
            'paid_revenue' => self::nullableDecimal($data['paid_revenue'] ?? null),
            'gross_margin_percent' => self::nullablePercent($data['gross_margin_percent'] ?? null),
            'non_target' => self::nullableInteger($data['non_target'] ?? null),
            'duplicates' => self::nullableInteger($data['duplicates'] ?? null),
            'unreachable' => self::nullableInteger($data['unreachable'] ?? null),
        ];
    }

    private static function hasComparisonData(array $comparison): bool
    {
        foreach ($comparison as $value) {
            if ($value !== null) {
                return true;
            }
        }
        return false;
    }

    private static function rich(mixed $value): string
    {
        $html = trim((string) $value);
        if ($html === '') {
            return '';
        }
        if (!class_exists(DOMDocument::class)) {
            $html = strip_tags($html, '<p><br><strong><b><em><i><u><h2><h3><h4><ul><ol><li><a><img><blockquote><div><span>');
            $html = preg_replace('/\s(on\w+|style)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/iu', '', $html) ?? $html;
            return mb_substr($html, 0, 100000);
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML(
            '<?xml encoding="utf-8" ?><div id="report-rich-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        $root = $dom->getElementById('report-rich-root');
        if (!$root) {
            return '';
        }
        self::sanitizeRichChildren($root);
        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $dom->saveHTML($child);
        }
        return mb_substr($result, 0, 100000);
    }

    private static function sanitizeRichChildren(DOMNode $parent): void
    {
        $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'u', 'h2', 'h3', 'h4', 'ul', 'ol', 'li', 'a', 'img', 'blockquote', 'div', 'span'];
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->hasChildNodes()) {
                self::sanitizeRichChildren($child);
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
            foreach ($attributes as $attribute) {
                $child->removeAttribute($attribute);
            }

            if ($tag === 'a') {
                $href = self::safeUrl((string) $child->getAttribute('href'));
                if ($href !== '') {
                    $child->setAttribute('href', $href);
                    $child->setAttribute('rel', 'noopener noreferrer');
                    $child->setAttribute('target', '_blank');
                }
            }
            if ($tag === 'img') {
                $source = (string) $child->getAttribute('src');
                if (!str_starts_with($source, '/uploads/report-media/')) {
                    $parent->removeChild($child);
                    continue;
                }
                $child->setAttribute('src', $source);
                $child->setAttribute('alt', 'Изображение отчёта');
                $child->setAttribute('loading', 'lazy');
            }
        }
    }

    private static function safeUrl(mixed $value): string
    {
        $url = trim((string) $value);
        if ($url === '') {
            return '';
        }
        if (str_starts_with($url, '/') || preg_match('#^(https?://|mailto:)#i', $url)) {
            return mb_substr($url, 0, 1000);
        }
        return '';
    }

    private static function plain(mixed $value, int $limit): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $limit);
    }

    private static function decimal(mixed $value): float
    {
        return max(0, (float) str_replace([' ', ','], ['', '.'], trim((string) $value)));
    }

    private static function nullableDecimal(mixed $value): ?float
    {
        return $value === null || trim((string) $value) === '' ? null : self::decimal($value);
    }

    private static function integer(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private static function nullableInteger(mixed $value): ?int
    {
        return $value === null || trim((string) $value) === '' ? null : self::integer($value);
    }

    private static function nullablePercent(mixed $value): ?float
    {
        return $value === null || trim((string) $value) === ''
            ? null : min(100, self::decimal($value));
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private static function divide(float|int $numerator, float|int $denominator): ?float
    {
        return $denominator > 0 ? $numerator / $denominator : null;
    }

    private static function percent(int $numerator, int $denominator): ?float
    {
        return $denominator > 0 ? ($numerator / $denominator) * 100 : null;
    }
}
PHPFILE;

$richEditorsHtml = <<<'HTML'
                            <!-- REPORTS_STEP2 -->
                            <div class="report-rich-grid">
                                <div class="rich-field" data-rich-field="work_done">
                                    <span class="rich-field-label">Что сделано за период</span>
                                    <div class="rich-editor-shell">
                                        <div class="rich-toolbar">
                                            <button type="button" data-rich-command="bold"><strong>B</strong></button>
                                            <button type="button" data-rich-command="italic"><em>I</em></button>
                                            <button type="button" data-rich-command="underline"><u>U</u></button>
                                            <button type="button" data-rich-block="h3">H3</button>
                                            <button type="button" data-rich-command="insertUnorderedList">• Список</button>
                                            <button type="button" data-rich-command="insertOrderedList">1. Список</button>
                                            <button type="button" data-rich-link>Ссылка</button>
                                            <button type="button" data-rich-image>Изображение</button>
                                            <button type="button" data-rich-command="removeFormat">Очистить</button>
                                        </div>
                                        <div class="rich-editor" contenteditable="true" data-rich-editor="work_done" data-placeholder="Оптимизации, тесты, изменения кампаний, подготовленные ТЗ..."></div>
                                    </div>
                                </div>

                                <div class="rich-field" data-rich-field="next_plan">
                                    <span class="rich-field-label">План следующего периода</span>
                                    <div class="rich-editor-shell">
                                        <div class="rich-toolbar">
                                            <button type="button" data-rich-command="bold"><strong>B</strong></button>
                                            <button type="button" data-rich-command="italic"><em>I</em></button>
                                            <button type="button" data-rich-command="underline"><u>U</u></button>
                                            <button type="button" data-rich-block="h3">H3</button>
                                            <button type="button" data-rich-command="insertUnorderedList">• Список</button>
                                            <button type="button" data-rich-command="insertOrderedList">1. Список</button>
                                            <button type="button" data-rich-link>Ссылка</button>
                                            <button type="button" data-rich-image>Изображение</button>
                                            <button type="button" data-rich-command="removeFormat">Очистить</button>
                                        </div>
                                        <div class="rich-editor" contenteditable="true" data-rich-editor="next_plan" data-placeholder="Какие действия планируются и зачем..."></div>
                                    </div>
                                </div>

                                <div class="rich-field" data-rich-field="recommendations">
                                    <span class="rich-field-label">Рекомендации</span>
                                    <div class="rich-editor-shell">
                                        <div class="rich-toolbar">
                                            <button type="button" data-rich-command="bold"><strong>B</strong></button>
                                            <button type="button" data-rich-command="italic"><em>I</em></button>
                                            <button type="button" data-rich-command="underline"><u>U</u></button>
                                            <button type="button" data-rich-block="h3">H3</button>
                                            <button type="button" data-rich-command="insertUnorderedList">• Список</button>
                                            <button type="button" data-rich-command="insertOrderedList">1. Список</button>
                                            <button type="button" data-rich-link>Ссылка</button>
                                            <button type="button" data-rich-image>Изображение</button>
                                            <button type="button" data-rich-command="removeFormat">Очистить</button>
                                        </div>
                                        <div class="rich-editor" contenteditable="true" data-rich-editor="recommendations" data-placeholder="Что рекомендуем изменить в рекламе, сайте или обработке лидов..."></div>
                                    </div>
                                </div>

                                <div class="rich-field" data-rich-field="notes">
                                    <span class="rich-field-label">Комментарии и ограничения данных</span>
                                    <div class="rich-editor-shell">
                                        <div class="rich-toolbar">
                                            <button type="button" data-rich-command="bold"><strong>B</strong></button>
                                            <button type="button" data-rich-command="italic"><em>I</em></button>
                                            <button type="button" data-rich-command="underline"><u>U</u></button>
                                            <button type="button" data-rich-block="h3">H3</button>
                                            <button type="button" data-rich-command="insertUnorderedList">• Список</button>
                                            <button type="button" data-rich-command="insertOrderedList">1. Список</button>
                                            <button type="button" data-rich-link>Ссылка</button>
                                            <button type="button" data-rich-image>Изображение</button>
                                            <button type="button" data-rich-command="removeFormat">Очистить</button>
                                        </div>
                                        <div class="rich-editor" contenteditable="true" data-rich-editor="notes" data-placeholder="Звонки не подключены, договоры внесены вручную, часть данных отсутствует..."></div>
                                    </div>
                                </div>
                            </div>

HTML;

$apiUploadCode = <<<'PHPAPI'
    // REPORTS_STEP2_IMAGE_UPLOAD
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'report_image_upload') {
        Security::requireCsrf($_POST['csrf_token'] ?? null);
        $project = $projectRepository->firstActive();
        if (!$project) {
            Security::json(['error' => 'Проект не настроен.'], 422);
        }
        $file = $_FILES['image'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            Security::json(['error' => 'Не удалось загрузить изображение.'], 422);
        }
        if ((int) ($file['size'] ?? 0) <= 0 || (int) $file['size'] > 8 * 1024 * 1024) {
            Security::json(['error' => 'Размер изображения должен быть не больше 8 МБ.'], 422);
        }
        $temporary = (string) ($file['tmp_name'] ?? '');
        $mime = function_exists('finfo_open')
            ? (string) finfo_file(finfo_open(FILEINFO_MIME_TYPE), $temporary)
            : (string) mime_content_type($temporary);
        $extensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extensions[$mime])) {
            Security::json(['error' => 'Разрешены JPG, PNG, WEBP и GIF.'], 422);
        }
        $relativeDirectory = '/uploads/report-media/' . (int) $project['id'];
        $directory = __DIR__ . $relativeDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось создать каталог изображений.');
        }
        $filename = date('Ymd-His') . '-' . bin2hex(random_bytes(10)) . '.' . $extensions[$mime];
        $destination = $directory . '/' . $filename;
        if (!move_uploaded_file($temporary, $destination)) {
            throw new RuntimeException('Не удалось сохранить изображение.');
        }
        @chmod($destination, 0644);
        Security::json([
            'ok' => true,
            'url' => $relativeDirectory . '/' . $filename,
        ]);
    }

PHPAPI;

$apiPostCode = <<<'PHPAPI'
    // REPORTS_STEP1_API_POST
    if ($action === 'save_report') {
        $project = $projectRepository->firstActive();
        if (!$project) {
            Security::json(['error' => 'Проект не настроен.'], 422);
        }

        [$dateFrom, $dateTo] = validateDates(
            trim((string) ($input['date_from'] ?? '')),
            trim((string) ($input['date_to'] ?? ''))
        );
        $comparisonDateFrom = trim((string) ($input['comparison_date_from'] ?? ''));
        $comparisonDateTo = trim((string) ($input['comparison_date_to'] ?? ''));

        if (($comparisonDateFrom === '') !== ($comparisonDateTo === '')) {
            Security::json(['error' => 'Для сравнения укажите обе даты периода.'], 422);
        }
        if ($comparisonDateFrom !== '' && $comparisonDateTo !== '') {
            [$comparisonDateFrom, $comparisonDateTo] = validateDates($comparisonDateFrom, $comparisonDateTo);
        }

        $title = trim((string) ($input['title'] ?? ''));
        if (mb_strlen($title) > 190) {
            Security::json(['error' => 'Название отчёта не должно превышать 190 символов.'], 422);
        }

        $channels = is_array($input['channels'] ?? null) ? array_values($input['channels']) : [];
        $groups = is_array($input['campaign_groups'] ?? null) ? array_values($input['campaign_groups']) : [];
        $creatives = is_array($input['creatives'] ?? null) ? array_values($input['creatives']) : [];

        if (count($channels) > 10 || count($groups) > 100 || count($creatives) > 100) {
            Security::json(['error' => 'Слишком много элементов в отчёте.'], 422);
        }

        $repository = new ReportRepository();
        $reportId = $repository->save([
            'id' => (int) ($input['id'] ?? 0),
            'project_id' => (int) $project['id'],
            'title' => $title,
            'report_type' => trim((string) ($input['report_type'] ?? '')),
            'audience' => trim((string) ($input['audience'] ?? 'owner')),
            'status' => trim((string) ($input['status'] ?? 'draft')),
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
            'comparison_date_from' => $comparisonDateFrom ?: null,
            'comparison_date_to' => $comparisonDateTo ?: null,
            'work_done' => (string) ($input['work_done'] ?? ''),
            'next_plan' => (string) ($input['next_plan'] ?? ''),
            'recommendations' => (string) ($input['recommendations'] ?? ''),
            'notes' => (string) ($input['notes'] ?? ''),
            'channels' => $channels,
            'campaign_groups' => $groups,
            'creatives' => $creatives,
        ], Auth::id());

        $report = $repository->find($reportId, (int) $project['id']);
        Auth::audit('report_saved', [
            'project_id' => (int) $project['id'],
            'report_id' => $reportId,
            'report_type' => $report['report_type'] ?? null,
            'status' => $report['status'] ?? null,
            'campaign_groups' => count($groups),
            'creatives' => count($creatives),
        ]);

        Security::json([
            'ok' => true,
            'report' => $report,
            'message' => 'Отчёт сохранён.',
        ]);
    }

PHPAPI;

$reportsJs = <<'JS'
    /* REPORTS_STEP1_JS */
    const reportChannelsByType = {
        advertising_summary: [
            {key: 'direct', name: 'Яндекс Директ'},
            {key: 'vk', name: 'VK Реклама'},
            {key: 'avito', name: 'Авито'},
            {key: '2gis', name: '2ГИС'},
            {key: 'yandex_business', name: 'Яндекс Бизнес'}
        ],
        seo: [{key: 'seo', name: 'Органический поиск (SEO)'}]
    };
    const reportStatusLabels = {
        draft: 'Черновик', review: 'На проверке', approved: 'Утверждён',
        sent: 'Отправлен', archive: 'Архив'
    };
    const reportTypeLabels = {
        advertising_summary: 'Сводный рекламный отчёт', seo: 'SEO-отчёт'
    };
    const reportAudienceLabels = {
        owner: 'Собственник', marketer: 'Маркетолог', sales: 'РОП', client: 'Клиент'
    };
    const reportMoneyFormatter = new Intl.NumberFormat('ru-RU', {
        style: 'currency', currency: 'RUB', maximumFractionDigits: 0
    });
    let reportsLoaded = false;

    function reportNumberValue(value) {
        const parsed = Number(String(value ?? '').trim().replaceAll(' ', '').replace(',', '.'));
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }
    function reportNullableNumber(value) {
        const normalized = String(value ?? '').trim();
        if (normalized === '') return null;
        const parsed = Number(normalized.replaceAll(' ', '').replace(',', '.'));
        return Number.isFinite(parsed) ? Math.max(0, parsed) : null;
    }
    function reportInputValue(value) {
        return value === null || value === undefined || Number(value) === 0 ? '' : String(value);
    }
    function reportNullableInputValue(value) {
        return value === null || value === undefined ? '' : String(value);
    }
    function reportMoney(value) {
        return value === null || value === undefined ? '—' : reportMoneyFormatter.format(Number(value || 0));
    }
    function reportPercent(value) {
        return value === null || value === undefined ? '—' : `${number.format(Number(value || 0))}%`;
    }
    function reportMultiplier(value) {
        return value === null || value === undefined ? '—' : `${number.format(Number(value || 0))}×`;
    }
    function reportDate(value) {
        const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})$/);
        return match ? `${match[3]}.${match[2]}.${match[1]}` : String(value || '');
    }
    function reportSafeDivide(numerator, denominator) {
        return Number(denominator || 0) > 0 ? Number(numerator || 0) / Number(denominator) : null;
    }
    function reportChannelCalculations(channel) {
        const spend = Number(channel.spend || 0);
        const revenue = Number(channel.paid_revenue || 0);
        const margin = channel.gross_margin_percent;
        return {
            ctr: reportSafeDivide(Number(channel.clicks || 0) * 100, channel.impressions),
            cpc: reportSafeDivide(spend, channel.clicks),
            click_to_lead: reportSafeDivide(Number(channel.leads || 0) * 100, channel.clicks),
            cpl: reportSafeDivide(spend, channel.leads),
            cpql: reportSafeDivide(spend, channel.qualified_leads),
            cost_per_contract: reportSafeDivide(spend, channel.contracts),
            lead_to_contract: reportSafeDivide(Number(channel.contracts || 0) * 100, channel.leads),
            average_contract: reportSafeDivide(channel.contract_amount, channel.contracts),
            roas: reportSafeDivide(revenue, spend),
            romi: spend > 0 && margin !== null && margin !== undefined
                ? ((revenue * (Number(margin) / 100) - spend) / spend) * 100
                : null
        };
    }
    function reportHasComparison(comparison) {
        return Object.values(comparison || {}).some(value => value !== null && value !== undefined && value !== '');
    }
    function reportSummary(channels, comparison = false) {
        const keys = [
            'spend', 'impressions', 'clicks', 'leads', 'qualified_leads', 'meetings',
            'offers', 'contracts', 'contract_amount', 'paid_revenue', 'non_target',
            'duplicates', 'unreachable'
        ];
        const summary = Object.fromEntries(keys.map(key => [key, 0]));
        let available = !comparison;
        let grossProfit = 0;
        let hasRevenue = false;
        let marginComplete = true;

        channels.forEach(channel => {
            const source = comparison ? (channel.comparison || {}) : channel;
            if (comparison && reportHasComparison(source)) available = true;
            keys.forEach(key => summary[key] += Number(source[key] || 0));
            const revenue = Number(source.paid_revenue || 0);
            if (revenue > 0) {
                hasRevenue = true;
                if (source.gross_margin_percent === null || source.gross_margin_percent === undefined) {
                    marginComplete = false;
                } else {
                    grossProfit += revenue * (Number(source.gross_margin_percent) / 100);
                }
            }
        });
        summary.available = available;
        summary.calculated = reportChannelCalculations({...summary, gross_margin_percent: null});
        summary.calculated.romi = summary.spend > 0 && hasRevenue && marginComplete
            ? ((grossProfit - summary.spend) / summary.spend) * 100
            : null;
        return summary;
    }

    function reportComparisonBadge(current, previous, betterMode = 'higher') {
        if (previous === null || previous === undefined || !Number.isFinite(Number(previous))) {
            return '';
        }
        const currentNumber = Number(current || 0);
        const previousNumber = Number(previous || 0);
        if (currentNumber === previousNumber) {
            return '<span class="report-kpi-change neutral">Без изменений</span>';
        }
        if (betterMode === 'neutral') {
            return '';
        }
        const better = betterMode === 'lower'
            ? currentNumber < previousNumber
            : currentNumber > previousNumber;
        const percent = previousNumber !== 0
            ? Math.abs((currentNumber - previousNumber) / Math.abs(previousNumber) * 100)
            : null;
        const suffix = percent === null ? '' : ` на ${number.format(percent)}%`;
        return `<span class="report-kpi-change ${better ? 'positive' : 'negative'}">${better ? '↑ лучше' : '↓ хуже'}${escapeHtml(suffix)}</span>`;
    }

    function reportCleanRichHtml(html) {
        const template = document.createElement('template');
        template.innerHTML = String(html || '');
        template.content.querySelectorAll('script,style,iframe,object,embed,form,input,button').forEach(node => node.remove());
        template.content.querySelectorAll('*').forEach(node => {
            [...node.attributes].forEach(attribute => {
                const name = attribute.name.toLowerCase();
                if (!['href', 'src', 'alt', 'target', 'rel', 'loading'].includes(name)) {
                    node.removeAttribute(attribute.name);
                }
                if (name.startsWith('on')) node.removeAttribute(attribute.name);
            });
            if (node.tagName === 'IMG' && !String(node.getAttribute('src') || '').startsWith('/uploads/report-media/')) {
                node.remove();
            }
        });
        return template.innerHTML;
    }
    function richEditor(field) {
        return $(`[data-rich-editor="${field}"]`);
    }
    function richEditorValue(field) {
        const editor = richEditor(field);
        if (!editor || editor.textContent.trim() === '') return '';
        return reportCleanRichHtml(editor.innerHTML);
    }
    function setRichEditorValue(field, value) {
        const editor = richEditor(field);
        if (editor) editor.innerHTML = reportCleanRichHtml(value || '');
    }

    async function uploadReportImage(file) {
        if (!file || !String(file.type || '').startsWith('image/')) {
            throw new Error('Выберите изображение.');
        }
        if (file.size > 8 * 1024 * 1024) {
            throw new Error('Размер изображения должен быть не больше 8 МБ.');
        }
        const formData = new FormData();
        formData.append('csrf_token', csrf);
        formData.append('image', file);
        const result = await api('/api.php?action=report_image_upload', {
            method: 'POST', body: formData
        });
        return result.url;
    }

    function chooseImages(multiple = false) {
        return new Promise(resolve => {
            const input = document.createElement('input');
            input.type = 'file';
            input.accept = 'image/jpeg,image/png,image/webp,image/gif';
            input.multiple = multiple;
            input.addEventListener('change', () => resolve([...input.files]));
            input.click();
        });
    }

    function campaignGroupHtml(channelKey, group = {}) {
        return `
            <article class="campaign-group-row" data-campaign-group data-channel-key="${escapeHtml(channelKey)}">
                <div class="campaign-group-top">
                    <label><span>Название группы</span><input data-group-field="title" value="${escapeHtml(group.title || '')}" placeholder="Например: Поиск — имплантация"></label>
                    <button type="button" class="button button-danger-small" data-remove-group>Удалить</button>
                </div>
                <label class="campaign-group-campaigns"><span>Кампании в группе</span><textarea rows="2" data-group-field="campaign_names" placeholder="Названия или ID кампаний, по одной в строке">${escapeHtml(group.campaign_names || '')}</textarea></label>
                <div class="campaign-group-grid">
                    ${[
                        ['spend', 'Расход, ₽'], ['impressions', 'Показы'], ['clicks', 'Клики'],
                        ['leads', 'Заявки'], ['qualified_leads', 'Квал. лиды'], ['contracts', 'Договоры'],
                        ['contract_amount', 'Сумма договоров, ₽'], ['paid_revenue', 'Оплачено, ₽']
                    ].map(([field, label]) => `<label><span>${label}</span><input type="number" min="0" step="${field.includes('amount') || field === 'spend' || field === 'paid_revenue' ? '0.01' : '1'}" data-group-field="${field}" value="${escapeHtml(reportInputValue(group[field]))}"></label>`).join('')}
                </div>
                <label class="campaign-group-campaigns"><span>Комментарий</span><textarea rows="2" data-group-field="notes">${escapeHtml(group.notes || '')}</textarea></label>
            </article>`;
    }

    function creativeHtml(channelKey, creative) {
        return `
            <article class="report-creative-card" data-report-creative data-channel-key="${escapeHtml(channelKey)}" data-image-url="${escapeHtml(creative.image_url || '')}" data-source-type="${escapeHtml(creative.source_type || 'manual')}">
                <img src="${escapeHtml(creative.image_url || '')}" alt="Пример объявления">
                <label><span>Название</span><input data-creative-field="title" value="${escapeHtml(creative.title || 'Пример объявления')}"></label>
                <label><span>Комментарий</span><textarea rows="2" data-creative-field="caption">${escapeHtml(creative.caption || '')}</textarea></label>
                <button type="button" class="button button-danger-small" data-remove-creative>Удалить</button>
            </article>`;
    }

    function renderReportChannels(type, storedChannels = [], storedGroups = [], storedCreatives = []) {
        const root = $('#reportChannels');
        if (!root) return;
        const definitions = reportChannelsByType[type] || reportChannelsByType.advertising_summary;
        const stored = Object.fromEntries((storedChannels || []).map(channel => [channel.channel_key, channel]));

        root.innerHTML = definitions.map(definition => {
            const channel = stored[definition.key] || {
                channel_key: definition.key, channel_name: definition.name, source_type: 'manual',
                spend: 0, impressions: 0, clicks: 0, leads: 0, qualified_leads: 0,
                meetings: 0, offers: 0, contracts: 0, contract_amount: 0, paid_revenue: 0,
                gross_margin_percent: null, non_target: 0, duplicates: 0, unreachable: 0,
                notes: '', comparison: {}
            };
            const comparison = channel.comparison || {};
            const groups = (storedGroups || []).filter(group => group.channel_key === definition.key);
            const creatives = (storedCreatives || []).filter(creative => creative.channel_key === definition.key);
            const field = (name, label, step = '1') => `<label><span>${label}</span><input type="number" min="0" step="${step}" data-field="${name}" value="${escapeHtml(reportInputValue(channel[name]))}"></label>`;
            const comparisonField = (name, label, step = '1') => `<label><span>${label}</span><input type="number" min="0" step="${step}" data-comparison-field="${name}" value="${escapeHtml(reportNullableInputValue(comparison[name]))}"></label>`;

            return `
                <article class="report-channel-card" data-channel-key="${escapeHtml(definition.key)}" data-channel-name="${escapeHtml(definition.name)}">
                    <div class="report-channel-head">
                        <div><strong>${escapeHtml(definition.name)}</strong><small>Итоги канала, воронка продаж и детализация кампаний</small></div>
                        <label class="report-source-label"><span>Источник</span><select data-field="source_type">
                            <option value="manual" ${channel.source_type === 'manual' ? 'selected' : ''}>Ручной ввод</option>
                            <option value="import" ${channel.source_type === 'import' ? 'selected' : ''}>Импорт</option>
                            <option value="api" ${channel.source_type === 'api' ? 'selected' : ''}>API</option>
                        </select></label>
                    </div>
                    <div class="report-channel-grid">
                        ${field('spend', 'Расход, ₽', '0.01')}${field('impressions', 'Показы')}${field('clicks', 'Клики / переходы')}
                        ${field('leads', 'Уникальные заявки')}${field('qualified_leads', 'Квалифицированные лиды')}${field('contracts', 'Договоры')}
                        ${field('contract_amount', 'Сумма договоров, ₽', '0.01')}${field('paid_revenue', 'Оплаченная выручка, ₽', '0.01')}${field('gross_margin_percent', 'Валовая маржа, %', '0.01')}
                    </div>
                    <details class="report-comparison">
                        <summary>Данные выбранного прошлого периода</summary>
                        <p class="muted">Эти значения используются в карточках сравнения. Период задаётся в верхней части отчёта.</p>
                        <div class="report-channel-grid">
                            ${comparisonField('spend', 'Расход, ₽', '0.01')}${comparisonField('impressions', 'Показы')}${comparisonField('clicks', 'Клики')}
                            ${comparisonField('leads', 'Заявки')}${comparisonField('qualified_leads', 'Квал. лиды')}${comparisonField('contracts', 'Договоры')}
                            ${comparisonField('contract_amount', 'Сумма договоров, ₽', '0.01')}${comparisonField('paid_revenue', 'Оплаченная выручка, ₽', '0.01')}${comparisonField('gross_margin_percent', 'Валовая маржа, %', '0.01')}
                        </div>
                    </details>
                    <details class="report-advanced">
                        <summary>Дополнительные показатели качества</summary>
                        <div class="report-channel-grid">
                            ${field('meetings', 'Встречи / замеры')}${field('offers', 'Коммерческие предложения')}${field('non_target', 'Нецелевые обращения')}
                            ${field('duplicates', 'Дубли и спам')}${field('unreachable', 'Не удалось связаться')}
                        </div>
                        <label class="report-channel-notes"><span>Комментарий по каналу</span><textarea rows="3" data-field="notes">${escapeHtml(channel.notes || '')}</textarea></label>
                    </details>
                    <section class="campaign-groups-section">
                        <div class="channel-subsection-head"><div><strong>Группы рекламных кампаний</strong><p class="muted">Детализация заявок и стоимости по направлениям или типам кампаний.</p></div><div class="channel-subsection-actions"><button type="button" class="button" data-sync-groups>Подставить итоги</button><button type="button" class="button" data-add-group>Добавить группу</button></div></div>
                        <div class="campaign-groups-list" data-campaign-groups>${groups.map(group => campaignGroupHtml(definition.key, group)).join('')}</div>
                    </section>
                    <section class="report-creatives-section">
                        <div class="channel-subsection-head"><div><strong>Примеры рекламных объявлений</strong><p class="muted">Сейчас можно загрузить скриншоты. Позже сюда будут подставляться объявления из API.</p></div><button type="button" class="button" data-upload-creative>Загрузить скриншоты</button></div>
                        <div class="report-creatives-grid" data-creatives>${creatives.map(creative => creativeHtml(definition.key, creative)).join('')}</div>
                    </section>
                </article>`;
        }).join('');
    }

    function readReportChannels() {
        return $$('.report-channel-card').map(card => {
            const value = field => card.querySelector(`[data-field="${field}"]`)?.value ?? '';
            const comparisonValue = field => card.querySelector(`[data-comparison-field="${field}"]`)?.value ?? '';
            return {
                channel_key: card.dataset.channelKey,
                channel_name: card.dataset.channelName,
                source_type: value('source_type') || 'manual',
                spend: reportNumberValue(value('spend')),
                impressions: reportNumberValue(value('impressions')),
                clicks: reportNumberValue(value('clicks')),
                leads: reportNumberValue(value('leads')),
                qualified_leads: reportNumberValue(value('qualified_leads')),
                meetings: reportNumberValue(value('meetings')),
                offers: reportNumberValue(value('offers')),
                contracts: reportNumberValue(value('contracts')),
                contract_amount: reportNumberValue(value('contract_amount')),
                paid_revenue: reportNumberValue(value('paid_revenue')),
                gross_margin_percent: reportNullableNumber(value('gross_margin_percent')),
                non_target: reportNumberValue(value('non_target')),
                duplicates: reportNumberValue(value('duplicates')),
                unreachable: reportNumberValue(value('unreachable')),
                notes: value('notes').trim(),
                comparison: {
                    spend: reportNullableNumber(comparisonValue('spend')),
                    impressions: reportNullableNumber(comparisonValue('impressions')),
                    clicks: reportNullableNumber(comparisonValue('clicks')),
                    leads: reportNullableNumber(comparisonValue('leads')),
                    qualified_leads: reportNullableNumber(comparisonValue('qualified_leads')),
                    contracts: reportNullableNumber(comparisonValue('contracts')),
                    contract_amount: reportNullableNumber(comparisonValue('contract_amount')),
                    paid_revenue: reportNullableNumber(comparisonValue('paid_revenue')),
                    gross_margin_percent: reportNullableNumber(comparisonValue('gross_margin_percent'))
                }
            };
        });
    }

    function readCampaignGroups() {
        return $$('[data-campaign-group]').map((row, index) => {
            const value = field => row.querySelector(`[data-group-field="${field}"]`)?.value ?? '';
            return {
                channel_key: row.dataset.channelKey,
                title: value('title').trim(),
                campaign_names: value('campaign_names').trim(),
                spend: reportNumberValue(value('spend')),
                impressions: reportNumberValue(value('impressions')),
                clicks: reportNumberValue(value('clicks')),
                leads: reportNumberValue(value('leads')),
                qualified_leads: reportNumberValue(value('qualified_leads')),
                contracts: reportNumberValue(value('contracts')),
                contract_amount: reportNumberValue(value('contract_amount')),
                paid_revenue: reportNumberValue(value('paid_revenue')),
                notes: value('notes').trim(),
                sort_order: index
            };
        }).filter(group => group.title !== '');
    }

    function readCreatives() {
        return $$('[data-report-creative]').map((card, index) => ({
            channel_key: card.dataset.channelKey,
            image_url: card.dataset.imageUrl,
            source_type: card.dataset.sourceType || 'manual',
            title: card.querySelector('[data-creative-field="title"]')?.value.trim() || 'Пример объявления',
            caption: card.querySelector('[data-creative-field="caption"]')?.value.trim() || '',
            sort_order: index
        }));
    }

    function collectReportPayload() {
        const form = $('#reportForm');
        if (!form) throw new Error('Форма отчёта не найдена.');
        return {
            csrf_token: csrf,
            id: Number(form.elements.report_id.value || 0),
            project_id: Number(form.elements.project_id.value || 0),
            title: form.elements.title.value.trim(),
            report_type: form.elements.report_type.value,
            audience: form.elements.audience.value,
            status: form.elements.status.value,
            date_from: form.elements.date_from.value,
            date_to: form.elements.date_to.value,
            comparison_date_from: form.elements.comparison_date_from.value,
            comparison_date_to: form.elements.comparison_date_to.value,
            work_done: richEditorValue('work_done'),
            next_plan: richEditorValue('next_plan'),
            recommendations: richEditorValue('recommendations'),
            notes: richEditorValue('notes'),
            channels: readReportChannels(),
            campaign_groups: readCampaignGroups(),
            creatives: readCreatives()
        };
    }

    function resetReportForm() {
        const form = $('#reportForm');
        if (!form) return;
        form.reset();
        form.elements.report_id.value = '0';
        form.elements.report_type.value = 'advertising_summary';
        form.elements.audience.value = 'owner';
        form.elements.status.value = 'draft';
        const dates = currentDates();
        form.elements.date_from.value = dates.date1 || '';
        form.elements.date_to.value = dates.date2 || '';
        form.elements.comparison_date_from.value = '';
        form.elements.comparison_date_to.value = '';
        ['work_done', 'next_plan', 'recommendations', 'notes'].forEach(field => setRichEditorValue(field, ''));
        $('#reportEditorTitle').textContent = 'Новый отчёт';
        $('#reportFormMessage').className = '';
        $('#reportFormMessage').textContent = '';
        renderReportChannels('advertising_summary', [], [], []);
        $('#reportPreview')?.classList.add('hidden');
    }

    function fillReportForm(report) {
        const form = $('#reportForm');
        if (!form) return;
        form.elements.report_id.value = String(report.id || 0);
        form.elements.title.value = report.title || '';
        form.elements.report_type.value = report.report_type || 'advertising_summary';
        form.elements.audience.value = report.audience || 'owner';
        form.elements.status.value = report.status || 'draft';
        form.elements.date_from.value = report.date_from || '';
        form.elements.date_to.value = report.date_to || '';
        form.elements.comparison_date_from.value = report.comparison_date_from || '';
        form.elements.comparison_date_to.value = report.comparison_date_to || '';
        setRichEditorValue('work_done', report.work_done);
        setRichEditorValue('next_plan', report.next_plan);
        setRichEditorValue('recommendations', report.recommendations);
        setRichEditorValue('notes', report.notes);
        $('#reportEditorTitle').textContent = report.title || 'Редактирование отчёта';
        renderReportChannels(report.report_type, report.channels || [], report.campaign_groups || [], report.creatives || []);
    }

    function renderReportsList(reports) {
        const root = $('#reportsList');
        if (!root) return;
        if (!reports.length) {
            root.innerHTML = '<div class="reports-empty"><strong>Отчётов пока нет</strong><span>Создайте первый черновик.</span></div>';
            return;
        }
        root.innerHTML = reports.map(report => `
            <button type="button" class="report-list-item" data-report-id="${report.id}">
                <span class="report-list-top"><strong>${escapeHtml(report.title)}</strong><em class="report-status report-status-${escapeHtml(report.status)}">${escapeHtml(reportStatusLabels[report.status] || report.status)}</em></span>
                <span class="report-list-meta">${escapeHtml(reportTypeLabels[report.report_type] || report.report_type)} · ${escapeHtml(reportDate(report.date_from))}—${escapeHtml(reportDate(report.date_to))}</span>
                <span class="report-list-kpi">Расход: ${escapeHtml(reportMoney(report.spend))} · Заявки: ${escapeHtml(number.format(report.leads || 0))} · Договоры: ${escapeHtml(number.format(report.contracts || 0))}</span>
            </button>`).join('');
    }

    async function loadReports(force = false) {
        if (!$('#reportForm') || (reportsLoaded && !force)) return;
        const root = $('#reportsList');
        if (root) root.innerHTML = '<span class="muted">Загрузка...</span>';
        try {
            const result = await api('/api.php?action=reports_list');
            renderReportsList(result.reports || []);
            reportsLoaded = true;
        } catch (error) {
            if (root) root.innerHTML = `<div class="alert alert-error">${escapeHtml(error.message)}</div>`;
        }
    }

    async function openReport(reportId) {
        const message = $('#reportsMessage');
        try {
            const result = await api(`/api.php?action=report_get&id=${encodeURIComponent(reportId)}`);
            fillReportForm(result.report);
            renderReportPreview(result.report);
            message.className = 'quality-banner ok';
            message.textContent = 'Отчёт загружен из истории.';
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    }

    function reportKpiCard(title, current, previous, formatter, note, betterMode = 'higher') {
        const hasPrevious = previous !== null && previous !== undefined;
        return `<article class="report-kpi-card">
            <span class="report-kpi-title">${escapeHtml(title)}</span>
            <strong>${escapeHtml(formatter(current))}</strong>
            <small>${escapeHtml(note)}</small>
            ${hasPrevious ? `<div class="report-kpi-previous">Прошлый период: ${escapeHtml(formatter(previous))}</div>${reportComparisonBadge(current, previous, betterMode)}` : '<div class="report-kpi-previous muted">Нет данных прошлого периода</div>'}
        </article>`;
    }

    function renderGroupPreview(groups) {
        if (!groups.length) return '';
        return `<section class="report-detail-section"><h3>Группы рекламных кампаний</h3><div class="table-scroll report-detail-table"><table class="data-table"><thead><tr><th>Группа</th><th>Кампании</th><th class="num">Расход</th><th class="num">Клики</th><th class="num">Заявки</th><th class="num">Квал.</th><th class="num">Договоры</th><th class="num">CPL</th><th class="num">Цена договора</th></tr></thead><tbody>${groups.map(group => {
            const calculated = group.calculated || reportChannelCalculations(group);
            return `<tr><td><strong>${escapeHtml(group.title)}</strong></td><td class="report-campaign-names">${escapeHtml(group.campaign_names || '—')}</td><td class="num">${escapeHtml(reportMoney(group.spend))}</td><td class="num">${escapeHtml(number.format(group.clicks || 0))}</td><td class="num">${escapeHtml(number.format(group.leads || 0))}</td><td class="num">${escapeHtml(number.format(group.qualified_leads || 0))}</td><td class="num">${escapeHtml(number.format(group.contracts || 0))}</td><td class="num">${escapeHtml(reportMoney(calculated.cpl))}</td><td class="num">${escapeHtml(reportMoney(calculated.cost_per_contract))}</td></tr>`;
        }).join('')}</tbody></table></div></section>`;
    }

    function renderCreativePreview(creatives) {
        if (!creatives.length) return '';
        return `<section class="report-detail-section"><h3>Примеры рекламных объявлений</h3><div class="report-preview-creatives">${creatives.map(creative => `<figure><img src="${escapeHtml(creative.image_url)}" alt="${escapeHtml(creative.title || 'Объявление')}"><figcaption><strong>${escapeHtml(creative.title || 'Пример объявления')}</strong>${creative.caption ? `<span>${escapeHtml(creative.caption)}</span>` : ''}</figcaption></figure>`).join('')}</div></section>`;
    }

    function renderReportPreview(payload) {
        const root = $('#reportPreviewContent');
        const preview = $('#reportPreview');
        if (!root || !preview) return;
        const channels = payload.channels || [];
        const summary = payload.summary || reportSummary(channels, false);
        const previous = payload.comparison_summary || reportSummary(channels, true);
        const calculated = summary.calculated || reportSummary(channels, false).calculated;
        const previousCalculated = previous.calculated || {};
        const comparisonAvailable = Boolean(previous.available) && Boolean(payload.comparison_date_from && payload.comparison_date_to);
        const previousValue = (value) => comparisonAvailable ? value : null;
        const title = payload.title || reportTypeLabels[payload.report_type] || 'Отчёт';
        const cards = [
            reportKpiCard('Расходы', summary.spend, previousValue(previous.spend), reportMoney, 'Все каналы', 'neutral'),
            reportKpiCard('Заявки', summary.leads, previousValue(previous.leads), value => number.format(value || 0), 'Уникальные', 'higher'),
            reportKpiCard('Квалифицированные', summary.qualified_leads, previousValue(previous.qualified_leads), value => number.format(value || 0), 'Целевые лиды', 'higher'),
            reportKpiCard('Договоры', summary.contracts, previousValue(previous.contracts), value => number.format(value || 0), 'Продажи', 'higher'),
            reportKpiCard('CPL', calculated.cpl, previousValue(previousCalculated.cpl), reportMoney, 'Расход / заявки', 'lower'),
            reportKpiCard('CPQL', calculated.cpql, previousValue(previousCalculated.cpql), reportMoney, 'Расход / квалифицированные', 'lower'),
            reportKpiCard('Стоимость договора', calculated.cost_per_contract, previousValue(previousCalculated.cost_per_contract), reportMoney, 'Расход / договоры', 'lower'),
            reportKpiCard('Оплаченная выручка', summary.paid_revenue, previousValue(previous.paid_revenue), reportMoney, 'Фактические оплаты', 'higher'),
            reportKpiCard('ROAS', calculated.roas, previousValue(previousCalculated.roas), reportMultiplier, 'Выручка / расход', 'higher'),
            reportKpiCard('ROMI', calculated.romi, previousValue(previousCalculated.romi), reportPercent, 'По валовой прибыли', 'higher')
        ];
        const channelRows = channels.map(channel => {
            const metrics = channel.calculated || reportChannelCalculations(channel);
            return `<tr><td><strong>${escapeHtml(channel.channel_name)}</strong><small class="report-source-badge">${escapeHtml(channel.source_type || 'manual')}</small></td><td class="num">${escapeHtml(reportMoney(channel.spend))}</td><td class="num">${escapeHtml(number.format(channel.clicks || 0))}</td><td class="num">${escapeHtml(number.format(channel.leads || 0))}</td><td class="num">${escapeHtml(number.format(channel.qualified_leads || 0))}</td><td class="num">${escapeHtml(number.format(channel.contracts || 0))}</td><td class="num">${escapeHtml(reportMoney(metrics.cpl))}</td><td class="num">${escapeHtml(reportMoney(metrics.cpql))}</td><td class="num">${escapeHtml(reportMoney(metrics.cost_per_contract))}</td><td class="num">${escapeHtml(reportMoney(channel.paid_revenue))}</td><td class="num">${escapeHtml(reportMultiplier(metrics.roas))}</td></tr>`;
        }).join('');
        const textBlock = (blockTitle, value) => value ? `<section class="report-preview-text"><h3>${escapeHtml(blockTitle)}</h3><div class="report-rich-content">${reportCleanRichHtml(value)}</div></section>` : '';
        const groupsByChannel = Object.groupBy
            ? Object.groupBy(payload.campaign_groups || [], group => group.channel_key)
            : (payload.campaign_groups || []).reduce((acc, group) => ((acc[group.channel_key] ||= []).push(group), acc), {});
        const creativesByChannel = Object.groupBy
            ? Object.groupBy(payload.creatives || [], creative => creative.channel_key)
            : (payload.creatives || []).reduce((acc, creative) => ((acc[creative.channel_key] ||= []).push(creative), acc), {});
        const details = channels.map(channel => {
            const groups = groupsByChannel[channel.channel_key] || [];
            const creatives = creativesByChannel[channel.channel_key] || [];
            if (!groups.length && !creatives.length) return '';
            return `<section class="report-channel-details"><h2>${escapeHtml(channel.channel_name)}</h2>${renderGroupPreview(groups)}${renderCreativePreview(creatives)}</section>`;
        }).join('');

        root.innerHTML = `
            <header class="report-document-head"><p class="eyebrow">Мир сайтов</p><h1>${escapeHtml(title)}</h1><div class="report-document-meta"><span>Период: ${escapeHtml(reportDate(payload.date_from))}—${escapeHtml(reportDate(payload.date_to))}</span><span>Получатель: ${escapeHtml(reportAudienceLabels[payload.audience] || payload.audience)}</span>${payload.comparison_date_from && payload.comparison_date_to ? `<span>Сравнение: ${escapeHtml(reportDate(payload.comparison_date_from))}—${escapeHtml(reportDate(payload.comparison_date_to))}</span>` : ''}</div></header>
            <div class="report-kpi-grid">${cards.join('')}</div>
            <section class="report-preview-section"><div class="reports-section-head"><div><strong>Результаты по каналам</strong><p class="muted">Заявки и договоры не подменяются микроконверсиями.</p></div></div><div class="table-scroll report-preview-table"><table class="data-table"><thead><tr><th>Канал</th><th class="num">Расход</th><th class="num">Клики</th><th class="num">Заявки</th><th class="num">Квал.</th><th class="num">Договоры</th><th class="num">CPL</th><th class="num">CPQL</th><th class="num">Цена договора</th><th class="num">Выручка</th><th class="num">ROAS</th></tr></thead><tbody>${channelRows}</tbody></table></div></section>
            ${details}
            <div class="report-preview-text-grid">${textBlock('Что сделано за период', payload.work_done)}${textBlock('План следующего периода', payload.next_plan)}${textBlock('Рекомендации', payload.recommendations)}${textBlock('Комментарии и ограничения данных', payload.notes)}</div>
            <section class="report-formulas-note"><strong>Расчёты:</strong> CPL = расходы / заявки; CPQL = расходы / квалифицированные лиды; стоимость договора = расходы / договоры; ROAS = оплаченная выручка / расходы. ROMI рассчитывается только при заполненной валовой марже.</section>`;
        preview.classList.remove('hidden');
        preview.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    $('.nav-link[data-section="reports"]')?.addEventListener('click', () => loadReports());
    $('#newReport')?.addEventListener('click', () => { resetReportForm(); showSection('reports'); });
    $('#reportsList')?.addEventListener('click', event => {
        const item = event.target.closest('[data-report-id]');
        if (item) openReport(Number(item.dataset.reportId));
    });
    $('#reportForm [name="report_type"]')?.addEventListener('change', event => renderReportChannels(event.currentTarget.value, [], [], []));

    $('#reportForm')?.addEventListener('click', async event => {
        const toolbarButton = event.target.closest('.rich-toolbar button');
        if (toolbarButton) {
            event.preventDefault();
            const field = toolbarButton.closest('[data-rich-field]')?.dataset.richField;
            const editor = richEditor(field);
            if (!editor) return;
            editor.focus();
            if (toolbarButton.dataset.richCommand) {
                document.execCommand(toolbarButton.dataset.richCommand, false, null);
            } else if (toolbarButton.dataset.richBlock) {
                document.execCommand('formatBlock', false, toolbarButton.dataset.richBlock);
            } else if (toolbarButton.hasAttribute('data-rich-link')) {
                const link = prompt('Адрес ссылки:');
                if (link) document.execCommand('createLink', false, link);
            } else if (toolbarButton.hasAttribute('data-rich-image')) {
                const selection = window.getSelection();
                const savedRange = selection?.rangeCount ? selection.getRangeAt(0).cloneRange() : null;
                const files = await chooseImages(false);
                if (!files.length) return;
                try {
                    toolbarButton.classList.add('loading');
                    const url = await uploadReportImage(files[0]);
                    editor.focus();
                    if (savedRange && selection) {
                        selection.removeAllRanges(); selection.addRange(savedRange);
                    }
                    document.execCommand('insertHTML', false, `<p><img src="${escapeHtml(url)}" alt="Изображение отчёта"></p>`);
                } catch (error) {
                    alert(error.message);
                } finally {
                    toolbarButton.classList.remove('loading');
                }
            }
            return;
        }

        const addGroup = event.target.closest('[data-add-group]');
        if (addGroup) {
            const card = addGroup.closest('.report-channel-card');
            card.querySelector('[data-campaign-groups]').insertAdjacentHTML('beforeend', campaignGroupHtml(card.dataset.channelKey));
            return;
        }
        const removeGroup = event.target.closest('[data-remove-group]');
        if (removeGroup) {
            removeGroup.closest('[data-campaign-group]').remove();
            return;
        }
        const syncGroups = event.target.closest('[data-sync-groups]');
        if (syncGroups) {
            const card = syncGroups.closest('.report-channel-card');
            const rows = $$('[data-campaign-group]', card);
            ['spend', 'impressions', 'clicks', 'leads', 'qualified_leads', 'contracts', 'contract_amount', 'paid_revenue'].forEach(field => {
                const total = rows.reduce((sum, row) => sum + reportNumberValue(row.querySelector(`[data-group-field="${field}"]`)?.value), 0);
                const input = card.querySelector(`[data-field="${field}"]`);
                if (input) input.value = total || '';
            });
            return;
        }
        const uploadCreative = event.target.closest('[data-upload-creative]');
        if (uploadCreative) {
            const card = uploadCreative.closest('.report-channel-card');
            const files = await chooseImages(true);
            for (const file of files) {
                try {
                    uploadCreative.classList.add('loading');
                    const url = await uploadReportImage(file);
                    card.querySelector('[data-creatives]').insertAdjacentHTML('beforeend', creativeHtml(card.dataset.channelKey, {
                        image_url: url, title: file.name.replace(/\.[^.]+$/, ''), caption: '', source_type: 'manual'
                    }));
                } catch (error) {
                    alert(error.message);
                    break;
                } finally {
                    uploadCreative.classList.remove('loading');
                }
            }
            return;
        }
        const removeCreative = event.target.closest('[data-remove-creative]');
        if (removeCreative) removeCreative.closest('[data-report-creative]').remove();
    });

    $('#previewReport')?.addEventListener('click', () => {
        try {
            const payload = collectReportPayload();
            if (!payload.date_from || !payload.date_to) throw new Error('Укажите период отчёта.');
            renderReportPreview(payload);
        } catch (error) {
            const message = $('#reportFormMessage');
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    });
    $('#printReport')?.addEventListener('click', () => window.print());
    $('#reportForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const message = $('#reportFormMessage');
        const submit = form.querySelector('button[type="submit"]');
        submit.disabled = true;
        message.className = '';
        message.textContent = 'Сохранение...';
        try {
            const payload = collectReportPayload();
            if (!payload.date_from || !payload.date_to) throw new Error('Укажите период отчёта.');
            const result = await api('/api.php?action=save_report', {method: 'POST', body: JSON.stringify(payload)});
            fillReportForm(result.report);
            renderReportPreview(result.report);
            message.className = 'alert alert-success';
            message.textContent = result.message;
            reportsLoaded = false;
            await loadReports(true);
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        } finally {
            submit.disabled = false;
        }
    });
    if ($('#reportForm')) resetReportForm();
JS;

$css = <<<'CSS'

/* REPORTS_STEP2_CSS */
.report-rich-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(360px, 1fr));
    gap: 16px;
}
.rich-field { min-width: 0; }
.rich-field-label {
    display: block;
    margin-bottom: 7px;
    color: var(--muted);
    font-size: 13px;
}
.rich-editor-shell {
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: white;
}
.rich-editor-shell:focus-within {
    border-color: #9bbcff;
    box-shadow: 0 0 0 4px rgba(20, 99, 255, .08);
}
.rich-toolbar {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    padding: 8px;
    border-bottom: 1px solid var(--line);
    background: #f8fafc;
}
.rich-toolbar button {
    border: 1px solid var(--line);
    border-radius: 7px;
    background: white;
    padding: 5px 8px;
    color: #344054;
    font-size: 11px;
}
.rich-editor {
    min-height: 190px;
    padding: 14px;
    outline: none;
    color: var(--text);
    line-height: 1.6;
}
.rich-editor:empty::before {
    content: attr(data-placeholder);
    color: #98a2b3;
    pointer-events: none;
}
.rich-editor img,
.report-rich-content img {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 14px 0;
    border-radius: 12px;
}
.rich-editor h2, .rich-editor h3, .rich-editor h4,
.report-rich-content h2, .report-rich-content h3, .report-rich-content h4 {
    margin: 18px 0 8px;
}
.rich-editor p, .report-rich-content p { margin: 0 0 10px; }
.rich-editor ul, .rich-editor ol,
.report-rich-content ul, .report-rich-content ol { padding-left: 22px; }
.report-comparison {
    margin-top: 14px;
    padding: 12px;
    border: 1px solid #d8e3ff;
    border-radius: 11px;
    background: #f7f9ff;
}
.report-comparison summary {
    color: #2859bd;
    font-size: 12px;
    font-weight: 800;
    cursor: pointer;
}
.report-comparison > p { margin: 8px 0 12px; font-size: 12px; }
.campaign-groups-section,
.report-creatives-section {
    margin-top: 18px;
    padding-top: 16px;
    border-top: 1px solid var(--line);
}
.channel-subsection-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 14px;
    margin-bottom: 12px;
}
.channel-subsection-head p { margin: 5px 0 0; font-size: 12px; }
.channel-subsection-actions { display: flex; gap: 7px; flex-wrap: wrap; }
.campaign-groups-list { display: grid; gap: 10px; }
.campaign-group-row {
    padding: 12px;
    border: 1px solid var(--line);
    border-radius: 11px;
    background: white;
}
.campaign-group-top {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: end;
    gap: 10px;
}
.campaign-group-top label,
.campaign-group-campaigns,
.report-creative-card label {
    display: grid;
    gap: 6px;
    color: var(--muted);
    font-size: 12px;
}
.campaign-group-grid {
    display: grid;
    grid-template-columns: repeat(4, minmax(0, 1fr));
    gap: 9px;
    margin-top: 10px;
}
.campaign-group-grid label {
    display: grid;
    gap: 5px;
    color: var(--muted);
    font-size: 11px;
}
.campaign-group-campaigns { margin-top: 10px; }
.button-danger-small {
    padding: 8px 10px;
    color: var(--danger);
    border-color: #fecdca;
    background: #fef3f2;
}
.report-creatives-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 12px;
}
.report-creative-card {
    display: grid;
    gap: 9px;
    padding: 10px;
    border: 1px solid var(--line);
    border-radius: 11px;
    background: white;
}
.report-creative-card img {
    width: 100%;
    aspect-ratio: 16 / 10;
    object-fit: contain;
    border-radius: 8px;
    background: #f2f4f7;
}
.report-kpi-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(190px, 1fr));
    gap: 12px;
    margin-bottom: 24px;
}
.report-kpi-card {
    min-width: 0;
    padding: 16px;
    border: 1px solid var(--line);
    border-radius: 14px;
    background: white;
    box-shadow: var(--shadow);
}
.report-kpi-title {
    display: block;
    min-height: 34px;
    margin-bottom: 7px;
    color: var(--muted);
    font-size: 13px;
}
.report-kpi-card strong {
    display: block;
    overflow-wrap: anywhere;
    font-size: clamp(21px, 2vw, 29px);
    line-height: 1.1;
}
.report-kpi-card small { display: block; margin-top: 7px; color: var(--muted); }
.report-kpi-previous {
    margin-top: 12px;
    padding-top: 9px;
    border-top: 1px solid #eef1f5;
    color: #667085;
    font-size: 11px;
}
.report-kpi-change {
    display: inline-flex;
    margin-top: 7px;
    padding: 4px 7px;
    border-radius: 999px;
    font-size: 10px;
    font-weight: 800;
}
.report-kpi-change.positive { background: #ecfdf3; color: #067647; }
.report-kpi-change.negative { background: #fef3f2; color: #b42318; }
.report-kpi-change.neutral { background: #f2f4f7; color: #667085; }
.report-preview-content { min-width: 0; }
.report-preview-table table,
.report-detail-table table { min-width: 1080px; }
.report-preview-text-grid {
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    align-items: start;
}
.report-preview-text { min-width: 0; }
.report-rich-content { overflow-wrap: anywhere; }
.report-channel-details {
    margin-top: 28px;
    padding-top: 22px;
    border-top: 2px solid #eef1f5;
}
.report-channel-details > h2 { margin-bottom: 16px; }
.report-detail-section { margin-top: 18px; }
.report-detail-section h3 { font-size: 16px; }
.report-campaign-names { white-space: pre-line; min-width: 180px; }
.report-preview-creatives {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap: 14px;
}
.report-preview-creatives figure {
    margin: 0;
    overflow: hidden;
    border: 1px solid var(--line);
    border-radius: 12px;
    background: white;
}
.report-preview-creatives img {
    display: block;
    width: 100%;
    max-height: 440px;
    object-fit: contain;
    background: #f2f4f7;
}
.report-preview-creatives figcaption { display: grid; gap: 5px; padding: 11px; }
.report-preview-creatives figcaption span { color: var(--muted); font-size: 12px; }

@media (max-width: 900px) {
    .report-rich-grid { grid-template-columns: 1fr; }
    .campaign-group-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 620px) {
    .channel-subsection-head { flex-direction: column; }
    .campaign-group-top { grid-template-columns: 1fr; }
    .campaign-group-grid { grid-template-columns: 1fr; }
    .report-kpi-grid { grid-template-columns: 1fr 1fr; }
}
@media print {
    .report-kpi-grid { grid-template-columns: repeat(3, 1fr); }
    .report-kpi-card { break-inside: avoid; box-shadow: none; }
    .report-preview-text, .report-channel-details, .report-detail-section, .report-preview-creatives figure { break-inside: avoid; }
    .report-preview-table table, .report-detail-table table { min-width: 0; font-size: 9px; }
}
CSS;

try {
    $index = $indexOriginal;
    $api = readFileStrict($apiPath);
    $js = readFileStrict($jsPath);
    $appCss = readFileStrict($cssPath);
    $schema = readFileStrict($schemaPath);

    $index = replaceBetween(
        $index,
        '                            <div class="form-grid report-text-grid">',
        '                            <div class="report-actions">',
        $richEditorsHtml,
        'текстовые поля отчёта'
    );
    $index = preg_replace('#/assets/app\.css\?v=\d+#', '/assets/app.css?v=8', $index) ?? $index;
    $index = preg_replace('#/assets/app\.js\?v=\d+#', '/assets/app.js?v=8', $index) ?? $index;

    if (!str_contains($api, 'REPORTS_STEP2_IMAGE_UPLOAD')) {
        $api = insertBeforeOnce($api, '    $input = Security::inputJson();', $apiUploadCode, 'загрузка изображений');
    }
    $api = replaceBetween(
        $api,
        '    // REPORTS_STEP1_API_POST',
        "    if (\$action === 'save_project') {",
        $apiPostCode,
        'API сохранения отчёта'
    );

    $js = replaceOnce(
        $js,
        "...(options.body ? {'Content-Type': 'application/json'} : {}),",
        "...((options.body && !(options.body instanceof FormData)) ? {'Content-Type': 'application/json'} : {}),",
        'поддержка FormData'
    );
    $js = replaceBetween(
        $js,
        '    /* REPORTS_STEP1_JS */',
        '    async function loadDashboard() {',
        $reportsJs . "\n",
        'JavaScript отчётов'
    );

    $appCss .= "\n" . $css . "\n";

    if (!str_contains($schema, 'REPORTS_STEP2_SCHEMA')) {
        $schema .= <<<'SQL'

-- REPORTS_STEP2_SCHEMA
CREATE TABLE IF NOT EXISTS report_details (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    detail_type VARCHAR(30) NOT NULL,
    channel_key VARCHAR(40) NOT NULL,
    title VARCHAR(190) NOT NULL,
    payload_json LONGTEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_report_details_report (report_id, detail_type, channel_key),
    CONSTRAINT fk_report_details_report
        FOREIGN KEY (report_id) REFERENCES reports(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
    }

    writeFileAtomic($repositoryPath, $repositoryContent . "\n");
    writeFileAtomic($indexPath, $index);
    writeFileAtomic($apiPath, $api);
    writeFileAtomic($jsPath, $js);
    writeFileAtomic($cssPath, $appCss);
    writeFileAtomic($schemaPath, $schema);

    $uploadBase = $root . '/uploads/report-media';
    if (!is_dir($uploadBase) && !mkdir($uploadBase, 0755, true) && !is_dir($uploadBase)) {
        throw new RuntimeException('Не удалось создать каталог загрузок.');
    }
    writeFileAtomic(
        $uploadBase . '/.htaccess',
        "Options -Indexes\n<FilesMatch \"\\.(php|phtml|php3|php4|php5|phar)$\">\n    Require all denied\n</FilesMatch>\n"
    );

    lintPhp($repositoryPath);
    lintPhp($indexPath);
    lintPhp($apiPath);

    require $root . '/app/bootstrap.php';
    $pdo = \SeoAnalytics\Core\Database::pdo();
    addColumnIfMissing($pdo, 'reports', 'recommendations', 'MEDIUMTEXT NULL AFTER `next_plan`');
    addColumnIfMissing($pdo, 'report_channels', 'comparison_json', 'LONGTEXT NULL AFTER `source_type`');
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS report_details (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            report_id BIGINT UNSIGNED NOT NULL,
            detail_type VARCHAR(30) NOT NULL,
            channel_key VARCHAR(40) NOT NULL,
            title VARCHAR(190) NOT NULL,
            payload_json LONGTEXT NOT NULL,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_report_details_report (report_id, detail_type, channel_key),
            CONSTRAINT fk_report_details_report
                FOREIGN KEY (report_id) REFERENCES reports(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    out('');
    out('Доработка отчётов — шаг 2 — установлена.');
    out('- визуальный редактор и изображения в текстовых блоках;');
    out('- отдельный блок рекомендаций;');
    out('- сравнение с выбранным прошлым периодом;');
    out('- зелёные и красные индикаторы динамики;');
    out('- исправленная адаптивная сетка предпросмотра;');
    out('- группы рекламных кампаний;');
    out('- загрузка скриншотов рекламных объявлений.');
    out('');
    out("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    rollback($root, $backupDirectory, $manifest);
    fwrite(STDERR, "\nОШИБКА: {$exception->getMessage()}\nФайлы восстановлены.\n");
    exit(1);
}
