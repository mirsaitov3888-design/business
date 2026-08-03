
/* PORTAL_NAVIGATION_STATE_V180322 */
(() => {
    'use strict';

    const STORAGE_KEY = 'seoAnalytics.activeSection.v22';
    const REQUIRED_SECTIONS = [
        {
            section: 'p1-sales',
            label: 'Продажи и экономика',
            after: 'reports'
        }
    ];
    const registry = new Map();
    let sequence = 0;
    let scheduled = false;
    let restoring = false;

    const qsa = (selector, root = document) =>
        Array.from(root.querySelectorAll(selector));

    function navigationRoots() {
        return qsa('.sidebar-nav, .sidebar-menu, .nav-menu, aside nav, .sidebar')
            .filter((node, index, rows) =>
                rows.indexOf(node) === index
                && node.querySelector('[data-section]')
            )
            .sort((a, b) =>
                b.querySelectorAll('[data-section]').length
                - a.querySelectorAll('[data-section]').length
            );
    }

    function navigationRoot() {
        return navigationRoots()[0] || null;
    }

    function isNavigationItem(node, root = navigationRoot()) {
        return node instanceof Element
            && root instanceof Element
            && root.contains(node)
            && node.matches('[data-section]');
    }

    function labelOf(node) {
        const textNode = node.querySelector('.nav-text, [data-nav-text], span:last-child');
        return String((textNode || node).textContent || '').trim();
    }

    function setLabel(node, label) {
        const textNode = node.querySelector('.nav-text, [data-nav-text], span:last-child');
        if (textNode) {
            textNode.textContent = label;
        } else {
            node.textContent = label;
        }
    }

    function capture(node) {
        const root = navigationRoot();
        if (!isNavigationItem(node, root)) return;
        const section = String(node.dataset.section || '').trim();
        if (!section || registry.has(section)) return;
        registry.set(section, {
            section,
            label: labelOf(node),
            node,
            order: sequence++
        });
    }

    function captureCurrentMenu() {
        const root = navigationRoot();
        if (!root) return;
        qsa('[data-section]', root).forEach(capture);
    }

    function cloneFallback(spec, root) {
        const reference = root.querySelector(
            `[data-section="${CSS.escape(spec.after || 'reports')}"]`
        );
        const template = reference || root.querySelector('[data-section]');
        const node = template
            ? template.cloneNode(true)
            : document.createElement('button');
        node.removeAttribute('id');
        node.type = 'button';
        node.classList.add('nav-link');
        node.dataset.section = spec.section;
        setLabel(node, spec.label);
        node.addEventListener('click', () => {
            activateSection(spec.section, {remember: true, fromClick: true});
        });
        return node;
    }

    function ensureRequiredSections() {
        const root = navigationRoot();
        if (!root) return;
        REQUIRED_SECTIONS.forEach(spec => {
            if (registry.has(spec.section)) return;
            const existing = root.querySelector(
                `[data-section="${CSS.escape(spec.section)}"]`
            );
            const node = existing || cloneFallback(spec, root);
            registry.set(spec.section, {
                section: spec.section,
                label: spec.label,
                node,
                order: sequence++
            });
        });
    }

    function insertByOrder(entry, root) {
        const rows = Array.from(registry.values())
            .sort((a, b) => a.order - b.order);
        const position = rows.findIndex(row => row.section === entry.section);
        let previous = null;
        for (let index = position - 1; index >= 0; index -= 1) {
            const candidate = rows[index].node;
            if (candidate instanceof Element && candidate.isConnected) {
                previous = candidate;
                break;
            }
        }
        if (previous && previous.parentElement === root) {
            previous.insertAdjacentElement('afterend', entry.node);
        } else {
            root.append(entry.node);
        }
    }

    function normalizeMenu() {
        const root = navigationRoot();
        if (!root) return;
        captureCurrentMenu();
        ensureRequiredSections();

        registry.forEach(entry => {
            const matches = qsa(
                `[data-section="${CSS.escape(entry.section)}"]`,
                root
            );
            if (!entry.node.isConnected) {
                if (matches.length) {
                    const replacement = matches[0];
                    if (entry.section === 'p1-sales') {
                        replacement.replaceWith(entry.node);
                    } else {
                        entry.node = replacement;
                    }
                } else {
                    insertByOrder(entry, root);
                }
            }

            qsa(`[data-section="${CSS.escape(entry.section)}"]`, root)
                .forEach(node => {
                    if (node !== entry.node) node.remove();
                });

            if (entry.section === 'p1-sales') {
                setLabel(entry.node, 'Продажи и экономика');
            }
        });
    }

    function validSection(section) {
        return typeof section === 'string'
            && /^[a-z0-9_-]+$/i.test(section)
            && registry.has(section);
    }

    function sectionFromHash() {
        const value = decodeURIComponent(location.hash.replace(/^#/, '')).trim();
        return validSection(value) ? value : '';
    }

    function sectionFromStorage() {
        try {
            const value = String(localStorage.getItem(STORAGE_KEY) || '').trim();
            return validSection(value) ? value : '';
        } catch (_) {
            return '';
        }
    }

    function activeSectionFromDom() {
        const root = navigationRoot();
        const activeButton = root?.querySelector('[data-section].active');
        if (activeButton?.dataset.section) {
            return String(activeButton.dataset.section);
        }
        const activeSection = document.querySelector('.section.active[id^="section-"]');
        return activeSection
            ? String(activeSection.id).replace(/^section-/, '')
            : '';
    }

    function rememberSection(section) {
        if (!validSection(section)) return;
        try {
            localStorage.setItem(STORAGE_KEY, section);
        } catch (_) {
        }
        const hash = `#${encodeURIComponent(section)}`;
        if (location.hash !== hash) {
            history.replaceState(history.state, '', hash);
        }
    }

    function activateSection(section, options = {}) {
        normalizeMenu();
        if (!validSection(section)) return false;
        const entry = registry.get(section);
        const button = entry?.node;
        if (!(button instanceof Element)) return false;

        if (options.remember !== false) rememberSection(section);

        if (!options.fromClick) {
            restoring = true;
            try {
                button.click();
            } finally {
                restoring = false;
            }
        }

        const target = document.getElementById(`section-${section}`);
        if (!target?.classList.contains('active')) {
            try {
                if (typeof window.showSection === 'function') {
                    window.showSection(section);
                }
            } catch (_) {
            }
        }
        return true;
    }

    function restoreSection() {
        normalizeMenu();
        const desired = sectionFromHash() || sectionFromStorage();
        if (!desired) return;
        let attempts = 0;
        const run = () => {
            attempts += 1;
            normalizeMenu();
            if (activateSection(desired, {remember: true}) || attempts >= 20) {
                return;
            }
            setTimeout(run, 50);
        };
        setTimeout(run, 0);
    }

    function updateBitrixProjectCount(root = document) {
        const form = root.querySelector?.('#b19OnboardingForm')
            || document.querySelector('#b19OnboardingForm');
        if (!form) return;
        const heading = qsa('.b19-section-heading h4', form)
            .find(node => /^Проекты Bitrix24(?:\s*\(\d+\))?$/.test(
                String(node.textContent || '').trim()
            ));
        if (!heading) return;
        const selected = form.querySelectorAll(
            'input[name="project_ids[]"]:checked'
        ).length;
        heading.textContent = `Проекты Bitrix24 (${selected})`;
    }

    function scheduleNormalize() {
        if (scheduled) return;
        scheduled = true;
        requestAnimationFrame(() => {
            scheduled = false;
            normalizeMenu();
            updateBitrixProjectCount();
        });
    }

    function bindEvents() {
        document.addEventListener('click', event => {
            const root = navigationRoot();
            const button = event.target.closest?.('[data-section]');
            if (!isNavigationItem(button, root)) return;
            const section = String(button.dataset.section || '');
            rememberSection(section);
            if (!restoring && section === 'p1-sales' && !button.isConnected) {
                normalizeMenu();
            }
        }, true);

        document.addEventListener('change', event => {
            if (event.target.matches?.('input[name="project_ids[]"]')) {
                updateBitrixProjectCount();
            }
        }, true);

        window.addEventListener('beforeunload', () => {
            const section = activeSectionFromDom();
            if (validSection(section)) rememberSection(section);
        });

        window.addEventListener('hashchange', () => {
            const section = sectionFromHash();
            if (section) activateSection(section, {remember: true});
        });
    }

    function boot() {
        captureCurrentMenu();
        ensureRequiredSections();
        normalizeMenu();
        bindEvents();
        updateBitrixProjectCount();

        const observer = new MutationObserver(records => {
            records.forEach(record => {
                record.addedNodes.forEach(node => {
                    if (node instanceof Element) {
                        if (node.matches('[data-section]')) capture(node);
                        node.querySelectorAll?.('[data-section]').forEach(capture);
                        updateBitrixProjectCount(node);
                    }
                });
            });
            scheduleNormalize();
        });
        observer.observe(document.body, {childList: true, subtree: true});

        restoreSection();
    }

    window.PortalNavigation = {
        activate: section => activateSection(section, {remember: true}),
        current: activeSectionFromDom,
        reloadCurrent: () => {
            const section = activeSectionFromDom();
            if (validSection(section)) rememberSection(section);
            location.reload();
        },
        normalize: normalizeMenu
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
