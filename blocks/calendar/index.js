/**
 * Event Calendar Block
 *
 * @param {Object} wp WordPress global object.
 */

( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, SelectControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;
	const { createElement: el, Fragment } = wp.element;

	/**
	 * Edit component for the calendar block.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} Block edit element.
	 */
	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const { view, showViewSwitcher, showNavigation } = attributes;
		const blockProps = useBlockProps();

		return el(
			Fragment,
			{},
			el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Calendar Settings', 'nettertech-events' ) },
					el( SelectControl, {
						label: __( 'Default View', 'nettertech-events' ),
						value: view,
						options: [
							{
								label: __( 'Month', 'nettertech-events' ),
								value: 'month',
							},
							{
								label: __( 'Week', 'nettertech-events' ),
								value: 'week',
							},
							{
								label: __( 'Day', 'nettertech-events' ),
								value: 'day',
							},
						],
						onChange( value ) {
							setAttributes( { view: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show View Switcher', 'nettertech-events' ),
						checked: showViewSwitcher,
						onChange( value ) {
							setAttributes( { showViewSwitcher: value } );
						},
						help: __(
							'Allow users to switch between month, week, and day views.',
							'nettertech-events'
						),
					} ),
					el( ToggleControl, {
						label: __( 'Show Navigation', 'nettertech-events' ),
						checked: showNavigation,
						onChange( value ) {
							setAttributes( { showNavigation: value } );
						},
						help: __(
							'Show previous/next navigation buttons.',
							'nettertech-events'
						),
					} )
				)
			),
			el(
				'div',
				blockProps,
				el(
					'div',
					{
						className:
							'nte-block-preview nte-block-preview--calendar',
					},
					el(
						'div',
						{ className: 'nte-block-preview__header' },
						el( 'span', {
							className:
								'nte-block-preview__icon dashicons dashicons-calendar-alt',
						} ),
						el(
							'span',
							{ className: 'nte-block-preview__title' },
							__( 'Event Calendar', 'nettertech-events' )
						)
					),
					el(
						'div',
						{ className: 'nte-block-preview__content' },
						el(
							'p',
							{},
							__(
								'Calendar will display here with',
								'nettertech-events'
							),
							' ',
							el( 'strong', {}, view ),
							' ',
							__( 'view.', 'nettertech-events' )
						)
					)
				)
			)
		);
	}

	/**
	 * Save component for the calendar block.
	 *
	 * @return {null} Null - dynamic block rendered server-side.
	 */
	function Save() {
		return null;
	}

	registerBlockType( 'nettertech-events/calendar', {
		edit: Edit,
		save: Save,
	} );
} )( window.wp );
