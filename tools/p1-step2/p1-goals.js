
/* P1_GOALS_V180212 */
(() => {
    'use strict';

    const state = {
        context: null,
        goals: []
    };

    const qs = (selector, root = document) => root.querySelector(selector);
    const number = new Intl.NumberFormat('ru-RU');

    function escapeHtml(value) {
        const node = document.createElement('div');
        node.textContent = String(value ?? '');
        return node.innerHTML;
    }

    async function request(action, options = {}) {
        const response = await fetch(`/p1-goals-api.php?action=${encodeURIComponent(action)}`, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Content-Type': 'application/json',
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

    function waitForSection() {
        const existing = qs('#section-p1-sales');
        if (existing) {
            return Promise.resolve(existing);
        }
        return new Promise(resolve => {
            const observer = new MutationObserver(() => {
                const section = qs('#section-p1-sales');
                if (section) {
                    observer.disconnect();
                    resolve(section);
                }
            });
            observer.observe(document.documentElement, {
                childList: true,
                subtree: true
            });
        });
    }

    function ensureTabs(section) {
        if (qs('#p1GoalsTab', section)) return;

        const head = qs('.p1-head', section);
        if (!head) return;

        const transactionsPane = document.createElement('div');
        transactionsPane.id = 'p1TransactionsPane';
        transactionsPane.className = 'p1-tab-pane active';

        [...section.children].forEach(child => {
            if (child !== head) {
                transactionsPane.append(child);
            }
        });

        const tabs = document.createElement('div');
        tabs.className = 'p1-tabs';
        tabs.innerHTML = `
            <button type="button" class="p1-tab active" data-p1-tab="transactions">
                Сделки и продажи
            </button>
            <button type="button" class="p1-tab" data-p1-tab="goals">
                Классификация целей
                <span id="p1UnclassifiedBadge"></span>
            </button>
        `;

        const goalsPane = document.createElement('div');
        goalsPane.id = 'p1GoalsTab';
        goalsPane.className = 'p1-tab-pane';
        goalsPane.innerHTML = `
            <div class="p12-intro-grid">
                <article class="panel p12-intro">
                    <p class="eyebrow">P1.2 — единые правила конверсий</p>
                    <h2>Классификация целей</h2>
                    <p>
                        Только цели класса «Лид» войдут в экономические показатели.
                        Вспомогательные и микроконверсии показываются отдельно и не
                        увеличивают количество заявок.
                    </p>
                    <div class="p12-legend">
                        <div><strong>Лид</strong><span>Форма, звонок, заказ, заявка — участвует в CPL.</span></div>
                        <div><strong>Вспомогательная</strong><span>Показывает намерение, но ещё не является заявкой.</span></div>
                        <div><strong>Микроконверсия</strong><span>Просмотр, скролл, клик, скачивание и другие действия.</span></div>
                        <div><strong>Не классифицирована</strong><span>Не участвует в экономике до принятия решения.</span></div>
                    </div>
                </article>

                <article class="panel p12-sync-panel">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">Яндекс Метрика</p>
                            <h2>Цели проекта</h2>
                        </div>
                    </div>
                    <p class="muted">
                        Синхронизация читает уже сохранённые в проекте ID целей.
                        Новые цели добавляются без классификации и требуют проверки специалиста.
                    </p>
                    <button type="button" class="button button-primary" id="p12Sync">
                        Подтянуть цели проекта
                    </button>
                    <div id="p12SyncMessage"></div>
                </article>
            </div>

            <div id="p12Message"></div>
            <div class="metric-grid p12-summary" id="p12Summary"></div>

            <div class="p12-layout">
                <article class="panel p12-form-panel">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">Карточка цели</p>
                            <h2 id="p12FormTitle">Новая цель</h2>
                        </div>
                        <button type="button" class="button" id="p12Reset">Очистить</button>
                    </div>
                    <form id="p12Form" class="settings-form">
                        <input type="hidden" name="id" value="0">
                        <div class="form-grid p12-form-grid">
                            <label>
                                <span>Источник</span>
                                <select name="source_system" required></select>
                            </label>
                            <label>
                                <span>ID или ключ цели</span>
                                <input name="external_id" maxlength="190" required placeholder="Например, 123456789">
                            </label>
                            <label class="p12-name-field">
                                <span>Название</span>
                                <input name="name" maxlength="255" required placeholder="Отправка формы консультации">
                            </label>
                            <label>
                                <span>Класс</span>
                                <select name="classification" required></select>
                            </label>
                            <label class="p12-active-field">
                                <input type="checkbox" name="active" checked>
                                <span>Активная цель</span>
                            </label>
                        </div>
                        <label>
                            <span>Комментарий</span>
                            <textarea name="notes" rows="4" maxlength="5000" placeholder="Почему цель отнесена к этому классу"></textarea>
                        </label>
                        <button type="submit" class="button button-primary">Сохранить</button>
                        <div id="p12FormMessage"></div>
                    </form>
                </article>

                <article class="panel p12-list-panel">
                    <div class="panel-head">
                        <div>
                            <p class="eyebrow">Каталог</p>
                            <h2>Все цели</h2>
                        </div>
                        <span class="muted" id="p12GoalCount"></span>
                    </div>
                    <div class="table-scroll">
                        <table class="data-table p12-table">
                            <thead>
                                <tr>
                                    <th>Цель</th>
                                    <th>Источник</th>
                                    <th>Классификация</th>
                                    <th>Статус</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody id="p12Goals"></tbody>
                        </table>
                    </div>
                </article>
            </div>
        `;

        head.insertAdjacentElement('afterend', tabs);
        tabs.insertAdjacentElement('afterend', transactionsPane);
        transactionsPane.insertAdjacentElement('afterend', goalsPane);

        tabs.addEventListener('click', event => {
            const button = event.target.closest('[data-p1-tab]');
            if (!button) return;
            const target = button.dataset.p1Tab;
            tabs.querySelectorAll('.p1-tab').forEach(tab => {
                tab.classList.toggle('active', tab === button);
            });
            transactionsPane.classList.toggle('active', target === 'transactions');
            goalsPane.classList.toggle('active', target === 'goals');
            if (target === 'goals') {
                loadGoals();
            }
        });
    }

    function fillOptions() {
        const form = qs('#p12Form');
        if (!form || !state.context) return;
        form.elements.source_system.innerHTML = Object.entries(state.context.sources)
            .map(([key, label]) => `<option value="${escapeHtml(key)}">${escapeHtml(label)}</option>`)
            .join('');
        form.elements.classification.innerHTML = Object.entries(state.context.classifications)
            .map(([key, label]) => `<option value="${escapeHtml(key)}">${escapeHtml(label)}</option>`)
            .join('');
    }

    function resetForm() {
        const form = qs('#p12Form');
        if (!form) return;
        form.reset();
        form.elements.id.value = '0';
        form.elements.source_system.value = 'manual';
        form.elements.classification.value = 'unclassified';
        form.elements.active.checked = true;
        qs('#p12FormTitle').textContent = 'Новая цель';
        const message = qs('#p12FormMessage');
        message.className = '';
        message.textContent = '';
    }

    function payloadFromForm() {
        const form = qs('#p12Form');
        const data = new FormData(form);
        return {
            id: Number(data.get('id') || 0),
            source_system: data.get('source_system'),
            external_id: data.get('external_id'),
            name: data.get('name'),
            classification: data.get('classification'),
            active: form.elements.active.checked,
            notes: data.get('notes')
        };
    }

    function renderSummary(counts) {
        const cards = [
            ['Всего', counts.total || 0, 'Каталог целей'],
            ['Лиды', counts.lead || 0, 'Участвуют в CPL'],
            ['Вспомогательные', counts.assisted || 0, 'Отдельный показатель'],
            ['Микроконверсии', counts.micro || 0, 'Поведение пользователя'],
            ['Не классифицированы', counts.unclassified || 0, 'Нужно решение']
        ];
        qs('#p12Summary').innerHTML = cards.map(([title, value, note]) => `
            <article class="metric-card ${title === 'Не классифицированы' && value > 0 ? 'p12-warning-card' : ''}">
                <span>${escapeHtml(title)}</span>
                <strong>${number.format(value)}</strong>
                <small>${escapeHtml(note)}</small>
            </article>
        `).join('');
        const badge = qs('#p1UnclassifiedBadge');
        badge.textContent = counts.unclassified > 0
            ? number.format(counts.unclassified)
            : '';
        badge.classList.toggle('visible', counts.unclassified > 0);
    }

    function classificationOptions(selected) {
        return Object.entries(state.context.classifications)
            .map(([key, label]) => `
                <option value="${escapeHtml(key)}" ${key === selected ? 'selected' : ''}>
                    ${escapeHtml(label)}
                </option>
            `).join('');
    }

    function renderGoals(goals) {
        state.goals = goals;
        qs('#p12GoalCount').textContent = `${number.format(goals.length)} целей`;
        qs('#p12Goals').innerHTML = goals.length
            ? goals.map(goal => `
                <tr data-goal-id="${Number(goal.id)}" class="${goal.active ? '' : 'p12-inactive'}">
                    <td>
                        <strong>${escapeHtml(goal.name)}</strong>
                        <small>${escapeHtml(goal.external_id)}</small>
                    </td>
                    <td>${escapeHtml(goal.source_name)}</td>
                    <td>
                        <select class="p12-inline-classification" data-id="${Number(goal.id)}">
                            ${classificationOptions(goal.classification)}
                        </select>
                    </td>
                    <td>
                        <button type="button" class="p12-active-toggle ${goal.active ? 'active' : ''}" data-id="${Number(goal.id)}">
                            ${goal.active ? 'Активна' : 'Отключена'}
                        </button>
                    </td>
                    <td class="p12-actions">
                        <button type="button" class="button p12-edit" data-id="${Number(goal.id)}">Изменить</button>
                        <button type="button" class="button p12-delete" data-id="${Number(goal.id)}">Удалить</button>
                    </td>
                </tr>
            `).join('')
            : '<tr><td colspan="5" class="muted">Цели ещё не добавлены.</td></tr>';
    }

    async function loadGoals() {
        if (!state.context) return;
        const message = qs('#p12Message');
        message.className = '';
        message.textContent = 'Загрузка...';
        try {
            const result = await request('list');
            renderSummary(result.counts);
            renderGoals(result.goals);
            message.textContent = '';
        } catch (error) {
            message.className = 'alert alert-error';
            message.textContent = error.message;
        }
    }

    function editGoal(id) {
        const goal = state.goals.find(item => Number(item.id) === Number(id));
        const form = qs('#p12Form');
        if (!goal || !form) return;
        form.elements.id.value = goal.id;
        form.elements.source_system.value = goal.source_system;
        form.elements.external_id.value = goal.external_id;
        form.elements.name.value = goal.name;
        form.elements.classification.value = goal.classification;
        form.elements.active.checked = Boolean(goal.active);
        form.elements.notes.value = goal.notes || '';
        qs('#p12FormTitle').textContent = `Редактирование #${goal.id}`;
        form.scrollIntoView({behavior: 'smooth', block: 'start'});
    }

    async function saveGoal(goal, messageNode = qs('#p12Message')) {
        const result = await request('save', {
            method: 'POST',
            body: JSON.stringify(goal)
        });
        messageNode.className = 'alert alert-success';
        messageNode.textContent = result.message;
        await loadGoals();
    }

    async function init() {
        if (window.__p1GoalsInitialized) return;
        window.__p1GoalsInitialized = true;

        try {
            state.context = await request('context');
        } catch (_) {
            return;
        }

        const section = await waitForSection();
        ensureTabs(section);
        fillOptions();
        resetForm();

        qs('#p12Reset').addEventListener('click', resetForm);

        qs('#p12Sync').addEventListener('click', async event => {
            const button = event.currentTarget;
            const message = qs('#p12SyncMessage');
            button.disabled = true;
            message.className = '';
            message.textContent = 'Синхронизация...';
            try {
                const result = await request('sync', {
                    method: 'POST',
                    body: '{}'
                });
                const info = result.result;
                message.className = info.found > 0
                    ? 'alert alert-success'
                    : 'alert alert-warning';
                message.textContent = `${result.message} Найдено: ${info.found}; добавлено: ${info.created}; обновлено: ${info.updated}.`;
                await loadGoals();
            } catch (error) {
                message.className = 'alert alert-error';
                message.textContent = error.message;
            } finally {
                button.disabled = false;
            }
        });

        qs('#p12Form').addEventListener('submit', async event => {
            event.preventDefault();
            const submit = event.currentTarget.querySelector('button[type="submit"]');
            const message = qs('#p12FormMessage');
            submit.disabled = true;
            message.className = '';
            message.textContent = 'Сохранение...';
            try {
                await saveGoal(payloadFromForm(), message);
                resetForm();
            } catch (error) {
                message.className = 'alert alert-error';
                message.textContent = error.message;
            } finally {
                submit.disabled = false;
            }
        });

        qs('#p12Goals').addEventListener('change', async event => {
            const select = event.target.closest('.p12-inline-classification');
            if (!select) return;
            const goal = state.goals.find(item => Number(item.id) === Number(select.dataset.id));
            if (!goal) return;
            select.disabled = true;
            try {
                await saveGoal({
                    ...goal,
                    classification: select.value,
                    active: Boolean(goal.active)
                });
            } catch (error) {
                const message = qs('#p12Message');
                message.className = 'alert alert-error';
                message.textContent = error.message;
                select.value = goal.classification;
            } finally {
                select.disabled = false;
            }
        });

        qs('#p12Goals').addEventListener('click', async event => {
            const edit = event.target.closest('.p12-edit');
            if (edit) {
                editGoal(edit.dataset.id);
                return;
            }

            const toggle = event.target.closest('.p12-active-toggle');
            if (toggle) {
                const goal = state.goals.find(item => Number(item.id) === Number(toggle.dataset.id));
                if (!goal) return;
                toggle.disabled = true;
                try {
                    await saveGoal({...goal, active: !Boolean(goal.active)});
                } catch (error) {
                    const message = qs('#p12Message');
                    message.className = 'alert alert-error';
                    message.textContent = error.message;
                } finally {
                    toggle.disabled = false;
                }
                return;
            }

            const remove = event.target.closest('.p12-delete');
            if (!remove || !confirm('Удалить цель и её классификацию?')) return;
            try {
                await request('delete', {
                    method: 'POST',
                    body: JSON.stringify({id: Number(remove.dataset.id)})
                });
                await loadGoals();
            } catch (error) {
                const message = qs('#p12Message');
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
