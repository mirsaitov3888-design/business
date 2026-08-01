    /* SITE_MONITORING_MODULE_JS */
    let monitoringLoaded = false;
    let monitoringState = {
        sites: [],
        selectedId: 0,
        detail: null,
        notificationSettings: null
    };
    const monitoringNumber = new Intl.NumberFormat('ru-RU', {maximumFractionDigits: 3});

    function monitoringShowMessage(type, text) {
        const root = $('#monitoringMessage');
        if (!root) return;
        root.className = type ? `alert alert-${type}` : '';
        root.textContent = text || '';
    }

    function monitoringDate(value, withTime = true) {
        if (!value) return '—';
        const parsed = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) return String(value);
        return parsed.toLocaleString('ru-RU', withTime
            ? {day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit'}
            : {day: '2-digit', month: '2-digit', year: 'numeric'}
        );
    }

    function monitoringDuration(seconds) {
        const total = Math.max(0, Number(seconds || 0));
        const days = Math.floor(total / 86400);
        const hours = Math.floor((total % 86400) / 3600);
        const minutes = Math.floor((total % 3600) / 60);
        if (days > 0) return `${days} д. ${hours} ч.`;
        if (hours > 0) return `${hours} ч. ${minutes} мин.`;
        return `${minutes} мин.`;
    }

    function monitoringStatusLabel(status) {
        return {
            up: 'Доступен',
            down: 'Недоступен',
            unknown: 'Нет данных'
        }[status] || status || 'Нет данных';
    }

    function monitoringSeverityLabel(value) {
        return {
            critical: 'Критично',
            warning: 'Предупреждение',
            info: 'Информация'
        }[value] || value || 'Информация';
    }

    function monitoringCategoryLabel(value) {
        return value === 'marketing' ? 'SEO / реклама' : 'Техническое';
    }

    function monitoringMetric(title, value, note, tone = '') {
        return `
            <article class="metric-card ${tone ? `monitoring-metric-${escapeHtml(tone)}` : ''}">
                <span>${escapeHtml(title)}</span>
                <strong>${escapeHtml(String(value ?? '—'))}</strong>
                <small>${escapeHtml(note || '')}</small>
            </article>`;
    }

    function monitoringRenderSummary(summary = {}) {
        const root = $('#monitoringSummary');
        if (!root) return;
        root.innerHTML = [
            monitoringMetric('Сайтов', monitoringNumber.format(summary.sites_count || 0), 'В активном проекте'),
            monitoringMetric('Доступны', monitoringNumber.format(summary.up_count || 0), 'Последняя проверка', 'positive'),
            monitoringMetric('Недоступны', monitoringNumber.format(summary.down_count || 0), 'После трёх попыток', summary.down_count ? 'negative' : ''),
            monitoringMetric('Открытые инциденты', monitoringNumber.format(summary.open_incidents || 0), 'Технические и маркетинговые', summary.open_incidents ? 'warning' : ''),
            monitoringMetric('Средний ответ', summary.average_response_ms == null ? '—' : `${monitoringNumber.format(summary.average_response_ms)} мс`, 'По последним проверкам')
        ].join('');
    }

    function monitoringRenderWorker(worker = {}) {
        const root = $('#monitoringWorkerStatus');
        if (!root) return;
        const details = worker.details || {};
        const status = worker.status || 'unknown';
        root.className = `monitoring-worker ${status === 'ok' ? 'positive' : status === 'stale' ? 'negative' : 'unknown'}`;
        if (status === 'ok') {
            root.innerHTML = `<strong>Worker работает</strong><span>Последний запуск: ${escapeHtml(monitoringDate(worker.updated_at))}</span>`;
        } else if (status === 'stale') {
            root.innerHTML = `<strong>Worker не отвечает</strong><span>Последняя активность: ${escapeHtml(monitoringDate(worker.updated_at))}</span>`;
        } else {
            root.innerHTML = '<strong>Worker</strong><span>Первый запуск ещё не зафиксирован.</span>';
        }
    }

    function monitoringRenderSites(sites = []) {
        monitoringState.sites = sites;
        const root = $('#monitoringSitesList');
        const count = $('#monitoringSitesCount');
        if (count) count.textContent = `${sites.length} шт.`;
        if (!root) return;

        if (!sites.length) {
            root.innerHTML = `
                <div class="reports-empty">
                    <strong>Сайты не добавлены</strong>
                    <span>Добавьте первый сайт — первичный аудит запустится автоматически.</span>
                </div>`;
            monitoringState.selectedId = 0;
            monitoringState.detail = null;
            monitoringRenderEmptyDetail();
            return;
        }

        root.innerHTML = sites.map(site => {
            const selected = Number(site.id) === Number(monitoringState.selectedId);
            const uptime = site.uptime_30d == null ? '—' : `${monitoringNumber.format(site.uptime_30d)}%`;
            return `
                <button type="button" class="monitoring-site-item ${selected ? 'is-active' : ''}" data-monitoring-site-id="${Number(site.id)}">
                    <span class="monitoring-site-item-head">
                        <strong>${escapeHtml(site.name)}</strong>
                        <em class="monitoring-status-dot ${escapeHtml(site.last_status || 'unknown')}">${escapeHtml(monitoringStatusLabel(site.last_status))}</em>
                    </span>
                    <span class="monitoring-site-url">${escapeHtml(site.host || site.base_url)}</span>
                    <span class="monitoring-site-item-metrics">
                        <span>Uptime: ${escapeHtml(uptime)}</span>
                        <span>${site.last_response_ms == null ? 'Нет времени ответа' : `${monitoringNumber.format(site.last_response_ms)} мс`}</span>
                        <span class="${Number(site.open_incidents || 0) > 0 ? 'has-problem' : ''}">Инциденты: ${monitoringNumber.format(site.open_incidents || 0)}</span>
                    </span>
                </button>`;
        }).join('');
    }

    function monitoringRenderEmptyDetail() {
        $('#monitoringEmptyDetail')?.classList.remove('hidden');
        $('#monitoringDetail')?.classList.add('hidden');
    }

    function monitoringRenderDetail(data) {
        monitoringState.detail = data;
        const site = data.site || {};
        const audit = data.audit || null;
        $('#monitoringEmptyDetail')?.classList.add('hidden');
        $('#monitoringDetail')?.classList.remove('hidden');
        const name = $('#monitoringDetailName');
        const url = $('#monitoringDetailUrl');
        if (name) name.textContent = site.name || 'Сайт';
        if (url) {
            url.textContent = site.base_url || '';
            url.href = site.base_url || '#';
        }

        $$('.monitoring-site-item').forEach(item => {
            item.classList.toggle('is-active', Number(item.dataset.monitoringSiteId || 0) === Number(site.id || 0));
        });

        monitoringRenderSiteMetrics(site, audit, data);
        monitoringRenderAuditSummary(audit?.summary || {});
        monitoringRenderAvailability(data.availability || []);
        monitoringRenderIndexing(audit);
        monitoringRenderInfrastructure(audit);
        monitoringRenderMetrika(site, audit);
        monitoringRenderIncidents(data.incidents || []);
        monitoringRenderEvents(data.events || []);
    }

    function monitoringRenderSiteMetrics(site, audit, data) {
        const root = $('#monitoringSiteMetrics');
        if (!root) return;
        const uptime = data.uptime_30d == null ? '—' : `${monitoringNumber.format(data.uptime_30d)}%`;
        const openIncidents = (data.incidents || []).filter(item => item.status === 'open').length;
        const sslDays = audit?.ssl_days_left;
        root.innerHTML = [
            monitoringMetric('Статус', monitoringStatusLabel(site.last_status), `HTTP ${site.last_http_code ?? '—'}`, site.last_status === 'up' ? 'positive' : site.last_status === 'down' ? 'negative' : ''),
            monitoringMetric('Uptime 30 дней', uptime, 'По фактическим проверкам'),
            monitoringMetric('Время ответа', site.last_response_ms == null ? '—' : `${monitoringNumber.format(site.last_response_ms)} мс`, `Порог: ${monitoringNumber.format(site.slow_threshold_ms || 3000)} мс`, site.last_response_ms > site.slow_threshold_ms ? 'warning' : ''),
            monitoringMetric('Открытые инциденты', monitoringNumber.format(openIncidents), 'Требуют внимания', openIncidents ? 'warning' : ''),
            monitoringMetric('SSL', sslDays == null ? '—' : `${monitoringNumber.format(sslDays)} дн.`, audit?.ssl_expires_at ? `до ${monitoringDate(audit.ssl_expires_at, false)}` : 'Данные недоступны', sslDays != null && sslDays <= 30 ? 'warning' : '')
        ].join('');
    }

    function monitoringRenderAuditSummary(summary = {}) {
        const root = $('#monitoringAuditSummary');
        if (!root) return;
        const groups = [
            ['critical', 'Критические проблемы'],
            ['warning', 'Предупреждения'],
            ['recommendation', 'Рекомендации'],
            ['ok', 'Успешные проверки']
        ];
        root.innerHTML = groups.map(([key, title]) => {
            const rows = Array.isArray(summary[key]) ? summary[key] : [];
            return `
                <article class="monitoring-summary-group ${key}">
                    <div class="monitoring-summary-group-head"><strong>${escapeHtml(title)}</strong><span>${rows.length}</span></div>
                    ${rows.length
                        ? `<ul>${rows.map(row => `<li>${escapeHtml(String(row))}</li>`).join('')}</ul>`
                        : '<p class="muted">Нет записей.</p>'}
                </article>`;
        }).join('');
    }

    function monitoringRenderAvailability(rows) {
        const chartRoot = $('#monitoringAvailabilityChart');
        const tableRoot = $('#monitoringAvailabilityTable');
        if (!chartRoot || !tableRoot) return;

        if (!rows.length) {
            chartRoot.innerHTML = '<div class="reports-empty"><strong>Проверок пока нет</strong><span>Worker добавит данные после первого запуска.</span></div>';
            tableRoot.innerHTML = '';
            return;
        }

        const width = 920;
        const height = 260;
        const padding = {left: 50, right: 18, top: 18, bottom: 34};
        const values = rows.map(row => row.is_up ? Number(row.response_ms || 0) : 0);
        const maxValue = Math.max(1000, ...values);
        const x = index => padding.left + (index / Math.max(1, rows.length - 1)) * (width - padding.left - padding.right);
        const y = value => height - padding.bottom - (value / maxValue) * (height - padding.top - padding.bottom);
        const upRows = rows.map((row, index) => row.is_up ? [x(index), y(Number(row.response_ms || 0))] : null).filter(Boolean);
        const path = upRows.map((point, index) => `${index === 0 ? 'M' : 'L'} ${point[0].toFixed(1)} ${point[1].toFixed(1)}`).join(' ');
        const grid = [0, .25, .5, .75, 1].map(part => {
            const value = Math.round(maxValue * part);
            const lineY = y(value);
            return `<line x1="${padding.left}" y1="${lineY}" x2="${width - padding.right}" y2="${lineY}" class="monitoring-chart-grid"/><text x="${padding.left - 8}" y="${lineY + 4}" text-anchor="end">${value}</text>`;
        }).join('');
        const points = rows.map((row, index) => {
            const pointX = x(index);
            const pointY = row.is_up ? y(Number(row.response_ms || 0)) : height - padding.bottom;
            return `<circle cx="${pointX.toFixed(1)}" cy="${pointY.toFixed(1)}" r="${row.is_up ? 3 : 5}" class="${row.is_up ? 'monitoring-chart-point-up' : 'monitoring-chart-point-down'}"><title>${escapeHtml(monitoringDate(row.checked_at))}: ${row.is_up ? `${row.response_ms || 0} мс` : `Ошибка, HTTP ${row.http_code || 0}`}</title></circle>`;
        }).join('');
        chartRoot.innerHTML = `
            <svg viewBox="0 0 ${width} ${height}" role="img" aria-label="График времени ответа сайта">
                ${grid}
                ${path ? `<path d="${path}" class="monitoring-chart-line"/>` : ''}
                ${points}
                <text x="${width / 2}" y="${height - 8}" text-anchor="middle">Последние ${rows.length} проверок</text>
                <text x="14" y="${height / 2}" transform="rotate(-90 14 ${height / 2})" text-anchor="middle">мс</text>
            </svg>`;

        const recent = [...rows].reverse().slice(0, 100);
        tableRoot.innerHTML = `
            <table class="data-table monitoring-checks-table">
                <thead><tr><th>Дата</th><th>Статус</th><th class="num">HTTP</th><th class="num">Время</th><th class="num">Попыток</th><th>Ошибка</th></tr></thead>
                <tbody>${recent.map(row => `
                    <tr>
                        <td>${escapeHtml(monitoringDate(row.checked_at))}</td>
                        <td><span class="monitoring-inline-status ${row.is_up ? 'positive' : 'negative'}">${row.is_up ? 'Доступен' : 'Ошибка'}</span></td>
                        <td class="num">${row.http_code ?? '—'}</td>
                        <td class="num">${row.response_ms == null ? '—' : `${monitoringNumber.format(row.response_ms)} мс`}</td>
                        <td class="num">${row.attempts ?? '—'}</td>
                        <td>${escapeHtml(row.error_text || '—')}</td>
                    </tr>`).join('')}</tbody>
            </table>`;
    }

    function monitoringAuditCard(title, value, note = '', tone = '') {
        return `
            <article class="monitoring-audit-card ${tone}">
                <span>${escapeHtml(title)}</span>
                <strong>${escapeHtml(value == null || value === '' ? '—' : String(value))}</strong>
                ${note ? `<small>${escapeHtml(note)}</small>` : ''}
            </article>`;
    }

    function monitoringRenderIndexing(audit) {
        const root = $('#monitoringIndexingGrid');
        if (!root) return;
        if (!audit) {
            root.innerHTML = '<div class="reports-empty"><strong>Аудит ещё не выполнен</strong><span>Запустите полный аудит сайта.</span></div>';
            return;
        }
        root.innerHTML = [
            monitoringAuditCard('Индексация', audit.indexing_allowed ? 'Разрешена' : 'Запрещена', audit.indexing_reason || '', audit.indexing_allowed ? 'positive' : 'negative'),
            monitoringAuditCard('Title', audit.title || 'Не найден', audit.title ? `${String(audit.title).length} символов` : '', audit.title ? '' : 'warning'),
            monitoringAuditCard('Description', audit.description || 'Не найден', audit.description ? `${String(audit.description).length} символов` : '', audit.description ? '' : 'warning'),
            monitoringAuditCard('H1', audit.h1 || 'Не найден', `Количество: ${audit.h1_count || 0}`, Number(audit.h1_count) === 1 ? 'positive' : 'warning'),
            monitoringAuditCard('Canonical', audit.canonical || 'Не найден', '', audit.canonical ? 'positive' : 'warning'),
            monitoringAuditCard('Meta robots', audit.meta_robots || 'Не задан', '', String(audit.meta_robots || '').toLowerCase().includes('noindex') ? 'negative' : ''),
            monitoringAuditCard('X-Robots-Tag', audit.x_robots_tag || 'Не задан', '', String(audit.x_robots_tag || '').toLowerCase().includes('noindex') ? 'negative' : ''),
            monitoringAuditCard('robots.txt', `HTTP ${audit.robots_status || '—'}`, audit.robots_summary || '', Number(audit.robots_status) >= 200 && Number(audit.robots_status) < 400 ? 'positive' : 'warning'),
            monitoringAuditCard('Sitemap', `HTTP ${audit.sitemap_status || '—'}`, audit.sitemap_url || '', Number(audit.sitemap_status) >= 200 && Number(audit.sitemap_status) < 400 ? 'positive' : 'warning'),
            monitoringAuditCard('Favicon', `HTTP ${audit.favicon_status || '—'}`, audit.favicon_url || '', Number(audit.favicon_status) >= 200 && Number(audit.favicon_status) < 400 ? 'positive' : 'warning'),
            monitoringAuditCard('Ответ для YandexBot', `HTTP ${audit.summary?.bot_http_code || '—'}`, 'Сравнивается с обычным запросом')
        ].join('');
    }

    function monitoringRenderInfrastructure(audit) {
        const root = $('#monitoringInfrastructureGrid');
        const dnsRoot = $('#monitoringDnsTable');
        if (!root || !dnsRoot) return;
        if (!audit) {
            root.innerHTML = '<div class="reports-empty"><strong>Аудит ещё не выполнен</strong><span>Запустите полный аудит сайта.</span></div>';
            dnsRoot.innerHTML = '';
            return;
        }
        root.innerHTML = [
            monitoringAuditCard('SSL', audit.ssl_valid == null ? 'Не применяется' : audit.ssl_valid ? 'Корректен' : 'Ошибка', audit.ssl_expires_at ? `До ${monitoringDate(audit.ssl_expires_at, false)}` : '', audit.ssl_valid === true ? 'positive' : audit.ssl_valid === false ? 'negative' : ''),
            monitoringAuditCard('До окончания SSL', audit.ssl_days_left == null ? '—' : `${audit.ssl_days_left} дней`, '', audit.ssl_days_left != null && audit.ssl_days_left <= 30 ? 'warning' : ''),
            monitoringAuditCard('Домен', audit.domain_name || '—', audit.domain_status === 'unavailable' ? 'Данные RDAP/WHOIS недоступны' : ''),
            monitoringAuditCard('Регистрация домена до', audit.domain_expires_at ? monitoringDate(audit.domain_expires_at, false) : '—', audit.domain_days_left == null ? '' : `Осталось ${audit.domain_days_left} дней`, audit.domain_days_left != null && audit.domain_days_left <= 60 ? 'warning' : ''),
            monitoringAuditCard('Последний аудит', monitoringDate(audit.created_at), audit.run_type || '')
        ].join('');

        const dns = audit.dns || {};
        const rows = Object.entries(dns).flatMap(([type, values]) => (values || []).map(value => [type, value]));
        dnsRoot.innerHTML = `
            <div class="reports-section-head"><div><strong>DNS-записи</strong><p class="muted">Изменения A, AAAA, CNAME, MX, NS и TXT сохраняются в истории.</p></div></div>
            ${rows.length
                ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Тип</th><th>Значение</th></tr></thead><tbody>${rows.map(([type, value]) => `<tr><td><strong>${escapeHtml(type)}</strong></td><td><code>${escapeHtml(String(value))}</code></td></tr>`).join('')}</tbody></table></div>`
                : '<div class="reports-empty"><strong>DNS-записи не получены</strong><span>Сервер не вернул доступные записи.</span></div>'}`;
    }

    function monitoringRenderMetrika(site, audit) {
        const root = $('#monitoringMetrikaGrid');
        if (!root) return;
        if (!audit) {
            root.innerHTML = '<div class="reports-empty"><strong>Аудит ещё не выполнен</strong><span>Запустите полный аудит сайта.</span></div>';
            return;
        }
        const ids = Array.isArray(audit.metrika_ids) ? audit.metrika_ids : [];
        const expected = Array.isArray(site.expected_metrika_ids_array) ? site.expected_metrika_ids_array : [];
        const missing = expected.filter(id => !ids.includes(id));
        root.innerHTML = [
            monitoringAuditCard('Обнаруженные счётчики', ids.length ? ids.join(', ') : 'Не обнаружены', ids.length ? 'Код найден в загруженном HTML' : 'Код может устанавливаться динамически через GTM', ids.length ? 'positive' : 'warning'),
            monitoringAuditCard('Ожидаемые счётчики', expected.length ? expected.join(', ') : 'Не заданы', missing.length ? `Не найдены: ${missing.join(', ')}` : expected.length ? 'Все ожидаемые ID найдены' : '', missing.length ? 'negative' : expected.length ? 'positive' : ''),
            monitoringAuditCard('Вебвизор', audit.webvisor_enabled == null ? 'Не удалось определить' : audit.webvisor_enabled ? 'Включён' : 'Не включён', 'Проверяется конфигурация в загруженном HTML', audit.webvisor_enabled === true ? 'positive' : audit.webvisor_enabled === false ? 'warning' : '')
        ].join('');
    }

    function monitoringRenderIncidents(rows) {
        const root = $('#monitoringIncidents');
        if (!root) return;
        if (!rows.length) {
            root.innerHTML = '<div class="reports-empty"><strong>Инцидентов нет</strong><span>Новые проблемы и восстановления появятся здесь.</span></div>';
            return;
        }
        root.innerHTML = rows.map(row => `
            <article class="monitoring-timeline-item ${escapeHtml(row.status)} ${escapeHtml(row.severity)}">
                <div class="monitoring-timeline-marker"></div>
                <div class="monitoring-timeline-body">
                    <div class="monitoring-timeline-head">
                        <strong>${escapeHtml(row.title)}</strong>
                        <span class="monitoring-incident-status ${escapeHtml(row.status)}">${row.status === 'open' ? 'Открыт' : 'Закрыт'}</span>
                    </div>
                    <p>${escapeHtml(row.details || '')}</p>
                    <div class="monitoring-timeline-meta">
                        <span>${escapeHtml(monitoringCategoryLabel(row.category))}</span>
                        <span>${escapeHtml(monitoringSeverityLabel(row.severity))}</span>
                        <span>Начало: ${escapeHtml(monitoringDate(row.started_at))}</span>
                        ${row.resolved_at ? `<span>Восстановление: ${escapeHtml(monitoringDate(row.resolved_at))}</span>` : ''}
                        ${row.duration_seconds != null ? `<span>Длительность: ${escapeHtml(monitoringDuration(row.duration_seconds))}</span>` : ''}
                    </div>
                </div>
            </article>`).join('');
    }

    function monitoringRenderEvents(rows) {
        const root = $('#monitoringEvents');
        if (!root) return;
        if (!rows.length) {
            root.innerHTML = '<div class="reports-empty"><strong>Изменений пока нет</strong><span>История начнёт заполняться после аудитов и смены состояний.</span></div>';
            return;
        }
        root.innerHTML = rows.map(row => `
            <article class="monitoring-timeline-item resolved ${escapeHtml(row.severity)}">
                <div class="monitoring-timeline-marker"></div>
                <div class="monitoring-timeline-body">
                    <div class="monitoring-timeline-head">
                        <strong>${escapeHtml(row.message)}</strong>
                        <span class="monitoring-event-category ${escapeHtml(row.category)}">${escapeHtml(monitoringCategoryLabel(row.category))}</span>
                    </div>
                    <div class="monitoring-timeline-meta">
                        <span>${escapeHtml(monitoringSeverityLabel(row.severity))}</span>
                        <span>${escapeHtml(monitoringDate(row.created_at))}</span>
                    </div>
                    ${(row.old_value || row.new_value) ? `
                        <details class="monitoring-change-details">
                            <summary>Показать изменение</summary>
                            <div><strong>Было</strong><pre>${escapeHtml(typeof row.old_value_decoded === 'string' ? row.old_value_decoded : JSON.stringify(row.old_value_decoded, null, 2) || '—')}</pre></div>
                            <div><strong>Стало</strong><pre>${escapeHtml(typeof row.new_value_decoded === 'string' ? row.new_value_decoded : JSON.stringify(row.new_value_decoded, null, 2) || '—')}</pre></div>
                        </details>` : ''}
                </div>
            </article>`).join('');
    }

    function monitoringApplyNotificationSettings(settings = {}) {
        monitoringState.notificationSettings = settings;
        const form = $('#monitoringNotificationSettings');
        if (!form) return;
        form.elements.telegram_bot_token.value = '';
        form.elements.telegram_bot_token.placeholder = settings.telegram_configured
            ? settings.telegram_token_masked || 'Токен настроен'
            : 'Введите токен Telegram-бота';
        form.elements.email_from.value = settings.email_from || '';
        form.elements.email_from_name.value = settings.email_from_name || 'Мониторинг сайтов';
        const status = $('#monitoringTelegramStatus');
        if (status) {
            status.textContent = settings.telegram_configured
                ? 'Telegram-бот настроен. Оставьте поле пустым, чтобы не менять токен.'
                : 'Telegram-бот пока не настроен.';
            status.className = settings.telegram_configured ? 'positive-text' : '';
        }
    }

    async function monitoringLoad(force = false) {
        if (!$('#monitoringSitesList')) return;
        if (monitoringLoaded && !force) return;
        try {
            const data = await api('/api.php?action=monitoring_overview');
            monitoringRenderSummary(data.summary || {});
            monitoringRenderWorker(data.worker || {});
            monitoringApplyNotificationSettings(data.notification_settings || {});
            monitoringRenderSites(data.sites || []);
            monitoringLoaded = true;

            if (!monitoringState.selectedId && data.sites?.length) {
                monitoringState.selectedId = Number(data.sites[0].id);
            }
            if (monitoringState.selectedId) {
                const exists = (data.sites || []).some(site => Number(site.id) === Number(monitoringState.selectedId));
                if (exists) {
                    await monitoringLoadSite(monitoringState.selectedId);
                } else {
                    monitoringState.selectedId = 0;
                    monitoringRenderEmptyDetail();
                }
            }
        } catch (error) {
            monitoringShowMessage('error', error.message);
        }
    }

    async function monitoringLoadSite(siteId) {
        monitoringState.selectedId = Number(siteId || 0);
        if (!monitoringState.selectedId) return;
        try {
            const data = await api(`/api.php?action=monitoring_site&id=${encodeURIComponent(monitoringState.selectedId)}`);
            monitoringRenderDetail(data);
        } catch (error) {
            monitoringShowMessage('error', error.message);
        }
    }

    function monitoringOpenEditor(site = null) {
        const panel = $('#monitoringSiteEditor');
        const form = $('#monitoringSiteForm');
        if (!panel || !form) return;
        form.reset();
        form.elements.id.value = String(site?.id || 0);
        form.elements.name.value = site?.name || '';
        form.elements.base_url.value = site?.base_url || '';
        form.elements.check_interval_minutes.value = site?.check_interval_minutes || 5;
        form.elements.slow_threshold_ms.value = site?.slow_threshold_ms || 3000;
        form.elements.expected_metrika_ids.value = site?.expected_metrika_ids || '';
        form.elements.technical_email.value = site?.technical_email || '';
        form.elements.marketing_email.value = site?.marketing_email || '';
        form.elements.technical_telegram_chat.value = site?.technical_telegram_chat || '';
        form.elements.marketing_telegram_chat.value = site?.marketing_telegram_chat || '';
        form.elements.is_active.checked = site ? Boolean(site.is_active) : true;
        form.elements.notify_email.checked = Boolean(site?.notify_email);
        form.elements.notify_telegram.checked = Boolean(site?.notify_telegram);
        form.elements.run_initial_audit.checked = !site;
        $('#monitoringSiteEditorTitle').textContent = site ? `Настройка: ${site.name}` : 'Новый сайт';
        $('#monitoringDeleteSite')?.classList.toggle('hidden', !site);
        panel.classList.remove('hidden');
        panel.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    function monitoringCloseEditor() {
        $('#monitoringSiteEditor')?.classList.add('hidden');
    }

    function monitoringSitePayload(form) {
        return {
            id: Number(form.elements.id.value || 0),
            name: form.elements.name.value.trim(),
            base_url: form.elements.base_url.value.trim(),
            check_interval_minutes: Number(form.elements.check_interval_minutes.value || 5),
            slow_threshold_ms: Number(form.elements.slow_threshold_ms.value || 3000),
            expected_metrika_ids: form.elements.expected_metrika_ids.value.trim(),
            technical_email: form.elements.technical_email.value.trim(),
            marketing_email: form.elements.marketing_email.value.trim(),
            technical_telegram_chat: form.elements.technical_telegram_chat.value.trim(),
            marketing_telegram_chat: form.elements.marketing_telegram_chat.value.trim(),
            is_active: form.elements.is_active.checked,
            notify_email: form.elements.notify_email.checked,
            notify_telegram: form.elements.notify_telegram.checked,
            run_initial_audit: form.elements.run_initial_audit.checked
        };
    }

    $('.nav-link[data-section="site-monitoring"]')?.addEventListener('click', () => monitoringLoad(true));
    $('#monitoringRefresh')?.addEventListener('click', async event => {
        event.currentTarget.disabled = true;
        monitoringShowMessage('', 'Обновляем данные мониторинга…');
        try {
            monitoringLoaded = false;
            await monitoringLoad(true);
            monitoringShowMessage('success', 'Данные мониторинга обновлены.');
        } finally {
            event.currentTarget.disabled = false;
        }
    });
    $('#monitoringAddSite')?.addEventListener('click', () => monitoringOpenEditor());
    $('#monitoringSiteEditorClose')?.addEventListener('click', monitoringCloseEditor);
    $('#monitoringEditSite')?.addEventListener('click', () => {
        const site = monitoringState.detail?.site;
        if (site) monitoringOpenEditor(site);
    });
    $('#monitoringSitesList')?.addEventListener('click', event => {
        const item = event.target.closest('[data-monitoring-site-id]');
        if (!item) return;
        monitoringLoadSite(Number(item.dataset.monitoringSiteId || 0));
    });
    $('.monitoring-tabs')?.addEventListener('click', event => {
        const button = event.target.closest('[data-monitoring-tab]');
        if (!button) return;
        const name = button.dataset.monitoringTab;
        $$('[data-monitoring-tab]').forEach(item => item.classList.toggle('is-active', item === button));
        $$('[data-monitoring-content]').forEach(item => item.classList.toggle('is-active', item.dataset.monitoringContent === name));
    });

    $('#monitoringSiteForm')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        monitoringShowMessage('', form.elements.run_initial_audit.checked
            ? 'Сохраняем сайт и выполняем первичный аудит. Это может занять до минуты…'
            : 'Сохраняем настройки сайта…');
        try {
            const result = await api('/api.php?action=monitoring_save_site', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf, site: monitoringSitePayload(form)})
            });
            const siteId = Number(result.result?.site?.id || 0);
            monitoringState.selectedId = siteId;
            monitoringCloseEditor();
            monitoringLoaded = false;
            await monitoringLoad(true);
            monitoringShowMessage('success', result.message || 'Сайт сохранён.');
        } catch (error) {
            monitoringShowMessage('error', error.message);
        } finally {
            button.disabled = false;
        }
    });

    $('#monitoringDeleteSite')?.addEventListener('click', async event => {
        const form = $('#monitoringSiteForm');
        const siteId = Number(form?.elements.id.value || 0);
        if (!siteId || !confirm('Удалить сайт и всю историю мониторинга? Это действие нельзя отменить.')) return;
        event.currentTarget.disabled = true;
        try {
            const result = await api('/api.php?action=monitoring_delete_site', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf, site_id: siteId})
            });
            monitoringState.selectedId = 0;
            monitoringCloseEditor();
            monitoringLoaded = false;
            await monitoringLoad(true);
            monitoringShowMessage('success', result.message || 'Сайт удалён.');
        } catch (error) {
            monitoringShowMessage('error', error.message);
        } finally {
            event.currentTarget.disabled = false;
        }
    });

    $('#monitoringCheckSite')?.addEventListener('click', async event => {
        const siteId = monitoringState.selectedId;
        if (!siteId) return;
        event.currentTarget.disabled = true;
        monitoringShowMessage('', 'Проверяем доступность. При ошибке будет выполнено до трёх попыток…');
        try {
            const result = await api('/api.php?action=monitoring_check_site', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf, site_id: siteId})
            });
            monitoringLoaded = false;
            await monitoringLoad(true);
            monitoringShowMessage(result.result?.is_up ? 'success' : 'error', result.message || 'Проверка завершена.');
        } catch (error) {
            monitoringShowMessage('error', error.message);
        } finally {
            event.currentTarget.disabled = false;
        }
    });

    $('#monitoringAuditSite')?.addEventListener('click', async event => {
        const siteId = monitoringState.selectedId;
        if (!siteId) return;
        event.currentTarget.disabled = true;
        monitoringShowMessage('', 'Проверяем индексацию, SEO-настройки, SSL, DNS, домен, Метрику и файлы сайта…');
        try {
            const result = await api('/api.php?action=monitoring_audit_site', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf, site_id: siteId})
            });
            monitoringLoaded = false;
            await monitoringLoad(true);
            monitoringShowMessage('success', result.message || 'Аудит завершён.');
        } catch (error) {
            monitoringShowMessage('error', error.message);
        } finally {
            event.currentTarget.disabled = false;
        }
    });

    $('#monitoringNotificationSettings')?.addEventListener('submit', async event => {
        event.preventDefault();
        const form = event.currentTarget;
        const button = form.querySelector('button[type="submit"]');
        button.disabled = true;
        try {
            const result = await api('/api.php?action=monitoring_save_notification_settings', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: csrf,
                    settings: {
                        telegram_bot_token: form.elements.telegram_bot_token.value.trim(),
                        email_from: form.elements.email_from.value.trim(),
                        email_from_name: form.elements.email_from_name.value.trim()
                    }
                })
            });
            monitoringApplyNotificationSettings(result.settings || {});
            monitoringShowMessage('success', result.message || 'Настройки уведомлений сохранены.');
        } catch (error) {
            monitoringShowMessage('error', error.message);
        } finally {
            button.disabled = false;
        }
    });
