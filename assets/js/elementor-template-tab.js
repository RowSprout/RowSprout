jQuery(function ($) {
	'use strict';

	var config = window.dpElementorTemplateTab;
	if (!config) {
		return;
	}

	// Elementor re-renders the elements panel's navigation (Elements /
	// Globals) from its own template whenever that panel opens, so the button
	// is re-added after every render. No "elementor-component-tab" class:
	// Elementor rebinds a routing click handler onto that class on every
	// render and would throw "Routes: `panel/elements/rowsprout-template` not
	// found" for ours.
	function ensureTabButton() {
		var nav = document.getElementById('elementor-panel-elements-navigation');
		if (!nav || nav.querySelector('[data-tab="rowsprout-template"]')) {
			return;
		}
		var button = document.createElement('button');
		button.type = 'button';
		button.className = 'elementor-panel-navigation-tab';
		button.setAttribute('data-tab', 'rowsprout-template');
		button.textContent = config.tabLabel || 'RowSprout template';
		var globalTab = nav.querySelector('[data-tab="global"]');
		nav.insertBefore(button, globalTab ? globalTab.nextSibling : null);
	}

	var ensureQueued = false;
	new MutationObserver(function () {
		if (ensureQueued) {
			return;
		}
		ensureQueued = true;
		window.requestAnimationFrame(function () {
			ensureQueued = false;
			ensureTabButton();
		});
	}).observe(document.body, { childList: true, subtree: true });
	ensureTabButton();

	var $lastTrigger = null;

	function openModal(event) {
		$lastTrigger = $(event.currentTarget);
		$('#dp-elementor-tab-modal').addClass('is-open').attr('aria-hidden', 'false');
	}

	function closeModal() {
		var $modal = $('#dp-elementor-tab-modal');

		// Setting aria-hidden="true" while a descendant (e.g. this button
		// itself, right after being clicked) still holds focus is an a11y
		// violation browsers warn about — move focus out first.
		if ($modal.find(document.activeElement).length) {
			document.activeElement.blur();
		}
		if ($lastTrigger && $lastTrigger.length) {
			$lastTrigger.trigger('focus');
		}

		$modal.removeClass('is-open').attr('aria-hidden', 'true');
	}

	$(document).on('click', '[data-tab="rowsprout-template"]', openModal);
	$(document).on('click', '.dp-elementor-tab-close, .dp-elementor-tab-backdrop', closeModal);

	function setSaveError(message, type) {
		var $error = $('#dp-elementor-tab-error');
		if (!$error.length) {
			$error = $('<div id="dp-elementor-tab-error" class="notice" style="margin:0 0 12px;padding:8px 12px;"></div>');
			$('.dp-elementor-tab-body').prepend($error);
		}
		$error
			.removeClass('notice-error notice-warning')
			.addClass(type === 'warning' ? 'notice-warning' : 'notice-error');
		if (message) {
			$error.text(message).show();
		} else {
			$error.hide();
		}
	}

	$(document).on('click', '#dp-elementor-tab-save', function () {
		var $btn = $(this);
		var $body = $('.dp-elementor-tab-body');

		// .find(':input') (not a <form>, so plain .serializeArray() on the
		// wrapper itself would try to serialize the wrapper <div>, not its
		// descendants) correctly picks up every dp_columns/dp_all_columns/
		// dp_groups/rowsprout_page_href input, including bracket-notation
		// array fields — sent as normal urlencoded form data below, which
		// PHP's own $_POST parsing already understands natively.
		var fields = $body.find(':input').serializeArray();
		fields.push({ name: 'action', value: config.ajaxAction });
		fields.push({ name: 'nonce', value: config.saveNonce });
		fields.push({ name: 'post_id', value: String(config.postId) });

		setSaveError('');
		$btn.prop('disabled', true);

		$.post(ajaxurl, $.param(fields))
			.done(function (response) {
				if (!response || !response.success) {
					var message = (response && response.data && response.data.message) || 'Opslaan is mislukt.';
					setSaveError(message);
					return;
				}

				// Keep this tab's own hidden dp_config_version input in sync
				// with what was just saved (no longer compared against by
				// this endpoint itself, but still read by the classic
				// screen's own save flow if that's ever loaded from the
				// same markup).
				if (response.data && response.data.config_updated_at) {
					$('.dp-elementor-tab-body input[name="dp_config_version"]').val(response.data.config_updated_at);
				}

				// Saved, but with a warning (e.g. the href has no href token):
				// keep the modal open so the warning stays in view next to
				// the field it is about — a toast disappears too quickly.
				var warning = response.data && response.data.warning;
				if (warning) {
					setSaveError(warning, 'warning');
					$('#dp-elementor-tab-error')[0].scrollIntoView({ block: 'nearest' });
					return;
				}

				closeModal();
			})
			.fail(function () {
				setSaveError('Opslaan is mislukt door een netwerk- of serverfout. Probeer het opnieuw.');
			})
			.always(function () {
				$btn.prop('disabled', false);
			});
	});

	// "Voorbeeld verversen" button (rowsprout_page_apply_preview_group,
	// registered alongside PREVIEW_GROUP_SETTING_KEY) — a plain document
	// setting change doesn't trigger Elementor to re-render existing
	// widgets, so this forces a lightweight autosave (confirmed server-side
	// to skip our own queueing/regeneration side effects — see
	// TemplateEditorTab::registerControls()'s docblock) and then reloads
	// the preview iframe, same pattern Elementor Pro's own Theme Builder
	// "Apply & Preview" uses (assets/js/editor.js: saveAndReload()).
	if (window.elementor && elementor.channels && elementor.channels.editor) {
		elementor.channels.editor.on('rowsproutPageEditor:ApplyPreviewGroup', function () {
			if (!window.$e || !$e.run) {
				return;
			}

			$e.run('document/save/auto', {
				force: true,
				onSuccess: function () {
					elementor.reloadPreview();
				}
			});
		});
	}
});
