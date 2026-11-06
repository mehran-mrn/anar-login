(function () {
	'use strict';
	window.AnarPasswordInit = function () { document.querySelectorAll('[data-anar-password]').forEach(function (root) {
		if (root.dataset.initialized) return;
		root.dataset.initialized = '1';
		const form = root.querySelector('form');
		const status = root.querySelector('[data-status]');
		let cooldown = 0;
		async function send(operation) {
			if (operation === 'reset' && form.elements.password.value !== form.elements.confirm.value) { status.textContent = status.dataset.mismatch; return; }
			const buttons = Array.from(form.querySelectorAll('button'));
			buttons.forEach(function (button) { button.disabled = true; });
			status.textContent = '';
			try {
				const data = new FormData(form);
				if (operation === 'request') { data.delete('password'); data.delete('confirm'); data.delete('code'); }
				const response = await fetch(root.dataset.endpoint + operation, {method: 'POST', body: data, credentials: 'same-origin', cache: 'no-store'});
				const result = await response.json();
				status.textContent = result.message || status.dataset.network;
				if (response.ok && operation === 'request') {
					root.querySelector('[data-reset-fields]').hidden = false;
					form.elements.code.required = true; form.elements.password.required = true; form.elements.confirm.required = true;
					form.elements.code.focus(); cooldown = Date.now() + 60000;
					setTimeout(function () { root.querySelector('[data-request]').disabled = false; }, 60000);
				}
				if (response.ok && operation === 'reset') { form.reset(); root.querySelector('[data-reset-fields]').hidden = true; form.elements.code.required = false; form.elements.password.required = false; form.elements.confirm.required = false; }
			} catch (error) { status.textContent = status.dataset.network; }
			finally { buttons.forEach(function (button) { button.disabled = button.hasAttribute('data-request') && Date.now() < cooldown; }); }
		}
		root.querySelector('[data-request]').addEventListener('click', function () { if (form.elements.phone.reportValidity()) send('request'); });
		form.addEventListener('submit', function (event) { event.preventDefault(); send('reset'); });
	}); };
	window.AnarPasswordInit();
})();
