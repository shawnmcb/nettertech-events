<?php
/**
 * Hooks unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\Hooks;

/**
 * Test Hooks class functionality.
 *
 * Verifies that the Hooks constants class declares the expected hook names
 * with the correct values and follows project naming conventions.
 *
 * @coversDefaultClass \NetterTechEvents\Core\Hooks
 *
 * @group structural
 */
class HooksTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( Hooks::class ) );
	}

	/**
	 * Test class is final.
	 *
	 * @return void
	 */
	public function test_class_is_final(): void {
		$reflection = new \ReflectionClass( Hooks::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	// =========================================================================
	// Constant Value Tests (Data Provider)
	// =========================================================================

	/**
	 * Provide constant name => expected value pairs.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function constant_value_provider(): array {
		return array(
			// Plugin Lifecycle.
			'INIT'                      => array( 'INIT', 'nettertech_events_init' ),
			'ACTIVATED'                 => array( 'ACTIVATED', 'nettertech_events_activated' ),
			'DEACTIVATED'               => array( 'DEACTIVATED', 'nettertech_events_deactivated' ),

			// Data Lifecycle - Events & Occurrences.
			'BEFORE_SAVE_EVENT'         => array( 'BEFORE_SAVE_EVENT', 'nettertech_events_before_save_event' ),
			'AFTER_SAVE_EVENT'          => array( 'AFTER_SAVE_EVENT', 'nettertech_events_after_save_event' ),
			'BEFORE_DELETE_EVENT'       => array( 'BEFORE_DELETE_EVENT', 'nettertech_events_before_delete_event' ),
			'AFTER_DELETE_EVENT'        => array( 'AFTER_DELETE_EVENT', 'nettertech_events_after_delete_event' ),
			'BEFORE_DUPLICATE_EVENT'    => array( 'BEFORE_DUPLICATE_EVENT', 'nettertech_events_before_duplicate_event' ),
			'AFTER_DUPLICATE_EVENT'     => array( 'AFTER_DUPLICATE_EVENT', 'nettertech_events_after_duplicate_event' ),
			'OCCURRENCES_GENERATED'     => array( 'OCCURRENCES_GENERATED', 'nettertech_events_occurrences_generated' ),
			'OCCURRENCE_STATUS_CHANGED' => array( 'OCCURRENCE_STATUS_CHANGED', 'nettertech_events_occurrence_status_changed' ),
			'TEMPLATES_APPLIED'         => array( 'TEMPLATES_APPLIED', 'nettertech_events_templates_applied' ),
			'TICKET_TYPE_SYNC_PRODUCT'  => array( 'TICKET_TYPE_SYNC_PRODUCT', 'nettertech_events_ticket_type_sync_product' ),
			'EVENT_CREATED'             => array( 'EVENT_CREATED', 'nettertech_events_event_created' ),
			'EVENT_UPDATED'             => array( 'EVENT_UPDATED', 'nettertech_events_event_updated' ),
			'EVENT_DELETED'             => array( 'EVENT_DELETED', 'nettertech_events_event_deleted' ),
			'EVENT_PUBLISHED'           => array( 'EVENT_PUBLISHED', 'nettertech_events_event_published' ),
			'EVENT_UNPUBLISHED'         => array( 'EVENT_UNPUBLISHED', 'nettertech_events_event_unpublished' ),
			'EVENT_RESTORED'            => array( 'EVENT_RESTORED', 'nettertech_events_event_restored' ),
			'OCCURRENCE_CREATED'        => array( 'OCCURRENCE_CREATED', 'nettertech_events_occurrence_created' ),
			'OCCURRENCE_DELETED'        => array( 'OCCURRENCE_DELETED', 'nettertech_events_occurrence_deleted' ),
			'OCCURRENCE_CANCELLED'      => array( 'OCCURRENCE_CANCELLED', 'nettertech_events_occurrence_cancelled' ),
			'OCCURRENCE_DELETION_BLOCKED' => array( 'OCCURRENCE_DELETION_BLOCKED', 'nettertech_events_occurrence_deletion_blocked' ),

			// Data Lifecycle - Attendees & Tickets.
			'ATTENDEE_CREATED'          => array( 'ATTENDEE_CREATED', 'nettertech_events_attendee_created' ),
			'ATTENDEE_CANCELLED'        => array( 'ATTENDEE_CANCELLED', 'nettertech_events_attendee_cancelled' ),
			'ATTENDEE_CHECKED_IN'       => array( 'ATTENDEE_CHECKED_IN', 'nettertech_events_attendee_checked_in' ),
			'ATTENDEE_CREATION_FAILED'    => array( 'ATTENDEE_CREATION_FAILED', 'nettertech_events_attendee_creation_failed' ),
			'CUSTOM_FIELD_VALUES_SAVED'  => array( 'CUSTOM_FIELD_VALUES_SAVED', 'nettertech_events_custom_field_values_saved' ),
			'ATTENDEE_FAILURE_HANDLED'   => array( 'ATTENDEE_FAILURE_HANDLED', 'nettertech_events_attendee_failure_handled' ),
			'REGISTRATION_VOIDED'       => array( 'REGISTRATION_VOIDED', 'nettertech_events_registration_voided' ),
			'TICKETS_REFUNDED'          => array( 'TICKETS_REFUNDED', 'nettertech_events_tickets_refunded' ),
			'TICKET_TYPES_SAVED'        => array( 'TICKET_TYPES_SAVED', 'nettertech_events_ticket_types_saved' ),
			'TICKET_ROW_FIELDS'         => array( 'TICKET_ROW_FIELDS', 'nettertech_events_ticket_row_fields' ),
			'ADMIN_TICKET_ROWS'         => array( 'ADMIN_TICKET_ROWS', 'nettertech_events_admin_ticket_rows' ),
			'TICKET_TYPES_TO_DELETE'    => array( 'TICKET_TYPES_TO_DELETE', 'nettertech_events_ticket_types_to_delete' ),
			'TICKET_TYPE_CREATED'       => array( 'TICKET_TYPE_CREATED', 'nettertech_events_ticket_type_created' ),
			'TICKET_TYPE_UPDATED'       => array( 'TICKET_TYPE_UPDATED', 'nettertech_events_ticket_type_updated' ),
			'TICKET_TYPE_SAVED'         => array( 'TICKET_TYPE_SAVED', 'nettertech_events_ticket_type_saved' ),
			'TICKET_TYPE_DELETED'       => array( 'TICKET_TYPE_DELETED', 'nettertech_events_ticket_type_deleted' ),
			'TICKET_ADD_BUTTON_AREA'    => array( 'TICKET_ADD_BUTTON_AREA', 'nettertech_events_ticket_add_button_area' ),
			'AFTER_TICKET_FORM'         => array( 'AFTER_TICKET_FORM', 'nettertech_events_after_ticket_form' ),
			'RSVP_SUBMITTED'            => array( 'RSVP_SUBMITTED', 'nettertech_events_rsvp_submitted' ),

			// Email & Notification.
			'CONFIRMATION_EMAILS_SENT'  => array( 'CONFIRMATION_EMAILS_SENT', 'nettertech_events_confirmation_emails_sent' ),
			'REMINDER_EMAIL_SUBJECT'    => array( 'REMINDER_EMAIL_SUBJECT', 'nettertech_events_reminder_email_subject' ),
			'REMINDER_EMAIL_DATA'       => array( 'REMINDER_EMAIL_DATA', 'nettertech_events_reminder_email_data' ),
			'REMINDER_EMAIL_CONTENT'    => array( 'REMINDER_EMAIL_CONTENT', 'nettertech_events_reminder_email_content' ),
			'ACTION_EMAIL_QR_CODES'    => array( 'ACTION_EMAIL_QR_CODES', 'nettertech_events_email_qr_codes' ),
			'ACTION_EMAIL_RSVP_QR_CODE' => array( 'ACTION_EMAIL_RSVP_QR_CODE', 'nettertech_events_email_rsvp_qr_code' ),

			// Waitlist.
			'WAITLIST_JOINED'           => array( 'WAITLIST_JOINED', 'nettertech_events_waitlist_joined' ),
			'WAITLIST_LEFT'             => array( 'WAITLIST_LEFT', 'nettertech_events_waitlist_left' ),
			'WAITLIST_PROMOTED'         => array( 'WAITLIST_PROMOTED', 'nettertech_events_waitlist_promoted' ),
			'HAS_WAITLIST'              => array( 'HAS_WAITLIST', 'nettertech_events_has_waitlist' ),
			'AFTER_SOLD_OUT'            => array( 'AFTER_SOLD_OUT', 'nettertech_events_after_sold_out' ),
			'WAITLIST_ENTRY_JOINED'     => array( 'WAITLIST_ENTRY_JOINED', 'nettertech_events_waitlist_entry_joined' ),
			'WAITLIST_LEAVE_AUTHORIZED' => array( 'WAITLIST_LEAVE_AUTHORIZED', 'nettertech_events_waitlist_leave_authorized' ),
			'WAITLIST_LEAVE_TOKEN_TTL'  => array( 'WAITLIST_LEAVE_TOKEN_TTL', 'nettertech_events_waitlist_leave_token_ttl' ),
			'WAITLIST_STATUS_AUTHORIZED' => array( 'WAITLIST_STATUS_AUTHORIZED', 'nettertech_events_waitlist_status_authorized' ),
			'WAITLIST_EMAIL_SUBJECT'    => array( 'WAITLIST_EMAIL_SUBJECT', 'nettertech_events_waitlist_email_subject' ),
			'WAITLIST_EMAIL_BODY'       => array( 'WAITLIST_EMAIL_BODY', 'nettertech_events_waitlist_email_body' ),
			'WAITLIST_NOTIFICATION_SENT' => array( 'WAITLIST_NOTIFICATION_SENT', 'nettertech_events_waitlist_notification_sent' ),

			// Registry completion 2026-07 (hooks fired before constants existed).
			'PREFIX_MIGRATION_COMPLETE' => array( 'PREFIX_MIGRATION_COMPLETE', 'nettertech_events_prefix_migration_complete' ),
			'PRODUCT_CAT_IDS'           => array( 'PRODUCT_CAT_IDS', 'nettertech_events_product_cat_ids' ),
			'AFTER_SINGLE_CONTENT'      => array( 'AFTER_SINGLE_CONTENT', 'nettertech_events_after_single_content' ),
			'AFTER_SERIES_CONTENT'      => array( 'AFTER_SERIES_CONTENT', 'nettertech_events_after_series_content' ),
			'SINGLE_SPACE_CONTENT'      => array( 'SINGLE_SPACE_CONTENT', 'nettertech_events_single_space_content' ),
			'AFTER_SINGLE_SPACE_CONTENT' => array( 'AFTER_SINGLE_SPACE_CONTENT', 'nettertech_events_after_single_space_content' ),
			'EMPTY_STATE_MESSAGE'       => array( 'EMPTY_STATE_MESSAGE', 'nettertech_events_empty_state_message' ),
			'EMPTY_STATE_CONTENT'       => array( 'EMPTY_STATE_CONTENT', 'nettertech_events_empty_state_content' ),
			'TICKET_SCAN_DATA'          => array( 'TICKET_SCAN_DATA', 'nettertech_events_ticket_scan_data' ),
			'CSP_FRAME_SRC'             => array( 'CSP_FRAME_SRC', 'nettertech_events_csp_frame_src' ),
			'CSP_SCRIPT_SRC'            => array( 'CSP_SCRIPT_SRC', 'nettertech_events_csp_script_src' ),
			'CSP_REPORT_URI'            => array( 'CSP_REPORT_URI', 'nettertech_events_csp_report_uri' ),
			'TRUSTED_PROXY_RANGES'      => array( 'TRUSTED_PROXY_RANGES', 'nettertech_events_trusted_proxy_ranges' ),
			'ATTENDEES_COLUMNS'         => array( 'ATTENDEES_COLUMNS', 'nettertech_events_attendees_columns' ),
			'ATTENDEES_COLUMN_CONTENT'  => array( 'ATTENDEES_COLUMN_CONTENT', 'nettertech_events_attendees_column_content' ),
			'ATTENDEES_PRIME'           => array( 'ATTENDEES_PRIME', 'nettertech_events_attendees_prime' ),
			'PURCHASES_SYNOPSIS'        => array( 'PURCHASES_SYNOPSIS', 'nettertech_events_purchases_synopsis' ),
			'SALE_SCHEDULE_UI_AVAILABLE' => array( 'SALE_SCHEDULE_UI_AVAILABLE', 'nettertech_events_sale_schedule_ui_available' ),
			'ON_SALE_TICKET_TYPES'      => array( 'ON_SALE_TICKET_TYPES', 'nettertech_events_on_sale_ticket_types' ),
			'HORIZON_EXTENSION_THRESHOLD' => array( 'HORIZON_EXTENSION_THRESHOLD', 'nettertech_events_horizon_extension_threshold' ),
			'HORIZON_EXTENSION_BATCH_SIZE' => array( 'HORIZON_EXTENSION_BATCH_SIZE', 'nettertech_events_horizon_extension_batch_size' ),
			'SAVE_PRO_EXTENSIONS'       => array( 'SAVE_PRO_EXTENSIONS', 'nettertech_events_save_pro_extensions' ),
			'CHECKIN_LOOKUP_DATA'       => array( 'CHECKIN_LOOKUP_DATA', 'nettertech_events_checkin_lookup_data' ),
			'CHECKIN_SEARCH_ITEM'       => array( 'CHECKIN_SEARCH_ITEM', 'nettertech_events_checkin_search_item' ),
			'TICKET_TYPE_REVENUE'       => array( 'TICKET_TYPE_REVENUE', 'nettertech_events_ticket_type_revenue' ),

			// Cache.
			'CACHE_INVALIDATED'         => array( 'CACHE_INVALIDATED', 'nettertech_events_cache_invalidated' ),
			'CAPACITY_CACHE_INVALIDATED' => array( 'CAPACITY_CACHE_INVALIDATED', 'nettertech_events_capacity_cache_invalidated' ),

			// Capacity.
			'CAPACITY_RESERVED'         => array( 'CAPACITY_RESERVED', 'nettertech_events_capacity_reserved' ),
			'CAPACITY_RELEASED'         => array( 'CAPACITY_RELEASED', 'nettertech_events_capacity_released' ),
			'CAPACITY_OVERSELL_DETECTED' => array( 'CAPACITY_OVERSELL_DETECTED', 'nettertech_events_capacity_oversell_detected' ),
			'BUFFER_STOCK_UPDATED'      => array( 'BUFFER_STOCK_UPDATED', 'nettertech_events_buffer_stock_updated' ),
			'AVAILABLE_COUNT'           => array( 'AVAILABLE_COUNT', 'nettertech_events_available_count' ),
			'CAPACITY_CHECK'            => array( 'CAPACITY_CHECK', 'nettertech_events_capacity_check' ),
			'CAPACITY_TYPES'            => array( 'CAPACITY_TYPES', 'nettertech_events_capacity_types' ),

			// Activity Log.
			'ACTIVITY_LOGGED'           => array( 'ACTIVITY_LOGGED', 'nettertech_events_activity_logged' ),
			'ACTIVITY_LOGGING_ENABLED'  => array( 'ACTIVITY_LOGGING_ENABLED', 'nettertech_events_activity_logging_enabled' ),
			'ACTIVITY_LOG_RETENTION_DAYS' => array( 'ACTIVITY_LOG_RETENTION_DAYS', 'nettertech_events_activity_log_retention_days' ),
			'ACTIVITY_RETENTION'        => array( 'ACTIVITY_RETENTION', 'nettertech_events_activity_retention' ),
			'SETTINGS_UPDATED'          => array( 'SETTINGS_UPDATED', 'nettertech_events_settings_updated' ),
			'ATTENDEES_EXPORTED'        => array( 'ATTENDEES_EXPORTED', 'nettertech_events_attendees_exported' ),
			'EVENTS_EXPORTED'           => array( 'EVENTS_EXPORTED', 'nettertech_events_events_exported' ),
			'DAILY_CLEANUP'             => array( 'DAILY_CLEANUP', 'nettertech_events_daily_cleanup' ),

			// Template & Display Filters.
			'TEMPLATE_ARGS'             => array( 'TEMPLATE_ARGS', 'nettertech_events_template_args' ),
			'GET_TEMPLATE_PART'         => array( 'GET_TEMPLATE_PART', 'nettertech_events_get_template_part' ),
			'TEMPLATE_PATHS'            => array( 'TEMPLATE_PATHS', 'nettertech_events_template_paths' ),
			'LOCALIZE_DATA'             => array( 'LOCALIZE_DATA', 'nettertech_events_localize_data' ),
			'CONTAINER_CLASS'           => array( 'CONTAINER_CLASS', 'nettertech_events_container_class' ),
			'CSS_OVERRIDES'             => array( 'CSS_OVERRIDES', 'nettertech_events_css_overrides' ),
			'IMAGE_RATIO_CSS_VARS'      => array( 'IMAGE_RATIO_CSS_VARS', 'nettertech_events_image_ratio_css_vars' ),
			'DETECTED_VIEWS'            => array( 'DETECTED_VIEWS', 'nettertech_events_detected_views' ),
			'LAYOUT_COMPONENTS'         => array( 'LAYOUT_COMPONENTS', 'nettertech_events_layout_components' ),
			'PALETTE_MAP'               => array( 'PALETTE_MAP', 'nettertech_events_palette_map' ),
			'SCHEMA_ORG_DATA'           => array( 'SCHEMA_ORG_DATA', 'nettertech_events_schema_org_data' ),
			'OPEN_GRAPH_TAGS'           => array( 'OPEN_GRAPH_TAGS', 'nettertech_events_open_graph_tags' ),

			// Calendar.
			'CALENDAR_ENQUEUE_SCRIPTS'  => array( 'CALENDAR_ENQUEUE_SCRIPTS', 'nettertech_events_calendar_enqueue_scripts' ),
			'CALENDAR_RENDER_COMPLETE'  => array( 'CALENDAR_RENDER_COMPLETE', 'nettertech_events_calendar_render_complete' ),
			'CALENDAR_SHORTCODE_ATTS'   => array( 'CALENDAR_SHORTCODE_ATTS', 'nettertech_events_calendar_shortcode_atts' ),
			'CALENDAR_WRAPPER_CLASSES'  => array( 'CALENDAR_WRAPPER_CLASSES', 'nettertech_events_calendar_wrapper_classes' ),
			'CALENDAR_HEADER_HTML'      => array( 'CALENDAR_HEADER_HTML', 'nettertech_events_calendar_header_html' ),
			'CALENDAR_JS_CONFIG'        => array( 'CALENDAR_JS_CONFIG', 'nettertech_events_calendar_js_config' ),

			// Shortcode Display.
			'CAROUSEL_EMPTY_MESSAGE'    => array( 'CAROUSEL_EMPTY_MESSAGE', 'nettertech_events_carousel_empty_message' ),
			'LIST_EMPTY_MESSAGE'        => array( 'LIST_EMPTY_MESSAGE', 'nettertech_events_list_empty_message' ),
			'SEARCH_PLACEHOLDER'        => array( 'SEARCH_PLACEHOLDER', 'nettertech_events_search_placeholder' ),
			'SEARCH_LABEL'              => array( 'SEARCH_LABEL', 'nettertech_events_search_label' ),

			// Single Occurrence.
			'SINGLE_OCCURRENCE_ACTIONS' => array( 'SINGLE_OCCURRENCE_ACTIONS', 'nettertech_events_single_occurrence_actions' ),

			// Settings.
			'SETTINGS_TABS'             => array( 'SETTINGS_TABS', 'nettertech_events_settings_tabs' ),
			'SETTINGS_TAB_RENDER'       => array( 'SETTINGS_TAB_RENDER', 'nettertech_events_settings_tab_render' ),
			'SETTINGS_TAB_SAVE'         => array( 'SETTINGS_TAB_SAVE', 'nettertech_events_settings_tab_save' ),
			'GET_TEMPLATE_PART_RENDERED' => array( 'GET_TEMPLATE_PART_RENDERED', 'nettertech_events_get_template_part_rendered' ),
			'BEFORE_TEMPLATE_LOAD'      => array( 'BEFORE_TEMPLATE_LOAD', 'nettertech_events_before_template_load' ),
			'AFTER_TEMPLATE_LOAD'       => array( 'AFTER_TEMPLATE_LOAD', 'nettertech_events_after_template_load' ),

			// Security & Rate Limiting.
			'CSP_DIRECTIVES'            => array( 'CSP_DIRECTIVES', 'nettertech_events_csp_directives' ),
			'PUBLIC_CSP_DIRECTIVES'     => array( 'PUBLIC_CSP_DIRECTIVES', 'nettertech_events_public_csp_directives' ),
			'RATE_LIMIT_SETTINGS'       => array( 'RATE_LIMIT_SETTINGS', 'nettertech_events_rate_limit_settings' ),
			'RATE_LIMIT_BYPASS'         => array( 'RATE_LIMIT_BYPASS', 'nettertech_events_rate_limit_bypass' ),

			// Frontend Branding.
			'SHOW_FRONTEND_BRANDING'    => array( 'SHOW_FRONTEND_BRANDING', 'nettertech_events_show_frontend_branding' ),

			// Cron.
			'GENERATE_OCCURRENCES_CRON' => array( 'GENERATE_OCCURRENCES_CRON', 'nettertech_events_generate_occurrences' ),
			'SEND_REMINDER_EMAILS_CRON' => array( 'SEND_REMINDER_EMAILS_CRON', 'nettertech_events_send_reminder_emails' ),
			'PURGE_ACTIVITY_LOG_PII_CRON' => array( 'PURGE_ACTIVITY_LOG_PII_CRON', 'nettertech_events_purge_activity_log_pii' ),
			'SWEEP_RESERVATIONS_CRON' => array( 'SWEEP_RESERVATIONS_CRON', 'nettertech_events_sweep_expired_reservations' ),
			'ICAL_STATIC_REGENERATE'  => array( 'ICAL_STATIC_REGENERATE', 'nettertech_events_ical_static_regenerate' ),

			// Capacity Reservations.
			'RESERVATION_CHANGED'     => array( 'RESERVATION_CHANGED', 'nettertech_events_reservation_changed' ),

			// Revisions.
			'MAX_REVISIONS'             => array( 'MAX_REVISIONS', 'nettertech_events_max_revisions' ),

			// REST API Response Filters.
			'REST_EVENTS_UPCOMING_RESPONSE'          => array( 'REST_EVENTS_UPCOMING_RESPONSE', 'nettertech_events_rest_events_upcoming_response' ),
			'REST_EVENTS_RANGE_RESPONSE'             => array( 'REST_EVENTS_RANGE_RESPONSE', 'nettertech_events_rest_events_range_response' ),
			'REST_EVENTS_GET_RESPONSE'               => array( 'REST_EVENTS_GET_RESPONSE', 'nettertech_events_rest_events_get_response' ),
			'REST_OCCURRENCES_LIST_RESPONSE'         => array( 'REST_OCCURRENCES_LIST_RESPONSE', 'nettertech_events_rest_occurrences_list_response' ),
			'REST_ADMIN_EVENTS_LIST_RESPONSE'        => array( 'REST_ADMIN_EVENTS_LIST_RESPONSE', 'nettertech_events_rest_admin_events_list_response' ),
			'REST_ADMIN_EVENTS_GET_RESPONSE'         => array( 'REST_ADMIN_EVENTS_GET_RESPONSE', 'nettertech_events_rest_admin_events_get_response' ),
			'REST_ADMIN_ATTENDEES_LIST_RESPONSE'     => array( 'REST_ADMIN_ATTENDEES_LIST_RESPONSE', 'nettertech_events_rest_admin_attendees_list_response' ),
			'REST_ADMIN_ATTENDEES_GET_RESPONSE'      => array( 'REST_ADMIN_ATTENDEES_GET_RESPONSE', 'nettertech_events_rest_admin_attendees_get_response' ),
			'REST_ADMIN_TICKET_TYPES_LIST_RESPONSE'  => array( 'REST_ADMIN_TICKET_TYPES_LIST_RESPONSE', 'nettertech_events_rest_admin_ticket_types_list_response' ),
			'REST_ADMIN_TICKET_TYPES_GET_RESPONSE'   => array( 'REST_ADMIN_TICKET_TYPES_GET_RESPONSE', 'nettertech_events_rest_admin_ticket_types_get_response' ),

			// Bulk Import.
			'BULK_IMPORT_COMPLETED'   => array( 'BULK_IMPORT_COMPLETED', 'nettertech_events_bulk_import_completed' ),

			// Extension Hooks (Pro Plugin Integration).
			'FILTER_SERVICE_PROVIDERS'   => array( 'FILTER_SERVICE_PROVIDERS', 'nettertech_events_service_providers' ),
			'ACTION_ADMIN_READY'         => array( 'ACTION_ADMIN_READY', 'nettertech_events_admin_ready' ),
			'ACTION_FRONTEND_READY'      => array( 'ACTION_FRONTEND_READY', 'nettertech_events_frontend_ready' ),
			'ACTION_REGISTER_ADMIN_PAGES' => array( 'ACTION_REGISTER_ADMIN_PAGES', 'nettertech_events_register_admin_pages' ),
			'ACTION_REGISTER_ASSETS'     => array( 'ACTION_REGISTER_ASSETS', 'nettertech_events_register_assets' ),
			'FILTER_LIST_COLUMNS'        => array( 'FILTER_LIST_COLUMNS', 'nettertech_events_list_columns' ),
			'ACTION_LIST_COLUMN'         => array( 'ACTION_LIST_COLUMN', 'nettertech_events_list_column' ),
			'AJAX_EVENT_SKUS'            => array( 'AJAX_EVENT_SKUS', 'nettertech_events_event_skus' ),
			'AJAX_ATTENDEE_CHECK_IN'     => array( 'AJAX_ATTENDEE_CHECK_IN', 'nettertech_events_attendee_check_in' ),
			'FILTER_ROUTE_TEMPLATE'      => array( 'FILTER_ROUTE_TEMPLATE', 'nettertech_events_route_template' ),
		);
	}

	/**
	 * Test each hook constant has the expected value.
	 *
	 * @dataProvider constant_value_provider
	 *
	 * @param string $constant_name The constant name on the Hooks class.
	 * @param string $expected      The expected string value.
	 * @return void
	 */
	public function test_constant_value( string $constant_name, string $expected ): void {
		$this->assertTrue(
			defined( Hooks::class . '::' . $constant_name ),
			"Hooks::{$constant_name} should be defined"
		);
		$this->assertSame(
			$expected,
			constant( Hooks::class . '::' . $constant_name ),
			"Hooks::{$constant_name} should equal '{$expected}'"
		);
	}

	// =========================================================================
	// Hook Naming Convention Tests
	// =========================================================================

	/**
	 * Test all hooks use a recognized plugin prefix.
	 *
	 * @return void
	 */
	public function test_all_hooks_have_plugin_prefix(): void {
		$reflection = new \ReflectionClass( Hooks::class );
		$constants  = $reflection->getConstants();

		foreach ( $constants as $name => $value ) {
			$has_prefix = str_starts_with( $value, 'nettertech_events_' )
				|| str_starts_with( $value, 'nettertech_events_' );
			$this->assertTrue(
				$has_prefix,
				"Constant {$name} should start with 'nettertech_events_' or 'nettertech_events_' prefix; got '{$value}'"
			);
		}
	}

	/**
	 * Test hook names contain only lowercase letters and underscores.
	 *
	 * @return void
	 */
	public function test_hooks_follow_naming_convention(): void {
		$reflection = new \ReflectionClass( Hooks::class );
		$constants  = $reflection->getConstants();

		foreach ( $constants as $name => $value ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z_]+$/',
				$value,
				"Constant {$name} value should contain only lowercase letters and underscores"
			);
		}
	}

	// =========================================================================
	// Hook Usage Verification Tests
	// =========================================================================

	/**
	 * Test each hook constant is referenced in the production code.
	 *
	 * Scans includes/ for Hooks::CONSTANT_NAME or the string literal value
	 * in do_action/apply_filters/add_action/add_filter calls. This catches
	 * dead hook constants that exist but are never fired or listened to.
	 *
	 * @dataProvider constant_value_provider
	 *
	 * @param string $constant_name The constant name on the Hooks class.
	 * @param string $expected      The expected string value.
	 * @return void
	 */
	public function test_hook_constant_is_used_in_codebase( string $constant_name, string $expected ): void {
		// These hooks are part of the documented extension API (docs/HOOKS.md,
		// docs/TEMPLATE-OVERRIDE.md) but not yet wired into production code.
		// They exist for third-party/pro plugin consumption.
		$deferred_api = array( 'TEMPLATE_ARGS', 'GET_TEMPLATE_PART', 'TEMPLATE_PATHS', 'SHOW_TICKET_UPGRADE_NOTICE', 'SHOW_FRONTEND_UPGRADE_HINT', 'TICKET_TYPE_SAVED', 'CHECKIN_LOOKUP_DATA', 'CHECKIN_SEARCH_ITEM' );
		if ( in_array( $constant_name, $deferred_api, true ) ) {
			$this->markTestSkipped( "Hooks::{$constant_name} is a documented extension point not yet wired in core." );
		}

		$plugin_dir = dirname( __DIR__, 3 );
		$scan_dirs  = array( $plugin_dir . '/includes', $plugin_dir . '/templates', $plugin_dir . '/nettertech-events.php' );

		$constant_ref = 'Hooks::' . $constant_name;
		$literal_ref  = "'" . $expected . "'";
		$found        = false;

		foreach ( $scan_dirs as $path ) {
			if ( is_file( $path ) ) {
				$contents = file_get_contents( $path );
				if ( str_contains( $contents, $constant_ref ) || str_contains( $contents, $literal_ref ) ) {
					$found = true;
					break;
				}
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $path, \FilesystemIterator::SKIP_DOTS )
			);

			foreach ( $iterator as $file ) {
				if ( 'php' !== $file->getExtension() ) {
					continue;
				}
				// Skip the Hooks class itself — we're looking for usage, not declaration.
				if ( str_ends_with( $file->getPathname(), 'Core/Hooks.php' ) ) {
					continue;
				}
				$contents = file_get_contents( $file->getPathname() );
				if ( str_contains( $contents, $constant_ref ) || str_contains( $contents, $literal_ref ) ) {
					$found = true;
					break 2;
				}
			}
		}

		$this->assertTrue(
			$found,
			"Hooks::{$constant_name} ('{$expected}') is not referenced via constant or string literal in production code"
		);
	}

	// =========================================================================
	// Constant Count Test
	// =========================================================================

	/**
	 * Test expected number of hook constants exist.
	 *
	 * @return void
	 */
	public function test_expected_number_of_constants(): void {
		$reflection = new \ReflectionClass( Hooks::class );
		$constants  = $reflection->getConstants();

		$this->assertGreaterThanOrEqual( 90, count( $constants ) );
	}

	/**
	 * Test data provider covers all declared constants.
	 *
	 * Guards against adding a constant to Hooks without a corresponding
	 * data-provider entry in this test class.
	 *
	 * @return void
	 */
	public function test_provider_covers_all_constants(): void {
		$reflection        = new \ReflectionClass( Hooks::class );
		$declared          = array_keys( $reflection->getConstants() );
		$provider_entries  = self::constant_value_provider();
		$tested_constants  = array_column( $provider_entries, 0 );

		$missing = array_diff( $declared, $tested_constants );

		$this->assertEmpty(
			$missing,
			'Data provider is missing coverage for: ' . implode( ', ', $missing )
		);
	}
}
