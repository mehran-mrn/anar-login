(function () {
    'use strict';
    const config = window.AnarFeatures || {};
    document.querySelectorAll('.anar-features form').forEach(function (form) {
        const controls = [...form.querySelectorAll('input[role="switch"]')];
        const queue = new Map();
        let running = false;
        let active = null;
		async function request(data, timeout = 20000) {
			const controller = new AbortController();
			const timer = window.setTimeout(() => controller.abort(), timeout);
			try {
				const response = await fetch(config.url, {method:'POST', credentials:'same-origin', body:data, signal:controller.signal});
				const body = await response.json().catch(() => null);
				return {response, body};
			} finally { window.clearTimeout(timer); }
		}
        const pending = () => running || queue.size > 0;
        const feedback = (input, text, error = false) => {
            const result = input.closest('.anar-feature-card').querySelector('.anar-feature-feedback');
            result.hidden = false; result.textContent = text; result.classList.toggle('is-error', error);
        };
        function render(input) {
            const card = input.closest('.anar-feature-card');
            card.classList.toggle('is-active', input.checked);
            card.classList.toggle('is-pending', queue.has(input) || active === input);
        }
        function updateLink(input, url) {
            const card = input.closest('.anar-feature-card');
            let link = card.querySelector('.anar-feature-configure');
            if (!link && input.checked) {
                link = document.createElement('a'); link.className = 'button anar-feature-configure';
                link.textContent = config.configure; card.querySelector('footer').append(link);
            }
            if (link) { link.href = url; link.hidden = !input.checked; }
        }
		function updateNavigation(data) {
			if (data.navigation) {
				const sidebar = document.querySelector('.wrap.anar-admin .anar-admin__nav');
				if (sidebar) { sidebar.innerHTML = data.navigation; document.dispatchEvent(new CustomEvent('anar:navigation-updated')); }
			}
			const submenu = document.querySelector('#toplevel_page_anar-login .wp-submenu');
			if (!submenu || !Array.isArray(data.menu)) return;
			data.menu.forEach(item => {
				const links = [...submenu.querySelectorAll('a[href]')].filter(link => new URL(link.href, window.location.href).searchParams.get('page') === item.page);
				if (!item.visible) { links.forEach(link => link.closest('li').remove()); return; }
				if (links.length) return;
				const entry = document.createElement('li');
				const link = document.createElement('a'); link.href = item.url; link.textContent = item.label;
				entry.append(link); submenu.append(entry);
			});
		}
        async function flush() {
            if (running) return;
            running = true;
            while (queue.size) {
                const [input, desired] = queue.entries().next().value;
                queue.delete(input); active = input;
                const previous = input.dataset.saved === '1';
                const card = input.closest('.anar-feature-card');
                if (desired === previous) { active = null; render(input); feedback(input, desired ? config.enabled : config.disabled); continue; }
                card.setAttribute('aria-busy', 'true'); render(input);
                feedback(input, desired ? config.enabling : config.disabling);
                const data = new URLSearchParams({action:'anar_feature_toggle', nonce:config.nonce, key:input.name.slice(9,-1), enabled:desired?'1':'0', expected:previous?'1':'0', workspace:document.querySelector('.anar-connection-form') ? 'settings' : ''});
                let confirmed = previous;
				let confirmedUrl = card.querySelector('.anar-feature-configure')?.href || '';
                try {
                    let response, body;
                    // A second browser tab may briefly hold the server mutation lock.
                    for (let attempt = 0; attempt < 3; attempt++) {
						({response, body} = await request(data));
                        if (!body?.data?.retry || attempt === 2) break;
                        await new Promise(resolve => window.setTimeout(resolve, 250 * (attempt + 1)));
                    }
                    if (typeof body?.data?.enabled === 'boolean') confirmed = body.data.enabled;
                    if (!response.ok || !body?.success) {
                        const error = new Error(body?.data?.text || config.failed); error.setup = body?.data?.setup; error.definite = !!body; throw error;
                    }
                    if (!queue.has(input)) input.checked = confirmed;
					confirmedUrl = body.data.url;
                    updateLink(input, body.data.url);
                    feedback(input, confirmed ? config.enabled : config.disabled);
					updateNavigation(body.data);
                    document.dispatchEvent(new CustomEvent('anar:feature-saved', {detail:{form, input, enabled:confirmed}}));
                    // Keep cards in place while the user is changing several features.
                } catch (error) {
					let reconciled = false;
					// A lost response may still have committed. Read, never replay, that mutation.
					if (!error.definite) {
						try {
							const check = new URLSearchParams({action:'anar_feature_state', nonce:config.nonce, key:input.name.slice(9,-1), workspace:data.get('workspace')});
							const {body} = await request(check, 10000);
							if (body?.success && typeof body.data.enabled === 'boolean') { confirmed = body.data.enabled; confirmedUrl = body.data.url; reconciled = true; updateNavigation(body.data); }
						} catch (_) { /* Keep the last confirmed state and show the uncertainty. */ }
					}
                    if (!queue.has(input)) input.checked = confirmed;
					if (confirmedUrl) updateLink(input, confirmedUrl);
					if (reconciled && confirmed === desired) {
						feedback(input, confirmed ? config.enabled : config.disabled);
						document.dispatchEvent(new CustomEvent('anar:feature-saved', {detail:{form, input, enabled:confirmed}}));
					} else feedback(input, error.definite ? error.message : config.failed, true);
                    if (error.setup) {
                        const link = document.createElement('a'); link.href = error.setup; link.textContent = config.setup;
                        card.querySelector('.anar-feature-feedback').append(' ', link);
                    }
                } finally {
                    input.dataset.saved = confirmed ? '1' : '0';
                    card.removeAttribute('aria-busy'); active = null; render(input);
                }
            }
            running = false;
            document.dispatchEvent(new CustomEvent('anar:feature-idle'));
        }
        controls.forEach(function (input) {
            input.dataset.saved = input.checked ? '1' : '0';
            input.addEventListener('change', function () {
                queue.set(input, input.checked); render(input); feedback(input, config.queued); flush();
            });
        });
        form.addEventListener('submit', event => event.preventDefault());
        window.addEventListener('beforeunload', function (event) {
            if (pending()) { event.preventDefault(); event.returnValue = ''; }
        });
        document.addEventListener('anar:before-navigate', function (event) {
            if (pending()) event.detail.wait.push(new Promise(resolve => document.addEventListener('anar:feature-idle', resolve, {once:true})));
        });
    });
})();
