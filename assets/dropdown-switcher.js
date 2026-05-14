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

				// CRITICAL: clear any previously-applied inline min-width
				// before measuring. scrollWidth returns max(content, clientWidth),
				// and clientWidth grows with min-width — so without this reset
				// the toggle/menu would grow by ~2 px on every open click.
				toggle.style.minWidth = '';
				menu.style.minWidth   = '';
				// Force a reflow so the cleared styles take effect before we
				// read scrollWidth / offsetWidth below.
				void menu.offsetWidth;

				// Step 1: measure the widest natural content (toggle button
				// or any menu link) and pin the toggle to fit it. The toggle
				// widens, which propagates to the floating-switcher box.
				var widest = toggle.scrollWidth;
				menu.querySelectorAll('a').forEach(function (a) {
					widest = Math.max(widest, a.scrollWidth);
				});
				if (widest > 0) {
					toggle.style.minWidth = (widest + 2) + 'px';
				}

				// Step 2: the floating-switcher (if any) wraps the toggle
				// with its own padding, so its outer width is now
				// `toggle + pad`. Sync the menu's min-width to the
				// floating-switcher's resulting offsetWidth so the menu
				// visually equals the box that triggered it.
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
