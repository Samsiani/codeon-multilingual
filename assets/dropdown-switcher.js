/**
 * CodeOn Multilingual — toggle behaviour for the custom dropdown switcher.
 *
 * Native <select>/<option> can't render <img> children, so the dropdown
 * switcher emits a <button> + <ul> pair. This script wires open/close
 * + click-outside + Escape-to-close + simple keyboard nav.
 */
(function () {
	'use strict';

	function closeAll(except) {
		document.querySelectorAll('[data-cml-dropdown][data-cml-open]').forEach(function (el) {
			if (el === except) return;
			el.removeAttribute('data-cml-open');
			var toggle = el.querySelector('.cml-dropdown-toggle');
			var menu   = el.querySelector('.cml-dropdown-menu');
			if (toggle) toggle.setAttribute('aria-expanded', 'false');
			if (menu)   menu.setAttribute('hidden', '');
		});
	}

	function bind(wrapper) {
		var toggle = wrapper.querySelector('.cml-dropdown-toggle');
		var menu   = wrapper.querySelector('.cml-dropdown-menu');
		if (!toggle || !menu) return;

		toggle.addEventListener('click', function (e) {
			e.preventDefault();
			var open = wrapper.hasAttribute('data-cml-open');
			closeAll(open ? null : wrapper);
			if (open) {
				wrapper.removeAttribute('data-cml-open');
				toggle.setAttribute('aria-expanded', 'false');
				menu.setAttribute('hidden', '');
			} else {
				wrapper.setAttribute('data-cml-open', '');
				toggle.setAttribute('aria-expanded', 'true');
				menu.removeAttribute('hidden');

				// Equalise toggle, menu and the floating-switcher container
				// so all three share the same width when the menu is open.
				// Step 1: find the widest natural content (toggle or any
				// menu link) and widen the toggle to fit it.
				var widest = toggle.scrollWidth;
				menu.querySelectorAll('a').forEach(function (a) {
					widest = Math.max(widest, a.scrollWidth);
				});
				if (widest > 0) {
					toggle.style.minWidth = (widest + 2) + 'px';
				}

				// Step 2: the floating-switcher (if any) wraps the toggle
				// with its own padding, so its outer width is `toggle + pad`.
				// Pin the menu's min-width to the floating-switcher's
				// offsetWidth so the menu visually matches the box that
				// triggered it. Reading offsetWidth here forces a reflow,
				// so the new toggle width is already applied.
				var floatingBox = wrapper.closest('.cml-floating-switcher');
				if (floatingBox) {
					menu.style.minWidth = floatingBox.offsetWidth + 'px';
				} else if (widest > 0) {
					menu.style.minWidth = (widest + 2) + 'px';
				}

				var firstLink = menu.querySelector('a');
				if (firstLink) firstLink.focus();
			}
		});

		menu.addEventListener('keydown', function (e) {
			if (e.key === 'Escape') {
				toggle.focus();
				wrapper.removeAttribute('data-cml-open');
				toggle.setAttribute('aria-expanded', 'false');
				menu.setAttribute('hidden', '');
			}
		});
	}

	function init() {
		document.querySelectorAll('[data-cml-dropdown]').forEach(bind);
	}

	document.addEventListener('click', function (e) {
		if (!e.target.closest('[data-cml-dropdown]')) closeAll(null);
	});
	document.addEventListener('keydown', function (e) {
		if (e.key === 'Escape') closeAll(null);
	});

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
