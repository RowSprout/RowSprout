/**
 * WPBakery's editors for "If / then" (ThirdParty\WPBakery\ConditionalElement;
 * data in window.rowsproutConditionalWpb):
 *
 * - the edit window: the preview note (the rules as they are in the form
 *   right now, from the panel's getParams(), go to POST
 *   rowsprout/v1/templates/<id>/conditions, answered for the template's
 *   preview group), and per rule "Value" / "And" only for an operator that
 *   takes them (WPBakery's dependencies cannot say that inside a group);
 * - the backend editor: "If / then", "Then" and "Else" labels, and the area
 *   the preview group gets marked (its saved rules, the same route).
 */
(function ($, data) {
	'use strict';

	if (!$ || !data) {
		return;
	}

	var i18n = data.i18n || {};
	var timer = null;
	var asked = {};

	function post(rules, elseEmpty) {
		return $.ajax({
			url: data.rest,
			method: 'POST',
			contentType: 'application/json',
			data: JSON.stringify({ rules: rules, elseEmpty: elseEmpty }),
			headers: { 'X-WP-Nonce': data.nonce }
		});
	}

	function parseRules(raw) {
		if (!raw) {
			return [];
		}
		try {
			var rules = JSON.parse(decodeURIComponent(raw));
			return Array.isArray(rules) ? rules : [];
		} catch (e) {
			return [];
		}
	}

	function childOf(id, shortcode) {
		return window.vc && vc.shortcodes ? vc.shortcodes.where({ parent_id: id, shortcode: shortcode })[0] : null;
	}

	function isEmpty(model) {
		return !model || vc.shortcodes.where({ parent_id: model.get('id') }).length === 0;
	}

	// The edit window.
	function panel() {
		return $('.vc_ui-panel-window[data-vc-shortcode="rowsprout_if"]:visible').first();
	}

	function updateNote() {
		var $note = panel().find('.rowsprout-wpb-note').first();
		var view = window.vc && vc.edit_element_block_view;
		if (!$note.length || !view || typeof view.getParams !== 'function') {
			return;
		}
		var rules = parseRules(view.getParams().rules);
		var elseEmpty = view.model ? isEmpty(childOf(view.model.get('id'), 'rowsprout_else')) : false;
		post(rules, elseEmpty).done(function (result) {
			$note.empty();
			(result && Array.isArray(result.notes) ? result.notes : []).forEach(function (text) {
				$('<div/>').text(text).appendTo($note);
			});
		});
	}

	// Per rule: "Value" and "And" only when its operator takes them.
	function toggleValues() {
		panel().find('.vc_param_group-list > .vc_param').each(function () {
			var $rule = $(this);
			var $operator = $rule.find('[data-vc-shortcode-param-name^="rules_op_"]').filter(function () {
				return $(this).css('display') !== 'none';
			}).first().find('select');
			var hasProperty = $.trim($rule.find('[data-vc-shortcode-param-name="rules_rule_property"] select').val() || '') !== '';
			var args = hasProperty && $operator.length ? (data.args[$operator.val()] || 0) : 0;
			$rule.find('[data-vc-shortcode-param-name="rules_value"]').toggle(args >= 1);
			$rule.find('[data-vc-shortcode-param-name="rules_value2"]').toggle(args >= 2);
		});
	}

	function onPanelChange() {
		clearTimeout(timer);
		timer = setTimeout(function () {
			toggleValues();
			updateNote();
		}, 250);
	}

	// The backend editor: labels and the preview group's area.
	function label(text, modifier) {
		return $('<div class="rs-wpb-label rs-wpb-label--' + modifier + '"/>').text(text)
			.append(modifier === 'if' ? null : $('<span class="rs-wpb-preview"/>').text(i18n.previewGroup || ''));
	}

	function decorate() {
		if (!window.vc || !vc.shortcodes) {
			return;
		}
		vc.shortcodes.where({ shortcode: 'rowsprout_if' }).forEach(function (model) {
			var id = model.get('id');
			var $if = $('[data-model-id="' + id + '"]');
			if (!$if.length) {
				return;
			}
			if (!$if.children('.rs-wpb-label').length) {
				$if.children('.vc_controls').first().after(label(i18n.ifThen, 'if'));
			}
			var then = childOf(id, 'rowsprout_then');
			var other = childOf(id, 'rowsprout_else');
			[[then, i18n.then, 'then'], [other, i18n['else'], 'else']].forEach(function (area) {
				var $area = area[0] ? $('[data-model-id="' + area[0].get('id') + '"]') : $();
				if ($area.length && !$area.children('.rs-wpb-label').length) {
					$area.children('.vc_controls').first().after(label(area[1], area[2]));
				}
			});
			var raw = (model.get('params') || {}).rules || '';
			var key = id + '|' + raw + '|' + (isEmpty(other) ? 1 : 0);
			if (asked[id] === key) {
				return;
			}
			asked[id] = key;
			post(parseRules(raw), isEmpty(other)).done(function (result) {
				var matches = !!(result && result.hasGroup && result.matches);
				var known = !!(result && result.hasGroup);
				if (then) {
					$('[data-model-id="' + then.get('id') + '"]').toggleClass('rs-wpb-is-preview', known && matches);
				}
				if (other) {
					$('[data-model-id="' + other.get('id') + '"]').toggleClass('rs-wpb-is-preview', known && !matches);
				}
			});
		});
	}

	var decorateTimer = null;
	function later() {
		clearTimeout(decorateTimer);
		decorateTimer = setTimeout(decorate, 200);
	}

	$(document).on('change keyup', '.vc_ui-panel-window[data-vc-shortcode="rowsprout_if"] :input', onPanelChange);
	$(document).on('click', '.vc_ui-panel-window[data-vc-shortcode="rowsprout_if"] .vc_param_group-add_content, .vc_ui-panel-window[data-vc-shortcode="rowsprout_if"] .vc_param_group-controls', onPanelChange);
	if (window.MutationObserver) {
		new MutationObserver(function () {
			var $note = panel().find('.rowsprout-wpb-note');
			if ($note.length && !$note.data('rsDone')) {
				$note.data('rsDone', true);
				onPanelChange();
			}
			// WPBakery builds a rule's fields when it is opened.
			if ($note.length) {
				toggleValues();
			}
			later();
		}).observe(document.body, { childList: true, subtree: true });
	}
	$(later);
}(window.jQuery, window.rowsproutConditionalWpb));
