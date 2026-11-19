(function () {
	'use strict';
	const config = window.AnarLoginAdmin || {};
	const root = document.querySelector('.wrap.anar-admin');
	if (!root) return;
	const connection = document.getElementById('anar-connections-form');
	const usage = root.querySelector('[data-active-messages]');
	const snapshot = () => connection ? JSON.stringify([...new FormData(connection).entries()]) : '';
	let savedConnection = snapshot();
	function draft() {
		if (!usage || !connection) return;
		const selected = connection.elements.namedItem('anar_login_settings[sms_provider]');
		const changed = !!selected && selected.value !== usage.dataset.savedProvider;
		usage.querySelector('[data-provider-draft-warning]').hidden = !(changed || snapshot() !== savedConnection);
	}
	root.addEventListener('input', event => {
		if (event.target.form === connection) draft();
	});
	root.addEventListener('change', draft);
	function report(scope, text, error) {
		const target = root.querySelector('[data-scoped-feedback="' + scope + '"]');
		target.textContent = text; target.hidden = false; target.classList.toggle('is-error', error);
	}
	async function submit(form, check) {
		if (!form.reportValidity() || form.dataset.busy === '1') return;
		const scope = form.dataset.scopedSettings;
		const data = new FormData(form); data.set('action', check ? 'anar_connection_check' : 'anar_settings_save');
		const buttons = [...root.querySelectorAll('[form="' + form.id + '"][type="submit"], [data-connection-check]')];
		buttons.forEach(button => { button.disabled = true; }); form.dataset.busy = '1';
		report(scope, check ? config.checking : config.saving, false);
		try {
			const response = await fetch(config.ajaxUrl, {method:'POST',credentials:'same-origin',body:data});
			const body = await response.json().catch(() => null);
			if (!response.ok || !body?.success) throw new Error(body?.data?.text || config.network);
			report(scope, body.data.text, false);
			if (!check) {
				if (scope === 'connections') {
					usage.dataset.savedProvider = body.data.provider;
					usage.querySelector('[data-active-provider-title]').textContent = body.data.title;
					root.querySelectorAll('[data-verification-messages]').forEach(panel => { panel.dataset.savedProvider = body.data.provider; });
					root.querySelectorAll('[form="anar-connections-form"][type="password"]').forEach(field => {
						if (field.value) { field.dataset.savedValue = '1'; field.value = ''; }
					});
					savedConnection = snapshot(); draft();
					root.querySelectorAll('[data-message-summary]').forEach(summary => {
						const text = body.data.states?.[summary.dataset.messageSummary];
						const status = summary.querySelector('[data-message-status]');
						if (text && status) status.textContent = text;
					});
				}
				document.dispatchEvent(new CustomEvent('anar:scoped-saved', {detail:{form}}));
			}
		} catch (error) { report(scope, error.message || config.network, true); }
		finally { form.dataset.busy = '0'; buttons.forEach(button => { button.disabled = false; }); }
	}
	document.querySelectorAll('[data-scoped-settings]').forEach(form => {
		form.addEventListener('submit', event => { event.preventDefault(); submit(form, false); });
	});
	root.querySelector('[data-connection-check]')?.addEventListener('click', () => submit(connection, true));
	// If a remaining main-form field is invalid, reveal it instead of silently blocking a hidden tab.
	root.querySelector('.anar-connection-form')?.addEventListener('invalid', event => {
		const field = event.target;
		const panel = field.closest('[data-panel]');
		if (panel?.hidden) root.querySelector('[data-tab="' + panel.dataset.panel + '"]')?.click();
		let parent = field.parentElement;
		while (parent && parent !== root) { if (parent.tagName === 'DETAILS') parent.open = true; parent = parent.parentElement; }
		const feedback = root.querySelector('[data-settings-validation]'); feedback.textContent = config.invalid; feedback.hidden = false;
		field.focus();
	}, true);
})();
