
/* BITRIX_CLIENT_ONBOARDING_V180319 */
(() => {
    'use strict';

    const state = {
        clientId: 0,
        response: null,
        originButton: null,
        loading: false
    };

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value == null ? '' : value);
        return node.innerHTML;
    }

    async function request(action, options = {}) {
        const response = await fetch(
            '/bitrix24-client-api.php?action=' + encodeURIComponent(action),
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
            const error = new Error(data.error || ('HTTP ' + response.status));
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function modalRoot() {
        let root = qs('#b19ModalRoot');
        if (!root) {
            root = document.createElement('div');
            root.id = 'b19ModalRoot';
            document.body.appendChild(root);
        }
        return root;
    }

    function closeModal() {
        modalRoot().innerHTML = '';
        state.loading = false;
    }

    function showLoading(title) {
        modalRoot().innerHTML = `
            <div class="b19-backdrop">
                <section class="b19-modal" role="dialog" aria-modal="true">
                    <header class="b19-modal-header">
                        <div>
                            <span>Bitrix24</span>
                            <h3>${escapeHtml(title)}</h3>
                        </div>
                        <button type="button" class="b19-close" data-b19-action="close">×</button>
                    </header>
                    <div class="b19-loading">Загружаем компании, контакты и проекты…</div>
                </section>
            </div>
        `;
    }

    function activeClientId() {
        const active = qs('.lk2-client-card.is-active[data-client-id]');
        return Number(active && active.dataset.clientId ? active.dataset.clientId : 0);
    }

    async function openOnboarding(clientId, originButton) {
        if (state.loading) return;
        state.loading = true;
        state.clientId = Number(clientId || 0);
        state.originButton = originButton || null;
        showLoading(state.clientId > 0 ? 'Редактирование клиента' : 'Новый клиент');
        try {
            const result = await request(
                'catalog&client_id=' + encodeURIComponent(state.clientId)
            );
            state.response = result.data;
            renderModal();
        } catch (error) {
            renderFatal(error.message);
        } finally {
            state.loading = false;
        }
    }

    function renderFatal(message) {
        const root = modalRoot();
        root.innerHTML = `
            <div class="b19-backdrop">
                <section class="b19-modal" role="dialog" aria-modal="true">
                    <header class="b19-modal-header">
                        <div><span>Bitrix24</span><h3>Не удалось загрузить справочник</h3></div>
                        <button type="button" class="b19-close" data-b19-action="close">×</button>
                    </header>
                    <div class="b19-error">${escapeHtml(message)}</div>
                    <footer class="b19-footer">
                        ${state.clientId <= 0 ? '<button type="button" class="btn btn-secondary" data-b19-action="manual">Ручной режим</button>' : ''}
                        <button type="button" class="btn btn-primary" data-b19-action="close">Закрыть</button>
                    </footer>
                </section>
            </div>
        `;
    }

    function renderModal() {
        const data = state.response || {};
        const directory = data.directory || {};
        const mapping = data.mapping || {};
        const localContext = data.local_context || {};
        const companies = Array.isArray(directory.companies) ? directory.companies : [];
        const contacts = Array.isArray(directory.contacts) ? directory.contacts : [];
        const projects = Array.isArray(directory.projects) ? directory.projects : [];
        const managers = Array.isArray(localContext.managers) ? localContext.managers : [];
        const companyId = Number((directory.company && directory.company.id) || mapping.company_id || 0);
        const sameCompany = companyId > 0 && companyId === Number(mapping.company_id || 0);
        const selectedContacts = new Set(
            (sameCompany ? (mapping.contact_ids || []) : contacts.map(item => item.id))
                .map(Number)
        );
        const recommended = new Set((directory.recommended_project_ids || []).map(Number));
        const selectedProjects = new Set(
            (sameCompany && mapping.bitrix_group_ids && mapping.bitrix_group_ids.length
                ? mapping.bitrix_group_ids
                : Array.from(recommended))
                .map(Number)
        );
        let primaryContactId = sameCompany
            ? Number(mapping.primary_contact_id || 0)
            : Number(contacts[0] && contacts[0].id ? contacts[0].id : 0);
        if (!selectedContacts.has(primaryContactId)) {
            primaryContactId = Number(contacts[0] && contacts[0].id ? contacts[0].id : 0);
        }

        modalRoot().innerHTML = `
            <div class="b19-backdrop">
                <section class="b19-modal b19-modal-wide" role="dialog" aria-modal="true">
                    <header class="b19-modal-header">
                        <div>
                            <span>Bitrix24 · ${escapeHtml(directory.portal_host || '')}</span>
                            <h3>${state.clientId > 0 ? 'Редактирование клиента' : 'Новый клиент'}</h3>
                            <p>Компания, контакты и проекты загружаются из Bitrix24. Сайты и подключения портала сохраняются.</p>
                        </div>
                        <button type="button" class="b19-close" data-b19-action="close">×</button>
                    </header>
                    <form id="b19OnboardingForm" class="b19-form">
                        <input type="hidden" name="client_id" value="${state.clientId}">
                        <section class="b19-section">
                            <div class="b19-section-heading">
                                <div><span>Шаг 1</span><h4>Компания Bitrix24</h4></div>
                                <small>${companies.length} компаний доступно</small>
                            </div>
                            <label class="b19-field">
                                <span>Компания</span>
                                <select name="company_id" required>
                                    <option value="">Выберите компанию</option>
                                    ${companies.map(company => `
                                        <option value="${Number(company.id)}" ${Number(company.id) === companyId ? 'selected' : ''}>
                                            ${escapeHtml(company.title)}
                                        </option>
                                    `).join('')}
                                </select>
                            </label>
                        </section>

                        <section class="b19-section">
                            <div class="b19-section-heading">
                                <div><span>Шаг 2</span><h4>Контактные лица</h4></div>
                                <small>${contacts.length} контактов компании</small>
                            </div>
                            <div class="b19-contact-list">
                                ${contacts.length ? contacts.map(contact => `
                                    <label class="b19-contact-card">
                                        <input type="checkbox" name="contact_ids[]" value="${Number(contact.id)}" ${selectedContacts.has(Number(contact.id)) ? 'checked' : ''}>
                                        <span class="b19-contact-main">
                                            <strong>${escapeHtml(contact.name)}</strong>
                                            <small>${escapeHtml([contact.phone, contact.email].filter(Boolean).join(' · ') || 'Контактные данные не указаны')}</small>
                                        </span>
                                        <span class="b19-primary-choice">
                                            <input type="radio" name="primary_contact_id" value="${Number(contact.id)}" ${Number(contact.id) === primaryContactId ? 'checked' : ''}>
                                            Основной
                                        </span>
                                    </label>
                                `).join('') : '<div class="b19-empty">У компании нет контактов. Будут использованы телефон и email компании.</div>'}
                            </div>
                        </section>

                        <section class="b19-section">
                            <div class="b19-section-heading">
                                <div><span>Шаг 3</span><h4>Проекты Bitrix24</h4></div>
                                <small>Можно выбрать несколько</small>
                            </div>
                            <label class="b19-field">
                                <span>Поиск проекта</span>
                                <input type="search" id="b19ProjectSearch" placeholder="Название проекта">
                            </label>
                            <div class="b19-project-list" id="b19ProjectList">
                                ${projects.map(project => `
                                    <label class="b19-project-card" data-project-search="${escapeHtml(String(project.name || '').toLowerCase())}">
                                        <input type="checkbox" name="project_ids[]" value="${Number(project.id)}" ${selectedProjects.has(Number(project.id)) ? 'checked' : ''}>
                                        <span>
                                            <strong>${escapeHtml(project.name)}</strong>
                                            ${project.description ? `<small>${escapeHtml(project.description)}</small>` : ''}
                                        </span>
                                        ${recommended.has(Number(project.id)) ? '<em>Рекомендован</em>' : ''}
                                    </label>
                                `).join('')}
                            </div>
                        </section>

                        <section class="b19-section b19-local-settings">
                            <div class="b19-section-heading">
                                <div><span>Портал</span><h4>Ответственный и статус</h4></div>
                            </div>
                            <div class="b19-grid">
                                <label class="b19-field">
                                    <span>Ответственный менеджер</span>
                                    <select name="manager_user_id">
                                        <option value="0">Не назначен</option>
                                        ${managers.map(manager => `
                                            <option value="${Number(manager.id)}" ${Number(manager.id) === Number(mapping.manager_user_id || 0) ? 'selected' : ''}>
                                                ${escapeHtml(manager.name)}
                                            </option>
                                        `).join('')}
                                    </select>
                                </label>
                                <label class="b19-field">
                                    <span>Статус</span>
                                    <select name="status">
                                        <option value="active" ${(mapping.status || 'active') === 'active' ? 'selected' : ''}>Активен</option>
                                        <option value="paused" ${mapping.status === 'paused' ? 'selected' : ''}>Приостановлен</option>
                                        <option value="archived" ${mapping.status === 'archived' ? 'selected' : ''}>Архив</option>
                                    </select>
                                </label>
                            </div>
                            <label class="b19-field">
                                <span>Внутренний комментарий</span>
                                <textarea name="notes" rows="3">${escapeHtml(mapping.notes || '')}</textarea>
                            </label>
                        </section>

                        <div id="b19Message" class="b19-message"></div>
                        <footer class="b19-footer">
                            ${state.clientId <= 0 ? '<button type="button" class="btn btn-secondary" data-b19-action="manual">Ручной режим</button>' : ''}
                            <button type="button" class="btn btn-secondary" data-b19-action="close">Отмена</button>
                            <button type="submit" class="btn btn-primary">Сохранить и синхронизировать</button>
                        </footer>
                    </form>
                </section>
            </div>
        `;

        const companySelect = qs('[name="company_id"]', modalRoot());
        if (companySelect) {
            companySelect.addEventListener('change', () => {
                loadCompany(Number(companySelect.value || 0));
            });
        }
        const search = qs('#b19ProjectSearch');
        if (search) {
            search.addEventListener('input', () => {
                const query = String(search.value || '').trim().toLowerCase();
                qsa('.b19-project-card', modalRoot()).forEach(card => {
                    card.hidden = query !== '' && !String(card.dataset.projectSearch || '').includes(query);
                });
            });
        }
        const form = qs('#b19OnboardingForm');
        if (form) form.addEventListener('submit', saveForm);
    }

    async function loadCompany(companyId) {
        if (companyId <= 0 || state.loading) return;
        state.loading = true;
        const message = qs('#b19Message');
        if (message) {
            message.className = 'b19-message is-loading';
            message.textContent = 'Загружаем контакты и рекомендации…';
        }
        try {
            const result = await request(
                'catalog&company_id=' + encodeURIComponent(companyId)
                + '&client_id=' + encodeURIComponent(state.clientId)
            );
            state.response = result.data;
            renderModal();
        } catch (error) {
            if (message) {
                message.className = 'b19-message is-error';
                message.textContent = error.message;
            }
        } finally {
            state.loading = false;
        }
    }

    async function saveForm(event) {
        event.preventDefault();
        if (state.loading) return;
        const form = event.currentTarget;
        const companyId = Number(form.elements.company_id.value || 0);
        const contactIds = qsa('[name="contact_ids[]"]:checked', form).map(item => Number(item.value));
        const projectIds = qsa('[name="project_ids[]"]:checked', form).map(item => Number(item.value));
        const primary = qs('[name="primary_contact_id"]:checked', form);
        const message = qs('#b19Message');
        state.loading = true;
        qsa('button, input, select, textarea', form).forEach(item => item.disabled = true);
        if (message) {
            message.className = 'b19-message is-loading';
            message.textContent = 'Сохраняем клиента, контакты и проекты…';
        }
        try {
            await request('save', {
                method: 'POST',
                body: JSON.stringify({
                    client_id: Number(form.elements.client_id.value || 0),
                    company_id: companyId,
                    contact_ids: contactIds,
                    primary_contact_id: primary ? Number(primary.value) : 0,
                    project_ids: projectIds,
                    manager_user_id: Number(form.elements.manager_user_id.value || 0),
                    status: form.elements.status.value,
                    notes: form.elements.notes.value
                })
            });
            if (message) {
                message.className = 'b19-message is-success';
                message.textContent = 'Клиент синхронизирован. Обновляем карточку…';
            }
            window.location.reload();
        } catch (error) {
            state.loading = false;
            qsa('button, input, select, textarea', form).forEach(item => item.disabled = false);
            if (message) {
                message.className = 'b19-message is-error';
                message.textContent = error.message;
            }
        }
    }

    function openManualMode() {
        const button = state.originButton;
        closeModal();
        if (!button) return;
        window.__b19ManualBypass = true;
        button.click();
    }

    document.addEventListener('click', event => {
        const action = event.target.closest('[data-b19-action]');
        if (action) {
            const name = action.dataset.b19Action;
            if (name === 'close') {
                event.preventDefault();
                closeModal();
            } else if (name === 'manual') {
                event.preventDefault();
                openManualMode();
            }
            return;
        }

        const target = event.target.closest(
            '[data-lk2-action="new-client"], [data-lk2-action="edit-client"]'
        );
        if (!target) return;
        if (window.__b19ManualBypass) {
            window.__b19ManualBypass = false;
            return;
        }
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        const isEdit = target.dataset.lk2Action === 'edit-client';
        openOnboarding(isEdit ? activeClientId() : 0, target);
    }, true);
})();
