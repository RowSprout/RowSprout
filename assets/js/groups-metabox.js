(function($) {
	'use strict';

	var cfg = window.rowsproutGroupsMetaBoxConfig || {};
	var i18n = cfg.i18n || {};
	var dpCodeId = parseInt(cfg.postId || 0, 10) || 0;
	var dpFieldTypes = cfg.fieldTypes || {};
	var dpUiTypeKindMap = cfg.uiTypeKindMap || {};
	var dpUiValidationMap = cfg.uiValidationMap || {};
	var dpOverridableAllowed = !!cfg.overridableAllowed;

	// Extension points for property types an add-on registers (wp.hooks; see
	// the PHP filter rowsprout_group_field_input_html for inputs drawn on load):
	// - filter 'rowsprout.groupFieldInputHtml' (null, field) → HTML string to
	//   draw that input in groups/properties added in the browser, or null.
	// - action 'rowsprout.groupFieldsRendered' ($container) → after inputs were
	//   drawn (on load, new group, new property); initialise widgets there and
	//   skip ones you already initialised.
	var dpHooks = window.wp && window.wp.hooks ? window.wp.hooks : null;
	function fieldsRendered($container){
		if(dpHooks && $container && $container.length){ dpHooks.doAction('rowsprout.groupFieldsRendered', $container); }
	}

	var tGroup = String(i18n.group || 'Groep');
	var tExpand = String(i18n.expand || 'Uitvouwen');
	var tCollapse = String(i18n.collapse || 'Inklappen');
	var tExpandAll = String(i18n.expandAll || 'Alles uitvouwen');
	var tCollapseAll = String(i18n.collapseAll || 'Alles inklappen');
	var tDeleteGroup = String(i18n.deleteGroup || 'Verwijder groep');
	var tSelect = String(i18n.select || 'Selecteer');
	var tRemove = String(i18n.remove || 'Verwijder');
	var tYes = String(i18n.yes || 'Ja');
	var tStateOverruled = String(i18n.stateOverruled || 'Overschrijfbaar');
	var tStateFixed = String(i18n.stateFixed || 'Vast');
	var tDeleteProperty = String(i18n.deleteProperty || 'Verwijderen');
	var tNoProperties = String(i18n.noProperties || 'Nog geen eigenschappen toegevoegd.');
	var tCannotLoadParentPayload = String(i18n.cannotLoadParentPayload || 'Kon parent template-gegevens niet laden.');
	var tInvalidUrl = String(i18n.invalidUrl || 'Voer een geldige URL in (http(s)://...)');
	var tInvalidEmail = String(i18n.invalidEmail || 'Voer een geldig e-mailadres in.');
	var tTypeSingleInstance = String(i18n.typeSingleInstance || 'Dit type kan maar één keer worden toegevoegd.');
	var tChildGroupAddDisabled = String(i18n.childGroupAddDisabled || 'Groepen toevoegen is niet toegestaan bij child templates.');
	var tDuplicatePropertyTitle = String(i18n.duplicatePropertyTitle || 'Deze eigenschapstitel bestaat al binnen deze template.');
	var tDuplicateHref = String(i18n.duplicateHref || 'Deze URL/slug wordt al gebruikt door een andere groep in deze template.');
	var tMediaTitle = String(i18n.mediaTitle || 'Selecteer of upload een afbeelding');
	var tMediaButton = String(i18n.mediaButton || 'Gebruik deze afbeelding');
	var tParentChangeWarning = String(i18n.parentChangeWarning || 'Het wijzigen van de parent vervangt de huidige groepen door groepen die overgeërfd zijn van de nieuwe parent. Je eigen groepdata (titels, hrefs, velden) gaat daarbij verloren. Doorgaan?');
	var tParentRemoveWarning = String(i18n.parentRemoveWarning || 'Het weghalen van de parent zet de groepen terug naar één lege groep. Je huidige groepdata (titels, hrefs, velden) gaat daarbij verloren. Doorgaan?');

	function escHtml(str){ return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
	function generateNumericId(){ return Math.floor(Date.now() % 1000000000) + Math.floor(Math.random()*100000); }
	function sanitizeCode(input){ return String(input||'').toLowerCase().replace(/[^a-z0-9_]+/g,'_').replace(/^_+|_+$/g,''); }
	function getTypeDefinition(type){ return dpFieldTypes[type] || {}; }
	function requiresOptions(type){ var d=getTypeDefinition(type); return !!d.supports_options || d.type==='select'; }
	function parseOptions(value){ if(Array.isArray(value)){return value;} if(typeof value!=='string'||!value){return [];} return value.split(/\r?\n/).map(function(x){return String(x).trim();}).filter(Boolean); }
	function serializeOptions(options){ return JSON.stringify(parseOptions(options)); }

	function getColumns(){
		var cols=[];
		$('#dp-columns-meta .dp-prop-meta').each(function(){
			var $m=$(this);
			cols.push({
				key:String($m.data('colKey')||''),
				type:String($m.data('colType')||'textfield'),
				label:String($m.data('colLabel')||''),
				code:String($m.data('colCode')||''),
				fieldType:String($m.data('colFieldType')||'text'),
				options:String($m.attr('data-col-options')||'[]'),
				locked:String($m.data('colLocked')||'0')==='1',
				canBeOverruled:String($m.data('colOverruled')||'0')==='1',
				sourcePostId:parseInt($m.data('colSourcePostId')||dpCodeId,10)||dpCodeId,
				inherited:String($m.data('colInherited')||'0')==='1'
			});
		});
		return cols;
	}

	function getGroupKey($card){
		var groupId=parseInt($card.attr('data-group-id')||$card.find('input[name$="[id]"]').first().val()||'0',10)||0;
		var parentId=parseInt($card.attr('data-parent-id')||$card.find('input[name$="[parent_id]"]').first().val()||'0',10)||0;
		if(groupId>0){ return 'id:'+groupId; }
		if(parentId>0){ return 'parent:'+parentId; }
		var rowIdx=parseInt($card.attr('data-row-idx')||'0',10)||0;
		return 'row:'+rowIdx;
	}

	// The controls that post a property's value: the elements named …[fields][<key>].
	// Read and write the value through them rather than "the first visible input", so
	// hidden-input widgets (Thumbnail, add-on inputs) and widgets with an unnamed helper
	// field keep their value across a parent sync. A checkbox posts a hidden "0" under
	// the same name, so checkboxes and radios are handled first.
	function namedFieldControls($field,colKey){
		return $field.find('[name$="[fields]['+colKey+']"]');
	}

	function readFieldValue($field,colKey){
		var $named=namedFieldControls($field,colKey);
		var $checkbox=$named.filter('input[type="checkbox"]');
		if($checkbox.length){ return $checkbox.first().is(':checked') ? '1' : '0'; }
		var $radios=$named.filter('input[type="radio"]');
		if($radios.length){ return String($radios.filter(':checked').val()||''); }
		if($named.length){ return String($named.last().val()||''); }
		// Nothing named (e.g. a locked inherited field): the visible input, as before.
		var $input=$field.find('input[type!="hidden"], textarea, select').first();
		return $input.length ? String($input.val()||'') : null;
	}

	function writeFieldValue($field,colKey,val){
		var $named=namedFieldControls($field,colKey);
		var $checkbox=$named.filter('input[type="checkbox"]');
		if($checkbox.length){
			$checkbox.first().prop('checked', String(val)==='1' || String(val).toLowerCase()==='on' || String(val).toLowerCase()==='true').trigger('change');
			return;
		}
		var $radios=$named.filter('input[type="radio"]');
		if($radios.length){
			$radios.prop('checked', false).filter(function(){ return String(this.value)===String(val); }).prop('checked', true).trigger('change');
			return;
		}
		// change: lets an add-on widget update its own display from the restored value.
		if($named.length){ $named.last().val(val).trigger('change'); return; }
		var $input=$field.find('input[type!="hidden"], textarea, select').first();
		if($input.length){ $input.val(val); }
	}

	function getGroupFieldSnapshot(){
		var snapshot={};
		$('#dp-groups-sortable .dp-group-card').each(function(){
			var $card=$(this);
			var groupKey=getGroupKey($card);
			if(!snapshot[groupKey]){ snapshot[groupKey]={}; }

			$card.find('.dp-prop-field').each(function(){
				var $field=$(this);
				var colKey=String($field.attr('data-col-key')||'');
				if(!colKey){ return; }
				var value=readFieldValue($field,colKey);
				if(value!==null){ snapshot[groupKey][colKey]=value; }
			});
		});
		return snapshot;
	}

	function applyGroupFieldSnapshot(snapshot){
		snapshot=snapshot||{};
		$('#dp-groups-sortable .dp-group-card').each(function(){
			var $card=$(this);
			var groupKey=getGroupKey($card);
			var values=snapshot[groupKey]||{};
			if(!Object.keys(values).length){
				var groupId=parseInt($card.attr('data-group-id')||'0',10)||0;
				if(groupId>0){
					values=snapshot['parent:'+groupId]||{};
				}
			}

			Object.keys(values).forEach(function(colKey){
				var $field=$card.find('.dp-prop-field[data-col-key="'+colKey+'"]');
				if(!$field.length){ return; }
				writeFieldValue($field,colKey,values[colKey]);
			});
		});
	}

	function resolveUiKind(colType, proposedKind){
		var kind=String(proposedKind||'').trim();
		if(!kind||kind==='text'){ var mapped=dpUiTypeKindMap[colType]; if(mapped){ kind=String(mapped);} }
		if(!kind){ kind=String(getTypeDefinition(colType).type||'text'); }
		return kind;
	}

	function makeInput(rowIdx,col,keyType,value,kind,optionsJson){
		var name='dp_groups['+rowIdx+'][fields]['+col+']';
		var options=[];
		if(optionsJson){ try{ options=JSON.parse(optionsJson);}catch(e){ options=[]; } }
		if(dpHooks){
			var custom=dpHooks.applyFilters('rowsprout.groupFieldInputHtml', null, { name:name, value:String(value), type:String(keyType), kind:String(kind), key:String(col), row:rowIdx, options:options, placeholder:'', locked:false });
			if(typeof custom==='string'){ return custom; }
		}
		if(kind==='textarea'||kind==='richtext'){
			return '<textarea name="'+name+'" rows="2">'+escHtml(value)+'</textarea>';
		}
		if(kind==='thumbnail'){
			var tid='dp_thumb_'+rowIdx+'_'+col;
			return '<input type="hidden" class="dp-thumb-field" id="'+tid+'" name="'+name+'" value="'+escHtml(value)+'" />'
				+ '<div class="dp-thumb-preview" id="'+tid+'_preview"></div>'
				+ '<button type="button" class="button button-small dp-thumb-upload" data-target="'+tid+'">'+escHtml(tSelect)+'</button> '
				+ '<button type="button" class="button button-small dp-thumb-remove is-hidden" data-target="'+tid+'">'+escHtml(tRemove)+'</button>';
		}
		if(kind==='checkbox'){
			var checked=String(value)==='1'||String(value).toLowerCase()==='on';
			return '<input type="hidden" name="'+name+'" value="0" />'
				+ '<label><input type="checkbox" name="'+name+'" value="1"'+(checked?' checked':'')+' /> '+escHtml(tYes)+'</label>';
		}
		if(kind==='select'){
			if(!options.length){ options=['']; }
			if(value && options.indexOf(String(value))===-1){ options=options.concat([String(value)]); }
			var html='<select name="'+name+'">';
			options.forEach(function(opt){ html += '<option value="'+escHtml(opt)+'"'+(String(opt)===String(value)?' selected':'')+'>'+escHtml(opt)+'</option>'; });
			html += '</select>';
			return html;
		}
		if(['number','color','date','datetime-local','email','tel','url'].indexOf(kind)!==-1){
			return '<input type="'+kind+'" name="'+name+'" value="'+escHtml(value)+'" data-col-type="'+escHtml(keyType)+'" />';
		}
		return '<input type="text" name="'+name+'" value="'+escHtml(value)+'" data-col-type="'+escHtml(keyType)+'" />';
	}

	function makeFieldHtml(rowIdx,col,value){
		var kind=resolveUiKind(col.type, col.fieldType || getTypeDefinition(col.type).type || 'text');
		return '<div class="dp-field dp-prop-field" data-col-key="'+escHtml(col.key)+'">'
			+ '<div class="dp-field-label">'+escHtml(col.label)+'</div>'
			+ makeInput(rowIdx,col.key,col.type,value || '',kind,col.options)
			+ '</div>';
	}

	function makeMetaHtml(idx,col){
		var locked = col.locked ? 1 : 0;
		var canBeOverruled = locked ? 0 : (col.canBeOverruled ? 1 : 0);
		var sourcePostId = parseInt(col.sourcePostId||dpCodeId,10)||dpCodeId;
		var inherited = col.inherited ? 1 : 0;
		var html = '<div class="dp-prop-meta" data-col-key="'+escHtml(col.key)+'" data-col-type="'+escHtml(col.type)+'" data-col-label="'+escHtml(col.label)+'" data-col-code="'+escHtml(col.code)+'" data-col-field-type="'+escHtml(col.fieldType)+'" data-col-options="'+escHtml(col.options)+'" data-col-locked="'+locked+'" data-col-overruled="'+canBeOverruled+'" data-col-source-post-id="'+sourcePostId+'" data-col-inherited="'+inherited+'">';
		html += '<input type="hidden" name="dp_all_columns['+idx+'][key]" value="'+escHtml(col.key)+'" />'
			+ '<input type="hidden" name="dp_all_columns['+idx+'][type]" value="'+escHtml(col.type)+'" />'
			+ '<input type="hidden" name="dp_all_columns['+idx+'][label]" value="'+escHtml(col.label)+'" />'
			+ '<input type="hidden" name="dp_all_columns['+idx+'][code]" value="'+escHtml(col.code)+'" />'
			+ '<input type="hidden" name="dp_all_columns['+idx+'][field_type]" value="'+escHtml(col.fieldType)+'" />'
			+ '<input type="hidden" name="dp_all_columns['+idx+'][options]" value="'+escHtml(col.options)+'" />'
			+ '<input type="hidden" name="dp_all_columns['+idx+'][can_be_overruled]" value="'+canBeOverruled+'" />'
			+ '<input type="hidden" name="dp_all_columns['+idx+'][verplicht]" value="0" />';
		if(!inherited){
			html += '<input type="hidden" name="dp_columns['+idx+'][key]" value="'+escHtml(col.key)+'" />'
				+ '<input type="hidden" name="dp_columns['+idx+'][type]" value="'+escHtml(col.type)+'" />'
				+ '<input type="hidden" name="dp_columns['+idx+'][label]" value="'+escHtml(col.label)+'" />'
				+ '<input type="hidden" name="dp_columns['+idx+'][code]" value="'+escHtml(col.code)+'" />'
				+ '<input type="hidden" name="dp_columns['+idx+'][field_type]" value="'+escHtml(col.fieldType)+'" />'
				+ '<input type="hidden" name="dp_columns['+idx+'][options]" value="'+escHtml(col.options)+'" />'
				+ '<input type="hidden" name="dp_columns['+idx+'][can_be_overruled]" value="'+canBeOverruled+'" />'
				+ '<input type="hidden" name="dp_columns['+idx+'][verplicht]" value="0" />';
		}
		html += '</div>';
		return html;
	}

	function isFixedOrderType(type){
		type=String(type||'');
		return type==='title' || type==='href' || type==='slug';
	}

	function canReorderColumn(col){
		if(!col || typeof col!=='object'){ return false; }
		if(col.inherited){ return false; }
		return !isFixedOrderType(col.type);
	}

	function reorderGroupFields(){
		var orderedKeys=[];
		getColumns().forEach(function(col){
			if(isFixedOrderType(col.type)){ return; }
			orderedKeys.push(String(col.key||''));
		});
		if(!orderedKeys.length){ return; }
		$('#dp-groups-sortable .dp-group-card .dp-fields-grid').each(function(){
			var $grid=$(this);
			orderedKeys.forEach(function(colKey){
				if(!colKey){ return; }
				var $field=$grid.find('.dp-prop-field[data-col-key="'+colKey+'"]');
				if($field.length){ $grid.append($field.first()); }
			});
		});
	}

	function syncMetaOrderWithPropertyTable(){
		var orderedKeys=[];
		$('#dp-props-tbody tr.is-reorderable').each(function(){
			var key=String($(this).attr('data-col-key')||'');
			if(key){ orderedKeys.push(key); }
		});
		if(!orderedKeys.length){ return; }

		var fixedNodes=[];
		var movableByKey={};
		$('#dp-columns-meta .dp-prop-meta').each(function(){
			var $meta=$(this);
			var key=String($meta.data('colKey')||'');
			var type=String($meta.data('colType')||'');
			var inherited=String($meta.data('colInherited')||'0')==='1';
			if(inherited || isFixedOrderType(type)){
				fixedNodes.push(this);
			} else {
				movableByKey[key]=this;
			}
		});

		var $metaContainer=$('#dp-columns-meta');
		$metaContainer.empty();
		fixedNodes.forEach(function(node){ $metaContainer.append(node); });
		orderedKeys.forEach(function(key){
			if(movableByKey[key]){
				$metaContainer.append(movableByKey[key]);
				delete movableByKey[key];
			}
		});
		Object.keys(movableByKey).forEach(function(key){
			$metaContainer.append(movableByKey[key]);
		});
		updateColIndexes();
	}

	function handlePropertyOrderChanged(){
		syncMetaOrderWithPropertyTable();
		reorderGroupFields();
	}

	function rebuildPropertyTable(){
		var rows='';
		var isEmpty=true;
		getColumns().forEach(function(col){
			isEmpty=false;
			var isOverruled = !col.locked && !!col.canBeOverruled;
			var isReorderable = canReorderColumn(col);
			var stateClass = isOverruled ? 'is-overruled' : 'is-fixed';
			var stateLabel = isOverruled ? tStateOverruled : tStateFixed;
			var typeDef = getTypeDefinition(col.type);
			var typeLabel = typeDef.label || col.type;
			var tokenPostId = parseInt(col.sourcePostId||dpCodeId,10)||dpCodeId;
			var token = '@code_'+col.code+'_'+tokenPostId+'@';
			var dragClass = isReorderable ? 'dp-prop-drag' : 'dp-prop-drag is-disabled';
			var rowClass = isReorderable ? 'dp-prop-row is-reorderable' : 'dp-prop-row';
			var dragHandleAttr = isReorderable ? ' draggable="true"' : '';
			var actions='';
			if(!col.locked && !col.inherited){
				actions = '<div class="dp-prop-actions">';
				actions += '<button type="button" class="button-link dp-col-delete" data-col-key="'+escHtml(col.key)+'">'+escHtml(tDeleteProperty)+'</button>';
				actions += '</div>';
			}
			rows += '<tr class="'+rowClass+'" data-col-key="'+escHtml(col.key)+'" data-col-type="'+escHtml(col.type)+'">'
				+ '<td><span class="dp-prop-label-wrap"><span class="'+dragClass+'"'+dragHandleAttr+'>☰</span><strong>'+escHtml(col.label)+'</strong></span></td>'
				+ '<td>'+escHtml(typeLabel)+'</td>'
				+ '<td><code>'+escHtml(token)+'</code></td>'
				+ '<td><span class="dp-state-badge '+stateClass+'">'+stateLabel+'</span></td>'
				+ '<td>'+actions+'</td>'
				+ '</tr>';
		});
		if(isEmpty){
			rows = '<tr><td colspan="5" class="dp-empty-state">'+escHtml(tNoProperties)+'</td></tr>';
		}
		$('#dp-props-tbody').html(rows);
	}

	function getColumnByType(type){
		var cols=getColumns();
		for(var i=0;i<cols.length;i++){ if(String(cols[i].type)===String(type)){ return cols[i]; } }
		return null;
	}

	function getColumnByTypes(types){
		for(var i=0;i<types.length;i++){
			var found=getColumnByType(types[i]);
			if(found){ return found; }
		}
		return null;
	}

	function makeGroupCardHtml(rowIdx){
		var cols=getColumns();
		var titleCol=getColumnByTypes(['title','naam']) || { key:'title', type:'title', fieldType:'text', options:'[]' };
		var hrefCol=getColumnByTypes(['href','slug']) || { key:'href', type:'href', fieldType:'text', options:'[]' };
		var fieldsHtml='';
		cols.forEach(function(col){
			if(col.type==='title' || col.type==='href' || col.type==='slug'){ return; }
			fieldsHtml += makeFieldHtml(rowIdx,col,'');
		});
		var titleInput = makeInput(rowIdx,titleCol.key,titleCol.type,'',resolveUiKind(titleCol.type,titleCol.fieldType),titleCol.options);
		var hrefInput = makeInput(rowIdx,hrefCol.key,hrefCol.type,'',resolveUiKind(hrefCol.type,hrefCol.fieldType),hrefCol.options);
		return '<div class="dp-group-card" data-row-idx="'+rowIdx+'" data-inherited="0">'
			+ '<div class="dp-group-head">'
			+ '<div class="dp-group-handle">&#9776;</div>'
			+ '<div class="dp-group-index">'+tGroup+' '+(rowIdx+1)+'</div>'
			+ '<div><span class="label">Titel</span>'+titleInput+'</div>'
			+ '<div><span class="label">Href</span>'+hrefInput+'<small class="dp-href-error-msg"></small></div>'
			+ '<button type="button" class="button button-small dp-group-toggle">'+tExpand+'</button>'
			+ '<button type="button" class="button-link dp-group-delete" title="'+escHtml(tDeleteGroup)+'">&times;</button>'
			+ '</div>'
			+ '<div class="dp-group-body">'
			+ '<input type="hidden" name="dp_groups['+rowIdx+'][id]" value="'+generateNumericId()+'" />'
			+ '<input type="hidden" name="dp_groups['+rowIdx+'][parent_id]" value="" />'
			+ '<div class="dp-fields-grid">'+fieldsHtml+'</div>'
			+ '</div>'
			+ '</div>';
	}

	function updateRowIndexes(){
		$('#dp-groups-sortable .dp-group-card').each(function(index){
			var $card=$(this);
			$card.attr('data-row-idx', index);
			$card.find('.dp-group-index').text(tGroup+' '+(index+1));
			$card.find('[name]').each(function(){ this.name = this.name.replace(/dp_groups\[\d+\]/, 'dp_groups['+index+']'); });
			$card.find('[id]').each(function(){ this.id = this.id.replace(/dp_thumb_\d+_/, 'dp_thumb_'+index+'_'); });
			$card.find('[data-target]').each(function(){ var target=String($(this).attr('data-target')||''); $(this).attr('data-target', target.replace(/dp_thumb_\d+_/, 'dp_thumb_'+index+'_')); });
		});
		checkHrefUniqueness();
	}

	function updateColIndexes(){
		$('#dp-columns-meta .dp-prop-meta').each(function(idx){
			$(this).find('[name]').each(function(){
				this.name=this.name.replace(/dp_columns\[\d+\]/,'dp_columns['+idx+']');
				this.name=this.name.replace(/dp_all_columns\[\d+\]/,'dp_all_columns['+idx+']');
			});
		});
	}

	function setCardExpanded($card,isOpen){
		$card.toggleClass('is-open', !!isOpen);
		$card.find('.dp-group-toggle').first().text(isOpen ? tCollapse : tExpand);
	}

	function refreshToggleAllButtonState(){
		var $cards=$('#dp-groups-sortable .dp-group-card');
		if(!$cards.length){
			$('#dp-toggle-all-groups').text(tExpandAll).data('state','collapsed');
			return;
		}
		var allOpen=true;
		$cards.each(function(){ if(!$(this).hasClass('is-open')){ allOpen=false; return false; } });
		$('#dp-toggle-all-groups').text(allOpen ? tCollapseAll : tExpandAll).data('state', allOpen ? 'expanded' : 'collapsed');
	}

	function setAllGroupsExpanded(isOpen){
		$('#dp-groups-sortable .dp-group-card').each(function(){ setCardExpanded($(this), isOpen); });
		refreshToggleAllButtonState();
	}

	function flashNewGroup($card){
		$card.addClass('is-new');
		window.setTimeout(function(){ $card.removeClass('is-new'); }, 900);
	}

	function refreshAddGroupButtonState(){
		var parentId=parseInt($('#parent_id').val()||'0',10)||0;
		var isChild=parentId>0;
		$('#dp-add-group-btn').prop('disabled', isChild);
		$('#dp-add-group-btn').attr('title', isChild ? tChildGroupAddDisabled : '');
	}

	function applyParentSyncPayload(payload, savedChildColumns, savedGroupValues){
		if(!payload || typeof payload!=='object'){ return; }
		savedChildColumns=savedChildColumns||[];
		savedGroupValues=savedGroupValues||{};
		$('#dp-columns-meta').html(String(payload.columns_meta_html||''));

		// A child column sharing a LABEL with one the parent's payload just
		// rendered above represents the same conceptual property, even
		// though it has its own, different key (most commonly: the same
		// property name was independently added to both the child and its
		// parent, each generating its own col_<timestamp> key). Re-appending
		// it below as well would show it twice -- and PayloadConfigBuilder's
		// own save-time dedup (buildFieldTypes()) only keeps ONE of the two
		// anyway, so showing both here is misleading regardless.
		var parentLabels={};
		$('#dp-columns-meta .dp-prop-meta').each(function(){
			var norm=String($(this).data('colLabel')||'').toLowerCase().trim();
			if(norm){ parentLabels[norm]=true; }
		});
		savedChildColumns=savedChildColumns.filter(function(col){
			return !parentLabels[String(col.label||'').toLowerCase().trim()];
		});

		savedChildColumns.forEach(function(col){
			var idx=$('#dp-columns-meta .dp-prop-meta').length;
			$('#dp-columns-meta').append(makeMetaHtml(idx,col));
		});
		rebuildPropertyTable();
		$('#dp-groups-sortable').html(String(payload.groups_html||''));
		$('#dp-groups-sortable .dp-group-card[data-inherited="1"]').each(function(){
			var $card=$(this);
			$card.find('input[data-col-type="title"], input[data-col-type="href"], input[data-col-type="slug"]').prop('readonly', true).prop('disabled', true);
			$card.find('.dp-group-delete').remove();
		});
		savedChildColumns.forEach(function(col){
			$('#dp-groups-sortable .dp-group-card').each(function(){
				var rowIdx=parseInt($(this).attr('data-row-idx'),10)||0;
				var $fieldsGrid=$(this).find('.dp-fields-grid');
				var existingField=$fieldsGrid.find('.dp-prop-field[data-col-key="'+col.key+'"]');
				if(existingField.length===0){
					$fieldsGrid.append(makeFieldHtml(rowIdx,col,''));
					var $firstInheritedGroup=$('#dp-groups-sortable .dp-group-card[data-inherited="1"]').first();
					if($firstInheritedGroup.length){
						var $parentField=$firstInheritedGroup.find('.dp-fields-grid .dp-prop-field[data-col-key="'+col.key+'"]');
						if($parentField.length){
							var $parentInput=$parentField.find('input[type!="hidden"], textarea').first();
							if($parentInput.length){
								var parentValue=$parentInput.val();
								var $childInput=$fieldsGrid.find('.dp-prop-field[data-col-key="'+col.key+'"] input[type!="hidden"], .dp-prop-field[data-col-key="'+col.key+'"] textarea').first();
								if($childInput.length && parentValue){
									$childInput.attr('placeholder', parentValue);
								}
							}
						}
					}
				}
			});
		});
		updateColIndexes();
		updateRowIndexes();
		reorderGroupFields();
		applyGroupFieldSnapshot(savedGroupValues);
		$('#dp-groups-sortable input[data-col-type]').each(function(){ applyInputBehavior($(this),'init'); });
		refreshToggleAllButtonState();
		fieldsRendered($('#dp-groups-sortable'));
	}

	function syncFromSelectedParent(parentId, savedChildColumns, savedGroupValues){
		savedChildColumns=savedChildColumns||[];
		savedGroupValues=savedGroupValues||{};
		var nonce = String($('#rowsprout_page_groups_nonce_field').val()||'');
		if(!nonce){ return; }
		$.post(ajaxurl, {
			action: 'dp_rowsprout_template_parent_payload',
			nonce: nonce,
			post_id: dpCodeId,
			parent_id: parentId
		}).done(function(response){
			if(!response || !response.success || !response.data){ return; }
			applyParentSyncPayload(response.data, savedChildColumns, savedGroupValues);
		}).fail(function(){
			window.alert(tCannotLoadParentPayload);
		});
	}

	function normalizeSlug(value){ return String(value||'').toLowerCase().trim().replace(/\s+/g,'-').replace(/[^a-z0-9\-]/g,'').replace(/\-+/g,'-').replace(/^\-+|\-+$/g,''); }
	function normalizeTitle(value){ return String(value||'').replace(/\s+/g,' ').trim(); }
	function isValidUrl(value){ if(!value){return true;} try{ var p=new URL(value); return p.protocol==='http:'||p.protocol==='https:'; }catch(e){ return false; } }
	function isValidEmail(value){ if(!value){return true;} return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(String(value)); }

	function applyInputBehavior($input,eventType){
		var mode=String(eventType||'').toLowerCase();
		var colType=String($input.data('col-type')||'');
		if(!colType){ return; }
		var rules=dpUiValidationMap[colType]||{};

		// Slug normalization/uniqueness checking mutates the value and scans
		// every group, so on a raw keystroke we wait for a short pause in
		// typing instead of running it on every character (avoids both
		// stripping a hyphen the user just typed at the end of the value and
		// flashing the duplicate error while they're still mid-word).
		if(mode==='input' && (rules.normalize==='slug' || rules.validate==='unique')){
			window.clearTimeout($input.data('dpValidateDebounce'));
			$input.data('dpValidateDebounce', window.setTimeout(function(){ applyInputBehavior($input,'blur'); }, 500));
			return;
		}
		window.clearTimeout($input.data('dpValidateDebounce'));

		var raw=String($input.val()||'');
		var value=raw;
		if(rules.normalize==='slug'){ value=normalizeSlug(value); }
		if(rules.normalize==='title' && mode!=='input'){ value=normalizeTitle(value); }
		if(value!==raw){ $input.val(value); }
		$input.removeClass('dp-input-invalid').attr('title','');
		$input[0].setCustomValidity('');
		if(rules.validate==='url' && !isValidUrl(value)){ $input.addClass('dp-input-invalid').attr('title',tInvalidUrl); $input[0].setCustomValidity(tInvalidUrl); }
		if(rules.validate==='email' && !isValidEmail(value)){ $input.addClass('dp-input-invalid').attr('title',tInvalidEmail); $input[0].setCustomValidity(tInvalidEmail); }
		if(rules.validate==='unique'){ checkHrefUniqueness(); }
	}

	/**
	 * Cross-group href/slug duplicate check (any field type whose UI
	 * validation rules declare validate:'unique', currently only href/slug).
	 * A value containing '@code_' is a per-group-resolving placeholder token
	 * (e.g. @code_id@), not literal text -- exempt, mirroring
	 * HrefFieldType::sanitize()'s own exemption for the exact same reason.
	 */
	function checkHrefUniqueness(){
		var candidates=[], seen={};
		$('#dp-groups-sortable input[data-col-type]').each(function(){
			var $el=$(this);
			var rules=dpUiValidationMap[String($el.data('col-type')||'')]||{};
			if(rules.validate!=='unique'){ return; }
			candidates.push($el);
			var raw=String($el.val()||'');
			if(!raw || raw.indexOf('@code_')!==-1){ return; }
			var norm=normalizeSlug(raw);
			if(!norm){ return; }
			(seen[norm]=seen[norm]||[]).push($el);
		});
		candidates.forEach(function($el){
			$el.removeClass('dp-input-invalid').attr('title','');
			$el[0].setCustomValidity('');
			$el.siblings('.dp-href-error-msg').removeClass('is-visible').text('');
		});
		$.each(seen, function(norm, els){
			if(els.length>1){
				els.forEach(function($el){
					$el.addClass('dp-input-invalid').attr('title',tDuplicateHref);
					$el[0].setCustomValidity(tDuplicateHref);
					$el.siblings('.dp-href-error-msg').addClass('is-visible').text(tDuplicateHref);
				});
			}
		});
	}

	function updateTypeDescription(){
		var type=$('#dp-new-type').val();
		var d=getTypeDefinition(type);
		$('#dp-type-description').text(d.description || '');
		var forceFixed = (type==='title' || type==='href' || type==='slug') || !dpOverridableAllowed;
		$('#dp-new-overruled').prop('checked', !forceFixed).prop('disabled', forceFixed);
		if(requiresOptions(type)){
			$('#dp-new-options-row').show();
			if(!$('#dp-new-options').val().trim() && Array.isArray(d.default_options)){ $('#dp-new-options').val(d.default_options.join('\n')); }
		}else{
			$('#dp-new-options-row').hide();
		}
		var allowMultiple = d.allow_multiple !== false;
		var alreadyExists = false;
		if(!allowMultiple){
			getColumns().forEach(function(col){ if(col.type===type){ alreadyExists=true; } });
		}
		if(!allowMultiple && alreadyExists){
			$('#dp-type-description').text(tTypeSingleInstance).css('color','#d63638');
			$('#dp-col-confirm').prop('disabled', true);
		} else {
			$('#dp-type-description').css('color','#64748b');
			$('#dp-col-confirm').prop('disabled', false);
		}
	}

	$(document).on('click', '.dp-group-delete', function(){
		$(this).closest('.dp-group-card').remove();
		updateRowIndexes();
		refreshToggleAllButtonState();
	});

	$(document).on('click', '.dp-group-toggle', function(){
		var $card=$(this).closest('.dp-group-card');
		setCardExpanded($card, !$card.hasClass('is-open'));
		refreshToggleAllButtonState();
	});

	$(document).on('click', '.dp-group-head', function(e){
		var $target=$(e.target);
		if($target.is('input, textarea, select, button, .button, .button-link')){ return; }
		var $card=$(this).closest('.dp-group-card');
		setCardExpanded($card, !$card.hasClass('is-open'));
		refreshToggleAllButtonState();
	});

	$('#dp-toggle-all-groups').on('click', function(){
		var hasCollapsed=false;
		$('#dp-groups-sortable .dp-group-card').each(function(){ if(!$(this).hasClass('is-open')){ hasCollapsed=true; return false; } });
		setAllGroupsExpanded(hasCollapsed);
	});

	$('#dp-add-group-btn').on('click', function(){
		if($(this).prop('disabled')){ return; }
		var rowIdx=$('#dp-groups-sortable .dp-group-card').length;
		var html=makeGroupCardHtml(rowIdx);
		$('#dp-groups-sortable').append(html);
		var $card=$('#dp-groups-sortable .dp-group-card').last();
		setCardExpanded($card, true);
		updateRowIndexes();
		refreshToggleAllButtonState();
		flashNewGroup($card);
		fieldsRendered($card);
	});

	// Server-computed (see GroupsMetaBoxAssets::renderScriptLoader()) rather
	// than read from WP core's native Page Attributes #parent_id <select>:
	// that control exists on the classic edit screen but is never rendered
	// inside Elementor's own editing canvas, so reading it there always came
	// back empty — silently skipping the initial auto-sync-from-parent
	// below (inherited properties' displayed tokens/values, and their
	// group-card inputs being locked read-only, all depend on it) even
	// though the template genuinely has a parent.
	var sjPreviousParentId=parseInt(cfg.parentId,10)||0;

	$(document).on('change', '#parent_id', function(){
		var $select=$(this);
		var parentId=parseInt($select.val()||'0',10)||0;
		var hasExistingGroups=$('#dp-groups-sortable .dp-group-card').length>0;

		if(hasExistingGroups && parentId!==sjPreviousParentId){
			var warningMessage=parentId>0 ? tParentChangeWarning : tParentRemoveWarning;
			if(!window.confirm(warningMessage)){
				$select.val(String(sjPreviousParentId));
				return;
			}
		}

		sjPreviousParentId=parentId;
		refreshAddGroupButtonState();
		var savedChildColumns=getColumns().filter(function(col){
			return !col.inherited && col.type!=='title' && col.type!=='href' && col.type!=='slug';
		});
		var savedGroupValues=getGroupFieldSnapshot();
		syncFromSelectedParent(parentId, savedChildColumns, savedGroupValues);
	});

	var initialParentId=sjPreviousParentId;
	refreshAddGroupButtonState();
	if(initialParentId>0){
		var savedChildColumns=getColumns().filter(function(col){
			return !col.inherited && col.type!=='title' && col.type!=='href' && col.type!=='slug';
		});
		var savedGroupValues=getGroupFieldSnapshot();
		syncFromSelectedParent(initialParentId, savedChildColumns, savedGroupValues);
	}

	$('#dp-add-col-btn').on('click', function(){ $('#dp-add-col-modal').fadeIn(120).attr('aria-hidden','false'); updateTypeDescription(); });
	$('#dp-new-type').on('change', updateTypeDescription);
	function closeModal(){ $('#dp-add-col-modal').fadeOut(120).attr('aria-hidden','true'); $('#dp-new-label').val(''); $('#dp-new-options').val(''); $('#dp-new-overruled').prop('checked', dpOverridableAllowed).prop('disabled', !dpOverridableAllowed); }
	$('#dp-col-cancel, #dp-col-close').on('click', closeModal);
	$('#dp-add-col-modal > div:first').on('click', closeModal);

	$('#dp-col-confirm').on('click', function(){
		var type=String($('#dp-new-type').val()||'');
		var d=getTypeDefinition(type);
		var label=String($('#dp-new-label').val()||'').trim() || String(d.label||type);
		var code=sanitizeCode(label || type) || String(d.default_code || 'veld');
		var colKey='col_'+Date.now();
		var fieldType=String(d.type || 'text');
		var options=requiresOptions(type) ? parseOptions($('#dp-new-options').val()) : [];
		var canBeOverruled=$('#dp-new-overruled').is(':checked');
		if(type==='title' || type==='href' || type==='slug' || !dpOverridableAllowed){ canBeOverruled=false; }

		var norm=label.toLowerCase().trim();
		var exists=false;
		$('#dp-columns-meta .dp-prop-meta').each(function(){
			if(String($(this).data('colLabel')||'').toLowerCase().trim()===norm){ exists=true; return false; }
		});
		if(exists){ window.alert(tDuplicatePropertyTitle); return; }

		var idx=$('#dp-columns-meta .dp-prop-meta').length;
		var col={ key:colKey, type:type, label:label, code:code, fieldType:fieldType, options:serializeOptions(options), locked:false, canBeOverruled:canBeOverruled, sourcePostId:dpCodeId, inherited:false };
		$('#dp-columns-meta').append(makeMetaHtml(idx,col));
		rebuildPropertyTable();
		updateColIndexes();
		reorderGroupFields();

		$('#dp-groups-sortable .dp-group-card').each(function(){
			var rowIdx=parseInt($(this).attr('data-row-idx'),10)||0;
			$(this).find('.dp-fields-grid').append(makeFieldHtml(rowIdx,col,''));
		});
		fieldsRendered($('#dp-groups-sortable'));

		closeModal();
	});

	$(document).on('click', '.dp-col-delete', function(){
		var colKey=String($(this).data('col-key')||'');
		if(!colKey){ return; }
		$('#dp-columns-meta .dp-prop-meta[data-col-key="'+colKey+'"]').remove();
		$('.dp-prop-field[data-col-key="'+colKey+'"]').remove();
		rebuildPropertyTable();
		updateColIndexes();
		reorderGroupFields();
	});

	$(document).on('dragstart', '#dp-props-tbody tr.is-reorderable .dp-prop-drag', function(e){
		var event=e.originalEvent;
		var $row=$(this).closest('tr.is-reorderable');
		$row.addClass('is-dragging');
		if(event && event.dataTransfer){
			event.dataTransfer.effectAllowed='move';
			event.dataTransfer.setData('text/plain', String($row.data('colKey')||''));
			if(event.dataTransfer.setDragImage){ event.dataTransfer.setDragImage($row[0], 0, 0); }
		}
	});

	$(document).on('dragover', '#dp-props-tbody tr.is-reorderable', function(e){
		e.preventDefault();
		var $dragging=$('#dp-props-tbody tr.is-dragging');
		if(!$dragging.length || $dragging[0]===this){ return; }
		var $target=$(this);
		var midpoint=$target.offset().top + ($target.outerHeight()/2);
		if(e.originalEvent.clientY < midpoint){
			$dragging.insertBefore($target);
		} else {
			$dragging.insertAfter($target);
		}
	});

	$(document).on('drop', '#dp-props-tbody tr.is-reorderable', function(e){
		e.preventDefault();
	});

	$(document).on('dragend', '#dp-props-tbody tr.is-reorderable .dp-prop-drag', function(){
		var $row=$(this).closest('tr.is-reorderable');
		if(!$row.hasClass('is-dragging')){ return; }
		$row.removeClass('is-dragging');
		handlePropertyOrderChanged();
	});

	$(document).on('input', '#dp-groups-sortable input[data-col-type]', function(){ applyInputBehavior($(this),'input'); });
	$(document).on('blur change', '#dp-groups-sortable input[data-col-type]', function(){ applyInputBehavior($(this),'blur'); });
	$('#dp-groups-sortable input[data-col-type]').each(function(){ applyInputBehavior($(this),'init'); });

	$(document).on('blur', '.dp-group-card input[data-col-type="title"]', function(){
		var titleVal=$(this).val().trim();
		var $href=$(this).closest('.dp-group-card').find('input[data-col-type="href"], input[data-col-type="slug"]').first();
		if($href.length && !$href.val().trim()){ $href.val(normalizeSlug(titleVal)); }
	});

	var dpMediaFrame;
	$(document).on('click', '.dp-thumb-upload', function(e){
		e.preventDefault();
		var $btn=$(this);
		var target=$btn.data('target');
		dpMediaFrame=wp.media({ title:tMediaTitle, button:{ text:tMediaButton }, multiple:false });
		dpMediaFrame.on('select', function(){
			var att=dpMediaFrame.state().get('selection').first().toJSON();
			var thumbUrl=att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
			$('#'+target).val(att.id);
			$('#'+target+'_preview').removeClass('dp-thumb-preview--inherited').html('<img src="'+thumbUrl+'" class="dp-thumb-image" />');
			// The "inherited from parent" note (see GroupFieldInputRenderer)
			// is a sibling of this button, not inside the preview div itself
			// (so .html() above never touches it) — the admin just picked
			// their own image, so this row is no longer inheriting for this
			// field and the note is stale.
			$btn.siblings('.dp-thumb-inherited-note').remove();
			$btn.siblings('.dp-thumb-remove').removeClass('is-hidden');
		});
		dpMediaFrame.open();
	});

	$(document).on('click', '.dp-thumb-remove', function(e){
		e.preventDefault();
		var target=$(this).data('target');
		$('#'+target).val('');
		$('#'+target+'_preview').html('');
		$(this).addClass('is-hidden');
	});

	refreshToggleAllButtonState();
	fieldsRendered($('#dp-groups-sortable'));
})(jQuery);
