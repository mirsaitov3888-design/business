from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.03.25"
root = Path.cwd()
component_root = root / "tools" / "local-bitrix-links-v25"

service = (component_root / "LocalBitrixLinkService.php").read_text(encoding="utf-8")
replacements = {
    "p.status AS project_status": "p.lk_status AS project_status",
    "created_by, created_at)": "user_id, created_at)",
    ":created_by, NOW())": ":user_id, NOW())",
    "'created_by' => $userId": "'user_id' => $userId",
}
for old, new in replacements.items():
    if old not in service:
        raise SystemExit(f"Local link service marker missing: {old}")
    service = service.replace(old, new)

components: dict[str, bytes] = {
    "service": service.encode("utf-8"),
    "api": (component_root / "local-bitrix-links-api.php").read_bytes(),
    "js": (component_root / "local-bitrix-links.js").read_bytes(),
    "css": (component_root / "local-bitrix-links.css").read_bytes(),
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
installer = head.rstrip() + "\n" + run.lstrip()
installer = installer.replace("__LOCAL_LINKS_VERSION__", VERSION)
installer = installer.replace("__LOCAL_LINKS_PAYLOAD_B64__", payload_b64)
installer = installer.replace("__LOCAL_LINKS_PAYLOAD_SHA__", payload_sha)

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(installer.encode("utf-8")).hexdigest())
