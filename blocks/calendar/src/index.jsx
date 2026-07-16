/**
 * Event Calendar Block - JSX Version
 */

import { registerBlockType } from '@wordpress/blocks';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import '../../editor.css';
import { PanelBody, SelectControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

function Edit( { attributes, setAttributes } ) {
	const { view, showViewSwitcher, showNavigation } = attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Calendar Settings', 'nettertech-events' ) }>
					<SelectControl
						label={ __( 'Default View', 'nettertech-events' ) }
						value={ view }
						options={ [
							{ label: __( 'Month', 'nettertech-events' ), value: 'month' },
							{ label: __( 'Week', 'nettertech-events' ), value: 'week' },
							{ label: __( 'Day', 'nettertech-events' ), value: 'day' },
						] }
						onChange={ ( value ) => setAttributes( { view: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show View Switcher', 'nettertech-events' ) }
						checked={ showViewSwitcher }
						onChange={ ( value ) => setAttributes( { showViewSwitcher: value } ) }
						help={ __( 'Allow users to switch between month, week, and day views.', 'nettertech-events' ) }
					/>
					<ToggleControl
						label={ __( 'Show Navigation', 'nettertech-events' ) }
						checked={ showNavigation }
						onChange={ ( value ) => setAttributes( { showNavigation: value } ) }
						help={ __( 'Show previous/next navigation buttons.', 'nettertech-events' ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<div className="nte-block-preview nte-block-preview--calendar">
					<div className="nte-block-preview__header">
						<span className="nte-block-preview__icon dashicons dashicons-calendar-alt" />
						<span className="nte-block-preview__title">
							{ __( 'Event Calendar', 'nettertech-events' ) }
						</span>
					</div>
					<div className="nte-block-preview__content">
						<p>
							{ __( 'Calendar will display here with', 'nettertech-events' ) }{ ' ' }
							<strong>{ view }</strong>{ ' ' }
							{ __( 'view.', 'nettertech-events' ) }
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

registerBlockType( 'nettertech-events/calendar', {
	edit: Edit,
	save: Save,
} );
