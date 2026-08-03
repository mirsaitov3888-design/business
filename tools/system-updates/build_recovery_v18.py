from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.03.18"
root = Path.cwd()
component_root = root / "tools" / "recovery-v18"

components = {
    "service": component_root / "PortalRecoveryService.php",
    "cli": component_root / "portal_recovery.php",
    "js": component_root / "portal-recovery.js",
}

payload: dict[str, dict[str, str]] = {}
for key, path in components.items():
    raw = path.read_bytes()
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

bad_needle = "$needle = \"    $action = trim((string) ($_GET['action'] ?? 'context'));\";"
good_needle = """$needle = <<<'PHPNEEDLE'
    $action = trim((string) ($_GET['action'] ?? 'context'));
PHPNEEDLE;"""
if run.count(bad_needle) != 1:
    raise SystemExit(
        f"client API needle marker: expected 1, found {run.count(bad_needle)}"
    )
run = run.replace(bad_needle, good_needle, 1)

installer = head.rstrip() + "\n" + run.lstrip()
installer = installer.replace("__RECOVERY_VERSION__", VERSION)
installer = installer.replace("__RECOVERY_PAYLOAD_B64__", payload_b64)
installer = installer.replace("__RECOVERY_PAYLOAD_SHA__", payload_sha)

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(installer.encode("utf-8")).hexdigest())
