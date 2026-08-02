from pathlib import Path

path = Path('updates/installers/2026.08.02.10.php')
text = path.read_text(encoding='utf-8')

replacements = [
    (
        "$service = new \\SeoAnalytics\\Services\\Bitrix24AcceptanceService();",
        "$service = \\SeoAnalytics\\Services\\Bitrix24AcceptanceService::create();",
    ),
    (
        """    public function __construct(\n        private readonly Bitrix24Client $client = new Bitrix24Client(),\n        private readonly PDO $pdo = new PDO('sqlite::memory:')\n    ) {\n    }\n""",
        """    public function __construct(\n        private readonly Bitrix24Client $client,\n        private readonly PDO $pdo\n    ) {\n    }\n""",
    ),
    (
        """            foreach ($rows as $row) {\n                $links[] = $this->acceptLink($row, $counts, $warnings);\n            }\n""",
        """            foreach ($rows as $row) {\n                $linkReport = $this->acceptLink($row, $counts, $warnings);\n                if (!empty($linkReport['error'])) {\n                    $errors[] = 'Связанный проект #'\n                        . (int) ($linkReport['project_id'] ?? 0)\n                        . ': '\n                        . (string) $linkReport['error'];\n                }\n                $links[] = $linkReport;\n            }\n""",
    ),
]

for old, new in replacements:
    count = text.count(old)
    if count != 1:
        raise SystemExit(f'Expected exactly one replacement, found {count}: {old[:80]!r}')
    text = text.replace(old, new, 1)

path.write_text(text, encoding='utf-8')
print('P0.5 installer corrected')
