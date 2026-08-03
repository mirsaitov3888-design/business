<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use Closure;
use PDO;
use RuntimeException;
use SeoAnalytics\Core\Database;

final class SiteOnboardingService
{
    private PDO $pdo;
    private ?Bitrix24Client $bitrix;
    private ?Closure $bitrixCaller;

    public function __construct(
        private readonly ClientStructureService $clients = new ClientStructureService(),
        private readonly PortalAccessService $access = new PortalAccessService(),
        ?Bitrix24Client $bitrix = null,
        ?Closure $bitrixCaller = null
    ) {
        $this->pdo = Database::pdo();
        $this->bitrixCaller = $bitrixCaller;
        $this->bitrix = $bitrixCaller instanceof Closure
            ? $bitrix
            : ($bitrix ?? new Bitrix24Client());
    }

    public function context(int $clientId, int $projectId, int $siteId = 0): array
    {
        $user = $this->access->requireRoles([
            'administrator',
            'moderator',
            'manager',
        ]);
        $detail = $this->clients->client($clientId);
        $project = null;
        foreach (($detail['projects'] ?? []) as $row) {
            if ((int) ($row['id'] ?? 0) === $projectId) {
                $project = $row;
                break;
            }
        }
        if (!is_array($project)) {
            throw new RuntimeException('Проект не относится к выбранному клиенту.');
        }

        $site = null;
        if ($siteId > 0) {
            foreach (($project['sites'] ?? []) as $row) {
                if ((int) ($row['id'] ?? 0) === $siteId) {
                    $site = $row;
                    break;
                }
            }
            if (!is_array($site)) {
                throw new RuntimeException('Сайт не относится к выбранному проекту.');
            }
        }

        $client = is_array($detail['client'] ?? null)
            ? $detail['client']
            : [];
        $companyId = (int) ($client['bitrix_company_id'] ?? 0);
        $bitrix = [
            'company_id' => $companyId,
            'company_name' => (string) (
                $client['bitrix_company_name']
                ?? $client['name']
                ?? ''
            ),
            'websites' => [],
            'available' => $companyId > 0,
            'warning' => null,
        ];

        if ($companyId > 0) {
            try {
                $company = $this->bitrixCompany($companyId);
                $bitrix['websites'] = $this->websiteList($company['WEB'] ?? []);
            } catch (\Throwable $exception) {
                $bitrix['warning'] = $exception->getMessage();
            }
        }

        $sources = $siteId > 0
            ? $this->sourceSettings($projectId, $siteId)
            : $this->emptySources();

        return [
            'user' => $user,
            'client' => $client,
            'project' => $project,
            'site' => $site,
            'bitrix' => $bitrix,
            'sources' => $sources,
            'direct_directory' => $this->directDirectory(),
        ];
    }

    public function save(array $data): array
    {
        $this->access->requireRoles([
            'administrator',
            'moderator',
            'manager',
        ]);

        $clientId = max(0, (int) ($data['client_id'] ?? 0));
        $projectId = max(0, (int) ($data['project_id'] ?? 0));
        $siteId = max(0, (int) ($data['site_id'] ?? $data['id'] ?? 0));
        $url = $this->normalizeUrl((string) ($data['url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('Укажите корректный URL сайта.');
        }

        $metrikaIds = $this->integerList($data['metrika_counter_ids'] ?? []);
        $webmasterIds = $this->stringList($data['webmaster_host_ids'] ?? []);
        $directEnabled = $this->bool($data['direct_enabled'] ?? false);
        $directLogin = trim((string) ($data['direct_client_login'] ?? ''));
        $directCampaignIds = $this->integerList($data['direct_campaign_ids'] ?? []);
        if ($directEnabled && $directLogin === '') {
            throw new RuntimeException('Для Директа укажите логин клиентского кабинета.');
        }

        $siteId = $this->clients->saveSite([
            'id' => $siteId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'name' => (string) ($data['name'] ?? ''),
            'url' => $url,
            'status' => (string) ($data['status'] ?? 'active'),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'metrika_counter_ids' => $metrikaIds,
            'webmaster_host_ids' => $webmasterIds,
            'notes' => (string) ($data['notes'] ?? ''),
        ]);

        $this->replaceSources(
            $projectId,
            $siteId,
            $url,
            $metrikaIds,
            $webmasterIds,
            $directEnabled,
            $directLogin,
            $directCampaignIds
        );

        $bitrixResult = $this->syncWebsiteToBitrix(
            $clientId,
            $projectId,
            $siteId,
            $url,
            $this->bool($data['sync_to_bitrix'] ?? true)
        );

        return [
            'site_id' => $siteId,
            'client_id' => $clientId,
            'project_id' => $projectId,
            'bitrix' => $bitrixResult,
            'site' => $this->site($projectId, $siteId),
        ];
    }

    private function replaceSources(
        int $projectId,
        int $siteId,
        string $url,
        array $metrikaIds,
        array $webmasterIds,
        bool $directEnabled,
        string $directLogin,
        array $directCampaignIds
    ): void {
        $this->pdo->prepare(
            'UPDATE project_source_links
             SET status = "archived", updated_at = NOW()
             WHERE project_id = :project_id
               AND site_id = :site_id
               AND source_type IN
                   ("yandex_metrika", "yandex_webmaster", "yandex_direct")'
        )->execute([
            'project_id' => $projectId,
            'site_id' => $siteId,
        ]);

        foreach ($metrikaIds as $counterId) {
            $this->upsertSource(
                $projectId,
                $siteId,
                'yandex_metrika',
                (string) $counterId,
                [
                    'counter_id' => $counterId,
                    'site_url' => $url,
                ]
            );
        }
        foreach ($webmasterIds as $hostId) {
            $this->upsertSource(
                $projectId,
                $siteId,
                'yandex_webmaster',
                $hostId,
                [
                    'host_id' => $hostId,
                    'site_url' => $url,
                ]
            );
        }
        if ($directEnabled) {
            $this->upsertSource(
                $projectId,
                $siteId,
                'yandex_direct',
                $directLogin,
                [
                    'client_login' => $directLogin,
                    'campaign_ids' => $directCampaignIds,
                    'site_url' => $url,
                ]
            );
        }
    }

    private function syncWebsiteToBitrix(
        int $clientId,
        int $projectId,
        int $siteId,
        string $url,
        bool $enabled
    ): array {
        $client = $this->clientRow($clientId);
        $companyId = (int) ($client['bitrix_company_id'] ?? 0);
        if (!$enabled || $companyId <= 0) {
            $status = $companyId > 0 ? 'skipped' : 'not_linked';
            $this->saveBitrixWebsiteSource(
                $projectId,
                $siteId,
                $companyId,
                $url,
                $status,
                null
            );
            return [
                'status' => $status,
                'company_id' => $companyId,
                'added' => false,
                'warning' => null,
            ];
        }

        try {
            $company = $this->bitrixCompany($companyId);
            $websites = $this->websiteList($company['WEB'] ?? []);
            if ($this->containsUrl($websites, $url)) {
                $this->saveBitrixWebsiteSource(
                    $projectId,
                    $siteId,
                    $companyId,
                    $url,
                    'synced',
                    null
                );
                return [
                    'status' => 'synced',
                    'company_id' => $companyId,
                    'added' => false,
                    'warning' => null,
                ];
            }

            $rawWeb = is_array($company['WEB'] ?? null)
                ? array_values($company['WEB'])
                : [];
            $rawWeb[] = [
                'VALUE' => $url,
                'VALUE_TYPE' => 'WORK',
            ];
            $response = $this->bitrixCall('crm.company.update', [
                'id' => $companyId,
                'fields' => ['WEB' => $rawWeb],
            ]);
            $success = ($response['result'] ?? false) !== false;
            if (!$success) {
                throw new RuntimeException('Bitrix24 не подтвердил обновление сайтов компании.');
            }

            $this->saveBitrixWebsiteSource(
                $projectId,
                $siteId,
                $companyId,
                $url,
                'synced',
                null
            );
            return [
                'status' => 'synced',
                'company_id' => $companyId,
                'added' => true,
                'warning' => null,
            ];
        } catch (\Throwable $exception) {
            $this->saveBitrixWebsiteSource(
                $projectId,
                $siteId,
                $companyId,
                $url,
                'error',
                $exception->getMessage()
            );
            return [
                'status' => 'error',
                'company_id' => $companyId,
                'added' => false,
                'warning' => $exception->getMessage(),
            ];
        }
    }

    private function sourceSettings(int $projectId, int $siteId): array
    {
        $result = $this->emptySources();
        $stmt = $this->pdo->prepare(
            'SELECT source_type, external_id, settings_json, status, updated_at
             FROM project_source_links
             WHERE project_id = :project_id
               AND site_id = :site_id
               AND status <> "archived"
             ORDER BY source_type ASC, id ASC'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'site_id' => $siteId,
        ]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings = json_decode((string) ($row['settings_json'] ?? ''), true);
            $settings = is_array($settings) ? $settings : [];
            $type = (string) $row['source_type'];
            if ($type === 'yandex_metrika') {
                $result['metrika']['counter_ids'][] = (int) $row['external_id'];
                $result['metrika']['status'] = (string) $row['status'];
                $result['metrika']['updated_at'] = $row['updated_at'];
            } elseif ($type === 'yandex_webmaster') {
                $result['webmaster']['host_ids'][] = (string) $row['external_id'];
                $result['webmaster']['status'] = (string) $row['status'];
                $result['webmaster']['updated_at'] = $row['updated_at'];
            } elseif ($type === 'yandex_direct') {
                $result['direct'] = [
                    'enabled' => true,
                    'client_login' => (string) $row['external_id'],
                    'campaign_ids' => $this->integerList($settings['campaign_ids'] ?? []),
                    'status' => (string) $row['status'],
                    'updated_at' => $row['updated_at'],
                ];
            } elseif ($type === 'bitrix24_company_web') {
                $result['bitrix'] = $settings + [
                    'status' => (string) $row['status'],
                    'updated_at' => $row['updated_at'],
                ];
            }
        }
        $result['metrika']['counter_ids'] = array_values(array_unique(array_filter(
            array_map('intval', $result['metrika']['counter_ids'])
        )));
        $result['webmaster']['host_ids'] = array_values(array_unique(array_filter(
            array_map('strval', $result['webmaster']['host_ids'])
        )));
        return $result;
    }

    private function directDirectory(): array
    {
        if (!class_exists(YandexDirectClient::class)) {
            return [
                'configured' => false,
                'clients' => [],
                'warning' => 'Сервис Яндекс Директа не установлен.',
            ];
        }
        try {
            $client = new YandexDirectClient();
            if (!$client->configured()) {
                return [
                    'configured' => false,
                    'clients' => [],
                    'warning' => 'Токен Яндекс Директа не настроен.',
                ];
            }
            $rows = $client->clientsGet();
            $clients = [];
            foreach (($rows['Clients'] ?? $rows['clients'] ?? []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $login = trim((string) ($row['Login'] ?? $row['login'] ?? ''));
                if ($login === '') {
                    continue;
                }
                $clients[] = [
                    'login' => $login,
                    'name' => trim((string) (
                        $row['ClientInfo']
                        ?? $row['clientInfo']
                        ?? $login
                    )),
                ];
            }
            return [
                'configured' => true,
                'clients' => $clients,
                'warning' => null,
            ];
        } catch (\Throwable $exception) {
            return [
                'configured' => false,
                'clients' => [],
                'warning' => $exception->getMessage(),
            ];
        }
    }

    private function bitrixCompany(int $companyId): array
    {
        $response = $this->bitrixCall('crm.company.get', ['id' => $companyId]);
        $company = $response['result'] ?? null;
        if (!is_array($company)) {
            throw new RuntimeException('Компания Bitrix24 не найдена.');
        }
        return $company;
    }

    private function bitrixCall(string $method, array $params): array
    {
        if ($this->bitrixCaller instanceof Closure) {
            $response = ($this->bitrixCaller)($method, $params);
            if (!is_array($response)) {
                throw new RuntimeException('Тестовый шлюз Bitrix24 вернул неверный ответ.');
            }
            return $response;
        }
        if (!$this->bitrix instanceof Bitrix24Client) {
            throw new RuntimeException('Интеграция Bitrix24 не настроена.');
        }
        return $this->bitrix->call($method, $params);
    }

    private function upsertSource(
        int $projectId,
        int $siteId,
        string $sourceType,
        string $externalId,
        array $settings,
        string $status = 'active'
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO project_source_links
             (project_id, site_id, source_type, external_id,
              settings_json, status, created_at, updated_at)
             VALUES
             (:project_id, :site_id, :source_type, :external_id,
              :settings_json, :status, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                settings_json = VALUES(settings_json),
                status = VALUES(status),
                updated_at = NOW()'
        );
        $stmt->execute([
            'project_id' => $projectId,
            'site_id' => $siteId,
            'source_type' => $sourceType,
            'external_id' => mb_substr($externalId, 0, 190),
            'settings_json' => json_encode(
                $settings,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ),
            'status' => $status,
        ]);
    }

    private function saveBitrixWebsiteSource(
        int $projectId,
        int $siteId,
        int $companyId,
        string $url,
        string $syncStatus,
        ?string $error
    ): void {
        if ($companyId <= 0) {
            return;
        }
        $this->upsertSource(
            $projectId,
            $siteId,
            'bitrix24_company_web',
            (string) $companyId,
            [
                'company_id' => $companyId,
                'url' => $url,
                'sync_status' => $syncStatus,
                'error' => $error,
                'synced_at' => $syncStatus === 'synced'
                    ? date(DATE_ATOM)
                    : null,
            ],
            $syncStatus === 'error' ? 'paused' : 'active'
        );
    }

    private function clientRow(int $clientId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM clients WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $clientId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new RuntimeException('Клиент не найден.');
        }
        return $row;
    }

    private function site(int $projectId, int $siteId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM project_sites
             WHERE id = :site_id AND project_id = :project_id LIMIT 1'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'project_id' => $projectId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : [];
    }

    private function websiteList(mixed $value): array
    {
        if (is_string($value) || is_numeric($value)) {
            $value = [['VALUE' => (string) $value]];
        }
        if (!is_array($value)) {
            return [];
        }
        $result = [];
        foreach ($value as $item) {
            $url = is_array($item)
                ? trim((string) ($item['VALUE'] ?? $item['value'] ?? ''))
                : trim((string) $item);
            $url = $this->normalizeUrl($url);
            if ($url === '') {
                continue;
            }
            $result[] = $url;
        }
        return array_values(array_unique($result));
    }

    private function containsUrl(array $urls, string $candidate): bool
    {
        $candidate = strtolower(rtrim($this->normalizeUrl($candidate), '/'));
        foreach ($urls as $url) {
            if (strtolower(rtrim($this->normalizeUrl((string) $url), '/')) === $candidate) {
                return true;
            }
        }
        return false;
    }

    private function normalizeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('~^https?://~i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'https'));
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = isset($parts['path']) ? '/' . ltrim((string) $parts['path'], '/') : '';
        $path = $path === '/' ? '' : rtrim($path, '/');
        return $scheme . '://' . $host . $port . $path;
    }

    private function integerList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\s,;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            'intval',
            $value
        ), static fn(int $id): bool => $id > 0)));
    }

    private function stringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = preg_split('/[\r\n,;]+/', $value) ?: [];
        }
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map(
            static fn(mixed $item): string => trim((string) $item),
            $value
        ), static fn(string $item): bool => $item !== '')));
    }

    private function bool(mixed $value): bool
    {
        return in_array($value, [true, 1, '1', 'true', 'yes', 'on'], true);
    }

    private function emptySources(): array
    {
        return [
            'metrika' => [
                'counter_ids' => [],
                'status' => 'not_configured',
                'updated_at' => null,
            ],
            'webmaster' => [
                'host_ids' => [],
                'status' => 'not_configured',
                'updated_at' => null,
            ],
            'direct' => [
                'enabled' => false,
                'client_login' => '',
                'campaign_ids' => [],
                'status' => 'not_configured',
                'updated_at' => null,
            ],
            'bitrix' => [
                'status' => 'not_configured',
                'updated_at' => null,
            ],
        ];
    }
}
