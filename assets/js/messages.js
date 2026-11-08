(function () {
	'use strict';
	const form = document.querySelector('[data-message-editor]');
	if (!form) return;
	const source = form.querySelector('[data-mapping-source]');
	const rows = form.querySelector('[data-mapping-rows]');
	const template = form.querySelector('[data-mapping-template]');
	const provider = form.querySelector('[data-message-provider]');
	const mode = form.querySelector('[data-message-mode]');
	const inherit = form.querySelector('[name="value[inherit]"]');
	const message = form.querySelector('[data-message-text]');
	const samples = JSON.parse(form.dataset.samples);
	let dirty = false;
	function serialize() {
		source.value = Array.from(rows.children).map(function (row) { const inputs = row.querySelectorAll('input'); return inputs[0].value.trim() + '=' + inputs[1].value.trim(); }).filter(function (line) { return line !== '='; }).join('\n');
	}
	function add(name, value) {
		const row = template.content.firstElementChild.cloneNode(true);
		const inputs = row.querySelectorAll('input'); inputs[0].value = name || ''; inputs[1].value = value || '';
		row.querySelector('[data-remove]').addEventListener('click', function () { row.remove(); serialize(); dirty = true; });
		row.querySelector('[data-up]').addEventListener('click', function () { if (row.previousElementSibling) rows.insertBefore(row, row.previousElementSibling); serialize(); dirty = true; });
		row.querySelector('[data-down]').addEventListener('click', function () { if (row.nextElementSibling) rows.insertBefore(row.nextElementSibling, row); serialize(); dirty = true; });
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
		form.querySelector('[data-message-preview]').textContent = message.value.replace(/\{([a-zA-Z0-9_]+)\}/g, function (match, name) { return Object.prototype.hasOwnProperty.call(samples, name) ? samples[name] : match; });
	}
	build(); render();
	form.querySelector('[data-add-mapping]').addEventListener('click', function () { add(); render(); dirty = true; });
	source.addEventListener('change', function () { build(); render(); });
	form.addEventListener('input', function () { dirty = true; render(); });
	form.addEventListener('change', render);
	form.addEventListener('submit', function () { dirty = false; });
	window.addEventListener('beforeunload', function (event) { if (dirty) { event.preventDefault(); event.returnValue = ''; } });
})();
