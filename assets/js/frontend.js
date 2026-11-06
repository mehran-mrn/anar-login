(function () {
	'use strict';

	const config = window.AnarLogin || {};
	const strings = config.strings || {};
	const otpLength = Number(config.otpLength) || 5;
	const params = new URLSearchParams(window.location.search);
	const redirectTo = params.get('redirect_to') || '';

	let numbers;
	try {
		numbers = new Intl.NumberFormat(config.locale || undefined, { useGrouping: false });
	} catch (error) {
		numbers = new Intl.NumberFormat(undefined, { useGrouping: false });
	}

	// Persian and Arabic-Indic digits typed on mobile keyboards become ASCII digits.
	const latinDigits = (value) => String(value || '')
		.replace(/[۰-۹]/g, (digit) => String(digit.charCodeAt(0) - 0x06F0))
		.replace(/[٠-٩]/g, (digit) => String(digit.charCodeAt(0) - 0x0660));

	const formatSeconds = (total) => {
		const minutes = Math.floor(total / 60);
		const seconds = total % 60;
		const padded = numbers.format(Math.floor(seconds / 10)) + numbers.format(seconds % 10);
		return minutes ? `${numbers.format(minutes)}:${padded}` : numbers.format(seconds);
	};

	const request = async (path, payload, authenticated) => {
		const headers = { 'Content-Type': 'application/json' };
		// Sign-in requests deliberately omit the nonce: a stale one from a cached page would be rejected.
		if (authenticated && config.nonce) headers['X-WP-Nonce'] = config.nonce;

		let response;
		try {
			response = await fetch(config.restUrl + path, {
				method: 'POST',
				credentials: 'same-origin',
				headers,
				body: JSON.stringify(payload)
			});
		} catch (error) {
			throw new Error(strings.network);
		}

		let body = {};
		try {
			body = await response.json();
		} catch (error) {
			body = {};
		}
		if (!response.ok) {
			const failure = new Error(body.message || strings.network);
			failure.code = body.code || '';
			throw failure;
		}
		return body;
	};

	const loading = (button, active, text) => {
		if (!button) return;
		const label = button.querySelector('span');
		button.classList.toggle('is-loading', active);
		button.setAttribute('aria-busy', active ? 'true' : 'false');
		button.disabled = active;
		if (!label) return;
		if (active) {
			if (!button.dataset.label) button.dataset.label = label.textContent;
			label.textContent = text || strings.processing;
		} else if (button.dataset.label) {
			label.textContent = button.dataset.label;
			delete button.dataset.label;
		}
	};

	// Drop the error flag left by a failed Google sign-in so a reload does not repeat it.
	if (config.errorArg && params.has(config.errorArg) && !window.anarLoginErrorCleared) {
		window.anarLoginErrorCleared = true;
		params.delete(config.errorArg);
		const query = params.toString();
		window.history.replaceState(null, '', window.location.pathname + (query ? `?${query}` : '') + window.location.hash);
	}

	document.querySelectorAll('.anar-login-card').forEach((card) => {
		// The popup may load this script on a page that already ran it; bind each card once.
		if (card.dataset.anarReady) return;
		card.dataset.anarReady = '1';
		// Read on use: the popup updates data-redirect to match the link that opened it.
		const cardRedirect = () => card.dataset.redirect || redirectTo;
		const identityForm = card.querySelector('.anar-form--identity');
		const codeForm = card.querySelector('.anar-form--code');
		const identityInput = identityForm.querySelector('[name="identity"]');
		const identitySubmit = identityForm.querySelector('[type="submit"]');
		const honeypot = identityForm.querySelector('[name="website"]');
		const codeInput = codeForm.querySelector('[name="code"]');
		const codeSubmit = codeForm.querySelector('[type="submit"]');
		const remember = codeForm.querySelector('[name="remember"]');
		const sentLabel = card.querySelector('.anar-code-copy__identity');
		const notice = card.querySelector('.anar-notice');
		const resend = card.querySelector('.anar-resend');
		const resendTimer = resend.querySelector('.anar-resend__timer');
		const google = card.querySelector('.anar-btn--google');
		let identity = '';
		let sentTo = '';
		let deadline = 0;
		let interval = 0;
		let verifying = false;
		let otpRequest = null;

		const message = (text, type) => {
			notice.textContent = text || '';
			notice.className = 'anar-notice' + (type ? ' is-' + type : '');
			notice.hidden = !text;
		};

		const cooldownLeft = () => Math.max(0, Math.ceil((deadline - Date.now()) / 1000));

		const showStep = (step) => {
			const isCode = step === 'code';
			identityForm.hidden = isCode;
			codeForm.hidden = !isCode;
			card.classList.toggle('is-code-step', isCode);
			(isCode ? codeInput : identityInput).focus({ preventScroll: true });
		};

		// A deadline instead of a decrementing counter stays correct when background tabs throttle timers.
		const countdown = (seconds) => {
			window.clearInterval(interval);
			deadline = Date.now() + (Number(seconds) || 60) * 1000;
			const tick = () => {
				const left = cooldownLeft();
				resend.disabled = left > 0;
				resendTimer.textContent = left > 0 ? formatSeconds(left) : '';
				if (!left) window.clearInterval(interval);
			};
			tick();
			interval = window.setInterval(tick, 500);
		};

		const stopOtpListener = () => {
			if (otpRequest) otpRequest.abort();
			otpRequest = null;
		};

		// Android Chrome fills the code when the SMS ends with "@domain #code" (WebOTP).
		const listenForOtp = () => {
			if (!('OTPCredential' in window) || !window.AbortController) return;
			stopOtpListener();
			otpRequest = new AbortController();
			navigator.credentials.get({ otp: { transport: ['sms'] }, signal: otpRequest.signal })
				.then((otp) => {
					if (otp && otp.code && !codeForm.hidden) {
						codeInput.value = otp.code;
						codeInput.dispatchEvent(new Event('input'));
					}
				})
				.catch(() => {});
		};

		const sendCode = async (trigger) => {
			loading(trigger, true);
			message('');
			let result;
			try {
				result = await request('auth/request', { identity, website: honeypot.value });
			} catch (error) {
				loading(trigger, false);
				message(error.message, 'error');
				return;
			}
			loading(trigger, false);
			sentTo = identity;
			sentLabel.textContent = identity;
			codeInput.value = '';
			codeInput.classList.remove('is-invalid');
			showStep('code');
			message(result.message, 'success');
			countdown(result.resend_after);
			listenForOtp();
		};

		const verify = async () => {
			if (verifying) return;
			const code = latinDigits(codeInput.value).replace(/\D/g, '');
			if (code.length !== otpLength) {
				message(strings.codeRequired, 'error');
				codeInput.focus();
				return;
			}

			verifying = true;
			loading(codeSubmit, true);
			message('');
			try {
				const result = await request('auth/verify', {
					identity: sentTo,
					code,
					remember: remember ? remember.checked : true,
					redirect_to: cardRedirect()
				});
				stopOtpListener();
				window.clearInterval(interval);
				card.classList.add('is-success');
				message(result.message, 'success');
				// The button stays busy while the browser navigates, so the used code cannot be resubmitted.
				loading(codeSubmit, true, strings.redirecting);
				window.location.assign(result.redirect || window.location.href);
			} catch (error) {
				verifying = false;
				loading(codeSubmit, false);
				message(error.message, 'error');
				codeInput.classList.remove('is-invalid');
				void codeInput.offsetWidth; // Restart the shake animation.
				codeInput.classList.add('is-invalid');
				if (error.code === 'anar_too_many_attempts') codeInput.value = '';
				codeInput.select();
			}
		};

		identityForm.addEventListener('submit', (event) => {
			event.preventDefault();
			identity = latinDigits(identityInput.value).trim();
			if (!identity) {
				message(strings.identityRequired, 'error');
				identityInput.focus();
				return;
			}
			// Coming back with the same details during the cooldown reopens the code step instead of failing.
			if (identity === sentTo && cooldownLeft() > 0) {
				message('');
				showStep('code');
				return;
			}
			sendCode(identitySubmit);
		});

		codeForm.addEventListener('submit', (event) => {
			event.preventDefault();
			verify();
		});

		codeForm.querySelector('.anar-auth__back').addEventListener('click', () => {
			stopOtpListener();
			message('');
			showStep('identity');
			identityInput.select();
		});

		resend.addEventListener('click', () => {
			if (resend.disabled) return;
			identity = sentTo;
			sendCode(resend);
		});

		codeInput.addEventListener('input', () => {
			const digits = latinDigits(codeInput.value).replace(/\D/g, '').slice(0, otpLength);
			if (codeInput.value !== digits) codeInput.value = digits;
			codeInput.classList.remove('is-invalid');
			if (digits.length === otpLength) verify();
		});

		if (google) {
			google.addEventListener('click', () => {
				const url = new URL(google.href);
				if (cardRedirect()) url.searchParams.set('redirect', cardRedirect());
				else url.searchParams.delete('redirect');
				url.searchParams.set('return', window.location.href);
				google.href = url.toString();
				google.classList.add('is-loading');
				google.setAttribute('aria-busy', 'true');
			});
		}

		// Returning through the back/forward cache must not leave the Google button spinning.
		window.addEventListener('pageshow', () => {
			if (google) {
				google.classList.remove('is-loading');
				google.removeAttribute('aria-busy');
			}
		});
	});

	document.querySelectorAll('.anar-panel').forEach((panel) => {
		if (panel.dataset.anarReady) return;
		panel.dataset.anarReady = '1';
		const activate = (name) => {
			panel.querySelectorAll('[data-anar-tab]').forEach((button) => {
				const active = button.dataset.anarTab === name;
				button.classList.toggle('is-active', active);
				if (active) button.setAttribute('aria-current', 'page');
				else button.removeAttribute('aria-current');
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
					const result = await request('profile', data, true);
					status.textContent = result.message;
					status.className = 'anar-form-status is-success';
					if (result.user && result.user.display_name) {
						panel.querySelectorAll('[data-anar-display-name]').forEach((element) => {
							element.textContent = result.user.display_name;
						});
					}
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
