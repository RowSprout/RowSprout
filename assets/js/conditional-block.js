/**
 * Editor side of the "If / then" block (Blocks\ConditionalBlock renders it).
 *
 * - rowsprout/conditional: the rules in the sidebar (the shape of
 *   Core\Conditions\RuleSet: rule_property, join, op_<kind>, value, value2;
 *   the operator list follows the chosen property's kind), the preview note
 *   from the server (POST rowsprout/v1/templates/<id>/conditions, for the
 *   template's preview group), and two fixed inner blocks.
 * - rowsprout/conditional-then / -else: areas holding any blocks.
 * - The canvas shows both areas, the one the preview group gets marked; the
 *   toolbar shows only Then or only Else (editor only, not saved).
 */
(function (wp, data) {
	'use strict';

	if (!wp || !wp.blocks || !wp.blockEditor || !data) {
		return;
	}

	var el = wp.element.createElement;
	var Fragment = wp.element.Fragment;
	var useState = wp.element.useState;
	var useEffect = wp.element.useEffect;
	var __ = wp.i18n.__;
	var C = wp.components;
	var BE = wp.blockEditor;
	var NAME = 'rowsprout/conditional';
	var THEN = 'rowsprout/conditional-then';
	var ELSE = 'rowsprout/conditional-else';

	function property(code) {
		for (var i = 0; i < data.properties.length; i++) {
			if (data.properties[i].value === code) {
				return data.properties[i];
			}
		}
		return null;
	}

	function operatorList(kind) {
		return (data.operators && data.operators[kind]) || [];
	}

	function argCount(kind, operator) {
		var list = operatorList(kind);
		for (var i = 0; i < list.length; i++) {
			if (list[i].value === operator) {
				return list[i].args;
			}
		}
		return 0;
	}

	// The operator a rule uses for its property's kind, as the server picks it.
	function operatorOf(rule, kind) {
		var chosen = rule['op_' + kind];
		return operatorList(kind).some(function (o) { return o.value === chosen; }) ? chosen : (data.defaults[kind] || 'is_not_empty');
	}

	function RuleEditor(props) {
		var rule = props.rule;
		var index = props.index;
		var prop = property(rule.rule_property || '');
		var kind = prop ? prop.kind : '';
		var operator = prop ? operatorOf(rule, kind) : '';
		var args = prop ? argCount(kind, operator) : 0;
		var change = function (key, value) {
			var next = Object.assign({}, rule);
			next[key] = value;
			props.onChange(next);
		};
		var properties = [{ value: '', label: __('-- Select --', 'rowsprout') }].concat(data.properties.map(function (p) {
			return { value: p.value, label: p.label };
		}));
		if (rule.rule_property && !prop) {
			// A property the template no longer has: keep it visible.
			properties.push({ value: rule.rule_property, label: rule.rule_property + ' (' + __('not in this template', 'rowsprout') + ')' });
		}

		return el(
			'div',
			{ className: 'rowsprout-conditional-rule' },
			index > 0 && el(C.SelectControl, {
				label: __('And / or', 'rowsprout'),
				value: rule.join === 'or' ? 'or' : 'and',
				options: [{ value: 'and', label: __('and', 'rowsprout') }, { value: 'or', label: __('or', 'rowsprout') }],
				onChange: function (v) { change('join', v); },
				__nextHasNoMarginBottom: true
			}),
			el(C.SelectControl, {
				label: __('Property', 'rowsprout'),
				value: rule.rule_property || '',
				options: properties,
				onChange: function (v) {
					var next = Object.assign({}, rule, { rule_property: v });
					var chosen = property(v);
					// A newly chosen property starts on its kind's default operator.
					if (chosen && !operatorList(chosen.kind).some(function (o) { return o.value === next['op_' + chosen.kind]; })) {
						next['op_' + chosen.kind] = data.defaults[chosen.kind] || 'is_not_empty';
					}
					props.onChange(next);
				},
				__nextHasNoMarginBottom: true
			}),
			prop && el(C.SelectControl, {
				label: __('Operator', 'rowsprout'),
				value: operator,
				options: operatorList(kind).map(function (o) { return { value: o.value, label: o.label }; }),
				onChange: function (v) { change('op_' + kind, v); },
				__nextHasNoMarginBottom: true
			}),
			prop && args > 0 && el(C.TextControl, {
				label: __('Value', 'rowsprout'),
				value: rule.value || '',
				placeholder: kind === 'date' ? (operator === 'year_is' ? '2026' : (operator === 'next_days' || operator === 'last_days' ? '30' : 'YYYY-MM-DD')) : '',
				onChange: function (v) { change('value', v); },
				__nextHasNoMarginBottom: true
			}),
			prop && args > 1 && el(C.TextControl, {
				label: __('And', 'rowsprout'),
				value: rule.value2 || '',
				placeholder: kind === 'date' ? 'YYYY-MM-DD' : '',
				onChange: function (v) { change('value2', v); },
				__nextHasNoMarginBottom: true
			}),
			el(C.Button, {
				variant: 'link',
				isDestructive: true,
				onClick: props.onRemove,
				disabled: !props.canRemove
			}, __('Remove rule', 'rowsprout'))
		);
	}

	function ConditionalEdit(props) {
		var rules = Array.isArray(props.attributes.rules) && props.attributes.rules.length ? props.attributes.rules : [{ join: 'and' }];
		var modeState = useState('both');
		var mode = modeState[0];
		var previewState = useState(null);
		var preview = previewState[0];
		var elseEmpty = wp.data.useSelect(function (select) {
			var block = select('core/block-editor').getBlock(props.clientId);
			var area = block && block.innerBlocks ? block.innerBlocks.filter(function (b) { return b.name === ELSE; })[0] : null;
			return !area || area.innerBlocks.length === 0;
		}, [props.clientId]);
		var key = JSON.stringify(rules) + (elseEmpty ? '1' : '0');

		useEffect(function () {
			if (!data.restPath) {
				return undefined;
			}
			var cancelled = false;
			var timer = setTimeout(function () {
				wp.apiFetch({ path: data.restPath, method: 'POST', data: { rules: rules, elseEmpty: elseEmpty } }).then(function (result) {
					if (!cancelled) {
						previewState[1](result);
					}
				}).catch(function () {
					if (!cancelled) {
						previewState[1](null);
					}
				});
			}, 300);
			return function () {
				cancelled = true;
				clearTimeout(timer);
			};
		}, [key]);

		var setRules = function (next) {
			props.setAttributes({ rules: next });
		};
		var className = 'rs-cond is-showing-' + mode;
		if (preview && preview.hasGroup) {
			className += preview.matches ? ' rs-cond-preview-then' : ' rs-cond-preview-else';
		}
		var blockProps = BE.useBlockProps({ className: className });
		var innerProps = BE.useInnerBlocksProps(blockProps, {
			template: [[THEN], [ELSE]],
			templateLock: 'all',
			allowedBlocks: [THEN, ELSE]
		});
		var views = [
			{ value: 'both', label: __('Both', 'rowsprout') },
			{ value: 'then', label: __('Then', 'rowsprout') },
			{ value: 'else', label: __('Else', 'rowsprout') }
		];

		return el(
			Fragment,
			null,
			el(BE.BlockControls, { group: 'other' }, el(C.ToolbarGroup, null, views.map(function (v) {
				return el(C.ToolbarButton, { key: v.value, isPressed: mode === v.value, onClick: function () { modeState[1](v.value); } }, v.label);
			}))),
			el(
				BE.InspectorControls,
				null,
				el(
					C.PanelBody,
					{ title: __('If', 'rowsprout') },
					preview && preview.notes && preview.notes.length > 0 && el('div', { className: 'rowsprout-conditional-panel-note' }, preview.notes.map(function (n, i) {
						return el('div', { key: i }, n);
					})),
					el('p', { className: 'components-base-control__help' }, __('Each rule is joined to the one above with "and" or "or"; "and" goes first, so "A and B or C" means "(A and B) or C".', 'rowsprout')),
					rules.map(function (rule, i) {
						return el(RuleEditor, {
							key: i,
							rule: rule,
							index: i,
							canRemove: rules.length > 1,
							onChange: function (next) {
								var copy = rules.slice();
								copy[i] = next;
								setRules(copy);
							},
							onRemove: function () {
								var copy = rules.slice();
								copy.splice(i, 1);
								setRules(copy.length ? copy : [{ join: 'and' }]);
							}
						});
					}),
					el(C.Button, { variant: 'secondary', onClick: function () { setRules(rules.concat([{ join: 'and' }])); } }, __('Add rule', 'rowsprout'))
				)
			),
			el('div', innerProps)
		);
	}

	function areaEdit(label, modifier) {
		return function () {
			var blockProps = BE.useBlockProps({ className: 'rs-cond-area rs-cond-area--' + modifier });
			var innerProps = BE.useInnerBlocksProps({ className: 'rs-cond-area-blocks' }, { templateLock: false, renderAppender: BE.InnerBlocks.ButtonBlockAppender });
			return el(
				'div',
				blockProps,
				el('div', { className: 'rs-cond-area-label' }, label, el('span', { className: 'rs-cond-area-preview' }, __('Preview group', 'rowsprout'))),
				el('div', innerProps)
			);
		};
	}

	wp.blocks.registerBlockType(NAME, {
		// The whole of supports (it replaces the server's): only insertable in a RowSprout template.
		supports: { html: false, align: ['wide', 'full'], spacing: { margin: true, padding: true }, inserter: !!data.isTemplate },
		edit: ConditionalEdit,
		save: function () {
			return el(BE.InnerBlocks.Content);
		}
	});
	wp.blocks.registerBlockType(THEN, {
		edit: areaEdit(__('Then', 'rowsprout'), 'then'),
		save: function () {
			return el(BE.InnerBlocks.Content);
		}
	});
	wp.blocks.registerBlockType(ELSE, {
		edit: areaEdit(__('Else', 'rowsprout'), 'else'),
		save: function () {
			return el(BE.InnerBlocks.Content);
		}
	});
}(window.wp, window.rowsproutConditionalBlock));
