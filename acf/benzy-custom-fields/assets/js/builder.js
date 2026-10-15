/* global jQuery, bcfBuilder */
(function ($) {
	'use strict';

	var TYPES = bcfBuilder.types;
	var i18n = bcfBuilder.i18n;
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
			return $(this).closest('.bcf-b-field')[0] === $li[0];
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

		var $li = $('<li class="bcf-b-field">').attr('data-key', f.key);

		var $head = $('<div class="bcf-b-head">').append(
			'<span class="bcf-b-handle dashicons dashicons-menu"></span>',
			$('<span class="bcf-b-col-label">').append('<strong class="bcf-b-label"></strong>'),
			$('<span class="bcf-b-col-name">').append('<code class="bcf-b-name"></code>'),
			$('<span class="bcf-b-col-type bcf-b-type">'),
			$('<span class="bcf-b-actions">').append(
				$('<a href="#" class="bcf-b-toggle">').text(i18n.edit), ' | ',
				$('<a href="#" class="bcf-b-dup">').text(i18n.duplicate), ' | ',
				$('<a href="#" class="bcf-b-delete">').text(i18n.delete)
			)
		);

		var $table = $('<table class="form-table bcf-b-settings">').append(
			row('Label', '<input type="text" class="widefat" data-prop="label">'),
			row('Name', $('<div>').append(
				'<input type="text" class="widefat code" data-prop="name">',
				'<p class="description">Used in code: <code class="bcf-b-usage"></code></p>'
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
			row('Sub Fields', $('<div class="bcf-b-sub">').append(
				'<ul class="bcf-b-list"></ul>',
				$('<button type="button" class="button bcf-b-add">').text(i18n.addSubField)
			), 'repeater')
		);

		$li.append($head, $('<div class="bcf-b-body">').append($table));

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

		var $subList = own($li, '.bcf-b-list');
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

		$li.find('> .bcf-b-head .bcf-b-label').text(label || i18n.newField);
		$li.find('> .bcf-b-head .bcf-b-name').text(name);
		$li.find('> .bcf-b-head .bcf-b-type').text(TYPES[type] || type);
		own($li, '.bcf-b-usage').text("bcf_get_field( '" + name + "' )");

		own($li, 'tr[data-types]').each(function () {
			var show = $(this).attr('data-types').split(',').indexOf(type) !== -1;
			$(this).toggle(show).find('[data-prop]').prop('disabled', !show);
		});

		updateEmpty();
	}

	function readList($ul) {
		return $ul.children('.bcf-b-field').map(function () {
			return readField($(this));
		}).get();
	}

	function readField($li) {
		var f = { key: $li.attr('data-key') };
		own($li, '[data-prop]').not(':disabled').each(function () {
			var $input = $(this);
			f[$input.data('prop')] = $input.is(':checkbox') ? ($input.is(':checked') ? 1 : 0) : $input.val();
		});
		f.sub_fields = f.type === 'repeater' ? readList(own($li, '.bcf-b-list').first()) : [];
		return f;
	}

	function makeSortable($ul) {
		$ul.sortable({
			handle: '.bcf-b-handle',
			items: '> .bcf-b-field',
			axis: 'y',
			placeholder: 'bcf-b-placeholder',
			forcePlaceholderSize: true
		});
	}

	function updateEmpty() {
		$('#bcf-builder .bcf-b-empty').toggle(!$('#bcf-builder .bcf-b-root').children().length);
	}

	function giveNewKeys(data) {
		data.key = uid();
		$.each(data.sub_fields || [], function (i, sub) {
			giveNewKeys(sub);
		});
		return data;
	}

	$(function () {
		var $builder = $('#bcf-builder');
		if (!$builder.length) {
			return;
		}

		var $root = $builder.find('.bcf-b-root');
		var fields = [];
		try {
			fields = JSON.parse($('#bcf-fields-json').val() || '[]') || [];
		} catch (e) {
			fields = [];
		}

		$.each(fields, function (i, f) {
			$root.append(buildField(f));
		});
		makeSortable($root);
		updateEmpty();

		$builder.on('click', '.bcf-b-add', function (e) {
			e.preventDefault();
			var $list = $(this).closest('td, #bcf-builder').find('.bcf-b-list').first();
			var $li = buildField().addClass('is-open');
			$list.append($li);
			updateEmpty();
			own($li, '[data-prop="label"]').trigger('focus');
		});

		$builder.on('click', '.bcf-b-toggle, .bcf-b-label', function (e) {
			e.preventDefault();
			$(this).closest('.bcf-b-field').toggleClass('is-open');
		});

		$builder.on('click', '.bcf-b-delete', function (e) {
			e.preventDefault();
			if (window.confirm(i18n.confirmDelete)) {
				$(this).closest('.bcf-b-field').remove();
				updateEmpty();
			}
		});

		$builder.on('click', '.bcf-b-dup', function (e) {
			e.preventDefault();
			var $li = $(this).closest('.bcf-b-field');
			var data = giveNewKeys(readField($li));
			data.name = data.name ? data.name + '_copy' : '';
			data.label = data.label ? data.label + ' (copy)' : '';
			$li.after(buildField(data).addClass('is-open'));
		});

		$builder.on('input', '[data-prop="label"]', function () {
			var $li = $(this).closest('.bcf-b-field');
			var $name = own($li, '[data-prop="name"]');
			if (!$name.data('touched')) {
				$name.val(slugify(this.value));
			}
			refresh($li);
		});

		$builder.on('input', '[data-prop="name"]', function () {
			$(this).data('touched', this.value !== '');
			refresh($(this).closest('.bcf-b-field'));
		});

		$builder.on('blur', '[data-prop="name"]', function () {
			this.value = slugify(this.value);
			refresh($(this).closest('.bcf-b-field'));
		});

		$builder.on('change', '[data-prop="type"]', function () {
			refresh($(this).closest('.bcf-b-field'));
		});

		// Keep the two inputs that share the "placeholder" property in sync.
		$builder.on('input', '[data-prop="placeholder"]', function () {
			var value = this.value;
			own($(this).closest('.bcf-b-field'), '[data-prop="placeholder"]').not(this).val(value);
		});

		$('#post').on('submit', function () {
			$('#bcf-fields-json').val(JSON.stringify(readList($root)));
		});
	});
}(jQuery));
