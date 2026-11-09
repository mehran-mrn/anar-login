(function () {
	'use strict';

	var config = window.AnarLoginPopup || {};
	var manual = 'a[href$="#anar-login"], .anar-login-trigger';
	var loading = null;
	var dialog = null;
	var status = null;
	var content = null;
	var loaded = false;
	var destination = '';
	var fallback = '';

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
		if (config.loginPath && target === config.loginPath && !url.searchParams.has('anar_password') && (!action || action === 'login' || action === 'register')) {
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

	var closeDialog = function () {
		if (typeof dialog.close === 'function') dialog.close();
		else dialog.removeAttribute('open');
	};

	var prepareDialog = function () {
		if (dialog) return;

		dialog = document.createElement('dialog');
		dialog.className = 'anar-modal';
		dialog.setAttribute('aria-label', config.title || '');
		dialog.dir = document.documentElement.dir || 'ltr';
		// The loading shell is ready even before the deferred form stylesheet arrives.
		Object.assign(dialog.style, {
			width: 'min(calc(100% - 24px), 490px)', maxWidth: 'none', padding: '0', border: '0',
			margin: 'auto', borderRadius: '28px', background: '#fff', boxShadow: '0 18px 55px #0003'
		});

		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'anar-modal__close';
		close.setAttribute('aria-label', config.close || 'Close');
		close.textContent = '×';
		Object.assign(close.style, {
			position: 'absolute', top: '14px', insetInlineEnd: '14px', display: 'grid', placeItems: 'center',
			width: '34px', height: '34px', padding: '0', border: '0', borderRadius: '50%',
			color: '#5c4f55', background: '#f5f3f4', font: '400 22px/1 Arial, sans-serif', cursor: 'pointer'
		});
		close.addEventListener('click', closeDialog);
		dialog.addEventListener('click', function (event) {
			if (event.target === dialog) closeDialog();
		});

		status = document.createElement('div');
		status.id = 'anar-popup-loading';
		status.setAttribute('role', 'status');
		dialog.setAttribute('aria-describedby', status.id);
		Object.assign(status.style, {
			display: 'grid', placeItems: 'center', gap: '12px', padding: '54px 22px',
			color: '#20151a', font: 'inherit', textAlign: 'center'
		});
		var progress = document.createElement('progress');
		progress.setAttribute('aria-hidden', 'true');
		progress.style.width = '64px';
		progress.style.accentColor = config.brandColor || '#d81b3f';
		status.append(progress, config.loading || 'Loading…');

		content = document.createElement('div');
		content.style.display = 'none';
		dialog.append(close, status, content);
		document.body.appendChild(dialog);
	};

	var focusForm = function () {
		var card = content.querySelector('.anar-login-card');
		if (card) card.dataset.redirect = destination || window.location.href.split('#')[0];
		var input = content.querySelector('input[name="identity"]');
		if (input) input.focus({ preventScroll: true });
	};

	var load = function () {
		if (loading) return loading;
		// Start the downloads while the server renders the HTML. Execute after insertion.
		var preloads = [];
		[[config.css, 'style'], [config.js, 'script']].forEach(function (asset) {
			if (!asset[0]) return;
			var link = document.createElement('link');
			link.rel = 'preload';
			link.href = asset[0];
			link.as = asset[1];
			document.head.appendChild(link);
			preloads.push(link);
		});
		var url = config.formUrl + (config.formUrl.indexOf('?') < 0 ? '?' : '&') + 'redirect=' + encodeURIComponent(window.location.href.split('#')[0]);
		loading = fetch(url, { credentials: 'same-origin' })
			.then(function (response) {
				if (!response.ok) throw new Error(String(response.status));
				return response.json();
			})
			.then(function (data) {
				window.AnarLogin = data.config;
				prepareDialog();
				content.innerHTML = data.html;

				var ready = [];
				if (dialog.querySelector('[data-anar-password]')) {
					if (!document.querySelector('link[rel="stylesheet"][href^="' + data.resetCss.split('?')[0] + '"]')) {
						var resetStyle = document.createElement('link'); resetStyle.rel = 'stylesheet'; resetStyle.href = data.resetCss; document.head.appendChild(resetStyle);
					}
					if (window.AnarPasswordInit) window.AnarPasswordInit();
					else ready.push(new Promise(function (resolve, reject) { var resetScript = document.createElement('script'); resetScript.src = data.resetJs; resetScript.onload = resolve; resetScript.onerror = reject; document.body.appendChild(resetScript); }));
				}
				if (!document.querySelector('link[rel="stylesheet"][href^="' + data.css.split('?')[0] + '"]')) {
					ready.push(new Promise(function (resolve, reject) {
						var link = document.createElement('link');
						link.rel = 'stylesheet';
						link.href = data.css;
						link.onload = resolve;
						link.onerror = function (error) {
							link.remove();
							reject(error);
						};
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
			.then(function () {
				loaded = true;
				status.remove();
				dialog.removeAttribute('aria-describedby');
				dialog.removeAttribute('style');
				dialog.querySelector('.anar-modal__close').removeAttribute('style');
				content.removeAttribute('style');
				if (dialog.open) focusForm();
			})
			.catch(function (error) {
				var wasOpen = dialog && dialog.open;
				if (dialog) dialog.remove();
				dialog = null;
				loading = null;
				if (wasOpen) window.location.href = fallback || config.accountUrl;
				throw error;
			})
			.finally(function () {
				preloads.forEach(function (link) { link.remove(); });
			});
		return loading;
	};

	var trigger = function (found, fallbackUrl) {
		if (config.loggedIn) {
			window.location.href = config.accountUrl;
			return;
		}
		prepareDialog();
		if (dialog.open) return;
		destination = found.redirect;
		fallback = fallbackUrl;
		dialog.classList.toggle('anar-modal--loading-open', !loaded);
		if (typeof dialog.showModal === 'function') dialog.showModal();
		else dialog.setAttribute('open', '');
		if (loaded) focusForm();
		else load().catch(function () {});
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
