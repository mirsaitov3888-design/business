
/* LK2_CLIENT_STRUCTURE_V180216 */
(() => {
    'use strict';

    const state = {
        context: null,
        selectedClientId: 0,
        detail: null,
        loading: false
    };

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }

    function textList(value) {
        return Array.isArray(value) ? value.join(', ') : '';
    }

    async function api(action, options = {}) {
        const response = await fetch(
            `/client-structure-api.php?action=${encodeURIComponent(action)}`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(options.body ? {'Content-Type': 'application/json'} : {}),
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

    function ensureMenuButton() {
        let button = qs('[data-section="clients"]');
        if (button) return button;
        const navigation = qs('.sidebar-nav, .sidebar-menu, .nav-menu, aside nav, .sidebar');
        if (!navigation) return null;
        button = document.createElement('button');
        button.type = 'button';
        button.className = 'nav-link lk2-clients-nav';
        button.dataset.section = 'clients';
        button.textContent = 'Клиенты';
        navigation.appendChild(button);
        return button;
    }

    function ensureSection() {
        let section = qs('#section-clients');
        if (!section) {
            section = document.createElement('section');
            section.id = 'section-clients';
            section.className = 'section lk2-section';
            const container = qs('.main-content, .content, main, #app') || document.body;
            container.appendChild(section);
        }
        if (!qs('#lk2ClientStructure', section)) {
            section.innerHTML = '<div id="lk2ClientStructure" class="lk2-client-structure"></div>';
        }
        return section;
    }

    function activateSection() {
        try {
            if (typeof window.showSection === 'function') {
                window.showSection('clients');
            }
        } catch (_) {
        }
        const section = ensureSection();
        qsa('.section').forEach(item => {
            if (item !== section) item.classList.remove('active');
        });
        section.classList.add('active');
        section.hidden = false;
        qsa('[data-section]').forEach(button => {
            button.classList.toggle('active', button.dataset.section === 'clients');
        });
        init().catch(showError);
    }

    function bindNavigation() {
        const button = ensureMenuButton();
        if (!button || button.dataset.lk2Bound === '1') return;
        button.dataset.lk2Bound = '1';
        button.addEventListener('click', () => setTimeout(activateSection, 0));
    }

    function permission(name) {
        return Boolean(state.context?.permissions?.[name]);
    }

    function renderLayout() {
        const root = qs('#lk2ClientStructure');
        if (!root) return;
        root.innerHTML = `
            <header class="lk2-page-header">
                <div>
                    <span class="lk2-eyebrow">Личный кабинет</span>
                    <h1>Клиенты, проекты и сайты</h1>
                    <p>Единая структура данных, которую используют все разделы портала.</p>
                </div>
                ${permission('create_client') ? '<button type="button" class="btn btn-primary" data-lk2-action="new-client">Добавить клиента</button>' : ''}
            </header>
            <div id="lk2Message" class="lk2-message"></div>
            <div class="lk2-layout">
                <aside class="lk2-client-list-panel">
                    <div class="lk2-panel-heading">
                        <strong>Клиенты</strong>
                        <span>${state.context.clients.length}</span>
                    </div>
                    <label class="lk2-search">
                        <span>Поиск</span>
                        <input id="lk2ClientSearch" type="search" placeholder="Название или контакт">
                    </label>
                    <div id="lk2ClientList" class="lk2-client-list"></div>
                </aside>
                <main id="lk2ClientDetail" class="lk2-detail-panel">
                    <div class="lk2-empty">
                        <strong>Выберите клиента</strong>
                        <p>Здесь появятся контакты, проекты и сайты.</p>
                    </div>
                </main>
            </div>
            <div id="lk2ModalRoot"></div>
        `;
        renderClientList();
        qs('#lk2ClientSearch')?.addEventListener('input', renderClientList);
        root.addEventListener('click', handleClick);
        root.addEventListener('submit', handleSubmit);
    }

    function renderClientList() {
        const container = qs('#lk2ClientList');
        if (!container || !state.context) return;
        const query = (qs('#lk2ClientSearch')?.value || '').trim().toLowerCase();
        const rows = state.context.clients.filter(client => {
            if (!query) return true;
            return [client.name, client.contact_name, client.contact_email, client.contact_phone]
                .some(value => String(value || '').toLowerCase().includes(query));
        });
        if (rows.length === 0) {
            container.innerHTML = '<div class="lk2-list-empty">Клиенты не найдены.</div>';
            return;
        }
        container.innerHTML = rows.map(client => `
            <button type="button"
                    class="lk2-client-card ${Number(client.id) === state.selectedClientId ? 'is-active' : ''}"
                    data-lk2-action="select-client"
                    data-client-id="${Number(client.id)}">
                <span class="lk2-client-card-title">${escapeHtml(client.name)}</span>
                <span class="lk2-client-card-meta">
                    ${Number(client.project_count || 0)} проектов · ${Number(client.site_count || 0)} сайтов
                </span>
                <span class="lk2-status lk2-status-${escapeHtml(client.status || 'active')}">
                    ${client.status === 'archived' ? 'Архив' : (client.status === 'paused' ? 'Пауза' : 'Активен')}
                </span>
            </button>
        `).join('');
    }

    async function selectClient(clientId) {
        state.selectedClientId = Number(clientId || 0);
        renderClientList();
        const detail = qs('#lk2ClientDetail');
        if (detail) detail.innerHTML = '<div class="lk2-loading">Загружаем карточку клиента…</div>';
        try {
            const result = await api('client&client_id=' + encodeURIComponent(state.selectedClientId));
            state.detail = result.data;
            renderClientDetail();
        } catch (error) {
            showError(error);
        }
    }

    function renderClientDetail() {
        const container = qs('#lk2ClientDetail');
        if (!container || !state.detail) return;
        const {client, client_users: clientUsers, projects} = state.detail;
        const canEdit = permission('edit_assigned') && state.context.user.role !== 'client';
        const canManageUsers = permission('manage_client_users');
        const userNames = clientUsers.length
            ? clientUsers.map(user => escapeHtml(user.name || user.email)).join(', ')
            : 'Не назначены';

        container.innerHTML = `
            <section class="lk2-client-header-card">
                <div>
                    <span class="lk2-eyebrow">Карточка клиента</span>
                    <h2>${escapeHtml(client.name)}</h2>
                    <p>${escapeHtml(client.contact_name || 'Контактное лицо не указано')}</p>
                </div>
                <div class="lk2-actions">
                    ${canManageUsers ? '<button type="button" class="btn btn-secondary" data-lk2-action="edit-users">Пользователи ЛК</button>' : ''}
                    ${canEdit ? '<button type="button" class="btn btn-primary" data-lk2-action="edit-client">Редактировать</button>' : ''}
                </div>
            </section>
            <section class="lk2-info-grid">
                <article><span>Email</span><strong>${escapeHtml(client.contact_email || '—')}</strong></article>
                <article><span>Телефон</span><strong>${escapeHtml(client.contact_phone || '—')}</strong></article>
                <article><span>Менеджер</span><strong>${escapeHtml(client.manager_name || 'Не назначен')}</strong></article>
                <article><span>Пользователи ЛК</span><strong>${userNames}</strong></article>
            </section>
            ${client.notes ? `<section class="lk2-note"><span>Комментарий</span><p>${escapeHtml(client.notes)}</p></section>` : ''}
            <section class="lk2-projects-section">
                <div class="lk2-section-heading">
                    <div>
                        <span class="lk2-eyebrow">Структура клиента</span>
                        <h3>Проекты и сайты</h3>
                    </div>
                    ${canEdit ? '<button type="button" class="btn btn-primary" data-lk2-action="new-project">Добавить проект</button>' : ''}
                </div>
                <div class="lk2-project-list">
                    ${projects.length ? projects.map(project => projectCard(project, canEdit)).join('') : `
                        <div class="lk2-empty-card">
                            <strong>Проекты пока не добавлены</strong>
                            <p>Создайте первый проект и добавьте к нему один или несколько сайтов.</p>
                        </div>
                    `}
                </div>
            </section>
        `;
    }

    function projectCard(project, canEdit) {
        const sites = Array.isArray(project.sites) ? project.sites : [];
        return `
            <article class="lk2-project-card" data-project-id="${Number(project.id)}">
                <header class="lk2-project-header">
                    <div>
                        <span class="lk2-status lk2-status-${escapeHtml(project.status || 'active')}">
                            ${project.status === 'archived' ? 'Архив' : (project.status === 'paused' ? 'Пауза' : 'Активен')}
                        </span>
                        <h4>${escapeHtml(project.name)}</h4>
                        ${project.description ? `<p>${escapeHtml(project.description)}</p>` : ''}
                    </div>
                    ${canEdit ? `
                        <div class="lk2-actions">
                            <button type="button" class="btn btn-secondary" data-lk2-action="edit-project" data-project-id="${Number(project.id)}">Изменить</button>
                            <button type="button" class="btn btn-secondary" data-lk2-action="new-site" data-project-id="${Number(project.id)}">Добавить сайт</button>
                            ${project.status !== 'archived' ? `<button type="button" class="btn btn-danger-soft" data-lk2-action="archive-project" data-project-id="${Number(project.id)}">В архив</button>` : ''}
                        </div>
                    ` : ''}
                </header>
                <div class="lk2-sites">
                    ${sites.length ? sites.map((site, index) => siteRow(project, site, index, sites.length, canEdit)).join('') : `
                        <div class="lk2-sites-empty">К проекту пока не привязаны сайты.</div>
                    `}
                </div>
            </article>
        `;
    }

    function siteRow(project, site, index, count, canEdit) {
        return `
            <div class="lk2-site-row ${site.status === 'archived' ? 'is-archived' : ''}" data-site-id="${Number(site.id)}">
                <div class="lk2-site-main">
                    <strong>${escapeHtml(site.name)}</strong>
                    <a href="${escapeHtml(site.url)}" target="_blank" rel="noopener noreferrer">${escapeHtml(site.host || site.url)}</a>
                </div>
                <div class="lk2-site-sources">
                    <span>Метрика: <strong>${escapeHtml(textList(site.metrika_counter_ids) || 'не подключена')}</strong></span>
                    <span>Вебмастер: <strong>${escapeHtml(textList(site.webmaster_host_ids) || 'не подключён')}</strong></span>
                </div>
                ${canEdit ? `
                    <div class="lk2-site-actions">
                        <button type="button" class="lk2-icon-button" title="Выше" data-lk2-action="move-site" data-direction="up" data-project-id="${Number(project.id)}" data-site-id="${Number(site.id)}" ${index === 0 ? 'disabled' : ''}>↑</button>
                        <button type="button" class="lk2-icon-button" title="Ниже" data-lk2-action="move-site" data-direction="down" data-project-id="${Number(project.id)}" data-site-id="${Number(site.id)}" ${index === count - 1 ? 'disabled' : ''}>↓</button>
                        <button type="button" class="btn btn-secondary" data-lk2-action="edit-site" data-project-id="${Number(project.id)}" data-site-id="${Number(site.id)}">Изменить</button>
                        ${site.status !== 'archived' ? `<button type="button" class="btn btn-danger-soft" data-lk2-action="archive-site" data-project-id="${Number(project.id)}" data-site-id="${Number(site.id)}">В архив</button>` : ''}
                    </div>
                ` : ''}
            </div>
        `;
    }

    function modal(title, body, formId) {
        const root = qs('#lk2ModalRoot');
        if (!root) return;
        root.innerHTML = `
            <div class="lk2-modal-backdrop" data-lk2-action="close-modal">
                <section class="lk2-modal" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}" onclick="event.stopPropagation()">
                    <header>
                        <h3>${escapeHtml(title)}</h3>
                        <button type="button" class="lk2-modal-close" data-lk2-action="close-modal">×</button>
                    </header>
                    <form id="${formId}" class="lk2-form">
                        ${body}
                        <footer>
                            <button type="button" class="btn btn-secondary" data-lk2-action="close-modal">Отмена</button>
                            <button type="submit" class="btn btn-primary">Сохранить</button>
                        </footer>
                    </form>
                </section>
            </div>
        `;
        qs(`#${formId} input, #${formId} select, #${formId} textarea`)?.focus();
    }

    function clientForm(client = {}) {
        const managerOptions = state.context.managers.map(manager => `
            <option value="${Number(manager.id)}" ${Number(client.manager_user_id || 0) === Number(manager.id) ? 'selected' : ''}>
                ${escapeHtml(manager.name)} · ${escapeHtml(manager.role)}
            </option>
        `).join('');
        modal(client.id ? 'Редактирование клиента' : 'Новый клиент', `
            <input type="hidden" name="id" value="${Number(client.id || 0)}">
            <label><span>Название клиента</span><input name="name" required maxlength="255" value="${escapeHtml(client.name || '')}"></label>
            <div class="lk2-form-grid">
                <label><span>Статус</span><select name="status">
                    <option value="active" ${client.status === 'active' ? 'selected' : ''}>Активен</option>
                    <option value="paused" ${client.status === 'paused' ? 'selected' : ''}>Пауза</option>
                    <option value="archived" ${client.status === 'archived' ? 'selected' : ''}>Архив</option>
                </select></label>
                <label><span>Ответственный менеджер</span><select name="manager_user_id"><option value="0">Не назначен</option>${managerOptions}</select></label>
            </div>
            <label><span>Контактное лицо</span><input name="contact_name" maxlength="255" value="${escapeHtml(client.contact_name || '')}"></label>
            <div class="lk2-form-grid">
                <label><span>Email</span><input name="contact_email" type="email" maxlength="255" value="${escapeHtml(client.contact_email || '')}"></label>
                <label><span>Телефон</span><input name="contact_phone" maxlength="100" value="${escapeHtml(client.contact_phone || '')}"></label>
            </div>
            <label><span>Комментарий</span><textarea name="notes" rows="4">${escapeHtml(client.notes || '')}</textarea></label>
        `, 'lk2ClientForm');
    }

    function usersForm() {
        const selected = new Set((state.detail.client_users || []).map(user => Number(user.id)));
        const options = state.context.client_users.length
            ? state.context.client_users.map(user => `
                <label class="lk2-check-row">
                    <input type="checkbox" name="user_ids" value="${Number(user.id)}" ${selected.has(Number(user.id)) ? 'checked' : ''}>
                    <span><strong>${escapeHtml(user.name)}</strong><small>${escapeHtml(user.email)}</small></span>
                </label>
            `).join('')
            : '<p>Пользователи с ролью «Клиент» ещё не созданы.</p>';
        modal('Пользователи клиентского кабинета', `
            <input type="hidden" name="client_id" value="${Number(state.detail.client.id)}">
            <div class="lk2-check-list">${options}</div>
        `, 'lk2UsersForm');
    }

    function projectForm(project = {}) {
        modal(project.id ? 'Редактирование проекта' : 'Новый проект', `
            <input type="hidden" name="id" value="${Number(project.id || 0)}">
            <input type="hidden" name="client_id" value="${Number(state.detail.client.id)}">
            <label><span>Название проекта</span><input name="name" required maxlength="190" value="${escapeHtml(project.name || '')}" placeholder="Например, Основной сайт или HR"></label>
            <div class="lk2-form-grid">
                <label><span>Статус</span><select name="status">
                    <option value="active" ${project.status === 'active' ? 'selected' : ''}>Активен</option>
                    <option value="paused" ${project.status === 'paused' ? 'selected' : ''}>Пауза</option>
                    <option value="archived" ${project.status === 'archived' ? 'selected' : ''}>Архив</option>
                </select></label>
                <label><span>Порядок</span><input name="sort_order" type="number" min="0" value="${Number(project.sort_order || 0)}"></label>
            </div>
            <label><span>Описание</span><textarea name="description" rows="4">${escapeHtml(project.description || '')}</textarea></label>
        `, 'lk2ProjectForm');
    }

    function siteForm(project, site = {}) {
        modal(site.id ? 'Редактирование сайта' : 'Новый сайт проекта', `
            <input type="hidden" name="id" value="${Number(site.id || 0)}">
            <input type="hidden" name="client_id" value="${Number(state.detail.client.id)}">
            <input type="hidden" name="project_id" value="${Number(project.id)}">
            <label><span>Название сайта</span><input name="name" required maxlength="190" value="${escapeHtml(site.name || '')}"></label>
            <label><span>URL</span><input name="url" required maxlength="1000" value="${escapeHtml(site.url || '')}" placeholder="https://example.ru"></label>
            <div class="lk2-form-grid">
                <label><span>Статус</span><select name="status">
                    <option value="active" ${site.status === 'active' ? 'selected' : ''}>Активен</option>
                    <option value="paused" ${site.status === 'paused' ? 'selected' : ''}>Пауза</option>
                    <option value="archived" ${site.status === 'archived' ? 'selected' : ''}>Архив</option>
                </select></label>
                <label><span>Порядок</span><input name="sort_order" type="number" min="0" value="${Number(site.sort_order || 0)}"></label>
            </div>
            <label><span>ID счётчиков Яндекс Метрики</span><input name="metrika_counter_ids" value="${escapeHtml(textList(site.metrika_counter_ids))}" placeholder="12345678, 87654321"><small>Несколько ID можно указать через запятую.</small></label>
            <label><span>ID сайтов Яндекс Вебмастера</span><textarea name="webmaster_host_ids" rows="3" placeholder="https:example.ru:443">${escapeHtml(textList(site.webmaster_host_ids))}</textarea></label>
            <label><span>Комментарий</span><textarea name="notes" rows="3">${escapeHtml(site.notes || '')}</textarea></label>
        `, 'lk2SiteForm');
    }

    function formDataObject(form) {
        const data = Object.fromEntries(new FormData(form).entries());
        ['id', 'client_id', 'project_id', 'manager_user_id', 'sort_order'].forEach(key => {
            if (key in data) data[key] = Number(data[key] || 0);
        });
        return data;
    }

    async function handleSubmit(event) {
        event.preventDefault();
        const form = event.target;
        try {
            if (form.id === 'lk2ClientForm') {
                const result = await api('save_client', {method: 'POST', body: JSON.stringify(formDataObject(form))});
                closeModal();
                await reloadContext(Number(result.id));
                showMessage(result.message);
            } else if (form.id === 'lk2UsersForm') {
                const input = new FormData(form);
                const payload = {
                    client_id: Number(input.get('client_id') || 0),
                    user_ids: input.getAll('user_ids').map(Number)
                };
                const result = await api('save_client_users', {method: 'POST', body: JSON.stringify(payload)});
                closeModal();
                await selectClient(payload.client_id);
                showMessage(result.message);
            } else if (form.id === 'lk2ProjectForm') {
                const payload = formDataObject(form);
                const result = await api('save_project', {method: 'POST', body: JSON.stringify(payload)});
                closeModal();
                await selectClient(payload.client_id);
                showMessage(result.message);
            } else if (form.id === 'lk2SiteForm') {
                const payload = formDataObject(form);
                const result = await api('save_site', {method: 'POST', body: JSON.stringify(payload)});
                closeModal();
                await selectClient(payload.client_id);
                showMessage(result.message);
            }
        } catch (error) {
            showError(error);
        }
    }

    async function handleClick(event) {
        const target = event.target.closest('[data-lk2-action]');
        if (!target) return;
        const action = target.dataset.lk2Action;
        if (action === 'select-client') return selectClient(target.dataset.clientId);
        if (action === 'new-client') return clientForm();
        if (action === 'edit-client') return clientForm(state.detail.client);
        if (action === 'edit-users') return usersForm();
        if (action === 'new-project') return projectForm();
        if (action === 'edit-project') {
            const project = state.detail.projects.find(row => Number(row.id) === Number(target.dataset.projectId));
            return projectForm(project || {});
        }
        if (action === 'new-site' || action === 'edit-site') {
            const project = state.detail.projects.find(row => Number(row.id) === Number(target.dataset.projectId));
            const site = action === 'edit-site'
                ? project?.sites?.find(row => Number(row.id) === Number(target.dataset.siteId))
                : {};
            return siteForm(project, site || {});
        }
        if (action === 'close-modal') return closeModal();
        if (action === 'archive-project') {
            if (!confirm('Перенести проект и его сайты в архив?')) return;
            return mutate('archive_project', {
                client_id: state.selectedClientId,
                project_id: Number(target.dataset.projectId)
            });
        }
        if (action === 'archive-site') {
            if (!confirm('Перенести сайт в архив?')) return;
            return mutate('archive_site', {
                client_id: state.selectedClientId,
                project_id: Number(target.dataset.projectId),
                site_id: Number(target.dataset.siteId)
            });
        }
        if (action === 'move-site') return moveSite(target);
    }

    async function moveSite(target) {
        const project = state.detail.projects.find(row => Number(row.id) === Number(target.dataset.projectId));
        if (!project) return;
        const ids = project.sites.map(site => Number(site.id));
        const index = ids.indexOf(Number(target.dataset.siteId));
        const next = target.dataset.direction === 'up' ? index - 1 : index + 1;
        if (index < 0 || next < 0 || next >= ids.length) return;
        [ids[index], ids[next]] = [ids[next], ids[index]];
        await mutate('reorder_sites', {
            client_id: state.selectedClientId,
            project_id: Number(project.id),
            site_ids: ids
        });
    }

    async function mutate(action, payload) {
        try {
            const result = await api(action, {method: 'POST', body: JSON.stringify(payload)});
            await selectClient(state.selectedClientId);
            showMessage(result.message);
        } catch (error) {
            showError(error);
        }
    }

    function closeModal() {
        const root = qs('#lk2ModalRoot');
        if (root) root.innerHTML = '';
    }

    function showMessage(message, type = 'success') {
        const node = qs('#lk2Message');
        if (!node) return;
        node.textContent = message || '';
        node.className = `lk2-message is-${type}`;
        clearTimeout(showMessage.timer);
        showMessage.timer = setTimeout(() => {
            node.textContent = '';
            node.className = 'lk2-message';
        }, 5000);
    }

    function showError(error) {
        console.error('LK2:', error);
        showMessage(error?.message || 'Не удалось выполнить действие.', 'error');
    }

    async function reloadContext(preferredClientId = 0) {
        const result = await api('context');
        state.context = result.context;
        const availableIds = state.context.clients.map(client => Number(client.id));
        state.selectedClientId = availableIds.includes(Number(preferredClientId))
            ? Number(preferredClientId)
            : (availableIds.includes(state.selectedClientId) ? state.selectedClientId : (availableIds[0] || 0));
        renderLayout();
        if (state.selectedClientId > 0) await selectClient(state.selectedClientId);
    }

    async function init() {
        if (state.loading) return;
        state.loading = true;
        try {
            ensureSection();
            await reloadContext(state.selectedClientId || Number(window.PortalProjectContext?.clientId || 0));
        } finally {
            state.loading = false;
        }
    }

    function boot() {
        bindNavigation();
        ensureSection();
        const observer = new MutationObserver(() => bindNavigation());
        observer.observe(document.body, {childList: true, subtree: true});
        document.addEventListener('portal:context-changed', event => {
            const clientId = Number(event.detail?.selected_client_id || 0);
            if (clientId > 0) state.selectedClientId = clientId;
        });
        if (location.hash === '#clients' || qs('[data-section="clients"].active')) {
            activateSection();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
