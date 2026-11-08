(function () {
	'use strict';
	let active = null;
	function position(help) {
		const text = help.querySelector('[role="tooltip"]');
		if (!text) return;
		const anchor = help.getBoundingClientRect();
		const bounds = text.getBoundingClientRect();
		text.style.left = Math.max(12, Math.min(anchor.left, document.documentElement.clientWidth - bounds.width - 12)) + 'px';
		text.style.right = 'auto';
		text.style.top = (anchor.bottom + bounds.height + 12 <= window.innerHeight ? anchor.bottom + 6 : Math.max(12, anchor.top - bounds.height - 6)) + 'px';
	}
	// Delegation also covers editors loaded into the shared message dialog.
	['mouseover', 'focusin', 'click'].forEach(function (name) {
		document.addEventListener(name, function (event) {
			const help = event.target.closest('.anar-help');
			if (!help) return;
			active = help;
			if (name === 'click') help.classList.remove('is-dismissed');
			position(help);
		});
	});
	document.addEventListener('mouseout', function (event) {
		const help = event.target.closest('.anar-help');
		if (help && !help.contains(event.relatedTarget) && !help.matches(':focus-within')) help.classList.remove('is-dismissed');
	});
	document.addEventListener('focusout', function (event) {
		const help = event.target.closest('.anar-help');
		if (help) help.classList.remove('is-dismissed');
	});
	document.addEventListener('keydown', function (event) {
		const help = event.target.closest('.anar-help');
		if (event.key === 'Escape' && help && !help.classList.contains('is-dismissed')) {
			help.classList.add('is-dismissed'); event.preventDefault(); event.stopPropagation();
		}
	});
	function update() { if (active && active.isConnected && active.matches(':hover, :focus-within')) position(active); }
	window.addEventListener('resize', update);
	window.addEventListener('scroll', update, true);
})();
