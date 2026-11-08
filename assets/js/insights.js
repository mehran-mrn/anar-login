(function () {
	'use strict';
	if (!window.AnarInsights) return;
	let sent = false;
	function record() {
		if (sent || document.visibilityState !== 'visible') return; sent = true;
		try {
			const key = 'anar-visit'; let stored; try { stored = JSON.parse(localStorage.getItem(key) || 'null'); } catch (error) { stored = null; }
			if (!stored || stored.expires < Date.now()) { const bytes = new Uint8Array(16); crypto.getRandomValues(bytes); stored = {id: Array.from(bytes, function (n) { return n.toString(16).padStart(2, '0'); }).join(''), expires: Date.now() + 30 * 86400000}; localStorage.setItem(key, JSON.stringify(stored)); }
			let source = ''; try { const referrer = new URL(document.referrer); if (referrer.origin !== location.origin) source = referrer.hostname; } catch (error) {}
			const data = new FormData(); data.set('visitor', stored.id); data.set('source', source);
			// A beacon never delays navigation and needs no response.
			if (!(navigator.sendBeacon && navigator.sendBeacon(AnarInsights.endpoint, data))) {
				fetch(AnarInsights.endpoint, {method: 'POST', credentials: 'omit', body: data, keepalive: true}).catch(function () {});
			}
		} catch (error) {}
	}
	document.addEventListener('visibilitychange', record); record();
})();
