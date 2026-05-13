/* CodeOn Multilingual — strings admin inline editor.
 *
 * Vanilla JS, no jQuery. Click a + or pencil icon → opens inline editor in
 * a placeholder row below the source row. Ctrl/Cmd+Enter saves, Esc cancels.
 * Save uses fetch POST to /wp-json/cml/v1/strings/<id>/translations with the
 * REST nonce; on success swaps the icon and updates the translated-count badge.
 *
 * DOM construction goes through createElement + textContent (no innerHTML on
 * dynamic content) — defence in depth against XSS regardless of server-side
 * escaping.
 */
(function () {
	'use strict';

	if (typeof window.cmlStringsData === 'undefined') {
		return;
	}
	var DATA = window.cmlStringsData;

	document.addEventListener('click', function (e) {
		var btn = e.target.closest('.cml-translate-btn');
		if (!btn) return;
		e.preventDefault();
		openEditor(btn);
	});

	function openEditor(btn) {
		var stringId = btn.dataset.stringId;
		var lang     = btn.dataset.lang;
		var row      = btn.closest('tr.cml-string-row');
		if (!row) return;
		var editRow  = document.getElementById('cml-edit-' + stringId);
		if (!editRow) return;

		if (editRow.dataset.openLang === lang) {
			closeEditor(editRow);
			return;
		}

		var source     = row.dataset.source     || '';
		var domain     = row.dataset.domain     || '';
		var context    = row.dataset.context    || '';
		var existing   = btn.dataset.translation || '';
		var langNative = btn.dataset.langNative || lang;
		var colspan    = row.children.length;

		clearChildren(editRow);
		editRow.dataset.openLang = lang;

		var td = document.createElement('td');
		td.colSpan   = colspan;
		td.className = 'cml-edit-cell';

		var form = document.createElement('div');
		form.className = 'cml-edit-form';
		td.appendChild(form);

		var meta = document.createElement('div');
		meta.className = 'cml-edit-meta';
		form.appendChild(meta);
		meta.appendChild(makeLabeledPair(DATA.i18n.source, domain, context));
		meta.appendChild(makeLabeledLang(DATA.i18n.translateTo, langNative, lang));

		var preview = document.createElement('pre');
		preview.className = 'cml-source-preview';
		preview.textContent = source;
		form.appendChild(preview);

		var textarea = document.createElement('textarea');
		textarea.className   = 'cml-translation-input';
		textarea.rows        = 3;
		textarea.placeholder = DATA.i18n.placeholder;
		textarea.value       = existing;
		form.appendChild(textarea);

		var actions = document.createElement('div');
		actions.className = 'cml-edit-actions';
		form.appendChild(actions);

		var saveBtn = button('button button-primary cml-save-btn', DATA.i18n.save);
		var cancelBtn = button('button cml-cancel-btn', DATA.i18n.cancel);
		var status = document.createElement('span');
		status.className = 'cml-status';
		status.setAttribute('aria-live', 'polite');
		actions.appendChild(saveBtn);
		actions.appendChild(document.createTextNode(' '));
		actions.appendChild(cancelBtn);
		actions.appendChild(status);

		editRow.appendChild(td);
		editRow.style.display = '';

		textarea.focus();
		textarea.setSelectionRange(textarea.value.length, textarea.value.length);

		cancelBtn.addEventListener('click', function () { closeEditor(editRow); });
		saveBtn.addEventListener('click',   function () { save(stringId, lang, textarea, editRow, btn, status, saveBtn); });
		textarea.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') {
				ev.preventDefault();
				closeEditor(editRow);
			}
			if (ev.key === 'Enter' && (ev.ctrlKey || ev.metaKey)) {
				ev.preventDefault();
				save(stringId, lang, textarea, editRow, btn, status, saveBtn);
			}
		});
	}

	function closeEditor(editRow) {
		editRow.style.display = 'none';
		clearChildren(editRow);
		delete editRow.dataset.openLang;
	}

	function save(stringId, lang, textarea, editRow, btn, status, saveBtn) {
		saveBtn.disabled   = true;
		status.textContent = DATA.i18n.saving;
		status.className   = 'cml-status cml-status-pending';

		fetch(DATA.restUrl + '/' + stringId + '/translations', {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce':   DATA.restNonce
			},
			body: JSON.stringify({ language: lang, translation: textarea.value })
		})
		.then(function (resp) {
			if (!resp.ok) {
				return resp.json().then(function (body) {
					throw new Error(body.message || ('HTTP ' + resp.status));
				});
			}
			return resp.json();
		})
		.then(function (result) {
			updateIcon(btn, result.action === 'saved', result.translation || '');
			updateProgress(stringId, result.translated_count);
			closeEditor(editRow);
		})
		.catch(function (err) {
			status.textContent = DATA.i18n.error + ': ' + err.message;
			status.className   = 'cml-status cml-status-error';
			saveBtn.disabled   = false;
		});
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

	function makeLabeledPair(label, value, context) {
		var span = document.createElement('span');
		var strong = document.createElement('strong');
		strong.textContent = label;
		span.appendChild(strong);
		span.appendChild(document.createTextNode(' '));
		var code = document.createElement('code');
		code.textContent = value;
		span.appendChild(code);
		if (context) {
			span.appendChild(document.createTextNode(' '));
			var small = document.createElement('small');
			small.textContent = context;
			span.appendChild(small);
		}
		return span;
	}

	function makeLabeledLang(label, native, code) {
		var span = document.createElement('span');
		var strong = document.createElement('strong');
		strong.textContent = label;
		span.appendChild(strong);
		span.appendChild(document.createTextNode(' ' + native + ' '));
		var codeEl = document.createElement('code');
		codeEl.textContent = code;
		span.appendChild(codeEl);
		return span;
	}

	function button(className, text) {
		var b = document.createElement('button');
		b.type        = 'button';
		b.className   = className;
		b.textContent = text;
		return b;
	}

	function clearChildren(el) {
		while (el.firstChild) {
			el.removeChild(el.firstChild);
		}
	}
})();
