(function () {
	'use strict';

	const config = window.AnarLoginAdmin || {};
	const root = document.querySelector('.anar-admin');
	if (!root) return;

	const revealActiveTab = () => {
		const nav = root.querySelector('.anar-admin__nav');
		const activeButton = nav?.querySelector('[data-tab].is-active');
		if (nav && activeButton && nav.scrollWidth > nav.clientWidth) {
			const bounds = nav.getBoundingClientRect();
			const item = activeButton.getBoundingClientRect();
			if (item.left < bounds.left) nav.scrollBy({ left: item.left - bounds.left - 8 });
			else if (item.right > bounds.right) nav.scrollBy({ left: item.right - bounds.right + 8 });
		}
	};
	window.addEventListener('resize', revealActiveTab);

	const showTab = (name) => {
		const connection = root.querySelector('.anar-connection-form');
		if (connection) connection.hidden = ['messages', 'sending', 'features', 'test'].includes(name);
		const save = root.querySelector('.anar-admin__save');
		if (save) save.hidden = name === 'setup' || name === 'sms';
		root.querySelectorAll('[data-tab]').forEach((button) => {
			button.classList.toggle('is-active', button.dataset.tab === name);
			button.setAttribute('aria-current', button.dataset.tab === name ? 'true' : 'false');
		});
		root.querySelectorAll('[data-panel]').forEach((panel) => {
			const active = panel.dataset.panel === name;
			panel.hidden = !active;
			panel.classList.toggle('is-active', active);
		});
		revealActiveTab();
		root.dispatchEvent(new CustomEvent('anar:tab-change', { detail: { name } }));
		try {
			window.sessionStorage.setItem('anarAdminTab', name);
		} catch (error) {
			// Storage can be blocked; the tab simply is not remembered.
		}
	};
	const navigateTab = (name) => {
		showTab(name);
		const address = new URL(window.location.href);
		address.searchParams.set('section', name);
		window.history.replaceState(window.history.state, '', address);
	};

	root.addEventListener('click', event => {
		const button = event.target.closest('[data-tab]');
		if (button && root.contains(button)) navigateTab(button.dataset.tab);
	});
	document.addEventListener('anar:navigation-updated', () => showTab(new URL(window.location.href).searchParams.get('section') || 'features'));

	let savedTab = '';
	try {
		savedTab = window.sessionStorage.getItem('anarAdminTab') || '';
	} catch (error) {
		savedTab = '';
	}
	const requestedTab = new URL(window.location.href).searchParams.get('section');
	if (requestedTab && root.querySelector(`[data-panel="${requestedTab}"]`)) showTab(requestedTab);
	else if (savedTab && root.querySelector(`[data-panel="${savedTab}"]`)) showTab(savedTab);
	else { const first = root.querySelector('[data-tab].is-active') || root.querySelector('[data-tab]'); if (first) showTab(first.dataset.tab); }

	root.querySelectorAll('[data-setup-target]').forEach((button) => {
		button.addEventListener('click', () => {
			navigateTab(button.dataset.setupTarget);
			const title = root.querySelector(`[data-panel="${button.dataset.setupTarget}"] h2`);
			if (title) { title.tabIndex = -1; title.focus(); }
		});
	});
	const setup = root.querySelector('[data-login-setup]');
	if (setup) {
		let step = 0;
		const showStep = (index) => {
			step = Math.max(0, Math.min(3, index));
			setup.querySelectorAll('[data-setup-step]').forEach((panel) => { panel.hidden = Number(panel.dataset.setupStep) !== step; });
			setup.querySelectorAll('[data-setup-go]').forEach((button) => {
				if (Number(button.dataset.setupGo) === step) button.setAttribute('aria-current', 'step');
				else button.removeAttribute('aria-current');
			});
			setup.querySelector('[data-setup-prev]').disabled = step === 0;
			setup.querySelector('[data-setup-next]').disabled = step === 3;
			setup.querySelector(`[data-setup-step="${step}"] h3`).focus();
		};
		setup.querySelectorAll('[data-setup-go]').forEach((button) => button.addEventListener('click', () => showStep(Number(button.dataset.setupGo))));
		setup.querySelector('[data-setup-prev]').addEventListener('click', () => showStep(step - 1));
		setup.querySelector('[data-setup-next]').addEventListener('click', () => showStep(step + 1));
	}
	const preview = root.querySelector('[data-login-preview-dialog]');
	if (preview) {
		let trigger;
		root.querySelectorAll('[data-login-preview]').forEach((button) => button.addEventListener('click', () => {
			trigger = button;
			const frame = preview.querySelector('iframe');
			if (!frame.hasAttribute('src')) frame.src = frame.dataset.src;
			preview.showModal();
		}));
		preview.querySelector('[data-login-preview-close]').addEventListener('click', () => preview.close());
		preview.addEventListener('close', () => { if (trigger) trigger.focus(); });
	}

	const logoMode = root.querySelector('#anar-form-logo');
	const logoCustom = root.querySelector('.anar-logo-picker__custom');
	if (logoMode && logoCustom) {
		const syncLogo = () => {
			logoCustom.hidden = logoMode.value !== 'custom';
			logoCustom.querySelectorAll('input').forEach(field => { field.disabled = logoCustom.hidden; });
		};
		logoMode.addEventListener('change', syncLogo);
		syncLogo();
		const mediaButton = logoCustom.querySelector('[data-anar-media]');
		if (mediaButton && window.wp && window.wp.media) {
			let frame;
			mediaButton.addEventListener('click', () => {
				if (!frame) {
					frame = window.wp.media({ library: { type: 'image' }, multiple: false });
					frame.on('select', () => {
						const image = frame.state().get('selection').first().toJSON();
						logoCustom.querySelector('input').value = (image.sizes && image.sizes.medium ? image.sizes.medium.url : image.url) || '';
					});
				}
				frame.open();
			});
		}
	}


	const methods = root.querySelector('[data-login-methods]');
	const syncMethods = () => {
		if (!methods) return;
		const enabled = [...methods.querySelectorAll('input[type="checkbox"]:checked')].map(input => input.value);
		const boxes = methods.querySelectorAll('input[type="checkbox"]');
		boxes[0].setCustomValidity(enabled.length ? '' : methods.querySelector('[data-login-methods-required]').textContent);
		methods.querySelector('[data-login-methods-required]').hidden = enabled.length > 0;
		methods.querySelectorAll('[data-login-bale]').forEach(el => { el.hidden = !enabled.includes('bale'); });
		root.querySelectorAll('[data-login-email]').forEach(el => { el.hidden = !enabled.includes('email'); });
	};
	methods?.addEventListener('change', syncMethods);
	root.querySelectorAll('[name="anar_login_settings[sms_provider]"]').forEach(input => input.addEventListener('change', syncMethods));
	syncMethods();

	const showCategory = name => {
		root.querySelectorAll('[data-connection-group]').forEach(el => { el.hidden = el.dataset.connectionGroup !== name; });
		root.querySelectorAll('[data-connection-category]').forEach(el => el.setAttribute('aria-pressed', String(el.dataset.connectionCategory === name)));
	};
	root.querySelectorAll('[data-connection-category]').forEach(button => button.addEventListener('click', () => showCategory(button.dataset.connectionCategory)));
	if (requestedTab === 'email') { showTab('sms'); showCategory('email'); }
	root.querySelectorAll('[data-provider-connections] input[type="radio"]').forEach(radio => {
		radio.addEventListener('change', () => {
			const group = radio.closest('[data-connection-group]');
			group.querySelectorAll('.anar-provider').forEach(el => el.classList.toggle('is-selected', el.contains(radio)));
			group.querySelectorAll('.anar-provider-settings').forEach(el => { el.hidden = el.dataset.provider !== radio.value; });
		});
	});
	root.querySelectorAll('.anar-provider-settings').forEach(panel => {
		const has = name => {
			const field = panel.querySelector(`[name$="[${name}]"]`);
			return !!field && (!!field.value.trim() || field.dataset.savedValue === '1');
		};
		panel.addEventListener('input', () => {
			let required = JSON.parse(panel.dataset.requiredFields);
			if (panel.dataset.provider === 'bale') required = has('api_key') ? ['api_key', 'bot_id'] : ['username', 'password'];
			let ready = required.every(has);
			if (panel.dataset.provider === 'bale' && has('api_key')) ready = ready && /^[1-9][0-9]*$/.test(panel.querySelector('[name$="[bot_id]"]').value.trim());
			if (panel.dataset.provider === 'custom') ready = ready && /^https?:\/\/[^/\s]+/i.test(panel.querySelector('[name$="[url]"]').value.trim());
			const badge = root.querySelector(`[data-connection-state="${panel.dataset.provider}"]`);
			if (badge) badge.textContent = ready ? config.connectionCompleteDraft : '⚠ ' + config.connectionIncomplete;
			badge?.classList.toggle('is-ready', ready);
			badge?.classList.toggle('needs-setup', !ready);
		});
	});
	root.querySelectorAll('[data-copy]').forEach((button) => {
		button.addEventListener('click', async () => {
			const original = button.textContent;
			try {
				await navigator.clipboard.writeText(button.dataset.copy);
				button.textContent = config.copied || 'Copied ✓';
			} catch (error) {
				button.textContent = config.failed || 'Copy failed';
			}
			window.setTimeout(() => { button.textContent = original; }, 1500);
		});
	});

	const color = root.querySelector('#anar-color');
	if (color) {
		color.addEventListener('input', () => {
			const code = color.closest('.anar-color').querySelector('code');
			if (code) code.textContent = color.value;
		});
	}
})();
