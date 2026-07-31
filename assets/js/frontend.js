(function () {
	'use strict';

	const config = window.AnarLogin || {};

	const request = async (path, payload) => {
		const response = await fetch(config.restUrl + path, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': config.nonce || ''
			},
			body: JSON.stringify(payload)
		});
		let body = {};
		try {
			body = await response.json();
		} catch (error) {
			body = {};
		}
		if (!response.ok) {
			throw new Error(body.message || config.strings.network);
		}
		return body;
	};

	const loading = (button, active) => {
		if (!button) return;
		if (active) {
			button.dataset.label = button.querySelector('span') ? button.querySelector('span').textContent : button.textContent;
			button.disabled = true;
			button.classList.add('is-loading');
			const label = button.querySelector('span');
			if (label) label.textContent = config.strings.processing;
		} else {
			button.disabled = false;
			button.classList.remove('is-loading');
			const label = button.querySelector('span');
			if (label && button.dataset.label) label.textContent = button.dataset.label;
		}
	};

	document.querySelectorAll('.anar-login-card').forEach((card) => {
		const identityForm = card.querySelector('.anar-form--identity');
		const codeForm = card.querySelector('.anar-form--code');
		const identityInput = identityForm.querySelector('[name="identity"]');
		const codeInput = codeForm.querySelector('[name="code"]');
		const notice = card.querySelector('.anar-notice');
		const resend = card.querySelector('.anar-resend');
		const google = card.querySelector('.anar-btn--google');
		let identity = '';
		let timer = 0;
		let interval;

		const message = (text, type) => {
			notice.textContent = text || '';
			notice.className = 'anar-notice' + (type ? ' is-' + type : '');
			notice.hidden = !text;
		};

		const countdown = (seconds) => {
			clearInterval(interval);
			timer = Number(seconds) || 60;
			resend.disabled = true;
			const tick = () => {
				const suffix = resend.querySelector('span');
				if (suffix) suffix.textContent = timer > 0 ? `(${timer})` : '';
				if (timer <= 0) {
					clearInterval(interval);
					resend.disabled = false;
				}
				timer -= 1;
			};
			tick();
			interval = setInterval(tick, 1000);
		};

		const sendCode = async () => {
			const button = identityForm.querySelector('[type="submit"]');
			loading(button, true);
			message('');
			try {
				const result = await request('auth/request', {
					identity,
					website: identityForm.querySelector('[name="website"]').value
				});
				identityForm.hidden = true;
				codeForm.hidden = false;
				card.querySelector('.anar-code-copy__identity').textContent = identity;
				message(result.message, 'success');
				countdown(result.resend_after);
				window.setTimeout(() => codeInput.focus(), 80);
			} catch (error) {
				message(error.message, 'error');
			} finally {
				loading(button, false);
			}
		};

		identityForm.addEventListener('submit', (event) => {
			event.preventDefault();
			identity = identityInput.value.trim();
			if (!identity) {
				message('شماره موبایل یا ایمیل را وارد کنید.', 'error');
				identityInput.focus();
				return;
			}
			sendCode();
		});

		codeForm.addEventListener('submit', async (event) => {
			event.preventDefault();
			const button = codeForm.querySelector('[type="submit"]');
			const code = codeInput.value.replace(/\D/g, '');
			if (code.length < 4) {
				message('کد تأیید را کامل وارد کنید.', 'error');
				codeInput.focus();
				return;
			}
			loading(button, true);
			message('');
			try {
				const result = await request('auth/verify', {
					identity,
					code,
					remember: codeForm.querySelector('[name="remember"]').checked
				});
				message(result.message, 'success');
				window.location.assign(result.redirect || window.location.href);
			} catch (error) {
				message(error.message, 'error');
				codeInput.select();
			} finally {
				loading(button, false);
			}
		});

		codeForm.querySelector('.anar-auth__back').addEventListener('click', () => {
			codeForm.hidden = true;
			identityForm.hidden = false;
			message('');
			clearInterval(interval);
			identityInput.focus();
		});

		resend.addEventListener('click', () => {
			if (!resend.disabled) sendCode();
		});

		codeInput.addEventListener('input', () => {
			codeInput.value = codeInput.value.replace(/[^\d۰-۹٠-٩]/g, '');
		});

		if (google) {
			const url = new URL(google.href);
			url.searchParams.set('redirect', window.location.href);
			google.href = url.toString();
		}
	});

	document.querySelectorAll('.anar-panel').forEach((panel) => {
		const activate = (name) => {
			panel.querySelectorAll('[data-anar-tab]').forEach((button) => {
				button.classList.toggle('is-active', button.dataset.anarTab === name);
			});
			panel.querySelectorAll('[data-anar-view]').forEach((view) => {
				const active = view.dataset.anarView === name;
				view.hidden = !active;
				view.classList.toggle('is-active', active);
			});
		};

		panel.querySelectorAll('[data-anar-tab]').forEach((button) => {
			button.addEventListener('click', () => activate(button.dataset.anarTab));
		});
		panel.querySelectorAll('[data-anar-goto]').forEach((button) => {
			button.addEventListener('click', () => activate(button.dataset.anarGoto));
		});

		const form = panel.querySelector('.anar-profile-form');
		if (form) {
			form.addEventListener('submit', async (event) => {
				event.preventDefault();
				const button = form.querySelector('[type="submit"]');
				const status = form.querySelector('.anar-form-status');
				loading(button, true);
				status.textContent = '';
				try {
					const data = Object.fromEntries(new FormData(form).entries());
					const result = await request('profile', data);
					status.textContent = result.message;
					status.className = 'anar-form-status is-success';
				} catch (error) {
					status.textContent = error.message;
					status.className = 'anar-form-status is-error';
				} finally {
					loading(button, false);
				}
			});
		}
	});
})();
