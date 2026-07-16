/**
 * Event Carousel Block - JSX Version
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	PanelBody,
	RangeControl,
	SelectControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import '../../editor.css';

function Edit( { attributes, setAttributes } ) {
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

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Carousel Settings', 'nettertech-events' ) }>
					<RangeControl
						label={ __( 'Number of Events', 'nettertech-events' ) }
						value={ limit }
						onChange={ ( value ) => setAttributes( { limit: value } ) }
						min={ 1 }
						max={ 20 }
					/>
					<RangeControl
						label={ __( 'Visible Columns', 'nettertech-events' ) }
						value={ columns }
						onChange={ ( value ) => setAttributes( { columns: value } ) }
						min={ 1 }
						max={ 6 }
						help={ __( 'Number of cards visible at once.', 'nettertech-events' ) }
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
						label={ __( 'Always Show Year', 'nettertech-events' ) }
						checked={ showYear }
						onChange={ ( value ) => setAttributes( { showYear: value } ) }
						help={ __( 'Year is always shown for events in different years.', 'nettertech-events' ) }
					/>
					<RangeControl
						label={ __( 'Max Tags Per Card', 'nettertech-events' ) }
						value={ maxTags }
						onChange={ ( value ) => setAttributes( { maxTags: value } ) }
						min={ 0 }
						max={ 20 }
						help={ __( 'Maximum tags shown per card. Set to 0 to show all. Extras collapse into …and N more.', 'nettertech-events' ) }
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Autoplay', 'nettertech-events' ) }
					initialOpen={ false }
				>
					<ToggleControl
						label={ __( 'Enable Autoplay', 'nettertech-events' ) }
						checked={ autoplay }
						onChange={ ( value ) => setAttributes( { autoplay: value } ) }
					/>
					{ autoplay && (
						<RangeControl
							label={ __( 'Interval (ms)', 'nettertech-events' ) }
							value={ interval }
							onChange={ ( value ) => setAttributes( { interval: value } ) }
							min={ 2000 }
							max={ 10000 }
							step={ 500 }
						/>
					) }
					{ autoplay && (
						<SelectControl
							label={ __( 'Playback Mode', 'nettertech-events' ) }
							value={ playbackMode }
							options={ [
								{ label: __( 'Play and rewind', 'nettertech-events' ), value: 'rewind' },
								{ label: __( 'Continuous loop', 'nettertech-events' ), value: 'loop' },
							] }
							onChange={ ( value ) => setAttributes( { playbackMode: value } ) }
							help={ __( 'Rewind snaps back to start; loop advances seamlessly. Reduced-motion users always see rewind.', 'nettertech-events' ) }
						/>
					) }
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="nte-block-preview nte-block-preview--carousel">
					<div className="nte-block-preview__header">
						<span className="nte-block-preview__icon dashicons dashicons-slides" />
						<span className="nte-block-preview__title">
							{ __( 'Event Carousel', 'nettertech-events' ) }
						</span>
					</div>
					<div className="nte-block-preview__content">
						<p>
							{ __( 'Showing', 'nettertech-events' ) }{ ' ' }
							<strong>{ limit }</strong>{ ' ' }
							{ __( 'events in', 'nettertech-events' ) }{ ' ' }
							<strong>{ columns }</strong>{ ' ' }
							{ __( 'columns.', 'nettertech-events' ) }
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

registerBlockType( 'nettertech-events/carousel', {
	edit: Edit,
	save: Save,
} );
