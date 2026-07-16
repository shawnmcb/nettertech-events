/**
 * Event Carousel Block
 *
 * @param {Object} wp WordPress global object.
 */

( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const { PanelBody, RangeControl, SelectControl, ToggleControl } = wp.components;
	const { __ } = wp.i18n;
	const { createElement: el, Fragment } = wp.element;

	/**
	 * Edit component for the carousel block.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} Block edit element.
	 */
	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const {
			limit,
			columns,
			showImage,
			showDate,
			showTime,
			showVenue,
			showYear,
			autoplay,
			interval,
			playbackMode,
			maxTags,
		} = attributes;
		const blockProps = useBlockProps();

		return el(
			Fragment,
			{},
			el(
				InspectorControls,
				{},
				el(
					PanelBody,
					{ title: __( 'Carousel Settings', 'nettertech-events' ) },
					el( RangeControl, {
						label: __( 'Number of Events', 'nettertech-events' ),
						value: limit,
						onChange( value ) {
							setAttributes( { limit: value } );
						},
						min: 1,
						max: 20,
					} ),
					el( RangeControl, {
						label: __( 'Visible Columns', 'nettertech-events' ),
						value: columns,
						onChange( value ) {
							setAttributes( { columns: value } );
						},
						min: 1,
						max: 6,
						help: __(
							'Number of cards visible at once.',
							'nettertech-events'
						),
					} )
				),
				el(
					PanelBody,
					{
						title: __( 'Display Options', 'nettertech-events' ),
						initialOpen: false,
					},
					el( ToggleControl, {
						label: __( 'Show Image', 'nettertech-events' ),
						checked: showImage,
						onChange( value ) {
							setAttributes( { showImage: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show Date', 'nettertech-events' ),
						checked: showDate,
						onChange( value ) {
							setAttributes( { showDate: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show Time', 'nettertech-events' ),
						checked: showTime,
						onChange( value ) {
							setAttributes( { showTime: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Show Venue', 'nettertech-events' ),
						checked: showVenue,
						onChange( value ) {
							setAttributes( { showVenue: value } );
						},
					} ),
					el( ToggleControl, {
						label: __( 'Always Show Year', 'nettertech-events' ),
						checked: showYear,
						onChange( value ) {
							setAttributes( { showYear: value } );
						},
						help: __(
							'Year is always shown for events in different years.',
							'nettertech-events'
						),
					} ),
					el( RangeControl, {
						label: __( 'Max Tags Per Card', 'nettertech-events' ),
						value: maxTags,
						onChange( value ) {
							setAttributes( { maxTags: value } );
						},
						min: 0,
						max: 20,
						help: __(
							'Maximum tags shown per card. Set to 0 to show all. Extras collapse into …and N more.',
							'nettertech-events'
						),
					} )
				),
				el(
					PanelBody,
					{
						title: __( 'Autoplay', 'nettertech-events' ),
						initialOpen: false,
					},
					el( ToggleControl, {
						label: __( 'Enable Autoplay', 'nettertech-events' ),
						checked: autoplay,
						onChange( value ) {
							setAttributes( { autoplay: value } );
						},
					} ),
					autoplay &&
						el( RangeControl, {
							label: __( 'Interval (ms)', 'nettertech-events' ),
							value: interval,
							onChange( value ) {
								setAttributes( { interval: value } );
							},
							min: 2000,
							max: 10000,
							step: 500,
						} ),
					autoplay &&
						el( SelectControl, {
							label: __( 'Playback Mode', 'nettertech-events' ),
							value: playbackMode,
							options: [
								{
									label: __(
										'Play and rewind',
										'nettertech-events'
									),
									value: 'rewind',
								},
								{
									label: __(
										'Continuous loop',
										'nettertech-events'
									),
									value: 'loop',
								},
							],
							onChange( value ) {
								setAttributes( { playbackMode: value } );
							},
							help: __(
								'Rewind snaps back to start; loop advances seamlessly. Reduced-motion users always see rewind.',
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
							'nte-block-preview nte-block-preview--carousel',
					},
					el(
						'div',
						{ className: 'nte-block-preview__header' },
						el( 'span', {
							className:
								'nte-block-preview__icon dashicons dashicons-slides',
						} ),
						el(
							'span',
							{ className: 'nte-block-preview__title' },
							__( 'Event Carousel', 'nettertech-events' )
						)
					),
					el(
						'div',
						{ className: 'nte-block-preview__content' },
						el(
							'p',
							{},
							__( 'Showing', 'nettertech-events' ),
							' ',
							el( 'strong', {}, limit ),
							' ',
							__( 'events in', 'nettertech-events' ),
							' ',
							el( 'strong', {}, columns ),
							' ',
							__( 'columns.', 'nettertech-events' )
						)
					)
				)
			)
		);
	}

	/**
	 * Save component for the carousel block.
	 *
	 * @return {null} Null - dynamic block rendered server-side.
	 */
	function Save() {
		return null;
	}

	registerBlockType( 'nettertech-events/carousel', {
		edit: Edit,
		save: Save,
	} );
} )( window.wp );
