
/* LK3_PROJECT_SOURCES_V180217 */
(() => {
    'use strict';

    const state = {
        context: null,
        loading: false
    };

    const qs = (selector, root = document) => root.querySelector(selector);

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }

    async function request() {
        const response = await fetch('/project-sources-api.php?action=context', {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {'X-Requested-With': 'XMLHttpRequest'}
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.error) {
            const error = new Error(data.error || `HTTP ${response.status}`);
            error.status = response.status;
            throw error;
        }
        return data.context || {};
    }

    function sourceBadge(label, values, className) {
        const list = Array.isArray(values) ? values : [];
        const title = list.length ? list.join(', ') : 'не настроено';
        return `
            <span class="lk3-source-badge ${list.length ? 'is-ready' : 'is-empty'} ${className}"
                  title="${escapeHtml(title)}">
                ${escapeHtml(label)}: ${list.length}
            </span>
        `;
    }

    function render(context) {
        state.context = context;
        window.ProjectSourceScope = {
            projectId: Number(context.selected_project_id || 0),
            clientId: Number(context.selected_client_id || 0),
            sites: Array.isArray(context.sites) ? context.sites : [],
            manifest: Array.isArray(context.source_manifest)
                ? context.source_manifest
                : [],
            reportScopes: Array.isArray(context.report_scopes)
                ? context.report_scopes
                : [],
            goalScopes: Array.isArray(context.goal_scopes)
                ? context.goal_scopes
                : []
        };

        let root = qs('#lk3ProjectSources');
        if (!root) {
            root = document.createElement('section');
            root.id = 'lk3ProjectSources';
            root.className = 'lk3-project-sources';
            const anchor = qs('#portalContextBar');
            if (anchor?.parentNode) {
                anchor.parentNode.insertBefore(root, anchor.nextSibling);
            } else {
                const container = qs('.main-content, .content, main, #app') || document.body;
                container.insertBefore(root, container.firstChild);
            }
        }

        const manifest = Array.isArray(context.source_manifest)
            ? context.source_manifest
            : [];
        const readySites = manifest.filter(site => site.ready).length;
        const projectName = context.selected_project?.name
            || context.selected_project?.title
            || `Проект #${Number(context.selected_project_id || 0)}`;

        root.innerHTML = `
            <div class="lk3-project-sources__head">
                <div>
                    <span>Источники выбранного проекта</span>
                    <strong>${escapeHtml(projectName)}</strong>
                </div>
                <small>${readySites} из ${manifest.length} сайтов имеют подключённые данные</small>
            </div>
            <div class="lk3-project-sources__sites">
                ${manifest.length ? manifest.map(site => `
                    <article class="lk3-source-site" data-site-id="${Number(site.site_id || 0)}">
                        <div class="lk3-source-site__name">
                            <strong>${escapeHtml(site.site_name || site.site_url)}</strong>
                            <small>${escapeHtml(site.site_url || '')}</small>
                        </div>
                        <div class="lk3-source-site__badges">
                            ${sourceBadge('Метрика', site.metrika_counter_ids, 'is-metrika')}
                            ${sourceBadge('Вебмастер', site.webmaster_host_ids, 'is-webmaster')}
                            ${sourceBadge(
                                'Другие',
                                (site.sources || []).map(source => source.source_type),
                                'is-other'
                            )}
                        </div>
                    </article>
                `).join('') : `
                    <div class="lk3-project-sources__empty">
                        У выбранного проекта пока нет активных сайтов.
                    </div>
                `}
            </div>
        `;

        document.dispatchEvent(new CustomEvent('project:sources-ready', {
            detail: window.ProjectSourceScope
        }));
    }

    async function load() {
        if (state.loading) return;
        state.loading = true;
        try {
            render(await request());
        } catch (error) {
            if (error.status !== 401 && error.status !== 403) {
                console.error('Project sources:', error);
            }
        } finally {
            state.loading = false;
        }
    }

    document.addEventListener('portal:context-ready', load);
    document.addEventListener('portal:context-changed', load);
    document.addEventListener('DOMContentLoaded', load, {once: true});
    if (document.readyState !== 'loading') {
        load();
    }
})();
