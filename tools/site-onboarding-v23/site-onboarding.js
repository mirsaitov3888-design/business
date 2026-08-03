
/* SITE_ONBOARDING_V180323 */
(() => {
    'use strict';

    const state = {
        loading: false,
        clientId: 0,
        projectId: 0,
        siteId: 0,
        context: null
    };

    const qs = (selector, root = document) => root.querySelector(selector);
    const qsa = (selector, root = document) => Array.from(root.querySelectorAll(selector));

    function html(value) {
        const node = document.createElement('div');
        node.textContent = String(value == null ? '' : value);
        return node.innerHTML;
    }

    function params(action, query = {}) {
        const result = new URLSearchParams({action});
        Object.entries(query).forEach(([key, value]) => {
            if (value !== '' && value !== null && value !== undefined) {
                result.set(key, String(value));
            }
        });
        return result.toString();
    }

    async function request(action, query = {}, options = {}) {
        const response = await fetch(
            '/site-onboarding-api.php?' + params(action, query),
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
            throw new Error(data.error || ('HTTP ' + response.status));
        }
        return data;
    }

    function modalRoot() {
        let root = qs('#siteOnboardingModalRoot');
        if (!root) {
            root = document.createElement('div');
            root.id = 'siteOnboardingModalRoot';
            document.body.appendChild(root);
        }
        return root;
    }

    function close() {
        modalRoot().innerHTML = '';
        state.loading = false;
    }

    function activeClientId() {
        const active = qs('.lk2-client-card.is-active[data-client-id]');
        return Number(active?.dataset.clientId || 0);
    }

    function showLoading() {
        modalRoot().innerHTML = `
            <div class="so23-backdrop">
                <section class="so23-modal" role="dialog" aria-modal="true">
                    <header class="so23-header">
                        <div><span>Сайт и источники</span><h3>Загружаем настройки…</h3></div>
                        <button type="button" data-so23-action="close">×</button>
                    </header>
                    <div class="so23-loading">Получаем сайты компании Bitrix24 и текущие подключения.</div>
                </section>
            </div>
        `;
    }

    async function open(clientId, projectId, siteId) {
        if (state.loading) return;
        state.loading = true;
        state.clientId = Number(clientId || 0);
        state.projectId = Number(projectId || 0);
        state.siteId = Number(siteId || 0);
        showLoading();
        try {
            const result = await request('context', {
                client_id: state.clientId,
                project_id: state.projectId,
                site_id: state.siteId || ''
            });
            state.context = result.data;
            render();
        } catch (error) {
            renderError(error.message);
        } finally {
            state.loading = false;
        }
    }

    function renderError(message) {
        modalRoot().innerHTML = `
            <div class="so23-backdrop">
                <section class="so23-modal" role="dialog" aria-modal="true">
                    <header class="so23-header">
                        <div><span>Сайт и источники</span><h3>Не удалось открыть форму</h3></div>
                        <button type="button" data-so23-action="close">×</button>
                    </header>
                    <div class="so23-error">${html(message)}</div>
                    <footer class="so23-footer">
                        <button type="button" class="btn btn-primary" data-so23-action="close">Закрыть</button>
                    </footer>
                </section>
            </div>
        `;
    }

    function list(value) {
        return Array.isArray(value) ? value.join(', ') : '';
    }

    function render() {
        const context = state.context || {};
        const site = context.site || {};
        const sources = context.sources || {};
        const bitrix = context.bitrix || {};
        const websites = Array.isArray(bitrix.websites) ? bitrix.websites : [];
        const currentUrl = String(site.url || websites[0] || '');
        const currentExists = websites.some(url => normalizeUrl(url) === normalizeUrl(currentUrl));
        const metrikaIds = list(
            sources.metrika?.counter_ids?.length
                ? sources.metrika.counter_ids
                : (site.metrika_counter_ids || [])
        );
        const webmasterIds = list(
            sources.webmaster?.host_ids?.length
                ? sources.webmaster.host_ids
                : (site.webmaster_host_ids || [])
        );
        const direct = sources.direct || {};
        const directDirectory = context.direct_directory || {};
        const directClients = Array.isArray(directDirectory.clients)
            ? directDirectory.clients
            : [];
        const directLogin = String(direct.client_login || '');
        const selectedWebsiteValue = currentExists ? currentUrl : '__manual__';

        modalRoot().innerHTML = `
            <div class="so23-backdrop">
                <section class="so23-modal so23-modal-wide" role="dialog" aria-modal="true">
                    <header class="so23-header">
                        <div>
                            <span>${html(context.client?.name || '')} · ${html(context.project?.name || '')}</span>
                            <h3>${site.id ? 'Редактирование сайта и подключений' : 'Новый сайт и подключения'}</h3>
                            <p>URL, Метрика, Директ и Вебмастер сохраняются один раз и используются всеми разделами.</p>
                        </div>
                        <button type="button" data-so23-action="close">×</button>
                    </header>
                    <form id="so23Form" class="so23-form">
                        <input type="hidden" name="site_id" value="${Number(site.id || 0)}">
                        <input type="hidden" name="client_id" value="${state.clientId}">
                        <input type="hidden" name="project_id" value="${state.projectId}">

                        <section class="so23-section">
                            <div class="so23-section-head">
                                <div><span>Шаг 1</span><h4>Сайт</h4></div>
                                <small>${bitrix.available ? 'Связан с Bitrix24' : 'Компания не связана с Bitrix24'}</small>
                            </div>
                            ${bitrix.warning ? `<div class="so23-warning">${html(bitrix.warning)}</div>` : ''}
                            <label class="so23-field">
                                <span>Сайт из карточки компании Bitrix24</span>
                                <select id="so23WebsiteSelect">
                                    ${websites.map(url => `
                                        <option value="${html(url)}" ${normalizeUrl(url) === normalizeUrl(selectedWebsiteValue) ? 'selected' : ''}>${html(url)}</option>
                                    `).join('')}
                                    <option value="__manual__" ${selectedWebsiteValue === '__manual__' ? 'selected' : ''}>Добавить другой сайт</option>
                                </select>
                            </label>
                            <div class="so23-grid">
                                <label class="so23-field">
                                    <span>Название сайта</span>
                                    <input name="name" required maxlength="190" value="${html(site.name || '')}" placeholder="Например, Основной сайт">
                                </label>
                                <label class="so23-field">
                                    <span>URL</span>
                                    <input name="url" required maxlength="1000" value="${html(currentUrl)}" placeholder="https://example.ru">
                                </label>
                                <label class="so23-field">
                                    <span>Статус</span>
                                    <select name="status">
                                        <option value="active" ${(site.status || 'active') === 'active' ? 'selected' : ''}>Активен</option>
                                        <option value="paused" ${site.status === 'paused' ? 'selected' : ''}>Пауза</option>
                                        <option value="archived" ${site.status === 'archived' ? 'selected' : ''}>Архив</option>
                                    </select>
                                </label>
                                <label class="so23-field">
                                    <span>Порядок</span>
                                    <input name="sort_order" type="number" min="0" value="${Number(site.sort_order || 0)}">
                                </label>
                            </div>
                            <label class="so23-check">
                                <input type="checkbox" name="sync_to_bitrix" ${bitrix.available ? 'checked' : ''} ${bitrix.available ? '' : 'disabled'}>
                                <span>Добавить новый URL в список сайтов компании Bitrix24</span>
                            </label>
                        </section>

                        <section class="so23-section">
                            <div class="so23-section-head">
                                <div><span>Шаг 2</span><h4>Яндекс Метрика</h4></div>
                                <small>${sources.metrika?.status || 'не настроена'}</small>
                            </div>
                            <label class="so23-field">
                                <span>ID счётчиков</span>
                                <input name="metrika_counter_ids" value="${html(metrikaIds)}" placeholder="12345678, 87654321">
                                <small>Можно указать несколько счётчиков через запятую.</small>
                            </label>
                        </section>

                        <section class="so23-section">
                            <div class="so23-section-head">
                                <div><span>Шаг 3</span><h4>Яндекс Директ</h4></div>
                                <small>${directDirectory.configured ? 'API доступен' : 'API не настроен'}</small>
                            </div>
                            ${directDirectory.warning ? `<div class="so23-warning">${html(directDirectory.warning)}</div>` : ''}
                            <label class="so23-check">
                                <input type="checkbox" name="direct_enabled" ${direct.enabled ? 'checked' : ''}>
                                <span>Подключить Директ к этому сайту</span>
                            </label>
                            <div class="so23-grid so23-direct-fields">
                                <label class="so23-field">
                                    <span>Логин клиентского кабинета</span>
                                    <input name="direct_client_login" list="so23DirectClients" value="${html(directLogin)}" placeholder="client-login">
                                    <datalist id="so23DirectClients">
                                        ${directClients.map(row => `<option value="${html(row.login)}">${html(row.name || row.login)}</option>`).join('')}
                                    </datalist>
                                </label>
                                <label class="so23-field">
                                    <span>ID рекламных кампаний</span>
                                    <input name="direct_campaign_ids" value="${html(list(direct.campaign_ids || []))}" placeholder="10001, 10002">
                                </label>
                            </div>
                        </section>

                        <section class="so23-section">
                            <div class="so23-section-head">
                                <div><span>Шаг 4</span><h4>Яндекс Вебмастер</h4></div>
                                <small>${sources.webmaster?.status || 'не настроен'}</small>
                            </div>
                            <label class="so23-field">
                                <span>Host ID сайтов</span>
                                <textarea name="webmaster_host_ids" rows="3" placeholder="https:example.ru:443">${html(webmasterIds)}</textarea>
                                <small>Несколько значений — с новой строки или через запятую.</small>
                            </label>
                        </section>

                        <label class="so23-field">
                            <span>Комментарий</span>
                            <textarea name="notes" rows="3">${html(site.notes || '')}</textarea>
                        </label>
                        <div id="so23Message" class="so23-message"></div>
                        <footer class="so23-footer">
                            <button type="button" class="btn btn-secondary" data-so23-action="close">Отмена</button>
                            <button type="submit" class="btn btn-primary">Сохранить сайт и подключения</button>
                        </footer>
                    </form>
                </section>
            </div>
        `;

        const form = qs('#so23Form');
        form?.addEventListener('submit', save);
        qs('#so23WebsiteSelect')?.addEventListener('change', event => {
            const value = event.target.value;
            const input = form?.elements.url;
            if (input && value !== '__manual__') input.value = value;
            if (input && value === '__manual__' && currentExists) input.value = '';
        });
        form?.elements.direct_enabled?.addEventListener('change', toggleDirectFields);
        toggleDirectFields();
        form?.elements.name?.focus();
    }

    function toggleDirectFields() {
        const form = qs('#so23Form');
        if (!form) return;
        const enabled = Boolean(form.elements.direct_enabled?.checked);
        qsa('.so23-direct-fields input', form).forEach(input => {
            input.disabled = !enabled;
        });
    }

    function normalizeUrl(value) {
        return String(value || '').trim().replace(/\/$/, '').toLowerCase();
    }

    function csv(value) {
        return String(value || '')
            .split(/[\s,;]+/)
            .map(item => item.trim())
            .filter(Boolean);
    }

    function lines(value) {
        return String(value || '')
            .split(/[\r\n,;]+/)
            .map(item => item.trim())
            .filter(Boolean);
    }

    async function save(event) {
        event.preventDefault();
        if (state.loading) return;
        const form = event.currentTarget;
        const message = qs('#so23Message');
        state.loading = true;
        qsa('button, input, select, textarea', form).forEach(node => node.disabled = true);
        if (message) {
            message.className = 'so23-message is-loading';
            message.textContent = 'Сохраняем сайт и подключения…';
        }
        try {
            const payload = {
                site_id: Number(form.elements.site_id.value || 0),
                client_id: Number(form.elements.client_id.value || 0),
                project_id: Number(form.elements.project_id.value || 0),
                name: form.elements.name.value,
                url: form.elements.url.value,
                status: form.elements.status.value,
                sort_order: Number(form.elements.sort_order.value || 0),
                sync_to_bitrix: Boolean(form.elements.sync_to_bitrix?.checked),
                metrika_counter_ids: csv(form.elements.metrika_counter_ids.value),
                direct_enabled: Boolean(form.elements.direct_enabled.checked),
                direct_client_login: form.elements.direct_client_login.value,
                direct_campaign_ids: csv(form.elements.direct_campaign_ids.value),
                webmaster_host_ids: lines(form.elements.webmaster_host_ids.value),
                notes: form.elements.notes.value
            };
            const result = await request('save', {}, {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            if (message) {
                message.className = result.warning
                    ? 'so23-message is-warning'
                    : 'so23-message is-success';
                message.textContent = result.message;
            }
            setTimeout(() => {
                if (window.PortalNavigation?.activate) {
                    window.PortalNavigation.activate('clients');
                }
                location.reload();
            }, result.warning ? 1600 : 500);
        } catch (error) {
            state.loading = false;
            qsa('button, input, select, textarea', form).forEach(node => node.disabled = false);
            toggleDirectFields();
            if (message) {
                message.className = 'so23-message is-error';
                message.textContent = error.message;
            }
        }
    }

    document.addEventListener('click', event => {
        const closeButton = event.target.closest?.('[data-so23-action="close"]');
        if (closeButton) {
            event.preventDefault();
            close();
            return;
        }
        if (event.target.classList?.contains('so23-backdrop')) {
            close();
            return;
        }

        const button = event.target.closest?.(
            '[data-lk2-action="new-site"], [data-lk2-action="edit-site"]'
        );
        if (!button) return;
        const clientId = activeClientId();
        const projectId = Number(button.dataset.projectId || 0);
        const siteId = button.dataset.lk2Action === 'edit-site'
            ? Number(button.dataset.siteId || 0)
            : 0;
        if (clientId <= 0 || projectId <= 0) return;
        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        open(clientId, projectId, siteId);
    }, true);
})();
