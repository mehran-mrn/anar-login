(function () {
	'use strict';
	document.querySelectorAll('[data-anar-stock]').forEach(function (form) {
		let busy = false;
		const status = form.querySelector('[data-status]');
		async function send(request) {
			if (busy || !form.elements.phone.reportValidity()) return;
			busy = true; const buttons = Array.from(form.querySelectorAll('button')); buttons.forEach(function (b) { b.disabled = true; });
			const data = new FormData(form); if (request) data.set('request_code', '1');
			try { const response = await fetch(form.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data}); const result = await response.json(); status.textContent = result.data && result.data.message ? result.data.message : ''; }
			catch (error) { status.textContent = 'Network error'; }
			finally { busy = false; buttons.forEach(function (b) { b.disabled = false; }); }
		}
		form.addEventListener('submit', function (event) { event.preventDefault(); send(false); }); form.querySelector('[data-stock-request]').addEventListener('click', function () { send(true); });
	});
})();
