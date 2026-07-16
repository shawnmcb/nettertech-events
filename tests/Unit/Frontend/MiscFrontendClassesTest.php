<?php
/**
 * Coverage-targeted tests for small frontend utility classes.
 *
 * Batches several previously-untested classes into one suite where each
 * class needs only a handful of behavior tests.
 *
 * Classes covered:
 * - ICalButton
 * - TemplateCompat
 *
 * @package NetterTechEvents\Tests\Unit\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Unit\Frontend;

use Brain\Monkey\Functions;
use Mockery;
use NetterTechEvents\Contracts\CalendarLinkServiceInterface;
use NetterTechEvents\Frontend\ICalButton;
use NetterTechEvents\Frontend\TemplateCompat;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Tests for small frontend utility classes.
 */
class MiscFrontendClassesTest extends \NetterTechEventsTestCase {

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html_e' )->echoArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'add_action' )->justReturn( true );
	}

	// =========================================================================
	// ICalButton
	// =========================================================================

	/**
	 * Test ICalButton::init does not throw.
	 *
	 * @return void
	 */
	public function test_ical_button_init(): void {
		$service = Mockery::mock( CalendarLinkServiceInterface::class );

		$button = new ICalButton( $service );
		$button->init();

		$this->assertInstanceOf( ICalButton::class, $button );
	}

	/**
	 * Test ICalButton::render skips past occurrences.
	 *
	 * @return void
	 */
	public function test_ical_button_render_skips_past_occurrence(): void {
		$service = Mockery::mock( CalendarLinkServiceInterface::class );
		$service->shouldNotReceive( 'all_links' );

		$event = new Event();
		$event->id    = 1;
		$event->title = 'Test';

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( true );

		$button = new ICalButton( $service );

		ob_start();
		$button->render( $occurrence, $event );
		$output = ob_get_clean();

		$this->assertSame( '', $output );
	}

	/**
	 * Test ICalButton::render outputs dropdown for upcoming occurrence.
	 *
	 * @return void
	 */
	public function test_ical_button_render_outputs_dropdown_for_upcoming(): void {
		$service = Mockery::mock( CalendarLinkServiceInterface::class );
		$service->shouldReceive( 'all_links' )->andReturn(
			array(
				'google' => array(
					'url'   => 'http://google',
					'label' => 'Google',
				),
				'ical'   => array(
					'url'   => 'http://ical',
					'label' => 'iCal',
				),
			)
		);

		$event        = new Event();
		$event->id    = 1;
		$event->title = 'X';

		$occurrence = Mockery::mock( Occurrence::class );
		$occurrence->shouldReceive( 'has_ended' )->andReturn( false );

		$button = new ICalButton( $service );

		ob_start();
		$button->render( $occurrence, $event );
		$output = ob_get_clean();

		$this->assertStringContainsString( 'nte-add-to-calendar', $output );
		$this->assertStringContainsString( 'http://google', $output );
		$this->assertStringContainsString( 'http://ical', $output );
		// iCal link should carry download attr; google should carry target="_blank".
		$this->assertStringContainsString( 'download', $output );
		$this->assertStringContainsString( 'target="_blank"', $output );
	}

	// =========================================================================
	// TemplateCompat
	// =========================================================================

	/**
	 * Test TemplateCompat::header on a classic theme delegates to get_header.
	 *
	 * @return void
	 */
	public function test_template_compat_header_classic_theme(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );

		$called = false;
		Functions\when( 'get_header' )->alias(
			static function () use ( &$called ) {
				$called = true;
			}
		);

		TemplateCompat::header();
		$this->assertTrue( $called );
	}

	/**
	 * Test TemplateCompat::footer on a classic theme delegates to get_footer.
	 *
	 * @return void
	 */
	public function test_template_compat_footer_classic_theme(): void {
		Functions\when( 'wp_is_block_theme' )->justReturn( false );

		$called = false;
		Functions\when( 'get_footer' )->alias(
			static function () use ( &$called ) {
				$called = true;
			}
		);

		TemplateCompat::footer();
		$this->assertTrue( $called );
	}
}
