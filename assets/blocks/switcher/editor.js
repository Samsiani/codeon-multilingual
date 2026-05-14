/**
 * CodeOn Multilingual — Language Switcher block (editor side).
 *
 * No build step: we use the WP-bundled globals directly (window.wp.*).
 * The server-side render handles all output; the editor uses
 * ServerSideRender for a live preview and InspectorControls for the
 * attribute panel.
 */
(function () {
	'use strict';

	const { registerBlockType }                              = window.wp.blocks;
	const { InspectorControls, useBlockProps }               = window.wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl }        = window.wp.components;
	const { createElement: el, Fragment }                    = window.wp.element;
	const { __ }                                             = window.wp.i18n;
	const ServerSideRender                                   = window.wp.serverSideRender;

	registerBlockType('codeon-multilingual/switcher', {
		edit: function (props) {
			const { attributes, setAttributes } = props;
			const blockProps = useBlockProps();

			return el(
				Fragment,
				{},
				el(
					InspectorControls,
					{},
					el(
						PanelBody,
						{ title: __('Switcher', 'codeon-multilingual'), initialOpen: true },
						el(SelectControl, {
							label: __('Style', 'codeon-multilingual'),
							value: attributes.style,
							options: [
								{ label: __('List',     'codeon-multilingual'), value: 'list' },
								{ label: __('Dropdown', 'codeon-multilingual'), value: 'dropdown' },
								{ label: __('Flags',    'codeon-multilingual'), value: 'flags' }
							],
							onChange: function (v) { setAttributes({ style: v }); }
						}),
						el(ToggleControl, {
							label:   __('Show flag', 'codeon-multilingual'),
							checked: !!attributes.showFlag,
							onChange: function (v) { setAttributes({ showFlag: !!v }); }
						}),
						el(ToggleControl, {
							label:   __('Show native name', 'codeon-multilingual'),
							checked: !!attributes.showNative,
							onChange: function (v) { setAttributes({ showNative: !!v }); }
						}),
						el(ToggleControl, {
							label:   __('Show language code', 'codeon-multilingual'),
							checked: !!attributes.showCode,
							onChange: function (v) { setAttributes({ showCode: !!v }); }
						})
					)
				),
				el(
					'div',
					blockProps,
					el(ServerSideRender, {
						block: 'codeon-multilingual/switcher',
						attributes: attributes
					})
				)
			);
		},
		save: function () { return null; } /* server-rendered */
	});
})();
