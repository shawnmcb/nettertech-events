<?php
/**
 * Admin service provider.
 *
 * @package NetterTechEvents\Core\Providers
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Providers;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Admin\ActivityLogPage;
use NetterTechEvents\Admin\AdminMenu;
use NetterTechEvents\Admin\AttendeesPage;
use NetterTechEvents\Admin\Attendees\AttendeesBulkActions;
use NetterTechEvents\Admin\Attendees\AttendeesSummaryService;
use NetterTechEvents\Contracts\AttendeesSummaryServiceInterface;
use NetterTechEvents\Admin\Attendees\AttendeesExporter;
use NetterTechEvents\Admin\CategoryPage;
use NetterTechEvents\Admin\CsvImportPage;
use NetterTechEvents\Admin\ListTables\EventsListTable;
use NetterTechEvents\Admin\Notices\WooCommerceMissingNotice;
use NetterTechEvents\Admin\EventActionHandler;
use NetterTechEvents\Admin\EventQuickEditHandler;
use NetterTechEvents\Admin\EventSaveHandler;
use NetterTechEvents\Admin\EventsPage;
use NetterTechEvents\Admin\OrganizerPage;
use NetterTechEvents\Admin\Organizers\OrganizerBulkActions;
use NetterTechEvents\Admin\OrganizerSaveHandler;
use NetterTechEvents\Admin\RevisionAjaxHandler;
use NetterTechEvents\Admin\SettingsPage;
use NetterTechEvents\Admin\SettingsSanitizer;
use NetterTechEvents\Admin\SpaceSaveHandler;
use NetterTechEvents\Admin\SpacesPage;
use NetterTechEvents\Core\ActivityLogHooks;
use NetterTechEvents\Core\Container;
use NetterTechEvents\Core\ServiceProviderInterface;
use NetterTechEvents\Core\ServiceRegistry;
use NetterTechEvents\Services\EmailConfig;
use NetterTechEvents\Services\PathConflictDetector;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;

use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;
use NetterTechEvents\Services\EventDuplicationService;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\RevisionService;
use NetterTechEvents\Services\TicketTypeSaver;

/**
 * Registers admin-side services: admin hooks, page handlers, list tables.
 *
 * @since 1.7.0
 * @api
 */
class AdminServiceProvider implements ServiceProviderInterface {

	/**
	 * Register admin services in the container.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			ActivityLogHooks::class,
			fn( Container $c ) => new ActivityLogHooks(
				$c->get( ActivityLogServiceInterface::class ),
				$c->get( ReminderLogRepositoryInterface::class )
			)
		);

		$container->singleton(
			EventSaveHandler::class,
			fn( Container $c ) => new EventSaveHandler(
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( CategoryRepositoryInterface::class ),
				$c->get( RecurrenceService::class ),
				$c->get( TicketTypeSaver::class ),
				$c->get( LayoutService::class ),
				$c->get( \NetterTechEvents\Services\RecurrenceRuleBuilder::class ),
				$c->get( \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class ),
				$c->get( \NetterTechEvents\Services\CheckInEmailSaver::class ),
				$c->get( OrganizerRepositoryInterface::class ),
				$c->get( TagRepositoryInterface::class )
			)
		);

		$container->singleton(
			\NetterTechEvents\Admin\OccurrenceSaveHandler::class,
			fn( Container $c ) => new \NetterTechEvents\Admin\OccurrenceSaveHandler(
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( EventRepositoryInterface::class ),
				$c->get( RecurrenceService::class ),
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( CategoryRepositoryInterface::class ),
				$c->get( TagRepositoryInterface::class ),
				$c->get( OrganizerRepositoryInterface::class ),
				$c->get( TicketTypeSaver::class ),
				$c->get( AttendeeRepositoryInterface::class )
			)
		);

		$container->singleton(
			OrganizerSaveHandler::class,
			fn( Container $c ) => new OrganizerSaveHandler(
				$c->get( OrganizerRepositoryInterface::class )
			)
		);

		$container->singleton(
			SpaceSaveHandler::class,
			fn( Container $c ) => new SpaceSaveHandler(
				$c->get( SpaceRepositoryInterface::class )
			)
		);

		$container->singleton(
			\NetterTechEvents\Admin\Spaces\SpacesBulkActions::class,
			fn( Container $c ) => new \NetterTechEvents\Admin\Spaces\SpacesBulkActions(
				$c->get( SpaceRepositoryInterface::class )
			)
		);

		$container->singleton(
			SpacesPage::class,
			fn( Container $c ) => new SpacesPage(
				$c->get( SpaceRepositoryInterface::class ),
				$c->get( \NetterTechEvents\Admin\Spaces\SpacesBulkActions::class )
			)
		);

		$container->singleton(
			EventsPage::class,
			fn( Container $c ) => new EventsPage(
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( CapacityServiceInterface::class ),
				$c->get( RecurrenceService::class ),
				$c->get( RevisionRepositoryInterface::class ),
				$c->get( AttendeeFieldRepositoryInterface::class ),
				$c->get( LayoutService::class ),
				$c->get( CategoryRepositoryInterface::class ),
				$c->get( OrganizerRepositoryInterface::class ),
				$c->get( SpaceRepositoryInterface::class )
			)
		);

		$container->singleton(
			AdminMenu::class,
			fn( Container $c ) => new AdminMenu( $c->get( EventsPage::class ) )
		);

		$container->singleton(
			EventActionHandler::class,
			fn( Container $c ) => new EventActionHandler(
				$c->get( EventRepositoryInterface::class ),
				$c->get( EventDuplicationService::class ),
				$c->get( TicketRepositoryInterface::class )
			)
		);

		$container->singleton(
			EventQuickEditHandler::class,
			fn( Container $c ) => new EventQuickEditHandler(
				$c->get( EventRepositoryInterface::class )
			)
		);

		$container->singleton(
			RevisionAjaxHandler::class,
			fn( Container $c ) => new RevisionAjaxHandler(
				$c->get( RevisionRepositoryInterface::class ),
				$c->get( RevisionService::class ),
				$c->get( EventRepositoryInterface::class )
			)
		);

		$container->singleton(
			WooCommerceMissingNotice::class,
			fn( Container $c ) => new WooCommerceMissingNotice(
				$c->get( TicketTypeRepositoryInterface::class )
			)
		);

		$container->singleton(
			\NetterTechEvents\Admin\QRGeneratorPage::class,
			fn() => new \NetterTechEvents\Admin\QRGeneratorPage()
		);

		// =========================================================================
		// Page-handler and support-class registrations (SA-19 DI-bypass closure).
		//
		// Each entry below replaces a hand-built dependency graph that lived inside
		// AdminMenu render methods (`new \NetterTechEvents\...` chains) so render
		// methods can resolve services from the container instead of constructing
		// them inline. Pure-value classes (SettingsSanitizer, PaletteResolver,
		// PathConflictDetector, settings sections) are registered as singletons —
		// they hold no per-render state. EventsListTable is intentionally a
		// non-singleton: it carries per-render WP_List_Table state and must be
		// constructed fresh per page load.
		// =========================================================================

		$container->singleton(
			SettingsSanitizer::class,
			fn() => new SettingsSanitizer()
		);

		$container->singleton(
			PathConflictDetector::class,
			fn() => new PathConflictDetector()
		);

		$container->singleton(
			OrganizerBulkActions::class,
			fn( Container $c ) => new OrganizerBulkActions(
				$c->get( OrganizerRepositoryInterface::class )
			)
		);

		$container->singleton(
			OrganizerPage::class,
			fn( Container $c ) => new OrganizerPage(
				$c->get( OrganizerRepositoryInterface::class ),
				$c->get( OrganizerBulkActions::class )
			)
		);

		$container->singleton(
			CategoryPage::class,
			function ( Container $c ): CategoryPage {
				$category_repo = $c->get( CategoryRepositoryInterface::class );
				if ( ! $category_repo instanceof \NetterTechEvents\Repositories\CategoryRepository ) {
					throw new \InvalidArgumentException( 'Expected CategoryRepository instance.' );
				}
				return new CategoryPage( $category_repo );
			}
		);

		$container->singleton(
			AttendeesExporter::class,
			fn( Container $c ) => new AttendeesExporter(
				ServiceRegistry::wpdb(),
				$c->get( AttendeeFieldRepositoryInterface::class ),
				$c->get( AttendeeFieldValueRepositoryInterface::class )
			)
		);

		$container->singleton(
			AttendeesBulkActions::class,
			fn( Container $c ) => new AttendeesBulkActions(
				ServiceRegistry::wpdb(),
				$c->get( AttendeesExporter::class ),
				class_exists( 'WooCommerce' ) ? $c->get( \NetterTechEvents\Services\OrderEmailHandler::class ) : null
			)
		);

		$container->singleton(
			AttendeesSummaryServiceInterface::class,
			fn( Container $c ) => new AttendeesSummaryService(
				ServiceRegistry::wpdb(),
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( CapacityCalculatorInterface::class )
			)
		);

		$container->singleton(
			AttendeesPage::class,
			fn( Container $c ) => new AttendeesPage(
				ServiceRegistry::wpdb(),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( AttendeesBulkActions::class ),
				$c->get( AttendeesSummaryServiceInterface::class )
			)
		);

		$container->singleton(
			CsvImportPage::class,
			fn( Container $c ) => new CsvImportPage(
				$c->get( CsvImporter::class ),
				$c->get( CsvParser::class ),
				$c->get( CsvColumnMapper::class )
			)
		);

		$container->singleton(
			\NetterTechEvents\Admin\ActivityLogPage::class,
			function ( Container $c ): \NetterTechEvents\Admin\ActivityLogPage {
				$service = $c->get( ActivityLogServiceInterface::class );
				if ( ! $service instanceof \NetterTechEvents\Services\ActivityLogService ) {
					throw new \InvalidArgumentException( 'Expected ActivityLogService instance.' );
				}
				return new \NetterTechEvents\Admin\ActivityLogPage( $service );
			}
		);

		$container->singleton(
			SettingsPage::class,
			fn( Container $c ) => new SettingsPage(
				$c->get( LayoutService::class ),
				$c->get( SettingsSanitizer::class ),
				$c->get( EmailConfig::class )
			)
		);

		// EventsListTable: per-render instance — carries WP_List_Table state.
		// Bound via factory() so each get() call returns a fresh instance.
		$container->singleton(
			EventsListTable::class,
			function ( Container $c ): EventsListTable {
				$category_repo = $c->get( CategoryRepositoryInterface::class );
				if ( ! $category_repo instanceof \NetterTechEvents\Repositories\CategoryRepository ) {
					throw new \InvalidArgumentException( 'Expected CategoryRepository instance.' );
				}
				return new EventsListTable(
					$c->get( EventRepositoryInterface::class ),
					$c->get( OccurrenceRepositoryInterface::class ),
					$c->get( EventDuplicationService::class ),
					$category_repo,
					$c->get( OccurrenceQueryRepositoryInterface::class ),
					$c->get( TicketRepositoryInterface::class ),
					$c->get( TicketTypeRepositoryInterface::class ),
					$c->get( AttendeeRepositoryInterface::class )
				);
			}
		);
	}
}
