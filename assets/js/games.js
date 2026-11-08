(function () {
	'use strict';
	document.querySelectorAll('[data-game-phone]').forEach(function (form) {
		let busy = false; let resendAt = 0;
		const phone = form.elements.phone; const code = form.elements.code;
		const request = form.querySelector('[data-phone-request]');
		const status = form.querySelector('[data-status]');
		async function send(requestCode) {
			if (busy || !phone.reportValidity() || (!requestCode && !code.reportValidity())) return;
			busy = true;
			const buttons = Array.from(form.querySelectorAll('button'));
			buttons.forEach(function (button) { button.disabled = true; });
			const data = new FormData(form);
			if (requestCode) data.set('request_code', '1');
			try {
				const response = await fetch(form.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data});
				const body = await response.json(); const result = body.data || {};
				status.textContent = result.message || form.dataset.network;
				if (!response.ok || !body.success) return;
				if (result.verified) { window.location.reload(); return; }
				if (requestCode) {
					form.querySelector('[data-phone-code]').hidden = false; code.required = true; code.value = ''; code.focus();
					resendAt = Date.now() + Number(result.resend_after || 60) * 1000;
					setTimeout(function () { if (!busy) request.disabled = false; }, Math.max(0, resendAt - Date.now()));
				}
			} catch (error) { status.textContent = form.dataset.network; }
			finally { busy = false; buttons.forEach(function (button) { button.disabled = button === request && Date.now() < resendAt; }); }
		}
		request.addEventListener('click', function () { send(true); });
		phone.addEventListener('input', function () { form.querySelector('[data-phone-code]').hidden = true; code.required = false; code.value = ''; });
		form.addEventListener('submit', function (event) { event.preventDefault(); send(!code.required); });
	});
	document.querySelectorAll('[data-anar-game]').forEach(function (root) {
		let play = 0; let busy = false; let rotation = 0;
		const buttons = Array.from(root.querySelectorAll('[data-pick]'));
		const status = root.querySelector('[data-status]');
		const scratch = root.querySelector('.anar-scratch');
		if (scratch) {
			const canvas = scratch.querySelector('canvas'); const context = canvas.getContext('2d'); let distance = 0; let previous = null;
			if (context) {
				const gradient = context.createLinearGradient(0, 0, 600, 240); gradient.addColorStop(0, '#c6b4cc'); gradient.addColorStop(0.5, '#eee6f0'); gradient.addColorStop(1, '#b997ae');
				context.fillStyle = gradient; context.fillRect(0, 0, 600, 240); context.fillStyle = '#a37992'; context.font = '70px serif'; context.textAlign = 'center'; context.fillText('✦  ✦  ✦', 300, 145);
				canvas.addEventListener('pointerdown', function (event) { previous = null; canvas.setPointerCapture(event.pointerId); });
				canvas.addEventListener('pointermove', function (event) {
					if (!canvas.hasPointerCapture(event.pointerId) || busy || scratch.disabled) return;
					const rect = canvas.getBoundingClientRect(); const point = [(event.clientX - rect.left) * 600 / rect.width, (event.clientY - rect.top) * 240 / rect.height];
					context.globalCompositeOperation = 'destination-out'; context.lineWidth = 42; context.lineCap = 'round'; context.beginPath(); context.moveTo(...(previous || point)); context.lineTo(...point); context.stroke();
					if (previous) distance += Math.hypot(point[0] - previous[0], point[1] - previous[1]); previous = point;
					if (distance > 450) choose(scratch);
				});
			}
		}
		async function choose(button) {
			if (busy || button.disabled) return; busy = true;
			const data = new FormData(); data.set('action', 'anar_game'); data.set('nonce', root.dataset.nonce); data.set('game', root.dataset.type); data.set('play_id', play); data.set('choice', button.dataset.pick);
			buttons.forEach(function (b) { b.disabled = true; }); status.textContent = '';
			try {
				const response = await fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data});
				const body = await response.json(); const result = body.data || {};
				if (!response.ok || !body.success) { status.textContent = result.message || root.dataset.network; buttons.forEach(function (b) { b.disabled = false; }); return; }
				play = result.play_id;
				const wheel = root.querySelector('[data-wheel]'); if (wheel) { rotation = Math.floor(rotation / 360) * 360 + 1440 + (result.won ? 270 : 90); wheel.style.transform = 'rotate(' + rotation + 'deg)'; }
				const slots = root.querySelector('[data-slots]'); if (slots) { slots.classList.add('is-spinning'); await new Promise(function (resolve) { setTimeout(resolve, 900); }); slots.classList.remove('is-spinning'); slots.querySelectorAll('span').forEach(function (s, i) { s.textContent = result.won ? '🍒' : ['🍒', '🍋', '🍇'][i]; }); }
				if (root.dataset.type === 'scratch') { button.classList.add('is-revealed'); button.querySelector('strong').textContent = result.won ? '✦' : '♡'; }
				if (['cards', 'boxes', 'treasure'].includes(root.dataset.type)) { button.classList.add('is-revealed'); button.textContent = result.won ? '🎉' : '♡'; }
				if (root.dataset.type === 'memory') {
					Object.keys(result.revealed).forEach(function (index) { const b = buttons.find(function (candidate) { return candidate.dataset.pick === index; }); if (b) { b.textContent = result.revealed[index]; b.classList.add('is-revealed'); } });
					await new Promise(function (resolve) { setTimeout(resolve, 800); });
					const open = Object.keys(result.revealed).length === 1 ? Object.keys(result.revealed) : [];
					buttons.forEach(function (b) { const matched = result.matched.includes(Number(b.dataset.pick)); b.disabled = matched || result.finished || open.includes(b.dataset.pick); b.classList.toggle('is-matched', matched); if (!matched && !result.finished && !open.includes(b.dataset.pick)) { b.textContent = '✦'; b.classList.remove('is-revealed'); } });
				}
				if (wheel) await new Promise(function (resolve) { setTimeout(resolve, window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 0 : 2100); });
				status.textContent = result.message;
				if (result.coupon) { const coupon = root.querySelector('[data-coupon]'); coupon.hidden = false; coupon.querySelector('strong').textContent = result.coupon; }
			} catch (error) { status.textContent = root.dataset.network; buttons.forEach(function (b) { b.disabled = false; }); }
			finally { busy = false; }
		}
		buttons.forEach(function (button) { button.addEventListener('click', function () { choose(button); }); if (button.tagName !== 'BUTTON') button.addEventListener('keydown', function (event) { if (event.key === 'Enter' || event.key === ' ') { event.preventDefault(); choose(button); } }); });
	});
})();
