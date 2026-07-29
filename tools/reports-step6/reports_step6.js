    /* REPORTS_STEP6_JS */

    function reportsStep6RoundToHundredths(value) {
        if (value === null || value === undefined || value === '') {
            return '';
        }

        const parsed = Number(String(value).replace(',', '.'));

        if (!Number.isFinite(parsed)) {
            return '';
        }

        return String(Math.round((parsed + Number.EPSILON) * 100) / 100);
    }

    function reportsStep6NormalizePercentInputs(root = document) {
        const selectors = [
            '[data-seo-metric="bounce_rate"]',
            '[data-seo-comparison="bounce_rate"]',
            '[data-seo-finance="gross_margin_percent"]',
            '[data-seo-finance-comparison="gross_margin_percent"]',
            '[data-seo-field="bounce_rate"]'
        ].join(',');

        root.querySelectorAll(selectors).forEach(input => {
            if (input.value.trim() === '') return;
            input.value = reportsStep6RoundToHundredths(input.value);
        });
    }

    const reportsStep6OriginalSeoSet = seoSet;
    seoSet = function(selector, data = {}) {
        reportsStep6OriginalSeoSet(selector, data);
        reportsStep6NormalizePercentInputs($('#seoReportEditor') || document);
    };

    const reportsStep6OriginalSeoRenderRows = seoRenderRows;
    seoRenderRows = function(type, rows = []) {
        reportsStep6OriginalSeoRenderRows(type, rows);
        reportsStep6NormalizePercentInputs($('#seoReportEditor') || document);
    };

    reportPercent = function(value) {
        if (value === null || value === undefined || value === '') {
            return '—';
        }

        const parsed = Number(value);

        if (!Number.isFinite(parsed)) {
            return '—';
        }

        return `${new Intl.NumberFormat('ru-RU', {
            minimumFractionDigits: 0,
            maximumFractionDigits: 2
        }).format(parsed)}%`;
    };

    function reportsStep6NormalizeToggleArrows(root = document) {
        root.querySelectorAll('.seo-collapse-toggle, .reports-rich-collapse-toggle')
            .forEach(button => {
                const icon = button.querySelector('span[aria-hidden="true"]');
                if (!icon) return;

                const expanded = button.getAttribute('aria-expanded') === 'true';
                icon.textContent = expanded ? '▴' : '▾';
                icon.classList.add('seo-toggle-icon');
            });
    }

    function reportsStep6SeoCards() {
        return $$('.seo-editor-card', $('#seoReportEditor') || document);
    }

    function reportsStep6RefreshGlobalToggle() {
        const button = $('#seoToggleAllSections');
        const cards = reportsStep6SeoCards();

        if (!button || !cards.length) return;

        const allCollapsed = cards.every(card =>
            card.classList.contains('is-collapsed')
        );

        button.setAttribute(
            'aria-expanded',
            allCollapsed ? 'false' : 'true'
        );
        button.innerHTML = allCollapsed
            ? '<span class="seo-toggle-icon" aria-hidden="true">▾</span> Развернуть все'
            : '<span class="seo-toggle-icon" aria-hidden="true">▴</span> Свернуть все';
    }

    function reportsStep6SetAllSeoCards(collapsed) {
        reportsStep6SeoCards().forEach(card =>
            reportsStep5SetCollapsed(card, collapsed)
        );
        reportsStep6NormalizeToggleArrows($('#seoReportEditor') || document);
        reportsStep6RefreshGlobalToggle();
    }

    const reportsStep6OriginalSetCollapsed = reportsStep5SetCollapsed;
    reportsStep5SetCollapsed = function(card, collapsed) {
        reportsStep6OriginalSetCollapsed(card, collapsed);
        reportsStep6NormalizeToggleArrows(card);
        reportsStep6RefreshGlobalToggle();
    };

    function reportsStep6SetupSeoIntro() {
        const editor = $('#seoReportEditor');
        const intro = editor?.querySelector('.seo-section-intro');

        if (!editor || !intro) return;

        intro.classList.add('seo-section-intro-step6');

        const copy = intro.querySelector(':scope > div:first-child');
        copy?.classList.add('seo-section-intro-copy');

        const collapseControls = $('#seoCollapseControls');
        const autofillControls = intro.querySelector('.seo-autofill-controls');

        if (collapseControls) {
            collapseControls.className = 'seo-collapse-controls';
            collapseControls.innerHTML = `
                <button
                    type="button"
                    class="seo-collapse-toggle seo-collapse-all-toggle"
                    id="seoToggleAllSections"
                    aria-expanded="true"
                ></button>
            `;

            const toggle = $('#seoToggleAllSections');
            toggle.onclick = () => {
                const cards = reportsStep6SeoCards();
                const allCollapsed = cards.length > 0
                    && cards.every(card => card.classList.contains('is-collapsed'));
                reportsStep6SetAllSeoCards(!allCollapsed);
            };

            intro.append(collapseControls);
        }

        if (autofillControls) {
            intro.append(autofillControls);
        }

        if (editor.dataset.step6CollapseSync !== '1') {
            editor.dataset.step6CollapseSync = '1';
            editor.addEventListener('click', event => {
                if (
                    event.target.closest('.seo-collapse-toggle')
                    || event.target.closest('.reports-rich-collapse-toggle')
                ) {
                    setTimeout(() => {
                        reportsStep6NormalizeToggleArrows(editor);
                        reportsStep6RefreshGlobalToggle();
                    }, 0);
                }
            });
        }

        reportsStep6NormalizeToggleArrows(editor);
        reportsStep6RefreshGlobalToggle();
    }

    const reportsStep6OriginalToggleSeoTemplate = toggleSeoTemplate;
    toggleSeoTemplate = function(type) {
        reportsStep6OriginalToggleSeoTemplate(type);
        reportsStep6SetupSeoIntro();
        reportsStep6NormalizePercentInputs($('#seoReportEditor') || document);
    };

    const reportsStep6OriginalAutofillApply = seoAutofillApply;
    seoAutofillApply = function(data) {
        reportsStep6OriginalAutofillApply(data);
        reportsStep6NormalizePercentInputs($('#seoReportEditor') || document);
        reportsStep6SetupSeoIntro();
    };

    reportsStep6SetupSeoIntro();
    reportsStep6NormalizePercentInputs($('#seoReportEditor') || document);
    reportsStep6NormalizeToggleArrows(document);
