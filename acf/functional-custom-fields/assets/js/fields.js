/* global jQuery, wp, tinymce, fcfFields */
(function ($) {
	'use strict';

	var i18n = fcfFields.i18n;
	var counter = 0;

	// In the block editor the classic editor API lives at wp.oldEditor.
	function classicEditor() {
		return wp.oldEditor || wp.editor;
	}

	function notInTemplate() {
		return !$(this).closest('.fcf-row-template').length;
	}

	/* ---------- WYSIWYG ---------- */

	function initEditor(textarea) {
		var $t = $(textarea);
		if ($t.data('fcfInit') || !textarea.id) {
			return;
		}
		$t.data('fcfInit', 1);

		classicEditor().initialize(textarea.id, {
			tinymce: {
				wpautop: true,
				plugins: 'charmap,colorpicker,hr,lists,media,paste,tabfocus,textcolor,fullscreen,wordpress,wpautoresize,wpeditimage,wpemoji,wpgallery,wplink,wpdialogs,wptextpattern,wpview',
				toolbar1: 'formatselect,bold,italic,bullist,numlist,blockquote,alignleft,aligncenter,alignright,link,unlink,wp_more,fullscreen,wp_adv',
				toolbar2: 'strikethrough,hr,forecolor,pastetext,removeformat,charmap,outdent,indent,undo,redo',
				setup: function (editor) {
					editor.on('change keyup', function () {
						editor.save();
					});
				}
			},
			quicktags: true,
			mediaButtons: true
		});
	}

	function removeEditor(textarea) {
		if (!$(textarea).data('fcfInit')) {
			return;
		}
		if (window.tinymce && tinymce.get(textarea.id)) {
			tinymce.get(textarea.id).save();
		}
		classicEditor().remove(textarea.id);
		$(textarea).data('fcfInit', 0);
	}

	/* ---------- Init ---------- */

	function init($ctx) {
		$ctx.find('.fcf-color').filter(notInTemplate).each(function () {
			if (!$(this).data('fcfInit')) {
				$(this).data('fcfInit', 1).wpColorPicker();
			}
		});

		$ctx.find('.fcf-wysiwyg').filter(notInTemplate).each(function () {
			initEditor(this);
		});

		$ctx.find('[data-fcf-required]').filter(notInTemplate).prop('required', true);

		$ctx.find('.fcf-gallery-items').filter(notInTemplate).each(function () {
			if ($(this).data('fcfInit')) {
				return;
			}
			$(this).data('fcfInit', 1).sortable({
				items: '> li',
				update: function () {
					syncGallery($(this).closest('.fcf-gallery'));
				}
			});
		});

		$ctx.find('.fcf-repeater').filter(notInTemplate).each(function () {
			var $rep = $(this);
			if ($rep.data('fcfInit')) {
				return;
			}
			$rep.data('fcfInit', 1);

			$rep.children('.fcf-rows').sortable({
				handle: '.fcf-row-handle',
				items: '> .fcf-row',
				axis: 'y',
				placeholder: 'fcf-row-placeholder',
				forcePlaceholderSize: true,
				start: function (e, ui) {
					// TinyMCE iframes break when moved in the DOM.
					ui.item.find('.fcf-wysiwyg').each(function () {
						removeEditor(this);
					});
				},
				stop: function (e, ui) {
					ui.item.find('.fcf-wysiwyg').filter(notInTemplate).each(function () {
						initEditor(this);
					});
					refreshRepeater($rep);
				}
			});

			var min = parseInt($rep.data('min'), 10) || 0;
			while ($rep.children('.fcf-rows').children('.fcf-row').length < min) {
				addRow($rep);
			}
			refreshRepeater($rep);
		});
	}

	/* ---------- Repeater ---------- */

	function addRow($rep) {
		var key = $rep.data('key');
		var index = String(Date.now()) + String(counter++);
		var html = $rep.children('.fcf-row-template').html().split('__IDX_' + key + '__').join(index);
		var $row = $($.parseHTML(html, document, false)).filter('.fcf-row');

		$rep.children('.fcf-rows').append($row);
		init($row);
		refreshRepeater($rep);
		return $row;
	}

	function refreshRepeater($rep) {
		var $rows = $rep.children('.fcf-rows').children('.fcf-row');
		var max = parseInt($rep.data('max'), 10) || 0;

		$rows.each(function (i) {
			$(this).children('.fcf-row-handle').find('.fcf-row-index').first().text(i + 1);
		});
		$rep.toggleClass('is-empty', !$rows.length);
		$rep.children('.fcf-add-row').prop('disabled', max > 0 && $rows.length >= max);
	}

	$(document).on('click', '.fcf-add-row', function (e) {
		e.preventDefault();
		var $rep = $(this).closest('.fcf-repeater');
		var max = parseInt($rep.data('max'), 10) || 0;

		if (max && $rep.children('.fcf-rows').children('.fcf-row').length >= max) {
			window.alert(i18n.maxRows.replace('%d', max));
			return;
		}
		addRow($rep);
	});

	$(document).on('click', '.fcf-remove-row', function (e) {
		e.preventDefault();
		var $row = $(this).closest('.fcf-row');
		var $rep = $row.closest('.fcf-repeater');
		var min = parseInt($rep.data('min'), 10) || 0;

		if (min && $rep.children('.fcf-rows').children('.fcf-row').length <= min) {
			window.alert(i18n.minRows.replace('%d', min));
			return;
		}
		if (!window.confirm(i18n.removeRow)) {
			return;
		}
		$row.find('.fcf-wysiwyg').each(function () {
			removeEditor(this);
		});
		$row.remove();
		refreshRepeater($rep);
	});

	$(document).on('click', '.fcf-row-handle', function () {
		$(this).closest('.fcf-row').toggleClass('is-collapsed');
	});

	/* ---------- Media ---------- */

	function mediaFrame(title, type, multiple) {
		var options = {
			title: title,
			multiple: multiple ? 'add' : false,
			button: { text: i18n.use }
		};
		if (type) {
			options.library = { type: type };
		}
		return wp.media(options);
	}

	$(document).on('click', '.fcf-image-select', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.fcf-image');
		var frame = mediaFrame(i18n.selectImage, 'image', false);

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			var url = att.sizes && att.sizes.medium ? att.sizes.medium.url : att.url;
			$wrap.find('.fcf-value').val(att.id).trigger('change');
			$wrap.find('.fcf-image-preview').empty().append($('<img>').attr({ src: url, alt: att.alt || '' }));
			$wrap.addClass('has-value');
		});
		frame.open();
	});

	$(document).on('click', '.fcf-file-select', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.fcf-file');
		var frame = mediaFrame(i18n.selectFile, null, false);

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			$wrap.find('.fcf-value').val(att.id).trigger('change');
			$wrap.find('.fcf-file-name a').attr('href', att.url).text(att.filename);
			$wrap.addClass('has-value');
		});
		frame.open();
	});

	$(document).on('click', '.fcf-media-remove', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.fcf-media');
		$wrap.find('.fcf-value').val('').trigger('change');
		$wrap.find('.fcf-image-preview').empty();
		$wrap.find('.fcf-file-name a').attr('href', '').text('');
		$wrap.removeClass('has-value');
	});

	function syncGallery($gallery) {
		var ids = $gallery.find('.fcf-gallery-items > li').map(function () {
			return $(this).data('id');
		}).get();
		$gallery.find('.fcf-value').val(ids.join(',')).trigger('change');
	}

	$(document).on('click', '.fcf-gallery-add', function (e) {
		e.preventDefault();
		var $gallery = $(this).closest('.fcf-gallery');
		var frame = mediaFrame(i18n.selectImages, 'image', true);

		frame.on('select', function () {
			var $items = $gallery.find('.fcf-gallery-items');
			frame.state().get('selection').each(function (model) {
				var att = model.toJSON();
				if ($items.children('[data-id="' + att.id + '"]').length) {
					return;
				}
				var url = att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url;
				$('<li>').attr('data-id', att.id).append(
					$('<img>').attr({ src: url, alt: att.alt || '' }),
					$('<button type="button" class="fcf-gallery-remove">&times;</button>')
				).appendTo($items);
			});
			syncGallery($gallery);
		});
		frame.open();
	});

	$(document).on('click', '.fcf-gallery-remove', function (e) {
		e.preventDefault();
		var $gallery = $(this).closest('.fcf-gallery');
		$(this).closest('li').remove();
		syncGallery($gallery);
	});

	/* ---------- Boot ---------- */

	// The block editor moves meta boxes out of the hidden #metaboxes container after load;
	// initialise only once they have moved, otherwise TinyMCE iframes come up blank.
	var mountTries = 0;

	function initWhenMounted() {
		mountTries++;
		var $pending = $('.fcf-fields').filter(function () {
			return !$(this).data('fcfBooted');
		});
		$pending.each(function () {
			var $box = $(this);
			if (!$box.closest('#metaboxes').length) {
				$box.data('fcfBooted', 1);
				init($box);
			}
		});
		if (mountTries < 200 && $pending.filter(function () { return !$(this).data('fcfBooted'); }).length) {
			window.setTimeout(initWhenMounted, 150);
		}
	}

	$(function () {
		initWhenMounted();

		// Block editor: copy TinyMCE content into the textareas before meta boxes are saved.
		if (wp.data && wp.data.select && wp.data.select('core/editor')) {
			var wasSaving = false;
			wp.data.subscribe(function () {
				var saving = wp.data.select('core/editor').isSavingPost();
				if (saving && !wasSaving && window.tinymce) {
					tinymce.triggerSave();
				}
				wasSaving = saving;
			});
		}
	});
}(jQuery));
