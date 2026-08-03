
/* LOCAL_BITRIX_LINKS_V180325 */
(() => {
    'use strict';

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function html(value) {
        const node = document.createElement('div');
        node.textContent = String(value == null ? '' : value);
        return node.innerHTML;
    }

    async function request(action, options = {}) {
        const response = await fetch(
            '/local-bitrix-links-api.php?action=' + encodeURIComponent(action),
            {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    ...(options.body ? {'Content-Type': 'application/json'} : {}),
                },
                ...options,
            }
        );
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.error) {
            throw new Error(payload.error || ('HTTP ' + response.status));
        }
        return payload.data;
    }

    function activeClientId() {
        const active = qs(
            '.lk2-client-card.is-active[data-client-id], '
            + '.lk2-client-card.active[data-client-id], '
            + '.lk2-client-card[aria-selected="true"][data-client-id]'
        );
        if (active) return Number(active.dataset.clientId || 0);
        const selected = qs('.lk2-client-card[data-client-id]');
        return Number(selected?.dataset.clientId || 0);
    }

    function activeClientName() {
        const active = qs(
            '.lk2-client-card.is-active, .lk2-client-card.active, '
            + '.lk2-client-card[aria-selected="true"]'
        );
        return String(qs('strong, h3, h4', active)?.textContent || '').trim();
    }

    function modalRoot() {
        let root = qs('#lb25ModalRoot');
        if (!root) {
            root = document.createElement('div');
            root.id = 'lb25ModalRoot';
            document.body.appendChild(root);
        }
        return root;
    }

    function closeModal() {
        modalRoot().innerHTML = '';
    }

    function shell(title, subtitle, body) {
        modalRoot().innerHTML = `
            <div class="lb25-backdrop">
                <section class="lb25-modal" role="dialog" aria-modal="true">
                    <header class="lb25-header">
                        <div>
                            <span>Только портал · Bitrix24 не изменяется</span>
                            <h3>${html(title)}</h3>
                            ${subtitle ? `<p>${html(subtitle)}</p>` : ''}
                        </div>
                        <button type="button" class="lb25-close" data-lb25-action="close">×</button>
                    </header>
                    <div class="lb25-body">${body}</div>
                </section>
            </div>
        `;
    }

    function decorate() {
        qsa('[data-lk2-action="new-project"]').forEach(button => {
            button.dataset.lb25Action = 'create-project';
            delete button.dataset.lk2Action;
            if (!button.dataset.lb25Labelled) {
                button.dataset.lb25Labelled = '1';
                button.title = 'Создать проект только в портале';
            }
        });

        const newClient = qs('[data-lk2-action="new-client"]');
        if (newClient && !qs('[data-lb25-action="links"]')) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'btn btn-secondary';
            button.dataset.lb25Action = 'links';
            button.textContent = 'Локальные связи Bitrix24';
            newClient.insertAdjacentElement('beforebegin', button);
        }
    }

    function openCreateProject() {
        const clientId = activeClientId();
        if (clientId <= 0) {
            alert('Сначала выберите клиента.');
            return;
        }
        const clientName = activeClientName();
        shell(
            'Новый проект',
            'Проект будет создан только в базе портала.',
            `
                <form id="lb25CreateProjectForm" class="lb25-form">
                    <input type="hidden" name="client_id" value="${clientId}">
                    <div class="lb25-note">
                        Клиент: <strong>${html(clientName || ('#' + clientId))}</strong>.
                        В Bitrix24 проект не создаётся и не изменяется.
                    </div>
                    <label>
                        <span>Название проекта</span>
                        <input name="name" maxlength="255" required autofocus>
                    </label>
                    <label>
                        <span>Описание</span>
                        <textarea name="description" rows="4"></textarea>
                    </label>
                    <label>
                        <span>Статус</span>
                        <select name="status">
                            <option value="active">Активен</option>
                            <option value="paused">Приостановлен</option>
                        </select>
                    </label>
                    <div id="lb25Message" class="lb25-message"></div>
                    <footer>
                        <button type="button" class="btn btn-secondary" data-lb25-action="close">Отмена</button>
                        <button type="submit" class="btn btn-primary">Создать в портале</button>
                    </footer>
                </form>
            `
        );
    }

    async function openLinks() {
        shell('Локальные связи Bitrix24', 'Загружаем данные…', '<div class="lb25-loading">Загрузка…</div>');
        try {
            const data = await request('context');
            const companies = data.company_links || [];
            const projects = data.project_links || [];
            shell(
                'Локальные связи Bitrix24',
                'Здесь видно, где портал хранит внешние ID. Отвязка не меняет Bitrix24.',
                `
                    <section class="lb25-section">
                        <div class="lb25-section-title">
                            <h4>Компании (${companies.length})</h4>
                            <small>${html(data.storage?.company || '')}</small>
                        </div>
                        <div class="lb25-list">
                            ${companies.length ? companies.map(row => `
                                <article class="lb25-link-card">
                                    <div>
                                        <strong>${html(row.client_name)}</strong>
                                        <p>Клиент портала #${Number(row.client_id)} · ${html(row.status)}</p>
                                        <p>Компания Bitrix24 #${Number(row.bitrix_company_id)} · ${html(row.bitrix_company_name || 'Без названия')}</p>
                                        <small>Проектов: ${Number(row.projects_count)} · сайтов: ${Number(row.sites_count)}</small>
                                    </div>
                                    <button type="button" class="btn btn-danger-soft"
                                        data-lb25-action="detach-client"
                                        data-client-id="${Number(row.client_id)}"
                                        data-client-name="${html(row.client_name)}">
                                        Освободить связи в портале
                                    </button>
                                </article>
                            `).join('') : '<div class="lb25-empty">Локальных связей компаний нет.</div>'}
                        </div>
                    </section>
                    <section class="lb25-section">
                        <div class="lb25-section-title">
                            <h4>Проекты (${projects.length})</h4>
                            <small>${html(data.storage?.project_link || '')}</small>
                        </div>
                        <div class="lb25-list">
                            ${projects.length ? projects.map(row => `
                                <article class="lb25-link-card ${row.orphan_project ? 'is-orphan' : ''}">
                                    <div>
                                        <strong>${html(row.project_name || row.bitrix_group_name || ('Проект #' + row.project_id))}</strong>
                                        <p>Локальный проект #${Number(row.project_id || 0)} · клиент ${html(row.client_name || 'не найден')}</p>
                                        <p>Проект Bitrix24 #${Number(row.bitrix_group_id || 0)} · ${html(row.bitrix_group_name || 'Без названия')}</p>
                                    </div>
                                    ${row.project_id && !row.orphan_project ? `
                                        <button type="button" class="btn btn-danger-soft"
                                            data-lb25-action="detach-project"
                                            data-project-id="${Number(row.project_id)}"
                                            data-project-name="${html(row.project_name || row.bitrix_group_name)}">
                                            Отвязать в портале
                                        </button>
                                    ` : '<span class="lb25-orphan">Осиротевшая запись</span>'}
                                </article>
                            `).join('') : '<div class="lb25-empty">Локальных связей проектов нет.</div>'}
                        </div>
                    </section>
                `
            );
        } catch (error) {
            shell('Локальные связи Bitrix24', '', `<div class="lb25-error">${html(error.message)}</div>`);
        }
    }

    function openDetachClient(button) {
        const id = Number(button.dataset.clientId || 0);
        const name = String(button.dataset.clientName || '');
        shell('Освободить связи клиента', 'Операция выполняется только в портале.', `
            <form id="lb25DetachClientForm" class="lb25-form">
                <input type="hidden" name="client_id" value="${id}">
                <div class="lb25-danger">
                    Будут удалены локальные ID компании, контактов и проектов Bitrix24.
                    Клиент, проекты и сайты портала сохранятся. В Bitrix24 ничего не изменится.
                </div>
                <label>
                    <span>Введите точное название: <strong>${html(name)}</strong></span>
                    <input name="confirmation" required autocomplete="off">
                </label>
                <div id="lb25Message" class="lb25-message"></div>
                <footer>
                    <button type="button" class="btn btn-secondary" data-lb25-action="links">Назад</button>
                    <button type="submit" class="btn btn-danger">Освободить только в портале</button>
                </footer>
            </form>
        `);
    }

    function openDetachProject(button) {
        const id = Number(button.dataset.projectId || 0);
        const name = String(button.dataset.projectName || '');
        shell('Отвязать проект от Bitrix24', 'Локальный проект и сайты сохранятся.', `
            <form id="lb25DetachProjectForm" class="lb25-form">
                <input type="hidden" name="project_id" value="${id}">
                <div class="lb25-danger">
                    Удаляется только локальная связь с внешним ID. Проект Bitrix24 не изменяется.
                </div>
                <label>
                    <span>Введите точное название: <strong>${html(name)}</strong></span>
                    <input name="confirmation" required autocomplete="off">
                </label>
                <div id="lb25Message" class="lb25-message"></div>
                <footer>
                    <button type="button" class="btn btn-secondary" data-lb25-action="links">Назад</button>
                    <button type="submit" class="btn btn-danger">Отвязать только в портале</button>
                </footer>
            </form>
        `);
    }

    async function submit(form) {
        const submitButton = qs('button[type="submit"]', form);
        const message = qs('#lb25Message', form);
        if (submitButton) submitButton.disabled = true;
        if (message) {
            message.className = 'lb25-message is-loading';
            message.textContent = 'Сохраняем локальные изменения…';
        }
        const data = Object.fromEntries(new FormData(form).entries());
        ['client_id', 'project_id'].forEach(key => {
            if (key in data) data[key] = Number(data[key] || 0);
        });
        let action = '';
        if (form.id === 'lb25CreateProjectForm') action = 'create_project';
        if (form.id === 'lb25DetachClientForm') action = 'detach_client';
        if (form.id === 'lb25DetachProjectForm') action = 'detach_project';
        try {
            const result = await request(action, {
                method: 'POST',
                body: JSON.stringify(data),
            });
            if (message) {
                message.className = 'lb25-message is-success';
                message.textContent = result.message;
            }
            setTimeout(() => {
                if (action.startsWith('detach_')) {
                    openLinks();
                } else if (window.PortalNavigation?.reloadCurrent) {
                    window.PortalNavigation.reloadCurrent();
                } else {
                    location.reload();
                }
            }, 500);
        } catch (error) {
            if (submitButton) submitButton.disabled = false;
            if (message) {
                message.className = 'lb25-message is-error';
                message.textContent = error.message;
            }
        }
    }

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-lb25-action]');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        const action = button.dataset.lb25Action;
        if (action === 'close') return closeModal();
        if (action === 'create-project') return openCreateProject();
        if (action === 'links') return openLinks();
        if (action === 'detach-client') return openDetachClient(button);
        if (action === 'detach-project') return openDetachProject(button);
    }, true);

    document.addEventListener('submit', event => {
        if (![
            'lb25CreateProjectForm',
            'lb25DetachClientForm',
            'lb25DetachProjectForm',
        ].includes(event.target.id)) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        submit(event.target);
    }, true);

    const observer = new MutationObserver(decorate);
    observer.observe(document.body, {childList: true, subtree: true});
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', decorate, {once: true});
    } else {
        decorate();
    }
})();
