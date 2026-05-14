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

				// CSS `width: max-content` is unreliable for position-absolute
				// menus anchored with right:0 (browsers can clamp the width to
				// the containing block). Measure each link's actual content
				// width and pin the menu's minWidth so the longest item
				// (e.g. "ქართული") always fits.
				var widest = 0;
				menu.querySelectorAll('a').forEach(function (a) {
					widest = Math.max(widest, a.scrollWidth);
				});
				if (widest > 0) {
					// Add 2 px to absorb sub-pixel rounding in some browsers.
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
