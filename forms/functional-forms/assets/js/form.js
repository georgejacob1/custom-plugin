/* global ffmForm */
(function () {
	'use strict';

	var cfg = window.ffmForm || {};
	var i18n = cfg.i18n || {};

	function fmt(str, value) {
		return String(str || '').replace(/%[sd]/, value);
	}

	function sizeText(bytes) {
		if (bytes < 1024) {
			return bytes + ' B';
		}
		if (bytes < 1048576) {
			return (bytes / 1024).toFixed(0) + ' KB';
		}
		return (bytes / 1048576).toFixed(1) + ' MB';
	}

	function setError(field, message) {
		if (!field) {
			return;
		}
		var el = field.querySelector('.ffm-error');
		field.classList.toggle('ffm-has-error', !!message);
		if (el) {
			el.textContent = message || '';
		}
	}

	/* ---------- drag & drop upload ---------- */

	function initDropzone(zone) {
		var input = zone.querySelector('input[type="file"]');
		var list = zone.querySelector('.ffm-file-list');
		var field = zone.closest('.ffm-field');
		var maxFiles = parseInt(zone.getAttribute('data-max-files'), 10) || 1;
		var maxBytes = parseInt(zone.getAttribute('data-max-bytes'), 10) || 0;
		var files = [];
		var canAssign = true;

		try {
			new DataTransfer(); // eslint-disable-line no-new
		} catch (e) {
			canAssign = false;
		}

		function apply() {
			if (canAssign) {
				var dt = new DataTransfer();
				files.forEach(function (f) {
					dt.items.add(f);
				});
				input.files = dt.files;
			}
			render();
			validate();
		}

		function validate() {
			var message = '';
			if (files.length > maxFiles) {
				message = fmt(i18n.tooMany, maxFiles);
			}
			files.forEach(function (f) {
				if (!message && maxBytes && f.size > maxBytes) {
					message = fmt(i18n.tooBig, f.name);
				}
			});
			setError(field, message);
			field.setAttribute('data-client-error', message ? '1' : '');
		}

		function render() {
			list.innerHTML = '';
			files.forEach(function (f, i) {
				var li = document.createElement('li');
				var name = document.createElement('span');
				name.className = 'ffm-file-name';
				name.textContent = f.name;
				var size = document.createElement('span');
				size.className = 'ffm-file-size';
				size.textContent = sizeText(f.size);
				li.appendChild(name);
				li.appendChild(size);
				if (canAssign) {
					var btn = document.createElement('button');
					btn.type = 'button';
					btn.className = 'ffm-file-remove';
					btn.setAttribute('aria-label', (i18n.remove || 'Remove') + ' ' + f.name);
					btn.innerHTML = '&times;';
					btn.addEventListener('click', function () {
						files.splice(i, 1);
						apply();
					});
					li.appendChild(btn);
				}
				list.appendChild(li);
			});
			zone.classList.toggle('has-files', files.length > 0);
		}

		input.addEventListener('change', function () {
			var picked = Array.prototype.slice.call(input.files || []);
			if (canAssign && maxFiles > 1) {
				// Add to the existing selection instead of replacing it.
				files = files.concat(picked.filter(function (p) {
					return !files.some(function (f) {
						return f.name === p.name && f.size === p.size;
					});
				}));
			} else {
				files = picked;
			}
			apply();
		});

		['dragenter', 'dragover'].forEach(function (evt) {
			zone.addEventListener(evt, function () {
				zone.classList.add('is-dragover');
			});
		});
		['dragleave', 'drop'].forEach(function (evt) {
			zone.addEventListener(evt, function () {
				zone.classList.remove('is-dragover');
			});
		});

		zone.ffmReset = function () {
			files = [];
			apply();
		};
	}

	/* ---------- AJAX submit ---------- */

	function initForm(form) {
		var wrap = form.closest('.ffm-wrap');
		var summary = form.querySelector('.ffm-error-summary');
		var success = wrap.querySelector('.ffm-success-message');
		var button = form.querySelector('.ffm-submit');
		var buttonText = button ? button.textContent : '';

		// We validate on the server and show inline messages instead of browser bubbles.
		form.setAttribute('novalidate', 'novalidate');

		Array.prototype.forEach.call(form.querySelectorAll('.ffm-dropzone'), initDropzone);

		form.addEventListener('input', function (e) {
			var field = e.target.closest('.ffm-field');
			if (field && field.classList.contains('ffm-has-error') && e.target.type !== 'file') {
				setError(field, '');
			}
		});

		form.addEventListener('submit', function (e) {
			if (!window.fetch || !window.FormData) {
				return; // Fall back to a normal POST.
			}
			e.preventDefault();

			// Don't upload files we already know the server will reject.
			var fileError = form.querySelector('.ffm-type-file[data-client-error="1"]');
			if (fileError) {
				fileError.scrollIntoView({ behavior: 'smooth', block: 'center' });
				return;
			}

			Array.prototype.forEach.call(form.querySelectorAll('.ffm-field'), function (f) {
				setError(f, '');
			});
			summary.hidden = true;
			form.classList.add('is-sending');
			if (button) {
				button.disabled = true;
				button.textContent = i18n.sending || buttonText;
			}

			fetch(cfg.ajaxUrl, { method: 'POST', body: new FormData(form), credentials: 'same-origin' })
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					if (res.success) {
						if (res.redirect) {
							window.location.href = res.redirect;
							return;
						}
						success.innerHTML = res.message;
						success.hidden = false;
						form.reset();
						Array.prototype.forEach.call(form.querySelectorAll('.ffm-dropzone'), function (z) {
							if (z.ffmReset) {
								z.ffmReset();
							}
						});
						form.hidden = true;
						wrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
						return;
					}

					var first = null;
					Object.keys(res.errors || {}).forEach(function (name) {
						var field = form.querySelector('.ffm-field[data-name="' + name.replace(/"/g, '') + '"]');
						setError(field, res.errors[name]);
						first = first || field;
					});
					summary.textContent = res.message || i18n.error;
					summary.hidden = false;
					var target = first || summary;
					target.scrollIntoView({ behavior: 'smooth', block: 'center' });
					var control = first && first.querySelector('input, select, textarea');
					if (control && control.type !== 'file') {
						control.focus({ preventScroll: true });
					}
				})
				.catch(function () {
					summary.textContent = i18n.error;
					summary.hidden = false;
				})
				.then(function () {
					form.classList.remove('is-sending');
					if (button) {
						button.disabled = false;
						button.textContent = buttonText;
					}
				});
		});
	}

	function boot() {
		Array.prototype.forEach.call(document.querySelectorAll('.ffm-form'), function (form) {
			if (!form.ffmReady) {
				form.ffmReady = true;
				initForm(form);
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
}());
