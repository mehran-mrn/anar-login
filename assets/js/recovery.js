(function () {
	'use strict';
	document.querySelectorAll('[data-anar-recovery]').forEach(function (root) {
		const button = root.querySelector('[data-save]');
		const status = root.querySelector('[data-status]');
		button.addEventListener('click', async function () {
			button.disabled = true;
			status.textContent = '';
			const data = new FormData();
			data.set('nonce', root.dataset.nonce);
			data.set('phone', root.querySelector('[data-phone]').value);
			data.set('email', root.querySelector('[data-email]').value);
			data.set('consent', root.querySelector('[data-consent]').checked ? '1' : '');
			try {
				const response = await fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data});
				const result = await response.json();
				status.textContent = response.ok && result.success ? status.dataset.success : status.dataset.error;
			} catch (error) {
				status.textContent = status.dataset.error;
			} finally {
				button.disabled = false;
			}
		});
	});
	document.querySelectorAll('[data-anar-recover]').forEach(function (root) {
		const status = root.querySelector('[data-status]');
		const buttons = root.querySelectorAll('button');
		const send = async function (requestCode) {
			buttons.forEach(function (button) { button.disabled = true; });
			status.textContent = '';
			const data = new FormData();
			data.set('nonce', root.dataset.nonce);
			data.set('phone', root.querySelector('[data-phone]').value);
			data.set('code', root.querySelector('[data-code]').value);
			try {
				const response = await fetch(requestCode ? root.dataset.request : root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data});
				const result = await response.json();
				if (response.ok && result.success) {
					if (requestCode) {
						status.textContent = status.dataset.requested;
					} else if (result.data && result.data.redirect) {
						const destination = new URL(result.data.redirect, window.location.href);
						if (destination.origin === window.location.origin) window.location.assign(destination.href);
					}
				} else {
					status.textContent = status.dataset.error;
				}
			} catch (error) {
				status.textContent = status.dataset.error;
			} finally {
				buttons.forEach(function (button) { button.disabled = false; });
			}
		};
		root.querySelector('[data-restore]').addEventListener('click', function () { send(false); });
		root.querySelector('[data-request-code]').addEventListener('click', function () { send(true); });
		root.querySelector('[data-code]').addEventListener('keydown', function (event) {
			if (event.key === 'Enter') { event.preventDefault(); send(false); }
		});
	});
})();
