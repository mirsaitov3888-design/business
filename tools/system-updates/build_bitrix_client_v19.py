from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.03.19"
root = Path.cwd()
component_root = root / "tools" / "bitrix-client-v19"

js = (component_root / "bitrix24-client.js").read_text(encoding="utf-8")
old_request = """    async function request(action, options = {}) {
        const response = await fetch(
            '/bitrix24-client-api.php?action=' + encodeURIComponent(action),
"""
new_request = """    async function request(action, query = {}, options = {}) {
        const params = new URLSearchParams({action});
        Object.entries(query).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                params.set(key, String(value));
            }
        });
        const response = await fetch(
            '/bitrix24-client-api.php?' + params.toString(),
"""
if js.count(old_request) != 1:
    raise SystemExit(f"request function marker: expected 1, found {js.count(old_request)}")
js = js.replace(old_request, new_request, 1)

old_initial = """            const result = await request(
                'catalog&client_id=' + encodeURIComponent(state.clientId)
            );"""
new_initial = """            const result = await request('catalog', {
                client_id: state.clientId
            });"""
if js.count(old_initial) != 1:
    raise SystemExit(f"initial catalog marker: expected 1, found {js.count(old_initial)}")
js = js.replace(old_initial, new_initial, 1)

old_company = """            const result = await request(
                'catalog&company_id=' + encodeURIComponent(companyId)
                + '&client_id=' + encodeURIComponent(state.clientId)
            );"""
new_company = """            const result = await request('catalog', {
                company_id: companyId,
                client_id: state.clientId
            });"""
if js.count(old_company) != 1:
    raise SystemExit(f"company catalog marker: expected 1, found {js.count(old_company)}")
js = js.replace(old_company, new_company, 1)

old_save = """            await request('save', {
                method: 'POST',"""
new_save = """            await request('save', {}, {
                method: 'POST',"""
if js.count(old_save) != 1:
    raise SystemExit(f"save request marker: expected 1, found {js.count(old_save)}")
js = js.replace(old_save, new_save, 1)

service = (component_root / "Bitrix24ClientOnboardingService.php").read_text(
    encoding="utf-8"
)
project_marker = """        $projects = $this->directory->selectedProjects($projectIds);
        $primaryContactId"""
project_replacement = """        $projects = $this->directory->selectedProjects($projectIds);
        if ($projects === []) {
            throw new RuntimeException('Выберите хотя бы один проект Bitrix24.');
        }
        $primaryContactId"""
if service.count(project_marker) != 1:
    raise SystemExit(
        f"project selection marker: expected 1, found {service.count(project_marker)}"
    )
service = service.replace(project_marker, project_replacement, 1)

components: dict[str, bytes] = {
    "directory": (component_root / "Bitrix24DirectoryService.php").read_bytes(),
    "service": service.encode("utf-8"),
    "api": (component_root / "bitrix24-client-api.php").read_bytes(),
    "js": js.encode("utf-8"),
    "css": (component_root / "bitrix24-client.css").read_bytes(),
    "schema": (component_root / "schema.sqlfrag").read_bytes(),
}

payload: dict[str, dict[str, str]] = {}
for key, raw in components.items():
    payload[key] = {
        "sha256": hashlib.sha256(raw).hexdigest(),
        "content": base64.b64encode(raw).decode("ascii"),
    }

payload_json = json.dumps(
    payload,
    ensure_ascii=False,
    separators=(",", ":"),
).encode("utf-8")
payload_b64 = base64.b64encode(payload_json).decode("ascii")
payload_sha = hashlib.sha256(payload_json).hexdigest()

head = (component_root / "installer-head.phpfrag").read_text(encoding="utf-8")
run = (component_root / "installer-run.phpfrag").read_text(encoding="utf-8")

old_backup = """    $backupTable = $table . '_before_bitrix_client_v19_' . $suffix;
    $pdo->exec('CREATE TABLE `' . $backupTable . '` LIKE `' . $table . '`');"""
new_backup = """    $backupPrefixes = [
        'clients' => 'cl',
        'project_client_links' => 'pcl',
        'bitrix24_project_links' => 'bpl',
        'client_bitrix_contacts' => 'cbc',
        'client_bitrix_projects' => 'cbp',
    ];
    $backupPrefix = $backupPrefixes[$table] ?? 'b19';
    $backupTable = $backupPrefix . '_b19_' . $suffix;
    $pdo->exec('CREATE TABLE `' . $backupTable . '` LIKE `' . $table . '`');"""
if run.count(old_backup) != 1:
    raise SystemExit(
        f"backup table marker: expected 1, found {run.count(old_backup)}"
    )
run = run.replace(old_backup, new_backup, 1)

installer = head.rstrip() + "\n" + run.lstrip()
installer = installer.replace("__BITRIX_CLIENT_VERSION__", VERSION)
installer = installer.replace("__BITRIX_CLIENT_PAYLOAD_B64__", payload_b64)
installer = installer.replace("__BITRIX_CLIENT_PAYLOAD_SHA__", payload_sha)

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(installer.encode("utf-8")).hexdigest())
