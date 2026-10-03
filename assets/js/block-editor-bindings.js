/**
 * Editor side of the "rowsprout/property" Block Bindings source
 * (Core\BlockBindings\PropertyBindingSource renders it on the page).
 *
 * - getFieldsList: every template property, offered in the block's
 *   Attributes panel. Every bindable text/URL/date attribute is a string, so
 *   all fields are type "string"; what each attribute gets is decided when
 *   formatting.
 * - getValues: the preview shown in the editor, already formatted by the
 *   server (PropertyBindingSource::editorFields(), the same format() the page
 *   uses) for each kind of attribute. An empty property shows the field's
 *   label, so the author sees what the block is bound to.
 * - Values are read-only here: they are edited per group in the RowSprout
 *   template box, not in the block.
 *
 * The list is refreshed after every save by template-block-editor.js.
 */
(function (wp, config) {
	'use strict';

	if (!wp || !config || !wp.blocks || typeof wp.blocks.registerBlockBindingsSource !== 'function') {
		return;
	}

	var i18n = config.i18n || {};
	var RICH = ['content', 'text', 'caption'];

	function fields() {
		return config.bindingFields || [];
	}

	function findField(key) {
		var list = fields();
		for (var i = 0; i < list.length; i++) {
			if (list[i].key === key) {
				return list[i];
			}
		}
		return null;
	}

	function previewFor(field, attribute) {
		var preview = field.preview || {};
		if (attribute === 'url') {
			return preview.url;
		}
		if (attribute === 'datetime') {
			return preview.datetime;
		}
		if (RICH.indexOf(attribute) !== -1) {
			return preview.content;
		}
		return preview.plain;
	}

	wp.blocks.registerBlockBindingsSource({
		name: 'rowsprout/property',
		label: i18n.bindingLabel,
		usesContext: ['postId', 'postType'],
		getFieldsList: function () {
			return fields().map(function (field) {
				return { label: field.label, type: 'string', args: { key: field.key } };
			});
		},
		getValues: function (options) {
			var values = {};
			Object.keys(options.bindings || {}).forEach(function (attribute) {
				var binding = options.bindings[attribute] || {};
				var field = findField(binding.args && binding.args.key);
				var value = field ? previewFor(field, attribute) : null;
				values[attribute] = value !== null && value !== undefined ? value : (field ? field.label : attribute);
			});
			return values;
		},
		canUserEditValue: function () {
			return false;
		}
	});
})(window.wp, window.rowsproutTemplateBlockEditor);
