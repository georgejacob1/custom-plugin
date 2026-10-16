/* global jQuery, ffmBuilder */
(function ($) {
	'use strict';

	var TYPES = ffmBuilder.types;
	var DEFAULTS = ffmBuilder.defaults;
	var i18n = ffmBuilder.i18n;

	var NO_INPUT = ['html'];
	var TEXTLIKE = ['text', 'textarea', 'email', 'phone', 'number', 'url', 'date'];
	var CHOICE = ['select', 'radio', 'checkbox'];

	var $builder = $('#ffm-builder');
	var $list = $builder.find('.ffm-b-list');
	var $json = $('#ffm-fields-json');

	function uid() {
		return 'field_' + Math.random().toString(36).slice(2, 12);
	}

	function slugify(str) {
		return String(str).toLowerCase()
			.normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '')
			.slice(0, 40);
	}

	function has(list, type) {
		return list.indexOf(type) !== -1;
	}

	function uniqueName(base, $except) {
		base = base || 'field';
		var taken = {};
		$list.children('.ffm-b-field').not($except).each(function () {
			taken[$(this).find('[data-prop="name"]').val()] = true;
		});
		var name = base;
		var i = 2;
		while (taken[name]) {
			name = base + '_' + i++;
		}
		return name;
	}

	function choicesOf(text) {
		return $.map(String(text || '').split(/\r?\n/), function (line) {
			line = $.trim(line);
			if (!line) {
				return null;
			}
			var parts = line.split(' : ');
			return parts.length > 1 ? parts.slice(1).join(' : ') : line;
		});
	}

	/* ---------- settings rows ---------- */

	function control(prop, value, kind, attrs) {
		var $el;
		if (kind === 'textarea') {
			$el = $('<textarea class="large-text" rows="4">').val(value);
		} else if (kind === 'checkbox') {
			$el = $('<input type="checkbox" value="1">').prop('checked', !!parseInt(value, 10));
		} else if (kind === 'select') {
			$el = $('<select>');
			$.each(attrs.options, function (val, label) {
				$el.append($('<option>').val(val).text(label));
			});
			$el.val(value);
			attrs = null;
		} else {
			$el = $('<input class="regular-text">').attr('type', kind || 'text').val(value);
		}
		if (attrs) {
			$el.attr(attrs);
		}
		return $el.addClass('ffm-b-input').attr('data-prop', prop);
	}

	function row(label, $control, help) {
		var $row = $('<div class="ffm-b-row">').append($('<label class="ffm-b-row-label">').text(label));
		var $wrap = $('<div class="ffm-b-row-control">').append($control);
		if (help) {
			$wrap.append($('<p class="description">').text(help));
		}
		return $row.append($wrap);
	}

	function settings(f) {
		var t = f.type;
		var rows = [];

		if (!has(NO_INPUT, t)) {
			rows.push(row(i18n.label, control('label', f.label)));
			rows.push(row(i18n.name, control('name', f.name, 'text', { 'class': 'regular-text code' }), i18n.nameHelp));
		}
		if ((has(TEXTLIKE, t) && t !== 'date') || t === 'select' || t === 'file') {
			rows.push(row(t === 'file' ? i18n.dropText : i18n.placeholder, control('placeholder', f.placeholder)));
		}
		if (has(CHOICE, t)) {
			rows.push(row(i18n.choices, control('choices', f.choices, 'textarea'), i18n.choicesHelp));
		}
		if (t === 'number') {
			rows.push(row(i18n.min, control('min', f.min, 'number', { step: 'any', 'class': 'small-text' })));
			rows.push(row(i18n.max, control('max', f.max, 'number', { step: 'any', 'class': 'small-text' })));
		}
		if (t === 'textarea') {
			rows.push(row(i18n.rows, control('rows', f.rows, 'number', { min: 2, max: 20, 'class': 'small-text' })));
		}
		if (t === 'file') {
			rows.push(row(i18n.allowed, control('allowed', f.allowed, 'text', { 'class': 'regular-text code' }), i18n.allowedHelp));
			rows.push(row(i18n.maxSize, control('max_size', f.max_size, 'number', { min: 0.1, step: 0.1, 'class': 'small-text' })));
			rows.push(row(i18n.maxFiles, control('max_files', f.max_files, 'number', { min: 1, max: 20, 'class': 'small-text' })));
		}
		if (has(TEXTLIKE, t) || has(CHOICE, t) || t === 'hidden') {
			rows.push(row(i18n.default, control('default', f.default), t === 'checkbox' ? i18n.defaultHelp : ''));
		}
		if (t === 'html') {
			rows.push(row(i18n.content, control('content', f.content, 'textarea')));
		}
		if (t !== 'hidden' && t !== 'html') {
			rows.push(row(i18n.help, control('help', f.help)));
			rows.push(row(i18n.required, control('required', f.required, 'checkbox')));
			rows.push(row(i18n.width, control('width', f.width, 'select', { options: { full: i18n.full, half: i18n.half } })));
		}
		return rows;
	}

	/* ---------- canvas preview ---------- */

	function preview($li) {
		var f = read($li);
		var t = f.type;
		var $p = $('<div class="ffm-b-preview">');

		if (t === 'html') {
			$p.append($('<div class="ffm-b-pv-html">').text($('<div>').html(f.content).text() || '…'));
		} else if (t === 'hidden') {
			$p.append($('<code>').text('hidden: ' + (f.default || '')));
		} else if (t === 'consent') {
			$p.append($('<label class="ffm-b-pv-choice">').append('<span class="ffm-b-pv-box"></span>', $('<span>').text(f.label)));
		} else if (t === 'radio' || t === 'checkbox') {
			$.each(choicesOf(f.choices).slice(0, 6), function (i, label) {
				$p.append($('<label class="ffm-b-pv-choice">').append(
					$('<span>').addClass(t === 'radio' ? 'ffm-b-pv-dot' : 'ffm-b-pv-box'),
					$('<span>').text(label)
				));
			});
		} else if (t === 'file') {
			$p.append($('<div class="ffm-b-pv-drop">').text('⇧ ' + (f.placeholder || TYPES.file)));
		} else {
			var text = f.placeholder || (t === 'date' ? 'dd / mm / yyyy' : '');
			var $box = $('<div class="ffm-b-pv-input">').text(text);
			if (t === 'textarea') {
				$box.addClass('is-textarea');
			}
			if (t === 'select') {
				$box.addClass('is-select');
			}
			$p.append($box);
		}
		if (f.help && t !== 'hidden' && t !== 'html') {
			$p.append($('<small class="ffm-b-pv-help">').text(f.help));
		}
		return $p;
	}

	/* ---------- field cards ---------- */

	function read($li) {
		var f = { key: $li.attr('data-key'), type: $li.attr('data-type') };
		$li.find('[data-prop]').each(function () {
			var $i = $(this);
			f[$i.attr('data-prop')] = $i.is(':checkbox') ? ($i.prop('checked') ? 1 : 0) : $i.val();
		});
		return $.extend({}, DEFAULTS, f);
	}

	function refresh($li) {
		var f = read($li);
		var label = f.type === 'html' ? TYPES.html : (f.label || i18n.untitled);
		$li.find('.ffm-b-title').text(label);
		$li.find('.ffm-b-req').toggle(!!f.required);
		$li.find('.ffm-b-name').text(f.type === 'html' ? '' : f.name);
		$li.toggleClass('is-half', f.width === 'half');
		$li.find('.ffm-b-preview').replaceWith(preview($li));
	}

	function buildField(f) {
		f = $.extend({}, DEFAULTS, f || {});
		f.key = f.key || uid();

		var $li = $('<li class="ffm-b-field">').attr({ 'data-key': f.key, 'data-type': f.type });

		var $head = $('<div class="ffm-b-head">').append(
			'<span class="ffm-b-handle dashicons dashicons-move" title="Drag"></span>',
			$('<span class="ffm-b-title-wrap">').append(
				'<strong class="ffm-b-title"></strong>',
				'<span class="ffm-b-req" aria-hidden="true">*</span>',
				$('<span class="ffm-b-type">').text(TYPES[f.type] || f.type),
				'<code class="ffm-b-name"></code>'
			),
			$('<span class="ffm-b-actions">').append(
				$('<button type="button" class="ffm-b-toggle dashicons dashicons-edit">').attr('title', i18n.edit),
				$('<button type="button" class="ffm-b-dup dashicons dashicons-admin-page">').attr('title', i18n.duplicate),
				$('<button type="button" class="ffm-b-delete dashicons dashicons-trash">').attr('title', i18n.delete)
			)
		);

		var $body = $('<div class="ffm-b-body">').append(settings(f));
		$li.append($head, '<div class="ffm-b-preview"></div>', $body);

		var $name = $body.find('[data-prop="name"]');
		$name.data('auto', !f.name || f.name === slugify(f.label));

		refresh($li);
		return $li;
	}

	function open($li) {
		var wasOpen = $li.hasClass('is-open');
		$list.children('.is-open').removeClass('is-open');
		if (!wasOpen) {
			$li.addClass('is-open');
		}
	}

	function sync() {
		var out = [];
		$list.children('.ffm-b-field').each(function () {
			out.push(read($(this)));
		});
		$json.val(JSON.stringify(out));
		$builder.find('.ffm-b-empty').toggle(!out.length);
	}

	function addField(type, $after) {
		var label = TYPES[type];
		if (type === 'consent') {
			label = i18n.consentLabel;
		}
		var f = { type: type, label: type === 'html' ? '' : label };
		if (type === 'html') {
			f.name = '';
		} else {
			f.name = uniqueName(type === 'consent' ? 'consent' : slugify(TYPES[type]));
		}
		if (type === 'radio' || type === 'checkbox' || type === 'select') {
			f.choices = 'Option 1\nOption 2\nOption 3';
		}
		var $li = buildField(f);
		$li.find('[data-prop="name"]').data('auto', true);
		if ($after && $after.length) {
			$after.after($li);
		} else {
			$list.append($li);
		}
		open($li);
		sync();
		$li[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
		$li.find('[data-prop="label"], [data-prop="content"]').first().trigger('focus');
	}

	/* ---------- events ---------- */

	$builder.on('click', '.ffm-b-add', function () {
		addField($(this).data('type'));
	});

	$list.on('click', '.ffm-b-head, .ffm-b-preview', function (e) {
		if ($(e.target).closest('.ffm-b-dup, .ffm-b-delete').length) {
			return;
		}
		open($(this).closest('.ffm-b-field'));
	});

	$list.on('click', '.ffm-b-dup', function () {
		var $li = $(this).closest('.ffm-b-field');
		var f = read($li);
		f.key = uid();
		if (f.type !== 'html') {
			f.name = uniqueName(f.name + '_copy');
		}
		var $copy = buildField(f);
		$li.after($copy);
		open($copy);
		sync();
	});

	$list.on('click', '.ffm-b-delete', function () {
		if (window.confirm(i18n.confirmDelete)) {
			$(this).closest('.ffm-b-field').remove();
			sync();
		}
	});

	$list.on('input change', '[data-prop]', function () {
		var $input = $(this);
		var $li = $input.closest('.ffm-b-field');
		var prop = $input.attr('data-prop');
		var $name = $li.find('[data-prop="name"]');

		if (prop === 'label' && $name.data('auto')) {
			$name.val(uniqueName(slugify($input.val()), $li));
		} else if (prop === 'name') {
			$name.data('auto', false);
		}
		refresh($li);
		sync();
	});

	$list.on('blur', '[data-prop="name"]', function () {
		var $li = $(this).closest('.ffm-b-field');
		$(this).val(uniqueName(slugify($(this).val()), $li));
		refresh($li);
		sync();
	});

	$list.sortable({
		handle: '.ffm-b-handle',
		placeholder: 'ffm-b-placeholder',
		forcePlaceholderSize: true,
		tolerance: 'pointer',
		start: function (e, ui) {
			ui.placeholder.toggleClass('is-half', ui.item.hasClass('is-half') && !ui.item.hasClass('is-open'));
		},
		update: sync
	});

	$('#post').on('submit', sync);

	/* ---------- boot ---------- */

	var initial = [];
	try {
		initial = JSON.parse($json.val() || '[]') || [];
	} catch (err) {
		initial = [];
	}
	$.each(initial, function (i, f) {
		$list.append(buildField(f));
	});
	sync();
}(jQuery));
