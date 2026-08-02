<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;

final class SystemDiagnosticsBridge
{
    public function __construct(
        private readonly string $projectRoot
    ) {
    }

    public function run(int $updateId, string $version): array
    {
        $dataRoot = dirname(rtrim($this->projectRoot, '/'), 2);
        $agentRoot = $dataRoot . '/mirsaitov-diagnostics';
        $agentPath = $agentRoot . '/agent.php';
        $configPath = $agentRoot . '/config.php';
        $reportsDirectory = $agentRoot . '/reports';

        if (!is_file($agentPath) || !is_file($configPath)) {
            throw new RuntimeException('Единый диагностический центр не установлен.');
        }

        $settings = require $configPath;
        if (!is_array($settings)) {
            throw new RuntimeException('Конфигурация диагностического центра повреждена.');
        }

        require_once $agentPath;
        if (!function_exists('md_report') || !function_exists('md_json')) {
            throw new RuntimeException('Ядро диагностического центра несовместимо.');
        }

        $report = md_report($settings, true);
        if (!is_array($report)) {
            throw new RuntimeException('Диагностический центр не вернул отчёт.');
        }

        $report['context'] = [
            'trigger' => 'system_update',
            'update_id' => $updateId,
            'version' => $version,
        ];
        $this->normalizeExpectedAuthorization($report);

        $counts = $this->counts($report);
        $status = $counts['error'] > 0
            ? 'failed'
            : ($counts['warning'] > 0 ? 'warning' : 'passed');

        if (!is_dir($reportsDirectory)
            && !mkdir($reportsDirectory, 0700, true)
            && !is_dir($reportsDirectory)) {
            throw new RuntimeException('Не удалось создать каталог диагностических отчётов.');
        }

        $safeVersion = preg_replace('/[^0-9A-Za-z._-]+/', '-', $version) ?: 'unknown';
        $reportName = sprintf(
            'update-%d-%s-%s.json',
            $updateId,
            trim($safeVersion, '-'),
            date('Ymd-His')
        );
        $json = md_json($report);

        $this->writeAtomic($reportsDirectory . '/' . $reportName, $json);
        $this->writeAtomic($reportsDirectory . '/latest.json', $json);

        return [
            'status' => $status,
            'report' => $reportName,
            'generated_at' => (string) ($report['generated_at'] ?? date(DATE_ATOM)),
            'counts' => $counts,
            'message' => $this->message($status, $counts),
        ];
    }

    private function normalizeExpectedAuthorization(array &$report): void
    {
        $normalized = [];
        $protectedReachable = 0;

        foreach (($report['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }

            $code = (string) ($finding['code'] ?? '');
            $status = (int) ($finding['http_status'] ?? 0);
            $error = mb_strtolower((string) ($finding['error'] ?? ''));

            if (str_starts_with($code, 'endpoint_')
                && $status === 401
                && (str_contains($error, 'авторизац') || str_contains($error, 'authorization'))) {
                $finding['severity'] = 'info';
                $finding['message'] = 'Защищённый endpoint доступен и корректно требует пользовательскую авторизацию.';
                $finding['expected_authentication'] = true;
                $protectedReachable++;
            }

            $normalized[] = $finding;
        }

        if ($protectedReachable > 0) {
            $normalized[] = [
                'severity' => 'ok',
                'code' => 'protected_endpoints_reachable',
                'message' => 'Защищённые endpoint портала доступны и отклоняют запросы без пользовательской сессии.',
                'count' => $protectedReachable,
            ];
        }

        $report['findings'] = $normalized;
    }

    private function counts(array $report): array
    {
        $counts = ['error' => 0, 'warning' => 0, 'ok' => 0, 'info' => 0];

        foreach (($report['findings'] ?? []) as $finding) {
            if (!is_array($finding)) {
                continue;
            }
            $severity = (string) ($finding['severity'] ?? 'info');
            if (array_key_exists($severity, $counts)) {
                $counts[$severity]++;
            }
        }

        return $counts;
    }

    private function message(string $status, array $counts): string
    {
        return match ($status) {
            'passed' => 'Диагностика завершена без ошибок и предупреждений.',
            'warning' => sprintf(
                'Диагностика завершена: ошибок — %d, предупреждений — %d.',
                $counts['error'],
                $counts['warning']
            ),
            default => sprintf(
                'Диагностика обнаружила ошибки: ошибок — %d, предупреждений — %d.',
                $counts['error'],
                $counts['warning']
            ),
        };
    }

    private function writeAtomic(string $path, string $content): void
    {
        $temporary = $path . '.tmp.' . bin2hex(random_bytes(5));
        if (file_put_contents($temporary, $content, LOCK_EX) === false) {
            throw new RuntimeException('Не удалось записать диагностический отчёт.');
        }
        @chmod($temporary, 0600);

        if (!rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('Не удалось сохранить диагностический отчёт.');
        }
        @chmod($path, 0600);
    }
}
