(function () {
	'use strict';

	var config = window.AnarLoginPopup || {};
	var manual = 'a[href$="#anar-login"], .anar-login-trigger';
	var loading = null;
	var dialog = null;

	var path = function (url) {
		return url.pathname.replace(/\/+$/, '');
	};

	// Login entry points of the theme or WooCommerce, replaced while signed out.
	var automatic = function (element) {
		if (!config.replace || config.loggedIn) return null;
		if (element.closest('.login-side-opener')) return { redirect: '' };

		var link = element.closest('a[href]');
		if (!link || link.target === '_blank') return null;
		var url;
		try {
			url = new URL(link.href, window.location.href);
		} catch (error) {
			return null;
		}
		if (url.origin !== window.location.origin) return null;

		var target = path(url);
		var action = url.searchParams.get('action') || '';
		if (config.loginPath && target === config.loginPath && (!action || action === 'login' || action === 'register')) {
			return { redirect: url.searchParams.get('redirect_to') || '' };
		}
		if (config.accountPath && (target === config.accountPath || target.indexOf(config.accountPath + '/') === 0) && target.indexOf('lost-password') < 0) {
			// Already on the account page, which shows the form itself.
			if (path(window.location) === config.accountPath) return null;
			url.searchParams.delete('action');
			return { redirect: url.toString() };
		}
		return null;
	};

	var match = function (element) {
		if (!element || !element.closest) return null;
		var trigger = element.closest(manual);
		if (trigger) return { redirect: '', manual: true };
		return automatic(element);
	};

	var load = function () {
		if (loading) return loading;
		var url = config.formUrl + (config.formUrl.indexOf('?') < 0 ? '?' : '&') + 'redirect=' + encodeURIComponent(window.location.href.split('#')[0]);
		loading = fetch(url, { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) throw new Error(String(response.status));
				return response.json();
			})
			.then(function (data) {
				window.AnarLogin = data.config;
				dialog = document.createElement('dialog');
				dialog.className = 'anar-modal';
				dialog.setAttribute('aria-label', config.title || '');
				dialog.innerHTML = '<button type="button" class="anar-modal__close" aria-label="' + (config.close || 'Close') + '">&times;</button>' + data.html;
				dialog.querySelector('.anar-modal__close').addEventListener('click', function () { dialog.close(); });
				// A click on the backdrop lands on the dialog element itself.
				dialog.addEventListener('click', function (event) {
					if (event.target === dialog) dialog.close();
				});
				document.body.appendChild(dialog);

				var ready = [];
				if (dialog.querySelector('[data-anar-password]')) {
					if (!document.querySelector('link[href^="' + data.resetCss.split('?')[0] + '"]')) {
						var resetStyle = document.createElement('link'); resetStyle.rel = 'stylesheet'; resetStyle.href = data.resetCss; document.head.appendChild(resetStyle);
					}
					if (window.AnarPasswordInit) window.AnarPasswordInit();
					else ready.push(new Promise(function (resolve, reject) { var resetScript = document.createElement('script'); resetScript.src = data.resetJs; resetScript.onload = resolve; resetScript.onerror = reject; document.body.appendChild(resetScript); }));
				}
				if (!document.querySelector('link[href^="' + data.css.split('?')[0] + '"]')) {
					ready.push(new Promise(function (resolve) {
						var link = document.createElement('link');
						link.rel = 'stylesheet';
						link.href = data.css;
						link.onload = link.onerror = resolve;
						document.head.appendChild(link);
					}));
				}
				ready.push(new Promise(function (resolve, reject) {
					var script = document.createElement('script');
					script.src = data.js;
					script.onload = resolve;
					script.onerror = reject;
					document.body.appendChild(script);
				}));
				return Promise.all(ready);
			})
			.catch(function (error) {
				loading = null;
				throw error;
			});
		return loading;
	};

	var open = function (redirect) {
		if (!dialog) return;
		var card = dialog.querySelector('.anar-login-card');
		// Return to the page that was asked for, otherwise stay on this one.
		if (card) card.dataset.redirect = redirect || window.location.href.split('#')[0];
		if (typeof dialog.showModal === 'function') dialog.showModal();
		else dialog.setAttribute('open', '');
		var input = dialog.querySelector('input[name="identity"]');
		if (input) input.focus();
	};

	var trigger = function (found, fallback) {
		if (config.loggedIn) {
			window.location.href = config.accountUrl;
			return;
		}
		load().then(function () { open(found.redirect); }).catch(function () {
			window.location.href = fallback || config.accountUrl;
		});
	};

	// Capture phase, so theme scripts (dropdowns, side panels) never see the click.
	document.addEventListener('click', function (event) {
		if (event.defaultPrevented || event.button > 0 || event.metaKey || event.ctrlKey || event.shiftKey) return;
		var found = match(event.target);
		if (!found) return;
		event.preventDefault();
		event.stopImmediatePropagation();
		var link = event.target.closest('a[href]');
		trigger(found, link && !found.manual ? link.href : '');
	}, true);

	// Warm up on hover or keyboard focus so the popup opens instantly.
	var warm = function (event) {
		if (!config.loggedIn && match(event.target)) load().catch(function () {});
	};
	document.addEventListener('pointerover', warm, { passive: true });
	document.addEventListener('focusin', warm);

	if (window.location.hash === '#anar-login') trigger({ redirect: '' });
})();
