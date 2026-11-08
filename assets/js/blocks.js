/**
 * Editor-only registration of the Anar blocks; markup comes from the server.
 */
(function (wp) {
	'use strict';

	if (!wp || !wp.blocks || !window.AnarBlocks) return;

	const el = wp.element.createElement;
	const ServerSideRender = wp.serverSideRender;
	const InspectorControls = wp.blockEditor.InspectorControls;
	const PanelBody = wp.components.PanelBody;
	const TextControl = wp.components.TextControl;
	const text = window.AnarBlocks.strings || {};

	// The live server preview stays inert so editors cannot trigger real code requests.
	const preview = (name, attributes) => el('div', { style: { pointerEvents: 'none' } }, el(ServerSideRender, { block: name, attributes }));

	const block = (name, title, description, icon, attribute, label) => {
		wp.blocks.registerBlockType(name, {
			apiVersion: 2,
			title,
			description,
			icon,
			category: 'widgets',
			attributes: attribute ? { [attribute]: { type: 'string', default: '' } } : {},
			edit: (props) => {
				const blockProps = wp.blockEditor.useBlockProps ? wp.blockEditor.useBlockProps() : {};
				return el('div', blockProps,
					attribute ? el(InspectorControls, null,
						el(PanelBody, { title: text.settings },
							el(TextControl, {
								label,
								value: props.attributes[attribute],
								onChange: (value) => props.setAttributes({ [attribute]: value })
							})
						)
					) : null,
					preview(name, props.attributes)
				);
			},
			save: () => null
		});
	};

	block('anar/login', text.login, text.loginInfo, 'lock', 'redirect', text.redirect);
	block('anar/login-button', text.button, text.buttonInfo, 'admin-users', 'label', text.label);
	if (window.AnarBlocks.reset) block('anar/reset-password', text.resetTitle, text.resetInfo, 'update', '', '');
}(window.wp));
