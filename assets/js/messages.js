(function () {
	'use strict';
	const config = window.AnarMessages;
	const dialog = document.querySelector('[data-message-dialog]');
	if (!config || !dialog || !dialog.showModal) return;
	const body = dialog.querySelector('[data-message-body]');
	const title = dialog.querySelector('h2');
	const feedback = dialog.querySelector('[data-message-feedback]');
	const closeButton = dialog.querySelector('[data-message-close]');
	const unsaved = dialog.querySelector('[data-message-unsaved]');
	const reloadButton = dialog.querySelector('[data-message-reload]');
	let selection = null;
	let pendingLeave = null;
	let editor = null;
	let opener = null;
	let controller = null;
	let busy = false;
	let restoringHistory = false;
	const parents = new Map();

	function snapshot(form) {
		return JSON.stringify(Array.from(form.elements).filter(function (field) {
			return field.name && !['_wpnonce', '_wp_http_referer', 'revision', 'test_phone', 'operation', 'submit'].includes(field.name) && !['submit', 'button'].includes(field.type) && (!['checkbox', 'radio'].includes(field.type) || field.checked);
		}).map(function (field) { return [field.name, field.value]; }));
	}
	function report(text, success, target) {
		const element = target || feedback;
		element.textContent = text;
		element.classList.toggle('is-success', !!success);
		element.classList.toggle('is-error', success === false);
		element.hidden = !text;
	}
	function canLeave(action) {
		if (busy) return false;
		if (editor && editor.dirty()) {
			pendingLeave = action; unsaved.hidden = false;
			unsaved.querySelector('[data-message-keep]').focus();
			return false;
		}
		return true;
	}
	function close() {
		if (controller) controller.abort();
		dialog.close();
		document.documentElement.classList.remove('anar-message-modal-open');
		body.replaceChildren(); editor = null; unsaved.hidden = true; pendingLeave = null;
		if (opener && opener.isConnected) opener.focus({ preventScroll: true });
	}
	function requestClose() {
		if (!canLeave(requestClose)) return;
		editor = null;
		if (window.history.state && window.history.state.anarMessageEditor) window.history.back();
		else {
			const address = new URL(window.location.href);
			address.searchParams.delete('edit_message'); address.searchParams.delete('message'); address.searchParams.delete('variant');
			window.history.replaceState(window.history.state, '', address);
			close();
		}
	}
	function addressFor(id, variant, replace) {
		const address = new URL(window.location.href);
		address.searchParams.set('edit_message', id); address.searchParams.delete('message');
		if (variant) address.searchParams.set('variant', variant);
		else address.searchParams.delete('variant');
		if (replace) window.history.replaceState(window.history.state, '', address);
		else window.history.pushState(Object.assign({}, window.history.state, { anarMessageEditor: true }), '', address);
	}
	async function open(id, variant, options) {
		options = options || {};
		if (!options.skipConfirm && !canLeave(function () { open(id, variant, Object.assign({}, options, { skipConfirm: true })); })) return;
		if (controller) controller.abort();
		controller = new AbortController();
		const signal = controller.signal;
		if (!dialog.open) {
			opener = document.activeElement;
			dialog.showModal();
			document.documentElement.classList.add('anar-message-modal-open');
		}
		editor = null; unsaved.hidden = true; pendingLeave = null;
		selection = { id: id, variant: variant }; reloadButton.hidden = true;
		body.replaceChildren(); body.setAttribute('aria-busy', 'true');
		title.textContent = config.loading;
		report(''); title.focus();
		if (options.history !== false) addressFor(id, variant, !!options.replace);
		try {
			const endpoint = new URL(config.ajaxUrl);
			endpoint.search = new URLSearchParams({ action: 'anar_message_editor', nonce: config.nonce, id: id });
			if (variant) endpoint.searchParams.set('variant', variant);
			const response = await fetch(endpoint, { credentials: 'same-origin', signal: signal });
			const result = await response.json();
			if (signal.aborted) return;
			if (!response.ok || !result.success) throw new Error(result.data && result.data.text || config.network);
			title.textContent = result.data.title;
			// Authenticated, same-origin PHP renders and escapes this HTML.
			body.innerHTML = result.data.html;
			editor = initialize(body.querySelector('[data-message-editor]'));
			document.dispatchEvent(new CustomEvent('anar:content-ready', { detail: { root: body } }));
		} catch (error) {
			if (error.name !== 'AbortError') { report(error.message || config.network, false); reloadButton.hidden = false; }
		} finally {
			if (!signal.aborted) body.removeAttribute('aria-busy');
		}
	}
	function initialize(form) {
		const source = form.querySelector('[data-mapping-source]');
		const rows = form.querySelector('[data-mapping-rows]');
		const template = form.querySelector('[data-mapping-template]');
		const provider = form.querySelector('[data-message-provider]');
		const mode = form.querySelector('[data-message-mode]');
		const inherit = form.querySelector('[name="value[inherit]"]');
		const message = form.querySelector('[data-message-text]');
		const samples = JSON.parse(form.dataset.samples);
		let baseline;
		function serialize() {
			source.value = Array.from(rows.children).map(function (row) {
				const inputs = row.querySelectorAll('input');
				return inputs[0].value.trim() + '=' + inputs[1].value.trim();
			}).filter(function (line) { return line !== '='; }).join('\n');
		}
		function add(name, value) {
			const row = template.content.firstElementChild.cloneNode(true);
			const inputs = row.querySelectorAll('input'); inputs[0].value = name || ''; inputs[1].value = value || '';
			row.querySelector('[data-remove]').addEventListener('click', function () {
				const next = row.nextElementSibling || row.previousElementSibling;
				row.remove(); serialize();
				(next ? next.querySelector('input') : form.querySelector('[data-add-mapping]')).focus();
			});
			row.querySelector('[data-up]').addEventListener('click', function () { if (row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling); serialize(); });
			row.querySelector('[data-down]').addEventListener('click', function () { if (row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row); serialize(); });
			row.addEventListener('input', serialize); rows.appendChild(row);
		}
		function build() {
			rows.replaceChildren();
			source.value.split(/\r?\n/).filter(Boolean).forEach(function (line) { const split = line.indexOf('='); add(split < 0 ? line : line.slice(0, split), split < 0 ? '' : line.slice(split + 1)); });
			if (!rows.children.length) add();
		}
		function render() {
			const textAllowed = ['custom', 'melipayamak'].includes(provider.value);
			if (!textAllowed && mode.value === 'text') mode.value = 'template';
			if (provider.value === 'custom') mode.value = 'text';
			mode.querySelector('[value="text"]').disabled = !textAllowed;
			form.querySelectorAll('[data-template-field]').forEach(function (field) { field.hidden = mode.value === 'text'; });
			form.querySelectorAll('[data-message-fields] input, [data-message-fields] textarea, [data-message-fields] select, [data-message-fields] button').forEach(function (field) { field.disabled = !!(inherit && inherit.checked && !field.closest('.anar-help')); });
			const label = form.querySelector('[data-message-text-label]');
			label.textContent = mode.value === 'text' ? label.dataset.textLabel : label.dataset.templateLabel;
			form.querySelector('[data-message-preview]').textContent = message.value.replace(/\{([a-zA-Z0-9_]+)\}/g, function (match, name) { return Object.prototype.hasOwnProperty.call(samples, name) ? samples[name] : match; });
		}
		build(); render(); baseline = snapshot(form);
		form.querySelector('[data-add-mapping]').addEventListener('click', function () { add(); render(); rows.lastElementChild.querySelector('input').focus(); });
		source.addEventListener('change', function () { build(); render(); });
		form.addEventListener('input', render); form.addEventListener('change', render);
		form.addEventListener('submit', async function (event) {
			event.preventDefault();
			if (busy) return;
			const operation = event.submitter && event.submitter.value || 'save';
			if (operation === 'test' && snapshot(form) !== baseline) { report(config.saveFirst, false); return; }
			unsaved.hidden = true; pendingLeave = null;
			const data = new FormData(form);
			// Disabled inherited fields retain their effective values when submitted.
			Array.from(form.elements).filter(function (field) { return field.disabled && field.name; }).forEach(function (field) { data.set(field.name, field.value); });
			data.set('action', 'anar_message_save'); data.set('operation', operation);
			busy = true; form.inert = true; form.setAttribute('aria-busy', 'true');
			const buttons = Array.from(form.querySelectorAll('[type="submit"]'));
			buttons.forEach(function (button) { button.disabled = true; }); closeButton.disabled = true;
			report(operation === 'test' ? config.sending : config.saving);
			try {
				const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
				const result = await response.json();
				if (!response.ok || !result.success) { reloadButton.hidden = !(result.data && result.data.reload); throw new Error(result.data && result.data.text || config.network); }
				reloadButton.hidden = true;
				report(result.data.text, true);
				form.elements.revision.value = result.data.revision;
				Object.entries(result.data.value || {}).forEach(function (entry) {
					const field = form.elements.namedItem('value[' + entry[0] + ']');
					if (!field) return;
					if (field.type === 'checkbox') field.checked = !!entry[1]; else field.value = entry[1];
				});
				build(); render();
				baseline = snapshot(form); updateSummary(result.data);
				if (operation === 'return') { busy = false; requestClose(); }
			} catch (error) { report(error.message || config.network, false); }
			finally {
				busy = false; form.inert = false; form.removeAttribute('aria-busy');
				buttons.forEach(function (button) { button.disabled = false; }); closeButton.disabled = false;
				if (dialog.open && event.submitter && event.submitter.isConnected) event.submitter.focus();
			}
		});
		return { dirty: function () { return snapshot(form) !== baseline; } };
	}
	function updateSummary(data) {
		document.querySelectorAll('[data-message-summary], [data-message-row]').forEach(function (element) {
			if ((element.dataset.messageSummary || element.dataset.messageRow) !== data.id) return;
			const status = element.querySelector('[data-message-status]');
			status.textContent = data.status;
			status.classList.toggle('needs-setup', data.incomplete); status.classList.toggle('is-complete', !data.incomplete);
			const method = element.querySelector('[data-message-method]'); if (method) method.textContent = data.method;
			const provider = element.querySelector('[data-message-provider-label]'); if (provider) provider.textContent = data.provider;
		});
	}
	document.addEventListener('click', function (event) {
		const link = event.target.closest('a[href]');
		if (!link || event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
		const address = new URL(link.href);
		const id = address.searchParams.get('edit_message') || address.searchParams.get('message');
		if (!id || address.origin !== window.location.origin || !['anar-login-settings', 'anar-messages'].includes(address.searchParams.get('page'))) return;
		event.preventDefault(); open(id, address.searchParams.get('variant'), { replace: dialog.open });
	});
	closeButton.addEventListener('click', requestClose);
	reloadButton.addEventListener('click', function () { if (selection) open(selection.id, selection.variant, { replace: true }); });
	function keepEditing() { unsaved.hidden = true; pendingLeave = null; title.focus(); }
	unsaved.querySelector('[data-message-keep]').addEventListener('click', keepEditing);
	unsaved.querySelector('[data-message-discard]').addEventListener('click', function () {
		const action = pendingLeave; pendingLeave = null; editor = null; unsaved.hidden = true;
		if (action) action();
	});
	dialog.addEventListener('cancel', function (event) { event.preventDefault(); if (unsaved.hidden) requestClose(); else keepEditing(); });
	dialog.addEventListener('keydown', function (event) {
		if (event.key !== 'Tab') return;
		const controls = Array.from(dialog.querySelectorAll('a[href], button, input, select, textarea, [tabindex]')).filter(function (field) {
			return field.tabIndex >= 0 && !field.disabled && !field.closest('[inert]') && field.getClientRects().length;
		});
		const first = controls[0]; const last = controls[controls.length - 1];
		if (!first) { event.preventDefault(); title.focus(); return; }
		if (event.shiftKey && (document.activeElement === first || document.activeElement === title)) { event.preventDefault(); last.focus(); }
		else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
	});
	dialog.addEventListener('click', function (event) {
		if (event.target !== dialog) return;
		const box = dialog.getBoundingClientRect();
		if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) requestClose();
	});
	window.addEventListener('popstate', function () {
		if (restoringHistory) { restoringHistory = false; return; }
		if (dialog.open && !canLeave(function () { editor = null; window.history.back(); })) { restoringHistory = true; window.history.forward(); return; }
		const address = new URL(window.location.href);
		const id = address.searchParams.get('edit_message') || address.searchParams.get('message');
		if (id) open(id, address.searchParams.get('variant'), { history: false, skipConfirm: true });
		else if (dialog.open) close();
	});
	window.addEventListener('beforeunload', function (event) {
		if (busy || editor && editor.dirty() || Array.from(parents).some(function (entry) { return snapshot(entry[0]) !== entry[1]; })) { event.preventDefault(); event.returnValue = ''; }
	});
	const filter = document.querySelector('[data-message-filters]');
	if (filter) {
		filter.querySelector('[data-message-filter-submit]').hidden = true;
		function filterRows() {
			let count = 0;
			const search = filter.elements.s.value.trim().toLocaleLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک');
			document.querySelectorAll('[data-message-row]').forEach(function (row) {
				const text = row.dataset.search.toLocaleLowerCase().replace(/ي/g, 'ی').replace(/ك/g, 'ک');
				row.hidden = !(filter.elements.all.checked || row.dataset.active === '1') || !!(filter.elements.group.value && filter.elements.group.value !== row.dataset.group) || !text.includes(search);
				if (!row.hidden) count++;
			});
			document.querySelector('[data-message-empty]').hidden = count > 0;
		}
		filter.addEventListener('submit', function (event) { event.preventDefault(); filterRows(); });
		filter.addEventListener('input', filterRows); filter.addEventListener('change', filterRows); filterRows();
	}
	document.querySelectorAll('.anar-connection-form, [data-message-shared], .anar-commerce form[action$="options.php"], .anar-features form').forEach(function (form) {
		parents.set(form, snapshot(form));
		form.addEventListener('submit', function () { if (!form.matches('[data-message-shared]')) parents.delete(form); });
	});
	const shared = document.querySelector('[data-message-shared]');
	if (shared) shared.addEventListener('submit', async function (event) {
		event.preventDefault();
		const button = shared.querySelector('[type="submit"]'); if (button.disabled) return;
		const data = new FormData(shared); data.set('action', 'anar_message_save');
		const target = shared.querySelector('[data-shared-feedback]'); button.disabled = true; shared.inert = true;
		try {
			const response = await fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: data });
			const result = await response.json();
			if (!response.ok || !result.success) throw new Error(result.data && result.data.text || config.network);
			Object.entries(result.data.values || {}).forEach(function (entry) { const field = shared.elements.namedItem('shared[' + entry[0] + ']'); if (field) field.value = entry[1]; });
			parents.set(shared, snapshot(shared)); report(result.data.text, true, target);
		} catch (error) { report(error.message || config.network, false, target); }
		finally { button.disabled = false; shared.inert = false; button.focus(); }
	});
	const initial = new URL(window.location.href);
	const initialId = initial.searchParams.get('edit_message') || initial.searchParams.get('message');
	if (initialId) open(initialId, initial.searchParams.get('variant'), { history: false });
})();
