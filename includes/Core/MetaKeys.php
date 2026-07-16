<?php
/**
 * Centralized meta keys.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Manages all meta keys used by the plugin to avoid string typos.
 *
 * @since 0.1.0
 * @api
 */
final class MetaKeys {

	// =========================================================================
	// Event Meta
	// =========================================================================

	/**
	 * Meta key for the event's recurrence rule (RRULE string).
	 * Stored on the event's backing post type.
	 */
	public const RECURRENCE_RULE = '_nettertech_events_recurrence_rule';

	/**
	 * Meta key for the event's capacity.
	 * Stored on the event's backing post type.
	 */
	public const CAPACITY = '_nettertech_events_capacity';

	// =========================================================================
	// WooCommerce Product Meta
	// =========================================================================

	/**
	 * Meta key linking a WooCommerce product to a NetterTechEvents ticket type ID.
	 */
	public const TICKET_TYPE_ID = '_nettertech_events_ticket_type_id';

	/**
	 * Meta key linking a WooCommerce product to a NetterTechEvents occurrence ID.
	 */
	public const OCCURRENCE_ID = '_nettertech_events_occurrence_id';

	/**
	 * Meta key linking a WooCommerce product to a NetterTechEvents event ID (for series passes).
	 */
	public const EVENT_ID = '_nettertech_events_event_id';

	/**
	 * Meta key to identify a WooCommerce product as a NetterTechEvents ticket.
	 * Value is 'yes'.
	 */
	public const IS_EVENT_TICKET = '_nettertech_events_is_event_ticket';

	/**
	 * Meta key to identify a WooCommerce product as a series pass.
	 * Value is 'yes'.
	 */
	public const IS_SERIES_PASS = '_nettertech_events_is_series_pass';

	// =========================================================================
	// WooCommerce Order Meta
	// =================================================_========================

	/**
	 * Meta key on a WC_Order to mark that attendee records have been created.
	 * Prevents duplicate processing. Value is 'yes'.
	 */
	public const ATTENDEES_CREATED = '_nettertech_events_attendees_created';

	/**
	 * Meta key on a WC_Order to store accessibility notes from checkout.
	 */
	public const ACCESSIBILITY_NOTES = '_nettertech_events_accessibility_notes';

	/**
	 * Meta key on a WC_Order to mark that confirmation emails have been sent.
	 * Value is 'yes'.
	 */
	public const CONFIRMATION_EMAIL_SENT = '_nettertech_events_confirmation_email_sent';

	/**
	 * Meta key on a WC_Order to store the timestamp of when the confirmation was sent.
	 */
	public const CONFIRMATION_EMAIL_SENT_AT = '_nettertech_events_confirmation_email_sent_at';

	/**
	 * Meta key on a WC_Order to store the timestamp of when the confirmation was last resent.
	 */
	public const CONFIRMATION_EMAIL_RESENT_AT = '_nettertech_events_confirmation_email_resent_at';

	/**
	 * Meta key prefix for atomic per-refund idempotency tracking.
	 * Full key: _nettertech_events_refund_processed_{refund_id}
	 */
	public const PROCESSED_REFUND_PREFIX = '_nettertech_events_refund_processed_';

	/**
	 * Meta key on a WC_Order to store custom attendee field data from checkout.
	 * JSON-encoded map of event_id => field_key => value.
	 *
	 * @since 3.6.0
	 */
	public const CUSTOM_FIELD_DATA = '_nettertech_events_custom_field_data';

	/**
	 * Meta key on a WC_Order_Item_Product to store per-attendee data.
	 * JSON-encoded array of attendee objects (name, email, phone, custom_fields).
	 * Present only when collect_individual_attendees is enabled and qty > 1.
	 *
	 * @since 3.6.0
	 */
	public const ATTENDEE_DATA = '_nettertech_events_attendee_data';

	// =========================================================================
	// WooCommerce Order Item Meta
	// =========================================================================
	// These are also used on the WC_Order_Item_Product object.
	// Duplicating constants for clarity where they are used.
	// phpcs:disable Generic.CodeAnalysis.DuplicateCode.Found -- intentional constant duplication for clarity at WC_Order_Item usage sites.

	/**
	 * Meta key linking a WC_Order_Item_Product to a NetterTechEvents ticket type ID.
	 */
	public const WC_ORDER_ITEM_TICKET_TYPE_ID = self::TICKET_TYPE_ID;

	/**
	 * Meta key linking a WC_Order_Item_Product to a NetterTechEvents occurrence ID.
	 */
	public const WC_ORDER_ITEM_OCCURRENCE_ID = self::OCCURRENCE_ID;

	/**
	 * Meta key linking a WC_Order_Item_Product to a NetterTechEvents event ID.
	 */
	public const WC_ORDER_ITEM_EVENT_ID = self::EVENT_ID;

	/**
	 * Meta key identifying a WC_Order_Item_Product as a series pass.
	 */
	public const WC_ORDER_ITEM_IS_SERIES_PASS = self::IS_SERIES_PASS;

	// phpcs:enable
}
