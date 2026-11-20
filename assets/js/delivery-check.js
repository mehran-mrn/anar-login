(function () {
	'use strict';
	const root = document.querySelector('.anar-admin');
	const panel = root?.querySelector('[data-delivery-check]');
	if (!panel) return;
	const config = window.AnarLoginAdmin || {};
	const form = root.querySelector('.anar-connection-form');
	const forms = [form, ...document.querySelectorAll('[data-scoped-settings]')].filter(Boolean);
	const phone = panel.querySelector('#anar-delivery-phone');
	const send = panel.querySelector('[data-delivery-send]');
	const refresh = panel.querySelector('[data-delivery-refresh]');
	const feedback = panel.querySelector('[data-delivery-feedback]');
	const result = panel.querySelector('.anar-sms-test__result');
	const snapshot = (target) => new URLSearchParams(new FormData(target)).toString();
	const baselines = new Map(forms.map(target => [target, snapshot(target)]));
	let dirty = false;
	let sending = false;
	let checking = false;
	const sync = () => {
		dirty = forms.some(target => snapshot(target) !== baselines.get(target));
		panel.querySelector('[data-delivery-unsaved]').hidden = !dirty;
		send.disabled = dirty || sending || checking || panel.dataset.ready !== '1';
	};
	root.addEventListener('input', sync);
	root.addEventListener('change', sync);
	document.addEventListener('anar:scoped-saved', (event) => {
		if (baselines.has(event.detail.form)) baselines.set(event.detail.form, snapshot(event.detail.form));
		sync();
		if (!panel.closest('[data-panel]').hidden) check();
	});
	const check = async () => {
		if (checking || sending) return;
		checking = true;
		refresh.disabled = true;
		feedback.textContent = config.checkingSaved;
		panel.setAttribute('aria-busy', 'true');
		sync();
		try {
			const response = await fetch(config.restUrl + 'admin/delivery-check', {
				credentials: 'same-origin', cache: 'no-store', headers: { 'X-WP-Nonce': config.nonce || '' }
			});
			const data = await response.json().catch(() => ({}));
			if (!response.ok || !Array.isArray(data.checks)) throw new Error(data.message || config.network);
			panel.dataset.ready = data.ready ? '1' : '0';
			panel.dataset.revision = data.revision;
			panel.querySelector('[data-delivery-provider]').textContent = data.provider;
			panel.querySelector('[data-delivery-method]').textContent = data.method;
			panel.querySelector('[data-delivery-report]').value = data.support;
			const list = panel.querySelector('[data-delivery-checks]');
			list.replaceChildren(...data.checks.map((item) => {
				const row = document.createElement('li');
				row.className = item.ok ? 'is-ready' : 'needs-setup';
				const mark = document.createElement('span');
				mark.setAttribute('aria-hidden', 'true');
				mark.textContent = item.ok ? '✓' : '!';
				const content = document.createElement('div');
				const title = document.createElement('strong');
				title.textContent = item.label;
				const detail = document.createElement('p');
				detail.textContent = item.detail;
				content.append(title, detail);
				row.append(mark, content);
				return row;
			}));
			feedback.textContent = config.checked;
		} catch (error) {
			panel.dataset.ready = '0';
			feedback.textContent = error.message || config.network;
		} finally {
			checking = false;
			refresh.disabled = false;
			panel.removeAttribute('aria-busy');
			sync();
		}
	};
	refresh.addEventListener('click', check);
	root.addEventListener('anar:tab-change', (event) => {
		if (event.detail.name === 'test') { sync(); check(); }
	});
	phone.required = true;
	send.addEventListener('click', async () => {
		sync();
		if (send.disabled || !phone.reportValidity()) return;
		const label = send.textContent;
		sending = true;
		send.textContent = config.sending;
		result.hidden = true;
		sync();
		try {
			// Test only the saved binding shown above. Never post the connection form.
			const response = await fetch(config.restUrl + 'admin/test-sms', {
				method: 'POST', credentials: 'same-origin', headers: { 'X-WP-Nonce': config.nonce || '' },
				body: new URLSearchParams({ phone: phone.value, revision: panel.dataset.revision })
			});
			const data = await response.json();
			result.textContent = response.ok ? config.accepted : (data.message || config.uncertain);
			result.className = 'anar-sms-test__result is-' + (response.ok ? 'success' : 'error');
			if (response.status === 409) panel.dataset.ready = '0';
		} catch (error) {
			result.textContent = config.uncertain;
			result.className = 'anar-sms-test__result is-error';
		} finally {
			sending = false;
			send.textContent = label;
			result.hidden = false;
			sync();
		}
	});
	phone.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') { event.preventDefault(); send.click(); }
	});
	panel.querySelector('[data-delivery-copy]').addEventListener('click', async () => {
		const report = panel.querySelector('[data-delivery-report]');
		const status = panel.querySelector('[data-delivery-copy-status]');
		try {
			await navigator.clipboard.writeText(report.value);
			status.textContent = config.copied;
		} catch (error) {
			report.focus();
			report.select();
			status.textContent = config.failed;
		}
	});
	sync();
})();
