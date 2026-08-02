from __future__ import annotations

import hashlib
import json
from pathlib import Path

source_path = Path("updates/installers/2026.08.02.4.php")
source = source_path.read_text(encoding="utf-8")

old_check = "    && str_contains($js, 'SYSTEM_UPDATE_HISTORY_SELECTION_V180204')\n"
new_check = "    && str_contains($js, 'SYSTEM_UPDATE_PROGRESS_SELECTION_V180205')\n"
if source.count(old_check) != 1:
    raise SystemExit("Old selection marker was not found exactly once")
source = source.replace(old_check, new_check, 1)

selection_start_marker = (
    "    if (!str_contains($js, 'SYSTEM_UPDATE_HISTORY_SELECTION_V180204')) {"
)
selection_end_marker = (
    "    if (!str_contains($js, 'NAV_RUNTIME_DEDUPE_V180204')) {"
)
selection_start = source.find(selection_start_marker)
selection_end = source.find(selection_end_marker, selection_start)
if selection_start < 0 or selection_end < 0 or selection_end <= selection_start:
    raise SystemExit("Old selection patch block was not found")

selection_replacement = r'''    if (!str_contains($js, 'SYSTEM_UPDATE_PROGRESS_SELECTION_V180205')) {
        $js .= <<<'JSPATCH'

/* SYSTEM_UPDATE_PROGRESS_SELECTION_V180205 */
(() => {
    const installProgressSelectionWrapper = () => {
        if (typeof systemUpdatesRenderProgress !== 'function') return;
        if (systemUpdatesRenderProgress.__selectionWrappedV180205) return;

        const original = systemUpdatesRenderProgress;
        const wrapped = function(rows) {
            const list = Array.isArray(rows) ? [...rows] : [];
            if (!list.length) return original.call(this, list);

            const active = list.find(item => [
                'queued',
                'installing',
                'rollback_queued',
                'rolling_back'
            ].includes(String(item?.status || '')));

            const successful = list.find(item => [
                'installed',
                'rolled_back'
            ].includes(String(item?.status || '')));

            const preferred = active || successful || list[0];
            const ordered = preferred
                ? [preferred, ...list.filter(item => item !== preferred)]
                : list;

            return original.call(this, ordered);
        };

        wrapped.__selectionWrappedV180205 = true;
        try {
            systemUpdatesRenderProgress = wrapped;
        } catch (_) {
        }
    };

    installProgressSelectionWrapper();
    if (document.readyState === 'loading') {
        document.addEventListener(
            'DOMContentLoaded',
            installProgressSelectionWrapper,
            {once: true}
        );
    }
})();
JSPATCH;
    }

'''
source = (
    source[:selection_start]
    + selection_replacement
    + source[selection_end:]
)

header_start_marker = '        $headerNeedle = "            CURLOPT_ENCODING => \'\',\\n";'
header_end_marker = "    }\n\n    $index = r24dedupeNav($index);"
header_start = source.find(header_start_marker)
header_end = source.find(header_end_marker, header_start)
if header_start < 0 or header_end < 0 or header_end <= header_start:
    raise SystemExit("Old HTTP header patch block was not found")
source = source[:header_start] + source[header_end:]

notification_function = r'''
function r25resolveOldUpdateNotifications(string $root): void
{
    try {
        $bootstrap = $root . '/app/bootstrap.php';
        if (!is_file($bootstrap)) {
            return;
        }

        require_once $bootstrap;
        $pdo = \SeoAnalytics\Core\Database::pdo();
        if (!$pdo->query("SHOW TABLES LIKE 'notifications'")->fetchColumn()) {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE notifications
             SET status = 'resolved',
                 resolved_at = COALESCE(resolved_at, NOW())
             WHERE status = 'open'
               AND title LIKE :title_prefix"
        );
        $stmt->execute([
            'title_prefix' => 'Ошибка системного обновления %',
        ]);

        if ($stmt->rowCount() > 0) {
            r24out(
                'Закрыто уведомлений о прошлых ошибках обновления: '
                . $stmt->rowCount() . '.'
            );
        }
    } catch (Throwable $exception) {
        r24out(
            'Предупреждение: старое уведомление не удалось закрыть автоматически: '
            . $exception->getMessage()
        );
    }
}

'''
root_marker = "$root = getcwd() ?: '';"
if source.count(root_marker) != 1:
    raise SystemExit("Root marker was not found exactly once")
source = source.replace(root_marker, notification_function + root_marker, 1)

lint_marker = "    r24lint($indexPath);\n\n"
if source.count(lint_marker) != 1:
    raise SystemExit("Final lint marker was not found exactly once")
source = source.replace(
    lint_marker,
    lint_marker + "    r25resolveOldUpdateNotifications($root);\n\n",
    1,
)

source = source.replace(
    "'/assets/app.js?v=180204'",
    "'/assets/app.js?v=180205'",
)
source = source.replace(
    "Кэш обновлений, старая карточка ошибки и дубли меню исправлены.",
    "Кэш обновлений, верхняя карточка и дубли меню исправлены.",
)
source = source.replace(
    "- старые ошибки остаются только в журнале операций;",
    "- верхняя карточка показывает активную или последнюю успешную операцию;",
)
source = source.replace(
    "Mirsaitov Cumulative Update/2026.08.02.4",
    "Mirsaitov Cumulative Update/2026.08.02.5",
)
source = source.replace(
    "update-cache-navigation-'",
    "update-cache-navigation-v5-'",
)

output_path = Path("updates/installers/2026.08.02.5.php")
output_path.write_text(source, encoding="utf-8")
installer_sha = hashlib.sha256(output_path.read_bytes()).hexdigest()

changes = [
    "Исправление больше не зависит от конкретной строки внутри systemUpdatesRenderProgress",
    "Автоматическая диагностика после обновлений устанавливается накопительно через проверенную версию 2026.08.02.3",
    "Манифест обновлений запрашивается с уникальным URL без CDN-кэша",
    "Верхняя карточка показывает активную или последнюю успешную операцию, а прошлые ошибки остаются в журнале",
    "Повторяющиеся пункты левого меню удаляются из HTML и контролируются в браузере",
    "После успешной установки закрываются открытые уведомления о предыдущих ошибках обновления",
    "Установщик повторно запускается безопасно и создаёт резервную копию изменяемых файлов",
]

release = {
    "version": "2026.08.02.5",
    "title": "Стабилизация обновлений и восстановление интерфейса",
    "released_at": "2026-08-02",
    "installer_path": "updates/installers/2026.08.02.5.php",
    "changelog": changes,
}
manifest = {
    "channel": "stable",
    "latest": {
        "version": release["version"],
        "title": release["title"],
        "released_at": release["released_at"],
        "changelog": changes,
        "installer_url": (
            "https://raw.githubusercontent.com/mirsaitov3888-design/business/"
            "main/updates/installers/2026.08.02.5.php"
        ),
        "sha256": installer_sha,
    },
}

Path("updates/release.json").write_text(
    json.dumps(release, ensure_ascii=False, indent=2) + "\n",
    encoding="utf-8",
)
Path("updates/manifest.json").write_text(
    json.dumps(manifest, ensure_ascii=False, indent=2) + "\n",
    encoding="utf-8",
)

print(f"installer_sha256={installer_sha}")
