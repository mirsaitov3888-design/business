from __future__ import annotations

import hashlib
import json
from pathlib import Path

VERSION = "2026.08.02.16"
root = Path.cwd()
component_root = root / "tools" / "lk2-v16"

component_paths = {
    "service": component_root / "ClientStructureService.php",
    "api": component_root / "client-structure-api.php",
    "js": component_root / "client-structure.js",
    "css": component_root / "client-structure.css",
    "schema": component_root / "schema.sqlfrag",
}

hashes = {
    key: hashlib.sha256(path.read_bytes()).hexdigest()
    for key, path in component_paths.items()
}

head = (component_root / "installer-head.phpfrag").read_text(encoding="utf-8")
run = (component_root / "installer-run.phpfrag").read_text(encoding="utf-8")
template = head.rstrip() + "\n" + run.lstrip()
template = template.replace("__LK2_VERSION__", VERSION)
template = template.replace(
    "__LK2_HASHES_JSON__",
    json.dumps(hashes, ensure_ascii=False, separators=(",", ":")),
)

output = root / "updates" / "installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(template, encoding="utf-8")
print(hashlib.sha256(template.encode("utf-8")).hexdigest())
