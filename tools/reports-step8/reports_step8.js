    /* REPORTS_STEP8_JS */
    const reportsStep8OriginalApplyAdvertisingAutofill =
        reportsStep7ApplyAdvertisingAutofill;

    reportsStep7ApplyAdvertisingAutofill = function(data) {
        reportsStep8OriginalApplyAdvertisingAutofill(data);

        (data.channels || []).forEach(channel => {
            const card = $(
                `.report-channel-card[data-channel-key="${CSS.escape(channel.channel_key)}"]`
            );

            if (!card) return;

            const setValue = (selector, value) => {
                const input = card.querySelector(selector);

                if (input && value !== null && value !== undefined) {
                    input.value = value;
                }
            };

            setValue('[data-field="spend"]', channel.spend);
            setValue('[data-field="impressions"]', channel.impressions);
            setValue('[data-field="clicks"]', channel.clicks);
            setValue('[data-field="leads"]', channel.leads);
            setValue('[data-comparison-field="spend"]', channel.previous_spend);
            setValue('[data-comparison-field="impressions"]', channel.previous_impressions);
            setValue('[data-comparison-field="clicks"]', channel.previous_clicks);
            setValue('[data-comparison-field="leads"]', channel.previous_leads);

            if (channel.source_type === 'api') {
                setValue('[data-field="source_type"]', 'api');
            }
        });

        const directGroups = data.campaign_groups || [];
        const directCard = $('.report-channel-card[data-channel-key="direct"]');
        const groupRoot = directCard?.querySelector('[data-campaign-groups]');

        if (groupRoot && directGroups.length) {
            groupRoot.innerHTML = directGroups
                .map(group => campaignGroupHtml('direct', group))
                .join('');
        }

        reportsStep7RenderAdvertisingKpis();

        const sources = (data.sources_used || []).join(' + ');
        const warnings = data.warnings || [];
        reportsStep7ShowAdvertisingMessage(
            warnings.length ? 'warning' : 'success',
            `
                <strong>Рекламные данные загружены</strong>
                <span>
                    Источники: ${escapeHtml(sources)}.
                    Директ заполнил расходы, показы, клики и кампании.
                    Метрика заполнила заявки и UTM-разбивку.
                </span>
                <span>
                    Квалифицированные лиды, договоры и выручка не перезаписывались.
                </span>
                ${warnings.length ? `
                    <ul>
                        ${warnings.map(item => `<li>${escapeHtml(item)}</li>`).join('')}
                    </ul>
                ` : ''}
            `
        );
    };
