(function () {
	'use strict';
	document.querySelectorAll('.anar-help').forEach(function (help) {
		const text = help.querySelector('[role="tooltip"]');
		function position() {
			const anchor = help.getBoundingClientRect();
			const bounds = text.getBoundingClientRect();
			const left = Math.max(12, Math.min(anchor.left, document.documentElement.clientWidth - bounds.width - 12));
			const top = anchor.bottom + bounds.height + 12 <= window.innerHeight ? anchor.bottom + 6 : Math.max(12, anchor.top - bounds.height - 6);
			text.style.left = left + 'px'; text.style.right = 'auto'; text.style.top = top + 'px';
		}
		help.addEventListener('mouseenter', position);
		help.addEventListener('focusin', position);
		window.addEventListener('resize', function () { if (help.matches(':hover, :focus-within')) position(); });
		window.addEventListener('scroll', function () { if (help.matches(':hover, :focus-within')) position(); }, true);
		help.addEventListener('keydown', function (event) {
			if (event.key === 'Escape') { help.classList.add('is-dismissed'); event.stopPropagation(); }
		});
		help.addEventListener('mouseleave', function () { if (!help.matches(':focus-within')) help.classList.remove('is-dismissed'); });
		help.addEventListener('focusout', function () { help.classList.remove('is-dismissed'); });
		help.querySelector('button').addEventListener('click', function () { help.classList.remove('is-dismissed'); position(); });
	});
})();
