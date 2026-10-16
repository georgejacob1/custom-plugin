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
						$(textarea).trigger('change');
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
				$(this).data('fcfInit', 1).wpColorPicker({
					change: function (e) {
						window.setTimeout(function () {
							$(e.target).trigger('change');
						});
					},
					clear: function () {
						$(this).closest('.fcf-field').find('.fcf-color').trigger('change');
					}
				});
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
		applyLogic($rep.closest('.fcf-fields'));
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
		applyLogic($rep.closest('.fcf-fields'));
	});

	$(document).on('click', '.fcf-row-handle', function () {
		$(this).closest('.fcf-row').toggleClass('is-collapsed');
	});

	/* ---------- Media ---------- */

	function mediaFrame($wrap, title, type, multiple) {
		var library = {};
		if (type) {
			library.type = type;
		}
		if ($wrap.data('library') === 'uploadedTo' && wp.media.view.settings.post && wp.media.view.settings.post.id) {
			library.uploadedTo = wp.media.view.settings.post.id;
		}
		return wp.media({
			title: title,
			multiple: multiple ? 'add' : false,
			library: library,
			button: { text: i18n.use }
		});
	}

	// Problems with an attachment against the field's restrictions.
	function attachmentErrors($wrap, att) {
		var d = $wrap.data();
		var errors = [];
		var mb = att.filesizeInBytes ? att.filesizeInBytes / 1048576 : null;

		if (d.mimeTypes) {
			var allowed = String(d.mimeTypes).split(',');
			var ext = String(att.filename || att.url || '').split('.').pop().toLowerCase();
			if (allowed.indexOf(ext) === -1) {
				errors.push(i18n.fileType.replace('%s', allowed.join(', ')));
			}
		}
		if (mb !== null && d.minSize && mb < d.minSize) {
			errors.push(i18n.minSize.replace('%s', d.minSize));
		}
		if (mb !== null && d.maxSize && mb > d.maxSize) {
			errors.push(i18n.maxSize.replace('%s', d.maxSize));
		}
		if (att.width && att.height) {
			if (d.minWidth && att.width < d.minWidth) {
				errors.push(i18n.minWidth.replace('%d', d.minWidth));
			}
			if (d.minHeight && att.height < d.minHeight) {
				errors.push(i18n.minHeight.replace('%d', d.minHeight));
			}
			if (d.maxWidth && att.width > d.maxWidth) {
				errors.push(i18n.maxWidth.replace('%d', d.maxWidth));
			}
			if (d.maxHeight && att.height > d.maxHeight) {
				errors.push(i18n.maxHeight.replace('%d', d.maxHeight));
			}
		}
		return errors;
	}

	function rejectAttachment($wrap, att) {
		var errors = attachmentErrors($wrap, att);
		if (errors.length) {
			window.alert(i18n.invalidFile + '\n\n' + (att.filename || att.title || '') + '\n- ' + errors.join('\n- '));
		}
		return errors.length > 0;
	}

	$(document).on('click', '.fcf-image-select', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.fcf-image');
		var frame = mediaFrame($wrap, i18n.selectImage, 'image', false);
		var size = $wrap.data('previewSize') || 'medium';

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			if (rejectAttachment($wrap, att)) {
				return;
			}
			var url = att.sizes && att.sizes[size] ? att.sizes[size].url : att.url;
			$wrap.find('.fcf-value').val(att.id).trigger('change');
			$wrap.find('.fcf-image-preview').empty().append($('<img>').attr({ src: url, alt: att.alt || '' }));
			$wrap.addClass('has-value');
		});
		frame.open();
	});

	$(document).on('click', '.fcf-file-select', function (e) {
		e.preventDefault();
		var $wrap = $(this).closest('.fcf-file');
		var frame = mediaFrame($wrap, i18n.selectFile, null, false);

		frame.on('select', function () {
			var att = frame.state().get('selection').first().toJSON();
			if (rejectAttachment($wrap, att)) {
				return;
			}
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
		var frame = mediaFrame($gallery, i18n.selectImages, 'image', true);

		frame.on('select', function () {
			var $items = $gallery.find('.fcf-gallery-items');
			frame.state().get('selection').each(function (model) {
				var att = model.toJSON();
				if ($items.children('[data-id="' + att.id + '"]').length || rejectAttachment($gallery, att)) {
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

	/* ---------- Conditional logic ---------- */

	function fieldValue($field) {
		var $input = $field.children('.fcf-input');
		var ta, editor;

		switch ($field.data('type')) {
			case 'checkbox':
				return $input.find('input[type="checkbox"]:checked').map(function () {
					return this.value;
				}).get();
			case 'radio':
				return $input.find('input[type="radio"]:checked').val() || '';
			case 'true_false':
				return $input.find('input[type="checkbox"]').is(':checked') ? '1' : '';
			case 'wysiwyg':
				ta = $input.find('textarea.fcf-wysiwyg')[0];
				editor = ta && window.tinymce ? tinymce.get(ta.id) : null;
				if (editor && !editor.isHidden()) {
					return editor.getContent();
				}
				return ta ? ta.value : '';
			case 'repeater':
				return $input.find('> .fcf-repeater > .fcf-rows > .fcf-row').length ? '1' : '';
			default:
				return $input.find('.fcf-value, input:not([type="hidden"]), select, textarea').first().val() || '';
		}
	}

	function ruleMatches(rule, value) {
		var values = $.isArray(value) ? value : (value === '' || value === null ? [] : [String(value)]);
		var pattern;

		switch (rule.operator) {
			case '!=empty':
				return values.length > 0;
			case '==empty':
				return !values.length;
			case '==':
				return values.indexOf(rule.value) !== -1;
			case '!=':
				return values.indexOf(rule.value) === -1;
			case '==pattern':
				try {
					pattern = new RegExp(rule.value);
				} catch (e) {
					return false;
				}
				return values.some(function (v) {
					return pattern.test(v);
				});
			case '==contains':
				return values.some(function (v) {
					return v.indexOf(rule.value) !== -1;
				});
		}
		return false;
	}

	function applyLogic($ctx) {
		$ctx.find('.fcf-field[data-conditions]').filter(notInTemplate).each(function () {
			var $field = $(this);
			var groups = $field.data('conditions') || [];

			var show = groups.some(function (rules) {
				return rules.every(function (rule) {
					var $target = $field.siblings('.fcf-field[data-key="' + rule.field + '"]');
					return $target.length && !$target.hasClass('fcf-logic-hidden') && ruleMatches(rule, fieldValue($target));
				});
			});

			$field.toggleClass('fcf-logic-hidden', !show);
			// Disabled inputs are not submitted, so a hidden field keeps its saved value.
			$field.find('input, select, textarea, button').filter(notInTemplate).each(function () {
				$(this).prop('disabled', !!$(this).closest('.fcf-logic-hidden').length);
			});
		});
	}

	$(document).on('change input', '.fcf-fields :input', function () {
		$(this).closest('.fcf-field').removeClass('fcf-has-error');
		applyLogic($(this).closest('.fcf-fields'));
	});

	/* ---------- Required fields the browser cannot check ---------- */

	var JS_REQUIRED = ['checkbox', 'wysiwyg', 'color', 'image', 'gallery', 'file'];

	$(document).on('submit', '#post, #fcf-options-form', function (e) {
		var $missing = $('.fcf-fields .fcf-field.is-required').filter(notInTemplate).filter(function () {
			var $field = $(this);
			var value = fieldValue($field);
			return JS_REQUIRED.indexOf($field.data('type')) !== -1 &&
				!$field.closest('.fcf-logic-hidden').length &&
				($.isArray(value) ? !value.length : !String(value).trim());
		});

		if (!$missing.length) {
			return;
		}

		e.preventDefault();
		$missing.addClass('fcf-has-error');
		window.alert(i18n.required + '\n- ' + $missing.map(function () {
			return $.trim($(this).children('.fcf-label').text().replace(/\*$/, ''));
		}).get().join('\n- '));
		$('#publishing-action .spinner, #save-action .spinner').removeClass('is-active');
		$('#publish, #save-post').removeClass('disabled');
		$('html, body').animate({ scrollTop: $missing.first().offset().top - 80 }, 200);
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
				applyLogic($box);
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
