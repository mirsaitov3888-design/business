<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use Closure;
use RuntimeException;

final class Bitrix24DirectoryService
{
    private Bitrix24Client $client;
    private ?Closure $caller;

    public function __construct(
        ?Bitrix24Client $client = null,
        ?Closure $caller = null
    ) {
        $this->client = $client ?? new Bitrix24Client();
        $this->caller = $caller;
    }

    public function catalog(?int $companyId = null): array
    {
        $companies = $this->companies();
        $projects = $this->projects();
        $contacts = $companyId !== null && $companyId > 0
            ? $this->contacts($companyId)
            : [];
        $company = null;
        foreach ($companies as $row) {
            if ((int) $row['id'] === (int) $companyId) {
                $company = $row;
                break;
            }
        }

        return [
            'portal_host' => $this->client->portalHost(),
            'companies' => $companies,
            'projects' => $projects,
            'company' => $company,
            'contacts' => $contacts,
            'recommended_project_ids' => $company === null
                ? []
                : $this->recommendedProjectIds(
                    (string) ($company['title'] ?? ''),
                    $projects
                ),
        ];
    }

    public function companies(): array
    {
        $result = [];
        $start = 0;

        do {
            $response = $this->call('crm.company.list', [
                'order' => ['TITLE' => 'ASC'],
                'filter' => [],
                'select' => [
                    'ID',
                    'TITLE',
                    'PHONE',
                    'EMAIL',
                    'ASSIGNED_BY_ID',
                    'DATE_CREATE',
                    'DATE_MODIFY',
                ],
                'start' => $start,
            ]);
            $rows = is_array($response['result'] ?? null)
                ? $response['result']
                : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = $this->normalizeCompany($row);
                if ($normalized['id'] > 0) {
                    $result[$normalized['id']] = $normalized;
                }
            }
            $start += 50;
            $total = (int) ($response['total'] ?? count($result));
        } while ($rows !== [] && count($result) < $total && $start < 10000);

        $result = array_values($result);
        usort(
            $result,
            static fn(array $a, array $b): int =>
                strnatcasecmp((string) $a['title'], (string) $b['title'])
        );
        return $result;
    }

    public function contacts(int $companyId): array
    {
        if ($companyId <= 0) {
            return [];
        }

        $result = [];
        $start = 0;
        do {
            $response = $this->call('crm.contact.list', [
                'order' => ['LAST_NAME' => 'ASC', 'NAME' => 'ASC'],
                'filter' => ['COMPANY_ID' => $companyId],
                'select' => [
                    'ID',
                    'NAME',
                    'LAST_NAME',
                    'SECOND_NAME',
                    'COMPANY_ID',
                    'PHONE',
                    'EMAIL',
                    'ASSIGNED_BY_ID',
                    'DATE_CREATE',
                    'DATE_MODIFY',
                ],
                'start' => $start,
            ]);
            $rows = is_array($response['result'] ?? null)
                ? $response['result']
                : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = $this->normalizeContact($row);
                if ($normalized['id'] > 0) {
                    $result[$normalized['id']] = $normalized;
                }
            }
            $start += 50;
            $total = (int) ($response['total'] ?? count($result));
        } while ($rows !== [] && count($result) < $total && $start < 10000);

        return array_values($result);
    }

    public function projects(): array
    {
        $result = [];
        $start = 0;
        do {
            $response = $this->call('sonet_group.get', [
                'ORDER' => ['NAME' => 'ASC'],
                'FILTER' => [
                    'ACTIVE' => 'Y',
                    'CLOSED' => 'N',
                ],
                'start' => $start,
            ]);
            $rows = is_array($response['result'] ?? null)
                ? $response['result']
                : [];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $normalized = $this->normalizeProject($row);
                if ($normalized['id'] > 0) {
                    $result[$normalized['id']] = $normalized;
                }
            }
            $start += 50;
            $total = (int) ($response['total'] ?? count($result));
        } while ($rows !== [] && count($result) < $total && $start < 10000);

        $result = array_values($result);
        usort(
            $result,
            static fn(array $a, array $b): int =>
                strnatcasecmp((string) $a['name'], (string) $b['name'])
        );
        return $result;
    }

    public function company(int $companyId): array
    {
        foreach ($this->companies() as $company) {
            if ((int) $company['id'] === $companyId) {
                return $company;
            }
        }
        throw new RuntimeException('Компания Bitrix24 не найдена.');
    }

    public function selectedProjects(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        if ($ids === []) {
            return [];
        }
        $available = [];
        foreach ($this->projects() as $project) {
            $available[(int) $project['id']] = $project;
        }
        $result = [];
        foreach ($ids as $id) {
            if (!isset($available[$id])) {
                throw new RuntimeException(
                    'Проект Bitrix24 #' . $id . ' не найден или недоступен.'
                );
            }
            $result[] = $available[$id];
        }
        return $result;
    }

    public function selectedContacts(int $companyId, array $ids): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $ids),
            static fn(int $id): bool => $id > 0
        )));
        $available = [];
        foreach ($this->contacts($companyId) as $contact) {
            $available[(int) $contact['id']] = $contact;
        }
        $result = [];
        foreach ($ids as $id) {
            if (!isset($available[$id])) {
                throw new RuntimeException(
                    'Контакт Bitrix24 #' . $id
                    . ' не относится к выбранной компании.'
                );
            }
            $result[] = $available[$id];
        }
        return $result;
    }

    private function recommendedProjectIds(
        string $companyTitle,
        array $projects
    ): array {
        $companyTokens = $this->tokens($companyTitle);
        if ($companyTokens === []) {
            return [];
        }
        $scored = [];
        foreach ($projects as $project) {
            $projectTokens = $this->tokens((string) ($project['name'] ?? ''));
            $common = array_intersect($companyTokens, $projectTokens);
            if ($common === []) {
                continue;
            }
            $score = count($common) / max(1, count($companyTokens));
            if ($score >= 0.34) {
                $scored[(int) $project['id']] = $score;
            }
        }
        arsort($scored, SORT_NUMERIC);
        return array_map('intval', array_keys($scored));
    }

    private function tokens(string $value): array
    {
        $value = mb_strtolower(trim($value));
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $value) ?: [];
        $stop = [
            'ооо' => true,
            'ип' => true,
            'ао' => true,
            'пао' => true,
            'компания' => true,
            'проект' => true,
            'группа' => true,
            'сайт' => true,
            'website' => true,
            'site' => true,
        ];
        $parts = array_values(array_unique(array_filter(
            $parts,
            static fn(string $part): bool =>
                mb_strlen($part) >= 2 && !isset($stop[$part])
        )));
        return $parts;
    }

    private function normalizeCompany(array $row): array
    {
        return [
            'id' => (int) ($row['ID'] ?? $row['id'] ?? 0),
            'title' => trim((string) ($row['TITLE'] ?? $row['title'] ?? '')),
            'phone' => $this->firstMultiValue($row['PHONE'] ?? $row['phone'] ?? []),
            'email' => $this->firstMultiValue($row['EMAIL'] ?? $row['email'] ?? []),
            'assigned_by_id' => (int) (
                $row['ASSIGNED_BY_ID'] ?? $row['assignedById'] ?? 0
            ),
            'raw' => $row,
        ];
    }

    private function normalizeContact(array $row): array
    {
        $name = trim(implode(' ', array_filter([
            (string) ($row['LAST_NAME'] ?? $row['lastName'] ?? ''),
            (string) ($row['NAME'] ?? $row['name'] ?? ''),
            (string) ($row['SECOND_NAME'] ?? $row['secondName'] ?? ''),
        ])));
        return [
            'id' => (int) ($row['ID'] ?? $row['id'] ?? 0),
            'company_id' => (int) ($row['COMPANY_ID'] ?? $row['companyId'] ?? 0),
            'name' => $name !== '' ? $name : 'Контакт без имени',
            'phone' => $this->firstMultiValue($row['PHONE'] ?? $row['phone'] ?? []),
            'email' => $this->firstMultiValue($row['EMAIL'] ?? $row['email'] ?? []),
            'assigned_by_id' => (int) (
                $row['ASSIGNED_BY_ID'] ?? $row['assignedById'] ?? 0
            ),
            'raw' => $row,
        ];
    }

    private function normalizeProject(array $row): array
    {
        return [
            'id' => (int) ($row['ID'] ?? $row['id'] ?? 0),
            'name' => trim((string) ($row['NAME'] ?? $row['name'] ?? '')),
            'description' => trim((string) (
                $row['DESCRIPTION'] ?? $row['description'] ?? ''
            )),
            'owner_id' => (int) ($row['OWNER_ID'] ?? $row['ownerId'] ?? 0),
            'raw' => $row,
        ];
    }

    private function firstMultiValue(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                $candidate = trim((string) (
                    $item['VALUE'] ?? $item['value'] ?? ''
                ));
            } else {
                $candidate = trim((string) $item);
            }
            if ($candidate !== '') {
                return $candidate;
            }
        }
        return '';
    }

    private function call(string $method, array $params): array
    {
        if ($this->caller instanceof Closure) {
            $result = ($this->caller)($method, $params);
            if (!is_array($result)) {
                throw new RuntimeException(
                    'Тестовый шлюз Bitrix24 вернул некорректный ответ.'
                );
            }
            return $result;
        }
        return $this->client->call($method, $params);
    }
}
