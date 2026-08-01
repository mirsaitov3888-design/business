    /* BITRIX24_STEP1_JS */
    let bitrix24Loaded = false;
    let bitrix24State = {
        link: null,
        projects: [],
        companies: []
    };

    function bitrix24Duration(seconds) {
        const total = Math.max(0, Number(seconds || 0));
        const hours = Math.floor(total / 3600);
        const minutes = Math.floor((total % 3600) / 60);

        return `${hours}:${String(minutes).padStart(2, '0')}`;
    }

    function bitrix24DateTime(value) {
        if (!value) return '—';
        const parsed = new Date(String(value).replace(' ', 'T'));

        if (Number.isNaN(parsed.getTime())) {
            return String(value);
        }

        return parsed.toLocaleString('ru-RU', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        });
    }

    function bitrix24ShowMessage(type, message) {
        const root = $('#bitrix24Message');
        if (!root) return;
        root.className = type ? `alert alert-${type}` : '';
        root.textContent = message || '';
    }

    function bitrix24SetBadge(type, text) {
        const badge = $('#bitrix24ConnectionBadge');
        if (!badge) return;
        badge.className = `bitrix24-badge ${type}`;
        badge.textContent = text;
    }

    function bitrix24RenderProfile(status) {
        const root = $('#bitrix24Profile');
        if (!root) return;

        if (!status.connected) {
            root.innerHTML = `
                <strong>Подключение не настроено</strong>
                <span>${escapeHtml(status.error || 'Проверьте URL входящего вебхука.')}</span>
            `;
            bitrix24SetBadge('negative', 'Нет связи');
            return;
        }

        const profile = status.profile || {};
        const userName = [
            profile.NAME || profile.name,
            profile.LAST_NAME || profile.lastName
        ].filter(Boolean).join(' ') || 'Пользователь Битрикс24';

        root.innerHTML = `
            <strong>${escapeHtml(userName)}</strong>
            <span>${escapeHtml(status.portal_host || '')}</span>
            <span>${profile.ADMIN === true || profile.ADMIN === 'Y' ? 'Администратор портала' : 'Права пользователя вебхука'}</span>
        `;
        bitrix24SetBadge('positive', 'Подключено');
    }

    function bitrix24FillSelect(select, rows, options) {
        if (!select) return;
        const current = String(options.current || '');
        select.innerHTML = options.empty
            ? `<option value="">${escapeHtml(options.empty)}</option>`
            : '';

        rows.forEach(row => {
            const id = String(options.id(row) || '');
            const title = String(options.title(row) || id);
            if (!id) return;
            const option = document.createElement('option');
            option.value = id;
            option.textContent = title;
            option.selected = id === current;
            select.append(option);
        });
    }

    function bitrix24ApplyLink() {
        const form = $('#bitrix24LinkForm');
        if (!form) return;
        const link = bitrix24State.link || {};

        bitrix24FillSelect(
            form.elements.bitrix_group_id,
            bitrix24State.projects,
            {
                current: link.bitrix_group_id,
                empty: 'Выберите проект Битрикс24',
                id: row => row.ID ?? row.id,
                title: row => row.NAME ?? row.name ?? `Проект #${row.ID ?? row.id}`
            }
        );

        bitrix24FillSelect(
            form.elements.bitrix_company_id,
            bitrix24State.companies,
            {
                current: link.bitrix_company_id,
                empty: 'Без привязки к компании',
                id: row => row.id ?? row.ID,
                title: row => row.title ?? row.TITLE ?? `Компания #${row.id ?? row.ID}`
            }
        );

        form.elements.report_tag.value = link.report_tag || 'client_report';
    }

    function bitrix24RenderPreview(data) {
        const summaryRoot = $('#bitrix24Summary');
        const body = $('#bitrix24TasksBody');
        const lastSync = $('#bitrix24LastSync');
        if (!summaryRoot || !body) return;

        const summary = data?.summary || {};
        summaryRoot.innerHTML = [
            ['Задач с тегом', number.format(summary.tasks_count || 0), 'Все синхронизированные'],
            ['Задач с временем', number.format(summary.tasks_with_time || 0), 'Есть записи за период'],
            ['Трудозатраты', bitrix24Duration(summary.elapsed_seconds), 'Часы и минуты'],
            ['Записей времени', number.format(summary.elapsed_entries || 0), 'Строки учёта времени']
        ].map(([title, value, note]) => `
            <article class="metric-card">
                <span>${escapeHtml(title)}</span>
                <strong>${escapeHtml(value)}</strong>
                <small>${escapeHtml(note)}</small>
            </article>
        `).join('');

        const tasks = data?.tasks || [];

        if (!tasks.length) {
            body.innerHTML = `
                <tr>
                    <td colspan="6" class="muted">
                        Задачи с выбранным тегом пока не синхронизированы или не найдены.
                    </td>
                </tr>
            `;
        } else {
            body.innerHTML = tasks.map(task => `
                <tr>
                    <td>
                        <strong>#${escapeHtml(String(task.bitrix_task_id))} ${escapeHtml(task.title || '')}</strong>
                        ${task.closed_date ? `<small class="bitrix24-task-date">Закрыта: ${escapeHtml(bitrix24DateTime(task.closed_date))}</small>` : ''}
                    </td>
                    <td>${escapeHtml(task.status || '—')}</td>
                    <td>${escapeHtml(task.responsible_name || '—')}</td>
                    <td>
                        <div class="bitrix24-tags">
                            ${(task.tags || []).map(tag => `<span>${escapeHtml(tag)}</span>`).join('') || '<span class="muted">—</span>'}
                        </div>
                    </td>
                    <td class="num">${number.format(task.elapsed_entries || 0)}</td>
                    <td class="num"><strong>${escapeHtml(bitrix24Duration(task.period_seconds))}</strong></td>
                </tr>
            `).join('');
        }

        if (lastSync) {
            lastSync.textContent = data?.last_sync?.created_at
                ? `Последняя синхронизация: ${bitrix24DateTime(data.last_sync.created_at)}`
                : 'Синхронизация ещё не выполнялась';
        }
    }

    async function bitrix24LoadPreview() {
        const dateFrom = $('#bitrix24DateFrom')?.value || '';
        const dateTo = $('#bitrix24DateTo')?.value || '';

        if (!dateFrom || !dateTo) return;

        const result = await api(
            `/api.php?action=bitrix24_preview&date1=${encodeURIComponent(dateFrom)}&date2=${encodeURIComponent(dateTo)}`
        );
        bitrix24RenderPreview(result.preview || {});
    }

    async function bitrix24Load(force = false) {
        if (!$('#bitrix24LinkForm')) return;
        if (bitrix24Loaded && !force) return;

        bitrix24ShowMessage('', '');
        bitrix24SetBadge('neutral', 'Проверяем');

        try {
            const status = await api('/api.php?action=bitrix24_status');
            bitrix24State.link = status.link || null;
            bitrix24RenderProfile(status);

            if (!status.connected) {
                return;
            }

            const [projects, companies] = await Promise.all([
                api('/api.php?action=bitrix24_projects'),
                api('/api.php?action=bitrix24_companies')
            ]);
            bitrix24State.projects = projects.projects || [];
            bitrix24State.companies = companies.companies || [];
            bitrix24ApplyLink();
            await bitrix24LoadPreview();
            bitrix24Loaded = true;
        } catch (error) {
            bitrix24SetBadge('negative', 'Ошибка');
            bitrix24ShowMessage('error', error.message);
        }
    }

    $('.nav-link[data-section="bitrix24"]')?.addEventListener(
        'click',
        () => bitrix24Load()
    );

    $('#bitrix24Refresh')?.addEventListener('click', async event => {
        event.currentTarget.disabled = true;
        try {
            bitrix24Loaded = false;
            await bitrix24Load(true);
        } finally {
            event.currentTarget.disabled = false;
        }
    });

    $('#bitrix24LinkForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const submit = form.querySelector('button[type="submit"]');
        const groupOption = form.elements.bitrix_group_id.selectedOptions[0];
        const companyOption = form.elements.bitrix_company_id.selectedOptions[0];
        submit.disabled = true;
        bitrix24ShowMessage('', 'Сохраняем связь…');

        try {
            const result = await api('/api.php?action=bitrix24_save_link', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: csrf,
                    project_id: Number(form.elements.project_id.value || 0),
                    bitrix_group_id: Number(form.elements.bitrix_group_id.value || 0),
                    bitrix_group_name: groupOption?.textContent || '',
                    bitrix_company_id: Number(form.elements.bitrix_company_id.value || 0),
                    bitrix_company_name: form.elements.bitrix_company_id.value
                        ? (companyOption?.textContent || '')
                        : '',
                    report_tag: form.elements.report_tag.value.trim()
                })
            });
            bitrix24State.link = result.link || null;
            bitrix24ApplyLink();
            bitrix24ShowMessage('success', 'Связь с проектом Битрикс24 сохранена.');
        } catch (error) {
            bitrix24ShowMessage('error', error.message);
        } finally {
            submit.disabled = false;
        }
    });

    $('#bitrix24Sync')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        const dateFrom = $('#bitrix24DateFrom')?.value || '';
        const dateTo = $('#bitrix24DateTo')?.value || '';

        if (!dateFrom || !dateTo) {
            bitrix24ShowMessage('error', 'Укажите период синхронизации.');
            return;
        }

        button.disabled = true;
        bitrix24ShowMessage('', 'Загружаем задачи и трудозатраты. Это может занять до нескольких минут…');

        try {
            const result = await api('/api.php?action=bitrix24_sync', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: csrf,
                    date_from: dateFrom,
                    date_to: dateTo
                })
            });
            bitrix24RenderPreview(result.preview || {});
            const warnings = result.warnings || [];
            bitrix24ShowMessage(
                warnings.length ? 'warning' : 'success',
                warnings.length
                    ? `Синхронизация завершена. Ограничения: ${warnings.join(' · ')}`
                    : 'Задачи и трудозатраты синхронизированы.'
            );
        } catch (error) {
            bitrix24ShowMessage('error', error.message);
        } finally {
            button.disabled = false;
        }
    });

    $('#bitrix24DateFrom')?.addEventListener('change', () => bitrix24LoadPreview());
    $('#bitrix24DateTo')?.addEventListener('change', () => bitrix24LoadPreview());
