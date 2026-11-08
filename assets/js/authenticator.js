(function () {
	'use strict';
	if (!window.AnarQr) return;
	document.querySelectorAll('[data-authenticator-uri]').forEach((container) => {
		try {
			const qr = window.AnarQr.QrCode.encodeText(container.dataset.authenticatorUri, window.AnarQr.QrCode.Ecc.MEDIUM);
			const namespace = 'http://www.w3.org/2000/svg';
			const svg = document.createElementNS(namespace, 'svg');
			svg.setAttribute('viewBox', `0 0 ${qr.size + 8} ${qr.size + 8}`);
			svg.setAttribute('aria-hidden', 'true');
			svg.setAttribute('shape-rendering', 'crispEdges');
			const path = document.createElementNS(namespace, 'path');
			const cells = [];
			for (let y = 0; y < qr.size; y++) {
				for (let x = 0; x < qr.size; x++) if (qr.getModule(x, y)) cells.push(`M${x + 4},${y + 4}h1v1h-1z`);
			}
			path.setAttribute('d', cells.join(' '));
			path.setAttribute('fill', '#000');
			svg.appendChild(path);
			container.appendChild(svg);
			container.hidden = false;
		} catch (error) {
			// The manual setup key remains usable. Never log the provisioning secret.
		}
	});
})();
