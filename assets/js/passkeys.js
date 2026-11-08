(function () {
	'use strict';
	const decode = (value) => Uint8Array.from(atob(value.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));
	const encode = (value) => btoa(String.fromCharCode(...new Uint8Array(value))).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	async function run(button) {
		if (button.disabled) return;
		button.disabled = true;
		button.setAttribute('aria-busy', 'true');
		const status = button.parentElement.querySelector('[data-passkey-status]');
		const report = (text) => { if (status) status.textContent = text; };
		const request = async (path, data) => {
			const headers = { 'Content-Type': 'application/json' };
			if (button.dataset.passkeyNonce) headers['X-WP-Nonce'] = button.dataset.passkeyNonce;
			const response = await fetch(button.dataset.passkeyRest + path, { method: 'POST', credentials: 'same-origin', headers, body: JSON.stringify(data) });
			const result = await response.json();
			if (!response.ok) throw Object.assign(new Error(result.message || button.dataset.passkeyError), { anarMessage: true });
			return result;
		};
		try {
			if (!window.PublicKeyCredential || !navigator.credentials || !window.isSecureContext) throw new Error(button.dataset.passkeyError);
			const registration = button.dataset.passkey === 'register';
			const card = button.closest('[data-redirect]');
			const redirect = (card && card.dataset.redirect) || new URLSearchParams(location.search).get('redirect_to') || '';
			const options = await request('options', { flow: registration ? button.dataset.passkeyFlow : '', redirect_to: redirect, remember: false });
			const publicKey = options.publicKey;
			publicKey.challenge = decode(publicKey.challenge);
			if (registration) {
				publicKey.user.id = decode(publicKey.user.id);
				(publicKey.excludeCredentials || []).forEach((key) => { key.id = decode(key.id); });
			} else (publicKey.allowCredentials || []).forEach((key) => { key.id = decode(key.id); });
			const credential = registration ? await navigator.credentials.create({ publicKey }) : await navigator.credentials.get({ publicKey });
			if (!credential) throw new Error(button.dataset.passkeyCancelled);
			const payload = { flow: options.flow, id: encode(credential.rawId), clientDataJSON: encode(credential.response.clientDataJSON) };
			if (registration) {
				payload.attestationObject = encode(credential.response.attestationObject);
				const name = button.parentElement.querySelector('[name="passkey_name"]');
				payload.name = name ? name.value : '';
			} else {
				payload.authenticatorData = encode(credential.response.authenticatorData);
				payload.signature = encode(credential.response.signature);
				payload.userHandle = credential.response.userHandle ? encode(credential.response.userHandle) : '';
			}
			const result = await request(registration ? 'register' : 'verify', payload);
			if (result.redirect) {
				const target = new URL(result.redirect, location.href);
				if (target.origin !== location.origin) throw new Error(button.dataset.passkeyError);
				location.assign(target.href);
			} else report(result.message || '');
		} catch (error) {
			report(error.name === 'NotAllowedError' || error.name === 'AbortError' ? button.dataset.passkeyCancelled : (error.anarMessage ? error.message : button.dataset.passkeyError));
		} finally { button.disabled = false; button.setAttribute('aria-busy', 'false'); }
	}
	window.AnarPasskeys = { run };
	document.addEventListener('click', (event) => { const button = event.target.closest('[data-passkey="register"]'); if (button) run(button); });
})();
