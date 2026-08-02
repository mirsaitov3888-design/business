
/* P1_SALES_V180211 */
(() => {
    'use strict';

    const state = {
        context: null,
        records: [],
        loaded: false
    };

    const qs = (selector, root = document) => root.querySelector(selector);
    const money = new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: 'RUB',
        maximumFractionDigits: 0
    });
    const number = new Intl.NumberFormat('ru-RU');

    function html(value) {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }

    async function request(action, options = {}) {
        const response = await fetch(`/p1-api.php?action=${encodeURIComponent(action)}`, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                ...(options.body instanceof FormData
                    ? {}
                    : {'Content-Type': 'application/json'}),
                ...(options.headers || {})
            },
            ...options
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok || data.error) {
            throw new Error(data.error || `HTTP ${response.status}`);
        }
        return data;
    }

    function ensureNavigation() {
        if (qs('.nav-link[data-section="p1-sales"]')) return;
        const reports = qs('.nav-link[data-section="reports"]');
        const button = reports
            ? reports.cloneNode(true)
            : document.createElement('button');
        button.type = 'button';
        button.classList.add('nav-link');
        button.dataset.section = 'p1-sales';
        const textNode = button.querySelector('.nav-text, span:last-child');
        if (textNode) {
            textNode.textContent = 'Продажи и экономика';
        } else {
            button.textContent = 'Продажи и экономика';
        }
        button.addEventListener('click', () => {
            if (typeof showSection === 'function') {
                showSection('p1-sales');
            } else {
                document.querySelectorAll('.section').forEach(section => {
                    section.classList.toggle('active', section.id === 'section-p1-sales');
                });
            }
            loadData();
        });
        if (reports?.parentElement) {
            reports.insertAdjacentElement('afterend', button);
        } else {
            qs('.sidebar, nav')?.append(button);
        }
    }

    function ensureSection() {
        if (qs('#section-p1-sales')) return;
        const section = document.createElement('section');
        section.id = 'section-p1-sales';
        section.className = 'section p1-sales-section';
        section.innerHTML = `
            <div class="p1-head">
                <div>
                    <p class="eyebrow">P1 — исходный MVP отчётности</p>
                    <h2>Продажи и экономика</h2>
                    <p class="muted">
                        Единый источник сделок для расчёта квалифицированных лидов,
                        договоров, выручки, ROAS и ROMI.
                    </p>
                </div>
                <div class="p1-project-badge" id="p1ProjectName"></div>
            </div>

            <div id="p1Message"></div>

            <article class="panel p1-filter-panel">
                <div class="p1-filter-row">
                    <label>
                        <span>Период с</span>
                        <input type="date" id="p1DateFrom">
                    </label>
                    <label>
                        <span>Период по</span>
                        <input type="date" id="p1DateTo">
                    </label>
                    <button type="button" class="button" id="p1Reload">Обновить</button>
                </div>
            </article>

            <div class="metric-grid p1-summary" id="p1Summary"></div>

            <div class="p1-workspace">
                <article class="panel p1-editor-panel">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">Ручной ввод</p>
                            <h2 id="p1FormTitle">Новая запись</h2>
                        </div>
                        <button type="button" class="button" id="p1Reset">Очистить</button>
                    </div>
                    <form id="p1SalesForm" class="settings-form">
                        <input type="hidden" name="id" value="0">
                        <div class="form-grid p1-form-grid">
                            <label>
                                <span>Дата</span>
                                <input type="date" name="occurred_at" required>
                            </label>
                            <label>
                                <span>Канал</span>
                                <select name="channel_key" required></select>
                            </label>
                            <label>
                                <span>Клиент или контакт</span>
                                <input name="customer_name" maxlength="255" placeholder="Название или имя">
                            </label>
                            <label>
                                <span>ID сделки</span>
                                <input name="external_id" maxlength="190" placeholder="Например, CRM-125">
                            </label>
                            <label>
                                <span>Статус</span>
                                <select name="status" required></select>
                            </label>
                            <label>
                                <span>Сумма договора</span>
                                <input name="contract_amount" inputmode="decimal" placeholder="0">
                            </label>
                            <label>
                                <span>Оплачено</span>
                                <input name="paid_amount" inputmode="decimal" placeholder="0">
                            </label>
                            <label>
                                <span>Валовая маржа, %</span>
                                <input name="gross_margin_percent" inputmode="decimal" placeholder="Необязательно">
                            </label>
                        </div>
                        <div class="p1-checks">
                            <label><input type="checkbox" name="qualified"> Квалифицированный лид</label>
                            <label><input type="checkbox" name="contract"> Есть договор</label>
                        </div>
                        <label>
                            <span>Комментарий</span>
                            <textarea name="notes" rows="4" maxlength="5000"></textarea>
                        </label>
                        <div class="report-actions">
                            <button type="submit" class="button button-primary">Сохранить</button>
                        </div>
                        <div id="p1FormMessage"></div>
                    </form>
                </article>

                <article class="panel p1-import-panel">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">Импорт</p>
                            <h2>CSV / XLSX</h2>
                        </div>
                    </div>
                    <p class="muted" id="p1ImportHelp"></p>
                    <form id="p1ImportForm" class="settings-form">
                        <label class="p1-file-field">
                            <span>Файл до 10 МБ и 5000 строк</span>
                            <input type="file" name="sales_file" accept=".csv,.txt,.xlsx" required>
                        </label>
                        <div class="report-actions p1-import-actions">
                            <button type="submit" class="button button-primary">Импортировать</button>
                            <a class="button" href="/p1-api.php?action=template">Шаблон CSV</a>
                        </div>
                        <div id="p1ImportMessage"></div>
                    </form>
                    <div class="p1-import-history" id="p1Imports"></div>
                </article>
            </div>

            <article class="panel p1-records-panel">
                <div class="panel-head">
                    <div>
                        <p class="eyebrow">Журнал</p>
                        <h2>Продажи и сделки</h2>
                    </div>
                    <span class="muted" id="p1RecordCount"></span>
                </div>
                <div class="table-scroll">
                    <table class="data-table p1-records-table">
                        <thead>
                            <tr>
                                <th>Дата</th>
                                <th>Канал</th>
                                <th>Клиент</th>
                                <th>Статус</th>
                                <th class="num">Договор</th>
                                <th class="num">Оплачено</th>
                                <th>Источник</th>
                                <th></th>
                            </tr>
                        </thead>
                        <tbody id="p1Records"></tbody>
                    </table>
                </div>
            </article>
        `;
        const main = qs('main, .main-content, .content');
        main?.append(section);
    }

    function fillOptions() {
        const form = qs('#p1SalesForm');
        if (!form || !state.context) return;
        form.elements.channel_key.innerHTML = Object.entries(state.context.channels)
            .map(([key, label]) => `<option value="${html(key)}">${html(label)}</option>`)
            .join('');
        form.elements.status.innerHTML = Object.entries(state.context.statuses)
            .map(([key, label]) => `<option value="${html(key)}">${html(label)}</option>`)
            .join('');
    }

    function resetForm() {
        const form = qs('#p1SalesForm');
        if (!form || !state.context) return;
        form.reset();
        form.elements.id.value = '0';
        form.elements.occurred_at.value = state.context.defaults.occurred_at;
        form.elements.channel_key.value = 'direct';
        form.elements.status.value = 'lead';
        qs('#p1FormTitle').textContent = 'Новая запись';
        qs('#p1FormMessage').className = '';
        qs('#p1FormMessage').textContent = '';
    }

    function formPayload() {
        const form = qs('#p1SalesForm');
        const data = new FormData(form);
        return {
            id: Number(data.get('id') || 0),
            occurred_at: data.get('occurred_at'),
            channel_key: data.get('channel_key'),
            customer_name: data.get('customer_name'),
            external_id: data.get('external_id'),
            status: data.get('status'),
            contract_amount: data.get('contract_amount'),
            paid_amount: data.get('paid_amount'),
            gross_margin_percent: data.get('gross_margin_percent'),
            qualified: form.elements.qualified.checked,
            contract: form.elements.contract.checked,
            notes: data.get('notes')
        };
    }

    function editRecord(id) {
        const record = state.records.find(item => Number(item.id) === Number(id));
        const form = qs('#p1SalesForm');
        if (!record || !form) return;
        form.elements.id.value = record.id;
        form.elements.occurred_at.value = record.occurred_at;
        form.elements.channel_key.value = record.channel_key;
        form.elements.customer_name.value = record.customer_name || '';
        form.elements.external_id.value = record.external_id || '';
        form.elements.status.value = record.status;
        form.elements.contract_amount.value = Number(record.contract_amount || 0) || '';
        form.elements.paid_amount.value = Number(record.paid_amount || 0) || '';
        form.elements.gross_margin_percent.value = record.gross_margin_percent ?? '';
        form.elements.qualified.checked = Boolean(record.qualified);
        form.elements.contract.checked = Boolean(record.contract);
        form.elements.notes.value = record.notes || '';
        qs('#p1FormTitle').textContent = `Редактирование #${record.id}`;
        form.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    function renderSummary(summary) {
        const cards = [
            ['Записи', number.format(summary.records_count || 0), 'Все этапы'],
            ['Квалифицированные', number.format(summary.qualified_count || 0), 'Целевые лиды'],
            ['Договоры', number.format(summary.contracts_count || 0), 'Заключено'],
            ['Сумма договоров', money.format(summary.contract_amount || 0), 'Потенциальная выручка'],
            ['Оплачено', money.format(summary.paid_amount || 0), 'Фактическая выручка'],
            ['Средний договор', summary.average_contract === null ? '—' : money.format(summary.average_contract), 'Сумма / договоры']
        ];
        qs('#p1Summary').innerHTML = cards.map(([title, value, note]) => `
            <article class="metric-card">
                <span>${html(title)}</span>
                <strong>${html(value)}</strong>
                <small>${html(note)}</small>
            </article>
        `).join('');
    }

    function renderRecords(records) {
        state.records = records;
        qs('#p1RecordCount').textContent = `${number.format(records.length)} записей`;
        qs('#p1Records').innerHTML = records.length
            ? records.map(record => `
                <tr data-record-id="${Number(record.id)}">
                    <td>${html(record.occurred_at)}</td>
                    <td>${html(record.channel_name)}</td>
                    <td>
                        <strong>${html(record.customer_name || 'Без имени')}</strong>
                        ${record.external_id ? `<small>${html(record.external_id)}</small>` : ''}
                    </td>
                    <td><span class="p1-status p1-status-${html(record.status)}">${html(record.status_name)}</span></td>
                    <td class="num">${html(money.format(record.contract_amount || 0))}</td>
                    <td class="num">${html(money.format(record.paid_amount || 0))}</td>
                    <td>${record.source_type === 'import' ? `Импорт${record.import_file ? `<small>${html(record.import_file)}</small>` : ''}` : 'Вручную'}</td>
                    <td class="p1-row-actions">
                        <button type="button" class="button p1-edit" data-id="${Number(record.id)}">Изменить</button>
                        <button type="button" class="button p1-delete" data-id="${Number(record.id)}">Удалить</button>
                    </td>
                </tr>
            `).join('')
            : '<tr><td colspan="8" class="muted">За выбранный период записей нет.</td></tr>';
    }

    function renderImports(imports) {
        qs('#p1Imports').innerHTML = imports.length
            ? `
                <strong>Последние импорты</strong>
                ${imports.slice(0, 5).map(item => `
                    <div class="p1-import-item">
                        <span>${html(item.original_name)}</span>
                        <small>
                            добавлено ${number.format(item.rows_imported)},
                            пропущено ${number.format(item.rows_skipped)},
                            ошибок ${number.format(item.rows_failed)}
                        </small>
                    </div>
                `).join('')}
            `
            : '<span class="muted">Импортов пока не было.</span>';
    }

    async function loadData() {
        if (!state.context) return;
        const from = qs('#p1DateFrom').value;
        const to = qs('#p1DateTo').value;
        const message = qs('#p1Message');
        message.className = '';
        message.textContent = 'Загрузка...';
        try {
            const result = await request(`list&date_from=${encodeURIComponent(from)}&date_to=${encodeURIComponent(to)}`);
            renderSummary(result.summary);
            renderRecords(result.records);
            renderImports(result.imports || []);
            message.textContent = '';
            state.loaded = true;
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    }

    async function init() {
        if (window.__p1SalesInitialized) return;
        window.__p1SalesInitialized = true;
        try {
            state.context = await request('context');
        } catch (_) {
            return;
        }
        ensureNavigation();
        ensureSection();
        fillOptions();
        qs('#p1ProjectName').textContent = state.context.project.name;
        qs('#p1DateFrom').value = state.context.defaults.date_from;
        qs('#p1DateTo').value = state.context.defaults.date_to;
        qs('#p1ImportHelp').textContent = state.context.capabilities.xlsx
            ? 'Поддерживаются CSV и XLSX. Дубликаты определяются автоматически.'
            : 'Поддерживается CSV. Для XLSX на сервере требуется расширение ZIP.';
        resetForm();

        qs('#p1Reload').addEventListener('click', loadData);
        qs('#p1Reset').addEventListener('click', resetForm);

        qs('#p1SalesForm').addEventListener('submit', async event => {
            event.preventDefault();
            const message = qs('#p1FormMessage');
            const submit = event.currentTarget.querySelector('button[type="submit"]');
            submit.disabled = true;
            message.className = '';
            message.textContent = 'Сохранение...';
            try {
                const result = await request('save', {
                    method: 'POST',
                    body: JSON.stringify(formPayload())
                });
                message.className = 'alert alert-success';
                message.textContent = result.message;
                resetForm();
                await loadData();
            } catch (error) {
                message.className = 'alert alert-error';
                message.textContent = error.message;
            } finally {
                submit.disabled = false;
            }
        });

        qs('#p1ImportForm').addEventListener('submit', async event => {
            event.preventDefault();
            const form = event.currentTarget;
            const message = qs('#p1ImportMessage');
            const submit = form.querySelector('button[type="submit"]');
            submit.disabled = true;
            message.className = '';
            message.textContent = 'Импорт...';
            try {
                const result = await request('import', {
                    method: 'POST',
                    body: new FormData(form)
                });
                const info = result.result;
                message.className = info.rows_failed > 0
                    ? 'alert alert-warning'
                    : 'alert alert-success';
                message.textContent = `Добавлено: ${info.rows_imported}; пропущено: ${info.rows_skipped}; ошибок: ${info.rows_failed}.`;
                form.reset();
                await loadData();
            } catch (error) {
                message.className = 'alert alert-error';
                message.textContent = error.message;
            } finally {
                submit.disabled = false;
            }
        });

        qs('#p1Records').addEventListener('click', async event => {
            const edit = event.target.closest('.p1-edit');
            if (edit) {
                editRecord(edit.dataset.id);
                return;
            }
            const remove = event.target.closest('.p1-delete');
            if (!remove || !confirm('Удалить запись продажи?')) return;
            try {
                await request('delete', {
                    method: 'POST',
                    body: JSON.stringify({id: Number(remove.dataset.id)})
                });
                await loadData();
            } catch (error) {
                const message = qs('#p1Message');
                message.className = 'alert alert-error';
                message.textContent = error.message;
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init, {once: true});
    } else {
        init();
    }
})();
