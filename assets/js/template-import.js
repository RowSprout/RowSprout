/**
 * The template import form (RowSprout → Import / Export): before a file is
 * imported, the server checks it without writing anything
 * (TemplateImportPreview) and this dialog shows per template whether it is
 * added as a new draft or updates an existing one, and which properties and
 * groups it adds, changes or removes. "Import" then submits the form as
 * before. Without dialog or fetch support the form imports directly.
 */
(function () {
	'use strict';

	var cfg = window.rowsproutTemplateImportConfig || {};
	var i18n = cfg.i18n || {};
	var form = document.getElementById('rowsprout-import-form');
	var dialog = document.getElementById('rowsprout-import-preview');
	if (!form || !dialog || typeof dialog.showModal !== 'function' || !window.fetch || !window.FormData) {
		return;
	}
	var body = dialog.querySelector('[data-preview-body]');
	var confirmButton = dialog.querySelector('[data-preview-confirm]');
	var confirmLabel = confirmButton.textContent;
	var confirmed = false;
	var request = 0;
	var LIST_LIMIT = 10;

	// sprintf() for the few forms the texts use: %s, %d, %1$s, %2$d.
	function format(text, values) {
		var next = 0;
		return String(text || '').replace(/%(?:(\d+)\$)?[sd]/g, function (match, position) {
			var value = position ? values[position - 1] : values[next++];
			return value === undefined ? '' : String(value);
		});
	}

	function element(tag, className, text) {
		var node = document.createElement(tag);
		if (className) {
			node.className = className;
		}
		if (text !== undefined) {
			node.textContent = String(text);
		}
		return node;
	}

	function note(text, warning) {
		return element('p', 'rowsprout-import-preview__note' + (warning ? ' rowsprout-import-preview__warning' : ''), text);
	}

	// The names, the first LIST_LIMIT shown and the rest behind "and N more".
	function nameList(names) {
		var list = element('ul');
		names.slice(0, LIST_LIMIT).forEach(function (name) {
			list.appendChild(element('li', '', name));
		});
		if (names.length > LIST_LIMIT) {
			var more = element('details');
			more.appendChild(element('summary', '', format(i18n.more, [names.length - LIST_LIMIT])));
			var rest = element('ul');
			names.slice(LIST_LIMIT).forEach(function (name) {
				rest.appendChild(element('li', '', name));
			});
			more.appendChild(rest);
			var item = element('li');
			item.style.listStyle = 'none';
			item.appendChild(more);
			list.appendChild(item);
		}
		return list;
	}

	function section(label, diff, isCreate, removedNote) {
		var wrap = element('div', 'rowsprout-import-preview__section');
		if (isCreate) {
			wrap.appendChild(element('strong', '', label + ' (' + diff.added.length + ')'));
			if (diff.added.length) {
				wrap.appendChild(nameList(diff.added));
			}
			return wrap;
		}
		wrap.appendChild(element('strong', '', label));
		var kinds = [['added', i18n.added], ['changed', i18n.changed], ['removed', i18n.removed]];
		var any = false;
		kinds.forEach(function (kind) {
			var names = diff[kind[0]] || [];
			if (!names.length) {
				return;
			}
			any = true;
			var part = element('div', 'rowsprout-import-preview__' + kind[0]);
			part.appendChild(element('span', '', kind[1] + ' (' + names.length + '):'));
			part.appendChild(nameList(names));
			if (kind[0] === 'removed' && removedNote) {
				part.appendChild(note(removedNote, true));
			}
			wrap.appendChild(part);
		});
		if (!any) {
			wrap.appendChild(document.createTextNode(' ' + i18n.noChanges));
		}
		if (diff.unchanged > 0) {
			wrap.appendChild(note(format(i18n.unchanged, [diff.unchanged])));
		}
		return wrap;
	}

	function templateCard(template) {
		var isCreate = template.action !== 'update';
		var card = element('div', 'rowsprout-import-preview__template');
		card.setAttribute('data-action', template.action);
		var heading = element('h3');
		heading.appendChild(element('span', 'rowsprout-import-preview__badge is-' + (isCreate ? 'create' : 'update'), isCreate ? i18n.create : i18n.update));
		heading.appendChild(document.createTextNode(template.title));
		card.appendChild(heading);

		if (!isCreate && template.target) {
			var updates = element('p', 'rowsprout-import-preview__note');
			var link = element('a', '', format(i18n.updates, [template.target.title, template.target.id, template.target.status]));
			link.href = template.target.url;
			link.target = '_blank';
			link.rel = 'noopener';
			updates.appendChild(link);
			card.appendChild(updates);
		}
		if (template.parent) {
			card.appendChild(note(format(i18n.childOf, [template.parent])));
		}
		if (template.parent_missing) {
			card.appendChild(note(i18n.parentMissing, true));
		}
		if (template.content_changed) {
			card.appendChild(note(i18n.contentChanged));
		}
		card.appendChild(section(i18n.properties, template.properties, isCreate));
		card.appendChild(section(i18n.groups, template.groups, isCreate, i18n.removedGroups));
		if (template.duplicate_urls) {
			card.appendChild(note(i18n.duplicateUrls, true));
		}
		return card;
	}

	function show(nodes, confirmText) {
		body.textContent = '';
		nodes.forEach(function (node) {
			body.appendChild(node);
		});
		confirmButton.hidden = !confirmText;
		confirmButton.textContent = confirmText || confirmLabel;
		(confirmText ? confirmButton : dialog.querySelector('[data-preview-cancel]')).focus();
	}

	function render(response) {
		if (!response || !response.success || !response.data) {
			var errors = response && response.data && response.data.errors ? response.data.errors : [i18n.failed];
			var list = element('ul', 'rowsprout-import-preview__warning');
			errors.forEach(function (error) {
				list.appendChild(element('li', '', error));
			});
			show([list], '');
			return;
		}

		var templates = response.data.templates || [];
		var created = templates.filter(function (template) { return template.action !== 'update'; }).length;
		var nodes = [];
		var summary = element('p');
		var parts = [];
		if (created) {
			parts.push(format(i18n.summaryCreate, [created]));
		}
		if (templates.length - created) {
			parts.push(format(i18n.summaryUpdate, [templates.length - created]));
		}
		summary.appendChild(element('strong', '', parts.join(' · ')));
		nodes.push(summary);
		nodes.push(note(i18n.summaryNote));
		templates.forEach(function (template) {
			nodes.push(templateCard(template));
		});

		var warnings = response.data.warnings || [];
		if (warnings.length) {
			var block = element('div', 'rowsprout-import-preview__section');
			block.appendChild(element('strong', '', i18n.warnings));
			var list2 = element('ul');
			warnings.forEach(function (warning) {
				list2.appendChild(element('li', '', warning));
			});
			block.appendChild(list2);
			nodes.push(block);
		}
		show(nodes, confirmLabel);
	}

	form.addEventListener('submit', function (event) {
		if (confirmed) {
			return;
		}
		event.preventDefault();
		var data = new FormData(form);
		data.set('action', cfg.action);
		var current = ++request;
		show([element('p', '', i18n.checking)], '');
		if (!dialog.open) {
			dialog.showModal();
		}
		window.fetch(cfg.ajaxUrl, { method: 'POST', body: data, credentials: 'same-origin' })
			.then(function (reply) {
				return reply.json();
			})
			.then(function (json) {
				if (current === request) {
					render(json);
				}
			})
			.catch(function () {
				if (current === request) {
					show([element('p', '', i18n.failed)], i18n.importAnyway);
				}
			});
	});

	confirmButton.addEventListener('click', function () {
		confirmed = true;
		dialog.close();
		if (typeof form.requestSubmit === 'function') {
			form.requestSubmit();
		} else {
			// The submit button is named "submit", which hides form.submit().
			HTMLFormElement.prototype.submit.call(form);
		}
	});

	Array.prototype.forEach.call(dialog.querySelectorAll('[data-preview-cancel]'), function (button) {
		button.addEventListener('click', function () {
			dialog.close();
		});
	});
	// Escape, the close button or Cancel: a check still running is ignored.
	dialog.addEventListener('close', function () {
		if (!confirmed) {
			request++;
		}
	});
}());
