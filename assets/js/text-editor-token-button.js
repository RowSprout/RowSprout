/**
 * TinyMCE plugin for Elementor's Text Editor widget: a "RowSprout property" menu
 * button that inserts a template property's @code_<code>_<templateId>@
 * placeholder token. The list of properties and the labels come from the
 * editor setting "rowsprout_tokens" (TextEditorTokenButton::addSettings()).
 */
(function (tinymce) {
	'use strict';

	if (!tinymce) {
		return;
	}

	tinymce.PluginManager.add('rowsprout_tokens', function (editor) {
		var settings = editor.getParam('rowsprout_tokens') || {};
		var fields = settings.fields || [];
		var i18n = settings.i18n || {};

		function insert(html) {
			editor.focus();
			editor.insertContent(html);
			// Elementor saves the control on keyup/change; make sure an
			// insert without typing afterwards is saved too.
			editor.fire('change');
		}

		function insertText(field) {
			insert(editor.dom.encode(field.token));
		}

		function insertLink(field, hrefPrefix) {
			// Selected text becomes the link text; otherwise the value
			// itself is shown.
			var selected = editor.selection.getContent({ format: 'text' });
			var text = selected !== '' ? selected : field.token;

			insert(editor.dom.createHTML('a', { href: hrefPrefix + field.token }, editor.dom.encode(text)));
		}

		// Types that can also be inserted as a link: type => [label key, href prefix].
		var linkTypes = {
			email: ['asEmailLink', 'mailto:'],
			url: ['asLink', '']
		};

		function buildMenu() {
			if (!fields.length) {
				return [{ text: i18n.empty || '', disabled: true }];
			}

			return fields.map(function (field) {
				var text = field.typeLabel ? field.label + ' (' + field.typeLabel + ')' : field.label;
				var link = linkTypes[field.type];

				if (link) {
					return {
						text: text,
						menu: [
							{ text: i18n.asText || '', onclick: function () { insertText(field); } },
							{ text: i18n[link[0]] || '', onclick: function () { insertLink(field, link[1]); } }
						]
					};
				}

				return { text: text, onclick: function () { insertText(field); } };
			});
		}

		editor.addButton('rowsprout_token', {
			type: 'menubutton',
			icon: 'dashicon dashicons-database',
			tooltip: i18n.button || '',
			menu: buildMenu()
		});
	});
})(window.tinymce);
