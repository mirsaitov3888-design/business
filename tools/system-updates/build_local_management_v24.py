from __future__ import annotations

import base64
import hashlib
import json
from pathlib import Path

VERSION = "2026.08.03.24"
root = Path.cwd()
component_root = root / "tools" / "local-management-v24"


def block(text: str, start: str, end: str) -> str:
    start_pos = text.find(start)
    end_pos = text.find(end, start_pos + len(start))
    if start_pos < 0 or end_pos < 0:
        raise SystemExit(f"Cannot extract block: {start!r} -> {end!r}")
    return text[start_pos:end_pos]


p1_source = (root / "tools/p1-step1/p1-sales.js").read_text(encoding="utf-8")
old_navigation = block(
    p1_source,
    "    function ensureNavigation() {",
    "    function ensureSection() {",
)
new_navigation = r'''    /* FINANCE_MENU_BOOTSTRAP_V180324 */
    function financeNavigationRoots() {
        const candidates = Array.from(document.querySelectorAll(
            '.sidebar-nav, .sidebar-menu, .nav-menu, aside nav, .sidebar'
        )).filter(root => root.querySelector('[data-section]'));
        const visible = candidates.filter(root =>
            root.getClientRects().length > 0
            && getComputedStyle(root).display !== 'none'
            && getComputedStyle(root).visibility !== 'hidden'
        );
        return visible.length ? visible : candidates.slice(0, 1);
    }

    function ensureNavigation() {
        financeNavigationRoots().forEach(root => {
            let button = root.querySelector('[data-section="p1-sales"]');
            if (!button) {
                const reports = root.querySelector('[data-section="reports"]');
                button = reports
                    ? reports.cloneNode(true)
                    : document.createElement('button');
                button.removeAttribute('id');
                button.type = 'button';
                button.classList.add('nav-link');
                button.dataset.section = 'p1-sales';
                if (reports?.parentElement === root) {
                    reports.insertAdjacentElement('afterend', button);
                } else {
                    root.append(button);
                }
            }
            const textNode = button.querySelector('.nav-text, [data-nav-text], span:last-child');
            if (textNode) {
                textNode.textContent = 'Финансы и экономика';
            } else {
                button.textContent = 'Финансы и экономика';
            }
            if (button.dataset.p1SalesBound === '1') return;
            button.dataset.p1SalesBound = '1';
            button.addEventListener('click', () => {
                if (typeof showSection === 'function') {
                    showSection('p1-sales');
                } else {
                    document.querySelectorAll('.section').forEach(section => {
                        section.classList.toggle('active', section.id === 'section-p1-sales');
                    });
                }
                if (!window.__p1SalesInitialized) {
                    init();
                } else {
                    loadData();
                }
            });
        });
    }

'''

old_init = block(
    p1_source,
    "    async function init() {",
    "        fillOptions();",
) + "        fillOptions();"
new_init = r'''    async function init() {
        if (window.__p1SalesInitialized || window.__p1SalesInitializing) return;
        window.__p1SalesInitializing = true;
        ensureNavigation();
        ensureSection();
        try {
            state.context = await request('context');
        } catch (error) {
            window.__p1SalesInitializing = false;
            window.__p1SalesInitialized = false;
            const message = qs('#p1Message');
            if (message) {
                message.className = 'alert alert-warning';
                message.textContent = error.message
                    || 'Выберите клиента и проект, затем откройте раздел повторно.';
            }
            return;
        }
        window.__p1SalesInitialized = true;
        window.__p1SalesInitializing = false;
        fillOptions();
'''

nav_source = (root / "tools/navigation-v22/navigation-state.js").read_text(encoding="utf-8")
old_nav_roots = block(
    nav_source,
    "    function navigationRoots() {",
    "    function navigationRoot() {",
)
new_nav_roots = r'''    function navigationRoots() {
        const candidates = qsa('.sidebar-nav, .sidebar-menu, .nav-menu, aside nav, .sidebar')
            .filter((node, index, rows) =>
                rows.indexOf(node) === index
                && node.querySelector('[data-section]')
            );
        const visible = candidates.filter(node =>
            node.getClientRects().length > 0
            && getComputedStyle(node).display !== 'none'
            && getComputedStyle(node).visibility !== 'hidden'
        );
        return (visible.length ? visible : candidates)
            .sort((a, b) =>
                b.querySelectorAll('[data-section]').length
                - a.querySelectorAll('[data-section]').length
            );
    }

'''

bitrix_source = (root / "tools/bitrix24-step1/Bitrix24Client.php").read_text(encoding="utf-8")
old_bitrix_call = """    public function call(string $method, array $params = []): array
    {
        $url = $this->webhookBase
"""
new_bitrix_call = """    public function call(string $method, array $params = []): array
    {
        Bitrix24SafetyPolicy::assertAllowed($method, $params);
        $url = $this->webhookBase
"""
if bitrix_source.count(old_bitrix_call) != 1:
    raise SystemExit("Bitrix call marker changed")

components: dict[str, bytes] = {
    "policy": (component_root / "Bitrix24SafetyPolicy.php").read_bytes(),
    "service": (component_root / "LocalStructureAdminService.php").read_bytes(),
    "api": (component_root / "local-structure-api.php").read_bytes(),
    "js": (component_root / "local-structure-admin.js").read_bytes(),
    "css": (component_root / "local-structure-admin.css").read_bytes(),
    "schema": (component_root / "schema.sqlfrag").read_bytes(),
    "old_p1_navigation": old_navigation.encode(),
    "new_p1_navigation": new_navigation.encode(),
    "old_p1_init": old_init.encode(),
    "new_p1_init": new_init.encode(),
    "old_nav_roots": old_nav_roots.encode(),
    "new_nav_roots": new_nav_roots.encode(),
    "old_bitrix_call": old_bitrix_call.encode(),
    "new_bitrix_call": new_bitrix_call.encode(),
}

payload: dict[str, dict[str, str]] = {}
for key, raw in components.items():
    payload[key] = {
        "sha256": hashlib.sha256(raw).hexdigest(),
        "content": base64.b64encode(raw).decode("ascii"),
    }

payload_json = json.dumps(payload, ensure_ascii=False, separators=(",", ":")).encode()
payload_b64 = base64.b64encode(payload_json).decode("ascii")
payload_sha = hashlib.sha256(payload_json).hexdigest()

head = (component_root / "installer-head.phpfrag").read_text(encoding="utf-8")
run = (component_root / "installer-run.phpfrag").read_text(encoding="utf-8")

old_run_patch = block(
    run,
    "    $appJs = $appJsBefore;",
    "    $appCss = lm24read($paths['app_css']);",
)
new_run_patch = r'''    $appJs = $appJsBefore;
    if (!str_contains($appJs, FINANCE_MENU_MARKER)) {
        $p1Anchor = strpos($appJs, '/p1-api.php?action=');
        if ($p1Anchor === false) {
            $p1Anchor = strpos($appJs, 'p1-api.php?action=');
        }
        $p1Start = false;
        if ($p1Anchor !== false) {
            $prefix = substr($appJs, 0, $p1Anchor);
            $p1Start = strrpos($prefix, "\n(() => {");
            if ($p1Start !== false) {
                $p1Start++;
            } else {
                $p1Start = strrpos($prefix, '(() => {');
            }
        }
        $p1End = $p1Anchor === false
            ? false
            : strpos($appJs, "\n})();", $p1Anchor);
        if ($p1Start === false || $p1End === false || $p1Start >= $p1Anchor) {
            throw new RuntimeException('Не найден ограниченный блок P1 по API-маркеру.');
        }
        $p1End += strlen("\n})();");
        $p1Segment = substr($appJs, $p1Start, $p1End - $p1Start);

        $count = 0;
        $p1Segment = preg_replace(
            '#    function ensureNavigation\(\) \{.*?(?=    function ensureSection\(\) \{)#s',
            $components['new_p1_navigation'],
            $p1Segment,
            1,
            $count
        );
        if (!is_string($p1Segment) || $count !== 1) {
            throw new RuntimeException('Не удалось исправить навигацию финансов внутри P1.');
        }

        $count = 0;
        $p1Segment = preg_replace(
            "#    async function init\\(\\) \\{.*?(?=        qs\\('#p1ProjectName'\\))#s",
            $components['new_p1_init'],
            $p1Segment,
            1,
            $count
        );
        if (!is_string($p1Segment) || $count !== 1) {
            throw new RuntimeException('Не удалось исправить инициализацию финансов внутри P1.');
        }
        $appJs = substr($appJs, 0, $p1Start)
            . $p1Segment
            . substr($appJs, $p1End);

        $navStart = strpos($appJs, '/* PORTAL_NAVIGATION_STATE_V180322 */');
        $navEnd = $navStart === false
            ? false
            : strpos($appJs, "\n})();", $navStart);
        if ($navStart === false || $navEnd === false) {
            throw new RuntimeException('Не найден ограниченный блок единой навигации.');
        }
        $navEnd += strlen("\n})();");
        $navSegment = substr($appJs, $navStart, $navEnd - $navStart);
        $count = 0;
        $navSegment = preg_replace(
            '#    function navigationRoots\(\) \{.*?(?=    function navigationRoot\(\) \{)#s',
            $components['new_nav_roots'],
            $navSegment,
            1,
            $count
        );
        if (!is_string($navSegment) || $count !== 1) {
            throw new RuntimeException('Не удалось переключить навигацию на видимое меню.');
        }
        $appJs = substr($appJs, 0, $navStart)
            . $navSegment
            . substr($appJs, $navEnd);
    }
    $appJs = str_replace('Продажи и экономика', 'Финансы и экономика', $appJs);
    if (!str_contains($appJs, LOCAL_MANAGEMENT_MARKER)) {
        $appJs = rtrim($appJs) . PHP_EOL . PHP_EOL
            . trim($components['js']) . PHP_EOL;
    }
    lm24write($paths['app_js'], $appJs);

'''
if old_run_patch not in run:
    raise SystemExit("Installer app.js patch block changed")
run = run.replace(old_run_patch, new_run_patch, 1)

installer = head.rstrip() + "\n" + run.lstrip()
installer = installer.replace("__LOCAL_MANAGEMENT_VERSION__", VERSION)
installer = installer.replace("__LOCAL_MANAGEMENT_PAYLOAD_B64__", payload_b64)
installer = installer.replace("__LOCAL_MANAGEMENT_PAYLOAD_SHA__", payload_sha)

output = root / "updates/installers" / f"{VERSION}.php"
output.parent.mkdir(parents=True, exist_ok=True)
output.write_text(installer, encoding="utf-8")
print(hashlib.sha256(installer.encode()).hexdigest())
