<?php
/**
 * MetaKeys unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Core;

use NetterTechEvents\Core\MetaKeys;

/**
 * Test MetaKeys class functionality.
 */
class MetaKeysTest extends \NetterTechEventsTestCase {

	// =========================================================================
	// Class Structure Tests
	// =========================================================================

	/**
	 * Test class exists.
	 *
	 * @return void
	 */
	public function test_class_exists(): void {
		$this->assertTrue( class_exists( MetaKeys::class ) );
	}

	/**
	 * Test class is final.
	 *
	 * @return void
	 */
	public function test_class_is_final(): void {
		$reflection = new \ReflectionClass( MetaKeys::class );
		$this->assertTrue( $reflection->isFinal() );
	}

	// =========================================================================
	// Event Meta Constant Tests
	// =========================================================================

	/**
	 * Test RECURRENCE_RULE constant value.
	 *
	 * @return void
	 */
	public function test_recurrence_rule_constant(): void {
		$this->assertSame( '_nettertech_events_recurrence_rule', MetaKeys::RECURRENCE_RULE );
	}

	/**
	 * Test CAPACITY constant value.
	 *
	 * @return void
	 */
	public function test_capacity_constant(): void {
		$this->assertSame( '_nettertech_events_capacity', MetaKeys::CAPACITY );
	}

	// =========================================================================
	// WooCommerce Product Meta Constant Tests
	// =========================================================================

	/**
	 * Test TICKET_TYPE_ID constant value.
	 *
	 * @return void
	 */
	public function test_ticket_type_id_constant(): void {
		$this->assertSame( '_nettertech_events_ticket_type_id', MetaKeys::TICKET_TYPE_ID );
	}

	/**
	 * Test OCCURRENCE_ID constant value.
	 *
	 * @return void
	 */
	public function test_occurrence_id_constant(): void {
		$this->assertSame( '_nettertech_events_occurrence_id', MetaKeys::OCCURRENCE_ID );
	}

	/**
	 * Test EVENT_ID constant value.
	 *
	 * @return void
	 */
	public function test_event_id_constant(): void {
		$this->assertSame( '_nettertech_events_event_id', MetaKeys::EVENT_ID );
	}

	/**
	 * Test IS_EVENT_TICKET constant value.
	 *
	 * @return void
	 */
	public function test_is_event_ticket_constant(): void {
		$this->assertSame( '_nettertech_events_is_event_ticket', MetaKeys::IS_EVENT_TICKET );
	}

	/**
	 * Test IS_SERIES_PASS constant value.
	 *
	 * @return void
	 */
	public function test_is_series_pass_constant(): void {
		$this->assertSame( '_nettertech_events_is_series_pass', MetaKeys::IS_SERIES_PASS );
	}

	// =========================================================================
	// WooCommerce Order Meta Constant Tests
	// =========================================================================

	/**
	 * Test ATTENDEES_CREATED constant value.
	 *
	 * @return void
	 */
	public function test_attendees_created_constant(): void {
		$this->assertSame( '_nettertech_events_attendees_created', MetaKeys::ATTENDEES_CREATED );
	}

	/**
	 * Test ACCESSIBILITY_NOTES constant value.
	 *
	 * @return void
	 */
	public function test_accessibility_notes_constant(): void {
		$this->assertSame( '_nettertech_events_accessibility_notes', MetaKeys::ACCESSIBILITY_NOTES );
	}

	/**
	 * Test CONFIRMATION_EMAIL_SENT constant value.
	 *
	 * @return void
	 */
	public function test_confirmation_email_sent_constant(): void {
		$this->assertSame( '_nettertech_events_confirmation_email_sent', MetaKeys::CONFIRMATION_EMAIL_SENT );
	}

	/**
	 * Test CONFIRMATION_EMAIL_SENT_AT constant value.
	 *
	 * @return void
	 */
	public function test_confirmation_email_sent_at_constant(): void {
		$this->assertSame( '_nettertech_events_confirmation_email_sent_at', MetaKeys::CONFIRMATION_EMAIL_SENT_AT );
	}

	/**
	 * Test CONFIRMATION_EMAIL_RESENT_AT constant value.
	 *
	 * @return void
	 */
	public function test_confirmation_email_resent_at_constant(): void {
		$this->assertSame( '_nettertech_events_confirmation_email_resent_at', MetaKeys::CONFIRMATION_EMAIL_RESENT_AT );
	}

	/**
	 * Test PROCESSED_REFUND_PREFIX constant value.
	 *
	 * @return void
	 */
	public function test_processed_refund_prefix_constant(): void {
		$this->assertSame( '_nettertech_events_refund_processed_', MetaKeys::PROCESSED_REFUND_PREFIX );
	}

	// =========================================================================
	// WooCommerce Order Item Meta Alias Tests
	// =========================================================================

	/**
	 * Test WC_ORDER_ITEM_TICKET_TYPE_ID is alias of TICKET_TYPE_ID.
	 *
	 * @return void
	 */
	public function test_wc_order_item_ticket_type_id_alias(): void {
		$this->assertSame( MetaKeys::TICKET_TYPE_ID, MetaKeys::WC_ORDER_ITEM_TICKET_TYPE_ID );
	}

	/**
	 * Test WC_ORDER_ITEM_OCCURRENCE_ID is alias of OCCURRENCE_ID.
	 *
	 * @return void
	 */
	public function test_wc_order_item_occurrence_id_alias(): void {
		$this->assertSame( MetaKeys::OCCURRENCE_ID, MetaKeys::WC_ORDER_ITEM_OCCURRENCE_ID );
	}

	/**
	 * Test WC_ORDER_ITEM_EVENT_ID is alias of EVENT_ID.
	 *
	 * @return void
	 */
	public function test_wc_order_item_event_id_alias(): void {
		$this->assertSame( MetaKeys::EVENT_ID, MetaKeys::WC_ORDER_ITEM_EVENT_ID );
	}

	/**
	 * Test WC_ORDER_ITEM_IS_SERIES_PASS is alias of IS_SERIES_PASS.
	 *
	 * @return void
	 */
	public function test_wc_order_item_is_series_pass_alias(): void {
		$this->assertSame( MetaKeys::IS_SERIES_PASS, MetaKeys::WC_ORDER_ITEM_IS_SERIES_PASS );
	}

	// =========================================================================
	// Meta Key Naming Convention Tests
	// =========================================================================

	/**
	 * Test all meta keys start with _nte_ prefix.
	 *
	 * @return void
	 */
	public function test_all_meta_keys_have_nte_prefix(): void {
		$reflection = new \ReflectionClass( MetaKeys::class );
		$constants  = $reflection->getConstants();

		foreach ( $constants as $name => $value ) {
			// Skip alias constants (they reference other constants).
			if ( str_starts_with( $name, 'WC_ORDER_ITEM_' ) ) {
				continue;
			}

			$this->assertStringStartsWith(
				'_nettertech_events_',
				$value,
				"Constant {$name} should start with '_nettertech_events_' prefix"
			);
		}
	}

	/**
	 * Test meta keys contain only lowercase letters and underscores.
	 *
	 * @return void
	 */
	public function test_meta_keys_follow_naming_convention(): void {
		$reflection = new \ReflectionClass( MetaKeys::class );
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
	// Constant Count Tests
	// =========================================================================

	/**
	 * Test expected number of constants exist.
	 *
	 * @return void
	 */
	public function test_expected_number_of_constants(): void {
		$reflection = new \ReflectionClass( MetaKeys::class );
		$constants  = $reflection->getConstants();

		// Should have at least 15 constants (including aliases).
		$this->assertGreaterThanOrEqual( 15, count( $constants ) );
	}
}
