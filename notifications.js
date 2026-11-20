(() => {
    const styles = `
        .app-toast-region {
            position: fixed; top: 20px; right: 20px; z-index: 1000;
            display: grid; gap: 10px; width: min(390px, calc(100vw - 32px));
            pointer-events: none;
        }
        .app-toast {
            --notice-color: #0284c7;
            display: grid; grid-template-columns: minmax(0, 1fr) auto; gap: 4px 12px;
            padding: 14px 14px 14px 16px; border: 1px solid #e2e8f0;
            border-left: 4px solid var(--notice-color); border-radius: 10px;
            background: #fff; color: #334155; box-shadow: 0 12px 32px rgba(15, 23, 42, .16);
            pointer-events: auto; animation: app-toast-in .18s ease-out;
        }
        .app-toast[data-type="success"] { --notice-color: #059669; }
        .app-toast[data-type="error"] { --notice-color: #dc2626; }
        .app-toast[data-type="warning"] { --notice-color: #d97706; }
        .app-toast[data-type="info"] { --notice-color: #0284c7; }
        .app-toast-label {
            margin: 0; color: var(--notice-color); font-size: 11px;
            font-weight: 700; text-transform: uppercase;
        }
        .app-toast-message { grid-column: 1; margin: 0; font-size: 14px; line-height: 1.45; }
        .app-toast-close {
            grid-column: 2; grid-row: 1 / span 2; align-self: start;
            width: 28px; height: 28px; border: 0; border-radius: 6px;
            background: transparent; color: #64748b; font-size: 20px; line-height: 1; cursor: pointer;
        }
        .app-toast-close:hover { background: #f1f5f9; color: #0f172a; }
        .app-confirm-backdrop {
            position: fixed; inset: 0; z-index: 1100; display: grid; place-items: center;
            padding: 20px; background: rgba(15, 23, 42, .48); backdrop-filter: blur(3px);
        }
        .app-confirm-backdrop[hidden] { display: none; }
        .app-confirm-dialog {
            width: min(440px, 100%); overflow: hidden; border: 1px solid #e2e8f0;
            border-radius: 12px; background: #fff; box-shadow: 0 24px 70px rgba(15, 23, 42, .24);
            animation: app-dialog-in .16s ease-out;
        }
        .app-confirm-content { padding: 24px 24px 20px; }
        .app-confirm-eyebrow { margin: 0 0 7px; color: #0284c7; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .app-confirm-title { margin: 0; color: #0f172a; font-size: 19px; font-weight: 700; }
        .app-confirm-message { margin: 10px 0 0; color: #475569; font-size: 14px; line-height: 1.55; }
        .app-confirm-actions {
            display: flex; justify-content: flex-end; gap: 8px; padding: 14px 24px;
            border-top: 1px solid #e2e8f0; background: #f8fafc;
        }
        .app-confirm-button {
            min-height: 38px; padding: 8px 14px; border: 1px solid transparent;
            border-radius: 7px; font: inherit; font-size: 13px; font-weight: 600; cursor: pointer;
        }
        .app-confirm-cancel { border-color: #cbd5e1; background: #fff; color: #334155; }
        .app-confirm-cancel:hover { background: #f1f5f9; }
        .app-confirm-accept { background: #0284c7; color: #fff; }
        .app-confirm-accept:hover { background: #0369a1; }
        .app-confirm-accept[data-danger="true"] { background: #dc2626; }
        .app-confirm-accept[data-danger="true"]:hover { background: #b91c1c; }
        @keyframes app-toast-in { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
        @keyframes app-dialog-in { from { opacity: 0; transform: translateY(5px) scale(.99); } to { opacity: 1; transform: translateY(0) scale(1); } }
        @media (max-width: 520px) {
            .app-toast-region { top: 12px; right: 12px; width: calc(100vw - 24px); }
            .app-confirm-content { padding: 20px 18px 16px; }
            .app-confirm-actions { padding: 12px 18px; }
        }
        @media (prefers-reduced-motion: reduce) {
            .app-toast, .app-confirm-dialog { animation: none; }
        }
    `;

    let toastRegion;
    let confirmBackdrop;
    let confirmResolver;
    let previouslyFocused;

    function ensureStyles() {
        if (document.getElementById('app-notification-styles')) return;
        const style = document.createElement('style');
        style.id = 'app-notification-styles';
        style.textContent = styles;
        document.head.appendChild(style);
    }

    function ensureToastRegion() {
        if (toastRegion) return toastRegion;
        toastRegion = document.createElement('div');
        toastRegion.className = 'app-toast-region';
        toastRegion.setAttribute('aria-live', 'polite');
        toastRegion.setAttribute('aria-relevant', 'additions');
        document.body.appendChild(toastRegion);
        return toastRegion;
    }

    function ensureConfirmDialog() {
        if (confirmBackdrop) return confirmBackdrop;
        confirmBackdrop = document.createElement('div');
        confirmBackdrop.className = 'app-confirm-backdrop';
        confirmBackdrop.hidden = true;
        confirmBackdrop.innerHTML = `
            <section class="app-confirm-dialog" role="alertdialog" aria-modal="true" aria-labelledby="app-confirm-title" aria-describedby="app-confirm-message">
                <div class="app-confirm-content">
                    <p class="app-confirm-eyebrow">Confirmation requise</p>
                    <h2 class="app-confirm-title" id="app-confirm-title">Confirmer l’action</h2>
                    <p class="app-confirm-message" id="app-confirm-message"></p>
                </div>
                <div class="app-confirm-actions">
                    <button type="button" class="app-confirm-button app-confirm-cancel">Annuler</button>
                    <button type="button" class="app-confirm-button app-confirm-accept">Confirmer</button>
                </div>
            </section>`;
        document.body.appendChild(confirmBackdrop);

        const cancelButton = confirmBackdrop.querySelector('.app-confirm-cancel');
        const acceptButton = confirmBackdrop.querySelector('.app-confirm-accept');
        cancelButton.addEventListener('click', () => finishConfirmation(false));
        acceptButton.addEventListener('click', () => finishConfirmation(true));
        confirmBackdrop.addEventListener('click', event => {
            if (event.target === confirmBackdrop) finishConfirmation(false);
        });
        document.addEventListener('keydown', event => {
            if (confirmBackdrop.hidden) return;
            if (event.key === 'Escape') finishConfirmation(false);
            if (event.key === 'Tab') {
                event.preventDefault();
                (document.activeElement === cancelButton ? acceptButton : cancelButton).focus();
            }
        });
        return confirmBackdrop;
    }

    function finishConfirmation(confirmed) {
        if (!confirmBackdrop || confirmBackdrop.hidden) return;
        confirmBackdrop.hidden = true;
        if (confirmResolver) confirmResolver(confirmed);
        confirmResolver = null;
        if (previouslyFocused && previouslyFocused.isConnected) previouslyFocused.focus();
    }

    window.appNotify = (message, type = 'success', duration = 4200) => {
        const region = ensureToastRegion();
        const labels = { success: 'Succès', error: 'Erreur', warning: 'Attention', info: 'Information' };
        const safeType = Object.hasOwn(labels, type) ? type : 'info';
        const toast = document.createElement('div');
        toast.className = 'app-toast';
        toast.dataset.type = safeType;
        toast.setAttribute('role', safeType === 'error' ? 'alert' : 'status');

        const label = document.createElement('p');
        label.className = 'app-toast-label';
        label.textContent = labels[safeType];
        const content = document.createElement('p');
        content.className = 'app-toast-message';
        content.textContent = String(message ?? '');
        const close = document.createElement('button');
        close.className = 'app-toast-close';
        close.type = 'button';
        close.setAttribute('aria-label', 'Fermer la notification');
        close.textContent = '\u00d7';
        close.addEventListener('click', () => toast.remove());
        toast.append(label, content, close);
        region.appendChild(toast);

        if (duration > 0) window.setTimeout(() => toast.remove(), duration);
    };

    window.appConfirm = (message, options = {}) => {
        ensureStyles();
        const backdrop = ensureConfirmDialog();
        if (confirmResolver) finishConfirmation(false);
        previouslyFocused = document.activeElement;
        backdrop.querySelector('#app-confirm-title').textContent = options.title || 'Confirmer l’action';
        backdrop.querySelector('#app-confirm-message').textContent = String(message ?? '');
        const acceptButton = backdrop.querySelector('.app-confirm-accept');
        acceptButton.textContent = options.confirmLabel || 'Confirmer';
        acceptButton.dataset.danger = options.danger ? 'true' : 'false';
        backdrop.hidden = false;
        backdrop.querySelector('.app-confirm-cancel').focus();
        return new Promise(resolve => { confirmResolver = resolve; });
    };

    document.addEventListener('submit', event => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.dataset.appConfirm) return;
        if (form.dataset.appConfirmPassed === 'true') {
            delete form.dataset.appConfirmPassed;
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter;
        window.appConfirm(form.dataset.appConfirm, {
            title: form.dataset.appConfirmTitle || 'Confirmer la suppression',
            confirmLabel: form.dataset.appConfirmLabel || 'Supprimer',
            danger: form.dataset.appConfirmDanger !== 'false'
        }).then(confirmed => {
            if (!confirmed) return;
            form.dataset.appConfirmPassed = 'true';
            if (submitter && submitter.form === form) form.requestSubmit(submitter);
            else form.requestSubmit();
        });
    }, true);

    document.addEventListener('click', event => {
        const link = event.target.closest('a[data-app-confirm]');
        if (!link) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        window.appConfirm(link.dataset.appConfirm, {
            title: link.dataset.appConfirmTitle || 'Confirmer la déconnexion',
            confirmLabel: link.dataset.appConfirmLabel || 'Continuer',
            danger: link.dataset.appConfirmDanger === 'true'
        }).then(confirmed => {
            if (confirmed) window.location.assign(link.href);
        });
    }, true);

    function promoteServerMessages() {
        document.querySelectorAll('main .mb-5.p-4.rounded-xl.border').forEach(message => {
            const type = message.classList.contains('bg-green-50') ? 'success'
                : message.classList.contains('bg-red-50') ? 'error'
                : null;
            if (!type) return;
            const text = message.textContent.trim();
            message.remove();
            if (text) window.appNotify(text, type);
        });
    }

    function initialize() {
        ensureStyles();
        promoteServerMessages();
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
