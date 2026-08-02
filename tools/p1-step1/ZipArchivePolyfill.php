<?php
declare(strict_types=1);

if (!class_exists('ZipArchive', false)) {
    /**
     * Минимальный read-only polyfill для стандартных XLSX/ZIP без ZIP64.
     * Реализует только методы, которые нужны SalesImportService.
     */
    final class ZipArchive
    {
        public const ER_NOZIP = 19;

        public int $numFiles = 0;

        /** @var array<string, string> */
        private array $entries = [];

        /** @var list<string> */
        private array $names = [];

        public function open(string $path): bool|int
        {
            $binary = file_get_contents($path);
            if (!is_string($binary) || strlen($binary) < 22) {
                return self::ER_NOZIP;
            }

            try {
                $this->entries = $this->parse($binary);
                $this->names = array_keys($this->entries);
                $this->numFiles = count($this->names);
                return true;
            } catch (Throwable) {
                $this->entries = [];
                $this->names = [];
                $this->numFiles = 0;
                return self::ER_NOZIP;
            }
        }

        public function getNameIndex(int $index): string|false
        {
            return $this->names[$index] ?? false;
        }

        public function getFromName(string $name): string|false
        {
            return array_key_exists($name, $this->entries)
                ? $this->entries[$name]
                : false;
        }

        public function close(): bool
        {
            $this->entries = [];
            $this->names = [];
            $this->numFiles = 0;
            return true;
        }

        /** @return array<string, string> */
        private function parse(string $binary): array
        {
            $eocd = strrpos($binary, "PK\x05\x06");
            if ($eocd === false || strlen($binary) < $eocd + 22) {
                throw new RuntimeException('Не найдена центральная директория ZIP.');
            }

            $footer = unpack(
                'vdisk/vdisk_start/ventries_disk/ventries/Vsize/Voffset/vcomment',
                substr($binary, $eocd + 4, 18)
            );
            if (!is_array($footer)) {
                throw new RuntimeException('Некорректный заголовок ZIP.');
            }

            $entriesCount = (int) ($footer['entries'] ?? 0);
            $position = (int) ($footer['offset'] ?? 0);
            $entries = [];

            for ($index = 0; $index < $entriesCount; $index++) {
                if (substr($binary, $position, 4) !== "PK\x01\x02") {
                    throw new RuntimeException('Повреждена центральная директория ZIP.');
                }

                $central = unpack(
                    'vversion_made/vversion_needed/vflags/vmethod/vtime/vdate/'
                    . 'Vcrc/Vcompressed/Vuncompressed/vname_length/vextra_length/'
                    . 'vcomment_length/vdisk/vinternal/Vexternal/Vlocal_offset',
                    substr($binary, $position + 4, 42)
                );
                if (!is_array($central)) {
                    throw new RuntimeException('Некорректная запись ZIP.');
                }

                $nameLength = (int) $central['name_length'];
                $extraLength = (int) $central['extra_length'];
                $commentLength = (int) $central['comment_length'];
                $name = substr($binary, $position + 46, $nameLength);
                $position += 46 + $nameLength + $extraLength + $commentLength;

                if ($name === '' || str_ends_with($name, '/')) {
                    continue;
                }

                $localOffset = (int) $central['local_offset'];
                if (substr($binary, $localOffset, 4) !== "PK\x03\x04") {
                    throw new RuntimeException('Не найден локальный заголовок ZIP.');
                }
                $local = unpack(
                    'vversion/vflags/vmethod/vtime/vdate/Vcrc/Vcompressed/'
                    . 'Vuncompressed/vname_length/vextra_length',
                    substr($binary, $localOffset + 4, 26)
                );
                if (!is_array($local)) {
                    throw new RuntimeException('Некорректный локальный заголовок ZIP.');
                }

                $dataOffset = $localOffset
                    + 30
                    + (int) $local['name_length']
                    + (int) $local['extra_length'];
                $compressed = substr(
                    $binary,
                    $dataOffset,
                    (int) $central['compressed']
                );

                $method = (int) $central['method'];
                if ($method === 0) {
                    $content = $compressed;
                } elseif ($method === 8 && function_exists('gzinflate')) {
                    $inflated = gzinflate($compressed);
                    if (!is_string($inflated)) {
                        throw new RuntimeException('Не удалось распаковать ZIP-entry.');
                    }
                    $content = $inflated;
                } else {
                    throw new RuntimeException('Неподдерживаемый метод сжатия ZIP: ' . $method);
                }

                if (
                    (int) $central['uncompressed'] > 0
                    && strlen($content) !== (int) $central['uncompressed']
                ) {
                    throw new RuntimeException('Размер распакованного ZIP-entry не совпал.');
                }

                $entries[$name] = $content;
            }

            return $entries;
        }
    }
}
