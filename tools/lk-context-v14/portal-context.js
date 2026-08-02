
/* LK_CONTEXT_V180214 */
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

    async function request(action, options = {}) {
        const response = await fetch(
            `/portal-context-api.php?action=${encodeURIComponent(action)}`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(options.body
                        ? {'Content-Type': 'application/json'}
                        : {}),
                    ...(options.headers || {})
                },
                ...options
            }
        );
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.error) {
            const error = new Error(data.error || `HTTP ${response.status}`);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function projectOptions(context, clientId) {
        const projects = Array.isArray(context.all_projects)
            ? context.all_projects
            : [];
        return projects.filter(project => {
            const projectClientId = Number(project.client_id || 0);
            return Number(clientId || 0) > 0
                ? projectClientId === Number(clientId)
                : projectClientId === 0;
        });
    }

    function selectedClientName(context) {
        return context.selected_client?.name
            || (context.selected_client_id ? `Клиент #${context.selected_client_id}` : 'Без клиента');
    }

    function selectedProjectName(context) {
        return context.selected_project?.name
            || (context.selected_project_id ? `Проект #${context.selected_project_id}` : 'Проект не выбран');
    }

    function render(context) {
        state.context = context;
        window.PortalProjectContext = {
            current: context,
            clientId: Number(context.selected_client_id || 0),
            projectId: Number(context.selected_project_id || 0),
            siteIds: Array.isArray(context.site_ids) ? context.site_ids : [],
            sites: Array.isArray(context.sites) ? context.sites : []
        };

        let bar = qs('#portalContextBar');
        if (!bar) {
            bar = document.createElement('section');
            bar.id = 'portalContextBar';
            bar.className = 'portal-context-bar';
            const container = qs('.main-content, .content, main, #app') || document.body;
            container.insertBefore(bar, container.firstChild);
        }

        const clients = Array.isArray(context.clients) ? context.clients : [];
        const projects = Array.isArray(context.projects) ? context.projects : [];
        const sites = Array.isArray(context.sites) ? context.sites : [];
        const showClient = Boolean(context.ui?.show_client_selector);
        const showProject = Boolean(context.ui?.show_project_selector);
        const unassignedProjects = (context.all_projects || []).some(
            project => Number(project.client_id || 0) === 0
        );

        const clientControl = showClient
            ? `
                <label class="portal-context-field">
                    <span>Клиент</span>
                    <select id="portalContextClient">
                        ${clients.map(client => `
                            <option value="${Number(client.id)}"
                                ${Number(client.id) === Number(context.selected_client_id) ? 'selected' : ''}>
                                ${escapeHtml(client.name)}
                            </option>
                        `).join('')}
                        ${unassignedProjects ? `
                            <option value="0"
                                ${Number(context.selected_client_id) === 0 ? 'selected' : ''}>
                                Без клиента
                            </option>
                        ` : ''}
                    </select>
                </label>
            `
            : `
                <div class="portal-context-static">
                    <span>Клиент</span>
                    <strong>${escapeHtml(selectedClientName(context))}</strong>
                </div>
            `;

        const projectControl = showProject
            ? `
                <label class="portal-context-field portal-context-project-field">
                    <span>Проект</span>
                    <select id="portalContextProject">
                        ${projects.map(project => `
                            <option value="${Number(project.id)}"
                                ${Number(project.id) === Number(context.selected_project_id) ? 'selected' : ''}>
                                ${escapeHtml(project.name)}
                            </option>
                        `).join('')}
                    </select>
                </label>
            `
            : `
                <div class="portal-context-static portal-context-project-field">
                    <span>Проект</span>
                    <strong>${escapeHtml(selectedProjectName(context))}</strong>
                </div>
            `;

        const siteText = sites.length === 0
            ? 'Сайты пока не добавлены'
            : `${sites.length} ${sites.length === 1 ? 'сайт' : (sites.length < 5 ? 'сайта' : 'сайтов')}`;
        const warning = context.warnings?.project_without_client
            ? '<small class="portal-context-warning">Проект пока не привязан к клиенту</small>'
            : '';

        bar.innerHTML = `
            <div class="portal-context-heading">
                <span class="portal-context-eyebrow">Контекст личного кабинета</span>
                <strong>Данные выбранного проекта</strong>
            </div>
            <div class="portal-context-controls">
                ${clientControl}
                ${projectControl}
                <div class="portal-context-sites" title="Все сайты выбранного проекта">
                    <span>Источники</span>
                    <strong>${escapeHtml(siteText)}</strong>
                    ${warning}
                </div>
            </div>
            <div id="portalContextMessage" class="portal-context-message"></div>
        `;

        qs('#portalContextClient')?.addEventListener('change', async event => {
            const clientId = Number(event.currentTarget.value || 0);
            const available = projectOptions(context, clientId);
            if (available.length === 0) {
                const message = qs('#portalContextMessage');
                message.textContent = 'У выбранного клиента пока нет доступных проектов.';
                message.className = 'portal-context-message is-warning';
                return;
            }
            await selectContext(clientId, Number(available[0].id));
        });

        qs('#portalContextProject')?.addEventListener('change', async event => {
            await selectContext(
                Number(context.selected_client_id || 0),
                Number(event.currentTarget.value || 0)
            );
        });

        document.dispatchEvent(new CustomEvent('portal:context-ready', {
            detail: context
        }));
    }

    async function selectContext(clientId, projectId) {
        if (state.loading || projectId <= 0) return;
        state.loading = true;
        const message = qs('#portalContextMessage');
        if (message) {
            message.textContent = 'Переключение проекта...';
            message.className = 'portal-context-message';
        }
        document.querySelectorAll('#portalContextBar select').forEach(select => {
            select.disabled = true;
        });
        try {
            const result = await request('select', {
                method: 'POST',
                body: JSON.stringify({
                    client_id: clientId,
                    project_id: projectId
                })
            });
            document.dispatchEvent(new CustomEvent('portal:context-changed', {
                detail: result.context
            }));
            window.location.reload();
        } catch (error) {
            state.loading = false;
            document.querySelectorAll('#portalContextBar select').forEach(select => {
                select.disabled = false;
            });
            if (message) {
                message.textContent = error.message;
                message.className = 'portal-context-message is-error';
            }
        }
    }

    async function init() {
        if (window.__portalContextInitialized) return;
        window.__portalContextInitialized = true;
        try {
            const result = await request('context');
            render(result.context);
        } catch (error) {
            if (error.status !== 401 && error.status !== 403) {
                console.error('Portal context:', error);
            }
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
