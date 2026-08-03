<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;

final class Bitrix24SafetyPolicy
{
    public static function assertAllowed(string $method, array $params = []): void
    {
        $method = strtolower(trim($method));
        if ($method === '') {
            throw new RuntimeException('Не указан метод Bitrix24.');
        }

        $destructive = preg_match(
            '/(?:^|\.)(?:delete|remove|destroy|erase|purge|unlink)(?:$|\.)/i',
            $method
        ) === 1
            || str_contains($method, 'recyclebin')
            || str_contains($method, 'trash');

        if ($destructive) {
            throw new RuntimeException(
                'Удаляющие операции Bitrix24 запрещены политикой портала: '
                . $method
            );
        }

        if ($method === 'batch') {
            $commands = $params['cmd'] ?? $params['commands'] ?? [];
            if (is_array($commands)) {
                foreach ($commands as $command) {
                    $command = strtolower((string) $command);
                    if (preg_match(
                        '/(?:^|\.)(?:delete|remove|destroy|erase|purge|unlink)(?:\?|$|\.)/i',
                        $command
                    ) === 1
                        || str_contains($command, 'recyclebin')
                        || str_contains($command, 'trash')) {
                        throw new RuntimeException(
                            'Пакет содержит запрещённую удаляющую операцию Bitrix24.'
                        );
                    }
                }
            }
        }
    }
}
