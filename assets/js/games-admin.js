(function () {
	'use strict';
	const preview = document.querySelector('[data-anar-game-preview]');
	if (!preview) return;
	const skin = document.getElementById('anar-game-game_skin');
	const color = document.getElementById('anar-game-game_color');
	function refresh() {
		preview.classList.remove('anar-skin-berry', 'anar-skin-ocean', 'anar-skin-forest');
		if (skin && ['berry', 'ocean', 'forest'].includes(skin.value)) preview.classList.add('anar-skin-' + skin.value);
		if (color && /^#[0-9a-f]{6}$/i.test(color.value)) preview.style.setProperty('--game-accent', color.value);
		else preview.style.removeProperty('--game-accent');
	}
	if (skin) skin.addEventListener('change', refresh);
	if (color) color.addEventListener('input', refresh);
})();
