<?php
declare(strict_types=1);

/**
 * Модуль отчётов — шаг 1.
 *
 * Добавляет:
 * - историю отчётов;
 * - сводный рекламный и SEO-отчёт;
 * - ручной ввод рекламных и продажных показателей;
 * - CPL, CPQL, стоимость договора, ROAS и ROMI;
 * - предпросмотр и печать в PDF через браузер.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Запустите установщик через PHP CLI.');
}

function console(string $message): void
{
    fwrite(STDOUT, $message . PHP_EOL);
}

function projectRoot(): string
{
    $variants = [
        realpath(dirname(__DIR__)),
        realpath(__DIR__),
    ];

    foreach ($variants as $variant) {
        if (
            is_string($variant)
            && is_file($variant . '/index.php')
            && is_file($variant . '/api.php')
            && is_dir($variant . '/app')
        ) {
            return $variant;
        }
    }

    throw new RuntimeException(
        'Не найден корень проекта. Поместите файл в каталог bin.'
    );
}

function readRequired(string $path): string
{
    $content = file_get_contents($path);

    if (!is_string($content)) {
        throw new RuntimeException("Не удалось прочитать файл: {$path}");
    }

    return $content;
}

function replaceOnce(
    string $content,
    string $needle,
    string $replacement,
    string $label
): string {
    $position = strpos($content, $needle);

    if ($position === false) {
        throw new RuntimeException(
            "Не найдена точка вставки: {$label}"
        );
    }

    return substr($content, 0, $position)
        . $replacement
        . substr($content, $position + strlen($needle));
}

function insertBeforeOnce(
    string $content,
    string $needle,
    string $insertion,
    string $label
): string {
    return replaceOnce(
        $content,
        $needle,
        $insertion . $needle,
        $label
    );
}

function writeAtomic(string $path, string $content): void
{
    $directory = dirname($path);

    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException(
            "Не удалось создать каталог: {$directory}"
        );
    }

    $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));

    if (file_put_contents($temporary, $content, LOCK_EX) === false) {
        throw new RuntimeException(
            "Не удалось записать временный файл: {$temporary}"
        );
    }

    if (!rename($temporary, $path)) {
        @unlink($temporary);
        throw new RuntimeException(
            "Не удалось заменить файл: {$path}"
        );
    }
}

function backupFiles(
    string $root,
    array $relativePaths,
    string $backupDirectory
): array {
    if (
        !is_dir($backupDirectory)
        && !mkdir($backupDirectory, 0700, true)
        && !is_dir($backupDirectory)
    ) {
        throw new RuntimeException(
            "Не удалось создать резервную копию: {$backupDirectory}"
        );
    }

    $manifest = [];

    foreach ($relativePaths as $relativePath) {
        $source = $root . '/' . $relativePath;
        $exists = is_file($source);

        $manifest[$relativePath] = [
            'existed' => $exists,
        ];

        if (!$exists) {
            continue;
        }

        $destination = $backupDirectory . '/' . $relativePath;
        $destinationDirectory = dirname($destination);

        if (
            !is_dir($destinationDirectory)
            && !mkdir($destinationDirectory, 0700, true)
            && !is_dir($destinationDirectory)
        ) {
            throw new RuntimeException(
                "Не удалось создать каталог копии: {$destinationDirectory}"
            );
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException(
                "Не удалось сохранить резервную копию: {$relativePath}"
            );
        }
    }

    return $manifest;
}

function rollbackFiles(
    string $root,
    string $backupDirectory,
    array $manifest
): void {
    foreach ($manifest as $relativePath => $info) {
        $destination = $root . '/' . $relativePath;

        if ($info['existed']) {
            $backup = $backupDirectory . '/' . $relativePath;

            if (is_file($backup)) {
                @copy($backup, $destination);
            }
        } elseif (is_file($destination)) {
            @unlink($destination);
        }
    }
}

function lintPhp(string $path): void
{
    if (!function_exists('exec')) {
        console("Предупреждение: exec() отключён, PHP-lint пропущен для {$path}");
        return;
    }

    $output = [];
    $code = 0;

    exec(
        escapeshellarg(PHP_BINARY)
        . ' -l '
        . escapeshellarg($path)
        . ' 2>&1',
        $output,
        $code
    );

    if ($code !== 0) {
        throw new RuntimeException(
            "Ошибка PHP-синтаксиса в {$path}:\n"
            . implode("\n", $output)
        );
    }
}

$root = projectRoot();

$paths = [
    'index.php',
    'api.php',
    'assets/app.js',
    'assets/app.css',
    'sql/schema.sql',
    'app/Repositories/ReportRepository.php',
];

$indexPath = $root . '/index.php';
$apiPath = $root . '/api.php';
$jsPath = $root . '/assets/app.js';
$cssPath = $root . '/assets/app.css';
$schemaPath = $root . '/sql/schema.sql';
$repositoryPath = $root . '/app/Repositories/ReportRepository.php';

$indexOriginal = readRequired($indexPath);

if (str_contains($indexOriginal, 'REPORTS_STEP1')) {
    console('Модуль отчётов — шаг 1 — уже установлен.');
    exit(0);
}

$backupDirectory = $root
    . '/storage/backups/reports-step1-'
    . date('Ymd-His');

$manifest = backupFiles(
    $root,
    $paths,
    $backupDirectory
);

console("Резервная копия: {$backupDirectory}");

$schemaStatements = [];

$schemaStatements[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS reports (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    project_id BIGINT UNSIGNED NOT NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    report_type VARCHAR(40) NOT NULL,
    audience VARCHAR(30) NOT NULL DEFAULT 'owner',
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    title VARCHAR(190) NOT NULL,
    date_from DATE NOT NULL,
    date_to DATE NOT NULL,
    comparison_date_from DATE NULL,
    comparison_date_to DATE NULL,
    work_done MEDIUMTEXT NULL,
    next_plan MEDIUMTEXT NULL,
    notes MEDIUMTEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_reports_project (project_id, updated_at),
    KEY idx_reports_status (status),
    CONSTRAINT fk_reports_project
        FOREIGN KEY (project_id) REFERENCES projects(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_reports_user
        FOREIGN KEY (created_by) REFERENCES users(id)
        ON DELETE RESTRICT
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL;

$schemaStatements[] = <<<'SQL'
CREATE TABLE IF NOT EXISTS report_channels (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    report_id BIGINT UNSIGNED NOT NULL,
    channel_key VARCHAR(40) NOT NULL,
    channel_name VARCHAR(100) NOT NULL,
    source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
    spend DECIMAL(18,2) NOT NULL DEFAULT 0,
    impressions BIGINT UNSIGNED NOT NULL DEFAULT 0,
    clicks BIGINT UNSIGNED NOT NULL DEFAULT 0,
    leads INT UNSIGNED NOT NULL DEFAULT 0,
    qualified_leads INT UNSIGNED NOT NULL DEFAULT 0,
    meetings INT UNSIGNED NOT NULL DEFAULT 0,
    offers INT UNSIGNED NOT NULL DEFAULT 0,
    contracts INT UNSIGNED NOT NULL DEFAULT 0,
    contract_amount DECIMAL(18,2) NOT NULL DEFAULT 0,
    paid_revenue DECIMAL(18,2) NOT NULL DEFAULT 0,
    gross_margin_percent DECIMAL(8,2) NULL,
    non_target INT UNSIGNED NOT NULL DEFAULT 0,
    duplicates INT UNSIGNED NOT NULL DEFAULT 0,
    unreachable INT UNSIGNED NOT NULL DEFAULT 0,
    notes TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_report_channel (report_id, channel_key),
    KEY idx_report_channels_report (report_id),
    CONSTRAINT fk_report_channels_report
        FOREIGN KEY (report_id) REFERENCES reports(id)
        ON DELETE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
SQL;

$repositoryContent = <<<'PHPFILE'
<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

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

    private const REPORT_TYPES = [
        'advertising_summary',
        'seo',
    ];

    private const AUDIENCES = [
        'owner',
        'marketer',
        'sales',
        'client',
    ];

    private const STATUSES = [
        'draft',
        'review',
        'approved',
        'sent',
        'archive',
    ];

    private const SOURCE_TYPES = [
        'manual',
        'import',
        'api',
    ];

    public function listByProject(int $projectId): array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                r.id,
                r.title,
                r.report_type,
                r.audience,
                r.status,
                r.date_from,
                r.date_to,
                r.created_at,
                r.updated_at,
                u.name AS author_name,
                u.email AS author_email,
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
                r.id,
                r.title,
                r.report_type,
                r.audience,
                r.status,
                r.date_from,
                r.date_to,
                r.created_at,
                r.updated_at,
                u.name,
                u.email
             ORDER BY r.updated_at DESC
             LIMIT 200'
        );

        $stmt->execute([
            'project_id' => $projectId,
        ]);

        $rows = $stmt->fetchAll();

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['spend'] = (float) $row['spend'];
            $row['leads'] = (int) $row['leads'];
            $row['qualified_leads'] = (int) $row['qualified_leads'];
            $row['contracts'] = (int) $row['contracts'];
            $row['contract_amount'] = (float) $row['contract_amount'];
            $row['paid_revenue'] = (float) $row['paid_revenue'];
            $row['author_name'] = $row['author_name']
                ?: $row['author_email'];

            $row['calculated'] = [
                'cpl' => self::divideMoney(
                    $row['spend'],
                    $row['leads']
                ),
                'cpql' => self::divideMoney(
                    $row['spend'],
                    $row['qualified_leads']
                ),
                'cost_per_contract' => self::divideMoney(
                    $row['spend'],
                    $row['contracts']
                ),
                'roas' => self::ratio(
                    $row['paid_revenue'],
                    $row['spend']
                ),
            ];

            unset($row['author_email']);
        }
        unset($row);

        return $rows;
    }

    public function find(int $id, int $projectId): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT
                r.*,
                u.name AS author_name,
                u.email AS author_email
             FROM reports r
             INNER JOIN users u ON u.id = r.created_by
             WHERE r.id = :id
               AND r.project_id = :project_id
             LIMIT 1'
        );

        $stmt->execute([
            'id' => $id,
            'project_id' => $projectId,
        ]);

        $report = $stmt->fetch();

        if (!$report) {
            return null;
        }

        $report['id'] = (int) $report['id'];
        $report['project_id'] = (int) $report['project_id'];
        $report['created_by'] = (int) $report['created_by'];
        $report['author_name'] = $report['author_name']
            ?: $report['author_email'];

        unset($report['author_email']);

        $stmt = Database::pdo()->prepare(
            'SELECT *
             FROM report_channels
             WHERE report_id = :report_id
             ORDER BY FIELD(
                channel_key,
                "direct",
                "vk",
                "avito",
                "2gis",
                "yandex_business",
                "seo"
             )'
        );

        $stmt->execute([
            'report_id' => $id,
        ]);

        $channels = [];

        foreach ($stmt->fetchAll() as $channel) {
            $channels[] = self::normalizeStoredChannel($channel);
        }

        $report['channels'] = $channels;
        $report['summary'] = self::calculateSummary($channels);

        return $report;
    }

    public function save(array $data, int $userId): int
    {
        $projectId = (int) ($data['project_id'] ?? 0);
        $reportId = (int) ($data['id'] ?? 0);

        if ($projectId <= 0 || $userId <= 0) {
            throw new RuntimeException(
                'Не удалось определить проект или пользователя.'
            );
        }

        $reportType = (string) ($data['report_type'] ?? '');

        if (!in_array($reportType, self::REPORT_TYPES, true)) {
            throw new RuntimeException(
                'Неизвестный тип отчёта.'
            );
        }

        $audience = (string) ($data['audience'] ?? 'owner');

        if (!in_array($audience, self::AUDIENCES, true)) {
            $audience = 'owner';
        }

        $status = (string) ($data['status'] ?? 'draft');

        if (!in_array($status, self::STATUSES, true)) {
            $status = 'draft';
        }

        $dateFrom = (string) ($data['date_from'] ?? '');
        $dateTo = (string) ($data['date_to'] ?? '');

        $typeLabel = $reportType === 'seo'
            ? 'SEO-отчёт'
            : 'Сводный рекламный отчёт';

        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            $title = "{$typeLabel} {$dateFrom}—{$dateTo}";
        }

        $title = mb_substr($title, 0, 190);

        $comparisonDateFrom = self::nullableDate(
            $data['comparison_date_from'] ?? null
        );
        $comparisonDateTo = self::nullableDate(
            $data['comparison_date_to'] ?? null
        );

        $workDone = self::text($data['work_done'] ?? '');
        $nextPlan = self::text($data['next_plan'] ?? '');
        $notes = self::text($data['notes'] ?? '');

        $channels = $this->sanitizeChannels(
            $reportType,
            is_array($data['channels'] ?? null)
                ? $data['channels']
                : []
        );

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            if ($reportId > 0) {
                $check = $pdo->prepare(
                    'SELECT id
                     FROM reports
                     WHERE id = :id
                       AND project_id = :project_id
                     LIMIT 1'
                );

                $check->execute([
                    'id' => $reportId,
                    'project_id' => $projectId,
                ]);

                if (!$check->fetchColumn()) {
                    throw new RuntimeException(
                        'Отчёт не найден или относится к другому проекту.'
                    );
                }

                $stmt = $pdo->prepare(
                    'UPDATE reports SET
                        report_type = :report_type,
                        audience = :audience,
                        status = :status,
                        title = :title,
                        date_from = :date_from,
                        date_to = :date_to,
                        comparison_date_from = :comparison_date_from,
                        comparison_date_to = :comparison_date_to,
                        work_done = :work_done,
                        next_plan = :next_plan,
                        notes = :notes,
                        updated_at = NOW()
                     WHERE id = :id
                       AND project_id = :project_id'
                );

                $stmt->execute([
                    'report_type' => $reportType,
                    'audience' => $audience,
                    'status' => $status,
                    'title' => $title,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'comparison_date_from' => $comparisonDateFrom,
                    'comparison_date_to' => $comparisonDateTo,
                    'work_done' => $workDone,
                    'next_plan' => $nextPlan,
                    'notes' => $notes,
                    'id' => $reportId,
                    'project_id' => $projectId,
                ]);
            } else {
                $stmt = $pdo->prepare(
                    'INSERT INTO reports
                     (
                        project_id,
                        created_by,
                        report_type,
                        audience,
                        status,
                        title,
                        date_from,
                        date_to,
                        comparison_date_from,
                        comparison_date_to,
                        work_done,
                        next_plan,
                        notes,
                        created_at,
                        updated_at
                     )
                     VALUES
                     (
                        :project_id,
                        :created_by,
                        :report_type,
                        :audience,
                        :status,
                        :title,
                        :date_from,
                        :date_to,
                        :comparison_date_from,
                        :comparison_date_to,
                        :work_done,
                        :next_plan,
                        :notes,
                        NOW(),
                        NOW()
                     )'
                );

                $stmt->execute([
                    'project_id' => $projectId,
                    'created_by' => $userId,
                    'report_type' => $reportType,
                    'audience' => $audience,
                    'status' => $status,
                    'title' => $title,
                    'date_from' => $dateFrom,
                    'date_to' => $dateTo,
                    'comparison_date_from' => $comparisonDateFrom,
                    'comparison_date_to' => $comparisonDateTo,
                    'work_done' => $workDone,
                    'next_plan' => $nextPlan,
                    'notes' => $notes,
                ]);

                $reportId = (int) $pdo->lastInsertId();
            }

            $stmt = $pdo->prepare(
                'DELETE FROM report_channels
                 WHERE report_id = :report_id'
            );

            $stmt->execute([
                'report_id' => $reportId,
            ]);

            $insert = $pdo->prepare(
                'INSERT INTO report_channels
                 (
                    report_id,
                    channel_key,
                    channel_name,
                    source_type,
                    spend,
                    impressions,
                    clicks,
                    leads,
                    qualified_leads,
                    meetings,
                    offers,
                    contracts,
                    contract_amount,
                    paid_revenue,
                    gross_margin_percent,
                    non_target,
                    duplicates,
                    unreachable,
                    notes,
                    created_at,
                    updated_at
                 )
                 VALUES
                 (
                    :report_id,
                    :channel_key,
                    :channel_name,
                    :source_type,
                    :spend,
                    :impressions,
                    :clicks,
                    :leads,
                    :qualified_leads,
                    :meetings,
                    :offers,
                    :contracts,
                    :contract_amount,
                    :paid_revenue,
                    :gross_margin_percent,
                    :non_target,
                    :duplicates,
                    :unreachable,
                    :notes,
                    NOW(),
                    NOW()
                 )'
            );

            foreach ($channels as $channel) {
                $insert->execute([
                    'report_id' => $reportId,
                    'channel_key' => $channel['channel_key'],
                    'channel_name' => $channel['channel_name'],
                    'source_type' => $channel['source_type'],
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
        $qualifiedLeads = (int) ($channel['qualified_leads'] ?? 0);
        $contracts = (int) ($channel['contracts'] ?? 0);
        $paidRevenue = (float) ($channel['paid_revenue'] ?? 0);

        $margin = $channel['gross_margin_percent'] ?? null;
        $margin = $margin === null ? null : (float) $margin;

        $romi = null;

        if ($spend > 0 && $margin !== null) {
            $grossProfit = $paidRevenue * ($margin / 100);
            $romi = (($grossProfit - $spend) / $spend) * 100;
        }

        return [
            'ctr' => self::percent($clicks, $impressions),
            'cpc' => self::divideMoney($spend, $clicks),
            'click_to_lead' => self::percent($leads, $clicks),
            'cpl' => self::divideMoney($spend, $leads),
            'cpql' => self::divideMoney($spend, $qualifiedLeads),
            'cost_per_contract' => self::divideMoney(
                $spend,
                $contracts
            ),
            'lead_to_contract' => self::percent(
                $contracts,
                $leads
            ),
            'average_contract' => self::divideMoney(
                (float) ($channel['contract_amount'] ?? 0),
                $contracts
            ),
            'roas' => self::ratio(
                $paidRevenue,
                $spend
            ),
            'romi' => $romi,
        ];
    }

    public static function calculateSummary(array $channels): array
    {
        $summary = [
            'spend' => 0.0,
            'impressions' => 0,
            'clicks' => 0,
            'leads' => 0,
            'qualified_leads' => 0,
            'meetings' => 0,
            'offers' => 0,
            'contracts' => 0,
            'contract_amount' => 0.0,
            'paid_revenue' => 0.0,
            'non_target' => 0,
            'duplicates' => 0,
            'unreachable' => 0,
        ];

        $grossProfit = 0.0;
        $hasRevenue = false;
        $marginComplete = true;

        foreach ($channels as $channel) {
            foreach ([
                'spend',
                'contract_amount',
                'paid_revenue',
            ] as $key) {
                $summary[$key] += (float) ($channel[$key] ?? 0);
            }

            foreach ([
                'impressions',
                'clicks',
                'leads',
                'qualified_leads',
                'meetings',
                'offers',
                'contracts',
                'non_target',
                'duplicates',
                'unreachable',
            ] as $key) {
                $summary[$key] += (int) ($channel[$key] ?? 0);
            }

            $revenue = (float) ($channel['paid_revenue'] ?? 0);

            if ($revenue > 0) {
                $hasRevenue = true;

                if ($channel['gross_margin_percent'] === null) {
                    $marginComplete = false;
                } else {
                    $grossProfit += $revenue
                        * ((float) $channel['gross_margin_percent'] / 100);
                }
            }
        }

        $romi = null;

        if (
            $summary['spend'] > 0
            && $hasRevenue
            && $marginComplete
        ) {
            $romi = (
                ($grossProfit - $summary['spend'])
                / $summary['spend']
            ) * 100;
        }

        $summary['calculated'] = [
            'ctr' => self::percent(
                $summary['clicks'],
                $summary['impressions']
            ),
            'cpc' => self::divideMoney(
                $summary['spend'],
                $summary['clicks']
            ),
            'click_to_lead' => self::percent(
                $summary['leads'],
                $summary['clicks']
            ),
            'cpl' => self::divideMoney(
                $summary['spend'],
                $summary['leads']
            ),
            'cpql' => self::divideMoney(
                $summary['spend'],
                $summary['qualified_leads']
            ),
            'cost_per_contract' => self::divideMoney(
                $summary['spend'],
                $summary['contracts']
            ),
            'lead_to_contract' => self::percent(
                $summary['contracts'],
                $summary['leads']
            ),
            'average_contract' => self::divideMoney(
                $summary['contract_amount'],
                $summary['contracts']
            ),
            'roas' => self::ratio(
                $summary['paid_revenue'],
                $summary['spend']
            ),
            'romi' => $romi,
        ];

        return $summary;
    }

    private function sanitizeChannels(
        string $reportType,
        array $channels
    ): array {
        $allowedKeys = $reportType === 'seo'
            ? ['seo']
            : [
                'direct',
                'vk',
                'avito',
                '2gis',
                'yandex_business',
            ];

        $result = [];

        foreach ($channels as $channel) {
            if (!is_array($channel)) {
                continue;
            }

            $key = (string) ($channel['channel_key'] ?? '');

            if (
                !in_array($key, $allowedKeys, true)
                || !isset(self::CHANNELS[$key])
            ) {
                continue;
            }

            $sourceType = (string) (
                $channel['source_type'] ?? 'manual'
            );

            if (!in_array($sourceType, self::SOURCE_TYPES, true)) {
                $sourceType = 'manual';
            }

            $result[$key] = [
                'channel_key' => $key,
                'channel_name' => self::CHANNELS[$key],
                'source_type' => $sourceType,
                'spend' => self::decimal($channel['spend'] ?? 0),
                'impressions' => self::integer($channel['impressions'] ?? 0),
                'clicks' => self::integer($channel['clicks'] ?? 0),
                'leads' => self::integer($channel['leads'] ?? 0),
                'qualified_leads' => self::integer(
                    $channel['qualified_leads'] ?? 0
                ),
                'meetings' => self::integer($channel['meetings'] ?? 0),
                'offers' => self::integer($channel['offers'] ?? 0),
                'contracts' => self::integer($channel['contracts'] ?? 0),
                'contract_amount' => self::decimal(
                    $channel['contract_amount'] ?? 0
                ),
                'paid_revenue' => self::decimal(
                    $channel['paid_revenue'] ?? 0
                ),
                'gross_margin_percent' => self::nullablePercent(
                    $channel['gross_margin_percent'] ?? null
                ),
                'non_target' => self::integer(
                    $channel['non_target'] ?? 0
                ),
                'duplicates' => self::integer(
                    $channel['duplicates'] ?? 0
                ),
                'unreachable' => self::integer(
                    $channel['unreachable'] ?? 0
                ),
                'notes' => self::text($channel['notes'] ?? ''),
            ];
        }

        foreach ($allowedKeys as $key) {
            if (!isset($result[$key])) {
                $result[$key] = [
                    'channel_key' => $key,
                    'channel_name' => self::CHANNELS[$key],
                    'source_type' => 'manual',
                    'spend' => 0.0,
                    'impressions' => 0,
                    'clicks' => 0,
                    'leads' => 0,
                    'qualified_leads' => 0,
                    'meetings' => 0,
                    'offers' => 0,
                    'contracts' => 0,
                    'contract_amount' => 0.0,
                    'paid_revenue' => 0.0,
                    'gross_margin_percent' => null,
                    'non_target' => 0,
                    'duplicates' => 0,
                    'unreachable' => 0,
                    'notes' => '',
                ];
            }
        }

        return array_values($result);
    }

    private static function normalizeStoredChannel(array $channel): array
    {
        foreach ([
            'id',
            'report_id',
            'impressions',
            'clicks',
            'leads',
            'qualified_leads',
            'meetings',
            'offers',
            'contracts',
            'non_target',
            'duplicates',
            'unreachable',
        ] as $key) {
            $channel[$key] = (int) ($channel[$key] ?? 0);
        }

        foreach ([
            'spend',
            'contract_amount',
            'paid_revenue',
        ] as $key) {
            $channel[$key] = (float) ($channel[$key] ?? 0);
        }

        $channel['gross_margin_percent'] =
            $channel['gross_margin_percent'] === null
                ? null
                : (float) $channel['gross_margin_percent'];

        $channel['calculated'] = self::calculateChannel($channel);

        return $channel;
    }

    private static function decimal(mixed $value): float
    {
        $normalized = str_replace(
            [' ', ','],
            ['', '.'],
            trim((string) $value)
        );

        return max(0, (float) $normalized);
    }

    private static function integer(mixed $value): int
    {
        return max(0, (int) $value);
    }

    private static function nullablePercent(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return min(100, max(0, self::decimal($value)));
    }

    private static function nullableDate(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function text(mixed $value): string
    {
        return mb_substr(
            trim((string) $value),
            0,
            60000
        );
    }

    private static function divideMoney(
        float $numerator,
        int $denominator
    ): ?float {
        if ($denominator <= 0) {
            return null;
        }

        return $numerator / $denominator;
    }

    private static function ratio(
        float $numerator,
        float $denominator
    ): ?float {
        if ($denominator <= 0) {
            return null;
        }

        return $numerator / $denominator;
    }

    private static function percent(
        int $numerator,
        int $denominator
    ): ?float {
        if ($denominator <= 0) {
            return null;
        }

        return ($numerator / $denominator) * 100;
    }
}
PHPFILE;

$reportsHtml = <<<'HTML'

        <!-- REPORTS_STEP1 -->
        <section id="section-reports" class="section">
            <div class="reports-toolbar">
                <div>
                    <p class="eyebrow">Управленческая отчётность</p>
                    <h2>Отчёты</h2>
                    <p class="muted reports-toolbar-note">
                        Реклама, заявки, квалифицированные лиды, договоры и выручка.
                    </p>
                </div>
                <button class="button button-primary" id="newReport">
                    Новый отчёт
                </button>
            </div>

            <div id="reportsMessage"></div>

            <?php if (!$project): ?>
                <div class="empty-state">
                    <h2>Сначала добавьте проект</h2>
                    <p>Отчёты создаются для активного проекта.</p>
                    <button class="button button-primary" data-open-section="settings">
                        Перейти к настройкам
                    </button>
                </div>
            <?php else: ?>
                <div class="reports-layout">
                    <article class="panel reports-history-panel">
                        <div class="panel-head">
                            <div>
                                <p class="eyebrow">История</p>
                                <h2>Сохранённые отчёты</h2>
                            </div>
                        </div>
                        <div id="reportsList" class="reports-list">
                            <span class="muted">Откройте раздел для загрузки истории.</span>
                        </div>
                    </article>

                    <article class="panel report-editor-panel">
                        <div class="panel-head">
                            <div>
                                <p class="eyebrow">Редактор</p>
                                <h2 id="reportEditorTitle">Новый отчёт</h2>
                            </div>
                        </div>

                        <form id="reportForm" class="settings-form">
                            <input type="hidden" name="report_id" value="0">
                            <input
                                type="hidden"
                                name="project_id"
                                value="<?= (int) $project['id'] ?>"
                            >

                            <div class="form-grid report-main-grid">
                                <label class="report-title-field">
                                    <span>Название отчёта</span>
                                    <input
                                        name="title"
                                        maxlength="190"
                                        placeholder="Например: Реклама за июль 2026"
                                    >
                                </label>

                                <label>
                                    <span>Тип отчёта</span>
                                    <select name="report_type">
                                        <option value="advertising_summary">
                                            Сводный рекламный
                                        </option>
                                        <option value="seo">
                                            SEO-отчёт
                                        </option>
                                    </select>
                                </label>

                                <label>
                                    <span>Получатель</span>
                                    <select name="audience">
                                        <option value="owner">Собственник</option>
                                        <option value="marketer">Маркетолог</option>
                                        <option value="sales">РОП</option>
                                        <option value="client">Клиент</option>
                                    </select>
                                </label>

                                <label>
                                    <span>Статус</span>
                                    <select name="status">
                                        <option value="draft">Черновик</option>
                                        <option value="review">На проверке</option>
                                        <option value="approved">Утверждён</option>
                                        <option value="sent">Отправлен</option>
                                        <option value="archive">Архив</option>
                                    </select>
                                </label>

                                <label>
                                    <span>Период с</span>
                                    <input
                                        type="date"
                                        name="date_from"
                                        value="<?= $dateFrom ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    <span>Период по</span>
                                    <input
                                        type="date"
                                        name="date_to"
                                        value="<?= $yesterday ?>"
                                        required
                                    >
                                </label>

                                <label>
                                    <span>Сравнение с</span>
                                    <input
                                        type="date"
                                        name="comparison_date_from"
                                    >
                                </label>

                                <label>
                                    <span>Сравнение по</span>
                                    <input
                                        type="date"
                                        name="comparison_date_to"
                                    >
                                </label>
                            </div>

                            <div class="reports-section-head">
                                <div>
                                    <strong>Каналы и данные продаж</strong>
                                    <p class="muted">
                                        На первом этапе показатели вводятся вручную.
                                        Позже эти же поля будут заполняться через API.
                                    </p>
                                </div>
                            </div>

                            <div id="reportChannels" class="report-channels"></div>

                            <div class="form-grid report-text-grid">
                                <label>
                                    <span>Что сделано за период</span>
                                    <textarea
                                        name="work_done"
                                        rows="7"
                                        placeholder="Оптимизации, тесты, изменения кампаний, подготовленные ТЗ..."
                                    ></textarea>
                                </label>

                                <label>
                                    <span>План следующего периода</span>
                                    <textarea
                                        name="next_plan"
                                        rows="7"
                                        placeholder="Какие действия планируются и зачем..."
                                    ></textarea>
                                </label>
                            </div>

                            <label>
                                <span>Комментарии и ограничения данных</span>
                                <textarea
                                    name="notes"
                                    rows="5"
                                    placeholder="Например: звонки не подключены, договоры внесены вручную..."
                                ></textarea>
                            </label>

                            <div class="report-actions">
                                <button
                                    type="submit"
                                    class="button button-primary"
                                >
                                    Сохранить черновик
                                </button>
                                <button
                                    type="button"
                                    class="button"
                                    id="previewReport"
                                >
                                    Предпросмотр
                                </button>
                            </div>

                            <div id="reportFormMessage"></div>
                        </form>
                    </article>
                </div>

                <article id="reportPreview" class="panel report-preview hidden">
                    <div class="panel-head report-preview-head">
                        <div>
                            <p class="eyebrow">Предпросмотр</p>
                            <h2>Управленческий отчёт</h2>
                        </div>
                        <button class="button" id="printReport">
                            Печать / сохранить PDF
                        </button>
                    </div>
                    <div id="reportPreviewContent"></div>
                </article>
            <?php endif; ?>
        </section>
HTML;

$reportsJs = <<<'JS'

    /* REPORTS_STEP1_JS */
    const reportChannelsByType = {
        advertising_summary: [
            {key: 'direct', name: 'Яндекс Директ'},
            {key: 'vk', name: 'VK Реклама'},
            {key: 'avito', name: 'Авито'},
            {key: '2gis', name: '2ГИС'},
            {key: 'yandex_business', name: 'Яндекс Бизнес'}
        ],
        seo: [
            {key: 'seo', name: 'Органический поиск (SEO)'}
        ]
    };

    const reportStatusLabels = {
        draft: 'Черновик',
        review: 'На проверке',
        approved: 'Утверждён',
        sent: 'Отправлен',
        archive: 'Архив'
    };

    const reportTypeLabels = {
        advertising_summary: 'Сводный рекламный отчёт',
        seo: 'SEO-отчёт'
    };

    const reportAudienceLabels = {
        owner: 'Собственник',
        marketer: 'Маркетолог',
        sales: 'РОП',
        client: 'Клиент'
    };

    const reportMoneyFormatter = new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: 0
    });

    let reportsLoaded = false;

    function reportNumberValue(value) {
        const normalized = String(value ?? '')
            .trim()
            .replaceAll(' ', '')
            .replace(',', '.');

        const parsed = Number(normalized);

        return Number.isFinite(parsed) && parsed > 0
            ? parsed
            : 0;
    }

    function reportNullableNumber(value) {
        const normalized = String(value ?? '').trim();

        if (normalized === '') {
            return null;
        }

        const parsed = Number(
            normalized
                .replaceAll(' ', '')
                .replace(',', '.')
        );

        return Number.isFinite(parsed)
            ? Math.max(0, parsed)
            : null;
    }

    function reportInputValue(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return Number(value) === 0
            ? ''
            : String(value);
    }

    function reportMoney(value) {
        if (value === null || value === undefined) {
            return '—';
        }

        return reportMoneyFormatter.format(Number(value || 0));
    }

    function reportPercent(value) {
        if (value === null || value === undefined) {
            return '—';
        }

        return `${number.format(Number(value || 0))}%`;
    }

    function reportMultiplier(value) {
        if (value === null || value === undefined) {
            return '—';
        }

        return `${number.format(Number(value || 0))}×`;
    }

    function reportDate(value) {
        const raw = String(value || '');
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})$/);

        return match
            ? `${match[3]}.${match[2]}.${match[1]}`
            : raw;
    }

    function reportSafeDivide(numerator, denominator) {
        return Number(denominator || 0) > 0
            ? Number(numerator || 0) / Number(denominator)
            : null;
    }

    function reportChannelCalculations(channel) {
        const margin = channel.gross_margin_percent;
        const spend = Number(channel.spend || 0);
        const revenue = Number(channel.paid_revenue || 0);

        return {
            ctr: reportSafeDivide(
                Number(channel.clicks || 0) * 100,
                channel.impressions
            ),
            cpc: reportSafeDivide(spend, channel.clicks),
            click_to_lead: reportSafeDivide(
                Number(channel.leads || 0) * 100,
                channel.clicks
            ),
            cpl: reportSafeDivide(spend, channel.leads),
            cpql: reportSafeDivide(
                spend,
                channel.qualified_leads
            ),
            cost_per_contract: reportSafeDivide(
                spend,
                channel.contracts
            ),
            lead_to_contract: reportSafeDivide(
                Number(channel.contracts || 0) * 100,
                channel.leads
            ),
            average_contract: reportSafeDivide(
                channel.contract_amount,
                channel.contracts
            ),
            roas: reportSafeDivide(revenue, spend),
            romi: spend > 0 && margin !== null
                ? (
                    (
                        revenue * (Number(margin) / 100)
                        - spend
                    )
                    / spend
                ) * 100
                : null
        };
    }

    function reportSummary(channels) {
        const summary = {
            spend: 0,
            impressions: 0,
            clicks: 0,
            leads: 0,
            qualified_leads: 0,
            meetings: 0,
            offers: 0,
            contracts: 0,
            contract_amount: 0,
            paid_revenue: 0,
            non_target: 0,
            duplicates: 0,
            unreachable: 0
        };

        let hasRevenue = false;
        let marginComplete = true;
        let grossProfit = 0;

        channels.forEach(channel => {
            [
                'spend',
                'impressions',
                'clicks',
                'leads',
                'qualified_leads',
                'meetings',
                'offers',
                'contracts',
                'contract_amount',
                'paid_revenue',
                'non_target',
                'duplicates',
                'unreachable'
            ].forEach(key => {
                summary[key] += Number(channel[key] || 0);
            });

            if (Number(channel.paid_revenue || 0) > 0) {
                hasRevenue = true;

                if (channel.gross_margin_percent === null) {
                    marginComplete = false;
                } else {
                    grossProfit += Number(channel.paid_revenue || 0)
                        * (
                            Number(channel.gross_margin_percent)
                            / 100
                        );
                }
            }
        });

        summary.calculated = reportChannelCalculations({
            ...summary,
            gross_margin_percent: null
        });

        summary.calculated.romi =
            summary.spend > 0
            && hasRevenue
            && marginComplete
                ? (
                    (grossProfit - summary.spend)
                    / summary.spend
                ) * 100
                : null;

        return summary;
    }

    function renderReportChannels(type, storedChannels = []) {
        const root = $('#reportChannels');

        if (!root) return;

        const channels = reportChannelsByType[type]
            || reportChannelsByType.advertising_summary;

        const stored = Object.fromEntries(
            (storedChannels || []).map(channel => [
                channel.channel_key,
                channel
            ])
        );

        root.innerHTML = channels.map(definition => {
            const channel = stored[definition.key] || {
                channel_key: definition.key,
                channel_name: definition.name,
                source_type: 'manual',
                spend: 0,
                impressions: 0,
                clicks: 0,
                leads: 0,
                qualified_leads: 0,
                meetings: 0,
                offers: 0,
                contracts: 0,
                contract_amount: 0,
                paid_revenue: 0,
                gross_margin_percent: null,
                non_target: 0,
                duplicates: 0,
                unreachable: 0,
                notes: ''
            };

            return `
                <article
                    class="report-channel-card"
                    data-channel-key="${escapeHtml(definition.key)}"
                    data-channel-name="${escapeHtml(definition.name)}"
                >
                    <div class="report-channel-head">
                        <div>
                            <strong>${escapeHtml(definition.name)}</strong>
                            <small>
                                Данные рекламного канала и отдела продаж
                            </small>
                        </div>

                        <label class="report-source-label">
                            <span>Источник</span>
                            <select data-field="source_type">
                                <option
                                    value="manual"
                                    ${channel.source_type === 'manual' ? 'selected' : ''}
                                >
                                    Ручной ввод
                                </option>
                                <option
                                    value="import"
                                    ${channel.source_type === 'import' ? 'selected' : ''}
                                >
                                    Импорт
                                </option>
                                <option
                                    value="api"
                                    ${channel.source_type === 'api' ? 'selected' : ''}
                                >
                                    API
                                </option>
                            </select>
                        </label>
                    </div>

                    <div class="report-channel-grid">
                        <label>
                            <span>Расход, ₽</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                data-field="spend"
                                value="${escapeHtml(reportInputValue(channel.spend))}"
                            >
                        </label>

                        <label>
                            <span>Показы</span>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                data-field="impressions"
                                value="${escapeHtml(reportInputValue(channel.impressions))}"
                            >
                        </label>

                        <label>
                            <span>Клики / переходы</span>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                data-field="clicks"
                                value="${escapeHtml(reportInputValue(channel.clicks))}"
                            >
                        </label>

                        <label>
                            <span>Уникальные заявки</span>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                data-field="leads"
                                value="${escapeHtml(reportInputValue(channel.leads))}"
                            >
                        </label>

                        <label>
                            <span>Квалифицированные лиды</span>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                data-field="qualified_leads"
                                value="${escapeHtml(reportInputValue(channel.qualified_leads))}"
                            >
                        </label>

                        <label>
                            <span>Договоры</span>
                            <input
                                type="number"
                                min="0"
                                step="1"
                                data-field="contracts"
                                value="${escapeHtml(reportInputValue(channel.contracts))}"
                            >
                        </label>

                        <label>
                            <span>Сумма договоров, ₽</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                data-field="contract_amount"
                                value="${escapeHtml(reportInputValue(channel.contract_amount))}"
                            >
                        </label>

                        <label>
                            <span>Оплаченная выручка, ₽</span>
                            <input
                                type="number"
                                min="0"
                                step="0.01"
                                data-field="paid_revenue"
                                value="${escapeHtml(reportInputValue(channel.paid_revenue))}"
                            >
                        </label>

                        <label>
                            <span>Валовая маржа, %</span>
                            <input
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                data-field="gross_margin_percent"
                                value="${escapeHtml(reportInputValue(channel.gross_margin_percent))}"
                                placeholder="Для ROMI"
                            >
                        </label>
                    </div>

                    <details class="report-advanced">
                        <summary>Дополнительные показатели качества</summary>

                        <div class="report-channel-grid">
                            <label>
                                <span>Встречи / замеры</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    data-field="meetings"
                                    value="${escapeHtml(reportInputValue(channel.meetings))}"
                                >
                            </label>

                            <label>
                                <span>Коммерческие предложения</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    data-field="offers"
                                    value="${escapeHtml(reportInputValue(channel.offers))}"
                                >
                            </label>

                            <label>
                                <span>Нецелевые обращения</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    data-field="non_target"
                                    value="${escapeHtml(reportInputValue(channel.non_target))}"
                                >
                            </label>

                            <label>
                                <span>Дубли и спам</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    data-field="duplicates"
                                    value="${escapeHtml(reportInputValue(channel.duplicates))}"
                                >
                            </label>

                            <label>
                                <span>Не удалось связаться</span>
                                <input
                                    type="number"
                                    min="0"
                                    step="1"
                                    data-field="unreachable"
                                    value="${escapeHtml(reportInputValue(channel.unreachable))}"
                                >
                            </label>
                        </div>

                        <label class="report-channel-notes">
                            <span>Комментарий по каналу</span>
                            <textarea
                                rows="3"
                                data-field="notes"
                                placeholder="Качество лидов, причины отклонений, ограничения..."
                            >${escapeHtml(channel.notes || '')}</textarea>
                        </label>
                    </details>
                </article>
            `;
        }).join('');
    }

    function readReportChannels() {
        return $$('.report-channel-card').map(card => {
            const value = field => {
                return card.querySelector(
                    `[data-field="${field}"]`
                )?.value ?? '';
            };

            return {
                channel_key: card.dataset.channelKey,
                channel_name: card.dataset.channelName,
                source_type: value('source_type') || 'manual',
                spend: reportNumberValue(value('spend')),
                impressions: reportNumberValue(value('impressions')),
                clicks: reportNumberValue(value('clicks')),
                leads: reportNumberValue(value('leads')),
                qualified_leads: reportNumberValue(
                    value('qualified_leads')
                ),
                meetings: reportNumberValue(value('meetings')),
                offers: reportNumberValue(value('offers')),
                contracts: reportNumberValue(value('contracts')),
                contract_amount: reportNumberValue(
                    value('contract_amount')
                ),
                paid_revenue: reportNumberValue(
                    value('paid_revenue')
                ),
                gross_margin_percent: reportNullableNumber(
                    value('gross_margin_percent')
                ),
                non_target: reportNumberValue(value('non_target')),
                duplicates: reportNumberValue(value('duplicates')),
                unreachable: reportNumberValue(value('unreachable')),
                notes: value('notes').trim()
            };
        });
    }

    function collectReportPayload() {
        const form = $('#reportForm');

        if (!form) {
            throw new Error('Форма отчёта не найдена.');
        }

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
            comparison_date_from:
                form.elements.comparison_date_from.value,
            comparison_date_to:
                form.elements.comparison_date_to.value,
            work_done: form.elements.work_done.value.trim(),
            next_plan: form.elements.next_plan.value.trim(),
            notes: form.elements.notes.value.trim(),
            channels: readReportChannels()
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

        $('#reportEditorTitle').textContent = 'Новый отчёт';
        $('#reportFormMessage').className = '';
        $('#reportFormMessage').textContent = '';

        renderReportChannels('advertising_summary', []);

        $('#reportPreview')?.classList.add('hidden');
    }

    function fillReportForm(report) {
        const form = $('#reportForm');

        if (!form) return;

        form.elements.report_id.value = String(report.id || 0);
        form.elements.title.value = report.title || '';
        form.elements.report_type.value =
            report.report_type || 'advertising_summary';
        form.elements.audience.value = report.audience || 'owner';
        form.elements.status.value = report.status || 'draft';
        form.elements.date_from.value = report.date_from || '';
        form.elements.date_to.value = report.date_to || '';
        form.elements.comparison_date_from.value =
            report.comparison_date_from || '';
        form.elements.comparison_date_to.value =
            report.comparison_date_to || '';
        form.elements.work_done.value = report.work_done || '';
        form.elements.next_plan.value = report.next_plan || '';
        form.elements.notes.value = report.notes || '';

        $('#reportEditorTitle').textContent =
            report.title || 'Редактирование отчёта';

        renderReportChannels(
            report.report_type,
            report.channels || []
        );
    }

    function renderReportsList(reports) {
        const root = $('#reportsList');

        if (!root) return;

        if (!reports.length) {
            root.innerHTML = `
                <div class="reports-empty">
                    <strong>Отчётов пока нет</strong>
                    <span>Создайте первый черновик.</span>
                </div>
            `;
            return;
        }

        root.innerHTML = reports.map(report => `
            <button
                type="button"
                class="report-list-item"
                data-report-id="${report.id}"
            >
                <span class="report-list-top">
                    <strong>${escapeHtml(report.title)}</strong>
                    <em class="report-status report-status-${escapeHtml(report.status)}">
                        ${escapeHtml(reportStatusLabels[report.status] || report.status)}
                    </em>
                </span>

                <span class="report-list-meta">
                    ${escapeHtml(reportTypeLabels[report.report_type] || report.report_type)}
                    · ${escapeHtml(reportDate(report.date_from))}
                    —${escapeHtml(reportDate(report.date_to))}
                </span>

                <span class="report-list-kpi">
                    Расход: ${escapeHtml(reportMoney(report.spend))}
                    · Заявки: ${escapeHtml(number.format(report.leads || 0))}
                    · Договоры: ${escapeHtml(number.format(report.contracts || 0))}
                </span>
            </button>
        `).join('');
    }

    async function loadReports(force = false) {
        if (!$('#reportForm')) return;
        if (reportsLoaded && !force) return;

        const root = $('#reportsList');

        if (root) {
            root.innerHTML = '<span class="muted">Загрузка...</span>';
        }

        try {
            const result = await api('/api.php?action=reports_list');
            renderReportsList(result.reports || []);
            reportsLoaded = true;
        } catch (error) {
            if (root) {
                root.innerHTML = `
                    <div class="alert alert-error">
                        ${escapeHtml(error.message)}
                    </div>
                `;
            }
        }
    }

    async function openReport(reportId) {
        const message = $('#reportsMessage');

        try {
            const result = await api(
                `/api.php?action=report_get&id=${encodeURIComponent(reportId)}`
            );

            fillReportForm(result.report);
            renderReportPreview(result.report);

            message.className = 'quality-banner ok';
            message.textContent = 'Отчёт загружен из истории.';
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    }

    function renderReportPreview(payload) {
        const root = $('#reportPreviewContent');
        const preview = $('#reportPreview');

        if (!root || !preview) return;

        const channels = payload.channels || [];
        const summary = payload.summary || reportSummary(channels);
        const calculated = summary.calculated
            || reportSummary(channels).calculated;

        const title = payload.title
            || (
                reportTypeLabels[payload.report_type]
                || 'Отчёт'
            );

        const comparison = payload.comparison_date_from
            && payload.comparison_date_to
                ? `
                    <span>
                        Сравнение:
                        ${escapeHtml(reportDate(payload.comparison_date_from))}
                        —${escapeHtml(reportDate(payload.comparison_date_to))}
                    </span>
                `
                : '';

        const cards = [
            ['Расходы', reportMoney(summary.spend), 'Все каналы'],
            ['Заявки', number.format(summary.leads || 0), 'Уникальные'],
            [
                'Квалифицированные',
                number.format(summary.qualified_leads || 0),
                'Целевые лиды'
            ],
            ['Договоры', number.format(summary.contracts || 0), 'Продажи'],
            ['CPL', reportMoney(calculated.cpl), 'Расход / заявки'],
            ['CPQL', reportMoney(calculated.cpql), 'Расход / квалифицированные'],
            [
                'Стоимость договора',
                reportMoney(calculated.cost_per_contract),
                'Расход / договоры'
            ],
            [
                'Оплаченная выручка',
                reportMoney(summary.paid_revenue),
                'Фактические оплаты'
            ],
            ['ROAS', reportMultiplier(calculated.roas), 'Выручка / расход'],
            ['ROMI', reportPercent(calculated.romi), 'По валовой прибыли']
        ];

        const channelRows = channels.map(channel => {
            const metrics = channel.calculated
                || reportChannelCalculations(channel);

            return `
                <tr>
                    <td>
                        <strong>${escapeHtml(channel.channel_name)}</strong>
                        <small class="report-source-badge">
                            ${escapeHtml(channel.source_type || 'manual')}
                        </small>
                    </td>
                    <td class="num">${escapeHtml(reportMoney(channel.spend))}</td>
                    <td class="num">${escapeHtml(number.format(channel.clicks || 0))}</td>
                    <td class="num">${escapeHtml(number.format(channel.leads || 0))}</td>
                    <td class="num">${escapeHtml(number.format(channel.qualified_leads || 0))}</td>
                    <td class="num">${escapeHtml(number.format(channel.contracts || 0))}</td>
                    <td class="num">${escapeHtml(reportMoney(metrics.cpl))}</td>
                    <td class="num">${escapeHtml(reportMoney(metrics.cpql))}</td>
                    <td class="num">${escapeHtml(reportMoney(metrics.cost_per_contract))}</td>
                    <td class="num">${escapeHtml(reportMoney(channel.paid_revenue))}</td>
                    <td class="num">${escapeHtml(reportMultiplier(metrics.roas))}</td>
                </tr>
            `;
        }).join('');

        const textBlock = (title, value) => {
            if (!value) return '';

            return `
                <section class="report-preview-text">
                    <h3>${escapeHtml(title)}</h3>
                    <div>${escapeHtml(value)}</div>
                </section>
            `;
        };

        root.innerHTML = `
            <header class="report-document-head">
                <p class="eyebrow">Мир сайтов</p>
                <h1>${escapeHtml(title)}</h1>
                <div class="report-document-meta">
                    <span>
                        Период:
                        ${escapeHtml(reportDate(payload.date_from))}
                        —${escapeHtml(reportDate(payload.date_to))}
                    </span>
                    <span>
                        Получатель:
                        ${escapeHtml(reportAudienceLabels[payload.audience] || payload.audience)}
                    </span>
                    ${comparison}
                </div>
            </header>

            <div class="metric-grid report-preview-cards">
                ${cards.map(([cardTitle, value, note]) => `
                    <article class="metric-card">
                        <span>${escapeHtml(cardTitle)}</span>
                        <strong>${escapeHtml(value)}</strong>
                        <small>${escapeHtml(note)}</small>
                    </article>
                `).join('')}
            </div>

            <section class="report-preview-section">
                <div class="reports-section-head">
                    <div>
                        <strong>Результаты по каналам</strong>
                        <p class="muted">
                            Заявки и договоры не подменяются микроконверсиями.
                        </p>
                    </div>
                </div>

                <div class="table-scroll report-preview-table">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Канал</th>
                                <th class="num">Расход</th>
                                <th class="num">Клики</th>
                                <th class="num">Заявки</th>
                                <th class="num">Квал.</th>
                                <th class="num">Договоры</th>
                                <th class="num">CPL</th>
                                <th class="num">CPQL</th>
                                <th class="num">Цена договора</th>
                                <th class="num">Выручка</th>
                                <th class="num">ROAS</th>
                            </tr>
                        </thead>
                        <tbody>${channelRows}</tbody>
                    </table>
                </div>
            </section>

            <div class="report-preview-text-grid">
                ${textBlock('Что сделано за период', payload.work_done)}
                ${textBlock('План следующего периода', payload.next_plan)}
            </div>

            ${textBlock('Комментарии и ограничения данных', payload.notes)}

            <section class="report-formulas-note">
                <strong>Расчёты:</strong>
                CPL = расходы / заявки;
                CPQL = расходы / квалифицированные лиды;
                стоимость договора = расходы / договоры;
                ROAS = оплаченная выручка / расходы.
                ROMI рассчитывается только при заполненной валовой марже.
            </section>
        `;

        preview.classList.remove('hidden');
        preview.scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    $('.nav-link[data-section="reports"]')?.addEventListener(
        'click',
        () => loadReports()
    );

    $('#newReport')?.addEventListener('click', () => {
        resetReportForm();
        showSection('reports');
    });

    $('#reportsList')?.addEventListener('click', event => {
        const item = event.target.closest('[data-report-id]');

        if (!item) return;

        openReport(Number(item.dataset.reportId));
    });

    $('#reportForm [name="report_type"]')?.addEventListener(
        'change',
        event => {
            renderReportChannels(
                event.currentTarget.value,
                []
            );
        }
    );

    $('#previewReport')?.addEventListener('click', () => {
        try {
            const payload = collectReportPayload();

            if (!payload.date_from || !payload.date_to) {
                throw new Error('Укажите период отчёта.');
            }

            renderReportPreview(payload);
        } catch (error) {
            const message = $('#reportFormMessage');
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    });

    $('#printReport')?.addEventListener('click', () => {
        window.print();
    });

    $('#reportForm')?.addEventListener('submit', async event => {
        event.preventDefault();

        const form = event.currentTarget;
        const message = $('#reportFormMessage');
        const submit = form.querySelector(
            'button[type="submit"]'
        );

        submit.disabled = true;
        message.className = '';
        message.textContent = 'Сохранение...';

        try {
            const payload = collectReportPayload();

            if (!payload.date_from || !payload.date_to) {
                throw new Error('Укажите период отчёта.');
            }

            const result = await api(
                '/api.php?action=save_report',
                {
                    method: 'POST',
                    body: JSON.stringify(payload)
                }
            );

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

    if ($('#reportForm')) {
        resetReportForm();
    }
JS;

$reportsCss = <<<'CSS'

/* REPORTS_STEP1_CSS */
select {
    width: 100%;
    border: 1px solid var(--line);
    background: white;
    color: var(--text);
    padding: 11px 12px;
    border-radius: 10px;
    outline: none;
    transition: .15s ease;
}

select:focus {
    border-color: #9bbcff;
    box-shadow: 0 0 0 4px rgba(20, 99, 255, .08);
}

.reports-toolbar {
    display: flex;
    justify-content: space-between;
    align-items: flex-end;
    gap: 18px;
    margin-bottom: 18px;
}

.reports-toolbar-note {
    margin: 8px 0 0;
}

.reports-layout {
    display: grid;
    grid-template-columns: 330px minmax(0, 1fr);
    gap: 18px;
    align-items: start;
}

.reports-history-panel {
    padding: 16px;
}

.reports-list {
    display: grid;
    gap: 9px;
    max-height: 720px;
    overflow: auto;
}

.report-list-item {
    width: 100%;
    border: 1px solid var(--line);
    background: #f8fafc;
    color: var(--text);
    padding: 12px;
    border-radius: 12px;
    text-align: left;
    transition: .15s ease;
}

.report-list-item:hover {
    background: white;
    border-color: #b8cbff;
    box-shadow: 0 8px 18px rgba(16, 24, 40, .07);
}

.report-list-top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 8px;
}

.report-list-top strong {
    line-height: 1.3;
}

.report-list-top em {
    flex: 0 0 auto;
}

.report-list-meta,
.report-list-kpi {
    display: block;
    margin-top: 7px;
    color: var(--muted);
    font-size: 11px;
    font-style: normal;
    line-height: 1.45;
}

.report-status {
    display: inline-flex;
    padding: 4px 7px;
    border-radius: 999px;
    background: #eef2f6;
    color: #475467;
    font-size: 9px;
    font-style: normal;
    font-weight: 800;
    white-space: nowrap;
}

.report-status-review {
    background: #fff8e7;
    color: #a16600;
}

.report-status-approved,
.report-status-sent {
    background: #ecfdf3;
    color: #067647;
}

.report-status-archive {
    background: #f2f4f7;
    color: #667085;
}

.reports-empty {
    display: grid;
    gap: 5px;
    padding: 18px 12px;
    border: 1px dashed #b8c4d8;
    border-radius: 12px;
    text-align: center;
}

.reports-empty span {
    color: var(--muted);
    font-size: 12px;
}

.report-editor-panel {
    min-width: 0;
}

.report-title-field {
    grid-column: span 2;
}

.reports-section-head {
    display: flex;
    justify-content: space-between;
    gap: 15px;
    align-items: flex-start;
}

.reports-section-head p {
    margin: 5px 0 0;
}

.report-channels {
    display: grid;
    gap: 14px;
}

.report-channel-card {
    border: 1px solid var(--line);
    border-radius: 14px;
    background: #fbfcfe;
    padding: 15px;
}

.report-channel-head {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 15px;
    margin-bottom: 14px;
}

.report-channel-head strong,
.report-channel-head small {
    display: block;
}

.report-channel-head small {
    margin-top: 4px;
    color: var(--muted);
}

.report-source-label {
    width: 170px;
    display: grid;
    gap: 5px;
    color: var(--muted);
    font-size: 11px;
}

.report-source-label select {
    padding: 8px 10px;
    font-size: 12px;
}

.report-channel-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 11px;
}

.report-channel-grid label,
.report-channel-notes {
    display: grid;
    gap: 6px;
    color: var(--muted);
    font-size: 12px;
}

.report-channel-grid input {
    padding: 9px 10px;
}

.report-advanced {
    margin-top: 14px;
    border-top: 1px solid var(--line);
    padding-top: 12px;
}

.report-advanced summary {
    color: #2859bd;
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;
}

.report-advanced[open] summary {
    margin-bottom: 13px;
}

.report-channel-notes {
    margin-top: 12px;
}

.report-text-grid textarea {
    min-height: 155px;
}

.report-actions {
    display: flex;
    gap: 10px;
    align-items: center;
}

.report-preview {
    margin-top: 18px;
}

.report-preview-head {
    align-items: flex-start;
}

.report-document-head {
    padding-bottom: 20px;
    border-bottom: 1px solid var(--line);
    margin-bottom: 20px;
}

.report-document-head h1 {
    margin-bottom: 12px;
}

.report-document-meta {
    display: flex;
    flex-wrap: wrap;
    gap: 8px 18px;
    color: var(--muted);
    font-size: 12px;
}

.report-preview-cards {
    margin-bottom: 24px;
}

.report-preview-section {
    margin-top: 22px;
}

.report-preview-table {
    max-height: none;
}

.report-source-badge {
    display: block;
    margin-top: 4px;
    color: var(--muted);
    font-size: 9px;
    text-transform: uppercase;
}

.report-preview-text-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-top: 22px;
}

.report-preview-text {
    padding: 17px;
    border: 1px solid var(--line);
    border-radius: 13px;
    background: #fbfcfe;
}

.report-preview-text h3 {
    font-size: 15px;
    margin-bottom: 10px;
}

.report-preview-text div {
    white-space: pre-wrap;
    color: #344054;
    font-size: 13px;
    line-height: 1.6;
}

.report-formulas-note {
    margin-top: 18px;
    padding: 13px 15px;
    border: 1px solid #d8e3ff;
    border-radius: 11px;
    background: #f5f8ff;
    color: #2859bd;
    font-size: 12px;
    line-height: 1.55;
}

@media (max-width: 1180px) {
    .reports-layout {
        grid-template-columns: 1fr;
    }

    .reports-list {
        max-height: 330px;
    }
}

@media (max-width: 760px) {
    .reports-toolbar,
    .report-channel-head {
        align-items: stretch;
        flex-direction: column;
    }

    .report-source-label {
        width: 100%;
    }

    .report-channel-grid,
    .report-preview-text-grid {
        grid-template-columns: 1fr;
    }

    .report-title-field {
        grid-column: auto;
    }

    .report-actions {
        align-items: stretch;
        flex-direction: column;
    }

    .report-actions .button {
        width: 100%;
    }
}

@media print {
    body {
        background: white;
    }

    .sidebar,
    .topbar,
    .reports-toolbar,
    .reports-layout,
    #reportsMessage,
    #reportPreview .report-preview-head .button {
        display: none !important;
    }

    .content {
        padding: 0;
        overflow: visible;
    }

    .section {
        display: none !important;
    }

    #section-reports {
        display: block !important;
    }

    #reportPreview,
    #reportPreview.hidden {
        display: block !important;
        margin: 0;
        padding: 0;
        border: 0;
        box-shadow: none;
    }

    .metric-card,
    .panel,
    .report-preview-text {
        box-shadow: none;
    }

    .table-scroll {
        overflow: visible;
        max-height: none;
    }
}
CSS;

$apiGetCode = <<<'PHPAPI'

        // REPORTS_STEP1_API_GET
        if ($action === 'reports_list') {
            $project = $projectRepository->firstActive();

            if (!$project) {
                Security::json(
                    ['error' => 'Проект не настроен.'],
                    422
                );
            }

            $reports = (new ReportRepository())->listByProject(
                (int) $project['id']
            );

            Security::json([
                'reports' => $reports,
            ]);
        }

        if ($action === 'report_get') {
            $project = $projectRepository->firstActive();

            if (!$project) {
                Security::json(
                    ['error' => 'Проект не настроен.'],
                    422
                );
            }

            $reportId = (int) ($_GET['id'] ?? 0);

            if ($reportId <= 0) {
                Security::json(
                    ['error' => 'Некорректный ID отчёта.'],
                    422
                );
            }

            $report = (new ReportRepository())->find(
                $reportId,
                (int) $project['id']
            );

            if (!$report) {
                Security::json(
                    ['error' => 'Отчёт не найден.'],
                    404
                );
            }

            Security::json([
                'report' => $report,
            ]);
        }

PHPAPI;

$apiPostCode = <<<'PHPAPI'

    // REPORTS_STEP1_API_POST
    if ($action === 'save_report') {
        $project = $projectRepository->firstActive();

        if (!$project) {
            Security::json(
                ['error' => 'Проект не настроен.'],
                422
            );
        }

        [$dateFrom, $dateTo] = validateDates(
            trim((string) ($input['date_from'] ?? '')),
            trim((string) ($input['date_to'] ?? ''))
        );

        $comparisonDateFrom = trim(
            (string) ($input['comparison_date_from'] ?? '')
        );
        $comparisonDateTo = trim(
            (string) ($input['comparison_date_to'] ?? '')
        );

        if (
            ($comparisonDateFrom === '')
            !== ($comparisonDateTo === '')
        ) {
            Security::json(
                [
                    'error' =>
                        'Для сравнения укажите обе даты периода.'
                ],
                422
            );
        }

        if (
            $comparisonDateFrom !== ''
            && $comparisonDateTo !== ''
        ) {
            [$comparisonDateFrom, $comparisonDateTo] =
                validateDates(
                    $comparisonDateFrom,
                    $comparisonDateTo
                );
        }

        $title = trim((string) ($input['title'] ?? ''));

        if (mb_strlen($title) > 190) {
            Security::json(
                [
                    'error' =>
                        'Название отчёта не должно превышать 190 символов.'
                ],
                422
            );
        }

        $channels = is_array($input['channels'] ?? null)
            ? array_values($input['channels'])
            : [];

        if (count($channels) > 10) {
            Security::json(
                ['error' => 'Слишком много рекламных каналов.'],
                422
            );
        }

        $repository = new ReportRepository();

        $reportId = $repository->save(
            [
                'id' => (int) ($input['id'] ?? 0),
                'project_id' => (int) $project['id'],
                'title' => $title,
                'report_type' => trim(
                    (string) ($input['report_type'] ?? '')
                ),
                'audience' => trim(
                    (string) ($input['audience'] ?? 'owner')
                ),
                'status' => trim(
                    (string) ($input['status'] ?? 'draft')
                ),
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'comparison_date_from' =>
                    $comparisonDateFrom ?: null,
                'comparison_date_to' =>
                    $comparisonDateTo ?: null,
                'work_done' => (string) (
                    $input['work_done'] ?? ''
                ),
                'next_plan' => (string) (
                    $input['next_plan'] ?? ''
                ),
                'notes' => (string) (
                    $input['notes'] ?? ''
                ),
                'channels' => $channels,
            ],
            Auth::id()
        );

        $report = $repository->find(
            $reportId,
            (int) $project['id']
        );

        Auth::audit(
            'report_saved',
            [
                'project_id' => (int) $project['id'],
                'report_id' => $reportId,
                'report_type' => $report['report_type'] ?? null,
                'status' => $report['status'] ?? null,
            ]
        );

        Security::json([
            'ok' => true,
            'report' => $report,
            'message' => 'Отчёт сохранён.',
        ]);
    }

PHPAPI;

try {
    $index = $indexOriginal;
    $api = readRequired($apiPath);
    $js = readRequired($jsPath);
    $css = readRequired($cssPath);
    $schema = readRequired($schemaPath);

    $navigationNeedle = <<<'HTML'
                <button class="nav-link" data-section="webmaster">Яндекс Вебмастер</button>
                <button class="nav-link" data-section="settings">Настройки</button>
HTML;

    $navigationReplacement = <<<'HTML'
                <button class="nav-link" data-section="webmaster">Яндекс Вебмастер</button>
                <button class="nav-link" data-section="reports">Отчёты</button>
                <button class="nav-link" data-section="settings">Настройки</button>
HTML;

    $index = replaceOnce(
        $index,
        $navigationNeedle,
        $navigationReplacement,
        'пункт меню «Отчёты»'
    );

    $index = insertBeforeOnce(
        $index,
        '        <section id="section-settings" class="section">',
        $reportsHtml . "\n",
        'раздел отчётов'
    );

    $index = preg_replace(
        '#/assets/app\.css\?v=\d+#',
        '/assets/app.css?v=7',
        $index
    ) ?? $index;

    $index = preg_replace(
        '#/assets/app\.js\?v=\d+#',
        '/assets/app.js?v=7',
        $index
    ) ?? $index;

    $api = replaceOnce(
        $api,
        'use SeoAnalytics\Repositories\ProjectRepository;',
        "use SeoAnalytics\\Repositories\\ProjectRepository;\n"
        . 'use SeoAnalytics\Repositories\ReportRepository;',
        'импорт ReportRepository'
    );

    $api = insertBeforeOnce(
        $api,
        "        Security::json(['error' => 'Неизвестное действие.'], 404);",
        $apiGetCode,
        'GET API отчётов'
    );

    $api = insertBeforeOnce(
        $api,
        "    if (\$action === 'save_project') {",
        $apiPostCode,
        'POST API отчётов'
    );

    $js = insertBeforeOnce(
        $js,
        '    async function loadDashboard() {',
        $reportsJs . "\n",
        'JavaScript модуля отчётов'
    );

    $css .= "\n" . $reportsCss . "\n";

    if (!str_contains($schema, 'REPORTS_STEP1_SCHEMA')) {
        $schema .= "\n\n-- REPORTS_STEP1_SCHEMA\n";

        foreach ($schemaStatements as $statement) {
            $schema .= $statement . ";\n\n";
        }
    }

    writeAtomic($repositoryPath, $repositoryContent . "\n");
    writeAtomic($indexPath, $index);
    writeAtomic($apiPath, $api);
    writeAtomic($jsPath, $js);
    writeAtomic($cssPath, $css);
    writeAtomic($schemaPath, $schema);

    lintPhp($repositoryPath);
    lintPhp($indexPath);
    lintPhp($apiPath);

    require $root . '/app/bootstrap.php';

    $pdo = \SeoAnalytics\Core\Database::pdo();

    foreach ($schemaStatements as $statement) {
        $pdo->exec($statement);
    }

    console('');
    console('Модуль отчётов — шаг 1 — установлен.');
    console('');
    console('Добавлено:');
    console('- раздел «Отчёты»;');
    console('- сводный рекламный и SEO-отчёт;');
    console('- ручной ввод заявок, квалифицированных лидов и договоров;');
    console('- CPL, CPQL, стоимость договора, ROAS и ROMI;');
    console('- история черновиков;');
    console('- предпросмотр и печать в PDF.');
    console('');
    console('Проверьте сайт: https://seo-test.mirsaitov.pw');
    console('');
    console("Резервная копия: {$backupDirectory}");
} catch (Throwable $exception) {
    rollbackFiles(
        $root,
        $backupDirectory,
        $manifest
    );

    fwrite(
        STDERR,
        PHP_EOL
        . 'ОШИБКА: '
        . $exception->getMessage()
        . PHP_EOL
        . 'Файлы восстановлены из резервной копии.'
        . PHP_EOL
    );

    exit(1);
}
