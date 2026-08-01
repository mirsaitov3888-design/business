    /* CLIENT_ACCESS_PORTAL_V16_JS */
    let portalContext = null;
    let portalContextLoaded = false;
    let portalClientsLoaded = false;
    let portalClientsState = {items: [], summary: {}, options: {}, selectedId: 0, detail: null};
    let portalNotificationType = '';
    let portalNotificationsLoaded = false;

    const portalRoleLabels = {
        administrator: 'Администратор',
        moderator: 'Модератор',
        manager: 'Менеджер',
        client: 'Клиент'
    };
    const portalClientStatusLabels = {
        active: 'Активен',
        pending: 'Ожидает подключения',
        paused: 'Приостановлен',
        archived: 'Архивный'
    };
    const portalNotificationTypeLabels = {
        system: 'Системное',
        operational: 'Для менеджера',
        client: 'Клиентское'
    };
    const portalSeverityLabels = {
        critical: 'Критично',
        warning: 'Предупреждение',
        info: 'Информация'
    };

    function portalShowMessage(rootId, type, text) {
        const root = $(rootId);
        if (!root) return;
        root.className = type ? `alert alert-${type}` : '';
        root.textContent = text || '';
    }

    function portalDate(value) {
        if (!value) return '—';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime())
            ? String(value)
            : parsed.toLocaleString('ru-RU', {
                day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'
            });
    }

    function portalUpdateUnreadBadge(count) {
        const badge = $('#portalNotificationsBadge');
        if (!badge) return;
        const value = Math.max(0, Number(count || 0));
        badge.textContent = value > 99 ? '99+' : String(value);
        badge.classList.toggle('hidden', value <= 0);
    }

    function portalApplyRoleNavigation(context) {
        const visible = new Set(context.visible_sections || []);
        document.querySelectorAll('.sidebar .nav-link[data-section]').forEach(item => {
            const section = String(item.dataset.section || '');
            item.classList.toggle('hidden', !visible.has(section));
        });

        const createClient = $('#portalClientCreate');
        const createNotification = $('#portalNotificationCreate');
        const canManage = Boolean(context.permissions?.manage_clients);
        const canNotify = Boolean(context.permissions?.create_notifications);
        createClient?.classList.toggle('hidden', !canManage);
        createNotification?.classList.toggle('hidden', !canNotify);

        const systemOption = $('#portalNotificationForm')?.elements.notification_type?.querySelector('option[value="system"]');
        if (systemOption) systemOption.disabled = context.user?.role !== 'administrator';
        document.body.dataset.portalRole = context.user?.role || 'client';
        portalUpdateUnreadBadge(context.unread_notifications || 0);
    }

    async function portalLoadContext(force = false) {
        if (portalContextLoaded && !force) return portalContext;
        try {
            portalContext = await api('/api.php?action=portal_context');
            portalContextLoaded = true;
            portalApplyRoleNavigation(portalContext);
            return portalContext;
        } catch (error) {
            console.error('Portal context error', error);
            return null;
        }
    }

    function portalMetric(title, value, note = '', tone = '') {
        return `
            <article class="metric-card ${tone ? `portal-tone-${escapeHtml(tone)}` : ''}">
                <span>${escapeHtml(title)}</span>
                <strong>${escapeHtml(String(value ?? 0))}</strong>
                <small>${escapeHtml(note)}</small>
            </article>`;
    }

    function portalRenderClientSummary(summary = {}) {
        const root = $('#portalClientsSummary');
        if (!root) return;
        root.innerHTML = [
            portalMetric('Всего клиентов', summary.total || 0, 'Доступны текущему пользователю'),
            portalMetric('Активные', summary.active || 0, 'В работе', 'positive'),
            portalMetric('Ожидают подключения', summary.pending || 0, 'Нужна настройка', summary.pending ? 'warning' : ''),
            portalMetric('Приостановлены', summary.paused || 0, 'Обслуживание остановлено', summary.paused ? 'warning' : ''),
            portalMetric('Без менеджера', summary.without_manager || 0, 'Требуется назначение', summary.without_manager ? 'negative' : '')
        ].join('');
    }

    function portalRenderClientFilters(options = {}) {
        const manager = $('#portalClientManagerFilter');
        if (manager) {
            const current = manager.value;
            manager.innerHTML = '<option value="">Все</option>' + (options.managers || []).map(item => `
                <option value="${Number(item.id)}">${escapeHtml(item.name || item.email || `Пользователь #${item.id}`)}</option>`
            ).join('');
            manager.value = current;
        }
    }

    function portalRenderClientsList(items = []) {
        const root = $('#portalClientsList');
        if (!root) return;
        portalClientsState.items = items;
        if (!items.length) {
            root.innerHTML = '<div class="reports-empty"><strong>Клиенты не найдены</strong><span>Измените фильтры или добавьте первого клиента.</span></div>';
            portalClientsState.selectedId = 0;
            portalRenderClientEmpty();
            return;
        }

        root.innerHTML = items.map(item => {
            const selected = Number(item.id) === Number(portalClientsState.selectedId);
            return `
                <button type="button" class="portal-client-item ${selected ? 'is-active' : ''}" data-portal-client-id="${Number(item.id)}">
                    <span class="portal-client-item-head">
                        <strong>${escapeHtml(item.name)}</strong>
                        <em class="portal-client-status ${escapeHtml(item.status || 'active')}">${escapeHtml(portalClientStatusLabels[item.status] || item.status)}</em>
                    </span>
                    <span class="portal-client-contact">${escapeHtml(item.contact_name || item.contact_email || 'Контакт не указан')}</span>
                    <span class="portal-client-item-meta">
                        <span>Менеджер: ${escapeHtml(item.manager_name || 'не назначен')}</span>
                        <span>Проекты: ${Number(item.projects_count || 0)}</span>
                        <span>Сайты: ${Number(item.sites_count || 0)}</span>
                        <span class="${Number(item.open_notifications || 0) > 0 ? 'has-problem' : ''}">События: ${Number(item.open_notifications || 0)}</span>
                    </span>
                </button>`;
        }).join('');
    }

    function portalRenderClientEmpty() {
        $('#portalClientEmpty')?.classList.remove('hidden');
        $('#portalClientDetail')?.classList.add('hidden');
    }

    function portalListBlock(title, rows, renderRow) {
        return `
            <article class="portal-detail-block">
                <div class="portal-detail-block-head"><strong>${escapeHtml(title)}</strong><span>${rows.length}</span></div>
                ${rows.length ? `<div class="portal-detail-rows">${rows.map(renderRow).join('')}</div>` : '<p class="muted">Нет привязанных данных.</p>'}
            </article>`;
    }

    function portalRenderClientDetail(data) {
        portalClientsState.detail = data;
        const root = $('#portalClientDetail');
        if (!root) return;
        const client = data.client || {};
        $('#portalClientEmpty')?.classList.add('hidden');
        root.classList.remove('hidden');
        document.querySelectorAll('[data-portal-client-id]').forEach(item => {
            item.classList.toggle('is-active', Number(item.dataset.portalClientId) === Number(client.id));
        });

        const canManage = Boolean(portalContext?.permissions?.manage_clients);
        root.innerHTML = `
            <div class="portal-client-detail-head">
                <div>
                    <p class="eyebrow">${escapeHtml(portalClientStatusLabels[client.status] || client.status || 'Клиент')}</p>
                    <h2>${escapeHtml(client.name || 'Клиент')}</h2>
                    <p class="muted">Менеджер: ${escapeHtml(client.manager_name || 'не назначен')}</p>
                </div>
                ${canManage ? '<button type="button" class="button" id="portalClientEdit">Редактировать</button>' : ''}
            </div>
            <div class="portal-contact-grid">
                <div><span>Контакт</span><strong>${escapeHtml(client.contact_name || '—')}</strong></div>
                <div><span>Email</span><strong>${escapeHtml(client.contact_email || '—')}</strong></div>
                <div><span>Телефон</span><strong>${escapeHtml(client.contact_phone || '—')}</strong></div>
            </div>
            ${client.notes ? `<div class="portal-client-notes"><strong>Внутренние заметки</strong><p>${escapeHtml(client.notes)}</p></div>` : ''}
            <div class="portal-detail-grid">
                ${portalListBlock('Проекты', data.projects || [], row => `
                    <div><strong>${escapeHtml(row.display_name || `Проект #${row.id}`)}</strong><span>${escapeHtml(row.display_url || '')}</span></div>`)}
                ${portalListBlock('Сайты', data.sites || [], row => `
                    <div><strong>${escapeHtml(row.name || row.host || `Сайт #${row.id}`)}</strong><span>${escapeHtml(row.base_url || row.host || '')} · ${escapeHtml(row.last_status || 'нет данных')}</span></div>`)}
                ${portalListBlock('Клиентские аккаунты', data.users || [], row => `
                    <div><strong>${escapeHtml(row.name || row.email || `Пользователь #${row.id}`)}</strong><span>${escapeHtml(row.email || '')}</span></div>`)}
                ${portalListBlock('Последние уведомления', data.notifications || [], row => `
                    <div><strong>${escapeHtml(row.title)}</strong><span>${escapeHtml(portalDate(row.created_at))} · ${escapeHtml(portalSeverityLabels[row.severity] || row.severity)}</span></div>`)}
            </div>`;
        $('#portalClientEdit')?.addEventListener('click', () => portalOpenClientEditor(client));
    }

    function portalClientFilters() {
        return {
            search: $('#portalClientSearch')?.value.trim() || '',
            status: $('#portalClientStatusFilter')?.value || '',
            manager_user_id: Number($('#portalClientManagerFilter')?.value || 0)
        };
    }

    async function portalClientsLoad(force = false) {
        if (portalClientsLoaded && !force) return;
        portalShowMessage('#portalClientsMessage', '', 'Загрузка данных');
        try {
            const filters = portalClientFilters();
            const query = new URLSearchParams();
            if (filters.search) query.set('search', filters.search);
            if (filters.status) query.set('status', filters.status);
            if (filters.manager_user_id) query.set('manager_user_id', String(filters.manager_user_id));
            const result = await api(`/api.php?action=clients_dashboard&${query.toString()}`);
            portalClientsState.summary = result.summary || {};
            portalClientsState.options = result.options || {};
            portalRenderClientSummary(result.summary || {});
            portalRenderClientFilters(result.options || {});
            portalRenderClientsList(result.items || []);
            portalClientsLoaded = true;
            portalShowMessage('#portalClientsMessage', '', '');
            if (!portalClientsState.selectedId && result.items?.length) {
                await portalClientSelect(Number(result.items[0].id));
            } else if (portalClientsState.selectedId) {
                await portalClientSelect(portalClientsState.selectedId);
            }
        } catch (error) {
            portalShowMessage('#portalClientsMessage', 'error', error.message);
        }
    }

    async function portalClientSelect(clientId) {
        if (!clientId) return;
        portalClientsState.selectedId = Number(clientId);
        const root = $('#portalClientDetail');
        if (root) root.innerHTML = '<div class="reports-empty"><strong>Загрузка данных</strong><span>Получаем карточку клиента.</span></div>';
        try {
            const data = await api(`/api.php?action=client_detail&client_id=${encodeURIComponent(clientId)}`);
            portalRenderClientDetail(data);
        } catch (error) {
            portalShowMessage('#portalClientsMessage', 'error', error.message);
        }
    }

    function portalCheckboxOptions(rootId, rows, selectedIds, name, labelRenderer) {
        const root = $(rootId);
        if (!root) return;
        const selected = new Set((selectedIds || []).map(Number));
        root.innerHTML = rows.length ? rows.map(row => `
            <label><input type="checkbox" name="${escapeHtml(name)}" value="${Number(row.id)}" ${selected.has(Number(row.id)) ? 'checked' : ''}><span>${labelRenderer(row)}</span></label>
        `).join('') : '<span class="muted">Нет доступных вариантов.</span>';
    }

    function portalOpenClientEditor(client = null) {
        const editor = $('#portalClientEditor');
        const form = $('#portalClientForm');
        if (!editor || !form) return;
        const options = portalClientsState.options || {};
        const detail = client ? portalClientsState.detail : null;
        form.reset();
        form.elements.id.value = client?.id || 0;
        form.elements.name.value = client?.name || '';
        form.elements.status.value = client?.status || 'active';
        form.elements.contact_name.value = client?.contact_name || '';
        form.elements.contact_email.value = client?.contact_email || '';
        form.elements.contact_phone.value = client?.contact_phone || '';
        form.elements.notes.value = client?.notes || '';

        form.elements.manager_user_id.innerHTML = '<option value="">Не назначен</option>' + (options.managers || []).map(row => `
            <option value="${Number(row.id)}">${escapeHtml(row.name || row.email || `Пользователь #${row.id}`)}</option>`
        ).join('');
        form.elements.manager_user_id.value = client?.manager_user_id || '';

        portalCheckboxOptions('#portalClientProjectsOptions', options.projects || [], (detail?.projects || []).map(row => row.id), 'project_ids[]', row => `${escapeHtml(row.display_name || `Проект #${row.id}`)}${row.display_url ? `<small>${escapeHtml(row.display_url)}</small>` : ''}`);
        portalCheckboxOptions('#portalClientSitesOptions', options.sites || [], (detail?.sites || []).map(row => row.id), 'site_ids[]', row => `${escapeHtml(row.name || row.host || `Сайт #${row.id}`)}<small>${escapeHtml(row.base_url || row.host || '')}</small>`);
        portalCheckboxOptions('#portalClientUsersOptions', options.client_users || [], (detail?.users || []).map(row => row.id), 'user_ids[]', row => `${escapeHtml(row.name || row.email || `Пользователь #${row.id}`)}<small>${escapeHtml(row.email || '')}</small>`);

        const title = $('#portalClientEditorTitle');
        if (title) title.textContent = client ? `Редактирование: ${client.name}` : 'Новый клиент';
        editor.classList.remove('hidden');
        editor.setAttribute('aria-hidden', 'false');
    }

    function portalCloseClientEditor() {
        const editor = $('#portalClientEditor');
        editor?.classList.add('hidden');
        editor?.setAttribute('aria-hidden', 'true');
    }

    function portalFormValues(form) {
        const selected = name => Array.from(form.querySelectorAll(`input[name="${name}"]:checked`)).map(item => Number(item.value));
        return {
            id: Number(form.elements.id.value || 0),
            name: form.elements.name.value.trim(),
            status: form.elements.status.value,
            manager_user_id: Number(form.elements.manager_user_id.value || 0),
            contact_name: form.elements.contact_name.value.trim(),
            contact_email: form.elements.contact_email.value.trim(),
            contact_phone: form.elements.contact_phone.value.trim(),
            notes: form.elements.notes.value.trim(),
            project_ids: selected('project_ids[]'),
            site_ids: selected('site_ids[]'),
            user_ids: selected('user_ids[]')
        };
    }

    function portalNotificationClass(item) {
        return `portal-notification-item severity-${escapeHtml(item.severity || 'info')} ${item.is_unread ? 'is-unread' : ''}`;
    }

    function portalRenderNotifications(items = []) {
        const root = $('#portalNotificationsList');
        if (!root) return;
        if (!items.length) {
            root.innerHTML = '<div class="reports-empty"><strong>Уведомлений нет</strong><span>Для выбранных фильтров события не найдены.</span></div>';
            return;
        }
        root.innerHTML = items.map(item => `
            <article class="${portalNotificationClass(item)}" data-portal-notification-id="${Number(item.id)}" data-unread="${item.is_unread ? '1' : '0'}">
                <div class="portal-notification-icon">${item.severity === 'critical' ? '!' : item.severity === 'warning' ? '△' : 'i'}</div>
                <div class="portal-notification-body">
                    <div class="portal-notification-head">
                        <div><span>${escapeHtml(portalNotificationTypeLabels[item.notification_type] || item.notification_type)}${item.client_name ? ` · ${escapeHtml(item.client_name)}` : ''}</span><strong>${escapeHtml(item.title)}</strong></div>
                        <time>${escapeHtml(portalDate(item.created_at))}</time>
                    </div>
                    <p>${escapeHtml(item.message || '')}</p>
                    <div class="portal-notification-meta">
                        <em>${escapeHtml(portalSeverityLabels[item.severity] || item.severity)}</em>
                        ${item.site_name ? `<span>Сайт: ${escapeHtml(item.site_name)}</span>` : ''}
                        ${item.project_name ? `<span>Проект: ${escapeHtml(item.project_name)}</span>` : ''}
                        ${item.is_unread ? '<button type="button" data-portal-mark-read>Прочитано</button>' : ''}
                    </div>
                </div>
            </article>`).join('');
    }

    async function portalNotificationsLoad(force = false) {
        if (portalNotificationsLoaded && !force) return;
        portalShowMessage('#portalNotificationsMessage', '', 'Загрузка данных');
        try {
            const query = new URLSearchParams();
            if (portalNotificationType) query.set('notification_type', portalNotificationType);
            const severity = $('#portalNotificationsSeverity')?.value || '';
            if (severity) query.set('severity', severity);
            if ($('#portalNotificationsUnreadOnly')?.checked) query.set('unread_only', '1');
            const result = await api(`/api.php?action=notifications_center&${query.toString()}`);
            portalRenderNotifications(result.items || []);
            portalUpdateUnreadBadge(result.unread_count || 0);
            portalNotificationsLoaded = true;
            portalShowMessage('#portalNotificationsMessage', '', '');
        } catch (error) {
            portalShowMessage('#portalNotificationsMessage', 'error', error.message);
        }
    }

    function portalOpenNotificationEditor() {
        const editor = $('#portalNotificationEditor');
        const form = $('#portalNotificationForm');
        if (!editor || !form) return;
        form.reset();
        const clients = portalClientsState.items || [];
        form.elements.client_id.innerHTML = '<option value="">Не выбран</option>' + clients.map(row => `<option value="${Number(row.id)}">${escapeHtml(row.name)}</option>`).join('');
        if (portalClientsState.selectedId) form.elements.client_id.value = String(portalClientsState.selectedId);
        editor.classList.remove('hidden');
        editor.setAttribute('aria-hidden', 'false');
    }

    function portalCloseNotificationEditor() {
        const editor = $('#portalNotificationEditor');
        editor?.classList.add('hidden');
        editor?.setAttribute('aria-hidden', 'true');
    }

    $('.nav-link[data-section="clients"]')?.addEventListener('click', async () => {
        await portalLoadContext();
        portalClientsLoaded = false;
        await portalClientsLoad(true);
    });
    $('.nav-link[data-section="notifications"]')?.addEventListener('click', async () => {
        await portalLoadContext(true);
        portalNotificationsLoaded = false;
        await portalNotificationsLoad(true);
    });

    $('#portalClientsList')?.addEventListener('click', event => {
        const item = event.target.closest('[data-portal-client-id]');
        if (item) portalClientSelect(Number(item.dataset.portalClientId || 0));
    });
    $('#portalClientCreate')?.addEventListener('click', () => portalOpenClientEditor());
    $('#portalClientEditorClose')?.addEventListener('click', portalCloseClientEditor);
    $('#portalNotificationCreate')?.addEventListener('click', portalOpenNotificationEditor);
    $('#portalNotificationEditorClose')?.addEventListener('click', portalCloseNotificationEditor);

    let portalClientSearchTimer = null;
    $('#portalClientSearch')?.addEventListener('input', () => {
        clearTimeout(portalClientSearchTimer);
        portalClientSearchTimer = setTimeout(() => {
            portalClientsLoaded = false;
            portalClientsLoad(true);
        }, 350);
    });
    $('#portalClientStatusFilter')?.addEventListener('change', () => {
        portalClientsLoaded = false;
        portalClientsLoad(true);
    });
    $('#portalClientManagerFilter')?.addEventListener('change', () => {
        portalClientsLoaded = false;
        portalClientsLoad(true);
    });

    $('#portalClientForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const result = await api('/api.php?action=client_save', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf, client: portalFormValues(form)})
            });
            portalClientsState.selectedId = Number(result.client_id || 0);
            portalCloseClientEditor();
            portalClientsLoaded = false;
            await portalClientsLoad(true);
            portalShowMessage('#portalClientsMessage', 'success', result.message || 'Клиент сохранён.');
        } catch (error) {
            portalShowMessage('#portalClientsMessage', 'error', error.message);
        } finally {
            button.disabled = false;
        }
    });

    document.querySelector('.portal-notification-filters')?.addEventListener('click', event => {
        const button = event.target.closest('[data-portal-notification-type]');
        if (!button) return;
        portalNotificationType = String(button.dataset.portalNotificationType || '');
        document.querySelectorAll('[data-portal-notification-type]').forEach(item => item.classList.toggle('is-active', item === button));
        portalNotificationsLoaded = false;
        portalNotificationsLoad(true);
    });
    $('#portalNotificationsUnreadOnly')?.addEventListener('change', () => {
        portalNotificationsLoaded = false;
        portalNotificationsLoad(true);
    });
    $('#portalNotificationsSeverity')?.addEventListener('change', () => {
        portalNotificationsLoaded = false;
        portalNotificationsLoad(true);
    });

    $('#portalNotificationsList')?.addEventListener('click', async event => {
        const item = event.target.closest('[data-portal-notification-id]');
        if (!item || item.dataset.unread !== '1') return;
        if (!event.target.closest('[data-portal-mark-read]') && event.target.closest('a,button')) return;
        try {
            await api('/api.php?action=notification_mark_read', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf, notification_id: Number(item.dataset.portalNotificationId || 0)})
            });
            portalNotificationsLoaded = false;
            await portalNotificationsLoad(true);
            portalContextLoaded = false;
            await portalLoadContext(true);
        } catch (error) {
            portalShowMessage('#portalNotificationsMessage', 'error', error.message);
        }
    });

    $('#portalNotificationsReadAll')?.addEventListener('click', async event => {
        event.currentTarget.disabled = true;
        try {
            await api('/api.php?action=notifications_mark_all_read', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf})
            });
            portalNotificationsLoaded = false;
            await portalNotificationsLoad(true);
            portalContextLoaded = false;
            await portalLoadContext(true);
        } catch (error) {
            portalShowMessage('#portalNotificationsMessage', 'error', error.message);
        } finally {
            event.currentTarget.disabled = false;
        }
    });

    $('#portalNotificationForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const result = await api('/api.php?action=notification_create', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: csrf,
                    notification: {
                        notification_type: form.elements.notification_type.value,
                        severity: form.elements.severity.value,
                        client_id: Number(form.elements.client_id.value || 0),
                        title: form.elements.title.value.trim(),
                        message: form.elements.message.value.trim()
                    }
                })
            });
            portalCloseNotificationEditor();
            portalNotificationsLoaded = false;
            await portalNotificationsLoad(true);
            portalShowMessage('#portalNotificationsMessage', 'success', result.message || 'Уведомление создано.');
        } catch (error) {
            portalShowMessage('#portalNotificationsMessage', 'error', error.message);
        } finally {
            button.disabled = false;
        }
    });

    setTimeout(() => portalLoadContext(true), 0);
    setInterval(() => portalLoadContext(true), 30000);
