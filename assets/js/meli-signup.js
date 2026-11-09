(function () {
	'use strict';
	const root = document.querySelector('.anar-admin');
	const dialog = root && root.querySelector('.anar-meli-dialog');
	const launch = root && root.querySelector('[data-meli-signup]');
	if (!dialog || !launch || typeof dialog.showModal !== 'function') return;
	const config = window.AnarLoginAdmin || {};
	const labels = config.meli || {};
	const form = dialog.querySelector('[data-meli-form]');
	const planList = form.querySelector('[data-meli-plans]');
	const submit = form.querySelector('[data-meli-submit]');
	const result = dialog.querySelector('[data-meli-result]');
	const errorBox = dialog.querySelector('[data-meli-error]');
	const status = root.querySelector('[data-meli-status]');
	let ticket = '', loadedAt = 0, loading = false, posting = false, completed = false, uncertain = false, opener;
	const removeNameDigits = (event) => {
		if (event.isComposing) return;
		const input = event.target;
		const value = input.value.replace(/\p{Nd}/gu, '');
		if (value === input.value) return;
		const caret = input.value.slice(0, input.selectionStart).replace(/\p{Nd}/gu, '').length;
		input.value = value;
		input.setSelectionRange(caret, caret);
	};
	for (const name of ['first_name', 'last_name']) {
		const input = form.elements.namedItem(name);
		input.addEventListener('input', removeNameDigits);
		input.addEventListener('compositionend', removeNameDigits);
	}
	const request = async (action, payload) => {
		const options = {credentials:'same-origin', headers:{'X-WP-Nonce':config.nonce || '', 'Accept':'application/json'}};
		if (payload) {
			options.method = 'POST'; options.headers['Content-Type'] = 'application/json'; options.body = JSON.stringify(payload);
		}
		const response = await fetch(config.restUrl + 'admin/meli-signup/' + action, options);
		const data = await response.json();
		if (!response.ok) {
			const failure = new Error(typeof data.message === 'string' ? data.message : labels.failed);
			failure.definitive = response.status === 400 || response.status === 422;
			throw failure;
		}
		return data;
	};
	const plans = async () => {
		const data = await request('products');
		if (!data.ready || !Array.isArray(data.products) || !data.products.length || typeof data.ticket !== 'string') return false;
		const previous = planList.querySelector(':checked');
		const selected = data.products.find((item) => previous && String(item.product_code) === previous.value) || data.products[0];
		const currency = {IRR: labels.irr, IRT: labels.irt}[data.price_unit];
		const formatter = new Intl.NumberFormat(document.documentElement.lang || 'fa-IR');
		const money = (amount) => formatter.format(amount) + ' ' + currency;
		const cards = data.products.map((item) => {
			const card = document.createElement('label');
			card.className = 'anar-meli-plan';
			const radio = document.createElement('input');
			radio.type = 'radio';
			radio.name = 'product_code';
			radio.value = String(item.product_code);
			radio.required = true;
			radio.checked = item === selected;
			const details = document.createElement('span');
			details.className = 'anar-meli-plan__details';
			const title = document.createElement('strong');
			title.className = 'anar-meli-plan__title';
			title.textContent = item.product_title;
			details.append(title);
			// Use the confirmed currency for both the plan price and SMS rate.
			if (currency && Number.isFinite(item.price_per_sms)) {
				const rate = document.createElement('span');
				rate.className = 'anar-meli-plan__rate';
				rate.textContent = labels.smsRate.replace('%s', money(item.price_per_sms));
				details.append(rate);
			}
			card.append(radio, details);
			if (currency && Number.isFinite(item.product_price)) {
				const price = document.createElement('span');
				price.className = 'anar-meli-plan__price';
				price.textContent = money(item.product_price);
				card.append(price);
			}
			return card;
		});
		planList.replaceChildren(...cards);
		ticket = data.ticket; loadedAt = Date.now(); return true;
	};
	const show = () => { if (!dialog.open) dialog.showModal(); document.body.classList.add('anar-meli-open'); };
	launch.addEventListener('click', async (event) => {
		event.preventDefault();
		if (loading) return;
		opener = launch;
		if (posting || completed || uncertain || (ticket && Date.now() - loadedAt < 480000)) { show(); return; }
		loading = true; launch.setAttribute('aria-busy', 'true'); status.hidden = false; status.textContent = labels.loading;
		let ready = false;
		try { ready = await plans(); } catch (error) { ready = false; }
		loading = false; launch.removeAttribute('aria-busy'); status.hidden = true;
		if (ready) show(); else window.location.assign(launch.href);
	});
	dialog.querySelector('[data-meli-close]').addEventListener('click', () => dialog.close());
	dialog.addEventListener('click', (event) => {
		const box = dialog.getBoundingClientRect();
		if (event.target === dialog && (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom)) dialog.close();
	});
	dialog.addEventListener('close', () => { document.body.classList.remove('anar-meli-open'); if (opener) opener.focus(); });
	form.addEventListener('submit', async (event) => {
		event.preventDefault();
		if (posting || completed || uncertain || !form.reportValidity()) return;
		posting = true; submit.disabled = true;
		const original = submit.textContent;
		submit.textContent = labels.posting; errorBox.hidden = true;
		try {
			const fields = new FormData(form);
			// The labelled form is submitted deliberately; there is no separate consent checkbox.
			const data = await request('register', {first_name:fields.get('first_name') || '', last_name:fields.get('last_name') || '', mobile_number:fields.get('mobile_number') || '', product_code:Number(fields.get('product_code')), website:fields.get('website') || '', consent:true, ticket});
			const url = new URL(data.invoice_url);
			if (url.protocol !== 'https:' || url.hostname !== 'sign.melipayamak.com' || url.port || url.username || url.password || url.search || url.hash || !/^\/invoice\/[0-9]+\/?$/.test(url.pathname)) throw new Error(labels.failed);
			dialog.querySelector('[data-meli-payment]').href = url.href;
			dialog.querySelector('[data-meli-result-text]').textContent = data.message;
			completed = true; form.hidden = true; result.hidden = false;
			dialog.querySelector('[data-meli-payment]').focus();
		} catch (error) {
			errorBox.textContent = error.message || labels.failed; errorBox.hidden = false;
			if (error.definitive) { try { if (!(await plans())) uncertain = true; } catch (failure) { uncertain = true; } }
			else uncertain = true;
		} finally { posting = false; submit.disabled = uncertain || completed; submit.textContent = original; }
	});
})();
