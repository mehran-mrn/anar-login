(function () {
	'use strict';
	const root = document.querySelector('[data-anar-auto]');
	if (!root) return;
	let timer; let last = '';
	function capture() {
		const phone = document.querySelector('#billing_phone, #billing-phone, input[autocomplete="tel"]');
		const email = document.querySelector('#billing_email, #email, input[autocomplete="email"]');
		if (!phone || !phone.value || phone.value === last) return;
		const data = new FormData();
		data.set('nonce', root.dataset.nonce); data.set('phone', phone.value); data.set('email', email ? email.value : '');
		const current = phone.value;
		fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data}).then(function (response) { if (response.ok) last = current; }).catch(function () {});
	}
	document.addEventListener('change', function (event) {
		if (event.target.matches('#billing_phone, #billing-phone, input[autocomplete="tel"], #billing_email, input[autocomplete="email"]')) { clearTimeout(timer); timer = setTimeout(capture, 1200); }
	});
	capture();
})();
