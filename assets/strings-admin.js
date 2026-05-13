/* CodeOn Multilingual — strings admin popup editor.
 *
 * WPML-style floating popup positioned near the clicked icon. Three columns:
 *   1. Source textarea (readonly, with original-language label)
 *   2. Copy button (source → translation)
 *   3. Translation textarea (editable, with target-language label)
 *
 * Save semantics:
 *   - Click outside the popup → autosave if the value changed, then close.
 *   - Esc → close without saving.
 *   - Shift+Enter → newline in textarea.
 *   - Ctrl/⌘+Enter → save explicitly and close.
 *
 * DOM construction uses createElement + textContent throughout (no innerHTML
 * with dynamic content) for XSS defence-in-depth.
 */
(function () {
	'use strict';

	if (typeof window.cmlStringsData === 'undefined') {
		return;
	}
	var DATA = window.cmlStringsData;

	var current = null; // active popup state: { btn, popup, srcTextarea, transTextarea, initialValue, ... }

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.cml-translate-btn');
		if (!btn) return;
		e.preventDefault();
		e.stopPropagation();
		openPopup(btn);
	});

	function openPopup(btn) {
		// Toggle close if clicking the same button.
		if (current && current.btn === btn) {
			closePopup(true);
			return;
		}
		if (current) {
			closePopup(true);
		}

		var row          = btn.closest('tr.cml-string-row');
		if (!row) return;
		var popup        = document.getElementById('cml-popup');
		if (!popup) return;

		var source       = row.dataset.source || '';
		var domain       = row.dataset.domain || '';
		var context      = row.dataset.context || '';
		var sourceLang   = row.dataset.sourceLanguage || 'en';
		var sourceNative = row.dataset.sourceLanguageNative || sourceLang.toUpperCase();
		var stringId     = btn.dataset.stringId;
		var targetLang   = btn.dataset.lang;
		var targetNative = btn.dataset.langNative || targetLang;
		var existing     = btn.dataset.translation || '';

		clearChildren(popup);

		// ----- Header -----
		var header = document.createElement('div');
		header.className = 'cml-popup-header';
		popup.appendChild(header);

		header.appendChild(makeLabel(DATA.i18n.original, sourceNative, sourceLang));
		header.appendChild(makeLabel(DATA.i18n.translateTo, targetNative, targetLang));

		// ----- Body (3 columns) -----
		var body = document.createElement('div');
		body.className = 'cml-popup-body';
		popup.appendChild(body);

		// Source textarea
		var srcWrap = document.createElement('div');
		srcWrap.className = 'cml-popup-col cml-popup-col-source';
		var srcTextarea = document.createElement('textarea');
		srcTextarea.readOnly = true;
		srcTextarea.value    = source;
		srcWrap.appendChild(srcTextarea);
		body.appendChild(srcWrap);

		// Copy button
		var copyWrap = document.createElement('div');
		copyWrap.className = 'cml-popup-col cml-popup-col-copy';
		var copyBtn = document.createElement('button');
		copyBtn.type      = 'button';
		copyBtn.className = 'button cml-popup-copy';
		copyBtn.title     = DATA.i18n.copySource;
		var copyIcon = document.createElement('span');
		copyIcon.className = 'dashicons dashicons-arrow-right-alt';
		copyIcon.setAttribute('aria-hidden', 'true');
		copyBtn.appendChild(copyIcon);
		copyWrap.appendChild(copyBtn);
		body.appendChild(copyWrap);

		// Translation textarea
		var transWrap = document.createElement('div');
		transWrap.className = 'cml-popup-col cml-popup-col-trans';
		var transTextarea = document.createElement('textarea');
		transTextarea.value       = existing;
		transTextarea.placeholder = DATA.i18n.placeholder;
		transWrap.appendChild(transTextarea);
		body.appendChild(transWrap);

		// ----- Footer (hint + status) -----
		var footer = document.createElement('div');
		footer.className = 'cml-popup-footer';
		var hint = document.createElement('span');
		hint.className   = 'cml-popup-hint';
		hint.textContent = DATA.i18n.hint;
		footer.appendChild(hint);
		var status = document.createElement('span');
		status.className = 'cml-popup-status';
		status.setAttribute('aria-live', 'polite');
		footer.appendChild(status);
		popup.appendChild(footer);

		// Position relative to the clicked button.
		popup.style.display     = 'block';
		popup.setAttribute('aria-hidden', 'false');
		position(popup, btn);

		// Stash state.
		current = {
			btn:            btn,
			popup:          popup,
			row:            row,
			stringId:       stringId,
			targetLang:     targetLang,
			srcTextarea:    srcTextarea,
			transTextarea:  transTextarea,
			status:         status,
			initialValue:   existing,
			outsideHandler: null,
			keyHandler:     null,
			saving:         false,
			disposed:       false,
		};

		// Focus translation input, place cursor at end.
		transTextarea.focus();
		try {
			transTextarea.setSelectionRange(transTextarea.value.length, transTextarea.value.length);
		} catch (e) { /* readonly state may throw on some browsers */ }

		// Copy source → translation.
		copyBtn.addEventListener('click', function () {
			transTextarea.value = source;
			transTextarea.focus();
		});

		// Keyboard: Esc to cancel, Ctrl/⌘+Enter to save now.
		current.keyHandler = function (ev) {
			if (ev.key === 'Escape') {
				ev.preventDefault();
				closePopup(false);
			} else if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
				ev.preventDefault();
				saveAndClose();
			}
			// Shift+Enter → default behaviour (newline). Plain Enter also newlines.
		};
		transTextarea.addEventListener('keydown', current.keyHandler);

		// Outside click → autosave.
		current.outsideHandler = function (ev) {
			if (!current) return;
			if (current.popup.contains(ev.target) || ev.target === current.btn) {
				return;
			}
			// Don't fire on other translate buttons — they'll handle their own open/close.
			if (ev.target.closest('.cml-translate-btn')) {
				return;
			}
			saveAndClose();
		};
		// Defer so the click that opened us doesn't close it.
		setTimeout(function () {
			document.addEventListener('mousedown', current ? current.outsideHandler : function () {});
		}, 0);
	}

	function saveAndClose() {
		if (!current || current.disposed) return;
		if (current.saving) return;
		var newValue = current.transTextarea.value;
		var oldValue = current.initialValue;
		if (newValue === oldValue) {
			closePopup(true);
			return;
		}
		current.saving = true;
		current.status.textContent = DATA.i18n.saving;
		current.status.className   = 'cml-popup-status cml-popup-status-pending';

		var stringId   = current.stringId;
		var lang       = current.targetLang;
		var btn        = current.btn;
		var popupRef   = current.popup;
		var statusRef  = current.status;

		fetch(DATA.restUrl + '/' + stringId + '/translations', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   DATA.restNonce
			},
			body: JSON.stringify({ language: lang, translation: newValue })
		})
		.then(function (resp) {
			if (!resp.ok) {
				return resp.json().then(function (b) { throw new Error(b.message || ('HTTP ' + resp.status)); });
			}
			return resp.json();
		})
		.then(function (result) {
			updateIcon(btn, result.action === 'saved', result.translation || '');
			updateProgress(stringId, result.translated_count);
			closePopup(true);
		})
		.catch(function (err) {
			statusRef.textContent = DATA.i18n.error + ': ' + err.message;
			statusRef.className   = 'cml-popup-status cml-popup-status-error';
			if (current) current.saving = false;
		});
	}

	function closePopup(skipSave) {
		if (!current || current.disposed) return;
		current.disposed = true;
		if (current.outsideHandler) {
			document.removeEventListener('mousedown', current.outsideHandler);
		}
		if (current.keyHandler && current.transTextarea) {
			current.transTextarea.removeEventListener('keydown', current.keyHandler);
		}
		var p = current.popup;
		p.style.display = 'none';
		p.setAttribute('aria-hidden', 'true');
		clearChildren(p);
		current = null;
	}

	function position(popup, btn) {
		var rect       = btn.getBoundingClientRect();
		var docW       = document.documentElement.clientWidth;
		var docH       = document.documentElement.clientHeight;
		var scrollY    = window.scrollY || window.pageYOffset || 0;
		var scrollX    = window.scrollX || window.pageXOffset || 0;
		var popupW     = popup.offsetWidth  || 640;
		var popupH     = popup.offsetHeight || 220;

		// Default: below the button, horizontally centered.
		var top  = rect.bottom + scrollY + 8;
		var left = rect.left + scrollX + rect.width / 2 - popupW / 2;

		// Clamp to viewport.
		if (left < scrollX + 10) {
			left = scrollX + 10;
		}
		if (left + popupW > scrollX + docW - 10) {
			left = scrollX + docW - popupW - 10;
		}

		// Flip above if no room below.
		if (rect.bottom + popupH + 16 > docH && rect.top - popupH - 8 > 0) {
			top = rect.top + scrollY - popupH - 8;
		}

		popup.style.top  = top  + 'px';
		popup.style.left = left + 'px';
	}

	function makeLabel(label, native, code) {
		var wrap = document.createElement('div');
		wrap.className = 'cml-popup-lang-label';
		var strong = document.createElement('strong');
		strong.textContent = label + ' ';
		wrap.appendChild(strong);
		var nativeEl = document.createElement('span');
		nativeEl.className   = 'cml-popup-lang-native';
		nativeEl.textContent = native;
		wrap.appendChild(nativeEl);
		wrap.appendChild(document.createTextNode(' '));
		var codeEl = document.createElement('code');
		codeEl.textContent = code;
		wrap.appendChild(codeEl);
		return wrap;
	}

	function updateIcon(btn, translated, newTranslation) {
		var icon = btn.querySelector('.dashicons');
		if (!icon) return;
		if (translated) {
			icon.className = 'dashicons dashicons-edit cml-icon-translated';
			btn.classList.add('cml-translated');
			btn.classList.remove('cml-missing');
			btn.title = DATA.i18n.editTranslation;
		} else {
			icon.className = 'dashicons dashicons-plus-alt2 cml-icon-missing';
			btn.classList.add('cml-missing');
			btn.classList.remove('cml-translated');
			btn.title = DATA.i18n.addTranslation;
		}
		btn.dataset.translation = newTranslation;
	}

	function updateProgress(stringId, count) {
		var badge = document.querySelector('tr.cml-string-row[data-string-id="' + stringId + '"] .cml-progress-badge');
		if (!badge) return;
		var total = parseInt(badge.dataset.total || '0', 10);
		badge.textContent = count + ' / ' + total;
		badge.classList.toggle('cml-fully-translated', count >= total && total > 0);
	}

	function clearChildren(el) {
		while (el.firstChild) {
			el.removeChild(el.firstChild);
		}
	}
})();
