(function () {
	'use strict';

	const root = document.querySelector('.anar-admin');
	if (!root) return;

	const showTab = (name) => {
		root.querySelectorAll('[data-tab]').forEach((button) => {
			button.classList.toggle('is-active', button.dataset.tab === name);
		});
		root.querySelectorAll('[data-panel]').forEach((panel) => {
			const active = panel.dataset.panel === name;
			panel.hidden = !active;
			panel.classList.toggle('is-active', active);
		});
		window.sessionStorage.setItem('anarAdminTab', name);
	};

	root.querySelectorAll('[data-tab]').forEach((button) => {
		button.addEventListener('click', () => showTab(button.dataset.tab));
	});

	const savedTab = window.sessionStorage.getItem('anarAdminTab');
	if (savedTab && root.querySelector(`[data-panel="${savedTab}"]`)) showTab(savedTab);

	root.querySelectorAll('.anar-provider input[type="radio"]').forEach((radio) => {
		radio.addEventListener('change', () => {
			root.querySelectorAll('.anar-provider').forEach((item) => item.classList.remove('is-selected'));
			radio.closest('.anar-provider').classList.add('is-selected');
			root.querySelectorAll('.anar-provider-settings').forEach((panel) => {
				panel.hidden = panel.dataset.provider !== radio.value;
			});
		});
	});

	root.querySelectorAll('[data-copy]').forEach((button) => {
		button.addEventListener('click', async () => {
			const original = button.textContent;
			try {
				await navigator.clipboard.writeText(button.dataset.copy);
				button.textContent = 'کپی شد ✓';
			} catch (error) {
				button.textContent = 'ناموفق';
			}
			window.setTimeout(() => { button.textContent = original; }, 1500);
		});
	});

	const color = root.querySelector('#anar-color');
	if (color) {
		color.addEventListener('input', () => {
			const code = color.closest('.anar-color').querySelector('code');
			if (code) code.textContent = color.value;
		});
	}
})();
