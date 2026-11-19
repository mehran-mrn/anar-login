(function () {
    'use strict';
    const root = document.querySelector('.wrap.anar-admin');
    if (!root) return;
    const route = new URL(window.location.href);
    const recoveryButtons = [...root.querySelectorAll('[data-recovery-view]')];
    function recoveryView(name) {
        if (!recoveryButtons.length) return;
        if (!['records','schedule','discounts'].includes(name)) name = 'records';
        recoveryButtons.forEach(button => {
            button.classList.toggle('is-active', button.dataset.recoveryView === name);
            button.setAttribute('aria-pressed', String(button.dataset.recoveryView === name));
        });
        const records = root.querySelector('[data-suite-panel="carts"]');
        const settings = root.querySelector('.anar-settings-form');
        if (records) records.hidden = name !== 'records';
        if (settings) {
            settings.hidden = name === 'records';
            settings.querySelectorAll('#anar-store-recovery, #anar-store-discounts').forEach(panel => {
                panel.hidden = name === 'discounts' ? panel.id !== 'anar-store-discounts' : panel.id !== 'anar-store-recovery';
            });
        }
        const address = new URL(window.location.href); address.searchParams.set('view', name);
        window.history.replaceState(window.history.state, '', address);
    }
    recoveryButtons.forEach(button => button.addEventListener('click', () => recoveryView(button.dataset.recoveryView)));
    recoveryView(route.searchParams.get('view') || 'records');
    let navigating = false;
    document.addEventListener('click', async function (event) {
        const link = event.target.closest('.anar-admin a[href]');
        if (!link || event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || link.target === '_blank' || link.hasAttribute('download')) return;
        const address = new URL(link.href, window.location.href);
        if (address.origin !== window.location.origin || address.pathname !== window.location.pathname && !address.pathname.endsWith('/wp-login.php')) return;
        if (address.searchParams.has('edit_message') || address.searchParams.has('message')) return;
        const current = new URL(window.location.href);
        if (address.pathname === current.pathname && address.searchParams.get('page') === current.searchParams.get('page') && address.searchParams.get('section') === current.searchParams.get('section') && address.hash) return;
        if (address.searchParams.get('page') === 'anar-login-settings' && root.querySelector('.anar-connection-form')) {
            const section = address.searchParams.get('section') || 'general';
            const button = root.querySelector('[data-tab="' + CSS.escape(section) + '"]');
            if (button) { event.preventDefault(); button.click(); return; }
        }
        event.preventDefault();
        if (navigating) return;
        const proceed = async () => {
            navigating = true;
            const detail = {wait:[]};
            document.dispatchEvent(new CustomEvent('anar:before-navigate', {detail}));
            root.setAttribute('aria-busy', 'true'); link.classList.add('is-loading');
            await Promise.all(detail.wait);
            window.location.assign(address.href);
        };
        const change = new CustomEvent('anar:navigate', {cancelable:true, detail:{proceed}});
        if (document.dispatchEvent(change)) proceed();
    });
    window.addEventListener('pageshow', () => { navigating = false; root.removeAttribute('aria-busy'); root.querySelectorAll('.is-loading').forEach(el => el.classList.remove('is-loading')); });
})();
