    /* AVAILABILITY_MODULE_JS */
    let availabilityLoaded = false;

    function availabilityStatusLabel(status) {
        return {
            up: 'Работает',
            degraded: 'Есть предупреждения',
            down: 'Недоступен',
            unknown: 'Нет данных'
        }[status] || 'Нет данных';
    }

    function availabilityDuration(seconds) {
        const value = Math.max(0, Number(seconds || 0));

        if (value < 60) return `${Math.round(value)} сек.`;
        if (value < 3600) return `${Math.floor(value / 60)} мин. ${Math.round(value % 60)} сек.`;
        if (value < 86400) return `${Math.floor(value / 3600)} ч. ${Math.floor((value % 3600) / 60)} мин.`;
        return `${Math.floor(value / 86400)} д. ${Math.floor((value % 86400) / 3600)} ч.`;
    }

    function availabilityDateTime(value) {
        const raw = String(value || '');
        const match = raw.match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        return match
            ? `${match[3]}.${match[2]}.${match[1]} ${match[4]}:${match[5]}`
            : raw || '—';
    }

    function renderAvailabilityCards(data) {
        const root = $('#availabilityCards');
        if (!root) return;

        const monitor = data.monitor || {};
        const summary = data.summary || {};
        const sslDays = monitor.ssl_days_remaining;
        const sslValue = sslDays == null
            ? '—'
            : sslDays < 0
                ? 'Просрочен'
                : `${number.format(sslDays)} дн.`;

        const cards = [
            [
                'Текущий статус',
                availabilityStatusLabel(monitor.current_status),
                monitor.last_checked_at
                    ? `Проверено ${availabilityDateTime(monitor.last_checked_at)}`
                    : 'Проверок пока нет',
                `availability-status-${monitor.current_status || 'unknown'}`
            ],
            [
                'Uptime',
                summary.uptime_percent == null
                    ? '—'
                    : `${number.format(summary.uptime_percent)}%`,
                `${number.format(summary.checks || 0)} проверок`,
                ''
            ],
            [
                'Средний ответ',
                summary.average_response_ms == null
                    ? '—'
                    : `${number.format(summary.average_response_ms)} мс`,
                summary.p95_response_ms == null
                    ? 'Нет P95'
                    : `P95: ${number.format(summary.p95_response_ms)} мс`,
                ''
            ],
            [
                'Инциденты',
                number.format(summary.incident_count || 0),
                `Простой: ${availabilityDuration(summary.total_downtime_seconds || 0)}`,
                ''
            ],
            [
                'SSL-сертификат',
                sslValue,
                monitor.ssl_expires_at
                    ? `До ${availabilityDateTime(monitor.ssl_expires_at).split(' ')[0]}`
                    : 'Для HTTP не применяется',
                sslDays != null && sslDays < 14
                    ? 'availability-status-degraded'
                    : ''
            ],
            [
                'HTTP-код',
                monitor.last_http_code == null
                    ? '—'
                    : String(monitor.last_http_code),
                monitor.last_response_ms == null
                    ? 'Нет последней проверки'
                    : `${number.format(monitor.last_response_ms)} мс`,
                ''
            ]
        ];

        root.innerHTML = cards.map(([title, value, note, className]) => `
            <article class="metric-card availability-card ${className}">
                <span>${escapeHtml(title)}</span>
                <strong>${escapeHtml(value)}</strong>
                <small>${escapeHtml(note)}</small>
            </article>
        `).join('');
    }

    function renderAvailabilityChart(data) {
        const root = $('#availabilityChart');
        if (!root) return;

        const history = data.history || [];

        if (!history.length) {
            root.innerHTML = '<p class="muted">История появится после первой проверки.</p>';
            return;
        }

        const rows = history.length > 120
            ? history.filter((row, index) => index % Math.ceil(history.length / 120) === 0 || index === history.length - 1)
            : history;
        const values = rows.map(row => Number(row.response_ms || 0));
        const maximum = Math.max(1, ...values);
        const width = 900;
        const height = 300;
        const left = 58;
        const right = 20;
        const top = 24;
        const bottom = 56;
        const plotWidth = width - left - right;
        const plotHeight = height - top - bottom;
        const x = index => rows.length <= 1
            ? left + plotWidth / 2
            : left + (plotWidth * index) / (rows.length - 1);
        const y = value => top + plotHeight - (Number(value || 0) / maximum) * plotHeight;
        const path = rows.map((row, index) => `${index === 0 ? 'M' : 'L'} ${x(index)} ${y(row.response_ms)}`).join(' ');
        const labelStep = Math.max(1, Math.ceil(rows.length / 7));

        const grid = Array.from({length: 5}, (_, index) => {
            const ratio = index / 4;
            const gridY = top + plotHeight - plotHeight * ratio;
            return `<g class="availability-grid-line"><line x1="${left}" y1="${gridY}" x2="${width - right}" y2="${gridY}"></line><text x="${left - 8}" y="${gridY + 4}" text-anchor="end">${escapeHtml(number.format(maximum * ratio))}</text></g>`;
        }).join('');

        const points = rows.map((row, index) => {
            const label = `${availabilityDateTime(row.checked_at)} · ${row.response_ms ?? '—'} мс · ${availabilityStatusLabel(row.status)}`;
            const dateLabel = index % labelStep === 0 || index === rows.length - 1
                ? `<text class="availability-x-label" x="${x(index)}" y="${height - 25}" text-anchor="middle">${escapeHtml(availabilityDateTime(row.checked_at).slice(0, 10))}</text>`
                : '';
            return `<circle class="availability-point availability-point-${escapeHtml(row.status)}" cx="${x(index)}" cy="${y(row.response_ms)}" r="4"><title>${escapeHtml(label)}</title></circle>${dateLabel}`;
        }).join('');

        root.innerHTML = `
            <div class="availability-chart-head">
                <span>Время ответа, мс</span>
                <div class="availability-legend">
                    <span><i class="up"></i>Работает</span>
                    <span><i class="degraded"></i>Предупреждение</span>
                    <span><i class="down"></i>Недоступен</span>
                </div>
            </div>
            <svg viewBox="0 0 ${width} ${height}" class="availability-svg" role="img" aria-label="История скорости ответа сайта">
                ${grid}
                <path class="availability-response-line" d="${path}"></path>
                ${points}
            </svg>
        `;
    }

    function renderAvailabilityCurrent(data) {
        const root = $('#availabilityCurrent');
        if (!root) return;

        const monitor = data.monitor || {};
        const status = monitor.current_status || 'unknown';

        root.innerHTML = `
            <div class="availability-current-status availability-current-${escapeHtml(status)}">
                <span class="availability-status-dot"></span>
                <div>
                    <strong>${escapeHtml(availabilityStatusLabel(status))}</strong>
                    <small>${escapeHtml(monitor.url || '—')}</small>
                </div>
            </div>
            <dl class="availability-details">
                <div><dt>Последняя проверка</dt><dd>${escapeHtml(availabilityDateTime(monitor.last_checked_at))}</dd></div>
                <div><dt>HTTP-код</dt><dd>${monitor.last_http_code == null ? '—' : escapeHtml(monitor.last_http_code)}</dd></div>
                <div><dt>Время ответа</dt><dd>${monitor.last_response_ms == null ? '—' : `${escapeHtml(number.format(monitor.last_response_ms))} мс`}</dd></div>
                <div><dt>Интервал</dt><dd>${escapeHtml(number.format(monitor.interval_minutes || 5))} мин.</dd></div>
                <div><dt>Порог медленного ответа</dt><dd>${escapeHtml(number.format(monitor.slow_threshold_ms || 3000))} мс</dd></div>
            </dl>
            ${monitor.last_error ? `<div class="availability-last-error">${escapeHtml(monitor.last_error)}</div>` : ''}
        `;
    }

    function renderAvailabilityIncidents(data) {
        const root = $('#availabilityIncidents');
        if (!root) return;

        const incidents = data.incidents || [];

        root.innerHTML = table([
            {
                label: 'Статус',
                render: row => `<span class="availability-incident-status availability-incident-${escapeHtml(row.status)}">${row.status === 'open' ? 'Открыт' : 'Завершён'}</span>`
            },
            {
                label: 'Начало',
                render: row => escapeHtml(availabilityDateTime(row.started_at))
            },
            {
                label: 'Окончание',
                render: row => escapeHtml(availabilityDateTime(row.ended_at))
            },
            {
                label: 'Длительность',
                num: true,
                render: row => escapeHtml(availabilityDuration(row.effective_duration_seconds))
            },
            {
                label: 'Неудачных проверок',
                num: true,
                render: row => number.format(row.failed_checks || 0)
            },
            {
                label: 'Причина',
                render: row => escapeHtml(row.last_error || row.first_error || '—')
            }
        ], incidents);
    }

    async function loadAvailabilityDashboard(force = false) {
        if (!hasProject) return;
        if (availabilityLoaded && !force) return;

        const message = $('#availabilityMessage');

        try {
            const dates = currentDates();
            const result = await api(
                `/api.php?action=availability_dashboard`
                + `&date1=${encodeURIComponent(dates.date1)}`
                + `&date2=${encodeURIComponent(dates.date2)}`
            );

            renderAvailabilityCards(result.data);
            renderAvailabilityChart(result.data);
            renderAvailabilityCurrent(result.data);
            renderAvailabilityIncidents(result.data);

            if (message) {
                message.className = 'quality-banner ok';
                message.textContent = result.data.summary.checks > 0
                    ? `Мониторинг: ${result.data.monitor.url} · проверок за период: ${result.data.summary.checks}`
                    : 'Монитор создан. Запустите первую проверку или добавьте команду в Cron.';
            }

            availabilityLoaded = true;
        } catch (error) {
            if (message) {
                message.className = 'alert alert-error';
                message.textContent = error.message;
            }
        }
    }

    async function runAvailabilityCheck() {
        const button = $('#runAvailabilityCheck');
        const message = $('#availabilityMessage');
        button.disabled = true;
        button.classList.add('loading');

        try {
            const result = await api('/api.php?action=run_availability_check', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf})
            });

            if (message) {
                message.className = result.check.status === 'down'
                    ? 'alert alert-error'
                    : result.check.status === 'degraded'
                        ? 'quality-banner'
                        : 'quality-banner ok';
                message.textContent = `Проверка завершена: ${availabilityStatusLabel(result.check.status)} · HTTP ${result.check.http_code ?? '—'} · ${result.check.response_ms ?? '—'} мс`;
            }

            availabilityLoaded = false;
            await loadAvailabilityDashboard(true);
        } catch (error) {
            if (message) {
                message.className = 'alert alert-error';
                message.textContent = error.message;
            }
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    $('.nav-link[data-section="availability"]')?.addEventListener(
        'click',
        () => loadAvailabilityDashboard()
    );

    $('#runAvailabilityCheck')?.addEventListener(
        'click',
        runAvailabilityCheck
    );
