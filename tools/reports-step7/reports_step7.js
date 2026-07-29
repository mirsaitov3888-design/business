    /* REPORTS_STEP7_JS */
    let reportsStep7ActiveReportId = 0;
    let reportsStep7StoredSegments = [];

    function reportsStep7SetActiveReport(reportId) {
        reportsStep7ActiveReportId = Number(reportId || 0);
        $$('#reportsList .report-list-item').forEach(item => {
            const active = Number(item.dataset.reportId || 0) === reportsStep7ActiveReportId;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-current', active ? 'true' : 'false');
        });
    }

    function reportsStep7SegmentHtml(channelKey, row = {}) {
        return `
            <article class="traffic-segment-row" data-traffic-segment data-channel-key="${escapeHtml(channelKey)}">
                <label class="traffic-segment-title"><span>Источник / точка назначения</span><input data-segment-field="title" value="${escapeHtml(row.title || '')}" placeholder="Сайт, Марквиз, квиз, лендинг"></label>
                <label><span>UTM source</span><input data-segment-field="utm_source" value="${escapeHtml(row.utm_source || '')}" placeholder="yandex"></label>
                <label><span>UTM medium</span><input data-segment-field="utm_medium" value="${escapeHtml(row.utm_medium || '')}" placeholder="cpc"></label>
                <label><span>UTM campaign</span><input data-segment-field="utm_campaign" value="${escapeHtml(row.utm_campaign || '')}"></label>
                <label class="traffic-segment-landing"><span>Домен / посадочная</span><input data-segment-field="landing" value="${escapeHtml(row.landing || '')}" placeholder="site.ru или marquiz.ru/..."></label>
                <label><span>Визиты</span><input type="number" min="0" step="1" data-segment-field="visits" value="${escapeHtml(reportNullableInputValue(row.visits))}"></label>
                <label><span>Пользователи</span><input type="number" min="0" step="1" data-segment-field="users" value="${escapeHtml(reportNullableInputValue(row.users))}"></label>
                <label><span>Заявки</span><input type="number" min="0" step="1" data-segment-field="leads" value="${escapeHtml(reportNullableInputValue(row.leads))}"></label>
                <label><span>Отказы, %</span><input type="number" min="0" max="100" step="0.01" data-segment-field="bounce_rate" value="${escapeHtml(reportNullableInputValue(row.bounce_rate))}"></label>
                <label><span>Визиты ранее</span><input type="number" min="0" step="1" data-segment-field="previous_visits" value="${escapeHtml(reportNullableInputValue(row.previous_visits))}"></label>
                <label><span>Заявки ранее</span><input type="number" min="0" step="1" data-segment-field="previous_leads" value="${escapeHtml(reportNullableInputValue(row.previous_leads))}"></label>
                <button type="button" class="button button-danger-small" data-remove-traffic-segment>Удалить</button>
            </article>`;
    }

    function reportsStep7RenderTrafficSegments(segments = []) {
        $$('.report-channel-card').forEach(card => {
            card.querySelector(':scope > .traffic-segments-section')?.remove();
            const channelKey = card.dataset.channelKey;
            const rows = (segments || []).filter(row => row.channel_key === channelKey);
            const section = document.createElement('section');
            section.className = 'traffic-segments-section';
            section.innerHTML = `
                <div class="channel-subsection-head">
                    <div><strong>Источники и точки назначения по UTM</strong><p class="muted">Внутренняя разбивка канала: сайт, Марквиз, квиз, лендинг или другая посадочная.</p></div>
                    <button type="button" class="button" data-add-traffic-segment>Добавить источник</button>
                </div>
                <div class="traffic-segments-list" data-traffic-segments>${rows.map(row => reportsStep7SegmentHtml(channelKey, row)).join('')}</div>`;
            const anchor = card.querySelector(':scope > .campaign-groups-section');
            anchor?.insertAdjacentElement('beforebegin', section);
        });
    }

    function reportsStep7ReadTrafficSegments() {
        return $$('[data-traffic-segment]').map((row, index) => {
            const value = field => row.querySelector(`[data-segment-field="${field}"]`)?.value ?? '';
            return {
                channel_key: row.dataset.channelKey,
                title: value('title').trim(),
                utm_source: value('utm_source').trim(),
                utm_medium: value('utm_medium').trim(),
                utm_campaign: value('utm_campaign').trim(),
                landing: value('landing').trim(),
                visits: reportNullableNumber(value('visits')),
                users: reportNullableNumber(value('users')),
                leads: reportNullableNumber(value('leads')),
                bounce_rate: reportNullableNumber(value('bounce_rate')),
                previous_visits: reportNullableNumber(value('previous_visits')),
                previous_leads: reportNullableNumber(value('previous_leads')),
                sort_order: index
            };
        }).filter(row => row.title || row.utm_source || row.utm_campaign || row.landing);
    }

    function reportsStep7AdvertisingSummary() {
        const channels = readReportChannels();
        const current = reportSummary(channels, false);
        const previous = reportSummary(channels, true);
        const form = $('#reportForm');
        const comparisonSelected = Boolean(form?.elements.comparison_date_from.value && form?.elements.comparison_date_to.value && previous.available);
        return {channels, current, previous, comparisonSelected};
    }

    function reportsStep7RenderAdvertisingKpis() {
        const root = $('#advertisingMainKpis');
        const form = $('#reportForm');
        if (!root || !form || form.elements.report_type.value === 'seo') return;
        const {current, previous, comparisonSelected} = reportsStep7AdvertisingSummary();
        const calculated = current.calculated || {};
        const previousCalculated = previous.calculated || {};
        const prior = value => comparisonSelected ? value : null;
        const cards = [
            reportKpiCard('Расходы', current.spend, prior(previous.spend), reportMoney, 'Рекламный бюджет', 'neutral'),
            reportKpiCard('Показы', current.impressions, prior(previous.impressions), value => number.format(value || 0), 'Все рекламные каналы'),
            reportKpiCard('Клики / переходы', current.clicks, prior(previous.clicks), value => number.format(value || 0), 'Из рекламных систем'),
            reportKpiCard('CTR', calculated.ctr, prior(previousCalculated.ctr), reportPercent, 'Клики / показы'),
            reportKpiCard('Заявки', current.leads, prior(previous.leads), value => number.format(value || 0), 'Основные обращения'),
            reportKpiCard('Квалифицированные', current.qualified_leads, prior(previous.qualified_leads), value => number.format(value || 0), 'Целевые лиды'),
            reportKpiCard('CPL', calculated.cpl, prior(previousCalculated.cpl), reportMoney, 'Расход / заявки', 'lower'),
            reportKpiCard('Договоры', current.contracts, prior(previous.contracts), value => number.format(value || 0), 'Заключённые сделки'),
            reportKpiCard('Стоимость договора', calculated.cost_per_contract, prior(previousCalculated.cost_per_contract), reportMoney, 'Расход / договоры', 'lower'),
            reportKpiCard('Оплаченная выручка', current.paid_revenue, prior(previous.paid_revenue), reportMoney, 'Фактические оплаты')
        ];
        root.innerHTML = cards.join('');
    }

    function reportsStep7ToggleTemplates(type) {
        const isSeo = type === 'seo';
        $('#advertisingReportIntro')?.classList.toggle('hidden', isSeo);
        $('#advertisingMainIndicators')?.classList.toggle('hidden', isSeo);
        $('#advertisingAutoFillMessage')?.classList.toggle('hidden', isSeo);
        if (!isSeo) reportsStep7RenderAdvertisingKpis();
    }

    function reportsStep7ShowAdvertisingMessage(type, html) {
        const root = $('#advertisingAutoFillMessage');
        if (!root) return;
        root.className = `advertising-autofill-message ${type}`;
        root.innerHTML = html;
    }

    function reportsStep7ApplyAdvertisingAutofill(data) {
        (data.channels || []).forEach(result => {
            const card = $(`.report-channel-card[data-channel-key="${CSS.escape(result.channel_key)}"]`);
            if (!card) return;
            const leadInput = card.querySelector('[data-field="leads"]');
            const previousLeadInput = card.querySelector('[data-comparison-field="leads"]');
            const sourceInput = card.querySelector('[data-field="source_type"]');
            if (leadInput && result.leads != null) leadInput.value = result.leads;
            if (previousLeadInput && result.previous_leads != null) previousLeadInput.value = result.previous_leads;
            if (sourceInput) sourceInput.value = 'api';
        });
        reportsStep7StoredSegments = data.traffic_segments || [];
        reportsStep7RenderTrafficSegments(reportsStep7StoredSegments);
        reportsStep7RenderAdvertisingKpis();
        const warnings = data.warnings || [];
        reportsStep7ShowAdvertisingMessage(warnings.length ? 'warning' : 'success', `
            <strong>Рекламные данные загружены</strong>
            <span>Источники: ${escapeHtml((data.sources_used || []).join(', '))}. Метрика заполнила заявки и UTM-разбивку. Ручные продажи и финансовые показатели не изменены.</span>
            ${warnings.length ? `<ul>${warnings.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : ''}`);
    }

    async function reportsStep7AdvertisingAutofill() {
        const form = $('#reportForm');
        const button = $('#advertisingAutoFill');
        if (!form || !button || form.elements.report_type.value === 'seo') return;
        const dateFrom = form.elements.date_from.value;
        const dateTo = form.elements.date_to.value;
        const comparisonDateFrom = form.elements.comparison_date_from.value;
        const comparisonDateTo = form.elements.comparison_date_to.value;
        if (!dateFrom || !dateTo) return reportsStep7ShowAdvertisingMessage('error', 'Укажите текущий период отчёта.');
        if (Boolean(comparisonDateFrom) !== Boolean(comparisonDateTo)) return reportsStep7ShowAdvertisingMessage('error', 'Для сравнения укажите обе даты периода.');
        const params = new URLSearchParams({action: 'advertising_autofill', date1: dateFrom, date2: dateTo});
        if (comparisonDateFrom && comparisonDateTo) {
            params.set('comparison_date1', comparisonDateFrom);
            params.set('comparison_date2', comparisonDateTo);
        }
        button.disabled = true;
        reportsStep7ShowAdvertisingMessage('loading', 'Загружаем рекламные визиты, заявки и UTM-разбивку из Метрики.');
        try {
            const result = await api(`/api.php?${params.toString()}`);
            reportsStep7ApplyAdvertisingAutofill(result.data || {});
        } catch (error) {
            reportsStep7ShowAdvertisingMessage('error', escapeHtml(error.message));
        } finally {
            button.disabled = false;
        }
    }

    function reportsStep7RenderTrafficSegmentsPreview(payload) {
        const root = $('#reportPreviewContent');
        if (!root || payload.report_type === 'seo') return;
        root.querySelector('[data-traffic-segments-preview]')?.remove();
        const rows = payload.traffic_segments || [];
        if (!rows.length) return;
        const names = Object.fromEntries((payload.channels || []).map(channel => [channel.channel_key, channel.channel_name]));
        const section = document.createElement('section');
        section.className = 'report-preview-section';
        section.dataset.trafficSegmentsPreview = '1';
        section.innerHTML = `
            <div class="reports-section-head"><div><strong>Источники и точки назначения по UTM</strong><p class="muted">Сайт, Марквиз, квизы и другие посадочные внутри рекламных каналов.</p></div></div>
            <div class="table-scroll report-detail-table"><table class="data-table"><thead><tr><th>Канал</th><th>Источник</th><th>UTM</th><th>Посадочная</th><th class="num">Визиты</th><th class="num">Заявки</th><th class="num">Конверсия</th><th class="num">Визиты ранее</th><th class="num">Заявки ранее</th></tr></thead><tbody>
            ${rows.map(row => {
                const conversion = reportSafeDivide(Number(row.leads || 0) * 100, row.visits);
                const utm = [row.utm_source, row.utm_medium, row.utm_campaign].filter(Boolean).join(' / ') || '—';
                return `<tr><td><strong>${escapeHtml(names[row.channel_key] || row.channel_key)}</strong></td><td>${escapeHtml(row.title || '—')}</td><td><code>${escapeHtml(utm)}</code></td><td>${escapeHtml(row.landing || '—')}</td><td class="num">${number.format(row.visits || 0)}</td><td class="num">${number.format(row.leads || 0)}</td><td class="num">${reportPercent(conversion)}</td><td class="num">${row.previous_visits == null ? '—' : number.format(row.previous_visits)}</td><td class="num">${row.previous_leads == null ? '—' : number.format(row.previous_leads)}</td></tr>`;
            }).join('')}</tbody></table></div>`;
        root.querySelector('.report-preview-section')?.insertAdjacentElement('afterend', section);
    }

    const reportsStep7OriginalRenderReportChannels = renderReportChannels;
    renderReportChannels = function(type, storedChannels = [], storedGroups = [], storedCreatives = [], storedSegments = reportsStep7StoredSegments) {
        reportsStep7OriginalRenderReportChannels(type, storedChannels, storedGroups, storedCreatives);
        if (type !== 'seo') {
            reportsStep7RenderTrafficSegments(storedSegments || []);
            reportsStep7RenderAdvertisingKpis();
        }
    };

    const reportsStep7OriginalCollectReportPayload = collectReportPayload;
    collectReportPayload = function() {
        const payload = reportsStep7OriginalCollectReportPayload();
        if (payload.report_type !== 'seo') payload.traffic_segments = reportsStep7ReadTrafficSegments();
        return payload;
    };

    const reportsStep7OriginalResetReportForm = resetReportForm;
    resetReportForm = function() {
        reportsStep7StoredSegments = [];
        reportsStep7OriginalResetReportForm();
        toggleSeoTemplate('advertising_summary');
        reportsStep7ToggleTemplates('advertising_summary');
        reportsStep7SetActiveReport(0);
        reportsStep7RenderTrafficSegments([]);
        reportsStep7RenderAdvertisingKpis();
    };

    const reportsStep7OriginalFillReportForm = fillReportForm;
    fillReportForm = function(report) {
        reportsStep7StoredSegments = report.traffic_segments || [];
        reportsStep7OriginalFillReportForm(report);
        const type = report.report_type || 'advertising_summary';
        toggleSeoTemplate(type);
        reportsStep7ToggleTemplates(type);
        if (type !== 'seo') {
            reportsStep7RenderTrafficSegments(reportsStep7StoredSegments);
            reportsStep7RenderAdvertisingKpis();
        }
        reportsStep7SetActiveReport(report.id || 0);
    };

    const reportsStep7OriginalRenderReportsList = renderReportsList;
    renderReportsList = function(reports) {
        reportsStep7OriginalRenderReportsList(reports);
        reportsStep7SetActiveReport(reportsStep7ActiveReportId);
    };

    const reportsStep7OriginalToggleSeoTemplate = toggleSeoTemplate;
    toggleSeoTemplate = function(type) {
        reportsStep7OriginalToggleSeoTemplate(type);
        reportsStep7ToggleTemplates(type);
    };

    const reportsStep7OriginalRenderReportPreview = renderReportPreview;
    renderReportPreview = function(payload) {
        reportsStep7OriginalRenderReportPreview(payload);
        if (payload.report_type !== 'seo') reportsStep7RenderTrafficSegmentsPreview(payload);
    };

    $('#reportForm')?.addEventListener('input', event => {
        if (event.target.closest('#reportChannels')) reportsStep7RenderAdvertisingKpis();
    });

    $('#reportForm [name="report_type"]')?.addEventListener('change', event => {
        const type = event.currentTarget.value;
        if (type !== 'seo') {
            reportsStep7StoredSegments = [];
            reportsStep7RenderTrafficSegments([]);
            reportsStep7RenderAdvertisingKpis();
        }
        reportsStep7ToggleTemplates(type);
    });

    $('#reportForm')?.addEventListener('click', event => {
        const add = event.target.closest('[data-add-traffic-segment]');
        if (add) {
            const card = add.closest('.report-channel-card');
            card?.querySelector('[data-traffic-segments]')?.insertAdjacentHTML('beforeend', reportsStep7SegmentHtml(card.dataset.channelKey));
            return;
        }
        const remove = event.target.closest('[data-remove-traffic-segment]');
        if (remove) remove.closest('[data-traffic-segment]')?.remove();
    });

    $('#advertisingAutoFill')?.addEventListener('click', reportsStep7AdvertisingAutofill);
    reportsStep7ToggleTemplates($('#reportForm')?.elements.report_type.value || 'advertising_summary');
