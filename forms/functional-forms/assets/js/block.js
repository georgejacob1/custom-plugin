/* global wp */
(function (blocks, element, components, blockEditor, serverSideRender, i18n) {
	'use strict';

	var el = element.createElement;
	var __ = i18n.__;
	var data = window.ffmBlock || { forms: [], newUrl: '' };

	function formOptions() {
		return [{ value: 0, label: __('— Select a form —', 'functional-forms') }].concat(
			data.forms.map(function (f) {
				return { value: f.id, label: f.title };
			})
		);
	}

	function picker(props) {
		return el(components.SelectControl, {
			label: __('Form', 'functional-forms'),
			value: props.attributes.formId,
			options: formOptions(),
			onChange: function (val) {
				props.setAttributes({ formId: parseInt(val, 10) || 0 });
			},
			__nextHasNoMarginBottom: true
		});
	}

	blocks.registerBlockType('functional-forms/form', {
		apiVersion: 2,
		title: __('Functional Form', 'functional-forms'),
		description: __('Embed a form built with Functional Forms.', 'functional-forms'),
		category: 'widgets',
		icon: 'feedback',
		keywords: [__('form', 'functional-forms'), __('contact', 'functional-forms'), __('upload', 'functional-forms')],
		supports: { html: false },
		attributes: {
			formId: { type: 'number', default: 0 }
		},

		edit: function (props) {
			var blockProps = blockEditor.useBlockProps();
			var formId = props.attributes.formId;

			var inspector = el(
				blockEditor.InspectorControls,
				null,
				el(components.PanelBody, { title: __('Form settings', 'functional-forms') },
					picker(props),
					formId ? el('p', null,
						el('a', { href: 'post.php?post=' + formId + '&action=edit', target: '_blank', rel: 'noopener' }, __('Edit this form ↗', 'functional-forms'))
					) : null
				)
			);

			if (!formId) {
				var body = data.forms.length
					? picker(props)
					: el('p', null,
						__('No published forms yet. ', 'functional-forms'),
						el('a', { href: data.newUrl, target: '_blank', rel: 'noopener' }, __('Create a form', 'functional-forms'))
					);

				return el('div', blockProps, inspector,
					el(components.Placeholder, {
						icon: 'feedback',
						label: __('Functional Form', 'functional-forms'),
						instructions: __('Choose the form to display.', 'functional-forms')
					}, body)
				);
			}

			return el('div', blockProps, inspector,
				el(components.Disabled, null,
					el(serverSideRender, { block: 'functional-forms/form', attributes: { formId: formId } })
				)
			);
		},

		save: function () {
			return null;
		}
	});
}(wp.blocks, wp.element, wp.components, wp.blockEditor, wp.serverSideRender, wp.i18n));
