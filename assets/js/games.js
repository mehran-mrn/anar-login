(function () {
	'use strict';
	const phoneInitialized = new WeakSet();
	function initPhone(form) {
		if (phoneInitialized.has(form)) return;
		phoneInitialized.add(form);
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
	}

	const initialized = new WeakSet();
	const pause = function (ms) { return new Promise(function (resolve) { setTimeout(resolve, ms); }); };
	const token = function () {
		const bytes = new Uint32Array(4); window.crypto.getRandomValues(bytes);
		return Array.from(bytes, function (n) { return n.toString(16).padStart(8, '0'); }).join('');
	};
	function init(root) {
		if (initialized.has(root)) return;
		initialized.add(root);
		const buttons = Array.from(root.querySelectorAll('[data-pick]'));
		const status = root.querySelector('[data-status]');
		const retry = root.querySelector('[data-retry]');
		const reduced = function () { return window.matchMedia('(prefers-reduced-motion: reduce)').matches; };
		const wheel = root.querySelector('[data-wheel]');
		const scratch = root.querySelector('.anar-scratch');
		const key = 'anar-game:' + root.dataset.account + ':' + root.dataset.type;
		let pending = null; let play = 0; let busy = false; let done = root.dataset.state !== 'ready'; let rotation = 0; let spin = null; let lastResult = null;
		try { pending = JSON.parse(sessionStorage.getItem(key)); if (pending) play = pending.play || 0; } catch (error) { /* Storage may be unavailable in private browsing. */ }
		function persist() { try { pending ? sessionStorage.setItem(key, JSON.stringify(pending)) : sessionStorage.removeItem(key); } catch (error) { /* The server remains authoritative. */ } }
		function state(value) { root.dataset.state = value; }
		function controls() {
			buttons.forEach(function (b) {
				const index = Number(b.dataset.pick);
				const matched = lastResult && lastResult.matched.includes(index);
				const open = lastResult && Object.keys(lastResult.revealed).length === 1 && Object.prototype.hasOwnProperty.call(lastResult.revealed, index);
				b.disabled = busy || done || !!pending || !!matched || !!open;
			});
		}
		function startWheel() {
			if (!wheel || reduced()) return;
			spin = wheel.animate([{transform: 'rotate(' + rotation + 'deg)'}, {transform: 'rotate(' + (rotation + 360) + 'deg)'}], {duration: 900, iterations: Infinity, easing: 'linear'});
		}
		async function stopWheel(result) {
			if (!wheel) return;
			let current = rotation;
			if (spin) { current += (Number(spin.currentTime || 0) / 900) * 360; spin.cancel(); spin = null; }
			if (result.wheel_segments) {
				const step = 360 / result.wheel_segments.length; const fragment = document.createDocumentFragment();
				result.wheel_segments.forEach(function (segment, i) {
					const sector = document.createElement('div'); sector.className = 'anar-wheel-sector'; sector.style.setProperty('--angle', (i * step) + 'deg'); sector.style.setProperty('--slice', (step + .1) + 'deg'); sector.dataset.tone = i % 3;
					const label = document.createElement('span'); label.className = 'anar-wheel-label'; label.style.setProperty('--angle', ((i + .5) * step) + 'deg'); label.textContent = segment.label;
					fragment.append(sector, label);
				});
				wheel.replaceChildren(fragment); wheel.dataset.segments = JSON.stringify(result.wheel_segments);
			}
			const segments = JSON.parse(wheel.dataset.segments);
			let index = segments.findIndex(function (segment) { return segment.id === (result.won ? result.prize_id || 'legacy' : ''); });
			if (index < 0) index = segments.findIndex(function (segment) { return !segment.id; });
			const target = 360 - (Math.max(index, 0) + 0.5) * 360 / segments.length;
			rotation = Math.ceil(current / 360) * 360 + 1080 + target;
			wheel.style.transform = 'rotate(' + current + 'deg)';
			if (reduced()) { wheel.style.transform = 'rotate(' + target + 'deg)'; rotation = target; return; }
			const stop = wheel.animate([{transform: 'rotate(' + current + 'deg)'}, {transform: 'rotate(' + rotation + 'deg)'}], {duration: 2400, easing: 'cubic-bezier(.12,.65,.18,1)', fill: 'forwards'});
			try { await stop.finished; } catch (error) { /* A removed widget can cancel its animation. */ }
			wheel.style.transform = 'rotate(' + rotation + 'deg)'; stop.cancel();
		}
		async function reveal(result, chosen) {
			lastResult = result;
			await stopWheel(result);
			if (scratch) scratch.classList.add('is-revealed');
			if (chosen) chosen.classList.add('is-selected');
			if (['cards', 'boxes', 'treasure'].includes(root.dataset.type) && chosen) {
				chosen.classList.add('is-revealed'); chosen.setAttribute('aria-label', result.message);
				await pause(reduced() ? 0 : 750);
			}
			if (root.dataset.type === 'slots') {
				const reels = Array.from(root.querySelectorAll('.anar-reel-strip'));
				const symbols = {};
				root.querySelectorAll('[data-fruit]').forEach(function (s) { if (!symbols[s.dataset.fruit]) symbols[s.dataset.fruit] = s.cloneNode(true); });
				for (let i = 0; i < reels.length; i++) {
					await pause(reduced() ? 0 : 230);
					const strip = reels[i]; strip.style.animation = 'none';
					const fruit = result.won ? 'cherry' : ['cherry', 'citrus', 'grape'][i];
					const source = symbols[fruit] || strip.firstElementChild;
					strip.replaceChildren(source.cloneNode(true)); strip.style.transform = 'translateY(0)';
				}
			}
			if (root.dataset.type === 'memory') {
				Object.keys(result.revealed).forEach(function (index) {
					const b = buttons.find(function (candidate) { return candidate.dataset.pick === index; });
					const symbol = ({'🍒':'cherry', '🍋':'citrus', '🍇':'grape', '🍓':'berry'})[result.revealed[index]] || result.revealed[index];
					const template = ['cherry', 'citrus', 'grape', 'berry'].includes(symbol) ? root.querySelector('template[data-symbol="' + symbol + '"]') : null;
					if (b && template) { b.querySelector('[data-face]').replaceChildren(template.content.cloneNode(true)); b.classList.add('is-revealed'); b.setAttribute('aria-label', template.dataset.label); }
				});
				root.querySelector('[data-moves]').textContent = result.moves + ' / 24'; root.querySelector('[data-pairs]').textContent = (result.matched.length / 2) + ' / 4';
				if (Object.keys(result.revealed).length === 2) await pause(reduced() ? 300 : 1000);
				buttons.forEach(function (b) {
					const matched = result.matched.includes(Number(b.dataset.pick));
					const open = Object.keys(result.revealed).length === 1 && Object.prototype.hasOwnProperty.call(result.revealed, b.dataset.pick);
					b.classList.toggle('is-matched', matched); b.classList.toggle('is-revealed', matched || open);
					b.classList.toggle('is-selected', matched || open);
				});
			}
			state(result.finished ? (result.won ? 'won' : result.exhausted ? 'exhausted' : 'lost') : 'ready');
			status.textContent = result.message;
			if (result.coupon) {
				const coupon = root.querySelector('[data-coupon]'); coupon.hidden = false; coupon.querySelector('strong').textContent = result.coupon;
				if (result.prize_label) coupon.querySelector('[data-prize-label]').textContent = result.prize_label;
				coupon.querySelector('[data-prize-terms]').textContent = result.prize_terms || '';
			}
			done = result.finished;
			if (done) {
				root.dataset.remaining = result.remaining;
				document.dispatchEvent(new CustomEvent('anar-game-finished', {detail:{account:root.dataset.account, game:root.dataset.type, remaining:result.remaining, total:result.total_remaining, mode:result.quota_mode, source:root}}));
				const start = root.querySelector('.anar-game-start'); if (start) start.textContent = root.dataset.completed;
				root.querySelector('.anar-game-quota').textContent = root.dataset.left.replace('%d', result.remaining);
			}
		}
		async function choose(button, continuing) {
			if (busy || done || (!continuing && (button.disabled || pending))) return;
			busy = true; retry.hidden = true;
			if (!continuing) pending = {request: pending ? pending.request : (root.dataset.request || token()), play: play, choice: Number(button.dataset.pick), move: token()};
			root.dataset.request = pending.request; persist(); controls(); state('running'); status.textContent = root.dataset.wait;
			button.classList.add('is-selected'); startWheel();
			const data = new FormData(); data.set('action', 'anar_game'); data.set('nonce', root.dataset.nonce); data.set('game', root.dataset.type); data.set('play_id', pending.play); data.set('choice', pending.choice); data.set('request_id', pending.request); data.set('move_id', pending.move);
			const controller = new AbortController(); const timeout = setTimeout(function () { controller.abort(); }, 20000);
			try {
				const response = await fetch(root.dataset.endpoint, {method: 'POST', credentials: 'same-origin', body: data, signal: controller.signal});
				const body = await response.json(); const result = body.data || {};
				if (!response.ok || !body.success) {
					const error = new Error(result.message || root.dataset.network); error.code = result.code || ''; error.userMessage = result.message || root.dataset.network; throw error;
				}
				play = result.play_id;
				await reveal(result, button);
				const resume = Object.assign({}, pending, {play: result.play_id});
				pending = null;
				if (result.finished) persist();
				else { try { sessionStorage.setItem(key, JSON.stringify(resume)); } catch (error) { /* Reload recovery is optional when storage is blocked. */ } }
			} catch (error) {
				if (spin) { spin.cancel(); spin = null; }
				const terminal = ['anar_game_quota', 'anar_game_expired', 'anar_game_unavailable', 'anar_game_campaign', 'anar_game_empty', 'anar_quiz_unconfigured', 'anar_milestone_pending', 'anar_game_missing', 'anar_game_coupon_expired'].includes(error.code);
				state(error.code === 'anar_game_quota' ? 'quota' : error.code === 'anar_game_empty' ? 'exhausted' : 'error');
				status.textContent = error.userMessage || root.dataset.network;
				if (terminal) { pending = null; persist(); done = true; } else retry.hidden = false;
			} finally { clearTimeout(timeout); busy = false; controls(); }
		}
		buttons.forEach(function (button) { button.addEventListener('click', function () { choose(button, false); }); });
		retry.addEventListener('click', function () { if (!pending) return; const b = buttons.find(function (candidate) { return Number(candidate.dataset.pick) === pending.choice; }) || buttons[0]; choose(b, true); });
		if (pending) { done = false; state('error'); status.textContent = root.dataset.network; retry.hidden = false; }
		controls();
		document.addEventListener('anar-game-finished', function (event) {
			const detail = event.detail;
			if (detail.source === root || detail.account !== root.dataset.account || busy || pending) return;
			let left = Number(root.dataset.remaining);
			if (detail.game === root.dataset.type || detail.mode === 'shared') left = detail.remaining;
			else if (detail.mode === 'combined') left = Math.min(left, detail.total);
			else return;
			root.dataset.remaining = left; root.querySelector('.anar-game-quota').textContent = root.dataset.left.replace('%d', left);
			if (!left && !done) { done = true; state('quota'); status.textContent = root.dataset.quotaMessage; controls(); }
		});
		const copy = root.querySelector('[data-copy]');
		copy.addEventListener('click', async function () {
			const text = root.querySelector('[data-coupon] strong');
			try { await navigator.clipboard.writeText(text.textContent); copy.textContent = root.dataset.copied; } catch (error) { const range = document.createRange(); range.selectNodeContents(text); const selection = window.getSelection(); selection.removeAllRanges(); selection.addRange(range); }
		});
		if (scratch) {
			const canvas = scratch.querySelector('canvas'); const context = canvas.getContext('2d', {willReadFrequently: true});
			let previous = null; let erasing = false; let marks = false;
			function cover() {
				if (!context || marks) return;
				const ratio = Math.min(3, window.devicePixelRatio || 1); const rect = canvas.getBoundingClientRect();
				canvas.width = Math.max(1, Math.round(rect.width * ratio)); canvas.height = Math.max(1, Math.round(rect.height * ratio));
				const w = canvas.width; const h = canvas.height;
				const gradient = context.createLinearGradient(0, 0, w, h); gradient.addColorStop(0, '#a5a5b2'); gradient.addColorStop(.4, '#e6e4ec'); gradient.addColorStop(.65, '#c1becd'); gradient.addColorStop(1, '#9494a5');
				context.fillStyle = gradient; context.fillRect(0, 0, w, h);
				context.strokeStyle = '#ffffff38'; context.lineWidth = ratio;
				for (let x = -h; x < w; x += 16 * ratio) { context.beginPath(); context.moveTo(x, 0); context.lineTo(x + h, h); context.stroke(); }
				context.strokeStyle = '#ffffff99'; context.lineWidth = 2 * ratio; context.strokeRect(14 * ratio, 14 * ratio, w - 28 * ratio, h - 28 * ratio);
				context.fillStyle = '#575366'; context.font = (42 * ratio) + 'px serif'; context.textAlign = 'center'; context.textBaseline = 'middle'; context.fillText('✦  ✦  ✦', w / 2, h / 2);
			}
			function erase(event) {
				if (!erasing || busy || done || pending || !context) return;
				const rect = canvas.getBoundingClientRect(); const point = [(event.clientX - rect.left) * canvas.width / rect.width, (event.clientY - rect.top) * canvas.height / rect.height];
				context.globalCompositeOperation = 'destination-out'; context.strokeStyle = '#000'; context.fillStyle = '#000'; context.lineWidth = canvas.width * .10; context.lineCap = 'round'; context.lineJoin = 'round';
				context.beginPath(); context.moveTo(...(previous || point)); context.lineTo(...point); context.stroke();
				context.beginPath(); context.arc(point[0], point[1], canvas.width * .05, 0, Math.PI * 2); context.fill();
				marks = true; previous = point;
			}
			function coverage() {
				if (!context || busy || done || pending) return;
				const pixels = context.getImageData(0, 0, canvas.width, canvas.height).data; let clear = 0; let samples = 0;
				for (let i = 3; i < pixels.length; i += 4 * 16) { if (pixels[i] < 128) clear++; samples++; }
				if (samples && clear / samples >= .4) choose(buttons[0], false);
			}
			canvas.addEventListener('pointerdown', function (event) { if (busy || done || pending || event.button > 0) return; erasing = true; previous = null; canvas.setPointerCapture(event.pointerId); erase(event); });
			canvas.addEventListener('pointermove', function (event) { erase(event); });
			function end() { erasing = false; previous = null; coverage(); }
			canvas.addEventListener('pointerup', end); canvas.addEventListener('pointercancel', end); canvas.addEventListener('lostpointercapture', end);
			new ResizeObserver(cover).observe(scratch); cover();
		}
	}
	function scan() { document.querySelectorAll('[data-anar-game]').forEach(init); document.querySelectorAll('[data-game-phone]').forEach(initPhone); }
	scan();
	let scheduled = false;
	new MutationObserver(function () { if (scheduled) return; scheduled = true; requestAnimationFrame(function () { scheduled = false; scan(); }); }).observe(document.documentElement, {childList: true, subtree: true});
})();
