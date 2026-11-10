(function () {
	'use strict';

	const config = window.AnarLoginAdmin || {};
	const root = document.querySelector('.anar-admin');
	if (!root) return;

	const showTab = (name) => {
		const connection = root.querySelector('.anar-connection-form');
		if (connection) connection.hidden = name === 'messages' || name === 'sending';
		const save = root.querySelector('.anar-admin__save');
		if (save) save.hidden = name === 'setup';
		root.querySelectorAll('[data-tab]').forEach((button) => {
			button.classList.toggle('is-active', button.dataset.tab === name);
			button.setAttribute('aria-current', button.dataset.tab === name ? 'true' : 'false');
		});
		root.querySelectorAll('[data-panel]').forEach((panel) => {
			const active = panel.dataset.panel === name;
			panel.hidden = !active;
			panel.classList.toggle('is-active', active);
		});
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

	root.querySelectorAll('[data-tab]').forEach((button) => {
		button.addEventListener('click', () => {
			navigateTab(button.dataset.tab);
		});
	});

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
		logoMode.addEventListener('change', () => {
			logoCustom.hidden = logoMode.value !== 'custom';
		});
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

	const tester = root.querySelector('.anar-sms-test');
	if (tester) {
		const phone = tester.querySelector('input');
		const send = tester.querySelector('button');
		const result = tester.querySelector('.anar-sms-test__result');
		const report = (text, type) => {
			result.textContent = text;
			result.className = 'anar-sms-test__result is-' + type;
			result.hidden = false;
		};

		send.addEventListener('click', async () => {
			const label = send.textContent;
			send.disabled = true;
			send.textContent = config.sending || label;
			try {
				// Send the form's current, unsaved values so the selected provider is the one tested.
				const body = new URLSearchParams();
				body.append('phone', phone.value);
				const form = tester.closest('form');
				if (form) {
					new FormData(form).forEach((value, name) => {
						if (name.indexOf('anar_login_settings[') === 0 && typeof value === 'string') body.append(name, value);
					});
				}
				const response = await fetch(config.restUrl + 'admin/test-sms', {
					method: 'POST',
					credentials: 'same-origin',
					headers: { 'X-WP-Nonce': config.nonce || '' },
					body
				});
				const data = await response.json().catch(() => ({}));
				report(data.message || config.network, response.ok ? 'success' : 'error');
			} catch (error) {
				report(config.network, 'error');
			} finally {
				send.disabled = false;
				send.textContent = label;
			}
		});

		// Enter in the test field must not submit the settings form.
		phone.addEventListener('keydown', (event) => {
			if (event.key === 'Enter') {
				event.preventDefault();
				send.click();
			}
		});
	}

	const verificationMessages = root.querySelector('[data-verification-messages]');
	const saveProviderFirst = verificationMessages?.querySelector('[data-save-provider-first]');
	root.querySelectorAll('.anar-provider input[type="radio"]').forEach((radio) => {
		radio.addEventListener('change', () => {
			root.querySelectorAll('.anar-provider').forEach((item) => item.classList.remove('is-selected'));
			radio.closest('.anar-provider').classList.add('is-selected');
			root.querySelectorAll('.anar-provider-settings').forEach((panel) => {
				panel.hidden = panel.dataset.provider !== radio.value;
			});
			if (saveProviderFirst) saveProviderFirst.hidden = radio.value === verificationMessages.dataset.savedProvider;
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
