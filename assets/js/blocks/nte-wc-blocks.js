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
	 * Read the accessibility-notes field definition localized by
	 * BlockIntegration::get_script_data() (`nettertech-events_data`).
	 *
	 * @return {Object|null} { inputName, label, placeholder, purpose, maxLength } or null.
	 */
	function getAccessibilityNotesSettings() {
		var wcSettings = window.wc && window.wc.wcSettings;
		if ( ! wcSettings || typeof wcSettings.getSetting !== 'function' ) {
			return null;
		}
		var data = wcSettings.getSetting( NAMESPACE + '_data', {} ) || {};
		var field = data.accessibilityNotes;
		if ( ! field || ! field.inputName || ! field.label ) {
			return null;
		}
		return field;
	}

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

		// Strings, limit and input name come from the server-side field
		// definition (BlockIntegration::get_script_data) so this textarea reads
		// exactly like the classic-checkout and RSVP ones. No payload, no field:
		// a silently different wording is worse than a missing optional field.
		var field = getAccessibilityNotesSettings();
		if ( ! field ) {
			return null;
		}

		var textareaId = 'nte-wc-blocks-accessibility-notes';
		var purposeId = textareaId + '-purpose';

		return createElement(
			'div',
			{ className: 'nte-accessibility-notes-field nte-wc-blocks-field' },
			createElement(
				'label',
				{ className: 'nte-wc-blocks-field__heading', htmlFor: textareaId },
				field.label
			),
			createElement( 'textarea', {
				id: textareaId,
				className: 'nte-wc-blocks-field__textarea',
				name: field.inputName,
				placeholder: field.placeholder,
				maxLength: field.maxLength,
				'aria-describedby': purposeId,
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
			} ),
			createElement(
				'p',
				{ className: 'nte-wc-blocks-field__purpose', id: purposeId },
				field.purpose
			)
		);
	};

	// =========================================================================
	// Checkout: Donation Picker
	// =========================================================================

	/**
	 * The cart's order total as display text, or '' when it cannot be formatted.
	 *
	 * @param {Object} totals Store API cart totals.
	 * @return {string} Formatted total.
	 */
	function formatOrderTotal( totals ) {
		const priceFormat = window.wc && window.wc.priceFormat;
		if ( ! totals || ! priceFormat ) {
			return '';
		}
		return priceFormat.formatPrice( totals.total_price, priceFormat.getCurrencyFromPriceResponse( totals ) );
	}

	/**
	 * Announce a message to screen readers.
	 *
	 * @param {string} message    Text to announce.
	 * @param {string} politeness 'polite' or 'assertive'.
	 */
	function announce( message, politeness ) {
		if ( window.wp.a11y && window.wp.a11y.speak ) {
			window.wp.a11y.speak( message, politeness || 'polite' );
		}
	}

	/**
	 * Donation picker for Block checkout: the block counterpart of
	 * DonationHandler::render_donation_field(), with the same classes so one
	 * stylesheet themes both.
	 *
	 * Everything it shows comes from the cart's Store API extension data
	 * (DonationHandler::get_store_api_data), refreshed with every cart response:
	 * it appears only for carts with tickets, and the round-up label is the
	 * server's figure for the current totals, never worked out here. A choice is
	 * sent with extensionCartUpdate, which recalculates the cart.
	 *
	 * @param {Object} props Slot fill props: cart, extensions, context.
	 * @return {Object|null} Element, or null when the cart cannot take a donation.
	 */
	const DonationPicker = function ( props ) {
		const pendingChoice = element.useState( null );
		const customText = element.useState( null );
		const errorText = element.useState( '' );
		const busy = element.useState( false );

		const cart = props.cart || {};
		const cartExtensions = props.extensions || cart.extensions || {};
		const donation = cartExtensions[ NAMESPACE ];

		// The order meta slot also renders in the Cart block. Ask once, at
		// checkout, like the classic picker.
		if ( 'woocommerce/checkout' !== props.context || ! donation || ! donation.available ) {
			return null;
		}

		const fieldId = 'nte-wc-blocks-donation';
		const causeId = fieldId + '-cause';
		const customId = fieldId + '-custom-amount';
		const errorId = fieldId + '-error';
		const choice = pendingChoice[ 0 ] || donation.selected;
		const customValue = null !== customText[ 0 ]
			? customText[ 0 ]
			: ( 'custom' === donation.selected ? String( donation.amount ) : '' );

		async function send( option, amount ) {
			if ( ! blocksCheckout.extensionCartUpdate ) {
				return;
			}
			busy[ 1 ]( true );
			errorText[ 1 ]( '' );

			try {
				await blocksCheckout.extensionCartUpdate( {
					namespace: NAMESPACE,
					data: {
						donation_option: option,
						donation_amount: amount,
					},
				} );
			} catch ( error ) {
				busy[ 1 ]( false );
				pendingChoice[ 1 ]( null );
				const failure = error && error.message
					? decodeEntities( error.message )
					: __( 'Your donation could not be updated. Please try again.', 'nettertech-events' );
				errorText[ 1 ]( failure );
				announce( failure, 'assertive' );
				return;
			}

			busy[ 1 ]( false );
			pendingChoice[ 1 ]( null );
			customText[ 1 ]( null );
			const cartStore = window.wp.data && window.wp.data.select( 'wc/store/cart' );
			const total = cartStore ? formatOrderTotal( cartStore.getCartTotals() ) : '';
			if ( total ) {
				/* translators: %s: order total */
				announce( i18n.sprintf( __( 'Donation updated. Order total: %s', 'nettertech-events' ), total ) );
			} else {
				announce( __( 'Donation updated.', 'nettertech-events' ) );
			}
		}

		/**
		 * The custom amount as a number, or an error message when it is out of range.
		 *
		 * @return {Object} { amount } or { error }.
		 */
		function readCustomAmount() {
			const amount = parseFloat( customValue );
			if ( ! isFinite( amount ) || amount < 1 ) {
				/* translators: %s: smallest amount, with currency symbol */
				return { error: i18n.sprintf( __( 'Enter an amount of at least %s.', 'nettertech-events' ), donation.currency_symbol + '1' ) };
			}
			if ( amount > donation.max ) {
				/* translators: %s: largest amount, with currency symbol */
				return { error: i18n.sprintf( __( 'Enter an amount no more than %s.', 'nettertech-events' ), donation.currency_symbol + donation.max ) };
			}
			return { amount: amount };
		}

		function submitCustom() {
			if ( '' === customValue ) {
				return;
			}
			const result = readCustomAmount();
			if ( result.error ) {
				errorText[ 1 ]( result.error );
				announce( result.error, 'assertive' );
				return;
			}
			if ( 'custom' === donation.selected && Math.abs( donation.amount - result.amount ) < 0.01 ) {
				return;
			}
			send( 'custom', result.amount );
		}

		function radioOption( key, value, amount, label, checked ) {
			return createElement(
				'label',
				{ key: key, className: 'nte-donation-field__option' },
				createElement( 'input', {
					type: 'radio',
					name: fieldId + '-option',
					className: 'nte-donation-option',
					value: value,
					checked: checked,
					onChange: function () {
						pendingChoice[ 1 ]( value );
						send( value, amount );
					},
				} ),
				createElement( 'span', { className: 'nte-donation-field__label' }, label )
			);
		}

		const options = [
			radioOption( 'roundup', 'roundup', 0, donation.roundup_label, 'roundup' === choice ),
		];

		( donation.presets || [] ).forEach( function ( preset, index ) {
			options.push(
				radioOption(
					'preset-' + index,
					'fixed',
					preset.amount,
					preset.label,
					'fixed' === choice && Math.abs( donation.amount - preset.amount ) < 0.01
				)
			);
		} );

		if ( donation.allow_custom ) {
			const hasError = '' !== errorText[ 0 ];
			options.push(
				createElement(
					'label',
					{ key: 'custom', className: 'nte-donation-field__option nte-donation-field__option--custom' },
					createElement( 'input', {
						type: 'radio',
						name: fieldId + '-option',
						className: 'nte-donation-option',
						value: 'custom',
						checked: 'custom' === choice,
						onChange: function () {
							pendingChoice[ 1 ]( 'custom' );
							const input = document.getElementById( customId );
							if ( input ) {
								input.focus();
							}
							if ( '' !== customValue ) {
								submitCustom();
							}
						},
					} ),
					createElement( 'span', { className: 'nte-donation-field__label' }, __( 'Other:', 'nettertech-events' ) ),
					createElement( 'span', { className: 'nte-donation-field__currency', 'aria-hidden': 'true' }, donation.currency_symbol ),
					createElement( 'input', {
						id: customId,
						type: 'number',
						className: 'nte-donation-custom-amount',
						inputMode: 'decimal',
						min: 1,
						max: donation.max,
						step: 1,
						value: customValue,
						'aria-label': __( 'Donation amount', 'nettertech-events' ),
						'aria-invalid': hasError ? 'true' : undefined,
						'aria-describedby': hasError ? errorId : undefined,
						onFocus: function () {
							pendingChoice[ 1 ]( 'custom' );
						},
						onChange: function ( event ) {
							customText[ 1 ]( event.target.value );
							errorText[ 1 ]( '' );
						},
						onBlur: submitCustom,
						onKeyDown: function ( event ) {
							// Enter applies the amount; it must not place the order.
							if ( 'Enter' === event.key ) {
								event.preventDefault();
								submitCustom();
							}
						},
					} )
				)
			);
		}

		options.push(
			radioOption( 'none', 'none', 0, __( 'No thanks', 'nettertech-events' ), 'none' === choice || '' === choice )
		);

		// The outer element takes the spacing WooCommerce gives every order
		// summary slot item, so the picker's own box keeps its padding.
		return createElement(
			'div',
			{ className: 'nte-donation-field-slot' },
			createElement(
				'div',
				{ className: 'nte-donation-field nte-donation-field--block' + ( busy[ 0 ] ? ' nte-loading' : '' ) },
				createElement(
					'fieldset',
					{
						className: 'nte-donation-field__fieldset',
						'aria-describedby': donation.cause ? causeId : undefined,
					},
					createElement(
						'legend',
						{ className: 'nte-donation-field__title' },
						createElement( 'span', { className: 'nte-donation-field__heart', 'aria-hidden': 'true' }, '❤' ),
						__( 'Add a Donation', 'nettertech-events' )
					),
					donation.cause
						? createElement( 'p', { className: 'nte-donation-field__cause', id: causeId }, donation.cause )
						: null,
					createElement( 'div', { className: 'nte-donation-field__options' }, options ),
					'' !== errorText[ 0 ]
						? createElement( 'p', { className: 'nte-donation-field__error', id: errorId }, errorText[ 0 ] )
						: null
				)
			)
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
						createElement( DonationPicker, {} ),
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
