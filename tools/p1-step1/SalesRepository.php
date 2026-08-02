<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use PDOException;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class SalesRepository
{
    public const CHANNELS = [
        'direct' => 'Яндекс Директ',
        'vk' => 'VK Реклама',
        'avito' => 'Авито',
        '2gis' => '2ГИС',
        'yandex_business' => 'Яндекс Бизнес',
        'seo' => 'SEO',
        'referral' => 'Рекомендации',
        'repeat' => 'Повторные продажи',
        'other' => 'Другое',
    ];

    public const STATUSES = [
        'lead' => 'Заявка',
        'qualified' => 'Квалифицирован',
        'meeting' => 'Встреча',
        'offer' => 'Предложение',
        'contract' => 'Договор',
        'paid' => 'Оплачено',
        'lost' => 'Отказ',
    ];

    public function list(
        int $projectId,
        string $dateFrom,
        string $dateTo,
        int $limit = 500
    ): array {
        $limit = min(2000, max(1, $limit));
        $stmt = Database::pdo()->prepare(
            'SELECT
                r.*,
                u.name AS author_name,
                b.original_name AS import_file
             FROM sales_records r
             INNER JOIN users u ON u.id = r.created_by
             LEFT JOIN sales_import_batches b ON b.id = r.import_batch_id
             WHERE r.project_id = :project_id
               AND r.occurred_at BETWEEN :date_from AND :date_to
             ORDER BY r.occurred_at DESC, r.id DESC
             LIMIT ' . $limit
        );
        $stmt->execute([
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $row = $this->normalizeStored($row);
        }
        unset($row);

        return $rows;
    }

    public function summary(
        int $projectId,
        string $dateFrom,
        string $dateTo
    ): array {
        $stmt = Database::pdo()->prepare(
            'SELECT
                COUNT(*) AS records_count,
                SUM(qualified = 1) AS qualified_count,
                SUM(contract = 1) AS contracts_count,
                SUM(status = "paid") AS paid_count,
                SUM(status = "lost") AS lost_count,
                COALESCE(SUM(contract_amount), 0) AS contract_amount,
                COALESCE(SUM(paid_amount), 0) AS paid_amount
             FROM sales_records
             WHERE project_id = :project_id
               AND occurred_at BETWEEN :date_from AND :date_to'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

        foreach ([
            'records_count',
            'qualified_count',
            'contracts_count',
            'paid_count',
            'lost_count',
        ] as $key) {
            $summary[$key] = (int) ($summary[$key] ?? 0);
        }
        foreach (['contract_amount', 'paid_amount'] as $key) {
            $summary[$key] = (float) ($summary[$key] ?? 0);
        }

        $summary['average_contract'] = $summary['contracts_count'] > 0
            ? $summary['contract_amount'] / $summary['contracts_count']
            : null;

        $channelStmt = Database::pdo()->prepare(
            'SELECT
                channel_key,
                COUNT(*) AS records_count,
                SUM(qualified = 1) AS qualified_count,
                SUM(contract = 1) AS contracts_count,
                COALESCE(SUM(contract_amount), 0) AS contract_amount,
                COALESCE(SUM(paid_amount), 0) AS paid_amount
             FROM sales_records
             WHERE project_id = :project_id
               AND occurred_at BETWEEN :date_from AND :date_to
             GROUP BY channel_key
             ORDER BY paid_amount DESC, contract_amount DESC, records_count DESC'
        );
        $channelStmt->execute([
            'project_id' => $projectId,
            'date_from' => $dateFrom,
            'date_to' => $dateTo,
        ]);
        $channels = $channelStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($channels as &$channel) {
            $channel['channel_name'] = self::CHANNELS[$channel['channel_key']]
                ?? $channel['channel_key'];
            foreach (['records_count', 'qualified_count', 'contracts_count'] as $key) {
                $channel[$key] = (int) $channel[$key];
            }
            foreach (['contract_amount', 'paid_amount'] as $key) {
                $channel[$key] = (float) $channel[$key];
            }
        }
        unset($channel);
        $summary['channels'] = $channels;

        return $summary;
    }

    public function save(array $data, int $userId, int $projectId): int
    {
        if ($userId <= 0 || $projectId <= 0) {
            throw new RuntimeException('Не удалось определить пользователя или проект.');
        }

        $record = $this->sanitize($data);
        $id = max(0, (int) ($data['id'] ?? 0));
        $pdo = Database::pdo();

        if ($id > 0) {
            $stmt = $pdo->prepare(
                'UPDATE sales_records SET
                    external_id = :external_id,
                    occurred_at = :occurred_at,
                    channel_key = :channel_key,
                    customer_name = :customer_name,
                    status = :status,
                    contract_amount = :contract_amount,
                    paid_amount = :paid_amount,
                    gross_margin_percent = :gross_margin_percent,
                    qualified = :qualified,
                    contract = :contract,
                    notes = :notes,
                    fingerprint = :fingerprint,
                    updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id
                   AND project_id = :project_id'
            );
            $stmt->execute($record + [
                'id' => $id,
                'project_id' => $projectId,
            ]);
            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare(
                    'SELECT id FROM sales_records
                     WHERE id = :id AND project_id = :project_id'
                );
                $check->execute(['id' => $id, 'project_id' => $projectId]);
                if (!$check->fetchColumn()) {
                    throw new RuntimeException('Запись продажи не найдена.');
                }
            }
            return $id;
        }

        $stmt = $pdo->prepare(
            'INSERT INTO sales_records
             (
                project_id,
                import_batch_id,
                created_by,
                external_id,
                occurred_at,
                channel_key,
                customer_name,
                status,
                contract_amount,
                paid_amount,
                gross_margin_percent,
                qualified,
                contract,
                source_type,
                notes,
                fingerprint,
                created_at,
                updated_at
             )
             VALUES
             (
                :project_id,
                NULL,
                :created_by,
                :external_id,
                :occurred_at,
                :channel_key,
                :customer_name,
                :status,
                :contract_amount,
                :paid_amount,
                :gross_margin_percent,
                :qualified,
                :contract,
                "manual",
                :notes,
                :fingerprint,
                CURRENT_TIMESTAMP,
                CURRENT_TIMESTAMP
             )'
        );

        try {
            $stmt->execute($record + [
                'project_id' => $projectId,
                'created_by' => $userId,
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }
            $existing = $pdo->prepare(
                'SELECT id FROM sales_records
                 WHERE project_id = :project_id
                   AND fingerprint = :fingerprint
                 LIMIT 1'
            );
            $existing->execute([
                'project_id' => $projectId,
                'fingerprint' => $record['fingerprint'],
            ]);
            $existingId = (int) $existing->fetchColumn();
            if ($existingId > 0) {
                return $existingId;
            }
            throw $exception;
        }

        return (int) $pdo->lastInsertId();
    }

    public function delete(int $id, int $projectId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM sales_records
             WHERE id = :id AND project_id = :project_id'
        );
        $stmt->execute(['id' => $id, 'project_id' => $projectId]);
        return $stmt->rowCount() > 0;
    }

    public function import(
        array $rows,
        array $meta,
        int $userId,
        int $projectId
    ): array {
        if ($userId <= 0 || $projectId <= 0) {
            throw new RuntimeException('Не удалось определить пользователя или проект.');
        }

        $pdo = Database::pdo();
        $pdo->beginTransaction();

        try {
            $batchStmt = $pdo->prepare(
                'INSERT INTO sales_import_batches
                 (
                    project_id,
                    created_by,
                    original_name,
                    file_type,
                    rows_total,
                    rows_imported,
                    rows_skipped,
                    rows_failed,
                    report_json,
                    created_at
                 )
                 VALUES
                 (
                    :project_id,
                    :created_by,
                    :original_name,
                    :file_type,
                    :rows_total,
                    0,
                    0,
                    :rows_failed,
                    :report_json,
                    CURRENT_TIMESTAMP
                 )'
            );
            $batchStmt->execute([
                'project_id' => $projectId,
                'created_by' => $userId,
                'original_name' => mb_substr((string) ($meta['original_name'] ?? 'import'), 0, 255),
                'file_type' => mb_substr((string) ($meta['file_type'] ?? 'csv'), 0, 20),
                'rows_total' => count($rows) + count($meta['errors'] ?? []),
                'rows_failed' => count($meta['errors'] ?? []),
                'report_json' => json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $batchId = (int) $pdo->lastInsertId();

            $insert = $pdo->prepare(
                'INSERT IGNORE INTO sales_records
                 (
                    project_id,
                    import_batch_id,
                    created_by,
                    external_id,
                    occurred_at,
                    channel_key,
                    customer_name,
                    status,
                    contract_amount,
                    paid_amount,
                    gross_margin_percent,
                    qualified,
                    contract,
                    source_type,
                    notes,
                    fingerprint,
                    created_at,
                    updated_at
                 )
                 VALUES
                 (
                    :project_id,
                    :import_batch_id,
                    :created_by,
                    :external_id,
                    :occurred_at,
                    :channel_key,
                    :customer_name,
                    :status,
                    :contract_amount,
                    :paid_amount,
                    :gross_margin_percent,
                    :qualified,
                    :contract,
                    "import",
                    :notes,
                    :fingerprint,
                    CURRENT_TIMESTAMP,
                    CURRENT_TIMESTAMP
                 )'
            );

            $imported = 0;
            $skipped = 0;
            foreach ($rows as $row) {
                $record = $this->sanitize($row);
                $insert->execute($record + [
                    'project_id' => $projectId,
                    'import_batch_id' => $batchId,
                    'created_by' => $userId,
                ]);
                if ($insert->rowCount() > 0) {
                    $imported++;
                } else {
                    $skipped++;
                }
            }

            $update = $pdo->prepare(
                'UPDATE sales_import_batches SET
                    rows_imported = :rows_imported,
                    rows_skipped = :rows_skipped
                 WHERE id = :id'
            );
            $update->execute([
                'rows_imported' => $imported,
                'rows_skipped' => $skipped,
                'id' => $batchId,
            ]);

            $pdo->commit();

            return [
                'batch_id' => $batchId,
                'rows_total' => count($rows) + count($meta['errors'] ?? []),
                'rows_imported' => $imported,
                'rows_skipped' => $skipped,
                'rows_failed' => count($meta['errors'] ?? []),
                'errors' => array_slice($meta['errors'] ?? [], 0, 100),
            ];
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function sanitize(array $data): array
    {
        $status = (string) ($data['status'] ?? 'lead');
        if (!isset(self::STATUSES[$status])) {
            $status = 'lead';
        }

        $channel = (string) ($data['channel_key'] ?? 'other');
        if (!isset(self::CHANNELS[$channel])) {
            $channel = 'other';
        }

        $date = $this->date($data['occurred_at'] ?? null);
        if ($date === null) {
            throw new RuntimeException('Укажите корректную дату записи.');
        }

        $qualified = $this->boolean($data['qualified'] ?? false)
            || in_array($status, ['qualified', 'meeting', 'offer', 'contract', 'paid'], true);
        $contract = $this->boolean($data['contract'] ?? false)
            || in_array($status, ['contract', 'paid'], true);

        $record = [
            'external_id' => $this->nullableText($data['external_id'] ?? null, 190),
            'occurred_at' => $date,
            'channel_key' => $channel,
            'customer_name' => $this->text($data['customer_name'] ?? '', 255),
            'status' => $status,
            'contract_amount' => $this->decimal($data['contract_amount'] ?? 0),
            'paid_amount' => $this->decimal($data['paid_amount'] ?? 0),
            'gross_margin_percent' => $this->nullablePercent($data['gross_margin_percent'] ?? null),
            'qualified' => $qualified ? 1 : 0,
            'contract' => $contract ? 1 : 0,
            'notes' => $this->nullableText($data['notes'] ?? null, 5000),
        ];
        $record['fingerprint'] = $this->fingerprint($record);

        return $record;
    }

    private function normalizeStored(array $row): array
    {
        foreach (['id', 'project_id', 'import_batch_id', 'created_by', 'qualified', 'contract'] as $key) {
            $row[$key] = $row[$key] === null ? null : (int) $row[$key];
        }
        foreach (['contract_amount', 'paid_amount'] as $key) {
            $row[$key] = (float) $row[$key];
        }
        $row['gross_margin_percent'] = $row['gross_margin_percent'] === null
            ? null
            : (float) $row['gross_margin_percent'];
        $row['channel_name'] = self::CHANNELS[$row['channel_key']]
            ?? $row['channel_key'];
        $row['status_name'] = self::STATUSES[$row['status']]
            ?? $row['status'];
        return $row;
    }

    private function fingerprint(array $record): string
    {
        return hash('sha256', implode('|', [
            $record['occurred_at'],
            $record['channel_key'],
            mb_strtolower((string) ($record['external_id'] ?? '')),
            mb_strtolower((string) ($record['customer_name'] ?? '')),
            $record['status'],
            number_format((float) $record['contract_amount'], 2, '.', ''),
            number_format((float) $record['paid_amount'], 2, '.', ''),
        ]));
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'm/d/Y'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date instanceof \DateTimeImmutable && $date->format($format) === $value) {
                return $date->format('Y-m-d');
            }
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial > 1 && $serial < 100000) {
                return (new \DateTimeImmutable('1899-12-30'))
                    ->modify('+' . (int) floor($serial) . ' days')
                    ->format('Y-m-d');
            }
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function decimal(mixed $value): float
    {
        $normalized = str_replace([' ', ' ', ','], ['', '', '.'], trim((string) $value));
        return is_numeric($normalized) ? max(0, (float) $normalized) : 0.0;
    }

    private function nullablePercent(mixed $value): ?float
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        return min(100, max(0, $this->decimal($value)));
    }

    private function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        return in_array(
            mb_strtolower(trim((string) $value)),
            ['1', 'true', 'yes', 'y', 'да', 'д', 'целевой', 'квалифицирован', 'договор'],
            true
        );
    }

    private function text(mixed $value, int $limit): string
    {
        return mb_substr(trim(strip_tags((string) $value)), 0, $limit);
    }

    private function nullableText(mixed $value, int $limit): ?string
    {
        $value = $this->text($value, $limit);
        return $value === '' ? null : $value;
    }
}
