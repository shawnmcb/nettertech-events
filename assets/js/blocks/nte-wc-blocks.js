/* eslint-disable no-unused-vars -- T4.1.4 grandfather: pre-existing violations baselined 2026-05-10 in audit/suite/frontend/EVENTS-FRONTEND-GATE-BASELINE.md. Remove on next refactor; new code must comply with FRONTEND-GATE-RULES.md. */
/**
 * NetterTech Events — WooCommerce Blocks Integration.
 *
 * Renders event ticket metadata (date, time, venue, ticket type) in
 * Block-based Cart and Checkout via WC Blocks slot fills.
 *
 * IIFE pattern — no build step required. Uses wp.element and
 * wc.blocksCheckout globals provided by WordPress/WooCommerce.
 *
 * @package NetterTechEvents
 * @since 1.1.0
 */
( function ( element, blocksCheckout, i18n, htmlEntities ) {
	'use strict';

	var createElement = element.createElement;
	var __ = i18n.__;
	var decodeEntities = htmlEntities.decodeEntities;

	var ExperimentalOrderMeta = blocksCheckout.ExperimentalOrderMeta;
	var registerCheckoutFilters = blocksCheckout.registerCheckoutFilters;

	var NAMESPACE = 'nettertech-events';

	// =========================================================================
	// Cart Item Metadata Display
	// =========================================================================

	/**
	 * Filter cart item name to link to event page for ticket products.
	 *
	 * Uses the `itemName` checkout filter provided by WC Blocks.
	 */
	registerCheckoutFilters( NAMESPACE, {
		itemName: function ( defaultValue, extensions, args ) {
			var data = args && args.cart && args.cart.extensions
				? args.cart.extensions[ NAMESPACE ]
				: null;

			// Cart item extensions are on the item, not the cart.
			if ( args && args.context === 'cart' ) {
				data = extensions[ NAMESPACE ] || null;
			}

			if ( ! data || ! data.is_event_ticket ) {
				return defaultValue;
			}

			// Return the event title if available; WC Blocks handles the link.
			if ( data.event_title ) {
				return decodeEntities( data.event_title );
			}

			return defaultValue;
		},
	} );

	/**
	 * Register a filter to add event metadata below cart item details.
	 *
	 * WC Blocks exposes `cartItemData` filter for additional key-value pairs.
	 * This is the Block equivalent of `woocommerce_get_item_data`.
	 */
	registerCheckoutFilters( NAMESPACE, {
		cartItemData: function ( defaultValue, extensions ) {
			var data = extensions[ NAMESPACE ];

			if ( ! data || ! data.is_event_ticket ) {
				return defaultValue;
			}

			var items = defaultValue || [];

			if ( data.event_date ) {
				items = items.concat( {
					key: 'nettertech_events_event_date',
					label: __( 'Date', 'nettertech-events' ),
					value: data.event_date,
				} );
			}

			if ( data.event_time ) {
				items = items.concat( {
					key: 'nettertech_events_event_time',
					label: __( 'Time', 'nettertech-events' ),
					value: data.event_time,
				} );
			}

			if ( data.venue_name ) {
				items = items.concat( {
					key: 'nettertech_events_venue_name',
					label: __( 'Venue', 'nettertech-events' ),
					value: data.venue_name,
				} );
			}

			if ( data.ticket_type ) {
				items = items.concat( {
					key: 'nettertech_events_ticket_type',
					label: __( 'Ticket Type', 'nettertech-events' ),
					value: data.ticket_type,
				} );
			}

			return items;
		},
	} );

	// =========================================================================
	// Checkout: Custom Attendee Registration Fields
	// =========================================================================

	/**
	 * Collect custom field definitions from all cart items and deduplicate by event.
	 */
	function getCustomFieldsFromCart( cart ) {
		var items = cart.cartItems || [];
		var fieldsByEvent = {};

		for ( var i = 0; i < items.length; i++ ) {
			var itemExt = items[ i ].extensions || {};
			var data = itemExt[ NAMESPACE ];
			if ( data && data.is_event_ticket && data.attendee_fields && data.attendee_fields.length > 0 ) {
				var eventId = data.event_id;
				if ( eventId && ! fieldsByEvent[ eventId ] ) {
					fieldsByEvent[ eventId ] = data.attendee_fields;
				}
			}
		}

		return fieldsByEvent;
	}

	/**
	 * Custom attendee fields component for Block checkout.
	 *
	 * Renders custom registration fields per event in the ExperimentalOrderMeta slot.
	 */
	var CustomFieldsComponent = function ( props ) {
		var cart = props.cart || {};
		var fieldsByEvent = getCustomFieldsFromCart( cart );
		var eventIds = Object.keys( fieldsByEvent );

		if ( eventIds.length === 0 ) {
			return null;
		}

		var fieldElements = [];

		fieldElements.push(
			createElement( 'h3', {
				className: 'nte-wc-blocks-field__heading',
				key: 'heading',
			}, __( 'Registration Information', 'nettertech-events' ) )
		);

		for ( var e = 0; e < eventIds.length; e++ ) {
			var eventId = eventIds[ e ];
			var fields = fieldsByEvent[ eventId ];

			for ( var f = 0; f < fields.length; f++ ) {
				var field = fields[ f ];
				var fieldKey = eventId + '_' + field.field_key;

				var inputEl;
				if ( field.field_type === 'textarea' ) {
					inputEl = createElement( 'textarea', {
						key: fieldKey,
						className: 'nte-wc-blocks-field__textarea',
						name: 'nettertech_events_cf_' + fieldKey,
						placeholder: field.placeholder || '',
						rows: 3,
						required: field.is_required,
						onChange: createFieldChangeHandler( eventId, field.field_key ),
					} );
				} else if ( field.field_type === 'select' ) {
					var options = [ createElement( 'option', { key: '', value: '' }, __( 'Select an option', 'nettertech-events' ) ) ];
					for ( var o = 0; o < ( field.options || [] ).length; o++ ) {
						options.push( createElement( 'option', { key: field.options[ o ], value: field.options[ o ] }, field.options[ o ] ) );
					}
					inputEl = createElement( 'select', {
						key: fieldKey,
						className: 'nte-wc-blocks-field__select',
						name: 'nettertech_events_cf_' + fieldKey,
						required: field.is_required,
						onChange: createFieldChangeHandler( eventId, field.field_key ),
					}, options );
				} else {
					inputEl = createElement( 'input', {
						key: fieldKey,
						type: field.field_type === 'phone' ? 'tel' : ( field.field_type || 'text' ),
						className: 'nte-wc-blocks-field__input',
						name: 'nettertech_events_cf_' + fieldKey,
						placeholder: field.placeholder || '',
						required: field.is_required,
						onChange: createFieldChangeHandler( eventId, field.field_key ),
					} );
				}

				fieldElements.push(
					createElement( 'div', {
						key: 'wrap-' + fieldKey,
						className: 'nte-wc-blocks-field__row',
					},
						createElement( 'label', {
							className: 'nte-wc-blocks-field__label',
							htmlFor: 'nettertech_events_cf_' + fieldKey,
						}, field.label + ( field.is_required ? ' *' : '' ) ),
						inputEl
					)
				);
			}
		}

		return createElement( 'div', {
			className: 'nte-custom-fields-checkout nte-wc-blocks-field',
		}, fieldElements );
	};

	/**
	 * Accumulated field values for extensionCartUpdate batching.
	 */
	var customFieldValues = {};

	/**
	 * Create a change handler that batches field values and sends via extensionCartUpdate.
	 */
	function createFieldChangeHandler( eventId, fieldKey ) {
		return function ( event ) {
			if ( ! customFieldValues[ eventId ] ) {
				customFieldValues[ eventId ] = {};
			}
			customFieldValues[ eventId ][ fieldKey ] = event.target.value;

			if ( blocksCheckout.extensionCartUpdate ) {
				blocksCheckout.extensionCartUpdate( {
					namespace: NAMESPACE,
					data: {
						custom_field_data: JSON.stringify( customFieldValues ),
					},
				} );
			}
		};
	}

	// =========================================================================
	// Checkout: Accessibility Notes Field
	// =========================================================================

	/**
	 * Accessibility notes component for Block checkout.
	 *
	 * Renders a textarea in the ExperimentalOrderMeta slot — the Block
	 * equivalent of the `woocommerce_after_order_notes` action.
	 */
	var AccessibilityNotesField = function ( props ) {
		var extensions = props.extensions || {};
		var cart = props.cart || {};

		// Only show if cart has event tickets.
		var hasTickets = false;
		var items = cart.cartItems || [];
		for ( var i = 0; i < items.length; i++ ) {
			var itemExt = items[ i ].extensions || {};
			if ( itemExt[ NAMESPACE ] && itemExt[ NAMESPACE ].is_event_ticket ) {
				hasTickets = true;
				break;
			}
		}

		if ( ! hasTickets ) {
			return null;
		}

		return createElement(
			'div',
			{ className: 'nte-accessibility-notes-field nte-wc-blocks-field' },
			createElement(
				'h3',
				{ className: 'nte-wc-blocks-field__heading' },
				__( 'Accessibility Requirements', 'nettertech-events' )
			),
			createElement( 'textarea', {
				className: 'nte-wc-blocks-field__textarea',
				name: 'nettertech_events_accessibility_notes',
				placeholder: __(
					'Please let us know about any accessibility needs or accommodations we can provide (wheelchair access, ASL interpreter, etc.)',
					'nettertech-events'
				),
				rows: 3,
				onChange: function ( event ) {
					// Store in extension data for server-side persistence.
					if ( blocksCheckout.extensionCartUpdate ) {
						blocksCheckout.extensionCartUpdate( {
							namespace: NAMESPACE,
							data: {
								accessibility_notes: event.target.value,
							},
						} );
					}
				},
			} )
		);
	};

	/**
	 * Register the ExperimentalOrderMeta slot fill.
	 */
	if ( ExperimentalOrderMeta ) {
		var render = function () {
			return createElement( ExperimentalOrderMeta, null,
				createElement( blocksCheckout.ExperimentalOrderMeta.Slot, null,
					function ( slotProps ) {
						return createElement( AccessibilityNotesField, slotProps );
					}
				)
			);
		};

		// Use the block registration approach.
		var registerPlugin = window.wp && window.wp.plugins && window.wp.plugins.registerPlugin;
		if ( registerPlugin ) {
			registerPlugin( 'nettertech-events-checkout-fields', {
				render: function () {
					return createElement( ExperimentalOrderMeta, null,
						createElement( CustomFieldsComponent, {} ),
						createElement( AccessibilityNotesField, {} )
					);
				},
				scope: 'woocommerce-checkout',
			} );
		}
	}
} )(
	window.wp.element,
	window.wc.blocksCheckout,
	window.wp.i18n,
	window.wp.htmlEntities
);
