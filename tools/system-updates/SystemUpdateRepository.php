<?php
declare(strict_types=1);

namespace SeoAnalytics\Repositories;

use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class SystemUpdateRepository
{
    public function history(int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $stmt = Database::pdo()->query(
            'SELECT * FROM system_updates ORDER BY id DESC LIMIT ' . $limit
        );
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as &$row) {
            $row['id'] = (int) $row['id'];
            $row['created_by'] = $row['created_by'] === null
                ? null
                : (int) $row['created_by'];
            $row['manifest'] = json_decode(
                (string) ($row['manifest_json'] ?? ''),
                true
            ) ?: [];
            unset($row['manifest_json']);
        }
        unset($row);

        return $rows;
    }

    public function createInstall(
        array $release,
        ?int $userId
    ): int {
        if ($this->hasActiveJob()) {
            throw new RuntimeException(
                'Уже есть обновление в очереди или в процессе установки.'
            );
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO system_updates
             (
                version,
                title,
                status,
                action_type,
                installer_url,
                sha256,
                manifest_json,
                created_by,
                created_at
             ) VALUES
             (
                :version,
                :title,
                "queued",
                "install",
                :installer_url,
                :sha256,
                :manifest_json,
                :created_by,
                NOW()
             )'
        );
        $stmt->execute([
            'version' => (string) $release['version'],
            'title' => (string) $release['title'],
            'installer_url' => (string) $release['installer_url'],
            'sha256' => (string) $release['sha256'],
            'manifest_json' => json_encode(
                $release,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'created_by' => $userId,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function createRollback(
        int $sourceUpdateId,
        ?int $userId
    ): int {
        if ($this->hasActiveJob()) {
            throw new RuntimeException(
                'Уже есть обновление в очереди или в процессе установки.'
            );
        }

        $source = $this->find($sourceUpdateId);

        if (!$source || $source['status'] !== 'installed') {
            throw new RuntimeException(
                'Для отката выберите успешно установленное обновление.'
            );
        }

        $backupPath = (string) ($source['backup_path'] ?? '');

        if ($backupPath === '' || !is_file($backupPath)) {
            throw new RuntimeException(
                'Резервная копия этого обновления не найдена.'
            );
        }

        $stmt = Database::pdo()->prepare(
            'INSERT INTO system_updates
             (
                version,
                title,
                status,
                action_type,
                backup_path,
                manifest_json,
                created_by,
                created_at
             ) VALUES
             (
                :version,
                :title,
                "rollback_queued",
                "rollback",
                :backup_path,
                :manifest_json,
                :created_by,
                NOW()
             )'
        );
        $stmt->execute([
            'version' => (string) $source['version'],
            'title' => 'Откат обновления ' . (string) $source['version'],
            'backup_path' => $backupPath,
            'manifest_json' => json_encode(
                ['source_update_id' => $sourceUpdateId],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'created_by' => $userId,
        ]);

        return (int) Database::pdo()->lastInsertId();
    }

    public function nextJob(): ?array
    {
        $stmt = Database::pdo()->query(
            'SELECT *
             FROM system_updates
             WHERE status IN ("queued", "rollback_queued")
             ORDER BY id ASC
             LIMIT 1'
        );
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare(
            'SELECT * FROM system_updates WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function markStarted(int $id, string $status): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE system_updates
             SET status = :status, started_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['status' => $status, 'id' => $id]);
    }

    public function markFinished(
        int $id,
        string $status,
        string $log,
        ?string $backupPath = null
    ): void {
        $stmt = Database::pdo()->prepare(
            'UPDATE system_updates
             SET status = :status,
                 log_text = :log_text,
                 backup_path = COALESCE(:backup_path, backup_path),
                 finished_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'log_text' => mb_substr($log, 0, 500000),
            'backup_path' => $backupPath,
            'id' => $id,
        ]);
    }

    public function appendLog(int $id, string $text): void
    {
        $stmt = Database::pdo()->prepare(
            'UPDATE system_updates
             SET log_text = CONCAT(COALESCE(log_text, ""), :text)
             WHERE id = :id'
        );
        $stmt->execute([
            'text' => mb_substr($text, 0, 100000),
            'id' => $id,
        ]);
    }

    private function hasActiveJob(): bool
    {
        $stmt = Database::pdo()->query(
            'SELECT COUNT(*)
             FROM system_updates
             WHERE status IN (
                "queued",
                "installing",
                "rollback_queued",
                "rolling_back"
             )'
        );

        return (int) $stmt->fetchColumn() > 0;
    }
}
