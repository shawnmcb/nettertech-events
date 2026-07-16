<?php
/**
 * DonationHandler unit tests.
 *
 * @package NetterTechEvents\Tests\Unit\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Integrations\WooCommerce;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Integrations\WooCommerce\CartHandler;
use NetterTechEvents\Integrations\WooCommerce\DonationHandler;

/**
 * Test DonationHandler business logic.
 */
class DonationHandlerTest extends \NetterTechEventsTestCase {

	/**
	 * Cart handler mock.
	 *
	 * @var CartHandler&\Mockery\MockInterface
	 */
	private $cart_handler;

	/**
	 * Rate limit service mock.
	 *
	 * @var \NetterTechEvents\Services\RateLimitService&\Mockery\MockInterface
	 */
	private $rate_limit_mock;

	/**
	 * System under test.
	 *
	 * @var DonationHandler
	 */
	private DonationHandler $sut;

	/**
	 * Set up test fixtures.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->cart_handler     = Mockery::mock( CartHandler::class );
		$this->rate_limit_mock = Mockery::mock( \NetterTechEvents\Services\RateLimitService::class );
		$this->rate_limit_mock->shouldReceive( 'should_bypass' )->andReturn( true )->byDefault();
		$this->sut             = new DonationHandler( $this->cart_handler, $this->rate_limit_mock );

		Functions\when( '__' )->returnArg();
	}

	// =========================================================================
	// get_settings()
	// =========================================================================

	/**
	 * Test get_settings returns defaults when no option exists.
	 *
	 * @return void
	 */
	public function test_get_settings_returns_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$settings = $this->sut->get_settings();

		$this->assertFalse( $settings['enabled'] );
		$this->assertSame( 'dollar', $settings['roundup_to'] );
		$this->assertTrue( $settings['allow_custom'] );
		$this->assertSame( 100, $settings['max_donation'] );
	}

	/**
	 * Test get_settings reads from option.
	 *
	 * @return void
	 */
	public function test_get_settings_reads_option(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations'      => true,
			'donation_cause'        => 'Help our venue',
			'roundup_to'            => 'five',
			'donation_presets'      => array( 10, 20, 50 ),
			'allow_custom_donation' => false,
			'max_donation'          => 500,
		) );

		$settings = $this->sut->get_settings();

		$this->assertTrue( $settings['enabled'] );
		$this->assertSame( 'Help our venue', $settings['cause'] );
		$this->assertSame( 'five', $settings['roundup_to'] );
		$this->assertSame( array( 10, 20, 50 ), $settings['presets'] );
		$this->assertFalse( $settings['allow_custom'] );
		$this->assertSame( 500, $settings['max_donation'] );
	}

	// =========================================================================
	// is_enabled()
	// =========================================================================

	/**
	 * Test is_enabled returns true when donations enabled and cart has tickets.
	 *
	 * @return void
	 */
	public function test_is_enabled_true(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
		) );

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->once()
			->andReturn( true );

		$this->assertTrue( $this->sut->is_enabled() );
	}

	/**
	 * Test is_enabled returns false when donations disabled.
	 *
	 * @return void
	 */
	public function test_is_enabled_false_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => false,
		) );

		$this->assertFalse( $this->sut->is_enabled() );
	}

	/**
	 * Test is_enabled returns false when cart has no tickets.
	 *
	 * @return void
	 */
	public function test_is_enabled_false_when_no_tickets_in_cart(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
		) );

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->once()
			->andReturn( false );

		$this->assertFalse( $this->sut->is_enabled() );
	}

	// =========================================================================
	// get_roundup_amount() — Dollar Rounding
	// =========================================================================

	/**
	 * Test dollar rounding with fractional total.
	 *
	 * @return void
	 */
	public function test_roundup_dollar_fractional(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'dollar' ) );

		// $17.50 → round up to $18 → donation = $0.50.
		$amount = $this->sut->get_roundup_amount( 17.50 );

		$this->assertSame( 0.50, $amount );
	}

	/**
	 * Test dollar rounding at exact dollar returns $1.
	 *
	 * @return void
	 */
	public function test_roundup_dollar_exact_returns_one(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'dollar' ) );

		// $20.00 → ceil = $20 → diff = $0 < $0.01 → return $1.00.
		$amount = $this->sut->get_roundup_amount( 20.00 );

		$this->assertSame( 1.00, $amount );
	}

	/**
	 * Test dollar rounding with one cent below.
	 *
	 * @return void
	 */
	public function test_roundup_dollar_one_cent_below(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'dollar' ) );

		// $19.99 → ceil = $20 → donation = $0.01.
		$amount = $this->sut->get_roundup_amount( 19.99 );

		$this->assertSame( 0.01, $amount );
	}

	// =========================================================================
	// get_roundup_amount() — Five Dollar Rounding
	// =========================================================================

	/**
	 * Test five-dollar rounding with fractional total.
	 *
	 * @return void
	 */
	public function test_roundup_five_fractional(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'five' ) );

		// $17.50 → ceil(17.50/5)*5 = $20 → donation = $2.50.
		$amount = $this->sut->get_roundup_amount( 17.50 );

		$this->assertSame( 2.50, $amount );
	}

	/**
	 * Test five-dollar rounding at exact five returns $5.
	 *
	 * @return void
	 */
	public function test_roundup_five_exact_returns_five(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'five' ) );

		// $20.00 → ceil(20/5)*5 = $20 → diff = $0 → return $5.00.
		$amount = $this->sut->get_roundup_amount( 20.00 );

		$this->assertSame( 5.00, $amount );
	}

	/**
	 * Test five-dollar rounding with mid-range total.
	 *
	 * @return void
	 */
	public function test_roundup_five_mid_range(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'five' ) );

		// $22.00 → ceil(22/5)*5 = $25 → donation = $3.00.
		$amount = $this->sut->get_roundup_amount( 22.00 );

		$this->assertSame( 3.00, $amount );
	}

	// =========================================================================
	// get_roundup_amount() — Ten Dollar Rounding
	// =========================================================================

	/**
	 * Test ten-dollar rounding with fractional total.
	 *
	 * @return void
	 */
	public function test_roundup_ten_fractional(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'ten' ) );

		// $17.50 → ceil(17.50/10)*10 = $20 → donation = $2.50.
		$amount = $this->sut->get_roundup_amount( 17.50 );

		$this->assertSame( 2.50, $amount );
	}

	/**
	 * Test ten-dollar rounding at exact ten returns $10.
	 *
	 * @return void
	 */
	public function test_roundup_ten_exact_returns_ten(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'ten' ) );

		// $30.00 → ceil(30/10)*10 = $30 → diff = $0 → return $10.00.
		$amount = $this->sut->get_roundup_amount( 30.00 );

		$this->assertSame( 10.00, $amount );
	}

	/**
	 * Test ten-dollar rounding with large total.
	 *
	 * @return void
	 */
	public function test_roundup_ten_large_total(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'ten' ) );

		// $123.45 → ceil(123.45/10)*10 = $130 → donation = $6.55.
		$amount = $this->sut->get_roundup_amount( 123.45 );

		$this->assertSame( 6.55, $amount );
	}

	// =========================================================================
	// get_roundup_amount() — Edge Cases
	// =========================================================================

	/**
	 * Test rounding with very small total.
	 *
	 * @return void
	 */
	public function test_roundup_small_total(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'dollar' ) );

		// $0.01 → ceil = $1 → donation = $0.99.
		$amount = $this->sut->get_roundup_amount( 0.01 );

		$this->assertSame( 0.99, $amount );
	}

	/**
	 * Test rounding with zero total returns increment.
	 *
	 * @return void
	 */
	public function test_roundup_zero_total(): void {
		Functions\when( 'get_option' )->justReturn( array( 'roundup_to' => 'dollar' ) );

		// $0.00 → ceil = $0 → diff = $0 → return $1.00.
		$amount = $this->sut->get_roundup_amount( 0.00 );

		$this->assertSame( 1.00, $amount );
	}

	// =========================================================================
	// set_session_donation() — Amount Capping
	// =========================================================================

	/**
	 * Test session donation is capped at max.
	 *
	 * @return void
	 */
	public function test_set_session_donation_caps_at_max(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'max_donation' => 100,
		) );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'set' )
			->with( 'nettertech_events_donation_amount', 100.0 )
			->once();
		$session->shouldReceive( 'set' )
			->with( 'nettertech_events_donation_type', 'custom' )
			->once();

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		$this->sut->set_session_donation( 500.0, 'custom' );
	}

	/**
	 * Test session donation negative clamped to zero.
	 *
	 * @return void
	 */
	public function test_set_session_donation_clamps_negative(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'max_donation' => 100,
		) );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'set' )
			->with( 'nettertech_events_donation_amount', 0.0 )
			->once();
		$session->shouldReceive( 'set' )
			->with( 'nettertech_events_donation_type', 'custom' )
			->once();

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		$this->sut->set_session_donation( -10.0, 'custom' );
	}

	/**
	 * Test set_session_donation with no session is a no-op.
	 *
	 * @return void
	 */
	public function test_set_session_donation_no_session(): void {
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );

		// Should not throw.
		$this->sut->set_session_donation( 10.0, 'fixed' );
		$this->assertTrue( true );
	}

	// =========================================================================
	// get_session_donation() / get_session_donation_type()
	// =========================================================================

	/**
	 * Test get_session_donation returns amount from session.
	 *
	 * @return void
	 */
	public function test_get_session_donation_returns_amount(): void {
		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->once()
			->andReturn( 25.0 );

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		$this->assertSame( 25.0, $this->sut->get_session_donation() );
	}

	/**
	 * Test get_session_donation returns zero when no session.
	 *
	 * @return void
	 */
	public function test_get_session_donation_no_session_returns_zero(): void {
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );

		$this->assertSame( 0.0, $this->sut->get_session_donation() );
	}

	/**
	 * Test get_session_donation_type returns type from session.
	 *
	 * @return void
	 */
	public function test_get_session_donation_type_returns_type(): void {
		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->once()
			->andReturn( 'roundup' );

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		$this->assertSame( 'roundup', $this->sut->get_session_donation_type() );
	}

	/**
	 * Test get_session_donation_type returns empty when no session.
	 *
	 * @return void
	 */
	public function test_get_session_donation_type_no_session(): void {
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );

		$this->assertSame( '', $this->sut->get_session_donation_type() );
	}

	// =========================================================================
	// clear_session_donation()
	// =========================================================================

	/**
	 * Test clear_session_donation resets session values.
	 *
	 * @return void
	 */
	public function test_clear_session_donation(): void {
		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'set' )
			->with( 'nettertech_events_donation_amount', 0 )
			->once();
		$session->shouldReceive( 'set' )
			->with( 'nettertech_events_donation_type', '' )
			->once();

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		$this->sut->clear_session_donation();
	}

	/**
	 * Test clear_session_donation with no session is a no-op.
	 *
	 * @return void
	 */
	public function test_clear_session_donation_no_session(): void {
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );

		$this->sut->clear_session_donation();
		$this->assertTrue( true );
	}

	// =========================================================================
	// calculate_donation_fee()
	// =========================================================================

	/**
	 * Test calculate_donation_fee adds fee when donation exists.
	 *
	 * @return void
	 */
	public function test_calculate_donation_fee_adds_fee(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
		) );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->andReturn( true );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 15.0 );

		$cart = Mockery::mock( \WC_Cart::class );
		$cart->shouldReceive( 'add_fee' )
			->with( 'Donation', 15.0, false )
			->once();

		Functions\when( 'WC' )->justReturn( (object) array(
			'session' => $session,
			'cart'    => $cart,
		) );

		$this->sut->calculate_donation_fee();
	}

	/**
	 * Test calculate_donation_fee skips when amount is zero.
	 *
	 * @return void
	 */
	public function test_calculate_donation_fee_skips_zero_amount(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
		) );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->andReturn( true );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 0.0 );

		$cart = Mockery::mock( \WC_Cart::class );
		$cart->shouldNotReceive( 'add_fee' );

		Functions\when( 'WC' )->justReturn( (object) array(
			'session' => $session,
			'cart'    => $cart,
		) );

		$this->sut->calculate_donation_fee();
	}

	/**
	 * Test calculate_donation_fee returns early in admin context.
	 *
	 * @return void
	 */
	public function test_calculate_donation_fee_skips_in_admin(): void {
		Functions\when( 'is_admin' )->justReturn( true );

		// Should not reach is_enabled, so no cart_handler call expected.
		$this->sut->calculate_donation_fee();
		$this->assertTrue( true );
	}

	/**
	 * Test calculate_donation_fee returns early when disabled.
	 *
	 * @return void
	 */
	public function test_calculate_donation_fee_skips_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => false,
		) );
		Functions\when( 'is_admin' )->justReturn( false );

		$this->sut->calculate_donation_fee();
		$this->assertTrue( true );
	}

	// =========================================================================
	// save_donation_meta()
	// =========================================================================

	/**
	 * Test save_donation_meta stores meta on order.
	 *
	 * @return void
	 */
	public function test_save_donation_meta_stores_on_order(): void {
		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 25.0 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( 'fixed' );
		$session->shouldReceive( 'set' )->twice(); // clear_session_donation.

		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'update_meta_data' )
			->with( '_nettertech_events_donation_amount', 25.0 )
			->once();
		$order->shouldReceive( 'update_meta_data' )
			->with( '_nettertech_events_donation_type', 'fixed' )
			->once();
		$order->shouldReceive( 'save' )->once();

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );
		Functions\when( 'wc_get_order' )->justReturn( $order );

		$this->sut->save_donation_meta( 123 );
	}

	/**
	 * Test save_donation_meta skips when no donation.
	 *
	 * @return void
	 */
	public function test_save_donation_meta_skips_when_no_donation(): void {
		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 0.0 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( '' );
		$session->shouldReceive( 'set' )->twice(); // clear_session_donation.

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );
		Functions\when( 'wc_get_order' )->justReturn( null );

		// Should not call order methods — just clear session.
		$this->sut->save_donation_meta( 123 );
		$this->assertTrue( true );
	}

	/**
	 * Test save_donation_meta handles null order gracefully.
	 *
	 * @return void
	 */
	public function test_save_donation_meta_null_order(): void {
		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 10.0 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( 'roundup' );
		$session->shouldReceive( 'set' )->twice();

		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );
		Functions\when( 'wc_get_order' )->justReturn( null );

		// Should not throw even when wc_get_order returns null.
		$this->sut->save_donation_meta( 999 );
		$this->assertTrue( true );
	}

	// =========================================================================
	// handle_ajax_update()
	// =========================================================================

	/**
	 * Test handle_ajax_update with roundup option.
	 *
	 * @return void
	 */
	public function test_handle_ajax_update_roundup(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
			'roundup_to'       => 'dollar',
			'max_donation'     => 100,
		) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->andReturn( true );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'set' )->twice();
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 0.50 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( 'roundup' );

		$cart = Mockery::mock( \WC_Cart::class );
		$cart->shouldReceive( 'get_subtotal' )->andReturn( 17.50 );

		Functions\when( 'WC' )->justReturn( (object) array(
			'session' => $session,
			'cart'    => $cart,
		) );

		$_POST['option'] = 'roundup';
		$_POST['nonce']  = 'test';

		$json_sent = null;
		Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$json_sent ) {
			$json_sent = $data;
		} );

		$this->sut->handle_ajax_update();

		$this->assertNotNull( $json_sent );
		$this->assertArrayHasKey( 'amount', $json_sent );
		$this->assertArrayHasKey( 'type', $json_sent );

		unset( $_POST['option'], $_POST['nonce'] );
	}

	/**
	 * Test handle_ajax_update with fixed option.
	 *
	 * @return void
	 */
	public function test_handle_ajax_update_fixed(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
			'max_donation'     => 100,
		) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->andReturn( true );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'set' )->twice();
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 10.0 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( 'fixed' );

		Functions\when( 'WC' )->justReturn( (object) array(
			'session' => $session,
			'cart'    => Mockery::mock( \WC_Cart::class ),
		) );

		$_POST['option'] = 'fixed';
		$_POST['amount'] = '10';
		$_POST['nonce']  = 'test';

		$json_sent = null;
		Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$json_sent ) {
			$json_sent = $data;
		} );

		$this->sut->handle_ajax_update();

		$this->assertNotNull( $json_sent );
		$this->assertSame( 10.0, $json_sent['amount'] );

		unset( $_POST['option'], $_POST['amount'], $_POST['nonce'] );
	}

	/**
	 * Test handle_ajax_update with custom option.
	 *
	 * @return void
	 */
	public function test_handle_ajax_update_custom(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
			'max_donation'     => 100,
		) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->andReturn( true );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'set' )->twice();
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 42.0 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( 'custom' );

		Functions\when( 'WC' )->justReturn( (object) array(
			'session' => $session,
			'cart'    => Mockery::mock( \WC_Cart::class ),
		) );

		$_POST['option'] = 'custom';
		$_POST['amount'] = '42';
		$_POST['nonce']  = 'test';

		$json_sent = null;
		Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$json_sent ) {
			$json_sent = $data;
		} );

		$this->sut->handle_ajax_update();

		$this->assertNotNull( $json_sent );
		$this->assertSame( 42.0, $json_sent['amount'] );

		unset( $_POST['option'], $_POST['amount'], $_POST['nonce'] );
	}

	/**
	 * Test handle_ajax_update with none option clears donation.
	 *
	 * @return void
	 */
	public function test_handle_ajax_update_none(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => true,
		) );
		Functions\when( 'check_ajax_referer' )->justReturn( true );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->andReturn( true );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'set' ); // clear_session_donation calls set() twice.
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 0.0 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( '' );

		Functions\when( 'WC' )->justReturn( (object) array(
			'session' => $session,
			'cart'    => Mockery::mock( \WC_Cart::class ),
		) );

		$_POST['option'] = 'none';
		$_POST['nonce']  = 'test';

		$json_sent = null;
		Functions\when( 'wp_send_json_success' )->alias( function ( $data ) use ( &$json_sent ) {
			$json_sent = $data;
		} );

		$this->sut->handle_ajax_update();

		$this->assertNotNull( $json_sent );
		$this->assertSame( 0.0, $json_sent['amount'] );

		unset( $_POST['option'], $_POST['nonce'] );
	}

	/**
	 * Test handle_ajax_update returns error when disabled.
	 *
	 * @return void
	 */
	public function test_handle_ajax_update_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => false,
		) );

		$error_sent = null;
		Functions\when( 'wp_send_json_error' )->alias( function ( $data, $code ) use ( &$error_sent ) {
			$error_sent = array( 'data' => $data, 'code' => $code );
			// wp_send_json_error normally dies, simulate with exception.
			throw new \RuntimeException( 'wp_send_json_error called' );
		} );

		try {
			$this->sut->handle_ajax_update();
			$this->fail( 'Expected RuntimeException from wp_send_json_error' );
		} catch ( \RuntimeException $e ) {
			$this->assertSame( 'wp_send_json_error called', $e->getMessage() );
		}

		$this->assertNotNull( $error_sent );
		$this->assertSame( 403, $error_sent['code'] );
	}

	// =========================================================================
	// display_donation_admin()
	// =========================================================================

	/**
	 * Test display_donation_admin outputs donation info.
	 *
	 * @return void
	 */
	public function test_display_donation_admin_outputs_info(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_amount', true )
			->andReturn( 25.0 );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_type', true )
			->andReturn( 'fixed' );

		Functions\when( 'wc_price' )->alias( function ( $price ) {
			return '$' . number_format( (float) $price, 2 );
		} );

		ob_start();
		$this->sut->display_donation_admin( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-donation-admin', $output );
		$this->assertStringContainsString( 'Donation', $output );
		$this->assertStringContainsString( '$25.00', $output );
		$this->assertStringContainsString( 'Preset', $output );
	}

	/**
	 * Test display_donation_admin returns early when no donation.
	 *
	 * @return void
	 */
	public function test_display_donation_admin_no_donation(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_amount', true )
			->andReturn( '' );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_type', true )
			->andReturn( '' );

		ob_start();
		$this->sut->display_donation_admin( $order );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test display_donation_admin shows roundup type label.
	 *
	 * @return void
	 */
	public function test_display_donation_admin_roundup_type(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_amount', true )
			->andReturn( 0.50 );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_type', true )
			->andReturn( 'roundup' );

		Functions\when( 'wc_price' )->alias( function ( $price ) {
			return '$' . number_format( (float) $price, 2 );
		} );

		ob_start();
		$this->sut->display_donation_admin( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Round-up', $output );
	}

	/**
	 * Test display_donation_admin shows custom type label.
	 *
	 * @return void
	 */
	public function test_display_donation_admin_custom_type(): void {
		$order = Mockery::mock( \WC_Order::class );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_amount', true )
			->andReturn( 42.0 );
		$order->shouldReceive( 'get_meta' )
			->with( '_nettertech_events_donation_type', true )
			->andReturn( 'custom' );

		Functions\when( 'wc_price' )->alias( function ( $price ) {
			return '$' . number_format( (float) $price, 2 );
		} );

		ob_start();
		$this->sut->display_donation_admin( $order );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'Custom', $output );
	}

	// =========================================================================
	// render_donation_field()
	// =========================================================================

	/**
	 * Test render_donation_field returns early when disabled.
	 *
	 * @return void
	 */
	public function test_render_donation_field_returns_early_when_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations' => false,
		) );

		ob_start();
		$this->sut->render_donation_field( Mockery::mock( \WC_Checkout::class ) );
		$output = ob_get_clean();

		$this->assertEmpty( $output );
	}

	/**
	 * Test render_donation_field outputs HTML when enabled.
	 *
	 * @return void
	 */
	public function test_render_donation_field_outputs_html(): void {
		Functions\when( 'get_option' )->justReturn( array(
			'enable_donations'      => true,
			'donation_cause'        => 'Support our venue',
			'roundup_to'            => 'dollar',
			'donation_presets'      => array( 5, 10 ),
			'allow_custom_donation' => true,
			'max_donation'          => 100,
		) );
		Functions\when( 'wc_price' )->alias( function ( $price ) {
			return '$' . number_format( (float) $price, 2 );
		} );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		Functions\when( 'checked' )->alias( function ( $checked, $current = true, $echo = true ) {
			$result = ( $checked === $current ) ? ' checked="checked"' : '';
			if ( $echo ) {
				echo $result; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
			return $result;
		} );

		$this->cart_handler
			->shouldReceive( 'cart_has_tickets' )
			->andReturn( true );

		$session = Mockery::mock( \WC_Session::class );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_amount', 0 )
			->andReturn( 0.0 );
		$session->shouldReceive( 'get' )
			->with( 'nettertech_events_donation_type', '' )
			->andReturn( '' );

		$cart = Mockery::mock( \WC_Cart::class );
		$cart->shouldReceive( 'get_subtotal' )->andReturn( 17.50 );

		Functions\when( 'WC' )->justReturn( (object) array(
			'session' => $session,
			'cart'    => $cart,
		) );

		ob_start();
		$this->sut->render_donation_field( Mockery::mock( \WC_Checkout::class ) );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-donation-field', $output );
		$this->assertStringContainsString( 'Add a Donation', $output );
		$this->assertStringContainsString( 'Support our venue', $output );
		$this->assertStringContainsString( 'roundup', $output );
		$this->assertStringContainsString( 'No thanks', $output );
	}
}
