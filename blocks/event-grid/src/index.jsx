/**
 * Event Grid Block - JSX Version
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	TextControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import '../../editor.css';

function Edit( { attributes, setAttributes } ) {
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

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Layout Settings', 'nettertech-events' ) }>
					<SelectControl
						label={ __( 'Layout', 'nettertech-events' ) }
						value={ layout }
						options={ [
							{ label: __( 'Grid', 'nettertech-events' ), value: 'grid' },
							{ label: __( 'List', 'nettertech-events' ), value: 'list' },
							{ label: __( 'Cards', 'nettertech-events' ), value: 'cards' },
						] }
						onChange={ ( value ) => setAttributes( { layout: value } ) }
					/>
					<RangeControl
						label={ __( 'Events Per Page', 'nettertech-events' ) }
						value={ limit }
						onChange={ ( value ) => setAttributes( { limit: value } ) }
						min={ 1 }
						max={ 50 }
					/>
					{ layout !== 'list' && (
						<RangeControl
							label={ __( 'Columns', 'nettertech-events' ) }
							value={ columns }
							onChange={ ( value ) => setAttributes( { columns: value } ) }
							min={ 1 }
							max={ 6 }
						/>
					) }
				</PanelBody>
				<PanelBody
					title={ __( 'Filter Options', 'nettertech-events' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show Filters', 'nettertech-events' ) }
						checked={ showFilters }
						onChange={ ( value ) => setAttributes( { showFilters: value } ) }
					/>
					{ showFilters && (
						<ToggleControl
							label={ __( 'Show Search', 'nettertech-events' ) }
							checked={ showSearch }
							onChange={ ( value ) => setAttributes( { showSearch: value } ) }
						/>
					) }
					{ showFilters && (
						<ToggleControl
							label={ __( 'Show Category Filter', 'nettertech-events' ) }
							checked={ showCategory }
							onChange={ ( value ) => setAttributes( { showCategory: value } ) }
						/>
					) }
					<TextControl
						label={ __( 'Pre-filter by Category IDs', 'nettertech-events' ) }
						value={ category }
						onChange={ ( value ) => setAttributes( { category: value } ) }
						help={ __( 'Comma-separated category IDs to pre-filter events.', 'nettertech-events' ) }
					/>
					<ToggleControl
						label={ __( 'Show Past Events', 'nettertech-events' ) }
						checked={ past }
						onChange={ ( value ) => setAttributes( { past: value } ) }
						help={ __( 'Show past events instead of upcoming.', 'nettertech-events' ) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Display Options', 'nettertech-events' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show Image', 'nettertech-events' ) }
						checked={ showImage }
						onChange={ ( value ) => setAttributes( { showImage: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show Date', 'nettertech-events' ) }
						checked={ showDate }
						onChange={ ( value ) => setAttributes( { showDate: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show Time', 'nettertech-events' ) }
						checked={ showTime }
						onChange={ ( value ) => setAttributes( { showTime: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show Venue', 'nettertech-events' ) }
						checked={ showVenue }
						onChange={ ( value ) => setAttributes( { showVenue: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show Excerpt', 'nettertech-events' ) }
						checked={ showExcerpt }
						onChange={ ( value ) => setAttributes( { showExcerpt: value } ) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Pagination', 'nettertech-events' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Show Pagination', 'nettertech-events' ) }
						checked={ pagination }
						onChange={ ( value ) => setAttributes( { pagination: value } ) }
					/>
					{ pagination && (
						<ToggleControl
							label={ __( 'AJAX Pagination', 'nettertech-events' ) }
							checked={ ajax }
							onChange={ ( value ) => setAttributes( { ajax: value } ) }
							help={ __( 'Load pages without full page reload.', 'nettertech-events' ) }
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="nte-block-preview nte-block-preview--grid">
					<div className="nte-block-preview__header">
						<span className="nte-block-preview__icon dashicons dashicons-grid-view" />
						<span className="nte-block-preview__title">
							{ __( 'Event Grid', 'nettertech-events' ) }
						</span>
					</div>
					<div className="nte-block-preview__content">
						<p>
							<strong>
								{ layout.charAt( 0 ).toUpperCase() + layout.slice( 1 ) }
							</strong>{ ' ' }
							{ __( 'layout', 'nettertech-events' ) }
							{ layout !== 'list' && (
								<>
									{ ' ' }{ __( 'with', 'nettertech-events' ) }{ ' ' }
									<strong>{ columns }</strong>{ ' ' }
									{ __( 'columns', 'nettertech-events' ) }
								</>
							) }
						</p>
						<p>
							{ __( 'Showing', 'nettertech-events' ) }{ ' ' }
							<strong>{ limit }</strong>{ ' ' }
							{ past
								? __( 'past events', 'nettertech-events' )
								: __( 'upcoming events', 'nettertech-events' )
							}{ ' ' }
							{ __( 'per page.', 'nettertech-events' ) }
						</p>
					</div>
				</div>
			</div>
		</>
	);
}

function Save() {
	return null;
}

registerBlockType( 'nettertech-events/grid', {
	edit: Edit,
	save: Save,
} );
