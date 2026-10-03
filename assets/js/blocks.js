/**
 * Editor side of the Grid block (Blocks\BlockRegistry renders it), registered
 * everywhere like the Elementor Grid widget. Dynamic: save() returns null and
 * the editor shows the server's own output through ServerSideRender, so the
 * preview is the real markup.
 */
(function (wp, data) {
	'use strict';

	if (!wp || !wp.blocks || !wp.blockEditor || !wp.serverSideRender || !data) {
		return;
	}

	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var C = wp.components;
	var InspectorControls = wp.blockEditor.InspectorControls;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var ServerSideRender = wp.serverSideRender;

	function preview(name, attributes) {
		return el(ServerSideRender, { block: name, attributes: attributes, skipBlockSupportAttributes: true });
	}

	wp.blocks.registerBlockType('rowsprout/page-grid', {
		edit: function (props) {
			var a = props.attributes;
			var set = props.setAttributes;
			return el(
				'div',
				useBlockProps(),
				el(
					InspectorControls,
					null,
					el(
						C.PanelBody,
						{ title: __('Settings', 'rowsprout') },
						el(C.SelectControl, {
							label: __('RowSprout template', 'rowsprout'),
							value: a.templateId,
							options: data.templates,
							onChange: function (v) { set({ templateId: v }); },
							__nextHasNoMarginBottom: true
						}),
						el(C.SelectControl, {
							label: __('Layout', 'rowsprout'),
							value: a.layout,
							options: [
								{ value: 'list', label: __('List', 'rowsprout') },
								{ value: 'cards', label: __('Cards', 'rowsprout') }
							],
							onChange: function (v) { set({ layout: v }); },
							__nextHasNoMarginBottom: true
						}),
						el(C.RangeControl, {
							label: __('Columns', 'rowsprout'),
							value: a.columns,
							min: 1,
							max: 6,
							onChange: function (v) { set({ columns: v || 1 }); },
							__nextHasNoMarginBottom: true
						}),
						a.layout === 'cards' ? el(C.ToggleControl, {
							label: __('Show thumbnail', 'rowsprout'),
							checked: a.showThumb,
							onChange: function (v) { set({ showThumb: v }); },
							__nextHasNoMarginBottom: true
						}) : null,
						a.layout === 'cards' && a.showThumb ? el(C.SelectControl, {
							label: __('Thumbnail size', 'rowsprout'),
							value: a.thumbSize,
							options: [
								{ value: 'thumbnail', label: __('Thumbnail', 'rowsprout') },
								{ value: 'medium', label: __('Medium', 'rowsprout') },
								{ value: 'large', label: __('Large', 'rowsprout') },
								{ value: 'full', label: __('Full', 'rowsprout') }
							],
							onChange: function (v) { set({ thumbSize: v }); },
							__nextHasNoMarginBottom: true
						}) : null,
						el(C.SelectControl, {
							label: __('Show title', 'rowsprout'),
							value: a.titleType,
							options: [
								{ value: 'parent', label: __('Parent title', 'rowsprout') },
								{ value: 'child', label: __('Child title', 'rowsprout') }
							],
							onChange: function (v) { set({ titleType: v }); },
							__nextHasNoMarginBottom: true
						}),
						el(C.SelectControl, {
							label: __('Sort items', 'rowsprout'),
							value: a.sortOrder,
							options: [
								{ value: '', label: __('No sorting', 'rowsprout') },
								{ value: 'asc', label: __('Alphabetical (A-Z)', 'rowsprout') },
								{ value: 'desc', label: __('Alphabetical (Z-A)', 'rowsprout') }
							],
							onChange: function (v) { set({ sortOrder: v }); },
							__nextHasNoMarginBottom: true
						})
					)
				),
				preview('rowsprout/page-grid', a)
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp, window.rowsproutBlocks);
