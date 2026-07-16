/**
 * Event Grid Block
 *
 * @param {Object} wp WordPress global object.
 */

( function ( wp ) {
	'use strict';

	const { registerBlockType } = wp.blocks;
	const { useBlockProps, InspectorControls } = wp.blockEditor;
	const {
		PanelBody,
		RangeControl,
		SelectControl,
		ToggleControl,
		TextControl,
	} = wp.components;
	const { __ } = wp.i18n;
	const { createElement: el, Fragment } = wp.element;

	/**
	 * Edit component for the event grid block.
	 *
	 * @param {Object} props Block props.
	 * @return {Object} Block edit element.
	 */
	function Edit( props ) {
		const { attributes, setAttributes } = props;
		const {
			limit,
			columns,
			layout,
			showFilters,
			showSearch,
			showCategory,
			showImage,
			showDate,
			showTime,
			showVenue,
			showExcerpt,
			pagination,
			ajax,
			category,
			past,
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
					{ title: __( 'Layout Settings', 'nettertech-events' ) },
					el( SelectControl, {
						label: __( 'Layout', 'nettertech-events' ),
						value: layout,
						options: [
							{
								label: __( 'Grid', 'nettertech-events' ),
								value: 'grid',
							},
							{
								label: __( 'List', 'nettertech-events' ),
								value: 'list',
							},
							{
								label: __( 'Cards', 'nettertech-events' ),
								value: 'cards',
							},
						],
						onChange( value ) {
							setAttributes( { layout: value } );
						},
					} ),
					el( RangeControl, {
						label: __( 'Events Per Page', 'nettertech-events' ),
						value: limit,
						onChange( value ) {
							setAttributes( { limit: value } );
						},
						min: 1,
						max: 50,
					} ),
					layout !== 'list' &&
						el( RangeControl, {
							label: __( 'Columns', 'nettertech-events' ),
							value: columns,
							onChange( value ) {
								setAttributes( { columns: value } );
							},
							min: 1,
							max: 6,
						} )
				),
				el(
					PanelBody,
					{
						title: __( 'Filter Options', 'nettertech-events' ),
						initialOpen: false,
					},
					el( ToggleControl, {
						label: __( 'Show Filters', 'nettertech-events' ),
						checked: showFilters,
						onChange( value ) {
							setAttributes( { showFilters: value } );
						},
					} ),
					showFilters &&
						el( ToggleControl, {
							label: __( 'Show Search', 'nettertech-events' ),
							checked: showSearch,
							onChange( value ) {
								setAttributes( { showSearch: value } );
							},
						} ),
					showFilters &&
						el( ToggleControl, {
							label: __( 'Show Category Filter', 'nettertech-events' ),
							checked: showCategory,
							onChange( value ) {
								setAttributes( { showCategory: value } );
							},
						} ),
					el( TextControl, {
						label: __(
							'Pre-filter by Category IDs',
							'nettertech-events'
						),
						value: category,
						onChange( value ) {
							setAttributes( { category: value } );
						},
						help: __(
							'Comma-separated category IDs to pre-filter events.',
							'nettertech-events'
						),
					} ),
					el( ToggleControl, {
						label: __( 'Show Past Events', 'nettertech-events' ),
						checked: past,
						onChange( value ) {
							setAttributes( { past: value } );
						},
						help: __(
							'Show past events instead of upcoming.',
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
						label: __( 'Show Excerpt', 'nettertech-events' ),
						checked: showExcerpt,
						onChange( value ) {
							setAttributes( { showExcerpt: value } );
						},
					} )
				),
				el(
					PanelBody,
					{
						title: __( 'Pagination', 'nettertech-events' ),
						initialOpen: false,
					},
					el( ToggleControl, {
						label: __( 'Show Pagination', 'nettertech-events' ),
						checked: pagination,
						onChange( value ) {
							setAttributes( { pagination: value } );
						},
					} ),
					pagination &&
						el( ToggleControl, {
							label: __( 'AJAX Pagination', 'nettertech-events' ),
							checked: ajax,
							onChange( value ) {
								setAttributes( { ajax: value } );
							},
							help: __(
								'Load pages without full page reload.',
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
						className: 'nte-block-preview nte-block-preview--grid',
					},
					el(
						'div',
						{ className: 'nte-block-preview__header' },
						el( 'span', {
							className:
								'nte-block-preview__icon dashicons dashicons-grid-view',
						} ),
						el(
							'span',
							{ className: 'nte-block-preview__title' },
							__( 'Event Grid', 'nettertech-events' )
						)
					),
					el(
						'div',
						{ className: 'nte-block-preview__content' },
						el(
							'p',
							{},
							el(
								'strong',
								{},
								layout.charAt( 0 ).toUpperCase() +
									layout.slice( 1 )
							),
							' ',
							__( 'layout', 'nettertech-events' ),
							layout !== 'list'
								? el(
										Fragment,
										{},
										' ',
										__( 'with', 'nettertech-events' ),
										' ',
										el( 'strong', {}, columns ),
										' ',
										__( 'columns', 'nettertech-events' )
								  )
								: null
						),
						el(
							'p',
							{},
							__( 'Showing', 'nettertech-events' ),
							' ',
							el( 'strong', {}, limit ),
							' ',
							past
								? __( 'past events', 'nettertech-events' )
								: __( 'upcoming events', 'nettertech-events' ),
							' ',
							__( 'per page.', 'nettertech-events' )
						)
					)
				)
			)
		);
	}

	/**
	 * Save component for the event grid block.
	 *
	 * @return {null} Null - dynamic block rendered server-side.
	 */
	function Save() {
		return null;
	}

	registerBlockType( 'nettertech-events/grid', {
		edit: Edit,
		save: Save,
	} );
} )( window.wp );
