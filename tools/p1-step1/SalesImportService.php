<?php
declare(strict_types=1);

namespace SeoAnalytics\Services;

use RuntimeException;
use SeoAnalytics\Repositories\SalesRepository;
use SimpleXMLElement;
use ZipArchive;

final class SalesImportService
{
    private const MAX_FILE_BYTES = 10_485_760;
    private const MAX_ROWS = 5000;

    private const HEADER_ALIASES = [
        'occurred_at' => [
            'дата', 'датасделки', 'датазаявки', 'date', 'dealdate', 'createdat',
        ],
        'external_id' => [
            'id', 'externalid', 'номер', 'номерсделки', 'idsделки', 'dealid',
        ],
        'channel_key' => [
            'канал', 'источник', 'источниклида', 'channel', 'source', 'utm_source',
        ],
        'customer_name' => [
            'клиент', 'контакт', 'название', 'customer', 'customername', 'client',
        ],
        'status' => [
            'статус', 'этап', 'стадия', 'status', 'stage',
        ],
        'contract_amount' => [
            'сумма', 'суммадоговора', 'бюджет', 'amount', 'contractamount', 'dealamount',
        ],
        'paid_amount' => [
            'оплачено', 'оплаченнаявыручка', 'выручка', 'paid', 'paidamount', 'revenue',
        ],
        'gross_margin_percent' => [
            'маржа', 'маржапроцент', 'валоваямаржа', 'margin', 'grossmarginpercent',
        ],
        'qualified' => [
            'квалифицированный', 'квалифицирован', 'целевой', 'qualified',
        ],
        'contract' => [
            'договор', 'естьдоговор', 'contract', 'won',
        ],
        'notes' => [
            'комментарий', 'примечание', 'notes', 'comment',
        ],
    ];

    public function parseUpload(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            throw new RuntimeException($this->uploadError($error));
        }

        $path = (string) ($file['tmp_name'] ?? '');
        $name = trim((string) ($file['name'] ?? ''));
        $size = (int) ($file['size'] ?? 0);

        if ($path === '' || !is_file($path)) {
            throw new RuntimeException('Загруженный файл не найден.');
        }
        if ($size <= 0 || $size > self::MAX_FILE_BYTES) {
            throw new RuntimeException('Размер файла должен быть от 1 байта до 10 МБ.');
        }

        $extension = mb_strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $matrix = match ($extension) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            default => throw new RuntimeException('Поддерживаются только CSV и XLSX.'),
        };

        $parsed = $this->mapRows($matrix);
        $parsed['original_name'] = $name !== '' ? $name : ('sales.' . $extension);
        $parsed['file_type'] = $extension === 'txt' ? 'csv' : $extension;
        return $parsed;
    }

    public function templateCsv(): string
    {
        $rows = [
            [
                'Дата',
                'ID сделки',
                'Канал',
                'Клиент',
                'Статус',
                'Сумма договора',
                'Оплачено',
                'Маржа %',
                'Квалифицированный',
                'Договор',
                'Комментарий',
            ],
            [
                date('Y-m-d'),
                'CRM-1001',
                'Яндекс Директ',
                'ООО Пример',
                'Оплачено',
                '150000',
                '100000',
                '35',
                'Да',
                'Да',
                'Пример строки — удалите её перед импортом',
            ],
        ];

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Не удалось создать шаблон.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        foreach ($rows as $row) {
            fputcsv($stream, $row, ';');
        }
        rewind($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        if (!is_string($content)) {
            throw new RuntimeException('Не удалось сформировать шаблон.');
        }
        return $content;
    }

    private function mapRows(array $matrix): array
    {
        $matrix = array_values(array_filter(
            $matrix,
            fn(array $row): bool => $this->rowHasValue($row)
        ));
        if ($matrix === []) {
            throw new RuntimeException('Файл не содержит строк с данными.');
        }

        $header = array_shift($matrix);
        $columnMap = [];
        foreach ($header as $index => $label) {
            $key = $this->headerKey((string) $label);
            if ($key !== null && !array_key_exists($key, $columnMap)) {
                $columnMap[$key] = (int) $index;
            }
        }

        if (!isset($columnMap['occurred_at'])) {
            throw new RuntimeException('В файле не найдена обязательная колонка «Дата».');
        }

        $rows = [];
        $errors = [];
        $repository = new SalesRepository();

        foreach (array_slice($matrix, 0, self::MAX_ROWS) as $offset => $sourceRow) {
            $line = $offset + 2;
            if (!$this->rowHasValue($sourceRow)) {
                continue;
            }

            $row = [];
            foreach ($columnMap as $key => $index) {
                $row[$key] = $sourceRow[$index] ?? null;
            }
            $row['channel_key'] = $this->channel($row['channel_key'] ?? '');
            $row['status'] = $this->status($row['status'] ?? '');
            $row['qualified'] = $this->booleanValue($row['qualified'] ?? null);
            $row['contract'] = $this->booleanValue($row['contract'] ?? null);

            try {
                $rows[] = $repository->sanitize($row);
            } catch (\Throwable $exception) {
                $errors[] = [
                    'line' => $line,
                    'message' => $exception->getMessage(),
                ];
            }
        }

        if ($rows === [] && $errors !== []) {
            throw new RuntimeException(
                'Не удалось подготовить ни одной строки. Первая ошибка: '
                . (string) ($errors[0]['message'] ?? 'неизвестная ошибка')
            );
        }

        return [
            'rows' => $rows,
            'errors' => $errors,
            'headers' => array_values(array_map('strval', $header)),
            'column_map' => $columnMap,
            'truncated' => count($matrix) > self::MAX_ROWS,
        ];
    }

    private function readCsv(string $path): array
    {
        $content = file_get_contents($path);
        if (!is_string($content)) {
            throw new RuntimeException('Не удалось прочитать CSV.');
        }
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding(
                $content,
                'UTF-8',
                ['Windows-1251', 'CP1251', 'ISO-8859-5']
            );
        }

        $firstLine = strtok($content, "\r\n") ?: '';
        $delimiter = ';';
        $scores = [
            ';' => substr_count($firstLine, ';'),
            ',' => substr_count($firstLine, ','),
            "\t" => substr_count($firstLine, "\t"),
        ];
        arsort($scores);
        $candidate = array_key_first($scores);
        if (is_string($candidate) && ($scores[$candidate] ?? 0) > 0) {
            $delimiter = $candidate;
        }

        $stream = fopen('php://temp', 'w+');
        if ($stream === false) {
            throw new RuntimeException('Не удалось подготовить CSV.');
        }
        fwrite($stream, $content);
        rewind($stream);

        $rows = [];
        while (($row = fgetcsv($stream, 0, $delimiter)) !== false) {
            $rows[] = array_map(
                static fn(mixed $value): string => trim((string) $value),
                $row
            );
            if (count($rows) > self::MAX_ROWS + 1) {
                break;
            }
        }
        fclose($stream);
        return $rows;
    }

    private function readXlsx(string $path): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new RuntimeException('Для XLSX на сервере требуется расширение ZIP.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('Не удалось открыть XLSX.');
        }

        try {
            $shared = $this->sharedStrings($zip);
            $sheetNames = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = (string) $zip->getNameIndex($index);
                if (preg_match('~^xl/worksheets/sheet\d+\.xml$~', $name) === 1) {
                    $sheetNames[] = $name;
                }
            }
            natsort($sheetNames);
            $sheetName = reset($sheetNames);
            if (!is_string($sheetName) || $sheetName === '') {
                throw new RuntimeException('В XLSX не найден рабочий лист.');
            }

            $xmlRaw = $zip->getFromName($sheetName);
            if (!is_string($xmlRaw) || $xmlRaw === '') {
                throw new RuntimeException('Не удалось прочитать первый лист XLSX.');
            }
            $xml = simplexml_load_string($xmlRaw);
            if (!$xml instanceof SimpleXMLElement) {
                throw new RuntimeException('Некорректная структура XLSX.');
            }

            $rowNodes = $xml->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
            $rows = [];
            foreach (array_slice($rowNodes, 0, self::MAX_ROWS + 1) as $rowNode) {
                if (!$rowNode instanceof SimpleXMLElement) {
                    continue;
                }
                $row = [];
                $cells = $rowNode->xpath('./*[local-name()="c"]') ?: [];
                foreach ($cells as $cell) {
                    if (!$cell instanceof SimpleXMLElement) {
                        continue;
                    }
                    $reference = (string) ($cell['r'] ?? '');
                    $column = $this->columnIndex($reference);
                    if ($column < 0) {
                        continue;
                    }
                    $type = (string) ($cell['t'] ?? '');
                    $valueNodes = $cell->xpath('./*[local-name()="v"]');
                    $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';
                    if ($type === 's') {
                        $value = $shared[(int) $value] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $texts = $cell->xpath('.//*[local-name()="t"]') ?: [];
                        $value = implode('', array_map('strval', $texts));
                    }
                    $row[$column] = trim($value);
                }
                if ($row !== []) {
                    $max = max(array_keys($row));
                    $normalized = [];
                    for ($column = 0; $column <= $max; $column++) {
                        $normalized[] = $row[$column] ?? '';
                    }
                    $rows[] = $normalized;
                }
            }
            return $rows;
        } finally {
            $zip->close();
        }
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $raw = $zip->getFromName('xl/sharedStrings.xml');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $xml = simplexml_load_string($raw);
        if (!$xml instanceof SimpleXMLElement) {
            return [];
        }
        $result = [];
        foreach ($xml->xpath('//*[local-name()="si"]') ?: [] as $item) {
            if (!$item instanceof SimpleXMLElement) {
                continue;
            }
            $texts = $item->xpath('.//*[local-name()="t"]') ?: [];
            $result[] = implode('', array_map('strval', $texts));
        }
        return $result;
    }

    private function headerKey(string $label): ?string
    {
        $normalized = $this->normalizeToken($label);
        foreach (self::HEADER_ALIASES as $key => $aliases) {
            if (in_array($normalized, $aliases, true)) {
                return $key;
            }
        }
        return null;
    }

    private function channel(mixed $value): string
    {
        $token = $this->normalizeToken((string) $value);
        $map = [
            'direct' => 'direct', 'яндексдирект' => 'direct', 'yandexdirect' => 'direct',
            'vk' => 'vk', 'вк' => 'vk', 'вкреклама' => 'vk',
            'avito' => 'avito', 'авито' => 'avito',
            '2gis' => '2gis', '2гис' => '2gis', 'дубльгис' => '2gis',
            'yandexbusiness' => 'yandex_business', 'яндексбизнес' => 'yandex_business', 'яндекскарты' => 'yandex_business',
            'seo' => 'seo', 'органика' => 'seo', 'органическийпоиск' => 'seo',
            'referral' => 'referral', 'рекомендация' => 'referral', 'рекомендации' => 'referral',
            'repeat' => 'repeat', 'повторный' => 'repeat', 'повторныепродажи' => 'repeat',
        ];
        return $map[$token] ?? (isset(SalesRepository::CHANNELS[(string) $value])
            ? (string) $value
            : 'other');
    }

    private function status(mixed $value): string
    {
        $token = $this->normalizeToken((string) $value);
        $map = [
            'lead' => 'lead', 'заявка' => 'lead', 'лид' => 'lead', 'новый' => 'lead',
            'qualified' => 'qualified', 'квалифицирован' => 'qualified', 'квалифицированный' => 'qualified', 'целевой' => 'qualified',
            'meeting' => 'meeting', 'встреча' => 'meeting', 'созвон' => 'meeting',
            'offer' => 'offer', 'предложение' => 'offer', 'коммерческоепредложение' => 'offer', 'кп' => 'offer',
            'contract' => 'contract', 'договор' => 'contract', 'сделка' => 'contract', 'выигран' => 'contract',
            'paid' => 'paid', 'оплачено' => 'paid', 'оплачен' => 'paid', 'payment' => 'paid',
            'lost' => 'lost', 'отказ' => 'lost', 'проигран' => 'lost', 'нецелевой' => 'lost',
        ];
        return $map[$token] ?? (isset(SalesRepository::STATUSES[(string) $value])
            ? (string) $value
            : 'lead');
    }

    private function booleanValue(mixed $value): bool
    {
        return in_array(
            $this->normalizeToken((string) $value),
            ['1', 'true', 'yes', 'y', 'да', 'д', 'целевой', 'договор'],
            true
        );
    }

    private function normalizeToken(string $value): string
    {
        $value = mb_strtolower(trim(str_replace('ё', 'е', $value)));
        return preg_replace('/[^a-zа-я0-9_]+/u', '', $value) ?? '';
    }

    private function columnIndex(string $reference): int
    {
        if (preg_match('/^([A-Z]+)/i', $reference, $match) !== 1) {
            return -1;
        }
        $letters = strtoupper($match[1]);
        $index = 0;
        for ($position = 0; $position < strlen($letters); $position++) {
            $index = ($index * 26) + (ord($letters[$position]) - 64);
        }
        return $index - 1;
    }

    private function rowHasValue(array $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return true;
            }
        }
        return false;
    }

    private function uploadError(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Файл превышает разрешённый размер.',
            UPLOAD_ERR_PARTIAL => 'Файл загрузился не полностью.',
            UPLOAD_ERR_NO_FILE => 'Выберите файл CSV или XLSX.',
            default => 'Ошибка загрузки файла, код ' . $error . '.',
        };
    }
}
