(function () {
	'use strict';
	document.querySelectorAll('[data-anar-stock]').forEach(function (form) {
		let busy = false;
		const status = form.querySelector('[data-status]');
		async function send(request) {
			if (busy || !form.elements.phone.reportValidity()) return;
			busy = true; const buttons = Array.from(form.querySelectorAll('button')); buttons.forEach(function (b) { b.disabled = true; });
			const data = new FormData(form); if (request) data.set('request_code', '1');
			try {
				let response = await fetch(form.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data});
				let result = await response.json();
				// Invisible bot check: solve the puzzle the server sent, then resend once.
				if (428 === response.status && result.data && result.data.challenge) {
					await import(result.data.challenge.script);
					const proof = await window.AnarChallenge.solve(result.data.challenge);
					Object.keys(proof).forEach(function (key) { data.set('proof[' + key + ']', proof[key]); });
					response = await fetch(form.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data});
					result = await response.json();
				}
				status.textContent = result.data && result.data.message ? result.data.message : '';
			}
			catch (error) { status.textContent = 'Network error'; }
			finally { busy = false; buttons.forEach(function (b) { b.disabled = false; }); }
		}
		form.addEventListener('submit', function (event) { event.preventDefault(); send(false); }); form.querySelector('[data-stock-request]').addEventListener('click', function () { send(true); });
	});
})();
