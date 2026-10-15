/* global jQuery, fcfBuilder */
(function ($) {
	'use strict';

	var TYPES = fcfBuilder.types;
	var i18n = fcfBuilder.i18n;
	var CHOICE_TYPES = ['select', 'checkbox', 'radio'];

	function uid() {
		return 'field_' + Math.random().toString(36).slice(2, 12);
	}

	function slugify(str) {
		return String(str).toLowerCase()
			.normalize('NFD').replace(/[̀-ͯ]/g, '')
			.replace(/[^a-z0-9]+/g, '_')
			.replace(/^_+|_+$/g, '');
	}

	function typeOptions() {
		return $.map(TYPES, function (label, type) {
			return $('<option>').val(type).text(label)[0];
		});
	}

	// Elements of $li that belong to it, not to nested sub fields.
	function own($li, selector) {
		return $li.find(selector).filter(function () {
			return $(this).closest('.fcf-b-field')[0] === $li[0];
		});
	}

	function row(label, html, types) {
		var $tr = $('<tr>').append($('<th>').text(label), $('<td>').append(html));
		if (types) {
			$tr.attr('data-types', types);
		}
		return $tr;
	}

	function buildField(f) {
		f = $.extend({
			key: uid(), label: '', name: '', type: 'text', instructions: '', required: 0,
			default_value: '', placeholder: '', choices: '', min: '', max: '', button_label: '', sub_fields: []
		}, f || {});

		var $li = $('<li class="fcf-b-field">').attr('data-key', f.key);

		var $head = $('<div class="fcf-b-head">').append(
			'<span class="fcf-b-handle dashicons dashicons-menu"></span>',
			$('<span class="fcf-b-col-label">').append('<strong class="fcf-b-label"></strong>'),
			$('<span class="fcf-b-col-name">').append('<code class="fcf-b-name"></code>'),
			$('<span class="fcf-b-col-type fcf-b-type">'),
			$('<span class="fcf-b-actions">').append(
				$('<a href="#" class="fcf-b-toggle">').text(i18n.edit), ' | ',
				$('<a href="#" class="fcf-b-dup">').text(i18n.duplicate), ' | ',
				$('<a href="#" class="fcf-b-delete">').text(i18n.delete)
			)
		);

		var $table = $('<table class="form-table fcf-b-settings">').append(
			row('Label', '<input type="text" class="widefat" data-prop="label">'),
			row('Name', $('<div>').append(
				'<input type="text" class="widefat code" data-prop="name">',
				'<p class="description">Used in code: <code class="fcf-b-usage"></code></p>'
			)),
			row('Type', $('<select class="widefat" data-prop="type">').append(typeOptions())),
			row('Instructions', '<textarea class="widefat" rows="2" data-prop="instructions"></textarea>'),
			row('Required', '<label><input type="checkbox" data-prop="required"> Required</label>',
				'text,textarea,number,email,url,select,date'),
			row('Choices', $('<div>').append(
				'<textarea class="widefat code" rows="4" data-prop="choices"></textarea>',
				'<p class="description">One per line. Use <code>value : Label</code> to set both.</p>'
			), CHOICE_TYPES.join(',')),
			row('Default Value', '<input type="text" class="widefat" data-prop="default_value">',
				'text,textarea,wysiwyg,number,email,url,select,radio,date,color,true_false'),
			row('Placeholder', '<input type="text" class="widefat" data-prop="placeholder">',
				'text,textarea,number,email,url'),
			row('Message', '<input type="text" class="widefat" data-prop="placeholder" data-alt="1">', 'true_false'),
			row('Minimum', '<input type="number" class="small-text" data-prop="min">', 'number,repeater'),
			row('Maximum', '<input type="number" class="small-text" data-prop="max">', 'number,repeater'),
			row('Button Label', '<input type="text" class="widefat" data-prop="button_label" placeholder="Add Row">', 'repeater'),
			row('Sub Fields', $('<div class="fcf-b-sub">').append(
				'<ul class="fcf-b-list"></ul>',
				$('<button type="button" class="button fcf-b-add">').text(i18n.addSubField)
			), 'repeater')
		);

		$li.append($head, $('<div class="fcf-b-body">').append($table));

		// Fill values.
		own($li, '[data-prop]').each(function () {
			var $input = $(this);
			var value = f[$input.data('prop')];
			if ($input.is(':checkbox')) {
				$input.prop('checked', !!parseInt(value, 10));
			} else {
				$input.val(value === null || value === undefined ? '' : value);
			}
		});
		own($li, '[data-prop="name"]').data('touched', !!f.name);

		var $subList = own($li, '.fcf-b-list');
		$.each(f.sub_fields || [], function (i, sub) {
			$subList.append(buildField(sub));
		});
		makeSortable($subList);

		refresh($li);
		return $li;
	}

	function refresh($li) {
		var type = own($li, '[data-prop="type"]').val();
		var label = own($li, '[data-prop="label"]').val();
		var name = own($li, '[data-prop="name"]').val();

		$li.find('> .fcf-b-head .fcf-b-label').text(label || i18n.newField);
		$li.find('> .fcf-b-head .fcf-b-name').text(name);
		$li.find('> .fcf-b-head .fcf-b-type').text(TYPES[type] || type);
		own($li, '.fcf-b-usage').text("fcf_get_field( '" + name + "' )");

		own($li, 'tr[data-types]').each(function () {
			var show = $(this).attr('data-types').split(',').indexOf(type) !== -1;
			$(this).toggle(show).find('[data-prop]').prop('disabled', !show);
		});

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
			f[$input.data('prop')] = $input.is(':checkbox') ? ($input.is(':checked') ? 1 : 0) : $input.val();
		});
		f.sub_fields = f.type === 'repeater' ? readList(own($li, '.fcf-b-list').first()) : [];
		return f;
	}

	function makeSortable($ul) {
		$ul.sortable({
			handle: '.fcf-b-handle',
			items: '> .fcf-b-field',
			axis: 'y',
			placeholder: 'fcf-b-placeholder',
			forcePlaceholderSize: true
		});
	}

	function updateEmpty() {
		$('#fcf-builder .fcf-b-empty').toggle(!$('#fcf-builder .fcf-b-root').children().length);
	}

	function giveNewKeys(data) {
		data.key = uid();
		$.each(data.sub_fields || [], function (i, sub) {
			giveNewKeys(sub);
		});
		return data;
	}

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
			var $list = $(this).closest('td, #fcf-builder').find('.fcf-b-list').first();
			var $li = buildField().addClass('is-open');
			$list.append($li);
			updateEmpty();
			own($li, '[data-prop="label"]').trigger('focus');
		});

		$builder.on('click', '.fcf-b-toggle, .fcf-b-label', function (e) {
			e.preventDefault();
			$(this).closest('.fcf-b-field').toggleClass('is-open');
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

		// Keep the two inputs that share the "placeholder" property in sync.
		$builder.on('input', '[data-prop="placeholder"]', function () {
			var value = this.value;
			own($(this).closest('.fcf-b-field'), '[data-prop="placeholder"]').not(this).val(value);
		});

		$('#post').on('submit', function () {
			$('#fcf-fields-json').val(JSON.stringify(readList($root)));
		});
	});
}(jQuery));
