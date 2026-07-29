    /* REPORTS_STEP3_JS */
    const seoMetricKeys = [
        'organic_visits', 'organic_users', 'search_impressions', 'search_clicks',
        'avg_position', 'bounce_rate', 'leads', 'pages_in_search',
        'excluded_pages', 'sqi'
    ];
    const seoFinanceKeys = [
        'seo_cost', 'qualified_leads', 'contracts', 'contract_amount',
        'paid_revenue', 'gross_margin_percent'
    ];

    function seoNullable(value) {
        const normalized = String(value ?? '').trim().replaceAll(' ', '').replace(',', '.');
        if (normalized === '') return null;
        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? Math.max(0, parsed) : null;
    }

    function seoInput(value) {
        return value === null || value === undefined ? '' : String(value);
    }

    function seoSet(selector, data = {}) {
        $$(selector).forEach(input => {
            const key = input.dataset.seoMetric
                || input.dataset.seoComparison
                || input.dataset.seoFinance
                || input.dataset.seoFinanceComparison;
            input.value = seoInput(data[key]);
        });
    }

    function seoRead(selector, datasetKey) {
        const result = {};
        $$(selector).forEach(input => {
            result[input.dataset[datasetKey]] = seoNullable(input.value);
        });
        return result;
    }

    function seoTrendRow(row = {}) {
        return `<article class="seo-repeat-row seo-trend-row" data-seo-row="trend">
            <label class="seo-wide-field"><span>Период</span><input data-seo-field="label" value="${escapeHtml(row.label || '')}" placeholder="Например: 1–7 июля"></label>
            <label><span>Визиты сейчас</span><input type="number" min="0" data-seo-field="visits_current" value="${escapeHtml(seoInput(row.visits_current))}"></label>
            <label><span>Визиты ранее</span><input type="number" min="0" data-seo-field="visits_previous" value="${escapeHtml(seoInput(row.visits_previous))}"></label>
            <label><span>Пользователи сейчас</span><input type="number" min="0" data-seo-field="users_current" value="${escapeHtml(seoInput(row.users_current))}"></label>
            <label><span>Пользователи ранее</span><input type="number" min="0" data-seo-field="users_previous" value="${escapeHtml(seoInput(row.users_previous))}"></label>
            <label><span>Заявки сейчас</span><input type="number" min="0" data-seo-field="leads_current" value="${escapeHtml(seoInput(row.leads_current))}"></label>
            <label><span>Заявки ранее</span><input type="number" min="0" data-seo-field="leads_previous" value="${escapeHtml(seoInput(row.leads_previous))}"></label>
            <button type="button" class="button button-danger-small" data-remove-seo-row>Удалить</button>
        </article>`;
    }

    function seoSourceRow(row = {}) {
        return `<article class="seo-repeat-row seo-source-row" data-seo-row="source">
            <label class="seo-wide-field"><span>Поисковая система</span><input data-seo-field="name" value="${escapeHtml(row.name || '')}" placeholder="Яндекс"></label>
            <label><span>Визиты</span><input type="number" min="0" data-seo-field="visits" value="${escapeHtml(seoInput(row.visits))}"></label>
            <label><span>Пользователи</span><input type="number" min="0" data-seo-field="users" value="${escapeHtml(seoInput(row.users))}"></label>
            <label><span>Заявки</span><input type="number" min="0" data-seo-field="leads" value="${escapeHtml(seoInput(row.leads))}"></label>
            <label><span>Отказы, %</span><input type="number" min="0" max="100" step="0.01" data-seo-field="bounce_rate" value="${escapeHtml(seoInput(row.bounce_rate))}"></label>
            <label><span>Визиты ранее</span><input type="number" min="0" data-seo-field="previous_visits" value="${escapeHtml(seoInput(row.previous_visits))}"></label>
            <label><span>Пользователи ранее</span><input type="number" min="0" data-seo-field="previous_users" value="${escapeHtml(seoInput(row.previous_users))}"></label>
            <label><span>Заявки ранее</span><input type="number" min="0" data-seo-field="previous_leads" value="${escapeHtml(seoInput(row.previous_leads))}"></label>
            <button type="button" class="button button-danger-small" data-remove-seo-row>Удалить</button>
        </article>`;
    }

    function seoQueryRow(row = {}) {
        return `<article class="seo-repeat-row seo-query-row" data-seo-row="query">
            <label class="seo-query-field"><span>Ключевая фраза</span><input data-seo-field="query" value="${escapeHtml(row.query || '')}" placeholder="Введите запрос"></label>
            <label><span>Позиция сейчас</span><input type="number" min="0" step="0.01" data-seo-field="position_current" value="${escapeHtml(seoInput(row.position_current))}"></label>
            <label><span>Позиция ранее</span><input type="number" min="0" step="0.01" data-seo-field="position_previous" value="${escapeHtml(seoInput(row.position_previous))}"></label>
            <label><span>Показы</span><input type="number" min="0" data-seo-field="impressions" value="${escapeHtml(seoInput(row.impressions))}"></label>
            <label><span>Клики</span><input type="number" min="0" data-seo-field="clicks" value="${escapeHtml(seoInput(row.clicks))}"></label>
            <label class="seo-page-field"><span>Целевая страница</span><input data-seo-field="page" value="${escapeHtml(row.page || '')}" placeholder="/services/page/"></label>
            <button type="button" class="button button-danger-small" data-remove-seo-row>Удалить</button>
        </article>`;
    }

    function seoPageRow(row = {}) {
        return `<article class="seo-repeat-row seo-page-row" data-seo-row="page">
            <label class="seo-page-field"><span>Страница</span><input data-seo-field="page" value="${escapeHtml(row.page || '')}" placeholder="/services/page/"></label>
            <label><span>Визиты сейчас</span><input type="number" min="0" data-seo-field="visits_current" value="${escapeHtml(seoInput(row.visits_current))}"></label>
            <label><span>Визиты ранее</span><input type="number" min="0" data-seo-field="visits_previous" value="${escapeHtml(seoInput(row.visits_previous))}"></label>
            <label><span>Заявки</span><input type="number" min="0" data-seo-field="leads" value="${escapeHtml(seoInput(row.leads))}"></label>
            <label><span>Отказы, %</span><input type="number" min="0" max="100" step="0.01" data-seo-field="bounce_rate" value="${escapeHtml(seoInput(row.bounce_rate))}"></label>
            <label><span>Средняя позиция</span><input type="number" min="0" step="0.01" data-seo-field="avg_position" value="${escapeHtml(seoInput(row.avg_position))}"></label>
            <button type="button" class="button button-danger-small" data-remove-seo-row>Удалить</button>
        </article>`;
    }

    function seoIssueRow(row = {}) {
        const severity = row.severity || 'warning';
        return `<article class="seo-repeat-row seo-issue-row" data-seo-row="issue">
            <label><span>Уровень</span><select data-seo-field="severity">
                <option value="critical" ${severity === 'critical' ? 'selected' : ''}>Критическая</option>
                <option value="warning" ${severity === 'warning' ? 'selected' : ''}>Предупреждение</option>
                <option value="recommendation" ${severity === 'recommendation' ? 'selected' : ''}>Рекомендация</option>
                <option value="ok" ${severity === 'ok' ? 'selected' : ''}>В порядке</option>
            </select></label>
            <label class="seo-query-field"><span>Проблема или проверка</span><input data-seo-field="title" value="${escapeHtml(row.title || '')}" placeholder="Например: ошибки в Sitemap"></label>
            <label class="seo-page-field"><span>Комментарий</span><input data-seo-field="comment" value="${escapeHtml(row.comment || '')}" placeholder="Что обнаружено и что нужно сделать"></label>
            <button type="button" class="button button-danger-small" data-remove-seo-row>Удалить</button>
        </article>`;
    }

    const seoRowTemplates = {
        trend: seoTrendRow,
        source: seoSourceRow,
        query: seoQueryRow,
        page: seoPageRow,
        issue: seoIssueRow
    };
    const seoRowRoots = {
        trend: '#seoTrendRows',
        source: '#seoSourceRows',
        query: '#seoQueryRows',
        page: '#seoPageRows',
        issue: '#seoIssueRows'
    };

    function seoRenderRows(type, rows = []) {
        const root = $(seoRowRoots[type]);
        if (!root) return;
        root.innerHTML = rows.map(row => seoRowTemplates[type](row)).join('');
    }

    function seoReadRows(type) {
        return $$(`[data-seo-row="${type}"]`).map(row => {
            const result = {};
            row.querySelectorAll('[data-seo-field]').forEach(input => {
                const key = input.dataset.seoField;
                result[key] = ['label', 'name', 'query', 'page', 'title', 'comment', 'severity'].includes(key)
                    ? input.value.trim()
                    : seoNullable(input.value);
            });
            return result;
        }).filter(row => {
            if (type === 'trend') return row.label;
            if (type === 'source') return row.name;
            if (type === 'query') return row.query;
            if (type === 'page') return row.page;
            return row.title;
        });
    }

    function seoEmptyData() {
        return {
            metrics: Object.fromEntries(seoMetricKeys.map(key => [key, null])),
            comparison: Object.fromEntries(seoMetricKeys.map(key => [key, null])),
            trend: [], sources: [], queries: [], pages: [], issues: [],
            finance: Object.fromEntries(seoFinanceKeys.map(key => [key, null])),
            finance_comparison: Object.fromEntries(seoFinanceKeys.map(key => [key, null])),
            results_html: ''
        };
    }

    function resetSeoEditor() {
        const data = seoEmptyData();
        seoSet('[data-seo-metric]', data.metrics);
        seoSet('[data-seo-comparison]', data.comparison);
        seoSet('[data-seo-finance]', data.finance);
        seoSet('[data-seo-finance-comparison]', data.finance_comparison);
        seoRenderRows('trend', []);
        seoRenderRows('source', [{name: 'Яндекс'}, {name: 'Google'}]);
        seoRenderRows('query', []);
        seoRenderRows('page', []);
        seoRenderRows('issue', []);
        setRichEditorValue('seo_results', '');
    }

    function toggleSeoTemplate(type) {
        const isSeo = type === 'seo';
        $('#seoReportEditor')?.classList.toggle('hidden', !isSeo);
        $('#advertisingReportHeader')?.classList.toggle('hidden', isSeo);
        $('#reportChannels')?.classList.toggle('hidden', isSeo);
    }

    function collectSeoData() {
        return {
            metrics: seoRead('[data-seo-metric]', 'seoMetric'),
            comparison: seoRead('[data-seo-comparison]', 'seoComparison'),
            trend: seoReadRows('trend'),
            sources: seoReadRows('source'),
            queries: seoReadRows('query'),
            pages: seoReadRows('page'),
            issues: seoReadRows('issue'),
            finance: seoRead('[data-seo-finance]', 'seoFinance'),
            finance_comparison: seoRead('[data-seo-finance-comparison]', 'seoFinanceComparison'),
            results_html: richEditorValue('seo_results')
        };
    }

    function collectSeoReportPayload() {
        const form = $('#reportForm');
        if (!form) throw new Error('Форма отчёта не найдена.');
        return {
            csrf_token: csrf,
            id: Number(form.elements.report_id.value || 0),
            project_id: Number(form.elements.project_id.value || 0),
            title: form.elements.title.value.trim(),
            report_type: 'seo',
            audience: form.elements.audience.value,
            status: form.elements.status.value,
            date_from: form.elements.date_from.value,
            date_to: form.elements.date_to.value,
            comparison_date_from: form.elements.comparison_date_from.value,
            comparison_date_to: form.elements.comparison_date_to.value,
            work_done: richEditorValue('work_done'),
            next_plan: richEditorValue('next_plan'),
            recommendations: richEditorValue('recommendations'),
            notes: richEditorValue('notes'),
            seo_data: collectSeoData()
        };
    }

    function fillSeoReportForm(report) {
        const form = $('#reportForm');
        if (!form) return;
        form.elements.report_id.value = String(report.id || 0);
        form.elements.title.value = report.title || '';
        form.elements.report_type.value = 'seo';
        form.elements.audience.value = report.audience || 'owner';
        form.elements.status.value = report.status || 'draft';
        form.elements.date_from.value = report.date_from || '';
        form.elements.date_to.value = report.date_to || '';
        form.elements.comparison_date_from.value = report.comparison_date_from || '';
        form.elements.comparison_date_to.value = report.comparison_date_to || '';
        setRichEditorValue('work_done', report.work_done);
        setRichEditorValue('next_plan', report.next_plan);
        setRichEditorValue('recommendations', report.recommendations);
        setRichEditorValue('notes', report.notes);
        $('#reportEditorTitle').textContent = report.title || 'Редактирование SEO-отчёта';

        const data = report.seo_data || seoEmptyData();
        seoSet('[data-seo-metric]', data.metrics || {});
        seoSet('[data-seo-comparison]', data.comparison || {});
        seoSet('[data-seo-finance]', data.finance || {});
        seoSet('[data-seo-finance-comparison]', data.finance_comparison || {});
        seoRenderRows('trend', data.trend || []);
        seoRenderRows('source', data.sources || []);
        seoRenderRows('query', data.queries || []);
        seoRenderRows('page', data.pages || []);
        seoRenderRows('issue', data.issues || []);
        setRichEditorValue('seo_results', data.results_html || '');
        toggleSeoTemplate('seo');
    }

    function seoMetricCalculations(data) {
        const metrics = data.metrics || {};
        const comparison = data.comparison || {};
        return {
            ctr: reportSafeDivide(Number(metrics.search_clicks || 0) * 100, metrics.search_impressions),
            previous_ctr: reportSafeDivide(Number(comparison.search_clicks || 0) * 100, comparison.search_impressions),
            conversion: reportSafeDivide(Number(metrics.leads || 0) * 100, metrics.organic_visits),
            previous_conversion: reportSafeDivide(Number(comparison.leads || 0) * 100, comparison.organic_visits)
        };
    }

    function seoQuerySummary(queries) {
        const currentPositions = queries.map(row => row.position_current).filter(value => value !== null && value !== undefined && value > 0);
        return {
            top3: currentPositions.filter(value => value <= 3).length,
            top10: currentPositions.filter(value => value <= 10).length,
            top30: currentPositions.filter(value => value <= 30).length,
            growing: queries.filter(row => row.position_current && row.position_previous && row.position_current < row.position_previous).length,
            falling: queries.filter(row => row.position_current && row.position_previous && row.position_current > row.position_previous).length,
            new_count: queries.filter(row => row.position_current && !row.position_previous).length,
            lost: queries.filter(row => !row.position_current && row.position_previous).length
        };
    }

    function seoPositionChange(current, previous) {
        if (current === null || current === undefined) return previous ? '<span class="seo-position-change negative">Потерян</span>' : '—';
        if (previous === null || previous === undefined) return '<span class="seo-position-change positive">Новый</span>';
        const difference = Number(previous) - Number(current);
        if (difference > 0) return `<span class="seo-position-change positive">↑ ${number.format(difference)}</span>`;
        if (difference < 0) return `<span class="seo-position-change negative">↓ ${number.format(Math.abs(difference))}</span>`;
        return '<span class="seo-position-change neutral">0</span>';
    }

    function seoRichBlock(title, html) {
        const clean = reportCleanRichHtml(html || '');
        if (!clean) return '';
        return `<section class="report-preview-text"><h3>${escapeHtml(title)}</h3><div class="report-rich-content">${clean}</div></section>`;
    }

    function renderSeoReportPreview(payload) {
        const preview = $('#reportPreview');
        const root = $('#reportPreviewContent');
        if (!preview || !root) return;

        const data = payload.seo_data || seoEmptyData();
        const metrics = data.metrics || {};
        const previous = data.comparison || {};
        const calc = seoMetricCalculations(data);
        const querySummary = seoQuerySummary(data.queries || []);
        const finance = data.finance || {};
        const financePrevious = data.finance_comparison || {};
        const seoCpl = reportSafeDivide(finance.seo_cost, metrics.leads);
        const seoCplPrevious = reportSafeDivide(financePrevious.seo_cost, previous.leads);
        const contractCost = reportSafeDivide(finance.seo_cost, finance.contracts);
        const contractCostPrevious = reportSafeDivide(financePrevious.seo_cost, financePrevious.contracts);
        const roas = reportSafeDivide(finance.paid_revenue, finance.seo_cost);
        const roasPrevious = reportSafeDivide(financePrevious.paid_revenue, financePrevious.seo_cost);
        const romi = finance.seo_cost > 0 && finance.gross_margin_percent !== null
            ? ((Number(finance.paid_revenue || 0) * (Number(finance.gross_margin_percent) / 100) - Number(finance.seo_cost)) / Number(finance.seo_cost)) * 100
            : null;

        const comparisonLabel = payload.comparison_date_from && payload.comparison_date_to
            ? `<span>Сравнение: ${escapeHtml(reportDate(payload.comparison_date_from))}—${escapeHtml(reportDate(payload.comparison_date_to))}</span>`
            : '';

        const kpis = [
            reportKpiCard('Органические визиты', metrics.organic_visits || 0, previous.organic_visits, value => number.format(value || 0), 'Трафик из поисковых систем'),
            reportKpiCard('Пользователи из поиска', metrics.organic_users || 0, previous.organic_users, value => number.format(value || 0), 'Уникальные пользователи'),
            reportKpiCard('Показы в поиске', metrics.search_impressions || 0, previous.search_impressions, value => number.format(value || 0), 'Видимость сайта'),
            reportKpiCard('Клики из поиска', metrics.search_clicks || 0, previous.search_clicks, value => number.format(value || 0), 'Переходы из выдачи'),
            reportKpiCard('CTR поиска', calc.ctr || 0, calc.previous_ctr, reportPercent, 'Клики / показы'),
            reportKpiCard('Средняя позиция', metrics.avg_position || 0, previous.avg_position, value => number.format(value || 0), 'Меньше — лучше', 'lower'),
            reportKpiCard('Основные заявки', metrics.leads || 0, previous.leads, value => number.format(value || 0), 'Только основные цели'),
            reportKpiCard('Конверсия SEO', calc.conversion || 0, calc.previous_conversion, reportPercent, 'Заявки / визиты'),
            reportKpiCard('Отказы', metrics.bounce_rate || 0, previous.bounce_rate, reportPercent, 'Меньше — лучше', 'lower'),
            reportKpiCard('Страницы в поиске', metrics.pages_in_search || 0, previous.pages_in_search, value => number.format(value || 0), 'Проиндексировано'),
            reportKpiCard('Исключённые страницы', metrics.excluded_pages || 0, previous.excluded_pages, value => number.format(value || 0), 'Меньше — лучше', 'lower'),
            reportKpiCard('ИКС', metrics.sqi || 0, previous.sqi, value => number.format(value || 0), 'Индекс качества сайта')
        ].join('');

        const trendRows = (data.trend || []).map(row => `<tr>
            <td><strong>${escapeHtml(row.label)}</strong></td>
            <td class="num">${number.format(row.visits_current || 0)}</td>
            <td class="num">${number.format(row.visits_previous || 0)}</td>
            <td class="num">${number.format(row.users_current || 0)}</td>
            <td class="num">${number.format(row.leads_current || 0)}</td>
            <td class="num">${number.format(row.leads_previous || 0)}</td>
        </tr>`).join('');

        const sourceRows = (data.sources || []).map(row => {
            const conversion = reportSafeDivide(Number(row.leads || 0) * 100, row.visits);
            const share = reportSafeDivide(Number(row.visits || 0) * 100, metrics.organic_visits);
            return `<tr><td><strong>${escapeHtml(row.name)}</strong></td><td class="num">${number.format(row.visits || 0)}</td><td class="num">${number.format(row.users || 0)}</td><td class="num">${number.format(row.leads || 0)}</td><td class="num">${reportPercent(conversion)}</td><td class="num">${reportPercent(row.bounce_rate)}</td><td class="num">${reportPercent(share)}</td></tr>`;
        }).join('');

        const queryRows = (data.queries || []).map(row => {
            const ctr = reportSafeDivide(Number(row.clicks || 0) * 100, row.impressions);
            return `<tr><td><strong>${escapeHtml(row.query)}</strong></td><td class="num">${row.position_current ?? '—'}</td><td class="num">${row.position_previous ?? '—'}</td><td class="num">${seoPositionChange(row.position_current, row.position_previous)}</td><td class="num">${number.format(row.impressions || 0)}</td><td class="num">${number.format(row.clicks || 0)}</td><td class="num">${reportPercent(ctr)}</td><td><code>${escapeHtml(row.page || '—')}</code></td></tr>`;
        }).join('');

        const pageRows = (data.pages || []).map(row => {
            const conversion = reportSafeDivide(Number(row.leads || 0) * 100, row.visits_current);
            const change = row.visits_previous !== null && row.visits_previous !== undefined
                ? reportComparisonBadge(row.visits_current || 0, row.visits_previous, 'higher') : '';
            return `<tr><td><code>${escapeHtml(row.page)}</code></td><td class="num">${number.format(row.visits_current || 0)}</td><td class="num">${number.format(row.visits_previous || 0)}</td><td class="num">${change}</td><td class="num">${number.format(row.leads || 0)}</td><td class="num">${reportPercent(conversion)}</td><td class="num">${reportPercent(row.bounce_rate)}</td><td class="num">${row.avg_position ?? '—'}</td></tr>`;
        }).join('');

        const issueRows = (data.issues || []).map(row => `<tr><td><span class="seo-severity seo-severity-${escapeHtml(row.severity)}">${escapeHtml({critical:'Критическая',warning:'Предупреждение',recommendation:'Рекомендация',ok:'В порядке'}[row.severity] || row.severity)}</span></td><td><strong>${escapeHtml(row.title)}</strong></td><td>${escapeHtml(row.comment || '—')}</td></tr>`).join('');

        const financeAvailable = seoFinanceKeys.some(key => finance[key] !== null && finance[key] !== undefined);
        const financeHtml = financeAvailable ? `<section class="report-preview-section seo-finance-preview">
            <div class="reports-section-head"><div><strong>Финансовый результат SEO</strong><p class="muted">Расходы текущего месяца могут давать отложенный эффект.</p></div></div>
            <div class="report-kpi-grid seo-finance-kpis">
                ${reportKpiCard('Стоимость SEO-работ', finance.seo_cost || 0, financePrevious.seo_cost, reportMoney, 'Расход за период', 'neutral')}
                ${reportKpiCard('Стоимость SEO-лида', seoCpl || 0, seoCplPrevious, reportMoney, 'SEO-работы / основные заявки', 'lower')}
                ${reportKpiCard('Квалифицированные лиды', finance.qualified_leads || 0, financePrevious.qualified_leads, value => number.format(value || 0), 'Целевые обращения')}
                ${reportKpiCard('Договоры', finance.contracts || 0, financePrevious.contracts, value => number.format(value || 0), 'Заключённые сделки')}
                ${reportKpiCard('Стоимость договора', contractCost || 0, contractCostPrevious, reportMoney, 'SEO-работы / договоры', 'lower')}
                ${reportKpiCard('Оплаченная выручка', finance.paid_revenue || 0, financePrevious.paid_revenue, reportMoney, 'Фактические оплаты')}
                ${reportKpiCard('ROAS SEO', roas || 0, roasPrevious, reportMultiplier, 'Выручка / стоимость работ')}
                ${reportKpiCard('ROMI SEO', romi || 0, null, reportPercent, 'По валовой прибыли')}
            </div>
        </section>` : '';

        root.innerHTML = `<header class="report-document-head">
            <p class="eyebrow">Мир сайтов · SEO</p>
            <h1>${escapeHtml(payload.title || 'SEO-отчёт')}</h1>
            <div class="report-document-meta"><span>Период: ${escapeHtml(reportDate(payload.date_from))}—${escapeHtml(reportDate(payload.date_to))}</span><span>Получатель: ${escapeHtml(reportAudienceLabels[payload.audience] || payload.audience)}</span>${comparisonLabel}</div>
        </header>
        <div class="report-kpi-grid seo-kpi-grid">${kpis}</div>
        ${(data.trend || []).length ? `<section class="report-preview-section"><div class="reports-section-head"><div><strong>Динамика органического трафика</strong><p class="muted">Текущий и прошлый период.</p></div></div><div class="table-scroll report-detail-table"><table class="data-table"><thead><tr><th>Период</th><th class="num">Визиты</th><th class="num">Ранее</th><th class="num">Пользователи</th><th class="num">Заявки</th><th class="num">Заявки ранее</th></tr></thead><tbody>${trendRows}</tbody></table></div></section>` : ''}
        ${(data.sources || []).length ? `<section class="report-preview-section"><div class="reports-section-head"><div><strong>Источники органического трафика</strong><p class="muted">Распределение по поисковым системам.</p></div></div><div class="table-scroll report-detail-table"><table class="data-table"><thead><tr><th>Поисковик</th><th class="num">Визиты</th><th class="num">Пользователи</th><th class="num">Заявки</th><th class="num">Конверсия</th><th class="num">Отказы</th><th class="num">Доля</th></tr></thead><tbody>${sourceRows}</tbody></table></div></section>` : ''}
        <section class="report-preview-section"><div class="reports-section-head"><div><strong>Позиции по ключевым фразам</strong><p class="muted">ТОП-3: ${querySummary.top3} · ТОП-10: ${querySummary.top10} · ТОП-30: ${querySummary.top30} · выросли: ${querySummary.growing} · упали: ${querySummary.falling}</p></div></div>${(data.queries || []).length ? `<div class="table-scroll report-detail-table seo-query-table"><table class="data-table"><thead><tr><th>Запрос</th><th class="num">Сейчас</th><th class="num">Ранее</th><th class="num">Изменение</th><th class="num">Показы</th><th class="num">Клики</th><th class="num">CTR</th><th>Страница</th></tr></thead><tbody>${queryRows}</tbody></table></div>` : '<p class="muted">Ключевые фразы пока не добавлены.</p>'}</section>
        ${(data.pages || []).length ? `<section class="report-preview-section"><div class="reports-section-head"><div><strong>Страницы органического поиска</strong><p class="muted">Трафик, заявки и качество посадочных страниц.</p></div></div><div class="table-scroll report-detail-table"><table class="data-table"><thead><tr><th>Страница</th><th class="num">Визиты</th><th class="num">Ранее</th><th class="num">Динамика</th><th class="num">Заявки</th><th class="num">Конверсия</th><th class="num">Отказы</th><th class="num">Позиция</th></tr></thead><tbody>${pageRows}</tbody></table></div></section>` : ''}
        <section class="report-preview-section"><div class="reports-section-head"><div><strong>Индексация и техническое состояние</strong><p class="muted">ИКС: ${number.format(metrics.sqi || 0)} · в поиске: ${number.format(metrics.pages_in_search || 0)} · исключено: ${number.format(metrics.excluded_pages || 0)}</p></div></div>${(data.issues || []).length ? `<div class="table-scroll report-detail-table"><table class="data-table"><thead><tr><th>Уровень</th><th>Проверка</th><th>Комментарий</th></tr></thead><tbody>${issueRows}</tbody></table></div>` : '<div class="wm-ok">Активные технические проблемы не добавлены.</div>'}</section>
        ${financeHtml}
        <div class="report-preview-text-grid seo-preview-rich-grid">
            ${seoRichBlock('Полученные результаты', data.results_html)}
            ${seoRichBlock('Что сделано за период', payload.work_done)}
            ${seoRichBlock('Рекомендации', payload.recommendations)}
            ${seoRichBlock('План следующего периода', payload.next_plan)}
        </div>
        ${seoRichBlock('Комментарии и ограничения данных', payload.notes)}
        <section class="report-formulas-note"><strong>Логика отчёта:</strong> органический трафик → поисковые системы → позиции → страницы → индексация и техническое состояние → заявки → финансовый результат.</section>`;

        preview.classList.remove('hidden');
        preview.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    $('[name="report_type"]')?.addEventListener('change', event => {
        toggleSeoTemplate(event.currentTarget.value);
        if (event.currentTarget.value === 'seo' && !$('#seoSourceRows')?.children.length) {
            resetSeoEditor();
        }
    });

    $('#newReport')?.addEventListener('click', () => {
        resetSeoEditor();
        toggleSeoTemplate('advertising_summary');
    });

    $('#seoReportEditor')?.addEventListener('click', event => {
        const addButton = event.target.closest('[data-add-seo-row]');
        if (addButton) {
            const type = addButton.dataset.addSeoRow;
            $(seoRowRoots[type])?.insertAdjacentHTML('beforeend', seoRowTemplates[type]({}));
            return;
        }
        const removeButton = event.target.closest('[data-remove-seo-row]');
        if (removeButton) {
            removeButton.closest('[data-seo-row]')?.remove();
        }
    });

    $('#previewReport')?.addEventListener('click', event => {
        if ($('#reportForm')?.elements.report_type.value !== 'seo') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        try {
            const payload = collectSeoReportPayload();
            if (!payload.date_from || !payload.date_to) throw new Error('Укажите период отчёта.');
            renderSeoReportPreview(payload);
        } catch (error) {
            const message = $('#reportFormMessage');
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    }, true);

    $('#reportForm')?.addEventListener('submit', async event => {
        if (event.currentTarget.elements.report_type.value !== 'seo') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const message = $('#reportFormMessage');
        const submit = event.currentTarget.querySelector('button[type="submit"]');
        submit.disabled = true;
        message.className = '';
        message.textContent = 'Сохранение SEO-отчёта...';
        try {
            const payload = collectSeoReportPayload();
            if (!payload.date_from || !payload.date_to) throw new Error('Укажите период отчёта.');
            const result = await api('/api.php?action=save_seo_report', {
                method: 'POST',
                body: JSON.stringify(payload)
            });
            fillSeoReportForm(result.report);
            renderSeoReportPreview(result.report);
            message.className = 'alert alert-success';
            message.textContent = result.message;
            reportsLoaded = false;
            await loadReports(true);
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        } finally {
            submit.disabled = false;
        }
    }, true);

    resetSeoEditor();
    toggleSeoTemplate($('#reportForm')?.elements.report_type.value || 'advertising_summary');
