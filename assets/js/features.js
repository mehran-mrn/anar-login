(function () {
	'use strict';
	const config = window.AnarFeatures || {};
	document.querySelectorAll('.anar-features form').forEach(function (form) {
		const controls = [...form.querySelectorAll('input[role="switch"]')];
		controls.forEach(function (input) {
			input.dataset.saved = input.checked ? '1' : '0';
			input.addEventListener('change', async function () {
				const card = input.closest('.anar-feature-card');
				const result = card.querySelector('.anar-feature-feedback');
				const previous = input.dataset.saved === '1';
				let confirmed = previous;
				let saved = false;
				const key = input.name.slice(9, -1);
				const data = new URLSearchParams({action:'anar_feature_toggle', nonce:config.nonce, key, enabled:input.checked ? '1' : '0', expected:previous ? '1' : '0'});
				controls.forEach(control => { control.disabled = true; });
				card.setAttribute('aria-busy', 'true');
				result.hidden = false; result.textContent = config.saving; result.classList.remove('is-error');
				try {
					const response = await fetch(config.url, {method:'POST', credentials:'same-origin', body:data});
					const body = await response.json().catch(() => null);
					if (!response.ok || !body?.success) {
						confirmed = typeof body?.data?.enabled === 'boolean' ? body.data.enabled : previous;
						throw new Error(body?.data?.text || config.failed);
					}
					input.checked = body.data.enabled;
					confirmed = body.data.enabled;
					let link = card.querySelector('.anar-feature-configure');
					if (!link && input.checked) {
						link = document.createElement('a'); link.className = 'button anar-feature-configure';
						link.textContent = config.configure;
						const arrow = document.createElement('span'); arrow.className = 'dashicons dashicons-arrow-' + (document.documentElement.dir === 'rtl' ? 'left' : 'right') + '-alt2'; arrow.setAttribute('aria-hidden','true');
						link.append(arrow); card.querySelector('footer').append(link);
					}
					if (link) { link.href = body.data.url; link.hidden = !input.checked; }
					const group = form.querySelector(input.checked ? '#active .anar-feature-grid' : '#other .anar-feature-grid');
					if (group) group.append(card);
					result.textContent = config.saved;
					saved = true;
				} catch (error) {
					input.checked = confirmed;
					result.textContent = error.message || config.failed; result.classList.add('is-error');
				} finally {
					input.dataset.saved = input.checked ? '1' : '0'; card.classList.toggle('is-active', input.checked);
					controls.forEach(control => { control.disabled = false; }); card.removeAttribute('aria-busy'); input.focus({preventScroll:true});
					if (saved) document.dispatchEvent(new CustomEvent('anar:feature-saved', {detail:{form}}));
				}
			});
		});
	});
})();
