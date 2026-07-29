    /* REPORTS_STEP7_RESULTS_JS */
    function reportsStep7AdvertisingResultsField() {
        return `
            <div class="rich-field" data-rich-field="advertising_results" id="advertisingResultsField">
                <span class="rich-field-label">Полученные результаты</span>
                <div class="rich-editor-shell">
                    <div class="rich-toolbar">
                        <button type="button" data-rich-command="bold"><strong>B</strong></button>
                        <button type="button" data-rich-command="italic"><em>I</em></button>
                        <button type="button" data-rich-command="underline"><u>U</u></button>
                        <button type="button" data-rich-block="h3">H3</button>
                        <button type="button" data-rich-command="insertUnorderedList">• Список</button>
                        <button type="button" data-rich-command="insertOrderedList">1. Список</button>
                        <button type="button" data-rich-link>Ссылка</button>
                        <button type="button" data-rich-image>Изображение</button>
                        <button type="button" data-rich-command="removeFormat">Очистить</button>
                    </div>
                    <div class="rich-editor" contenteditable="true" data-rich-editor="advertising_results" data-placeholder="Какие результаты получены: динамика переходов, заявок, стоимости лида, качества обращений и продаж..."></div>
                </div>
            </div>`;
    }

    function reportsStep7EnsureAdvertisingResultsField() {
        const grid = $('#reportForm .report-rich-grid');
        if (!grid || $('#advertisingResultsField')) return;
        grid.insertAdjacentHTML('afterbegin', reportsStep7AdvertisingResultsField());
        reportsStep5SetupRichFieldCollapsibles?.();
    }

    function reportsStep7ToggleAdvertisingResults(type) {
        reportsStep7EnsureAdvertisingResultsField();
        $('#advertisingResultsField')?.classList.toggle('hidden', type === 'seo');
    }

    const reportsStep7ResultsOriginalCollectReportPayload = collectReportPayload;
    collectReportPayload = function() {
        const payload = reportsStep7ResultsOriginalCollectReportPayload();
        if (payload.report_type !== 'seo') {
            payload.advertising_results = richEditorValue('advertising_results');
        }
        return payload;
    };

    const reportsStep7ResultsOriginalResetReportForm = resetReportForm;
    resetReportForm = function() {
        reportsStep7ResultsOriginalResetReportForm();
        reportsStep7EnsureAdvertisingResultsField();
        setRichEditorValue('advertising_results', '');
        reportsStep7ToggleAdvertisingResults('advertising_summary');
    };

    const reportsStep7ResultsOriginalFillReportForm = fillReportForm;
    fillReportForm = function(report) {
        reportsStep7ResultsOriginalFillReportForm(report);
        reportsStep7EnsureAdvertisingResultsField();
        if (report.report_type !== 'seo') {
            setRichEditorValue(
                'advertising_results',
                report.advertising_results || ''
            );
        }
        reportsStep7ToggleAdvertisingResults(
            report.report_type || 'advertising_summary'
        );
    };

    const reportsStep7ResultsOriginalToggleSeoTemplate = toggleSeoTemplate;
    toggleSeoTemplate = function(type) {
        reportsStep7ResultsOriginalToggleSeoTemplate(type);
        reportsStep7ToggleAdvertisingResults(type);
    };

    const reportsStep7ResultsOriginalRenderReportPreview = renderReportPreview;
    renderReportPreview = function(payload) {
        reportsStep7ResultsOriginalRenderReportPreview(payload);
        if (payload.report_type === 'seo' || !payload.advertising_results) return;
        const grid = $('#reportPreviewContent .report-preview-text-grid');
        if (!grid) return;
        const section = document.createElement('section');
        section.className = 'report-preview-text advertising-results-preview';
        section.innerHTML = `<h3>Полученные результаты</h3><div class="report-rich-content">${reportCleanRichHtml(payload.advertising_results)}</div>`;
        grid.insertAdjacentElement('afterbegin', section);
    };

    reportsStep7EnsureAdvertisingResultsField();
    reportsStep7ToggleAdvertisingResults(
        $('#reportForm')?.elements.report_type.value || 'advertising_summary'
    );
