/**
 * "Import and export groups" on the template's General tab
 * (Admin\GroupCsv\GroupCsvSection): the download button and the import
 * wizard, modelled on Excel's Text Import Wizard.
 *
 * The file is read and split here; GroupCsvImporter decides everything else.
 * Step 4 asks it for a dry run (what would be added, updated, skipped), the
 * Import button sends the same request for real. The import saves the
 * template on the server, so the editor reloads afterwards.
 *
 * None of these controls has a name: the template form must never post them.
 */
(function($) {
	'use strict';

	var cfg = window.rowsproutGroupCsvConfig;
	if (!cfg) {
		return;
	}
	var i18n = cfg.i18n || {};

	var DELIMITERS = { tab: '\t', semicolon: ';', comma: ',', space: ' ' };
	var PREVIEW_ROWS = 20;
	var EXAMPLES = 3;
	var LAST_STEP = 4;

	// Exposed as window.rowsproutGroupCsv for the jsdom tests in
	// rowsprout-support, which replace navigate().
	var api = {
		navigate: function(url) {
			if (url) {
				window.location.href = url;
			} else {
				window.location.reload();
			}
		}
	};

	var state = {
		step: 1,
		buffer: null,
		text: '',
		records: [],
		mapping: [],
		newProperties: [],
		busy: false,
		saved: false
	};

	// sprintf for the translated strings: %s, %d and %1$s style.
	function fmt(text) {
		var args = Array.prototype.slice.call(arguments, 1);
		var next = 0;
		return String(text || '').replace(/%(?:(\d+)\$)?[sd]/g, function(match, position) {
			var value = position ? args[parseInt(position, 10) - 1] : args[next++];
			return value === undefined ? '' : String(value);
		});
	}

	function esc(value) {
		return String(value).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
	}

	function normalizeHeader(value) {
		return String(value || '').replace(/\s+/g, ' ').trim().toLowerCase();
	}

	/* ------------------------------------------------------------------
	 * Unsaved changes. The import saves on the server and reloads the
	 * editor, which throws away whatever was not saved. An edit only counts
	 * while the config version is still the one it was made under: both
	 * the block editor and the Elementor modal refresh dp_config_version
	 * after they save, without reloading.
	 * ------------------------------------------------------------------ */

	var $section = $('[data-dp-group-csv]').first();
	var $modal = $('#dp-csv-modal');
	if (!$section.length || !$modal.length) {
		return;
	}
	// Inside the block editor's metabox area a fixed-position element is
	// positioned against that area, not the window. The styles are scoped to
	// .dp-csv-modal as well, so they still apply in the Elementor editor.
	$modal.appendTo(document.body);

	function versionInput() {
		var $tabs = $section.closest('[data-dp-template-tabs]');
		var $input = $tabs.prevAll('input[name="dp_config_version"]').first();
		return $input.length ? $input : $('input[name="dp_config_version"]').first();
	}

	function currentVersion() {
		return String(versionInput().val() || '');
	}

	var dirtyVersion = null;
	function markDirty() {
		dirtyVersion = currentVersion();
	}

	$(document).on('input change', '[data-dp-template-tabs] :input', function(e) {
		if ($(e.target).closest('#dp-csv-modal, [data-dp-group-csv]').length) {
			return;
		}
		// A programmatic change (the parent sync, widget refreshes) has no originalEvent.
		if (e.type === 'input' || e.originalEvent) {
			markDirty();
		}
	});
	$(document).on('click', '#dp-add-group-btn, .dp-group-delete, #dp-col-confirm, .dp-col-delete, .dp-thumb-upload, .dp-thumb-remove', markDirty);
	$(document).on('drop', '#dp-props-tbody, #dp-groups-sortable', markDirty);

	function hasUnsavedChanges() {
		if (dirtyVersion !== null && dirtyVersion === currentVersion()) {
			return true;
		}
		try {
			var editor = window.wp && window.wp.data && window.wp.data.select('core/editor');
			if (editor && editor.isEditedPostDirty()) {
				return true;
			}
		} catch (e) { /* not the block editor */ }
		try {
			if (window.wp && window.wp.autosave && window.wp.autosave.server && window.wp.autosave.server.postChanged()) {
				return true;
			}
		} catch (e) { /* not the classic editor */ }
		try {
			if (window.elementor && window.elementor.saver && window.elementor.saver.isEditorChanged()) {
				return true;
			}
		} catch (e) { /* not Elementor */ }
		return false;
	}

	/* ------------------------------------------------------------------
	 * Export
	 * ------------------------------------------------------------------ */

	$('#dp-csv-export').on('click', function(e) {
		e.preventDefault();
		var url = String($(this).data('exportUrl') || '');
		if (url) {
			window.location.href = url + '&format=' + encodeURIComponent(String($('#dp-csv-format').val() || ''));
		}
	});

	/* ------------------------------------------------------------------
	 * Reading and splitting the file
	 * ------------------------------------------------------------------ */

	function decode(buffer, encoding) {
		return new TextDecoder(encoding).decode(buffer);
	}

	// The encoding a byte order mark announces, else UTF-8 when the bytes
	// are valid UTF-8, else Windows-1252: what Excel writes for "CSV (comma
	// delimited)" on a Western European Windows.
	function detectEncoding(buffer) {
		var bytes = new Uint8Array(buffer.slice(0, 3));
		if (bytes[0] === 0xEF && bytes[1] === 0xBB && bytes[2] === 0xBF) {
			return { encoding: 'utf-8', guessed: false };
		}
		if (bytes[0] === 0xFF && bytes[1] === 0xFE) {
			return { encoding: 'utf-16le', guessed: false };
		}
		if (bytes[0] === 0xFE && bytes[1] === 0xFF) {
			return { encoding: 'utf-16be', guessed: false };
		}
		try {
			new TextDecoder('utf-8', { fatal: true }).decode(buffer);
			return { encoding: 'utf-8', guessed: false };
		} catch (e) {
			return { encoding: 'windows-1252', guessed: true };
		}
	}

	/**
	 * RFC 4180, as Excel reads it: a qualifier only opens a quoted value at
	 * the start of a cell, a doubled qualifier inside one is a literal, and
	 * line breaks inside one are part of the value. With "consecutive" a run
	 * of delimiters counts as one.
	 */
	function parse(text, delimiters, qualifier, consecutive) {
		var records = [];
		var row = [];
		var cell = '';
		var inQuotes = false;
		var atCellStart = true;
		var afterDelimiter = false;
		var length = text.length;

		for (var i = 0; i < length; i++) {
			var ch = text.charAt(i);
			if (inQuotes) {
				if (ch === qualifier) {
					if (text.charAt(i + 1) === qualifier) {
						cell += qualifier;
						i++;
					} else {
						inQuotes = false;
					}
				} else {
					cell += ch;
				}
				continue;
			}
			if (qualifier && ch === qualifier && atCellStart) {
				inQuotes = true;
				atCellStart = false;
				afterDelimiter = false;
				continue;
			}
			if (delimiters.indexOf(ch) !== -1) {
				if (!(consecutive && afterDelimiter)) {
					row.push(cell);
					cell = '';
				}
				atCellStart = true;
				afterDelimiter = true;
				continue;
			}
			if (ch === '\r' || ch === '\n') {
				if (ch === '\r' && text.charAt(i + 1) === '\n') {
					i++;
				}
				row.push(cell);
				records.push(row);
				row = [];
				cell = '';
				atCellStart = true;
				afterDelimiter = false;
				continue;
			}
			cell += ch;
			atCellStart = false;
			afterDelimiter = false;
		}
		if (cell !== '' || row.length || inQuotes) {
			row.push(cell);
			records.push(row);
		}
		return records;
	}

	// The delimiter used most on the first line outside quotes; Excel's
	// "sep=;" first line wins when present.
	function detectDelimiter(text) {
		var firstLine = text.split(/\r\n|\r|\n/, 1)[0] || '';
		var sep = /^sep=(.)$/i.exec(firstLine);
		if (sep) {
			return { delimiter: sep[1], sepLine: true };
		}
		var unquoted = firstLine.replace(/"[^"]*"/g, '');
		var best = 'comma';
		var bestCount = 0;
		['tab', 'semicolon', 'comma'].forEach(function(name) {
			var count = unquoted.split(DELIMITERS[name]).length - 1;
			if (count > bestCount) {
				best = name;
				bestCount = count;
			}
		});
		return { delimiter: DELIMITERS[best], sepLine: false };
	}

	function chosenDelimiters() {
		var chars = '';
		$modal.find('[data-csv-delimiter]:checked').each(function() {
			var name = String($(this).data('csvDelimiter'));
			if (name === 'other') {
				chars += String($('#dp-csv-other').val() || '').charAt(0);
			} else if (DELIMITERS[name]) {
				chars += DELIMITERS[name];
			}
		});
		return chars;
	}

	function setDelimiter(delimiter) {
		$modal.find('[data-csv-delimiter]').prop('checked', false);
		var known = false;
		$.each(DELIMITERS, function(name, value) {
			if (value === delimiter) {
				$modal.find('[data-csv-delimiter="' + name + '"]').prop('checked', true);
				known = true;
			}
		});
		if (!known) {
			$modal.find('[data-csv-delimiter="other"]').prop('checked', true);
			$('#dp-csv-other').val(delimiter);
		}
	}

	function startRow() {
		return Math.max(1, parseInt($('#dp-csv-start').val(), 10) || 1);
	}

	function hasHeaders() {
		return $('#dp-csv-headers').is(':checked');
	}

	function reparse() {
		state.records = parse(state.text, chosenDelimiters(), String($('#dp-csv-qualifier').val() || ''), $('#dp-csv-consecutive').is(':checked'));
	}

	// Records from the start row on: the header (or null) and the data rows
	// with their numbers in the file. Rows without any value are left out.
	function table() {
		var rows = state.records.slice(startRow() - 1);
		var header = null;
		var firstNumber = startRow();
		if (hasHeaders() && rows.length) {
			header = rows.shift();
			firstNumber++;
		}
		var data = [];
		var numbers = [];
		rows.forEach(function(row, index) {
			if (row.some(function(cell) { return String(cell).trim() !== ''; })) {
				data.push(row);
				numbers.push(firstNumber + index);
			}
		});
		var width = header ? header.length : 0;
		data.forEach(function(row) { width = Math.max(width, row.length); });
		return { header: header, rows: data, numbers: numbers, width: width };
	}

	/* ------------------------------------------------------------------
	 * Rendering the steps
	 * ------------------------------------------------------------------ */

	// Spreadsheet column names: A … Z, AA, AB …
	function columnLetter(index) {
		var name = '';
		for (var n = index + 1; n > 0; n = Math.floor((n - 1) / 26)) {
			name = String.fromCharCode(65 + (n - 1) % 26) + name;
		}
		return name;
	}

	function delimiterSummary() {
		var names = [];
		$modal.find('[data-csv-delimiter]:checked').each(function() {
			var name = String($(this).data('csvDelimiter'));
			var label = (i18n.delimiterNames || {})[name] || name;
			names.push(name === 'other' ? fmt(label, String($('#dp-csv-other').val() || '').charAt(0)) : label);
		});
		return names.join(', ');
	}

	/**
	 * Step 1: a line that sums up what was recognised (columns, rows,
	 * delimiter), and behind "Show preview" the file split with the detected
	 * (or chosen) settings from its first row on: rows before the start row
	 * dimmed, the header row marked.
	 */
	function renderFilePreview() {
		var $preview = $('#dp-csv-file-preview').empty();
		var $recognised = $('#dp-csv-recognised').prop('hidden', true).text('');
		$('#dp-csv-preview-toggle').prop('hidden', !state.text.trim());
		if (!state.text.trim()) {
			setPreviewOpen(false);
			return;
		}
		reparse();
		var start = startRow();
		var records = state.records.slice(0, Math.max(PREVIEW_ROWS, start + PREVIEW_ROWS - 1));
		var width = 0;
		records.forEach(function(row) { width = Math.max(width, row.length); });

		var $headRow = $('<tr/>').append('<th class="dp-csv-rownum"></th>');
		for (var c = 0; c < width; c++) {
			$headRow.append($('<th class="dp-csv-colname"/>').text(columnLetter(c)));
		}
		var $body = $('<tbody/>');
		records.forEach(function(row, index) {
			var number = index + 1;
			var $tr = $('<tr/>')
				.toggleClass('is-skipped', number < start)
				.toggleClass('is-header', hasHeaders() && number === start)
				.append($('<td class="dp-csv-rownum"/>').text(number));
			for (var c = 0; c < width; c++) {
				var value = String(row[c] === undefined ? '' : row[c]);
				$tr.append($('<td/>').text(value).attr('title', value));
			}
			$body.append($tr);
		});
		$preview.append($('<table/>').append($('<thead/>').append($headRow)).append($body));

		var data = table();
		$recognised.text(fmt(i18n.recognised, data.width, data.rows.length, delimiterSummary())).prop('hidden', false);
	}

	function renderPreview() {
		var $preview = $('#dp-csv-preview').empty();
		reparse();
		var data = table();
		if (!data.rows.length && !data.header) {
			$preview.append($('<p class="dp-csv-empty"/>').text(i18n.noRows));
			return;
		}
		var $table = $('<table/>');
		var $headRow = $('<tr/>').append('<th class="dp-csv-rownum"></th>');
		for (var c = 0; c < data.width; c++) {
			$headRow.append($('<th/>').text(data.header ? String(data.header[c] || '') : fmt(i18n.column, c + 1)));
		}
		$table.append($('<thead/>').append($headRow));
		var $body = $('<tbody/>');
		data.rows.slice(0, PREVIEW_ROWS).forEach(function(row, index) {
			var $tr = $('<tr/>').append($('<td class="dp-csv-rownum"/>').text(data.numbers[index]));
			for (var c = 0; c < data.width; c++) {
				var value = String(row[c] === undefined ? '' : row[c]);
				$tr.append($('<td/>').text(value).attr('title', value));
			}
			$body.append($tr);
		});
		$table.append($body);
		$preview.append($table);
		if (data.rows.length > PREVIEW_ROWS) {
			$preview.after($('<p class="dp-csv-count"/>').text(fmt(i18n.previewCount, PREVIEW_ROWS, data.rows.length)));
		}
	}

	function suggestTarget(header, used) {
		var name = normalizeHeader(header);
		if (name === '') {
			return '';
		}
		if (name === normalizeHeader(cfg.idHeader) && !used.id) {
			return 'id';
		}
		var match = '';
		(cfg.columns || []).forEach(function(column) {
			if (match) {
				return;
			}
			var codes = [normalizeHeader(column.label), String(column.code), String(column.key), '@code_' + column.code];
			if (codes.indexOf(name) !== -1 && !used['prop:' + column.key]) {
				match = 'prop:' + column.key;
			}
		});
		// An unknown header is not imported until the user makes it a new
		// property: a messy file must not add properties unnoticed.
		return match;
	}

	function addableTypes() {
		var used = {};
		(cfg.columns || []).forEach(function(column) { used[column.type] = true; });
		return (cfg.types || []).filter(function(type) {
			return type.allowMultiple || !used[type.type];
		});
	}

	function typeDefinition(type) {
		var found = null;
		(cfg.types || []).forEach(function(definition) {
			if (definition.type === type) {
				found = definition;
			}
		});
		return found;
	}

	// The distinct values of a file column, as options for a new property.
	function columnValues(index) {
		var seen = {};
		var values = [];
		table().rows.forEach(function(row) {
			var value = String(row[index] === undefined ? '' : row[index]).trim();
			if (value !== '' && !seen[value]) {
				seen[value] = true;
				values.push(value);
			}
		});
		return values.slice(0, 200);
	}

	// The choice per file column, kept while the wizard goes back and forth
	// as long as the columns stay the same. New properties made in this run
	// (state.newProperties) are offered to every column; only the ones a
	// column imports into are created.
	function buildMapping() {
		var data = table();
		var signature = data.width + '|' + (data.header ? data.header.join('\u0001') : '');
		if (state.mapping.signature === signature) {
			return;
		}
		var used = {};
		var mapping = [];
		for (var c = 0; c < data.width; c++) {
			var header = data.header ? String(data.header[c] || '').trim() : '';
			var target = data.header ? suggestTarget(header, used) : '';
			if (target) {
				used[target] = true;
			}
			mapping.push({ target: target, header: header });
		}
		mapping.signature = signature;
		state.mapping = mapping;
		state.newProperties = [];
	}

	function columnName(index) {
		var entry = state.mapping[index] || {};
		return entry.header || fmt(i18n.column, index + 1);
	}

	function targetSelect(index, current) {
		var $select = $('<select/>').attr('id', 'dp-csv-target-' + index).attr('data-csv-target', index);
		$select.append($('<option value=""/>').text(i18n.doNotImport));
		$select.append($('<option value="id"/>').text(i18n.idTarget));
		var $group = $('<optgroup/>').attr('label', i18n.properties);
		(cfg.columns || []).forEach(function(column) {
			// A child template's Title and Href (the parent's) only find the row.
			$group.append($('<option/>').val('prop:' + column.key).text(column.matchOnly ? fmt(i18n.findOnly, column.label) : column.label));
		});
		$select.append($group);
		if (state.newProperties.length) {
			var $new = $('<optgroup/>').attr('label', i18n.newPropertiesGroup);
			state.newProperties.forEach(function(property) {
				$new.append($('<option/>').val('newprop:' + property.id).text(property.label));
			});
			$select.append($new);
		}
		$select.append($('<option value="new"/>').text(i18n.newProperty));
		$select.val(current);
		return $select;
	}

	// Column index => the other columns that chose the same target.
	function conflicts() {
		var byTarget = {};
		state.mapping.forEach(function(entry, index) {
			if (entry.target && entry.target !== 'new') {
				(byTarget[entry.target] = byTarget[entry.target] || []).push(index);
			}
		});
		var result = {};
		$.each(byTarget, function(target, indexes) {
			if (indexes.length > 1) {
				indexes.forEach(function(index) {
					result[index] = indexes.filter(function(other) { return other !== index; });
				});
			}
		});
		return result;
	}

	function showConflicts() {
		var found = conflicts();
		state.mapping.forEach(function(entry, index) {
			var $cell = $modal.find('[data-csv-target="' + index + '"]').closest('td');
			var others = found[index];
			$cell.toggleClass('has-conflict', !!others);
			$cell.find('.dp-csv-conflict').remove();
			if (others) {
				$cell.append($('<div class="dp-csv-conflict"/>').text(fmt(i18n.alsoChosen, others.map(columnName).join(', '))));
			}
		});
		return !$.isEmptyObject(found);
	}

	function refreshTargetSelects() {
		state.mapping.forEach(function(entry, index) {
			$modal.find('[data-csv-target="' + index + '"]').replaceWith(targetSelect(index, entry.target));
		});
		showConflicts();
	}

	function renderMapping() {
		reparse();
		buildMapping();
		var data = table();
		var $body = $('#dp-csv-map-body').empty();
		state.mapping.forEach(function(entry, index) {
			var examples = [];
			for (var r = 0; r < data.rows.length && examples.length < EXAMPLES; r++) {
				var value = String(data.rows[r][index] === undefined ? '' : data.rows[r][index]).trim();
				if (value !== '') {
					examples.push(value.length > 60 ? value.slice(0, 57) + '…' : value);
				}
			}
			var $examples = $('<td class="dp-csv-map-examples"/>');
			examples.forEach(function(example) { $examples.append($('<span/>').text(example)); });
			$body.append($('<tr/>')
				.append($('<td/>').append($('<label/>').attr('for', 'dp-csv-target-' + index).text(columnName(index))))
				.append($examples)
				.append($('<td/>').append(targetSelect(index, entry.target))));
		});
		showConflicts();
	}

	/* ------------------------------------------------------------------
	 * The "Add new property" dialog, opened by choosing "New property…"
	 * for a column: the Properties tab's dialog, on top of the wizard.
	 * ------------------------------------------------------------------ */

	var $propModal = $('#dp-csv-prop-modal');
	var propEdit = null;

	function propertyLabelTaken(label) {
		var name = normalizeHeader(label);
		var labels = (cfg.reservedLabels || []).concat(state.newProperties.map(function(property) { return property.label; }));
		return labels.some(function(other) {
			return normalizeHeader(other) === name;
		});
	}

	function showPropertyTypeDetails() {
		var definition = typeDefinition(String($('#dp-csv-prop-type').val() || '')) || {};
		$('#dp-csv-prop-type-description').text(definition.description || '');
		$('#dp-csv-prop-options-row').prop('hidden', !definition.supportsOptions);
	}

	// Types a new property can have: not Title/Href, and not a single-use
	// type the template or an earlier new property already has.
	function newPropertyTypes() {
		var used = {};
		state.newProperties.forEach(function(property) { used[property.type] = true; });
		return addableTypes().filter(function(type) {
			return type.allowMultiple || !used[type.type];
		});
	}

	// revertTo: the column's previous choice, restored on Cancel.
	function openPropertyDialog(index, revertTo) {
		propEdit = { index: index, revertTo: revertTo };
		var $type = $('#dp-csv-prop-type').empty();
		newPropertyTypes().forEach(function(type) {
			$type.append($('<option/>').val(type.type).text(type.label));
		});
		$type.val('textfield');
		if (!$type.val()) {
			$type.prop('selectedIndex', 0);
		}
		$('#dp-csv-prop-options').val(columnValues(index).join('\n'));
		$('#dp-csv-prop-label').val(state.mapping[index].header);
		$('#dp-csv-prop-overruled').prop('checked', true);
		$('#dp-csv-prop-error').prop('hidden', true).text('');
		showPropertyTypeDetails();
		$propModal.prop('hidden', false);
		$('#dp-csv-prop-label').trigger('focus');
	}

	function closePropertyDialog(confirmed) {
		if (!propEdit) {
			return;
		}
		var edit = propEdit;
		propEdit = null;
		$propModal.prop('hidden', true);
		if (!confirmed) {
			state.mapping[edit.index].target = edit.revertTo;
		}
		refreshTargetSelects();
		$modal.find('[data-csv-target="' + edit.index + '"]').trigger('focus');
	}

	function confirmPropertyDialog() {
		if (!propEdit) {
			return;
		}
		var label = String($('#dp-csv-prop-label').val() || '').trim();
		var error = !label ? i18n.labelRequired : (propertyLabelTaken(label) ? i18n.duplicateLabel : '');
		if (error) {
			$('#dp-csv-prop-error').text(error).prop('hidden', false);
			$('#dp-csv-prop-label').trigger('focus');
			return;
		}
		var type = String($('#dp-csv-prop-type').val() || '');
		var definition = typeDefinition(type) || {};
		var property = {
			id: state.newProperties.length,
			label: label,
			type: type,
			overridable: $('#dp-csv-prop-overruled').is(':checked'),
			options: definition.supportsOptions
				? String($('#dp-csv-prop-options').val() || '').split(/\r?\n/).map(function(option) { return option.trim(); }).filter(Boolean)
				: []
		};
		state.newProperties.push(property);
		state.mapping[propEdit.index].target = 'newprop:' + property.id;
		closePropertyDialog(true);
	}

	// Only the new properties a column imports into, numbered in the order
	// the columns use them.
	function payload() {
		var data = table();
		var newProperties = [];
		var numbers = {};
		var mapping = state.mapping.map(function(entry) {
			var match = /^newprop:(\d+)$/.exec(entry.target);
			if (!match) {
				return entry.target === 'new' ? '' : entry.target;
			}
			if (numbers[match[1]] === undefined) {
				var property = state.newProperties[parseInt(match[1], 10)];
				numbers[match[1]] = newProperties.length;
				newProperties.push({ label: property.label, type: property.type, options: property.options, can_be_overruled: property.overridable });
			}
			return 'new:' + numbers[match[1]];
		});
		return { mapping: mapping, new_properties: newProperties, rows: data.rows, row_numbers: data.numbers };
	}

	function request(apply) {
		return $.ajax({
			url: cfg.ajaxUrl,
			method: 'POST',
			dataType: 'json',
			data: {
				action: cfg.action,
				nonce: cfg.nonce,
				template_id: cfg.templateId,
				version: currentVersion(),
				apply: apply ? '1' : '0',
				payload: JSON.stringify(payload())
			}
		});
	}

	function responseData(response) {
		if (response && response.data && typeof response.data === 'object') {
			return response.data;
		}
		return { errors: [i18n.requestFailed] };
	}

	function list(items) {
		var $ul = $('<ul/>');
		items.forEach(function(item) { $ul.append($('<li/>').text(item)); });
		return $ul;
	}

	function renderSummary(result) {
		var $summary = $('#dp-csv-summary').empty();
		$summary.append($('<p class="dp-csv-label"/>').text(i18n.summaryHeading));
		var lines = [];
		if (result.added) { lines.push(fmt(i18n.added, result.added)); }
		if (result.updated) { lines.push(fmt(i18n.updated, result.updated)); }
		if (result.unchanged) { lines.push(fmt(i18n.unchanged, result.unchanged)); }
		if (result.newProperties && result.newProperties.length) { lines.push(fmt(i18n.newProperties, result.newProperties.join(', '))); }
		if (result.skippedCount) { lines.push(fmt(i18n.skipped, result.skippedCount)); }
		var changes = result.added || result.updated || (result.newProperties && result.newProperties.length);
		if (lines.length) {
			$summary.append(list(lines).addClass('dp-csv-summary-list'));
		}
		if (!changes) {
			$summary.append($('<div class="dp-csv-box is-info"/>').append($('<p/>').text(i18n.nothing)));
		}
		var notes = (result.warnings || []).concat(result.skipped || []);
		if (notes.length) {
			$summary.append($('<div class="dp-csv-box"/>')
				.append($('<p/>').append($('<strong/>').text(i18n.warnings)))
				.append(list(notes)));
		}
		if (changes) {
			$summary.append($('<div class="dp-csv-box is-info"/>')
				.append($('<p/>').text(i18n.saveNote))
				.append($('<p/>').text(i18n.generate)));
			if (hasUnsavedChanges()) {
				$summary.append($('<div class="dp-csv-box"/>').append($('<p/>').append($('<strong/>').text(i18n.unsaved))));
			}
		}
		$('#dp-csv-import').prop('disabled', !changes);
	}

	// Only when nothing was saved: a saved import reloads the page right away.
	function renderDone(result) {
		var $done = $('#dp-csv-done').empty();
		$done.append($('<div class="dp-csv-box is-info"/>').append($('<p/>').text(i18n.nothingSaved)));
		var notes = (result.warnings || []).concat(result.skipped || []);
		if (notes.length) {
			$done.append($('<div class="dp-csv-box"/>').append(list(notes)));
		}
	}

	/* ------------------------------------------------------------------
	 * Navigation
	 * ------------------------------------------------------------------ */

	function showError(messages) {
		var $error = $('#dp-csv-error').empty();
		messages = (messages || []).filter(Boolean);
		if (!messages.length) {
			$error.prop('hidden', true);
			return;
		}
		messages.forEach(function(message) { $error.append($('<p/>').text(message)); });
		$error.prop('hidden', false);
	}

	function setBusy(busy, label) {
		state.busy = busy;
		$('#dp-csv-next, #dp-csv-back, #dp-csv-import').prop('disabled', busy);
		$('#dp-csv-step-label').text(busy && label ? label : stepLabel());
	}

	function stepLabel() {
		return typeof state.step === 'number' ? String((i18n.steps || [])[state.step - 1] || '') : '';
	}

	function goTo(step) {
		state.step = step;
		showError([]);
		$modal.find('[data-csv-step]').each(function() {
			$(this).prop('hidden', String($(this).data('csvStep')) !== String(step));
		});
		$('.dp-csv-count').remove();
		if (step === 1) {
			// Only when there is something to lose.
			$('#dp-csv-unsaved').prop('hidden', !hasUnsavedChanges()).find('strong').text(i18n.unsaved);
			renderFilePreview();
		}
		if (step === 2) { renderPreview(); }
		if (step === 3) { renderMapping(); }
		var done = step === 'done';
		$('#dp-csv-back').prop('hidden', done || step === 1);
		$('#dp-csv-next').prop('hidden', done || step === LAST_STEP);
		$('#dp-csv-import').prop('hidden', step !== LAST_STEP);
		$modal.find('[data-csv-cancel]').prop('hidden', done && state.saved);
		$('#dp-csv-step-label').text(stepLabel());
	}

	function next() {
		if (state.busy) {
			return;
		}
		if (state.step === 1) {
			if (!state.buffer) {
				showError([i18n.noFile]);
				return;
			}
			if (!state.text.trim()) {
				showError([i18n.emptyFile]);
				return;
			}
			goTo(2);
			return;
		}
		if (state.step === 2) {
			if (!chosenDelimiters()) {
				showError([i18n.noDelimiter]);
				return;
			}
			reparse();
			if (!table().rows.length) {
				showError([i18n.noRows]);
				return;
			}
			goTo(3);
			return;
		}
		if (state.step === 3) {
			if (showConflicts()) {
				showError([i18n.duplicateTargets]);
				return;
			}
			setBusy(true, i18n.checking);
			request(false).done(function(response) {
				var result = responseData(response);
				setBusy(false);
				if (result.errors && result.errors.length) {
					showError(result.errors);
					return;
				}
				goTo(4);
				renderSummary(result);
			}).fail(function(xhr) {
				setBusy(false);
				showError(responseData(xhr && xhr.responseJSON).errors);
			});
		}
	}

	function runImport() {
		if (state.busy) {
			return;
		}
		setBusy(true, i18n.importing);
		request(true).done(function(response) {
			var result = responseData(response);
			setBusy(false);
			if (result.errors && result.errors.length) {
				showError(result.errors);
				return;
			}
			if (result.saved) {
				// Stays busy: the dialog cannot be closed while the page reloads.
				state.saved = true;
				setBusy(true, i18n.reloading);
				reload();
				return;
			}
			renderDone(result);
			goTo('done');
		}).fail(function(xhr) {
			setBusy(false);
			showError(responseData(xhr && xhr.responseJSON).errors);
		});
	}

	function reload() {
		// Elementor's editor URL reloads in place; the post-edit screens go
		// to the edit link, which also works from a fresh post-new.php.
		api.navigate(window.elementor || !cfg.editUrl ? '' : cfg.editUrl);
	}

	function setPreviewOpen(open) {
		$('#dp-csv-file-preview').prop('hidden', !open);
		$('#dp-csv-preview-toggle').attr('aria-expanded', open ? 'true' : 'false').text(open ? i18n.hidePreview : i18n.showPreview);
	}

	function reset() {
		setPreviewOpen(false);
		state.step = 1;
		state.buffer = null;
		state.text = '';
		state.records = [];
		state.mapping = [];
		state.saved = false;
		$('#dp-csv-file').val('');
		$('#dp-csv-encoding').val('utf-8');
		$('#dp-csv-start').val('1');
		$('#dp-csv-headers').prop('checked', true);
		$('#dp-csv-encoding-notice').prop('hidden', true).text('');
		$('#dp-csv-consecutive').prop('checked', false);
		$('#dp-csv-qualifier').val('"');
		setDelimiter(',');
	}

	var lastFocus = null;

	function open() {
		reset();
		lastFocus = document.activeElement;
		$modal.prop('hidden', false);
		goTo(1);
		$('#dp-csv-file').trigger('focus');
	}

	function close() {
		if (state.busy || propEdit) {
			return;
		}
		if (state.saved) {
			reload();
			return;
		}
		$modal.prop('hidden', true);
		if (lastFocus && lastFocus.focus) {
			lastFocus.focus();
		}
	}

	function loadText() {
		state.text = state.buffer ? decode(state.buffer, String($('#dp-csv-encoding').val() || 'utf-8')) : '';
		renderFilePreview();
	}

	$('#dp-csv-open').on('click', open);
	$modal.on('click', '[data-csv-close], .dp-csv-backdrop', close);
	$('#dp-csv-next').on('click', next);
	$('#dp-csv-back').on('click', function() {
		if (!state.busy && typeof state.step === 'number' && state.step > 1) {
			goTo(state.step - 1);
		}
	});
	$('#dp-csv-import').on('click', runImport);
	$('#dp-csv-preview-toggle').on('click', function() {
		setPreviewOpen(String($(this).attr('aria-expanded')) !== 'true');
	});

	$('#dp-csv-file').on('change', function() {
		var file = this.files && this.files[0];
		showError([]);
		state.mapping = [];
		if (!file) {
			state.buffer = null;
			loadText();
			return;
		}
		var reader = new FileReader();
		reader.onload = function() {
			state.buffer = reader.result;
			var detected = detectEncoding(state.buffer);
			$('#dp-csv-encoding').val(detected.encoding);
			$('#dp-csv-encoding-notice').text(i18n.notUtf8).prop('hidden', !detected.guessed);
			loadText();
			var delimiter = detectDelimiter(state.text);
			setDelimiter(delimiter.delimiter);
			$('#dp-csv-start').val(delimiter.sepLine ? '2' : '1');
			renderFilePreview();
		};
		reader.onerror = function() {
			state.buffer = null;
			showError([i18n.readError]);
		};
		reader.readAsArrayBuffer(file);
	});

	$('#dp-csv-encoding').on('change', function() {
		$('#dp-csv-encoding-notice').prop('hidden', true);
		state.mapping = [];
		loadText();
	});
	$('#dp-csv-start, #dp-csv-headers').on('input change', function() {
		state.mapping = [];
		renderFilePreview();
	});
	$modal.on('change input', '[data-csv-delimiter], #dp-csv-other, #dp-csv-consecutive, #dp-csv-qualifier', function() {
		if (this.id === 'dp-csv-other' && String($(this).val() || '') !== '') {
			$modal.find('[data-csv-delimiter="other"]').prop('checked', true);
		}
		state.mapping = [];
		$('.dp-csv-count').remove();
		renderPreview();
	});

	$modal.on('change', '[data-csv-target]', function() {
		var index = parseInt($(this).data('csvTarget'), 10);
		var entry = state.mapping[index];
		if (!entry) {
			return;
		}
		var previous = entry.target;
		entry.target = String($(this).val() || '');
		if (entry.target === 'new') {
			openPropertyDialog(index, previous);
			return;
		}
		showConflicts();
	});
	$modal.on('click', '[data-csv-prop-cancel]', function() {
		closePropertyDialog(false);
	});
	$('#dp-csv-prop-confirm').on('click', confirmPropertyDialog);
	$('#dp-csv-prop-type').on('change', showPropertyTypeDetails);

	$modal.on('keydown', function(e) {
		// Enter would submit the template form around the dialog.
		if (e.key === 'Enter' && $(e.target).is('input')) {
			e.preventDefault();
			if (propEdit && e.target.id === 'dp-csv-prop-label') {
				confirmPropertyDialog();
			}
			return;
		}
		if (e.key === 'Escape') {
			e.preventDefault();
			if (propEdit) {
				closePropertyDialog(false);
			} else {
				close();
			}
			return;
		}
		if (e.key === 'Tab') {
			var $scope = propEdit ? $propModal : $modal;
			var $focusable = $scope.find('button, input, select, textarea, [tabindex="0"]').filter(':visible:not(:disabled)');
			if (!$focusable.length) {
				return;
			}
			var first = $focusable.get(0);
			var last = $focusable.get($focusable.length - 1);
			if (e.shiftKey && document.activeElement === first) {
				e.preventDefault();
				last.focus();
			} else if (!e.shiftKey && document.activeElement === last) {
				e.preventDefault();
				first.focus();
			}
		}
	});

	// Another parent in the classic editor's Page Attributes changes which
	// groups the template has; the server only knows the stored parent.
	$(document).on('change', '#parent_id', function() {
		var changed = (parseInt($(this).val() || '0', 10) || 0) !== (parseInt(cfg.parentId || 0, 10) || 0);
		$section.find('.dp-csv-actions').prop('hidden', changed);
		$section.find('.dp-csv-parent-changed').prop('hidden', !changed);
	});

	api.parse = parse;
	api.detectDelimiter = detectDelimiter;
	api.detectEncoding = detectEncoding;
	window.rowsproutGroupCsv = api;
})(jQuery);
