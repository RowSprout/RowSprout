/**
 * Block editor counterpart of the Elementor Text Editor button
 * (text-editor-token-button.js): a rich-text toolbar button in every text
 * block that inserts a template property's @code_<code>_<templateId>@ token
 * at the cursor, inline with the text. The token is resolved when a page is
 * generated (MarkupTokenReplacer), like any typed token.
 *
 * The property list comes from window.rowsproutTemplateBlockEditor.tokenFields
 * (Core\Template\InsertableTokenFields) and is refreshed after every save by
 * template-block-editor.js, so it is read at click time, not cached.
 *
 * Email and URL properties can also be inserted as a link (core/link format
 * with the token as href; mailto: for email). With text selected, a link wraps
 * the selection instead of inserting the token as text.
 */
(function (wp, config) {
	'use strict';

	if (!wp || !config || !wp.richText || !wp.blockEditor || !wp.blockEditor.RichTextToolbarButton) {
		return;
	}

	var el = wp.element.createElement;
	var useState = wp.element.useState;
	var richText = wp.richText;
	var components = wp.components;
	var i18n = config.i18n || {};

	var NAME = 'rowsprout/property-token';
	var LINK_PREFIX = { email: 'mailto:', url: '' };

	function insertToken(value, field, asLink) {
		if (!asLink) {
			return richText.insert(value, field.token);
		}

		var format = { type: 'core/link', attributes: { url: LINK_PREFIX[field.type] + field.token } };
		if (value.start !== value.end) {
			return richText.applyFormat(value, format, value.start, value.end);
		}

		var start = value.start;
		var inserted = richText.insert(value, field.token);
		return richText.applyFormat(inserted, format, start, start + field.token.length);
	}

	var settings = {
		title: i18n.tokenButton,
		// Required by registerFormatType; this format is never applied itself.
		tagName: 'span',
		className: 'rowsprout-property-token',
		edit: TokenButton
	};

	function TokenButton(props) {
		var openState = useState(false);
		var isOpen = openState[0];
		var setOpen = openState[1];
		var anchor = richText.useAnchor
			? richText.useAnchor({ editableContentElement: props.contentRef && props.contentRef.current, settings: settings })
			: undefined;

		function choose(field, asLink) {
			props.onChange(insertToken(props.value, field, asLink));
			setOpen(false);
		}

		var items = [];
		(config.tokenFields || []).forEach(function (field) {
			var label = field.typeLabel ? field.label + ' (' + field.typeLabel + ')' : field.label;
			items.push(el(components.MenuItem, { key: field.token, onClick: function () { choose(field, false); } }, label));
			if (Object.prototype.hasOwnProperty.call(LINK_PREFIX, field.type)) {
				var suffix = field.type === 'email' ? i18n.tokenAsEmailLink : i18n.tokenAsLink;
				items.push(el(components.MenuItem, { key: field.token + '-link', onClick: function () { choose(field, true); } }, label + ' — ' + suffix));
			}
		});

		return el(
			wp.element.Fragment,
			null,
			el(wp.blockEditor.RichTextToolbarButton, {
				icon: 'database',
				title: i18n.tokenButton,
				isActive: isOpen,
				onClick: function () { setOpen(!isOpen); }
			}),
			isOpen ? el(
				components.Popover,
				{ anchor: anchor, placement: 'bottom-start', onClose: function () { setOpen(false); }, focusOnMount: 'firstElement' },
				el(
					'div',
					// No own max-height/overflow: the Popover already scrolls its
					// content, and a second scroll container showed two bars.
					{ style: { minWidth: '260px', padding: '4px' } },
					items.length
						? el(components.MenuGroup, { label: i18n.tokenButton }, items)
						: el('p', { style: { margin: 0, padding: '8px', maxWidth: '260px' } }, i18n.tokenEmpty)
				)
			) : null
		);
	}

	richText.registerFormatType(NAME, settings);

	// Every token ends with "@", which opens core's "@mention a user"
	// autocompleter right after an insert (or while typing a token by hand).
	// Mentions mean nothing in a template, and this script only loads on the
	// template screen, so drop that one completer here. Priority 20: after
	// core adds it (editor/autocompleters/set-default-completers, 10).
	if (wp.hooks) {
		wp.hooks.addFilter('editor.Autocomplete.completers', 'rowsprout/no-user-mentions', function (completers) {
			return (completers || []).filter(function (completer) {
				return !completer || completer.name !== 'users';
			});
		}, 20);
	}
})(window.wp, window.rowsproutTemplateBlockEditor);
