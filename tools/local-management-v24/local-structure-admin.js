
/* LOCAL_STRUCTURE_MANAGEMENT_V180324 */
(() => {
    'use strict';

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value == null ? '' : value);
        return node.innerHTML;
    }

    async function request(action, query = {}, options = {}) {
        const params = new URLSearchParams({action});
        Object.entries(query).forEach(([key, value]) => {
            if (value !== null && value !== undefined && value !== '') {
                params.set(key, String(value));
            }
        });
        const response = await fetch('/local-structure-api.php?' + params.toString(), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.body ? {'Content-Type': 'application/json'} : {}),
                ...(options.headers || {})
            },
            ...options
        });
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.error) {
            throw new Error(payload.error || ('HTTP ' + response.status));
        }
        return payload.data;
    }

    function projectName(card) {
        return String(qs('.lk2-project-header h4', card)?.textContent || '').trim();
    }

    function siteName(row) {
        return String(qs('.lk2-site-main strong', row)?.textContent || '').trim();
    }

    function decorate() {
        qsa('.lk2-project-card[data-project-id]').forEach(card => {
            const actions = qs('.lk2-project-header .lk2-actions', card);
            if (!actions) return;
            const projectId = Number(card.dataset.projectId || 0);
            if (!qs('[data-lm24-action="move-project"]', actions)) {
                actions.insertAdjacentHTML('beforeend', `
                    <button type="button" class="btn btn-secondary"
                            data-lm24-action="move-project"
                            data-project-id="${projectId}">
                        Сменить клиента
                    </button>
                `);
            }
            if (!qs('[data-lm24-action="delete-project"]', actions)) {
                actions.insertAdjacentHTML('beforeend', `
                    <button type="button" class="btn btn-danger-soft"
                            data-lm24-action="delete-project"
                            data-project-id="${projectId}">
                        Удалить из портала
                    </button>
                `);
            }
        });

        qsa('.lk2-site-row[data-site-id]').forEach(row => {
            const actions = qs('.lk2-site-actions', row);
            const projectCard = row.closest('.lk2-project-card[data-project-id]');
            if (!actions || !projectCard) return;
            if (qs('[data-lm24-action="delete-site"]', actions)) return;
            actions.insertAdjacentHTML('beforeend', `
                <button type="button" class="btn btn-danger-soft"
                        data-lm24-action="delete-site"
                        data-project-id="${Number(projectCard.dataset.projectId || 0)}"
                        data-site-id="${Number(row.dataset.siteId || 0)}">
                    Удалить из портала
                </button>
            `);
        });
    }

    function modalRoot() {
        let root = qs('#lk2ModalRoot');
        if (!root) {
            root = document.createElement('div');
            root.id = 'lk2ModalRoot';
            document.body.appendChild(root);
        }
        return root;
    }

    function closeModal() {
        modalRoot().innerHTML = '';
    }

    function showModal(title, body, formId) {
        modalRoot().innerHTML = `
            <div class="lk2-modal-backdrop lm24-backdrop">
                <section class="lk2-modal lm24-modal" role="dialog" aria-modal="true">
                    <header>
                        <div>
                            <span class="lm24-local-label">Только портал · Bitrix24 не изменяется</span>
                            <h3>${escapeHtml(title)}</h3>
                        </div>
                        <button type="button" class="lk2-modal-close" data-lm24-action="close">×</button>
                    </header>
                    <form id="${formId}" class="lk2-form">
                        ${body}
                    </form>
                </section>
            </div>
        `;
    }

    async function openMove(button) {
        const projectId = Number(button.dataset.projectId || 0);
        const card = button.closest('.lk2-project-card');
        const name = projectName(card);
        const context = await request('context', {project_id: projectId});
        const options = context.clients
            .filter(client => Number(client.id) !== Number(context.current_client_id))
            .map(client => `
                <option value="${Number(client.id)}">${escapeHtml(client.name)}</option>
            `).join('');
        showModal('Сменить клиента проекта', `
            <input type="hidden" name="project_id" value="${projectId}">
            <div class="lm24-warning">
                Проект «${escapeHtml(name)}» будет перенесён только между клиентами портала.
                Компания и проект в Bitrix24 останутся без изменений.
            </div>
            <label>
                <span>Новый клиент</span>
                <select name="target_client_id" required>
                    <option value="">Выберите клиента</option>
                    ${options}
                </select>
            </label>
            <div id="lm24Message" class="lm24-message"></div>
            <footer>
                <button type="button" class="btn btn-secondary" data-lm24-action="close">Отмена</button>
                <button type="submit" class="btn btn-primary">Перенести в портале</button>
            </footer>
        `, 'lm24MoveProjectForm');
    }

    function openDeleteProject(button) {
        const projectId = Number(button.dataset.projectId || 0);
        const card = button.closest('.lk2-project-card');
        const name = projectName(card);
        showModal('Удалить проект из портала', `
            <input type="hidden" name="project_id" value="${projectId}">
            <input type="hidden" name="expected_name" value="${escapeHtml(name)}">
            <div class="lm24-danger">
                Будут удалены локальный проект, его сайты, подключения, продажи и связанные данные портала.
                Проект, задачи и компания в Bitrix24 останутся без изменений.
            </div>
            <label>
                <span>Введите точное название проекта: <strong>${escapeHtml(name)}</strong></span>
                <input name="confirmation" required autocomplete="off">
            </label>
            <div id="lm24Message" class="lm24-message"></div>
            <footer>
                <button type="button" class="btn btn-secondary" data-lm24-action="close">Отмена</button>
                <button type="submit" class="btn btn-danger">Удалить только из портала</button>
            </footer>
        `, 'lm24DeleteProjectForm');
    }

    function openDeleteSite(button) {
        const projectId = Number(button.dataset.projectId || 0);
        const siteId = Number(button.dataset.siteId || 0);
        const row = button.closest('.lk2-site-row');
        const name = siteName(row);
        showModal('Удалить сайт из портала', `
            <input type="hidden" name="project_id" value="${projectId}">
            <input type="hidden" name="site_id" value="${siteId}">
            <div class="lm24-danger">
                Сайт и его подключения будут удалены только из локальной базы портала.
                Поле WEB компании и другие данные Bitrix24 останутся без изменений.
            </div>
            <label>
                <span>Введите точное название сайта: <strong>${escapeHtml(name)}</strong></span>
                <input name="confirmation" required autocomplete="off">
            </label>
            <div id="lm24Message" class="lm24-message"></div>
            <footer>
                <button type="button" class="btn btn-secondary" data-lm24-action="close">Отмена</button>
                <button type="submit" class="btn btn-danger">Удалить только из портала</button>
            </footer>
        `, 'lm24DeleteSiteForm');
    }

    async function submitForm(form) {
        const message = qs('#lm24Message', form);
        const submit = qs('button[type="submit"]', form);
        if (submit) submit.disabled = true;
        if (message) {
            message.className = 'lm24-message is-loading';
            message.textContent = 'Выполняем локальную операцию…';
        }
        try {
            const data = Object.fromEntries(new FormData(form).entries());
            ['project_id', 'site_id', 'target_client_id'].forEach(key => {
                if (key in data) data[key] = Number(data[key] || 0);
            });
            let action = '';
            if (form.id === 'lm24MoveProjectForm') action = 'move_project';
            if (form.id === 'lm24DeleteProjectForm') action = 'delete_project';
            if (form.id === 'lm24DeleteSiteForm') action = 'delete_site';
            const result = await request(action, {}, {
                method: 'POST',
                body: JSON.stringify(data)
            });
            if (message) {
                message.className = 'lm24-message is-success';
                message.textContent = result.message;
            }
            setTimeout(() => {
                if (window.PortalNavigation?.reloadCurrent) {
                    window.PortalNavigation.reloadCurrent();
                } else {
                    location.reload();
                }
            }, 500);
        } catch (error) {
            if (submit) submit.disabled = false;
            if (message) {
                message.className = 'lm24-message is-error';
                message.textContent = error.message;
            }
        }
    }

    document.addEventListener('click', event => {
        const button = event.target.closest?.('[data-lm24-action]');
        if (!button) return;
        event.preventDefault();
        event.stopPropagation();
        const action = button.dataset.lm24Action;
        if (action === 'close') return closeModal();
        if (action === 'move-project') return openMove(button).catch(error => alert(error.message));
        if (action === 'delete-project') return openDeleteProject(button);
        if (action === 'delete-site') return openDeleteSite(button);
    }, true);

    document.addEventListener('submit', event => {
        if (!['lm24MoveProjectForm', 'lm24DeleteProjectForm', 'lm24DeleteSiteForm'].includes(event.target.id)) {
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        submitForm(event.target);
    }, true);

    const observer = new MutationObserver(decorate);
    observer.observe(document.body, {childList: true, subtree: true});
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', decorate, {once: true});
    } else {
        decorate();
    }
})();
