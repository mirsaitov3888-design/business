<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use RuntimeException;
use SeoAnalytics\Repositories\MonitoringRepository;

final class SiteMonitoringService
{
    public function __construct(
        private readonly MonitoringRepository $repository = new MonitoringRepository(),
        private readonly MonitoringNotifier $notifier = new MonitoringNotifier()
    ) {
    }

    public function overview(int $projectId): array
    {
        $data = $this->repository->dashboard($projectId);
        $data['notification_settings'] = $this->notifier->publicSettings();
        return $data;
    }

    public function detail(int $siteId, int $projectId): array
    {
        return $this->repository->detail($siteId, $projectId);
    }

    public function saveSite(array $input, int $projectId): array
    {
        $normalized = $this->normalizeSiteInput($input, $projectId);
        $isNew = (int) ($normalized['id'] ?? 0) <= 0;
        $id = $this->repository->saveSite($normalized);
        $site = $this->repository->site($id, $projectId);

        if (!$site) {
            throw new RuntimeException('Сайт сохранён, но не найден после сохранения.');
        }

        $initial = null;
        if ($isNew || !empty($input['run_initial_audit'])) {
            $availability = $this->checkAvailabilityBySite($site, true);
            $site = $this->repository->site($id, $projectId) ?? $site;
            $audit = $this->auditSiteBySite($site, 'initial');
            $initial = [
                'availability' => $availability,
                'audit' => $audit,
            ];
        }

        return [
            'site' => $this->repository->site($id, $projectId),
            'initial' => $initial,
        ];
    }

    public function deleteSite(int $siteId, int $projectId): void
    {
        $this->repository->deleteSite($siteId, $projectId);
    }

    public function checkSite(int $siteId, int $projectId): array
    {
        $site = $this->repository->site($siteId, $projectId);
        if (!$site) {
            throw new RuntimeException('Сайт не найден.');
        }
        return $this->checkAvailabilityBySite($site, true);
    }

    public function auditSite(int $siteId, int $projectId): array
    {
        $site = $this->repository->site($siteId, $projectId);
        if (!$site) {
            throw new RuntimeException('Сайт не найден.');
        }
        return $this->auditSiteBySite($site, 'manual');
    }

    public function saveNotificationSettings(array $input): array
    {
        return $this->notifier->saveSettings($input);
    }

    public function runDueChecks(): array
    {
        @set_time_limit(0);
        $started = microtime(true);
        $sites = $this->repository->dueSites();
        $processed = 0;
        $audited = 0;
        $errors = [];

        $this->repository->setWorkerState('heartbeat', [
            'status' => 'running',
            'started_at' => date(DATE_ATOM),
            'sites_due' => count($sites),
        ]);

        foreach ($sites as $site) {
            try {
                $this->checkAvailabilityBySite($site, false);
                $processed++;

                $lastAudit = trim((string) ($site['last_audit_at'] ?? ''));
                $auditDue = $lastAudit === ''
                    || strtotime($lastAudit) === false
                    || strtotime($lastAudit) <= time() - 86400;

                if ($auditDue) {
                    $fresh = $this->repository->site((int) $site['id']) ?? $site;
                    $this->auditSiteBySite($fresh, 'scheduled');
                    $audited++;
                }
            } catch (\Throwable $exception) {
                $errors[] = '#' . $site['id'] . ' ' . $site['host'] . ': ' . $exception->getMessage();
            }
        }

        if (random_int(1, 50) === 1) {
            $this->repository->cleanup();
        }

        $result = [
            'status' => $errors === [] ? 'ok' : 'warning',
            'finished_at' => date(DATE_ATOM),
            'processed' => $processed,
            'audited' => $audited,
            'errors' => array_slice($errors, 0, 20),
            'duration_seconds' => round(microtime(true) - $started, 3),
        ];
        $this->repository->setWorkerState('heartbeat', $result);
        return $result;
    }

    private function checkAvailabilityBySite(array $site, bool $manual): array
    {
        $attempts = [];
        $success = null;

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $result = $this->fetch(
                (string) $site['base_url'],
                'Mozilla/5.0 (compatible; MirsaitovMonitor/1.0; +https://mirsaitov.net)',
                25,
                512 * 1024
            );
            $attempts[] = $result;

            if ($this->isAvailable($result)) {
                $success = $result;
                break;
            }

            if ($attempt < 3) {
                usleep(800000);
            }
        }

        $final = $success ?? end($attempts);
        if (!is_array($final)) {
            throw new RuntimeException('Проверка доступности не вернула результат.');
        }

        $isUp = $success !== null;
        $oldStatus = (string) ($site['last_status'] ?? 'unknown');
        $httpCode = isset($final['http_code']) ? (int) $final['http_code'] : null;
        $responseMs = isset($final['response_ms']) ? (int) $final['response_ms'] : null;
        $error = $isUp ? null : $this->availabilityError($final);
        $failures = $isUp ? 0 : ((int) ($site['consecutive_failures'] ?? 0) + 1);

        $this->repository->addAvailability(
            (int) $site['id'],
            $isUp,
            $httpCode,
            $responseMs,
            count($attempts),
            $final['final_url'] ?? null,
            $error
        );
        $this->repository->updateAvailabilityState(
            (int) $site['id'],
            $isUp ? 'up' : 'down',
            $httpCode,
            $responseMs,
            $failures
        );

        if (!$isUp) {
            $wasOpen = $this->repository->findOpenIncident((int) $site['id'], 'site_down') !== null;
            $incident = $this->repository->openIncident(
                (int) $site['id'],
                'site_down',
                'technical',
                'critical',
                'Сайт недоступен',
                'Три последовательные попытки завершились ошибкой. ' . $error
            );

            if (!$wasOpen && $oldStatus !== 'down') {
                $event = $this->repository->recordEvent(
                    (int) $site['id'],
                    'site_down',
                    'technical',
                    'critical',
                    $oldStatus,
                    ['http_code' => $httpCode, 'error' => $error],
                    'Сайт недоступен после трёх повторных проверок.'
                );
                $this->notifyEvent($event);
            }
        } else {
            $resolved = $this->repository->resolveIncident(
                (int) $site['id'],
                'site_down',
                'Сайт снова отвечает. HTTP ' . (string) $httpCode . ', ' . (string) $responseMs . ' мс.'
            );

            if ($oldStatus === 'down' || $resolved !== null) {
                $event = $this->repository->recordEvent(
                    (int) $site['id'],
                    'site_restored',
                    'technical',
                    'info',
                    'down',
                    ['http_code' => $httpCode, 'response_ms' => $responseMs],
                    'Сайт восстановлен и снова доступен.'
                );
                $this->notifyEvent($event);
            }
        }

        $slow = $isUp
            && $responseMs !== null
            && $responseMs > (int) ($site['slow_threshold_ms'] ?? 3000);
        $this->syncCondition(
            $site,
            'slow_response',
            $slow,
            'technical',
            'warning',
            'Сайт отвечает медленно',
            $slow
                ? 'Время ответа ' . $responseMs . ' мс превышает порог ' . $site['slow_threshold_ms'] . ' мс.'
                : 'Время ответа вернулось в допустимый диапазон.',
            false
        );

        return [
            'is_up' => $isUp,
            'http_code' => $httpCode,
            'response_ms' => $responseMs,
            'attempts' => count($attempts),
            'final_url' => $final['final_url'] ?? null,
            'error' => $error,
            'manual' => $manual,
        ];
    }

    private function auditSiteBySite(array $site, string $runType): array
    {
        @set_time_limit(180);
        $previous = $this->repository->latestAudit((int) $site['id']);
        $home = $this->fetch(
            (string) $site['base_url'],
            'Mozilla/5.0 (compatible; MirsaitovAudit/1.0; +https://mirsaitov.net)',
            35,
            3 * 1024 * 1024
        );
        $html = (string) ($home['body'] ?? '');
        $headers = is_array($home['headers'] ?? null) ? $home['headers'] : [];
        $page = $this->parseHtml($html, (string) ($home['final_url'] ?? $site['base_url']));
        $xRobots = implode(', ', $headers['x-robots-tag'] ?? []);

        $robotsUrl = rtrim((string) $site['base_url'], '/') . '/robots.txt';
        $robots = $this->fetch($robotsUrl, 'MirsaitovMonitor/1.0', 20, 1024 * 1024);
        $robotsBody = $this->isHttpSuccess($robots) ? (string) ($robots['body'] ?? '') : '';
        $robotsData = $this->parseRobots($robotsBody);

        $sitemapUrl = $robotsData['sitemaps'][0]
            ?? (rtrim((string) $site['base_url'], '/') . '/sitemap.xml');
        $sitemap = $this->fetch($sitemapUrl, 'MirsaitovMonitor/1.0', 25, 2 * 1024 * 1024);

        $faviconUrl = $page['favicon_url']
            ?: (rtrim((string) $site['base_url'], '/') . '/favicon.ico');
        $favicon = $this->fetch($faviconUrl, 'MirsaitovMonitor/1.0', 15, 512 * 1024, true);

        $ssl = $this->sslInfo((string) $site['host'], str_starts_with((string) $site['base_url'], 'https://'));
        $dns = $this->dnsInfo((string) $site['host']);
        $domain = $this->domainInfo((string) $site['host']);
        $bot = $this->fetch(
            (string) $site['base_url'],
            'Mozilla/5.0 (compatible; YandexBot/3.0; +http://yandex.com/bots)',
            25,
            256 * 1024,
            true
        );

        $indexingReasons = [];
        $metaRobots = mb_strtolower((string) ($page['meta_robots'] ?? ''));
        $xRobotsLower = mb_strtolower($xRobots);
        if (str_contains($metaRobots, 'noindex')) {
            $indexingReasons[] = 'На главной странице найден meta robots со значением noindex.';
        }
        if (str_contains($xRobotsLower, 'noindex')) {
            $indexingReasons[] = 'В HTTP-заголовке X-Robots-Tag найдено значение noindex.';
        }
        if (!empty($robotsData['disallow_all'])) {
            $indexingReasons[] = 'В robots.txt для общего робота указан Disallow: /.';
        }
        if (!$this->isHttpSuccess($home)) {
            $indexingReasons[] = 'Главная страница возвращает HTTP ' . (string) ($home['http_code'] ?? 0) . '.';
        }

        $metrika = $this->detectMetrika($html);
        $expectedMetrika = is_array($site['expected_metrika_ids_array'] ?? null)
            ? $site['expected_metrika_ids_array']
            : [];
        $missingExpected = array_values(array_diff($expectedMetrika, $metrika['ids']));
        $botMismatch = $this->isHttpSuccess($home)
            && !$this->isHttpSuccess($bot);

        $audit = [
            'http_code' => (int) ($home['http_code'] ?? 0),
            'final_url' => $home['final_url'] ?? $site['base_url'],
            'title' => $page['title'],
            'description' => $page['description'],
            'h1' => $page['h1'],
            'h1_count' => $page['h1_count'],
            'canonical' => $page['canonical'],
            'meta_robots' => $page['meta_robots'],
            'x_robots_tag' => $xRobots,
            'indexing_allowed' => $indexingReasons === [],
            'indexing_reason' => $indexingReasons === []
                ? 'Запретов индексации главной страницы не обнаружено.'
                : implode(' ', $indexingReasons),
            'robots_status' => (int) ($robots['http_code'] ?? 0),
            'robots_hash' => $robotsBody === '' ? null : hash('sha256', $robotsBody),
            'robots_summary' => $robotsData['summary'],
            'sitemap_url' => $sitemapUrl,
            'sitemap_status' => (int) ($sitemap['http_code'] ?? 0),
            'sitemap_hash' => $this->isHttpSuccess($sitemap)
                ? hash('sha256', (string) ($sitemap['body'] ?? ''))
                : null,
            'favicon_url' => $faviconUrl,
            'favicon_status' => (int) ($favicon['http_code'] ?? 0),
            'metrika_ids' => $metrika['ids'],
            'webvisor_enabled' => $metrika['webvisor'],
            'ssl_valid' => $ssl['valid'],
            'ssl_expires_at' => $ssl['expires_at'],
            'ssl_days_left' => $ssl['days_left'],
            'dns' => $dns['records'],
            'dns_hash' => $dns['hash'],
            'domain_name' => $domain['domain'],
            'domain_registered_at' => $domain['registered_at'],
            'domain_expires_at' => $domain['expires_at'],
            'domain_days_left' => $domain['days_left'],
            'domain_status' => $domain['status'],
            'summary' => [],
        ];

        $audit['summary'] = $this->buildAuditSummary(
            $site,
            $audit,
            $home,
            $bot,
            $missingExpected,
            $botMismatch
        );

        $auditId = $this->repository->saveAudit((int) $site['id'], $audit, $runType);
        $audit['id'] = $auditId;
        $audit['created_at'] = date('Y-m-d H:i:s');

        $this->processAuditConditions(
            $site,
            $audit,
            $missingExpected,
            $botMismatch,
            $home,
            $robots,
            $sitemap,
            $favicon
        );
        $this->processAuditChanges($site, $previous, $audit);

        if ($previous === null) {
            $event = $this->repository->recordEvent(
                (int) $site['id'],
                'initial_audit',
                'technical',
                'info',
                null,
                $audit['summary'],
                'Первичный аудит сайта завершён.'
            );
            $this->notifyEvent($event, false);
        }

        return $audit;
    }

    private function processAuditConditions(
        array $site,
        array $audit,
        array $missingExpected,
        bool $botMismatch,
        array $home,
        array $robots,
        array $sitemap,
        array $favicon
    ): void {
        $this->syncCondition(
            $site,
            'indexing_blocked',
            empty($audit['indexing_allowed']),
            'marketing',
            'critical',
            'Сайт закрыт от индексации',
            (string) $audit['indexing_reason']
        );
        $this->syncCondition(
            $site,
            'robots_unavailable',
            !$this->isHttpSuccess($robots),
            'technical',
            'warning',
            'robots.txt недоступен',
            !$this->isHttpSuccess($robots)
                ? 'Файл robots.txt возвращает HTTP ' . (string) ($robots['http_code'] ?? 0) . '.'
                : 'Файл robots.txt снова доступен.'
        );
        $this->syncCondition(
            $site,
            'sitemap_unavailable',
            !$this->isHttpSuccess($sitemap),
            'technical',
            'warning',
            'Sitemap недоступен',
            !$this->isHttpSuccess($sitemap)
                ? 'Файл Sitemap возвращает HTTP ' . (string) ($sitemap['http_code'] ?? 0) . '.'
                : 'Файл Sitemap снова доступен.'
        );
        $this->syncCondition(
            $site,
            'favicon_missing',
            !$this->isHttpSuccess($favicon),
            'marketing',
            'warning',
            'Favicon не найден',
            !$this->isHttpSuccess($favicon)
                ? 'Указанный favicon не загрузился. HTTP ' . (string) ($favicon['http_code'] ?? 0) . '.'
                : 'Favicon снова доступен.'
        );
        $this->syncCondition(
            $site,
            'metrika_missing',
            ($audit['metrika_ids'] ?? []) === [] || $missingExpected !== [],
            'marketing',
            'critical',
            'Код Яндекс Метрики не обнаружен',
            $missingExpected !== []
                ? 'В загруженном HTML не обнаружены ожидаемые счётчики: ' . implode(', ', $missingExpected) . '.'
                : (($audit['metrika_ids'] ?? []) === []
                    ? 'Код Метрики не обнаружен в загруженном HTML. Он может устанавливаться динамически через менеджер тегов.'
                    : 'Ожидаемые счётчики снова обнаружены.')
        );
        $this->syncCondition(
            $site,
            'webvisor_disabled',
            ($audit['metrika_ids'] ?? []) !== [] && $audit['webvisor_enabled'] === false,
            'marketing',
            'warning',
            'Вебвизор отключён',
            ($audit['metrika_ids'] ?? []) !== [] && $audit['webvisor_enabled'] === false
                ? 'В конфигурации счётчика найдено webvisor: false или не найдено включение Вебвизора.'
                : 'Вебвизор снова включён либо состояние не удалось определить.'
        );
        $this->syncCondition(
            $site,
            'ssl_invalid',
            str_starts_with((string) $site['base_url'], 'https://') && $audit['ssl_valid'] !== true,
            'technical',
            'critical',
            'Ошибка SSL-сертификата',
            $audit['ssl_valid'] === true
                ? 'SSL-сертификат снова корректен.'
                : 'Не удалось подтвердить корректность SSL-сертификата.'
        );
        $sslDays = $audit['ssl_days_left'];
        $this->syncCondition(
            $site,
            'ssl_expiring',
            is_int($sslDays) && $sslDays <= 30,
            'technical',
            is_int($sslDays) && $sslDays <= 14 ? 'critical' : 'warning',
            'Скоро закончится SSL-сертификат',
            is_int($sslDays) && $sslDays <= 30
                ? 'До окончания SSL-сертификата осталось дней: ' . $sslDays . '.'
                : 'Срок действия SSL-сертификата находится в допустимом диапазоне.'
        );
        $domainDays = $audit['domain_days_left'];
        $this->syncCondition(
            $site,
            'domain_expiring',
            is_int($domainDays) && $domainDays <= 60,
            'technical',
            is_int($domainDays) && $domainDays <= 30 ? 'critical' : 'warning',
            'Скоро закончится регистрация домена',
            is_int($domainDays) && $domainDays <= 60
                ? 'До окончания регистрации домена осталось дней: ' . $domainDays . '.'
                : 'Срок регистрации домена находится в допустимом диапазоне.'
        );
        $this->syncCondition(
            $site,
            'bot_http_mismatch',
            $botMismatch,
            'technical',
            'warning',
            'Поисковый робот получает другой HTTP-ответ',
            $botMismatch
                ? 'Обычный запрос: HTTP ' . (string) ($home['http_code'] ?? 0)
                    . '; YandexBot: HTTP ' . (string) ($audit['summary']['bot_http_code'] ?? 0) . '.'
                : 'Ответ для поискового робота совпадает с обычным ответом.'
        );
    }

    private function processAuditChanges(array $site, ?array $previous, array $current): void
    {
        if ($previous === null) {
            return;
        }

        $changes = [
            ['title', 'marketing', 'warning', 'Изменился Title главной страницы'],
            ['description', 'marketing', 'warning', 'Изменился Description главной страницы'],
            ['h1', 'marketing', 'warning', 'Изменился H1 главной страницы'],
            ['canonical', 'marketing', 'warning', 'Изменился canonical главной страницы'],
            ['meta_robots', 'marketing', 'critical', 'Изменился meta robots'],
            ['x_robots_tag', 'marketing', 'critical', 'Изменился X-Robots-Tag'],
            ['robots_hash', 'marketing', 'warning', 'Изменился robots.txt'],
            ['sitemap_hash', 'marketing', 'warning', 'Изменился Sitemap'],
            ['favicon_url', 'marketing', 'info', 'Изменился адрес favicon'],
            ['dns_hash', 'technical', 'warning', 'Изменились DNS-записи'],
            ['domain_expires_at', 'technical', 'warning', 'Изменилась дата окончания регистрации домена'],
            ['ssl_expires_at', 'technical', 'warning', 'Изменилась дата окончания SSL-сертификата'],
        ];

        foreach ($changes as [$field, $category, $severity, $message]) {
            $old = $previous[$field] ?? null;
            $new = $current[$field] ?? null;
            if ($this->comparable($old) === $this->comparable($new)) {
                continue;
            }
            $event = $this->repository->recordEvent(
                (int) $site['id'],
                'changed_' . $field,
                $category,
                $severity,
                $old,
                $new,
                $message . '.'
            );
            $this->notifyEvent($event);
        }

        $oldMetrika = $previous['metrika_ids'] ?? [];
        $newMetrika = $current['metrika_ids'] ?? [];
        sort($oldMetrika, SORT_NATURAL);
        sort($newMetrika, SORT_NATURAL);
        if ($oldMetrika !== $newMetrika) {
            $event = $this->repository->recordEvent(
                (int) $site['id'],
                'changed_metrika_ids',
                'marketing',
                'critical',
                $oldMetrika,
                $newMetrika,
                'Изменился набор счётчиков Яндекс Метрики.'
            );
            $this->notifyEvent($event);
        }
    }

    private function syncCondition(
        array $site,
        string $type,
        bool $active,
        string $category,
        string $severity,
        string $title,
        string $details,
        bool $notify = true
    ): void {
        $open = $this->repository->findOpenIncident((int) $site['id'], $type);

        if ($active) {
            $this->repository->openIncident(
                (int) $site['id'],
                $type,
                $category,
                $severity,
                $title,
                $details
            );

            if ($open === null) {
                $event = $this->repository->recordEvent(
                    (int) $site['id'],
                    $type,
                    $category,
                    $severity,
                    null,
                    $details,
                    $title . ': ' . $details
                );
                $this->notifyEvent($event, $notify);
            }
            return;
        }

        if ($open !== null) {
            $this->repository->resolveIncident((int) $site['id'], $type, $details);
            $event = $this->repository->recordEvent(
                (int) $site['id'],
                $type . '_resolved',
                $category,
                'info',
                $open['details'] ?? null,
                $details,
                'Проблема устранена: ' . $title . '.'
            );
            $this->notifyEvent($event, $notify);
        }
    }

    private function notifyEvent(array $event, bool $external = true): void
    {
        if ($event === []) {
            return;
        }
        $full = $this->repository->event((int) $event['id']);
        if (!$full) {
            return;
        }
        if ($external) {
            $this->notifier->notify($full);
        } else {
            $this->repository->markEventNotified((int) $full['id']);
            $this->repository->addNotificationLog(
                (int) $full['id'],
                'internal',
                null,
                'stored',
                null
            );
        }
    }

    private function buildAuditSummary(
        array $site,
        array $audit,
        array $home,
        array $bot,
        array $missingExpected,
        bool $botMismatch
    ): array {
        $summary = [
            'critical' => [],
            'warning' => [],
            'recommendation' => [],
            'ok' => [],
            'bot_http_code' => (int) ($bot['http_code'] ?? 0),
        ];

        if (!$this->isHttpSuccess($home)) {
            $summary['critical'][] = 'Главная страница возвращает HTTP ' . (string) ($home['http_code'] ?? 0) . '.';
        } else {
            $summary['ok'][] = 'Главная страница доступна.';
        }
        if (empty($audit['indexing_allowed'])) {
            $summary['critical'][] = (string) $audit['indexing_reason'];
        } else {
            $summary['ok'][] = 'Запретов индексации не обнаружено.';
        }
        if (trim((string) $audit['title']) === '') {
            $summary['warning'][] = 'На главной странице отсутствует Title.';
        } else {
            $summary['ok'][] = 'Title найден.';
        }
        if (trim((string) $audit['description']) === '') {
            $summary['warning'][] = 'На главной странице отсутствует Description.';
        } else {
            $summary['ok'][] = 'Description найден.';
        }
        if ((int) $audit['h1_count'] === 0) {
            $summary['warning'][] = 'На главной странице отсутствует H1.';
        } elseif ((int) $audit['h1_count'] > 1) {
            $summary['warning'][] = 'На главной странице найдено несколько H1: ' . $audit['h1_count'] . '.';
        } else {
            $summary['ok'][] = 'На странице найден один H1.';
        }
        if (trim((string) $audit['canonical']) === '') {
            $summary['recommendation'][] = 'Не найден canonical главной страницы.';
        } else {
            $summary['ok'][] = 'Canonical найден.';
        }
        if (($audit['metrika_ids'] ?? []) === []) {
            $summary['warning'][] = 'Код Метрики не обнаружен в загруженном HTML. Он может устанавливаться динамически.';
        } else {
            $summary['ok'][] = 'Обнаружены счётчики Метрики: ' . implode(', ', $audit['metrika_ids']) . '.';
        }
        if ($missingExpected !== []) {
            $summary['critical'][] = 'Не обнаружены ожидаемые счётчики Метрики: ' . implode(', ', $missingExpected) . '.';
        }
        if ($audit['webvisor_enabled'] === false && ($audit['metrika_ids'] ?? []) !== []) {
            $summary['warning'][] = 'Вебвизор не обнаружен как включённый.';
        }
        if ($audit['ssl_valid'] === false) {
            $summary['critical'][] = 'SSL-сертификат не прошёл проверку.';
        } elseif (is_int($audit['ssl_days_left']) && $audit['ssl_days_left'] <= 30) {
            $summary['warning'][] = 'До окончания SSL осталось дней: ' . $audit['ssl_days_left'] . '.';
        } elseif ($audit['ssl_valid'] === true) {
            $summary['ok'][] = 'SSL-сертификат корректен.';
        }
        if (!$this->httpCodeOk((int) $audit['robots_status'])) {
            $summary['warning'][] = 'robots.txt недоступен.';
        } else {
            $summary['ok'][] = 'robots.txt доступен.';
        }
        if (!$this->httpCodeOk((int) $audit['sitemap_status'])) {
            $summary['warning'][] = 'Sitemap недоступен.';
        } else {
            $summary['ok'][] = 'Sitemap доступен.';
        }
        if (!$this->httpCodeOk((int) $audit['favicon_status'])) {
            $summary['recommendation'][] = 'Favicon не обнаружен или недоступен.';
        } else {
            $summary['ok'][] = 'Favicon доступен.';
        }
        if ($audit['domain_status'] === 'unavailable') {
            $summary['recommendation'][] = 'Данные о сроке регистрации домена недоступны.';
        } elseif (is_int($audit['domain_days_left']) && $audit['domain_days_left'] <= 60) {
            $summary['warning'][] = 'До окончания регистрации домена осталось дней: ' . $audit['domain_days_left'] . '.';
        }
        if ($botMismatch) {
            $summary['warning'][] = 'YandexBot получает другой HTTP-ответ.';
        } else {
            $summary['ok'][] = 'Ответ для YandexBot не отличается от обычного запроса.';
        }

        return $summary;
    }

    private function parseHtml(string $html, string $baseUrl): array
    {
        $result = [
            'title' => '',
            'description' => '',
            'h1' => '',
            'h1_count' => 0,
            'canonical' => '',
            'meta_robots' => '',
            'favicon_url' => '',
        ];
        if ($html === '') {
            return $result;
        }

        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = @$dom->loadHTML(
            '<?xml encoding="UTF-8">' . $html,
            LIBXML_NOWARNING | LIBXML_NOERROR | LIBXML_NONET
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $result;
        }

        $xpath = new DOMXPath($dom);
        $result['title'] = $this->nodeText($xpath->query('//title')->item(0));
        $result['description'] = $this->metaContent($xpath, 'description');
        $result['meta_robots'] = $this->metaContent($xpath, 'robots');
        $h1Nodes = $xpath->query('//h1');
        $result['h1_count'] = $h1Nodes ? $h1Nodes->length : 0;
        $result['h1'] = $h1Nodes && $h1Nodes->length > 0
            ? $this->nodeText($h1Nodes->item(0))
            : '';
        $canonicalNode = $xpath->query('//link[contains(concat(" ", translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), " "), " canonical ")]')->item(0);
        if ($canonicalNode) {
            $result['canonical'] = $this->absoluteUrl((string) $canonicalNode->attributes?->getNamedItem('href')?->nodeValue, $baseUrl);
        }
        $faviconNode = $xpath->query('//link[contains(translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz"), "icon")]')->item(0);
        if ($faviconNode) {
            $result['favicon_url'] = $this->absoluteUrl((string) $faviconNode->attributes?->getNamedItem('href')?->nodeValue, $baseUrl);
        }
        return $result;
    }

    private function metaContent(DOMXPath $xpath, string $name): string
    {
        $query = '//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="'
            . $name
            . '"]';
        $node = $xpath->query($query)->item(0);
        return $node
            ? trim((string) $node->attributes?->getNamedItem('content')?->nodeValue)
            : '';
    }

    private function nodeText(?\DOMNode $node): string
    {
        if (!$node) {
            return '';
        }
        return trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
    }

    private function parseRobots(string $body): array
    {
        if ($body === '') {
            return [
                'disallow_all' => false,
                'sitemaps' => [],
                'summary' => 'Файл не загружен.',
            ];
        }

        $sitemaps = [];
        $disallowAll = false;
        $agents = [];
        $currentAgents = [];
        $lines = preg_split('/\R/u', $body) ?: [];

        foreach ($lines as $line) {
            $line = trim(preg_replace('/\s*#.*$/u', '', $line) ?? '');
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$key, $value] = array_map('trim', explode(':', $line, 2));
            $key = mb_strtolower($key);
            if ($key === 'user-agent') {
                $agent = mb_strtolower($value);
                $currentAgents = [$agent];
                $agents[$agent] = true;
                continue;
            }
            if ($key === 'sitemap' && filter_var($value, FILTER_VALIDATE_URL)) {
                $sitemaps[] = $value;
                continue;
            }
            if ($key === 'disallow' && trim($value) === '/') {
                if (in_array('*', $currentAgents, true) || in_array('yandex', $currentAgents, true)) {
                    $disallowAll = true;
                }
            }
        }

        $summary = 'User-agent: ' . ($agents === [] ? 'не найден' : implode(', ', array_keys($agents))) . '. ';
        $summary .= $disallowAll
            ? 'Обнаружен полный запрет Disallow: /.'
            : 'Полный запрет для общего робота не обнаружен.';
        if ($sitemaps !== []) {
            $summary .= ' Sitemap: ' . implode(', ', array_values(array_unique($sitemaps))) . '.';
        }

        return [
            'disallow_all' => $disallowAll,
            'sitemaps' => array_values(array_unique($sitemaps)),
            'summary' => $summary,
        ];
    }

    private function detectMetrika(string $html): array
    {
        $ids = [];
        $patterns = [
            '/\bym\s*\(\s*["\']?(\d{4,20})["\']?\s*,/u',
            '/new\s+Ya\.Metrika2?\s*\(\s*\{[^}]*\bid\s*:\s*["\']?(\d{4,20})/isu',
            '/mc\.yandex\.ru\/watch\/(\d{4,20})/u',
            '/yandex_metrika_callbacks[^\d]{0,100}(\d{4,20})/isu',
        ];
        foreach ($patterns as $pattern) {
            preg_match_all($pattern, $html, $matches);
            foreach ($matches[1] ?? [] as $id) {
                $ids[] = (string) $id;
            }
        }
        $ids = array_values(array_unique($ids));
        sort($ids, SORT_NATURAL);

        $lower = mb_strtolower($html);
        $webvisor = null;
        if ($ids !== []) {
            if (preg_match('/webvisor\s*:\s*true/iu', $html)) {
                $webvisor = true;
            } elseif (preg_match('/webvisor\s*:\s*false/iu', $html)) {
                $webvisor = false;
            }
        }

        return ['ids' => $ids, 'webvisor' => $webvisor];
    }

    private function sslInfo(string $host, bool $required): array
    {
        if (!$required) {
            return ['valid' => null, 'expires_at' => null, 'days_left' => null];
        }

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'peer_name' => $host,
                'SNI_enabled' => true,
            ],
        ]);
        $errno = 0;
        $errstr = '';
        $client = @stream_socket_client(
            'ssl://' . $host . ':443',
            $errno,
            $errstr,
            15,
            STREAM_CLIENT_CONNECT,
            $context
        );
        if (!is_resource($client)) {
            return ['valid' => false, 'expires_at' => null, 'days_left' => null, 'error' => $errstr];
        }
        $params = stream_context_get_params($client);
        fclose($client);
        $certificate = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$certificate) {
            return ['valid' => false, 'expires_at' => null, 'days_left' => null];
        }
        $parsed = openssl_x509_parse($certificate);
        if (!is_array($parsed)) {
            return ['valid' => false, 'expires_at' => null, 'days_left' => null];
        }
        $timestamp = isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : 0;
        return [
            'valid' => $timestamp > time(),
            'expires_at' => $timestamp > 0 ? date('Y-m-d H:i:s', $timestamp) : null,
            'days_left' => $timestamp > 0 ? (int) floor(($timestamp - time()) / 86400) : null,
        ];
    }

    private function dnsInfo(string $host): array
    {
        $types = [
            'A' => DNS_A,
            'AAAA' => defined('DNS_AAAA') ? DNS_AAAA : 0,
            'CNAME' => DNS_CNAME,
            'MX' => DNS_MX,
            'NS' => DNS_NS,
            'TXT' => DNS_TXT,
        ];
        $records = [];
        foreach ($types as $label => $type) {
            if ($type === 0) {
                continue;
            }
            $rows = @dns_get_record($host, $type);
            if (!is_array($rows)) {
                $rows = [];
            }
            $clean = [];
            foreach ($rows as $row) {
                $value = match ($label) {
                    'A' => $row['ip'] ?? '',
                    'AAAA' => $row['ipv6'] ?? '',
                    'CNAME' => $row['target'] ?? '',
                    'MX' => trim(($row['pri'] ?? '') . ' ' . ($row['target'] ?? '')),
                    'NS' => $row['target'] ?? '',
                    'TXT' => $row['txt'] ?? implode('', $row['entries'] ?? []),
                    default => '',
                };
                $value = trim((string) $value);
                if ($value !== '') {
                    $clean[] = $value;
                }
            }
            $clean = array_values(array_unique($clean));
            sort($clean, SORT_NATURAL);
            $records[$label] = $clean;
        }
        ksort($records);
        $encoded = json_encode($records, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return [
            'records' => $records,
            'hash' => is_string($encoded) ? hash('sha256', $encoded) : null,
        ];
    }

    private function domainInfo(string $host): array
    {
        $host = mb_strtolower(preg_replace('/^www\./i', '', $host) ?? $host);
        $labels = array_values(array_filter(explode('.', $host)));
        $candidates = [];
        for ($i = 0; $i <= max(0, count($labels) - 2); $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            if (substr_count($candidate, '.') >= 1) {
                $candidates[] = $candidate;
            }
        }

        foreach ($candidates as $candidate) {
            $response = $this->fetch(
                'https://rdap.org/domain/' . rawurlencode($candidate),
                'MirsaitovMonitor/1.0',
                20,
                1024 * 1024
            );
            if (!$this->isHttpSuccess($response)) {
                continue;
            }
            $data = json_decode((string) ($response['body'] ?? ''), true);
            if (!is_array($data)) {
                continue;
            }
            $registered = null;
            $expires = null;
            foreach (($data['events'] ?? []) as $event) {
                if (!is_array($event)) {
                    continue;
                }
                $action = mb_strtolower((string) ($event['eventAction'] ?? ''));
                $date = (string) ($event['eventDate'] ?? '');
                if (in_array($action, ['registration', 'registered'], true)) {
                    $registered = $date;
                }
                if (in_array($action, ['expiration', 'expiry', 'expires'], true)) {
                    $expires = $date;
                }
            }
            $expiresTimestamp = $expires ? strtotime($expires) : false;
            return [
                'domain' => (string) ($data['ldhName'] ?? $candidate),
                'registered_at' => $registered,
                'expires_at' => $expires,
                'days_left' => $expiresTimestamp !== false
                    ? (int) floor(($expiresTimestamp - time()) / 86400)
                    : null,
                'status' => $expires ? 'ok' : 'expiry_unavailable',
            ];
        }

        return [
            'domain' => $host,
            'registered_at' => null,
            'expires_at' => null,
            'days_left' => null,
            'status' => 'unavailable',
        ];
    }

    private function fetch(
        string $url,
        string $userAgent,
        int $timeout,
        int $maxBytes,
        bool $headFallback = false
    ): array {
        $headers = [];
        $body = '';
        $ch = curl_init($url);
        if ($ch === false) {
            return ['http_code' => 0, 'response_ms' => null, 'error' => 'Не удалось инициализировать cURL.', 'headers' => [], 'body' => '', 'final_url' => $url];
        }
        $started = microtime(true);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => min(15, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_ENCODING => '',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers): int {
                $length = strlen($line);
                if (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $name = mb_strtolower(trim($name));
                    $headers[$name] ??= [];
                    $headers[$name][] = trim($value);
                }
                return $length;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $maxBytes): int {
                $remaining = $maxBytes - strlen($body);
                if ($remaining > 0) {
                    $body .= substr($chunk, 0, $remaining);
                }
                return strlen($chunk);
            },
        ]);
        $executed = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $finalUrl = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
        $error = curl_error($ch);
        curl_close($ch);
        $responseMs = (int) round((microtime(true) - $started) * 1000);

        return [
            'http_code' => $httpCode,
            'response_ms' => $responseMs,
            'error' => $executed === false ? ($error !== '' ? $error : 'Нет ответа.') : null,
            'headers' => $headers,
            'body' => $body,
            'final_url' => $finalUrl !== '' ? $finalUrl : $url,
        ];
    }

    private function isAvailable(array $result): bool
    {
        return ($result['error'] ?? null) === null
            && $this->httpCodeOk((int) ($result['http_code'] ?? 0));
    }

    private function isHttpSuccess(array $result): bool
    {
        return $this->httpCodeOk((int) ($result['http_code'] ?? 0));
    }

    private function httpCodeOk(int $code): bool
    {
        return $code >= 200 && $code < 400;
    }

    private function availabilityError(array $result): string
    {
        $error = trim((string) ($result['error'] ?? ''));
        if ($error !== '') {
            return $error;
        }
        $code = (int) ($result['http_code'] ?? 0);
        return $code > 0 ? 'HTTP ' . $code : 'Сервер не вернул HTTP-ответ.';
    }

    private function normalizeSiteInput(array $input, int $projectId): array
    {
        $url = trim((string) ($input['base_url'] ?? ''));
        if ($url === '') {
            throw new RuntimeException('Укажите адрес сайта.');
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        $parts = parse_url($url);
        $scheme = mb_strtolower((string) ($parts['scheme'] ?? ''));
        $host = mb_strtolower((string) ($parts['host'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw new RuntimeException('Некорректный адрес сайта.');
        }
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            throw new RuntimeException('Для мониторинга укажите доменное имя, а не IP-адрес.');
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = trim((string) ($parts['path'] ?? ''));
        $path = $path === '' || $path === '/' ? '' : '/' . ltrim($path, '/');
        $baseUrl = $scheme . '://' . $host . $port . $path;

        return [
            'id' => (int) ($input['id'] ?? 0),
            'project_id' => $projectId,
            'name' => trim((string) ($input['name'] ?? '')) ?: $host,
            'base_url' => rtrim($baseUrl, '/'),
            'host' => $host,
            'is_active' => !array_key_exists('is_active', $input) || !empty($input['is_active']),
            'check_interval_minutes' => $input['check_interval_minutes'] ?? 5,
            'slow_threshold_ms' => $input['slow_threshold_ms'] ?? 3000,
            'notify_email' => !empty($input['notify_email']),
            'notify_telegram' => !empty($input['notify_telegram']),
            'technical_email' => $input['technical_email'] ?? '',
            'marketing_email' => $input['marketing_email'] ?? '',
            'technical_telegram_chat' => $input['technical_telegram_chat'] ?? '',
            'marketing_telegram_chat' => $input['marketing_telegram_chat'] ?? '',
            'expected_metrika_ids' => $input['expected_metrika_ids'] ?? '',
        ];
    }

    private function absoluteUrl(string $url, string $base): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        $parts = parse_url($base);
        $origin = (string) ($parts['scheme'] ?? 'https') . '://' . (string) ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $origin .= ':' . (int) $parts['port'];
        }
        if (str_starts_with($url, '//')) {
            return (string) ($parts['scheme'] ?? 'https') . ':' . $url;
        }
        if (str_starts_with($url, '/')) {
            return $origin . $url;
        }
        $path = (string) ($parts['path'] ?? '/');
        $directory = rtrim(str_replace('\\', '/', dirname($path)), '/');
        return $origin . ($directory !== '' ? $directory : '') . '/' . ltrim($url, '/');
    }

    private function comparable(mixed $value): string
    {
        if (is_array($value)) {
            ksort($value);
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '';
        }
        return trim((string) $value);
    }
}
