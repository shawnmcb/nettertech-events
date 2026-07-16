<?php
/**
 * Bootstrap smoke test for the Events plugin (Phase 5 / T5.1.3).
 *
 * Asserts the plugin wakes up correctly inside a real WordPress runtime:
 * Plugin class loads, ServiceRegistry resolves required contracts via the
 * live container, and top-level WordPress hooks are attached at boot.
 *
 * Schema table existence and dbDelta idempotency are deliberately NOT
 * re-asserted here — see SchemaMigrationIntegrationTest for that coverage.
 *
 *
 * @package NetterTechEvents\Tests\Integration\Bootstrap
 */

declare(strict_types=1);

namespace NetterTechEvents\Tests\Integration\Bootstrap;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Core\Plugin;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;

/**
 * Bootstrap smoke: positive-path verification that Events wakes up.
 *
 * @group bootstrap-smoke
 * @group wiring
 */
class EventsBootstrapSmokeTest extends \NetterTechEventsIntegrationTestCase {

	/**
	 * Smoke tests don't mutate data — skip the per-class transaction wrapping.
	 *
	 * @var bool
	 */
	protected static bool $use_transactions = false;

	/**
	 * The Events Plugin entry class is loaded by the time we run.
	 *
	 * @return void
	 */
	public function test_plugin_class_is_loaded(): void {
		$this->assertTrue(
			class_exists( Plugin::class ),
			'NetterTechEvents\\Core\\Plugin must be autoloaded before smoke runs.'
		);
	}

	/**
	 * ServiceRegistry has been initialised with a Container.
	 *
	 * @return void
	 */
	public function test_service_registry_container_is_initialised(): void {
		$container = ServiceRegistry::container();
		$this->assertNotNull( $container, 'ServiceRegistry::container() returned null.' );
	}

	/**
	 * Critical repository + service contracts resolve via the live container.
	 *
	 * Uses Container::get() rather than the deprecated ServiceRegistry::get()
	 * to avoid emitting deprecation notices during smoke (per BOOTSTRAP-SMOKE-PATTERN
	 * positive-only convention).
	 *
	 * @return void
	 */
	public function test_core_contracts_resolve(): void {
		$contracts = array(
			EventRepositoryInterface::class,
			OccurrenceRepositoryInterface::class,
			TicketTypeRepositoryInterface::class,
			TicketRepositoryInterface::class,
			AttendeeRepositoryInterface::class,
			CapacityServiceInterface::class,
		);

		$container = ServiceRegistry::container();

		foreach ( $contracts as $contract ) {
			$this->assertTrue(
				$container->has( $contract ),
				"Container is missing required contract binding: {$contract}"
			);

			$instance = $container->get( $contract );

			$this->assertInstanceOf(
				$contract,
				$instance,
				"Container resolved {$contract} but returned wrong implementation type."
			);
		}
	}

	/**
	 * Typed ServiceRegistry accessors return the expected interface types.
	 *
	 * These are the public API every consumer plugin codes against; they MUST
	 * stay live or every consumer breaks at boot.
	 *
	 * @return void
	 */
	public function test_typed_service_accessors_return_expected_types(): void {
		$this->assertInstanceOf( EventRepositoryInterface::class, ServiceRegistry::event_repository() );
		$this->assertInstanceOf( OccurrenceRepositoryInterface::class, ServiceRegistry::occurrence_repository() );
		$this->assertInstanceOf( TicketTypeRepositoryInterface::class, ServiceRegistry::ticket_type_repository() );
		$this->assertInstanceOf( TicketRepositoryInterface::class, ServiceRegistry::ticket_repository() );
		$this->assertInstanceOf( AttendeeRepositoryInterface::class, ServiceRegistry::attendee_repository() );
		$this->assertInstanceOf( CapacityServiceInterface::class, ServiceRegistry::capacity_service() );
	}

	/**
	 * Top-level WordPress hooks are attached after plugin boot.
	 *
	 * We assert the hook *has* a callback registered (priority any). We don't
	 * pin specific callbacks — that would make the test brittle against
	 * legitimate refactors of the boot sequence. Smoke is "did the wiring fire?",
	 * not "is the wiring shaped the way it was on day one?".
	 *
	 * @return void
	 */
	public function test_critical_hooks_are_attached(): void {
		$critical_hooks = array(
			'init',
			'admin_init',
			'rest_api_init',
		);

		foreach ( $critical_hooks as $hook ) {
			$this->assertNotFalse(
				has_action( $hook ),
				"Critical hook '{$hook}' has no callbacks attached after Events boot."
			);
		}
	}

	/**
	 * The Events public extension Hooks contract class is loaded.
	 *
	 * `Hooks::*` constants are the canonical extension API consumed by Pro,
	 * Rentals, Migrator, and GR. If the class isn't loaded, every consumer's
	 * `add_action( Hooks::INIT, ... )` call crashes at boot.
	 *
	 * Asserts the class is present plus a sample of the most-consumed
	 * constants (Pro fires `AFTER_SAVE_EVENT`, Migrator fires
	 * `BULK_IMPORT_COMPLETED` and `TICKET_TYPE_SYNC_PRODUCT`).
	 *
	 * @return void
	 */
	public function test_hooks_contract_constants_class_is_loaded(): void {
		$this->assertTrue(
			class_exists( Hooks::class ),
			'NetterTechEvents\\Core\\Hooks (the extension contract class) failed to load.'
		);

		$contract_constants = array(
			'INIT'                     => 'nettertech_events_init',
			'AFTER_SAVE_EVENT'         => 'nettertech_events_after_save_event',
			'OCCURRENCES_GENERATED'    => 'nettertech_events_occurrences_generated',
			'TICKET_TYPE_SYNC_PRODUCT' => 'nettertech_events_ticket_type_sync_product',
		);

		foreach ( $contract_constants as $name => $expected_literal ) {
			$fq_constant = Hooks::class . '::' . $name;
			$this->assertTrue(
				defined( $fq_constant ),
				"Public contract constant {$fq_constant} is missing."
			);
			$this->assertSame(
				$expected_literal,
				constant( $fq_constant ),
				"Public contract constant {$fq_constant} value drifted from its documented literal."
			);
		}
	}
}
