
/* PORTAL_UI_HOTFIX_V180320 */
(() => {
    'use strict';

    const STYLE_ID = 'portalUiHotfixV20Styles';
    const CSS = `
.lk2-client-structure .btn,
.b19-modal .btn,
.lk2-client-structure button[data-lk2-action]:not(.lk2-client-card):not(.lk2-icon-button):not(.lk2-modal-close) {
    appearance: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 38px;
    padding: 8px 14px;
    border: 1px solid #d0d7e2;
    border-radius: 10px;
    background: #fff;
    color: #344054;
    font: inherit;
    font-size: 13px;
    font-weight: 650;
    line-height: 1.2;
    text-decoration: none;
    cursor: pointer;
    transition: border-color .16s ease, background .16s ease, color .16s ease, box-shadow .16s ease;
}
.lk2-client-structure .btn:hover,
.b19-modal .btn:hover,
.lk2-client-structure button[data-lk2-action]:not(.lk2-client-card):not(.lk2-icon-button):not(.lk2-modal-close):hover {
    border-color: #98a2b3;
    background: #f8fafc;
}
.lk2-client-structure .btn-primary,
.b19-modal .btn-primary,
.lk2-client-structure button[data-lk2-action="new-client"],
.lk2-client-structure button[data-lk2-action="new-project"] {
    border-color: #2563eb !important;
    background: #2563eb !important;
    color: #fff !important;
    box-shadow: 0 4px 12px rgba(37, 99, 235, .18);
}
.lk2-client-structure .btn-primary:hover,
.b19-modal .btn-primary:hover,
.lk2-client-structure button[data-lk2-action="new-client"]:hover,
.lk2-client-structure button[data-lk2-action="new-project"]:hover {
    border-color: #1d4ed8 !important;
    background: #1d4ed8 !important;
}
.lk2-client-structure .btn-secondary,
.b19-modal .btn-secondary {
    border-color: #d0d7e2 !important;
    background: #fff !important;
    color: #344054 !important;
}
.lk2-client-structure .btn-danger-soft {
    border-color: #f2c6c1 !important;
    background: #fff4f2 !important;
    color: #b42318 !important;
}
.lk2-client-structure button:disabled,
.b19-modal button:disabled {
    opacity: .55;
    cursor: not-allowed;
}
.lk2-modal-backdrop,
.b19-backdrop {
    overscroll-behavior: contain;
}
.lk2-modal,
.b19-modal {
    isolation: isolate;
}
`;

    function ensureStyles() {
        if (document.getElementById(STYLE_ID)) return;
        const style = document.createElement('style');
        style.id = STYLE_ID;
        style.textContent = CSS;
        (document.head || document.documentElement).appendChild(style);
    }

    function guardLk2Modal(modal) {
        if (!(modal instanceof Element) || modal.dataset.uiHotfixV20 === '1') return;
        modal.dataset.uiHotfixV20 = '1';
        modal.removeAttribute('onclick');
        modal.addEventListener('click', event => {
            const closeControl = event.target.closest('[data-lk2-action="close-modal"]');
            if (!closeControl) {
                event.stopPropagation();
            }
        });
    }

    function guardB19Modal(modal) {
        if (!(modal instanceof Element) || modal.dataset.uiHotfixV20 === '1') return;
        modal.dataset.uiHotfixV20 = '1';
        modal.addEventListener('click', event => {
            if (!event.target.closest('[data-b19-action="close"]')) {
                event.stopPropagation();
            }
        });
    }

    function scan(root = document) {
        ensureStyles();
        if (root instanceof Element && root.matches('.lk2-modal')) {
            guardLk2Modal(root);
        }
        if (root instanceof Element && root.matches('.b19-modal')) {
            guardB19Modal(root);
        }
        root.querySelectorAll?.('.lk2-modal').forEach(guardLk2Modal);
        root.querySelectorAll?.('.b19-modal').forEach(guardB19Modal);
    }

    function boot() {
        scan(document);
        const observer = new MutationObserver(records => {
            records.forEach(record => {
                record.addedNodes.forEach(node => {
                    if (node instanceof Element) scan(node);
                });
            });
        });
        observer.observe(document.body, {childList: true, subtree: true});
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot, {once: true});
    } else {
        boot();
    }
})();
