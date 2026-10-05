/**
 * Block editor support for rowsprout_template — see Admin\TemplateBlockEditor
 * for the save flow this script takes part in:
 *
 * 1. Marks the editor's own REST save of this template with a header, so
 *    SavePost::handleSave() waits for the metabox request that follows.
 * 2. Adds a "Save action" panel to the document sidebar and mirrors its
 *    values as hidden inputs into the "RowSprout template" metabox form (the
 *    same field names the classic Publish box submits). An add-on extends
 *    the panel through wp.hooks, all receiving the shared state object and
 *    setState(patch):
 *    - filter 'rowsprout.templateSaveActionFields' (array of elements shown
 *      under the select),
 *    - filter 'rowsprout.templateSaveActionInputs' (object of extra hidden
 *      inputs, name => value),
 *    - action 'rowsprout.templateSaved' (after each metabox save).
 * 3. After every metabox save, fetches the fresh config version (written
 *    back into the form, so the next save isn't seen as a stale form) and
 *    the persisted save notices (shown as editor notices).
 */
(function (wp, config) {
	'use strict';

	if (!wp || !config || !wp.plugins || !wp.element || !wp.data) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var components = wp.components;
	var apiFetch = wp.apiFetch;
	var Panel = (wp.editor && wp.editor.PluginDocumentSettingPanel) || (wp.editPost && wp.editPost.PluginDocumentSettingPanel);
	var i18n = config.i18n || {};
	var METABOX_ID = 'rowsprout_page_template_box';

	// 1. Mark this template's own REST saves (and autosaves).
	var savePath = new RegExp('/wp/v2/rowsprout_template/' + config.postId + '(/autosaves)?(\\?|$)');
	apiFetch.use(function (options, next) {
		var target = options.path || options.url || '';
		var method = (options.method || 'GET').toUpperCase();
		if (method !== 'GET' && savePath.test(target)) {
			options.headers = options.headers || {};
			options.headers[config.header] = '1';
		}
		return next(options);
	});

	// 2. Hidden inputs inside the metabox form.
	var state = { saveAction: config.saveAction || 'update_pages' };
	var hooks = wp.hooks;

	function metaboxInside() {
		return document.querySelector('#' + METABOX_ID + ' .inside') || document.getElementById(METABOX_ID);
	}

	function writeHiddenInputs() {
		var box = metaboxInside();
		if (!box) {
			return false;
		}
		var holder = box.querySelector('[data-rowsprout-block-editor-fields]');
		if (!holder) {
			holder = document.createElement('div');
			holder.setAttribute('data-rowsprout-block-editor-fields', '');
			holder.hidden = true;
			box.appendChild(holder);
		}
		holder.innerHTML = '';
		var action = document.createElement('input');
		action.type = 'hidden';
		action.name = 'rowsprout_page_save_action';
		action.value = state.saveAction;
		holder.appendChild(action);
		var extra = hooks ? hooks.applyFilters('rowsprout.templateSaveActionInputs', {}, state, setState) : {};
		Object.keys(extra || {}).forEach(function (name) {
			var input = document.createElement('input');
			input.type = 'hidden';
			input.name = name;
			input.value = String(extra[name]);
			holder.appendChild(input);
		});
		return true;
	}

	function emitSaveActionChange() {
		try {
			document.dispatchEvent(new CustomEvent('rowsproutSaveActionChange', { detail: { key: state.saveAction } }));
		} catch (e) {}
	}

	// The metabox area renders after the editor itself; retry briefly.
	(function initInputs(attempt) {
		if (writeHiddenInputs()) {
			emitSaveActionChange();
		} else if (attempt < 40) {
			setTimeout(function () { initInputs(attempt + 1); }, 250);
		}
	})(0);

	var listeners = [];
	function setState(patch) {
		for (var key in patch) {
			if (Object.prototype.hasOwnProperty.call(patch, key)) {
				state[key] = patch[key];
			}
		}
		writeHiddenInputs();
		listeners.forEach(function (listener) { listener(); });
	}

	function currentAction() {
		var current = null;
		config.actions.forEach(function (action) {
			if (action.value === state.saveAction) {
				current = action;
			}
		});
		return current;
	}

	// Re-renders the calling component whenever the shared state changes.
	function useSharedState() {
		var tick = useState(0);
		wp.element.useEffect(function () {
			var listener = function () { tick[1](function (n) { return n + 1; }); };
			listeners.push(listener);
			return function () { listeners = listeners.filter(function (l) { return l !== listener; }); };
		}, []);
	}

	// The same fields in the document sidebar and in the pre-publish panel;
	// both read and write the one shared state above.
	function SaveActionFields() {
		useSharedState();
		var current = currentAction();

		return el(
			wp.element.Fragment,
			null,
			el(components.SelectControl, {
				label: i18n.saveAction,
				value: state.saveAction,
				options: config.actions.map(function (action) { return { value: action.value, label: action.label }; }),
				onChange: function (value) {
					setState({ saveAction: value });
					emitSaveActionChange();
				},
				__nextHasNoMarginBottom: true
			}),
			current && current.description ? el('p', { className: 'components-base-control__help', style: { marginTop: '8px' } }, current.description) : null,
			hooks ? hooks.applyFilters('rowsprout.templateSaveActionFields', [], state, setState) : null
		);
	}

	function SaveActionPanel() {
		return el(Panel, { name: 'rowsprout-save-action', title: i18n.panelTitle, className: 'rowsprout-save-action-panel' }, el(SaveActionFields));
	}

	// Shown in the "Are you ready to publish?" panel instead of core's
	// Visibility / Publish date rows, which template-block-editor.css hides.
	var PrePublishPanel = wp.editor && wp.editor.PluginPrePublishPanel;
	function SaveActionPrePublish() {
		useSharedState();
		var current = currentAction();
		return el(
			PrePublishPanel,
			{ title: i18n.panelTitle + (current ? ': ' + current.label : ''), initialOpen: true, className: 'rowsprout-save-action-prepublish' },
			el(SaveActionFields)
		);
	}

	if (Panel) {
		wp.plugins.registerPlugin('rowsprout-template-save-action', { render: SaveActionPanel });
	}
	if (PrePublishPanel) {
		wp.plugins.registerPlugin('rowsprout-template-save-action-prepublish', { render: SaveActionPrePublish });
	}

	// 2b. Save lock. On the classic screen the browser refuses to submit the
	// form while groups-metabox.js has flagged a field (setCustomValidity():
	// duplicate URL, invalid URL/email). The block editor posts the metabox
	// with FormData, which ignores validity, so mirror that with a save lock.
	var LOCK_KEY = 'rowsprout-invalid-fields';
	var isLocked = false;

	function syncSaveLock() {
		var box = document.getElementById(METABOX_ID);
		var invalid = !!(box && box.querySelector(':invalid'));
		if (invalid === isLocked) {
			return;
		}
		isLocked = invalid;
		var editor = wp.data.dispatch('core/editor');
		var notices = wp.data.dispatch('core/notices');
		if (invalid) {
			editor.lockPostSaving(LOCK_KEY);
			notices.createNotice('warning', i18n.invalidFields, { id: LOCK_KEY, isDismissible: false });
		} else {
			editor.unlockPostSaving(LOCK_KEY);
			notices.removeNotice(LOCK_KEY);
		}
	}

	// After groups-metabox.js's own input/change handlers have (re)validated.
	['input', 'change', 'click'].forEach(function (type) {
		document.addEventListener(type, function (event) {
			if (event.target && event.target.closest && event.target.closest('#' + METABOX_ID)) {
				setTimeout(syncSaveLock, 0);
			}
		}, true);
	});
	setTimeout(syncSaveLock, 1500);

	// 3. After each metabox save: fresh config version + notices.
	var editPostStore = wp.data.select('core/edit-post');
	var wasSavingMetaBoxes = false;
	wp.data.subscribe(function () {
		if (!editPostStore || typeof editPostStore.isSavingMetaBoxes !== 'function') {
			return;
		}
		var isSaving = editPostStore.isSavingMetaBoxes();
		if (wasSavingMetaBoxes && !isSaving) {
			afterMetaboxSave();
		}
		wasSavingMetaBoxes = isSaving;
	});

	function afterMetaboxSave() {
		apiFetch({ path: config.restPath, method: 'POST' }).then(function (response) {
			var versionInput = document.querySelector('#' + METABOX_ID + ' input[name="dp_config_version"]');
			if (versionInput && response && typeof response.configVersion === 'string') {
				versionInput.value = response.configVersion;
			}
			// Read live by block-editor-token-button.js.
			if (response && Array.isArray(response.tokenFields)) {
				config.tokenFields = response.tokenFields;
			}
			// Read live by block-editor-bindings.js.
			if (response && Array.isArray(response.bindingFields)) {
				config.bindingFields = response.bindingFields;
			}
			var notices = wp.data.dispatch('core/notices');
			(response && response.notices ? response.notices : []).forEach(function (notice, index) {
				notices.createNotice(notice.type === 'error' ? 'error' : 'warning', notice.message, {
					id: 'rowsprout-save-notice-' + index,
					isDismissible: true
				});
			});
			if (hooks) {
				hooks.doAction('rowsprout.templateSaved', state, setState);
			}
		}).catch(function () {
			wp.data.dispatch('core/notices').createNotice('error', i18n.afterSaveFailed, {
				id: 'rowsprout-after-save-failed',
				isDismissible: true
			});
		});
	}
})(window.wp, window.rowsproutTemplateBlockEditor);
