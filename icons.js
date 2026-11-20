(() => {
    if (!window.lucide) return;

    const menuIcons = new Map([
        ['tableau de bord', 'layout-dashboard'],
        ['milieux / destinations', 'map-pinned'],
        ['tarifs', 'tags'],
        ['voyages', 'route'],
        ['voyageurs', 'users-round'],
        ['billets', 'ticket'],
        ['chauffeurs', 'user-round'],
        ['vehicules', 'bus'],
        ['expediteurs', 'send'],
        ['colis', 'package'],
        ['suivi des colis', 'package-search'],
        ['caisse', 'wallet'],
        ['depenses', 'receipt'],
        ['rapports', 'chart-column'],
        ['agences', 'building-2'],
        ['utilisateurs', 'users-round'],
        ['parametres', 'settings'],
        ['statistiques', 'chart-pie']
    ]);

    const actionIcons = [
        [/^supprimer/, 'trash-2'],
        [/^modifier/, 'pencil'],
        [/^imprimer/, 'printer'],
        [/^partager/, 'share-2'],
        [/^rechercher|^recherche|^ok$/, 'search'],
        [/^emettre/, 'ticket'],
        [/^enregistrer/, 'save'],
        [/^ajouter|^creer|^nouveau|^nouvelle/, 'plus'],
        [/^annuler/, 'circle-x'],
        [/^retour|^precedent/, 'arrow-left'],
        [/^deconnexion|^se deconnecter/, 'log-out'],
        [/^connexion|^se connecter/, 'log-in'],
        [/^envoyer|whatsapp/, 'message-circle'],
        [/^recu/, 'receipt'],
        [/^afficher/, 'eye'],
        [/^masquer/, 'eye-off'],
        [/^calculer/, 'calculator'],
        [/^generer/, 'wand-sparkles'],
        [/^filtrer/, 'list-filter'],
        [/^telecharger|^exporter/, 'download'],
        [/^importer/, 'upload'],
        [/^voir|^consulter/, 'eye'],
        [/^valider/, 'circle-check'],
        [/^confirmer|^continuer/, 'check'],
        [/^fermer|^×$/, 'x']
    ];

    const controlSelector = 'button, a.menu-link, a.btn, a[class*="bg-"], a.text-sky-600, a.text-sky-700';

    const iconStyle = document.createElement('style');
    iconStyle.textContent = '.app-action-icon{display:inline-block;width:16px;height:16px;margin-right:7px;flex-shrink:0;vertical-align:middle}';
    document.head.appendChild(iconStyle);

    function normalize(text) {
        return text.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().replace(/\s+/g, ' ').trim();
    }

    function iconFor(control) {
        const label = normalize(control.textContent || '');
        if (control.matches('.menu-link')) return menuIcons.get(label) || 'circle-dot';

        for (const [pattern, icon] of actionIcons) {
            if (pattern.test(label)) return icon;
        }

        if (control.matches('button[type="submit"]')) return 'circle-check';
        if (control.matches('a.btn, a[class*="bg-"]')) return 'arrow-right';
        return null;
    }

    function decorate(control) {
        if (control.querySelector(':scope > [data-lucide], :scope > svg.lucide')) return;
        const iconName = iconFor(control);
        if (!iconName) return;

        const icon = document.createElement('i');
        icon.setAttribute('data-lucide', iconName);
        icon.setAttribute('aria-hidden', 'true');
        icon.className = 'app-action-icon';
        control.insertBefore(icon, control.firstChild);
    }

    function decorateControls(root = document) {
        const controls = [];
        if (root instanceof Element && root.matches(controlSelector)) controls.push(root);
        controls.push(...root.querySelectorAll(controlSelector));
        if (!controls.length) return;

        controls.forEach(decorate);
        lucide.createIcons();
    }

    function initialize() {
        decorateControls();
        new MutationObserver(records => {
            records.forEach(record => record.addedNodes.forEach(node => {
                if (node instanceof Element) decorateControls(node);
            }));
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
    else initialize();
})();
