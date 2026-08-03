from __future__ import annotations

import base64
import hashlib
from pathlib import Path

VERSION = "2026.08.03.22"
root = Path.cwd()
component_root = root / "tools" / "navigation-v22"

navigation_js = (component_root / "navigation-state.js").read_bytes()
payload_b64 = base64.b64encode(navigation_js).decode("ascii")
payload_sha = hashlib.sha256(navigation_js).hexdigest()

head = (component_root / "installer-head.phpfrag").read_text(encoding="utf-8")
run = (component_root / "installer-run.phpfrag").read_text(encoding="utf-8")
installer = head.rstrip() + "\n" + run.lstrip()
installer = installer.replace("__NAVIGATION_STATE_VERSION__", VERSION)
installer = installer.replace("__NAVIGATION_STATE_PAYLOAD_B64__", payload_b64)
installer = installer.replace("__NAVIGATION_STATE_PAYLOAD_SHA__", payload_sha)

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(installer.encode("utf-8")).hexdigest())
