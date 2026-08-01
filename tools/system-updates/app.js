    /* SYSTEM_UPDATES_MODULE_JS */
    let systemUpdatesLoaded = false;
    let systemUpdatesPolling = null;
    let systemUpdatesState = null;

    const systemUpdateStatusLabels = {
        queued: 'В очереди',
        installing: 'Устанавливается',
        installed: 'Установлено',
        failed: 'Ошибка установки',
        rollback_queued: 'Откат в очереди',
        rolling_back: 'Выполняется откат',
        rolled_back: 'Откат выполнен',
        rollback_failed: 'Ошибка отката'
    };

    function systemUpdatesDate(value) {
        if (!value) return '—';
        const parsed = new Date(String(value).replace(' ', 'T'));
        return Number.isNaN(parsed.getTime())
            ? String(value)
            : parsed.toLocaleString('ru-RU', {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            });
    }

    function systemUpdatesShowMessage(type, text) {
        const root = $('#systemUpdatesMessage');
        if (!root) return;
        root.className = type ? `alert alert-${type}` : '';
        root.textContent = text || '';
    }

    function systemUpdatesRenderRelease(state) {
        const current = $('#systemUpdatesCurrentVersion');
        const latest = $('#systemUpdatesLatestVersion');
        const badge = $('#systemUpdatesBadge');
        const releaseRoot = $('#systemUpdatesRelease');
        const install = $('#systemUpdatesInstall');
        const release = state.latest || null;

        if (current) current.textContent = state.current_version || '—';
        if (latest) latest.textContent = release?.version || state.current_version || '—';

        if (state.error) {
            if (badge) {
                badge.className = 'updates-badge negative';
                badge.textContent = 'Ошибка проверки';
            }
            if (releaseRoot) {
                releaseRoot.innerHTML = `<strong>Не удалось проверить обновления</strong><span>${escapeHtml(state.error)}</span>`;
            }
            install?.classList.add('hidden');
            return;
        }

        if (!release) {
            if (badge) {
                badge.className = 'updates-badge neutral';
                badge.textContent = 'Нет данных';
            }
            if (releaseRoot) releaseRoot.textContent = 'Сервер обновлений не вернул описание версии.';
            install?.classList.add('hidden');
            return;
        }

        if (state.update_available) {
            if (badge) {
                badge.className = 'updates-badge warning';
                badge.textContent = 'Есть обновление';
            }
            install?.classList.remove('hidden');
        } else {
            if (badge) {
                badge.className = 'updates-badge positive';
                badge.textContent = 'Актуальная версия';
            }
            install?.classList.add('hidden');
        }

        const changes = Array.isArray(release.changelog)
            ? release.changelog
            : [];
        if (releaseRoot) {
            releaseRoot.innerHTML = `
                <strong>${escapeHtml(release.title || `Версия ${release.version}`)}</strong>
                <span>${escapeHtml(release.released_at ? `Опубликовано: ${release.released_at}` : '')}</span>
                ${changes.length ? `<ul>${changes.map(item => `<li>${escapeHtml(item)}</li>`).join('')}</ul>` : '<span>Описание изменений не указано.</span>'}
            `;
        }
    }

    function systemUpdatesRenderHistory(rows) {
        const root = $('#systemUpdatesHistory');
        if (!root) return;

        if (!rows.length) {
            root.innerHTML = '<div class="updates-empty"><strong>Обновлений пока не было</strong><span>Новые операции появятся здесь.</span></div>';
            return;
        }

        root.innerHTML = rows.map(row => {
            const canRollback = row.status === 'installed' && row.backup_path;
            const log = String(row.log_text || '').trim();
            return `
                <article class="updates-history-item updates-status-${escapeHtml(row.status)}">
                    <div class="updates-history-head">
                        <div>
                            <strong>${escapeHtml(row.title || row.version)}</strong>
                            <span>Версия ${escapeHtml(row.version)} · ${escapeHtml(systemUpdatesDate(row.created_at))}</span>
                        </div>
                        <em>${escapeHtml(systemUpdateStatusLabels[row.status] || row.status)}</em>
                    </div>
                    ${row.backup_path ? `<div class="updates-backup">Резервная копия: <code>${escapeHtml(row.backup_path)}</code></div>` : ''}
                    ${log ? `<details class="updates-log"><summary>Журнал операции</summary><pre>${escapeHtml(log)}</pre></details>` : ''}
                    ${canRollback ? `<button type="button" class="button" data-system-update-rollback="${row.id}">Откатить это обновление</button>` : ''}
                </article>`;
        }).join('');
    }

    function systemUpdatesHasActiveJob(rows) {
        return rows.some(row => [
            'queued',
            'installing',
            'rollback_queued',
            'rolling_back'
        ].includes(row.status));
    }

    function systemUpdatesManagePolling(rows) {
        const active = systemUpdatesHasActiveJob(rows);

        if (active && !systemUpdatesPolling) {
            systemUpdatesPolling = setInterval(
                () => systemUpdatesLoad(true),
                5000
            );
        } else if (!active && systemUpdatesPolling) {
            clearInterval(systemUpdatesPolling);
            systemUpdatesPolling = null;
        }
    }

    async function systemUpdatesLoad(force = false) {
        if (!$('#systemUpdatesHistory')) return;
        if (systemUpdatesLoaded && !force) return;

        try {
            const state = await api('/api.php?action=system_updates_status');
            systemUpdatesState = state;
            systemUpdatesRenderRelease(state);
            systemUpdatesRenderHistory(state.history || []);
            systemUpdatesManagePolling(state.history || []);
            systemUpdatesLoaded = true;
        } catch (error) {
            systemUpdatesShowMessage('error', error.message);
        }
    }

    $('.nav-link[data-section="system-updates"]')?.addEventListener(
        'click',
        () => systemUpdatesLoad(true)
    );

    $('#systemUpdatesRefresh')?.addEventListener('click', async event => {
        const button = event.currentTarget;
        button.disabled = true;
        systemUpdatesShowMessage('', 'Проверяем сервер обновлений…');
        try {
            systemUpdatesLoaded = false;
            await systemUpdatesLoad(true);
            systemUpdatesShowMessage('success', 'Проверка обновлений завершена.');
        } finally {
            button.disabled = false;
        }
    });

    $('#systemUpdatesInstall')?.addEventListener('click', async event => {
        const release = systemUpdatesState?.latest;
        if (!release) return;

        if (!confirm(`Установить обновление ${release.version}? Перед установкой будет создана полная резервная копия.`)) {
            return;
        }

        const button = event.currentTarget;
        button.disabled = true;
        systemUpdatesShowMessage('', 'Ставим обновление в очередь…');

        try {
            const result = await api('/api.php?action=system_update_install', {
                method: 'POST',
                body: JSON.stringify({csrf_token: csrf})
            });
            systemUpdatesShowMessage('success', result.message || 'Обновление поставлено в очередь.');
            await systemUpdatesLoad(true);
        } catch (error) {
            systemUpdatesShowMessage('error', error.message);
        } finally {
            button.disabled = false;
        }
    });

    $('#systemUpdatesHistory')?.addEventListener('click', async event => {
        const button = event.target.closest('[data-system-update-rollback]');
        if (!button) return;
        const updateId = Number(button.dataset.systemUpdateRollback || 0);

        if (!confirm('Откатить систему к состоянию до этого обновления? Текущее состояние также будет сохранено.')) {
            return;
        }

        button.disabled = true;
        systemUpdatesShowMessage('', 'Ставим откат в очередь…');

        try {
            const result = await api('/api.php?action=system_update_rollback', {
                method: 'POST',
                body: JSON.stringify({
                    csrf_token: csrf,
                    update_id: updateId
                })
            });
            systemUpdatesShowMessage('success', result.message || 'Откат поставлен в очередь.');
            await systemUpdatesLoad(true);
        } catch (error) {
            systemUpdatesShowMessage('error', error.message);
        } finally {
            button.disabled = false;
        }
    });
