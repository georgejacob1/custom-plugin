/* global jQuery, fcfBuilder */
(function ($) {
	'use strict';

	var TYPES = fcfBuilder.types;
	var OPERATORS = fcfBuilder.operators;
	var SIZES = fcfBuilder.imageSizes || {};
	var i18n = fcfBuilder.i18n;
	var CHOICE_TYPES = 'select,checkbox,radio';
	var MEDIA_TYPES = 'image,gallery,file';
	var TYPE_ICONS = {
		text: 'editor-textcolor',
		textarea: 'editor-alignleft',
		wysiwyg: 'editor-kitchensink',
		number: 'editor-ol',
		email: 'email',
		url: 'admin-links',
		select: 'arrow-down-alt2',
		checkbox: 'yes-alt',
		radio: 'marker',
		true_false: 'leftright',
		date: 'calendar-alt',
		color: 'art',
		image: 'format-image',
		gallery: 'format-gallery',
		file: 'media-default',
		repeater: 'list-view',
		tab: 'index-card'
	};
	var TABS = {
		general: 'General',
		validation: 'Validation',
		presentation: 'Presentation',
		logic: 'Conditional Logic'
	};
	var DEFAULTS = {
		key: '', label: '', name: '', type: 'text', instructions: '', required: 0,
		default_value: '', placeholder: '', choices: '', min: '', max: '', button_label: '', layout: 'table', placement: 'top', endpoint: 0,
		maxlength: '', prepend: '', append: '', return_format: 'array', library: 'all', preview_size: 'medium',
		min_width: '', min_height: '', min_size: '', max_width: '', max_height: '', max_size: '', mime_types: '',
		wrapper_width: '', wrapper_class: '', wrapper_id: '', conditional_logic: [], sub_fields: []
	};

	function uid() {
		return 'field_' + Math.random().toString(36).slice(2, 12);
	}

	function slugify(str) {
		return String(str).toLowerCase()
			.normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '');
	}

	function iconClass(type) {
		return 'dashicons dashicons-' + (TYPE_ICONS[type] || 'admin-generic');
	}

	function options(map) {
		return $.map(map, function (label, value) {
			return $('<option>').val(value).text(label)[0];
		});
	}

	// Elements of $li that belong to it, not to nested sub fields.
	function own($li, selector) {
		return $li.find(selector).filter(function () {
			return $(this).closest('.fcf-b-field')[0] === $li[0];
		});
	}

	/* ---------- Setting controls ---------- */

	function setting(label, input, opts) {
		opts = opts || {};
		var $s = $('<div class="fcf-b-setting">').append($('<label class="fcf-b-setting-label">').text(label));
		if (opts.help) {
			$s.append($('<p class="fcf-b-help">').text(opts.help));
		}
		$s.append(input);
		if (opts.desc) {
			$s.append($('<p class="fcf-b-desc">').html(opts.desc));
		}
		if (opts.types) {
			$s.attr('data-types', opts.types);
		}
		if (opts.notTypes) {
			$s.attr('data-not-types', opts.notTypes);
		}
		return $s;
	}

	function section() {
		return $('<div class="fcf-b-section">').append($.makeArray(arguments));
	}

	function text(prop, attrs) {
		return $('<input type="text" class="fcf-b-input">').attr('data-prop', prop).attr(attrs || {});
	}

	function number(prop) {
		return $('<input type="number" min="0" step="any" class="fcf-b-input fcf-b-input-small">').attr('data-prop', prop);
	}

	function textarea(prop, rows) {
		return $('<textarea class="fcf-b-input">').attr({ 'data-prop': prop, rows: rows || 3 });
	}

	function select(prop, map) {
		return $('<select class="fcf-b-input">').attr('data-prop', prop).append(options(map));
	}

	function toggle(label, $input) {
		return $('<label class="fcf-b-switch-wrap">').append(
			$input,
			'<span class="fcf-b-switch" aria-hidden="true"></span>',
			$('<span class="fcf-b-switch-label">').text(label)
		);
	}

	function radios(prop, map) {
		var name = 'fcf_b_' + prop + '_' + Math.random().toString(36).slice(2, 8);
		return $('<ul class="fcf-b-radios">').append($.map(map, function (label, value) {
			return $('<li>').append($('<label>').append(
				$('<input type="radio">').attr({ name: name, value: value, 'data-prop': prop }),
				document.createTextNode(' ' + label)
			))[0];
		}));
	}

	// Inputs with "prepend" / "append" addons, e.g. [Width | 300 | px].
	function inputGroups(parts) {
		return $('<div class="fcf-b-groups">').append($.map(parts, function (p) {
			var $g = $('<div class="fcf-b-group">').append(
				p.before ? $('<span class="fcf-b-addon">').text(p.before) : null,
				$('<input>').attr({ type: p.number ? 'number' : 'text', 'data-prop': p.prop, min: p.number ? 0 : null, step: p.number ? 'any' : null }),
				p.after ? $('<span class="fcf-b-addon">').text(p.after) : null
			);
			if (p.types) {
				$g.attr('data-types', p.types);
			}
			return $g[0];
		}));
	}

	/* ---------- Tabs ---------- */

	function generalTab() {
		return [
			section(setting('Field Type', $('<div class="fcf-b-type-picker">').append(
				$('<div class="fcf-b-type-select">').append(
					'<span class="fcf-b-type-select-icon"></span>',
					select('type', TYPES)
				),
				$('<button type="button" class="button fcf-b-browse">').append('<span class="dashicons dashicons-screenoptions"></span>', document.createTextNode('Browse Fields'))
			))),
			section(
				setting('Field Label', text('label'), { desc: 'This is the name which will appear on the EDIT page' }),
				setting('Field Name', text('name', { 'class': 'fcf-b-input code' }), {
					desc: 'Single word, no spaces. Underscores allowed. Use in code: <code class="fcf-b-usage"></code>',
					notTypes: 'tab'
				}),
				setting('Placement', radios('placement', { top: 'Top aligned', left: 'Left aligned' }), {
					desc: 'Fields below this tab (up to the next tab) are shown when the tab is selected.',
					types: 'tab'
				}),
				setting('', toggle('New Tab Group', $('<input type="checkbox">').attr('data-prop', 'endpoint')), {
					desc: 'Start a new row of tabs here instead of continuing the previous one.',
					types: 'tab'
				}),
				setting('Choices', textarea('choices', 5), {
					desc: 'Enter each choice on a new line. Use <code>value : Label</code> to set both.',
					types: CHOICE_TYPES
				}),
				setting('Default Value', text('default_value'), {
					desc: 'Appears when creating a new post',
					types: 'text,number,email,url,select,radio,date,color'
				}),
				setting('Default Value', textarea('default_value', 3), {
					desc: 'Appears when creating a new post',
					types: 'textarea,wysiwyg'
				}),
				setting('Default Value', toggle('On by default', $('<input type="checkbox">').attr('data-prop', 'default_value')), {
					types: 'true_false'
				}),
				setting('Message', text('placeholder', { 'data-alt': '1' }), {
					desc: 'Displays text alongside the checkbox',
					types: 'true_false'
				}),
				setting('Return Format', radios('return_format', { array: 'Array', url: 'URL', id: 'ID' }), {
					desc: 'The value returned by <code>fcf_get_field()</code>',
					types: MEDIA_TYPES
				}),
				setting('Library', radios('library', { all: 'All', uploadedTo: 'Uploaded to post' }), {
					desc: 'Limit the media library choice',
					types: MEDIA_TYPES
				}),
				setting('Sub Fields', $('<div class="fcf-b-sub">').append(
					'<ul class="fcf-b-list"></ul>',
					$('<div class="fcf-b-sub-footer">').append(
						$('<button type="button" class="button fcf-b-add fcf-b-btn-outline">').text(i18n.addSubField)
					)
				), { types: 'repeater' })
			)
		];
	}

	function validationTab() {
		function sizeParts(prefix) {
			return [
				{ before: 'Width', prop: prefix + '_width', after: 'px', number: true, types: 'image,gallery' },
				{ before: 'Height', prop: prefix + '_height', after: 'px', number: true, types: 'image,gallery' },
				{ before: 'File size', prop: prefix + '_size', after: 'MB', number: true }
			];
		}

		return [
			section(
				setting('', toggle('Required', $('<input type="checkbox">').attr('data-prop', 'required')), {
					types: 'text,textarea,wysiwyg,number,email,url,select,checkbox,radio,true_false,date,color,image,gallery,file'
				}),
				setting('Character Limit', number('maxlength'), { desc: 'Leave blank for no limit', types: 'text,textarea' }),
				setting('Minimum Value', number('min'), { types: 'number' }),
				setting('Maximum Value', number('max'), { types: 'number' }),
				setting('Minimum Rows', number('min'), { types: 'repeater' }),
				setting('Maximum Rows', number('max'), { types: 'repeater' }),
				setting('Minimum', inputGroups(sizeParts('min')), { help: 'Restrict which files can be uploaded', types: MEDIA_TYPES }),
				setting('Maximum', inputGroups(sizeParts('max')), { help: 'Restrict which files can be uploaded', types: MEDIA_TYPES }),
				setting('Allowed File Types', text('mime_types', { placeholder: 'jpg, png, pdf' }), {
					desc: 'Comma separated list. Leave blank for all types',
					types: MEDIA_TYPES
				})
			)
		];
	}

	function presentationTab() {
		return [
			section(
				setting('Instructions', textarea('instructions', 3), { desc: 'Instructions for authors. Shown when submitting data', notTypes: 'tab' }),
				setting('Placeholder Text', text('placeholder'), { desc: 'Appears within the input', types: 'text,textarea,number,email,url' }),
				setting('Prepend', text('prepend'), { desc: 'Appears before the input', types: 'text,number,email,url' }),
				setting('Append', text('append'), { desc: 'Appears after the input', types: 'text,number,email,url' }),
				setting('Preview Size', select('preview_size', SIZES), { types: 'image' }),
				setting('Layout', radios('layout', { table: 'Table (horizontal)', block: 'Block (vertical)', row: 'Row (labels on the left)' }), {
					desc: 'How the sub fields of each row are laid out',
					types: 'repeater'
				}),
				setting('Button Label', text('button_label', { placeholder: 'Add Row' }), { types: 'repeater' })
			),
			section(
				setting('Wrapper Attributes', inputGroups([
					{ before: 'width', prop: 'wrapper_width', after: '%', number: true },
					{ before: 'class', prop: 'wrapper_class' },
					{ before: 'id', prop: 'wrapper_id' }
				]), { notTypes: 'tab' })
			)
		];
	}

	function logicTab() {
		return [
			section(toggle('Conditional Logic', $('<input type="checkbox" class="fcf-b-logic-on">'))),
			section(
				$('<div class="fcf-b-logic-rules">').append(
					$('<label class="fcf-b-setting-label">').text('Show this field if'),
					'<div class="fcf-b-rule-groups"></div>',
					'<h4 class="fcf-b-or">or</h4>',
					$('<button type="button" class="button fcf-b-btn-outline fcf-b-add-group">').text('Add rule group')
				)
			).addClass('fcf-b-logic-section')
		];
	}

	/* ---------- Conditional logic rules ---------- */

	function ruleRow(rule) {
		rule = $.extend({ field: '', operator: '!=empty', value: '' }, rule);
		return $('<div class="fcf-b-rule">').append(
			$('<select class="fcf-b-rule-field">').attr('data-value', rule.field),
			$('<select class="fcf-b-rule-op">').append(options(OPERATORS)).val(rule.operator),
			$('<input type="text" class="fcf-b-rule-value">').val(rule.value),
			$('<button type="button" class="button fcf-b-btn-outline fcf-b-rule-and">').text('and'),
			$('<button type="button" class="fcf-b-rule-remove" aria-label="Remove rule">').append('<span class="dashicons dashicons-minus"></span>')
		);
	}

	function ruleGroup(rules) {
		var $group = $('<div class="fcf-b-rule-group">').append('<h4 class="fcf-b-or">or</h4>');
		$.each(rules && rules.length ? rules : [{}], function (i, rule) {
			$group.append(ruleRow(rule));
		});
		return $group;
	}

	// Rules can target the other fields on the same level.
	function populateRuleFields($li) {
		var targets = $li.siblings('.fcf-b-field').filter(function () {
			return own($(this), '[data-prop="type"]').val() !== 'tab';
		}).map(function () {
			var $s = $(this);
			return {
				key: $s.attr('data-key'),
				label: own($s, '[data-prop="label"]').val() || own($s, '[data-prop="name"]').val() || i18n.newField
			};
		}).get();

		own($li, '.fcf-b-rule-field').each(function () {
			var $sel = $(this);
			var current = $sel.val() || $sel.attr('data-value') || '';
			$sel.empty();
			if (!targets.length) {
				$sel.append($('<option value="">').text('No other fields available'));
			}
			$.each(targets, function (i, t) {
				$sel.append($('<option>').val(t.key).text(t.label));
			});
			if (current) {
				$sel.val(current);
			}
			// Keep the stored target until a real option is selected.
			if ($sel.val()) {
				$sel.attr('data-value', $sel.val());
			}
		});
	}

	function updateLogic($li) {
		var $on = own($li, '.fcf-b-logic-on');
		var on = $on.is(':checked');
		var $groups = own($li, '.fcf-b-rule-groups');

		if (on && !$groups.children().length) {
			$groups.append(ruleGroup());
		}
		own($li, '.fcf-b-logic-section').toggle(on);
		own($li, '.fcf-b-tab[data-tab="logic"]').toggleClass('has-logic', on);
		own($li, '.fcf-b-rule').each(function () {
			var op = $(this).find('.fcf-b-rule-op').val();
			$(this).find('.fcf-b-rule-value').prop('disabled', op === '!=empty' || op === '==empty');
		});
		if (on && $li.parent().length) {
			populateRuleFields($li);
		}
	}

	function readLogic($li) {
		if (!own($li, '.fcf-b-logic-on').is(':checked')) {
			return [];
		}
		var groups = [];
		own($li, '.fcf-b-rule-group').each(function () {
			var rules = [];
			$(this).children('.fcf-b-rule').each(function () {
				var $r = $(this);
				var field = $r.find('.fcf-b-rule-field').val() || $r.find('.fcf-b-rule-field').attr('data-value') || '';
				if (field) {
					rules.push({ field: field, operator: $r.find('.fcf-b-rule-op').val(), value: $r.find('.fcf-b-rule-value').val() });
				}
			});
			if (rules.length) {
				groups.push(rules);
			}
		});
		return groups;
	}

	/* ---------- Field rows ---------- */

	function buildField(f) {
		f = $.extend({}, DEFAULTS, { key: uid() }, f || {});

		var $li = $('<li class="fcf-b-field">').attr('data-key', f.key);

		var $head = $('<div class="fcf-b-head">').append(
			$('<span class="fcf-b-col-order fcf-b-handle">').append('<span class="fcf-b-grip"></span>', '<span class="fcf-b-order"></span>'),
			$('<span class="fcf-b-col-toggle">').append(
				$('<a href="#" class="fcf-b-toggle fcf-b-chevron dashicons dashicons-arrow-down-alt2">').attr('aria-label', i18n.edit)
			),
			$('<span class="fcf-b-col-label">').append(
				'<strong class="fcf-b-label"></strong>',
				$('<span class="fcf-b-actions">').append(
					$('<a href="#" class="fcf-b-toggle">').text(i18n.edit),
					$('<a href="#" class="fcf-b-dup">').text(i18n.duplicate),
					$('<a href="#" class="fcf-b-delete">').text(i18n.delete)
				)
			),
			$('<span class="fcf-b-col-name">').append('<span class="fcf-b-name"></span>'),
			$('<span class="fcf-b-col-type">').append('<span class="fcf-b-type-icon"></span>', '<span class="fcf-b-type"></span>')
		);

		var builders = { general: generalTab, validation: validationTab, presentation: presentationTab, logic: logicTab };
		var $tabs = $('<div class="fcf-b-tabs" role="tablist">');
		var $panels = $('<div class="fcf-b-panels">');
		$.each(TABS, function (id, label) {
			$tabs.append($('<a href="#" class="fcf-b-tab" role="tab">').attr('data-tab', id).text(label));
			$panels.append($('<div class="fcf-b-panel">').attr('data-tab', id).append(builders[id]()));
		});
		$tabs.children().first().addClass('is-active');
		$panels.children().first().addClass('is-active');

		var $body = $('<div class="fcf-b-body">').append(
			$tabs,
			$panels,
			$('<div class="fcf-b-body-footer">').append(
				$('<button type="button" class="button fcf-b-btn-outline fcf-b-close">').text('Close Field')
			)
		);

		$li.append($head, $body);

		// Fill values.
		own($li, '[data-prop]').each(function () {
			var $input = $(this);
			var value = f[$input.data('prop')];
			if ($input.is(':radio')) {
				$input.prop('checked', String(value) === $input.val());
			} else if ($input.is(':checkbox')) {
				$input.prop('checked', !!parseInt(value, 10));
			} else {
				$input.val(value === null || value === undefined ? '' : value);
			}
		});
		own($li, '[data-prop="name"]').data('touched', !!f.name);

		var logic = $.isArray(f.conditional_logic) ? f.conditional_logic : [];
		own($li, '.fcf-b-logic-on').prop('checked', logic.length > 0);
		var $groups = own($li, '.fcf-b-rule-groups');
		$.each(logic, function (i, rules) {
			$groups.append(ruleGroup(rules));
		});

		var $subList = own($li, '.fcf-b-list');
		$.each(f.sub_fields || [], function (i, sub) {
			$subList.append(buildField(sub));
		});
		makeSortable($subList);

		refresh($li);
		updateLogic($li);
		return $li;
	}

	function refresh($li) {
		var type = own($li, '[data-prop="type"]').val();
		var label = own($li, '[data-prop="label"]').val();
		var name = own($li, '[data-prop="name"]').val();

		$li.find('> .fcf-b-head .fcf-b-label').text(label || i18n.newField);
		$li.find('> .fcf-b-head .fcf-b-name').text(name);
		$li.find('> .fcf-b-head .fcf-b-type').text(TYPES[type] || type);
		$li.find('> .fcf-b-head .fcf-b-type-icon').attr('class', 'fcf-b-type-icon ' + iconClass(type));
		own($li, '.fcf-b-type-select-icon').attr('class', 'fcf-b-type-select-icon ' + iconClass(type));
		own($li, '.fcf-b-usage').text("fcf_get_field( '" + name + "' )");

		// Parents come before children in document order, so nested rules win.
		own($li, '[data-types]').each(function () {
			var show = $(this).attr('data-types').split(',').indexOf(type) !== -1;
			$(this).toggle(show).find('[data-prop]').prop('disabled', !show);
		});
		own($li, '[data-not-types]').each(function () {
			var show = $(this).attr('data-not-types').split(',').indexOf(type) === -1;
			$(this).toggle(show).find('[data-prop]').prop('disabled', !show);
		});

		// Tabs only have general settings.
		var isTab = type === 'tab';
		$li.toggleClass('fcf-b-is-tab', isTab);
		if (isTab) {
			$li.find('> .fcf-b-head .fcf-b-name').text('');
		}
		var $tabs = $li.find('> .fcf-b-body > .fcf-b-tabs > .fcf-b-tab');
		$tabs.not('[data-tab="general"]').toggle(!isTab);
		if (isTab && !$tabs.filter('.is-active').is('[data-tab="general"]')) {
			$tabs.filter('[data-tab="general"]').trigger('click');
		}

		updateEmpty();
	}

	function readList($ul) {
		return $ul.children('.fcf-b-field').map(function () {
			return readField($(this));
		}).get();
	}

	function readField($li) {
		var f = { key: $li.attr('data-key') };
		own($li, '[data-prop]').not(':disabled').each(function () {
			var $input = $(this);
			var prop = $input.data('prop');
			if ($input.is(':radio')) {
				if ($input.is(':checked')) {
					f[prop] = $input.val();
				}
			} else if ($input.is(':checkbox')) {
				f[prop] = $input.is(':checked') ? 1 : 0;
			} else {
				f[prop] = $input.val();
			}
		});
		f.conditional_logic = readLogic($li);
		f.sub_fields = f.type === 'repeater' ? readList(own($li, '.fcf-b-list').first()) : [];
		return f;
	}

	function makeSortable($ul) {
		$ul.sortable({
			handle: '.fcf-b-handle',
			items: '> .fcf-b-field',
			axis: 'y',
			placeholder: 'fcf-b-placeholder',
			forcePlaceholderSize: true,
			update: renumber
		});
	}

	function renumber() {
		$('#fcf-builder .fcf-b-list').each(function () {
			$(this).children('.fcf-b-field').each(function (i) {
				$(this).find('> .fcf-b-head .fcf-b-order').text(i + 1);
			});
		});
	}

	function updateEmpty() {
		var empty = !$('#fcf-builder .fcf-b-root').children().length;
		$('#fcf-builder .fcf-b-empty').toggle(empty);
		$('#fcf-builder .fcf-b-columns').toggle(!empty);
		renumber();
	}

	function giveNewKeys(data) {
		data.key = uid();
		$.each(data.sub_fields || [], function (i, sub) {
			giveNewKeys(sub);
		});
		return data;
	}

	/* ---------- Browse Fields modal ---------- */

	var $modal = null;
	var $modalTarget = null;

	function openBrowser($li) {
		if (!$modal) {
			$modal = $('<div class="fcf-b-modal">').append(
				$('<div class="fcf-b-modal-box" role="dialog" aria-modal="true" aria-label="Select Field Type">').append(
					$('<div class="fcf-b-modal-head">').append(
						'<h2>Select Field Type</h2>',
						'<input type="search" class="fcf-b-modal-search" placeholder="Search fields...">',
						'<button type="button" class="fcf-b-modal-close" aria-label="Close"><span class="dashicons dashicons-no-alt"></span></button>'
					),
					$('<div class="fcf-b-modal-grid">').append($.map(TYPES, function (label, type) {
						return $('<button type="button" class="fcf-b-modal-type">').attr('data-type', type).append(
							$('<span class="fcf-b-modal-icon">').addClass(iconClass(type)),
							$('<span class="fcf-b-modal-label">').text(label)
						)[0];
					}))
				)
			).appendTo('body');

			$modal.on('click', function (e) {
				if (e.target === this || $(e.target).closest('.fcf-b-modal-close').length) {
					closeBrowser();
				}
			});
			$modal.on('click', '.fcf-b-modal-type', function () {
				if ($modalTarget) {
					own($modalTarget, '[data-prop="type"]').val($(this).attr('data-type')).trigger('change');
				}
				closeBrowser();
			});
			$modal.on('input', '.fcf-b-modal-search', function () {
				var q = this.value.toLowerCase();
				$modal.find('.fcf-b-modal-type').each(function () {
					$(this).toggle($(this).text().toLowerCase().indexOf(q) !== -1);
				});
			});
			$(document).on('keydown', function (e) {
				if (e.key === 'Escape' && $modal.hasClass('is-open')) {
					closeBrowser();
				}
			});
		}

		$modalTarget = $li;
		var current = own($li, '[data-prop="type"]').val();
		$modal.find('.fcf-b-modal-search').val('').trigger('input');
		$modal.find('.fcf-b-modal-type').each(function () {
			$(this).toggleClass('is-selected', $(this).attr('data-type') === current);
		});
		$modal.addClass('is-open');
		$modal.find('.fcf-b-modal-search').trigger('focus');
	}

	function closeBrowser() {
		$modal.removeClass('is-open');
		if ($modalTarget) {
			own($modalTarget, '.fcf-b-browse').trigger('focus');
		}
		$modalTarget = null;
	}

	/* ---------- Boot ---------- */

	$(function () {
		var $builder = $('#fcf-builder');
		if (!$builder.length) {
			return;
		}

		var $root = $builder.find('.fcf-b-root');
		var fields = [];
		try {
			fields = JSON.parse($('#fcf-fields-json').val() || '[]') || [];
		} catch (e) {
			fields = [];
		}

		$.each(fields, function (i, f) {
			$root.append(buildField(f));
		});
		makeSortable($root);
		updateEmpty();

		$builder.on('click', '.fcf-b-add', function (e) {
			e.preventDefault();
			var $list = $(this).closest('.fcf-b-sub, #fcf-builder').find('.fcf-b-list').first();
			var $li = buildField().addClass('is-open');
			$list.append($li);
			updateEmpty();
			own($li, '[data-prop="label"]').trigger('focus');
		});

		$builder.on('click', '.fcf-b-toggle, .fcf-b-label', function (e) {
			e.preventDefault();
			$(this).closest('.fcf-b-field').toggleClass('is-open');
		});

		$builder.on('click', '.fcf-b-close', function (e) {
			e.preventDefault();
			var $li = $(this).closest('.fcf-b-field').removeClass('is-open');
			$li.find('> .fcf-b-head .fcf-b-chevron').trigger('focus');
		});

		$builder.on('click', '.fcf-b-tab', function (e) {
			e.preventDefault();
			var $tab = $(this);
			var $body = $tab.closest('.fcf-b-body');
			var id = $tab.attr('data-tab');
			$tab.addClass('is-active').siblings().removeClass('is-active');
			$body.children('.fcf-b-panels').children('.fcf-b-panel').each(function () {
				$(this).toggleClass('is-active', $(this).attr('data-tab') === id);
			});
			if (id === 'logic') {
				populateRuleFields($tab.closest('.fcf-b-field'));
			}
		});

		$builder.on('click', '.fcf-b-browse', function (e) {
			e.preventDefault();
			openBrowser($(this).closest('.fcf-b-field'));
		});

		$builder.on('click', '.fcf-b-delete', function (e) {
			e.preventDefault();
			if (window.confirm(i18n.confirmDelete)) {
				$(this).closest('.fcf-b-field').remove();
				updateEmpty();
			}
		});

		$builder.on('click', '.fcf-b-dup', function (e) {
			e.preventDefault();
			var $li = $(this).closest('.fcf-b-field');
			var data = giveNewKeys(readField($li));
			data.name = data.name ? data.name + '_copy' : '';
			data.label = data.label ? data.label + ' (copy)' : '';
			$li.after(buildField(data).addClass('is-open'));
			renumber();
		});

		$builder.on('input', '[data-prop="label"]', function () {
			var $li = $(this).closest('.fcf-b-field');
			var $name = own($li, '[data-prop="name"]');
			if (!$name.data('touched')) {
				$name.val(slugify(this.value));
			}
			refresh($li);
		});

		$builder.on('input', '[data-prop="name"]', function () {
			$(this).data('touched', this.value !== '');
			refresh($(this).closest('.fcf-b-field'));
		});

		$builder.on('blur', '[data-prop="name"]', function () {
			this.value = slugify(this.value);
			refresh($(this).closest('.fcf-b-field'));
		});

		$builder.on('change', '[data-prop="type"]', function () {
			refresh($(this).closest('.fcf-b-field'));
		});

		// Keep text inputs that share a property (e.g. placeholder / message) in sync.
		$builder.on('input', 'input[type="text"][data-prop], textarea[data-prop]', function () {
			var value = this.value;
			own($(this).closest('.fcf-b-field'), '[data-prop="' + $(this).data('prop') + '"]')
				.not(this).not(':checkbox, :radio').val(value);
		});

		/* Conditional logic */

		$builder.on('change', '.fcf-b-logic-on', function () {
			updateLogic($(this).closest('.fcf-b-field'));
		});

		$builder.on('change', '.fcf-b-rule-op', function () {
			updateLogic($(this).closest('.fcf-b-field'));
		});

		$builder.on('change', '.fcf-b-rule-field', function () {
			$(this).attr('data-value', $(this).val() || '');
		});

		$builder.on('click', '.fcf-b-rule-and', function (e) {
			e.preventDefault();
			$(this).closest('.fcf-b-rule').after(ruleRow());
			updateLogic($(this).closest('.fcf-b-field'));
		});

		$builder.on('click', '.fcf-b-add-group', function (e) {
			e.preventDefault();
			var $li = $(this).closest('.fcf-b-field');
			own($li, '.fcf-b-rule-groups').append(ruleGroup());
			updateLogic($li);
		});

		$builder.on('click', '.fcf-b-rule-remove', function (e) {
			e.preventDefault();
			var $li = $(this).closest('.fcf-b-field');
			var $group = $(this).closest('.fcf-b-rule-group');
			$(this).closest('.fcf-b-rule').remove();
			if (!$group.children('.fcf-b-rule').length) {
				$group.remove();
			}
			if (!own($li, '.fcf-b-rule').length) {
				own($li, '.fcf-b-logic-on').prop('checked', false);
			}
			updateLogic($li);
		});

		$('#post').on('submit', function () {
			$('#fcf-fields-json').val(JSON.stringify(readList($root)));
		});

		/* Toolbar: title input, Add Field, Save Changes */

		// The toolbar sits outside the form, so the moved title input points back at it.
		$('#title').attr({ form: 'post', placeholder: $('#title-prompt-text').text() || 'Field Group Title' })
			.appendTo('.fcf-toolbar-title');
		$('#titlediv').addClass('fcf-title-moved');

		$('.fcf-toolbar-add-field').on('click', function () {
			$builder.closest('.postbox').removeClass('closed');
			$builder.find('.fcf-b-footer .fcf-b-add').trigger('click');
			var $last = $root.children('.fcf-b-field').last();
			if ($last.length) {
				$('html, body').animate({ scrollTop: $last.offset().top - 140 }, 200);
			}
		});

		// The hidden Publish box button saves; the "Active" switch decides the status.
		$('.fcf-toolbar-save').on('click', function () {
			var $btn = $(this).prop('disabled', true).addClass('is-busy');
			$('#publish').trigger('click');
			// Re-enable if the browser blocked the submit (e.g. an invalid input).
			window.setTimeout(function () {
				$btn.prop('disabled', false).removeClass('is-busy');
			}, 3000);
		});

		/* Location rules */

		var LOC = fcfBuilder.location;
		var $locGroups = $('#fcf-location .fcf-l-groups');

		function locOptions($select, groups) {
			$.each(groups || [], function (i, group) {
				var $target = group[0] ? $('<optgroup>').attr('label', group[0]) : $select;
				$.each(group[1], function (j, pair) {
					$target.append($('<option>').val(pair[0]).text(pair[1]));
				});
				if (group[0]) {
					$select.append($target);
				}
			});
		}

		function locFillValues($select, param, value) {
			$select.empty();
			locOptions($select, LOC.choices[param]);
			value = value === undefined || value === null ? '' : String(value);
			// Keep a saved value whose post / term no longer exists in the list.
			if (value && !$select.find('option').filter(function () { return this.value === value; }).length) {
				$select.prepend($('<option>').val(value).text(value));
			}
			if (value) {
				$select.val(value);
			}
		}

		function locRule(rule) {
			rule = $.extend({ param: 'post_type', operator: '==', value: '' }, rule);
			var $param = $('<select class="fcf-l-param">');
			locOptions($param, LOC.params);
			$param.val(rule.param);
			var $value = $('<select class="fcf-l-value">');
			locFillValues($value, rule.param, rule.value);

			return $('<div class="fcf-l-rule">').append(
				$param,
				$('<select class="fcf-l-operator">').append(options(LOC.operators)).val(rule.operator),
				$value,
				$('<button type="button" class="fcf-toolbar-btn fcf-l-and">').text('and'),
				$('<button type="button" class="fcf-l-remove" aria-label="Remove rule">').append('<span class="dashicons dashicons-minus"></span>')
			);
		}

		function locGroup(rules) {
			var $group = $('<div class="fcf-l-group">').append('<p class="fcf-l-or">or</p>');
			$.each(rules && rules.length ? rules : [{}], function (i, rule) {
				$group.append(locRule(rule));
			});
			return $group;
		}

		function locRefresh() {
			// The last remaining rule cannot be removed.
			$locGroups.find('.fcf-l-remove').toggle($locGroups.find('.fcf-l-rule').length > 1);
		}

		function locRead() {
			var groups = [];
			$locGroups.children('.fcf-l-group').each(function () {
				var rules = [];
				$(this).children('.fcf-l-rule').each(function () {
					var $r = $(this);
					rules.push({
						param: $r.find('.fcf-l-param').val(),
						operator: $r.find('.fcf-l-operator').val(),
						value: $r.find('.fcf-l-value').val() || ''
					});
				});
				if (rules.length) {
					groups.push(rules);
				}
			});
			return groups;
		}

		if ($locGroups.length) {
			var savedLocation = [];
			try {
				savedLocation = JSON.parse($('#fcf-location-json').val() || '[]') || [];
			} catch (e) {
				savedLocation = [];
			}
			$.each(savedLocation.length ? savedLocation : [[]], function (i, rules) {
				$locGroups.append(locGroup(rules));
			});
			locRefresh();

			$('#fcf-location').on('change', '.fcf-l-param', function () {
				locFillValues($(this).siblings('.fcf-l-value'), this.value);
			});

			$('#fcf-location').on('click', '.fcf-l-and', function (e) {
				e.preventDefault();
				var $rule = $(this).closest('.fcf-l-rule');
				// New rule starts with the same param, like ACF.
				$rule.after(locRule({ param: $rule.find('.fcf-l-param').val() }));
				locRefresh();
			});

			$('#fcf-location').on('click', '.fcf-l-add-group', function (e) {
				e.preventDefault();
				$locGroups.append(locGroup());
				locRefresh();
			});

			$('#fcf-location').on('click', '.fcf-l-remove', function (e) {
				e.preventDefault();
				var $group = $(this).closest('.fcf-l-group');
				$(this).closest('.fcf-l-rule').remove();
				if (!$group.children('.fcf-l-rule').length) {
					$group.remove();
				}
				locRefresh();
			});

			$('#post').on('submit', function () {
				$('#fcf-location-json').val(JSON.stringify(locRead()));
			});
		}

		/* Settings box tabs */

		$('#fcf-location').on('click', '.fcf-s-tab', function (e) {
			e.preventDefault();
			var id = $(this).attr('data-tab');
			$(this).addClass('is-active').siblings().removeClass('is-active');
			$(this).closest('.fcf-settings').children('.fcf-s-panel').each(function () {
				$(this).toggleClass('is-active', $(this).attr('data-tab') === id);
			});
		});
	});
}(jQuery));
