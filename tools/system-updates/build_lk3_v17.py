from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.02.17"
root = Path.cwd()
component_root = root / "tools" / "lk3-v17"

components = {
    "service": component_root / "ProjectSourceService.php",
    "api": component_root / "project-sources-api.php",
    "js": component_root / "project-sources.js",
    "css": component_root / "project-sources.css",
    "schema": component_root / "schema.sqlfrag",
}

payload: dict[str, dict[str, str]] = {}
for key, path in components.items():
    text = path.read_text(encoding="utf-8")
    if key == "service":
        old = """            'status' => in_array(
                (string) ($data['status'] ?? 'active'),
                ['active', 'paused', 'archived'],
                true
            ) ? (string) $data['status'] : 'active',
"""
        new = """            'status' => in_array(
                (string) ($data['status'] ?? 'active'),
                ['active', 'paused', 'archived'],
                true
            ) ? (string) ($data['status'] ?? 'active') : 'active',
"""
        if text.count(old) != 1:
            raise SystemExit(
                f"LK3 source status marker: expected 1, found {text.count(old)}"
            )
        text = text.replace(old, new, 1)
    raw = text.encode("utf-8")
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
installer = installer.replace("__LK3_VERSION__", VERSION)
installer = installer.replace("__LK3_PAYLOAD_B64__", payload_b64)
installer = installer.replace("__LK3_PAYLOAD_SHA__", payload_sha)

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(installer.encode("utf-8")).hexdigest())
