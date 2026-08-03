from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.03.23"
root = Path.cwd()
component_root = root / "tools" / "site-onboarding-v23"

components: dict[str, bytes] = {
    "service": (component_root / "SiteOnboardingService.php").read_bytes(),
    "api": (component_root / "site-onboarding-api.php").read_bytes(),
    "js": (component_root / "site-onboarding.js").read_bytes(),
    "css": (component_root / "site-onboarding.css").read_bytes(),
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
installer = installer.replace("__SITE_ONBOARDING_VERSION__", VERSION)
installer = installer.replace("__SITE_ONBOARDING_PAYLOAD_B64__", payload_b64)
installer = installer.replace("__SITE_ONBOARDING_PAYLOAD_SHA__", payload_sha)

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(installer.encode("utf-8")).hexdigest())
