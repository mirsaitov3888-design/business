    /* REPORTS_STEP5_JS */

    function reportsStep5SetCollapsed(card, collapsed) {
        card.classList.toggle('is-collapsed', collapsed);
        const button = card.querySelector(':scope > .seo-editor-head .seo-collapse-toggle');
        if (button) {
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            button.innerHTML = collapsed
                ? '<span aria-hidden="true">⌄</span> Развернуть'
                : '<span aria-hidden="true">⌃</span> Свернуть';
        }
    }

    function reportsStep5SetupSeoCollapsibles() {
        const editor = $('#seoReportEditor');
        if (!editor) return;

        const cards = $$('.seo-editor-card', editor);

        cards.forEach((card, index) => {
            if (card.dataset.step5Collapsible === '1') return;

            const head = card.querySelector(':scope > .seo-editor-head');
            if (!head) return;

            card.dataset.step5Collapsible = '1';
            card.classList.add('seo-collapsible-card');

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'seo-collapse-toggle';
            button.addEventListener('click', () => {
                reportsStep5SetCollapsed(
                    card,
                    !card.classList.contains('is-collapsed')
                );
            });
            head.append(button);

            reportsStep5SetCollapsed(card, index > 0);
        });

        const intro = editor.querySelector('.seo-section-intro');
        if (intro && !$('#seoCollapseControls')) {
            const controls = document.createElement('div');
            controls.id = 'seoCollapseControls';
            controls.className = 'seo-collapse-controls';
            controls.innerHTML = `
                <button type="button" class="button" data-seo-expand-all>
                    Развернуть все
                </button>
                <button type="button" class="button" data-seo-collapse-all>
                    Свернуть все
                </button>
            `;
            intro.append(controls);

            controls.querySelector('[data-seo-expand-all]')?.addEventListener(
                'click',
                () => cards.forEach(card => reportsStep5SetCollapsed(card, false))
            );
            controls.querySelector('[data-seo-collapse-all]')?.addEventListener(
                'click',
                () => cards.forEach(card => reportsStep5SetCollapsed(card, true))
            );
        }
    }

    function reportsStep5SetupRichFieldCollapsibles() {
        $$('#reportForm .rich-field').forEach(field => {
            if (field.dataset.step5Collapsible === '1') return;

            const label = field.querySelector(':scope > .rich-field-label');
            const shell = field.querySelector(':scope > .rich-editor-shell');
            if (!label || !shell) return;

            field.dataset.step5Collapsible = '1';
            field.classList.add('reports-rich-collapsible', 'is-collapsed');

            const header = document.createElement('div');
            header.className = 'reports-rich-collapse-head';
            field.insertBefore(header, label);
            header.append(label);

            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'reports-rich-collapse-toggle';
            button.setAttribute('aria-expanded', 'false');
            button.innerHTML = '<span aria-hidden="true">⌄</span> Развернуть';
            button.addEventListener('click', () => {
                const collapsed = !field.classList.contains('is-collapsed');
                field.classList.toggle('is-collapsed', collapsed);
                button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
                button.innerHTML = collapsed
                    ? '<span aria-hidden="true">⌄</span> Развернуть'
                    : '<span aria-hidden="true">⌃</span> Свернуть';
            });
            header.append(button);
        });
    }

    function reportsStep5CompactNumber(value) {
        const numberValue = Number(value || 0);
        return new Intl.NumberFormat('ru-RU', {
            notation: Math.abs(numberValue) >= 1000 ? 'compact' : 'standard',
            maximumFractionDigits: 1
        }).format(numberValue);
    }

    function reportsStep5ChartLabel(value, limit = 16) {
        const text = String(value || '');
        return text.length > limit
            ? `${text.slice(0, limit - 1)}…`
            : text;
    }

    function reportsStep5GroupedBarChart(title, subtitle, labels, current, previous) {
        const width = 820;
        const height = 300;
        const left = 58;
        const right = 18;
        const top = 34;
        const bottom = 72;
        const plotWidth = width - left - right;
        const plotHeight = height - top - bottom;
        const hasPrevious = previous.some(value => value !== null && value !== undefined);
        const values = [
            ...current.map(value => Number(value || 0)),
            ...previous.map(value => Number(value || 0))
        ];
        const maximum = Math.max(1, ...values);
        const groupWidth = plotWidth / Math.max(1, labels.length);
        const barWidth = Math.min(34, groupWidth * (hasPrevious ? .3 : .48));
        const y = value => top + plotHeight - (Number(value || 0) / maximum) * plotHeight;
        const h = value => (Number(value || 0) / maximum) * plotHeight;

        const grid = Array.from({length: 5}, (_, index) => {
            const ratio = index / 4;
            const lineY = top + plotHeight - plotHeight * ratio;
            const label = reportsStep5CompactNumber(maximum * ratio);
            return `<g class="report-chart-grid-line"><line x1="${left}" y1="${lineY}" x2="${width - right}" y2="${lineY}"></line><text x="${left - 9}" y="${lineY + 4}" text-anchor="end">${escapeHtml(label)}</text></g>`;
        }).join('');

        const bars = labels.map((label, index) => {
            const center = left + groupWidth * index + groupWidth / 2;
            const currentValue = Number(current[index] || 0);
            const previousValue = previous[index];
            const currentX = hasPrevious ? center - barWidth - 3 : center - barWidth / 2;
            const previousX = center + 3;
            const currentBar = `<rect class="report-chart-current" x="${currentX}" y="${y(currentValue)}" width="${barWidth}" height="${h(currentValue)}" rx="5"><title>${escapeHtml(label)}: ${escapeHtml(String(currentValue))}</title></rect>`;
            const previousBar = hasPrevious && previousValue !== null && previousValue !== undefined
                ? `<rect class="report-chart-previous" x="${previousX}" y="${y(previousValue)}" width="${barWidth}" height="${h(previousValue)}" rx="5"><title>${escapeHtml(label)} — прошлый период: ${escapeHtml(String(previousValue))}</title></rect>`
                : '';
            return `<g>${currentBar}${previousBar}<text class="report-chart-x-label" x="${center}" y="${height - 42}" text-anchor="middle">${escapeHtml(reportsStep5ChartLabel(label))}</text></g>`;
        }).join('');

        return `<article class="report-chart-card">
            <div class="report-chart-head"><div><strong>${escapeHtml(title)}</strong><span>${escapeHtml(subtitle)}</span></div><div class="report-chart-legend"><span><i class="current"></i>Текущий</span>${hasPrevious ? '<span><i class="previous"></i>Прошлый</span>' : ''}</div></div>
            <svg class="report-chart-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(title)}">${grid}${bars}</svg>
        </article>`;
    }

    function reportsStep5LineChart(title, subtitle, labels, current, previous) {
        const width = 820;
        const height = 300;
        const left = 58;
        const right = 20;
        const top = 34;
        const bottom = 64;
        const plotWidth = width - left - right;
        const plotHeight = height - top - bottom;
        const currentValues = current.map(value => Number(value || 0));
        const previousValues = previous.map(value => value === null || value === undefined ? null : Number(value));
        const hasPrevious = previousValues.some(value => value !== null);
        const maximum = Math.max(1, ...currentValues, ...previousValues.filter(value => value !== null));
        const x = index => labels.length <= 1
            ? left + plotWidth / 2
            : left + (plotWidth * index) / (labels.length - 1);
        const y = value => top + plotHeight - (Number(value || 0) / maximum) * plotHeight;
        const points = values => values.map((value, index) => value === null ? null : `${x(index)},${y(value)}`);
        const path = values => {
            let result = '';
            let drawing = false;
            points(values).forEach(point => {
                if (point === null) {
                    drawing = false;
                    return;
                }
                result += `${drawing ? ' L ' : 'M '}${point}`;
                drawing = true;
            });
            return result;
        };
        const labelStep = Math.max(1, Math.ceil(labels.length / 8));

        const grid = Array.from({length: 5}, (_, index) => {
            const ratio = index / 4;
            const lineY = top + plotHeight - plotHeight * ratio;
            const label = reportsStep5CompactNumber(maximum * ratio);
            return `<g class="report-chart-grid-line"><line x1="${left}" y1="${lineY}" x2="${width - right}" y2="${lineY}"></line><text x="${left - 9}" y="${lineY + 4}" text-anchor="end">${escapeHtml(label)}</text></g>`;
        }).join('');

        const xLabels = labels.map((label, index) => {
            if (index % labelStep !== 0 && index !== labels.length - 1) return '';
            return `<text class="report-chart-x-label" x="${x(index)}" y="${height - 30}" text-anchor="middle">${escapeHtml(reportsStep5ChartLabel(label, 11))}</text>`;
        }).join('');

        const currentDots = currentValues.map((value, index) => `<circle class="report-chart-dot-current" cx="${x(index)}" cy="${y(value)}" r="3"><title>${escapeHtml(labels[index])}: ${escapeHtml(String(value))}</title></circle>`).join('');
        const previousDots = hasPrevious
            ? previousValues.map((value, index) => value === null ? '' : `<circle class="report-chart-dot-previous" cx="${x(index)}" cy="${y(value)}" r="3"><title>${escapeHtml(labels[index])} — прошлый период: ${escapeHtml(String(value))}</title></circle>`).join('')
            : '';

        return `<article class="report-chart-card">
            <div class="report-chart-head"><div><strong>${escapeHtml(title)}</strong><span>${escapeHtml(subtitle)}</span></div><div class="report-chart-legend"><span><i class="current"></i>Текущий</span>${hasPrevious ? '<span><i class="previous"></i>Прошлый</span>' : ''}</div></div>
            <svg class="report-chart-svg" viewBox="0 0 ${width} ${height}" role="img" aria-label="${escapeHtml(title)}">${grid}${xLabels}<path class="report-chart-line-current" d="${path(currentValues)}"></path>${hasPrevious ? `<path class="report-chart-line-previous" d="${path(previousValues)}"></path>` : ''}${currentDots}${previousDots}</svg>
        </article>`;
    }

    function reportsStep5InsertCharts(root, charts, title = 'Динамика показателей') {
        root.querySelector('[data-reports-step5-charts]')?.remove();
        if (!charts.length) return;

        const section = document.createElement('section');
        section.className = 'report-preview-section report-charts-section';
        section.dataset.reportsStep5Charts = '1';
        section.innerHTML = `<div class="reports-section-head"><div><strong>${escapeHtml(title)}</strong><p class="muted">Текущий период и выбранный период сравнения.</p></div></div><div class="report-chart-grid">${charts.join('')}</div>`;

        const anchor = root.querySelector('.report-kpi-grid, .report-preview-cards');
        anchor?.insertAdjacentElement('afterend', section);
    }

    function reportsStep5RenderSeoCharts(payload) {
        const root = $('#reportPreviewContent');
        if (!root) return;

        const data = payload.seo_data || {};
        const trend = data.trend || [];
        const charts = [];

        if (trend.length) {
            const labels = trend.map(row => row.label || '');
            charts.push(reportsStep5LineChart(
                'Органический трафик',
                'Визиты по дням или периодам',
                labels,
                trend.map(row => row.visits_current),
                trend.map(row => row.visits_previous)
            ));

            if (trend.some(row => Number(row.leads_current || 0) > 0 || row.leads_previous !== null)) {
                charts.push(reportsStep5LineChart(
                    'Заявки из SEO',
                    'Основные цели по дням или периодам',
                    labels,
                    trend.map(row => row.leads_current),
                    trend.map(row => row.leads_previous)
                ));
            }
        }

        const queries = data.queries || [];
        if (queries.length) {
            const currentPositions = queries.map(row => Number(row.position_current || 0)).filter(value => value > 0);
            const previousPositions = queries.map(row => Number(row.position_previous || 0)).filter(value => value > 0);
            charts.push(reportsStep5GroupedBarChart(
                'Распределение позиций',
                'Количество запросов в поисковых диапазонах',
                ['ТОП-3', 'ТОП-10', 'ТОП-30'],
                [
                    currentPositions.filter(value => value <= 3).length,
                    currentPositions.filter(value => value <= 10).length,
                    currentPositions.filter(value => value <= 30).length
                ],
                [
                    previousPositions.filter(value => value <= 3).length,
                    previousPositions.filter(value => value <= 10).length,
                    previousPositions.filter(value => value <= 30).length
                ]
            ));
        }

        reportsStep5InsertCharts(root, charts, 'Графики SEO-отчёта');
    }

    function reportsStep5RenderAdvertisingCharts(payload) {
        const root = $('#reportPreviewContent');
        if (!root) return;

        const channels = (payload.channels || []).filter(channel => {
            const previous = channel.comparison || {};
            return ['clicks', 'leads', 'contracts'].some(key =>
                Number(channel[key] || 0) > 0 || Number(previous[key] || 0) > 0
            );
        });

        if (!channels.length) {
            reportsStep5InsertCharts(root, []);
            return;
        }

        const labels = channels.map(channel => channel.channel_name || channel.channel_key);
        const previousAvailable = Boolean(payload.comparison_date_from && payload.comparison_date_to);
        const previous = key => channels.map(channel => previousAvailable
            ? (channel.comparison?.[key] ?? null)
            : null
        );

        const charts = [
            reportsStep5GroupedBarChart(
                'Переходы по каналам',
                'Клики и переходы из рекламных систем',
                labels,
                channels.map(channel => channel.clicks),
                previous('clicks')
            ),
            reportsStep5GroupedBarChart(
                'Заявки по каналам',
                'Уникальные основные обращения',
                labels,
                channels.map(channel => channel.leads),
                previous('leads')
            ),
            reportsStep5GroupedBarChart(
                'Продажи по каналам',
                'Заключённые договоры',
                labels,
                channels.map(channel => channel.contracts),
                previous('contracts')
            )
        ];

        reportsStep5InsertCharts(root, charts, 'Графики рекламного отчёта');
    }

    const reportsStep5OriginalRenderSeoReportPreview = renderSeoReportPreview;
    renderSeoReportPreview = function(payload) {
        reportsStep5OriginalRenderSeoReportPreview(payload);
        reportsStep5RenderSeoCharts(payload);
    };

    const reportsStep5OriginalRenderReportPreview = renderReportPreview;
    renderReportPreview = function(payload) {
        reportsStep5OriginalRenderReportPreview(payload);
        if (payload.report_type !== 'seo') {
            reportsStep5RenderAdvertisingCharts(payload);
        }
    };

    const reportsStep5OriginalToggleSeoTemplate = toggleSeoTemplate;
    toggleSeoTemplate = function(type) {
        reportsStep5OriginalToggleSeoTemplate(type);
        reportsStep5SetupSeoCollapsibles();
        reportsStep5SetupRichFieldCollapsibles();
    };

    reportsStep5SetupSeoCollapsibles();
    reportsStep5SetupRichFieldCollapsibles();
