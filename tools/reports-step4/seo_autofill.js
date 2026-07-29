    /* REPORTS_STEP4_JS */
    let seoAutofillLastMeta = null;

    function seoAutofillInstallControls() {
        const intro = $('#seoReportEditor .seo-section-intro');

        if (!intro || $('#seoAutoFill')) return;

        const controls = document.createElement('div');
        controls.className = 'seo-autofill-controls';
        controls.innerHTML = `
            <button type="button" class="button button-primary" id="seoAutoFill">
                Загрузить из Метрики и Вебмастера
            </button>
            <small>
                Финансы и текстовые блоки не изменяются
            </small>
        `;
        intro.append(controls);

        const message = document.createElement('div');
        message.id = 'seoAutoFillMessage';
        message.className = 'seo-autofill-message hidden';
        intro.insertAdjacentElement('afterend', message);
    }

    function seoAutofillHasData() {
        const metricFilled = $$([
            '[data-seo-metric]',
            '[data-seo-comparison]'
        ].join(',')).some(input => input.value.trim() !== '');

        const contentRows = [
            'trend',
            'query',
            'page',
            'issue'
        ].some(type => $$(`[data-seo-row="${type}"]`).length > 0);

        const sourceNumbers = $$([
            '[data-seo-row="source"] input[type="number"]'
        ].join(',')).some(input => input.value.trim() !== '');

        return metricFilled || contentRows || sourceNumbers;
    }

    function seoAutofillShowMessage(type, html) {
        const root = $('#seoAutoFillMessage');

        if (!root) return;

        root.className = `seo-autofill-message ${type}`;
        root.innerHTML = html;
    }

    function seoAutofillQualityWarnings(quality) {
        const warnings = [];

        Object.entries(quality || {}).forEach(([name, item]) => {
            if (item?.sampled) {
                const share = item.sample_share == null
                    ? ''
                    : ` (${number.format(Number(item.sample_share) * 100)}%)`;
                warnings.push(`Семплирование: ${name}${share}`);
            }

            if (Number(item?.data_lag || 0) > 0) {
                warnings.push(`Задержка данных: ${name}`);
            }
        });

        return warnings;
    }

    function seoAutofillRenderStatus(data) {
        const warnings = [
            ...(data.warnings || []),
            ...seoAutofillQualityWarnings(data.quality || {})
        ];
        const goals = data.goal_ids || [];
        const period = data.period || {};
        const sourceText = (data.sources_used || []).join(' + ');
        const comparisonText = period.comparison_date_from
            && period.comparison_date_to
                ? ` Сравнение: ${escapeHtml(reportDate(period.comparison_date_from))}—${escapeHtml(reportDate(period.comparison_date_to))}.`
                : '';

        seoAutofillShowMessage(
            warnings.length ? 'warning' : 'success',
            `
                <div class="seo-autofill-summary">
                    <strong>Данные загружены</strong>
                    <span>
                        ${escapeHtml(sourceText)}.
                        Период: ${escapeHtml(reportDate(period.date_from))}—${escapeHtml(reportDate(period.date_to))}.${comparisonText}
                    </span>
                    <span>
                        Основных целей: ${escapeHtml(String(goals.length))}.
                        Финансы и редакторские блоки сохранены без изменений.
                    </span>
                </div>
                ${warnings.length ? `
                    <details class="seo-autofill-warnings" open>
                        <summary>Ограничения данных: ${warnings.length}</summary>
                        <ul>
                            ${warnings.map(warning => `<li>${escapeHtml(warning)}</li>`).join('')}
                        </ul>
                    </details>
                ` : ''}
            `
        );
    }

    function seoAutofillApply(data) {
        seoSet('[data-seo-metric]', data.metrics || {});
        seoSet('[data-seo-comparison]', data.comparison || {});
        seoRenderRows('trend', data.trend || []);
        seoRenderRows('source', data.sources || []);
        seoRenderRows('query', data.queries || []);
        seoRenderRows('page', data.pages || []);
        seoRenderRows('issue', data.issues || []);
        seoAutofillLastMeta = data;
        seoAutofillRenderStatus(data);
    }

    function seoAutofillAddPreviewSource(data) {
        const root = $('#reportPreviewContent');

        if (!root || !data) return;

        root.querySelector('.seo-autofill-preview-source')?.remove();

        const notice = document.createElement('div');
        notice.className = 'seo-autofill-preview-source';
        notice.innerHTML = `
            <strong>Источники данных:</strong>
            ${escapeHtml((data.sources_used || []).join(', '))}.
            Показатели продаж и финансовые данные заполняются отдельно.
        `;

        const header = root.querySelector('.report-document-head');
        header?.insertAdjacentElement('afterend', notice);
    }

    async function seoAutofillLoad() {
        const form = $('#reportForm');
        const button = $('#seoAutoFill');

        if (!form || !button) return;

        if (form.elements.report_type.value !== 'seo') {
            return;
        }

        const dateFrom = form.elements.date_from.value;
        const dateTo = form.elements.date_to.value;
        const comparisonDateFrom =
            form.elements.comparison_date_from.value;
        const comparisonDateTo =
            form.elements.comparison_date_to.value;

        if (!dateFrom || !dateTo) {
            seoAutofillShowMessage(
                'error',
                'Укажите текущий период отчёта.'
            );
            return;
        }

        if (
            Boolean(comparisonDateFrom)
            !== Boolean(comparisonDateTo)
        ) {
            seoAutofillShowMessage(
                'error',
                'Для сравнения укажите обе даты периода.'
            );
            return;
        }

        if (
            seoAutofillHasData()
            && !confirm(
                'Автозагрузка заменит показатели трафика, запросы, страницы и диагностику в SEO-шаблоне. Финансы и текстовые блоки останутся без изменений. Продолжить?'
            )
        ) {
            return;
        }

        const parameters = new URLSearchParams({
            action: 'seo_autofill',
            date1: dateFrom,
            date2: dateTo
        });

        if (comparisonDateFrom && comparisonDateTo) {
            parameters.set(
                'comparison_date1',
                comparisonDateFrom
            );
            parameters.set(
                'comparison_date2',
                comparisonDateTo
            );
        }

        button.disabled = true;
        button.classList.add('loading');
        seoAutofillShowMessage(
            'loading',
            'Загружаем Метрику и Вебмастер. Для большого периода это может занять до минуты.'
        );

        try {
            const result = await api(
                `/api.php?${parameters.toString()}`
            );

            seoAutofillApply(result.data || {});

            const payload = collectSeoReportPayload();
            renderSeoReportPreview(payload);
            seoAutofillAddPreviewSource(result.data || {});
        } catch (error) {
            seoAutofillShowMessage(
                'error',
                escapeHtml(error.message)
            );
        } finally {
            button.disabled = false;
            button.classList.remove('loading');
        }
    }

    seoAutofillInstallControls();

    $('#seoAutoFill')?.addEventListener(
        'click',
        seoAutofillLoad
    );

    $('#previewReport')?.addEventListener(
        'click',
        () => {
            if (
                $('#reportForm')?.elements.report_type.value === 'seo'
                && seoAutofillLastMeta
            ) {
                setTimeout(
                    () => seoAutofillAddPreviewSource(
                        seoAutofillLastMeta
                    ),
                    0
                );
            }
        }
    );
