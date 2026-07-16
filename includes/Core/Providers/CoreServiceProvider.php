<?php
/**
 * Core service provider.
 *
 * @package NetterTechEvents\Core\Providers
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Providers;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\CacheManager;
use NetterTechEvents\Core\Container;
use NetterTechEvents\Core\NetterTechEventsSettings;
use NetterTechEvents\Core\ServiceProviderInterface;
use NetterTechEvents\Core\ServiceRegistry;

use NetterTechEvents\Contracts\ActivityLogServiceInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CalendarLinkServiceInterface;
use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EmailServiceInterface;
use NetterTechEvents\Contracts\EmailTemplateRendererInterface;
use NetterTechEvents\Contracts\EventDeletionCascadeInterface;
use NetterTechEvents\Contracts\IcsGeneratorInterface;
use NetterTechEvents\Contracts\ExportServiceInterface;
use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\QRCodeServiceInterface;
use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;
use NetterTechEvents\Contracts\ReservationManagerInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\TicketCodeGeneratorInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistServiceInterface;

use NetterTechEvents\Repositories\ActivityLogRepository;
use NetterTechEvents\Services\ActivityLogService;
use NetterTechEvents\Services\AttendeeFieldService;
use NetterTechEvents\Services\CalendarLinkService;
use NetterTechEvents\Services\CapacityCalculator;
use NetterTechEvents\Services\CapacityService;
use NetterTechEvents\Services\CsvColumnMapper;
use NetterTechEvents\Services\CsvImporter;
use NetterTechEvents\Services\CsvParser;
use NetterTechEvents\Services\CsvValidator;
use NetterTechEvents\Services\EmailConfig;
use NetterTechEvents\Services\EmailService;
use NetterTechEvents\Services\EmailTemplateRenderer;
use NetterTechEvents\Services\IcsGenerator;
use NetterTechEvents\Services\EventDeletionCascade;
use NetterTechEvents\Services\EventDuplicationService;
use NetterTechEvents\Services\ExportService;
use NetterTechEvents\Services\ICalFeedRegenerationListener;
use NetterTechEvents\Services\ICalFileWriter;
use NetterTechEvents\Services\ICalService;
use NetterTechEvents\Services\LayoutService;
use NetterTechEvents\Services\OccurrenceGenerator;
use NetterTechEvents\Services\OccurrenceHorizonExtender;
use NetterTechEvents\Services\OrderEmailHandler;
use NetterTechEvents\Services\PrivacyService;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Services\RecurrenceService;
use NetterTechEvents\Services\ReminderEmailService;
use NetterTechEvents\Services\ReservationManager;
use NetterTechEvents\Services\RevisionService;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\Services\RsvpCapacityHandler;
use NetterTechEvents\Services\RsvpEmailHandler;
use NetterTechEvents\Services\TicketCodeGenerator;
use NetterTechEvents\Services\TicketTypeSaver;
use NetterTechEvents\Services\VEventParser;
use NetterTechEvents\Services\WaitlistEmailHandler;
use NetterTechEvents\Services\WaitlistService;

use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;

/**
 * Registers core application services: capacity, email, recurrence,
 * reservation, caching, CSV import, and settings.
 *
 * @since 1.7.0
 * @api
 */
class CoreServiceProvider implements ServiceProviderInterface {

	/**
	 * Register core services in the container.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Container param required by factory signature.

		$this->register_settings( $container );
		$this->register_capacity_services( $container );
		$this->register_qr_services( $container );
		$this->register_email_services( $container );
		$this->register_recurrence_services( $container );
		$this->register_utility_services( $container );
		$this->register_cache( $container );

		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	}

	/**
	 * Register settings.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private function register_settings( Container $container ): void {
		$container->singleton(
			NetterTechEventsSettings::class,
			fn() => NetterTechEventsSettings::from_option()
		);
	}

	/**
	 * Register QR code generation service.
	 *
	 * QRCodeService provides arbitrary and event-page QR generation for
	 * the base plugin and is also the source of truth for presentation
	 * settings (colors, logo, size, style). Pro consumes this service
	 * for check-in QR rendering without duplicating settings.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private function register_qr_services( Container $container ): void {
		$container->singleton(
			QRCodeServiceInterface::class,
			fn( Container $c ) => new \NetterTechEvents\Services\QRCodeService(
				$c->get( NetterTechEventsSettings::class )
			)
		);
	}

	/**
	 * Register capacity-related services.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private function register_capacity_services( Container $container ): void {
		$container->singleton(
			ReservationManagerInterface::class,
			fn() => new ReservationManager()
		);

		$container->singleton(
			CapacityCalculatorInterface::class,
			fn( Container $c ) => new CapacityCalculator(
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( ReservationManagerInterface::class ),
				$c->get( HouseCapacityRepositoryInterface::class )
			)
		);

		$container->singleton(
			CapacityServiceInterface::class,
			fn( Container $c ) => new CapacityService(
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( CapacityCalculatorInterface::class ),
				$c->get( ReservationManagerInterface::class )
			)
		);

		// RSVP capacity bridge (NTE-036): increments sold_count on Hooks::RSVP_SUBMITTED
		// so free-event RSVPs participate in capacity enforcement instead of relying
		// on the WooCommerce order-completion path that never fires for free events.
		$container->singleton(
			RsvpCapacityHandler::class,
			fn( Container $c ) => new RsvpCapacityHandler(
				$c->get( CapacityServiceInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class )
			)
		);
	}

	/**
	 * Register email-related services.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private function register_email_services( Container $container ): void {
		$container->singleton(
			EmailTemplateRendererInterface::class,
			fn( Container $c ) => new EmailTemplateRenderer(
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( EventRepositoryInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class )
			)
		);

		// ICS calendar-file generator for email attachments (NTE-107 — extracted
		// from EmailTemplateRenderer). Distinct from ICalService (the public feed).
		$container->singleton(
			IcsGeneratorInterface::class,
			fn( Container $c ) => new IcsGenerator(
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( EventRepositoryInterface::class )
			)
		);

		$container->singleton(
			ReminderEmailService::class,
			fn( Container $c ) => new ReminderEmailService(
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( AttendeeRepositoryInterface::class ),
				$c->get( ReminderLogRepositoryInterface::class ),
				$c->get( EventRepositoryInterface::class ),
				$c->get( EmailTemplateRendererInterface::class ),
				$c->get( IcsGeneratorInterface::class )
			)
		);

		// EmailConfig provides shared settings and recipient resolution.
		// Handlers depend on EmailConfig (not EmailService), breaking the cycle.
		$container->singleton(
			EmailConfig::class,
			fn( Container $c ) => new EmailConfig(
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( EventRepositoryInterface::class )
			)
		);

		$container->singleton(
			OrderEmailHandler::class,
			fn( Container $c ) => new OrderEmailHandler(
				$c->has( QRCodeServiceInterface::class ) ? $c->get( QRCodeServiceInterface::class ) : null,
				$c->get( TicketRepositoryInterface::class ),
				$c->get( EmailTemplateRendererInterface::class ),
				$c->get( EmailConfig::class ),
				$c->get( IcsGeneratorInterface::class )
			)
		);

		$container->singleton(
			RsvpEmailHandler::class,
			fn( Container $c ) => new RsvpEmailHandler(
				$c->has( QRCodeServiceInterface::class ) ? $c->get( QRCodeServiceInterface::class ) : null,
				$c->get( TicketCodeGeneratorInterface::class ),
				$c->get( TicketRepositoryInterface::class ),
				$c->get( EmailTemplateRendererInterface::class ),
				$c->get( EmailConfig::class ),
				$c->get( IcsGeneratorInterface::class )
			)
		);

		// EmailService receives handlers via constructor; single-phase construction.
		$container->singleton(
			EmailService::class,
			fn( Container $c ) => new EmailService(
				$c->get( EmailConfig::class ),
				$c->get( OrderEmailHandler::class ),
				$c->get( RsvpEmailHandler::class )
			)
		);

		// Interface alias for Pro extensibility.
		$container->singleton(
			EmailServiceInterface::class,
			fn( Container $c ) => $c->get( EmailService::class )
		);

		$container->singleton(
			WaitlistEmailHandler::class,
			fn( Container $c ) => new WaitlistEmailHandler(
				$c->get( WaitlistRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( EmailTemplateRendererInterface::class ),
				$c->get( EmailConfig::class )
			)
		);
	}

	/**
	 * Register recurrence-related services.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private function register_recurrence_services( Container $container ): void {
		$container->singleton(
			RecurrenceService::class,
			fn( Container $c ) => new RecurrenceService(
				new RRuleParser(),
				new OccurrenceGenerator(),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( AttendeeRepositoryInterface::class )
			)
		);

		$container->singleton(
			OccurrenceHorizonExtender::class,
			fn( Container $c ) => new OccurrenceHorizonExtender(
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( RecurrenceService::class )
			)
		);
	}

	/**
	 * Register utility services.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private function register_utility_services( Container $container ): void {
		$container->singleton(
			TicketCodeGenerator::class,
			fn() => new TicketCodeGenerator()
		);

		// Interface alias for Pro extensibility.
		$container->singleton(
			TicketCodeGeneratorInterface::class,
			fn( Container $c ) => $c->get( TicketCodeGenerator::class )
		);

		$container->singleton(
			ActivityLogServiceInterface::class,
			fn() => new ActivityLogService(
				new ActivityLogRepository( ServiceRegistry::wpdb() )
			)
		);

		$container->singleton(
			EventDuplicationService::class,
			fn( Container $c ) => new EventDuplicationService(
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class )
			)
		);

		$container->singleton(
			EventDeletionCascadeInterface::class,
			fn( Container $c ) => new EventDeletionCascade(
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( TicketRepositoryInterface::class ),
				$c->get( AttendeeRepositoryInterface::class ),
				$c->get( AttendeeFieldValueRepositoryInterface::class ),
				$c->get( AttendeeFieldRepositoryInterface::class ),
				$c->get( WaitlistRepositoryInterface::class ),
				$c->get( ReminderLogRepositoryInterface::class ),
				$c->get( CategoryRepositoryInterface::class ),
				$c->get( TagRepositoryInterface::class ),
				$c->get( OrganizerRepositoryInterface::class ),
				$c->get( ReservationManagerInterface::class )
			)
		);

		$container->singleton(
			AttendeeFieldService::class,
			fn( Container $c ) => new AttendeeFieldService(
				$c->get( AttendeeFieldRepositoryInterface::class ),
				$c->get( AttendeeFieldValueRepositoryInterface::class )
			)
		);

		$container->singleton(
			RevisionService::class,
			fn( Container $c ) => new RevisionService(
				$c->get( RevisionRepositoryInterface::class ),
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				ServiceRegistry::wpdb()
			)
		);

		$container->singleton(
			RateLimitService::class,
			fn( Container $c ) => new RateLimitService(
				$c->get( NetterTechEventsSettings::class )
			)
		);

		$container->singleton(
			PrivacyService::class,
			fn() => new PrivacyService( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			WaitlistService::class,
			fn( Container $c ) => new WaitlistService(
				$c->get( WaitlistRepositoryInterface::class )
			)
		);

		// Interface alias for Pro extensibility.
		$container->singleton(
			WaitlistServiceInterface::class,
			fn( Container $c ) => $c->get( WaitlistService::class )
		);

		$container->singleton(
			CalendarLinkService::class,
			fn() => new CalendarLinkService()
		);

		// Interface alias for Pro extensibility.
		$container->singleton(
			CalendarLinkServiceInterface::class,
			fn( Container $c ) => $c->get( CalendarLinkService::class )
		);

		$container->singleton(
			ICalService::class,
			fn( Container $c ) => new ICalService(
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				new VEventParser(),
				$c->get( CategoryRepositoryInterface::class ),
				$c->get( RecurrenceService::class ),
				new OccurrenceGenerator()
			)
		);

		$container->singleton(
			ICalFileWriter::class,
			fn( Container $c ) => new ICalFileWriter(
				$c->get( ICalService::class )
			)
		);

		$container->singleton(
			ICalFeedRegenerationListener::class,
			fn( Container $c ) => new ICalFeedRegenerationListener(
				$c->get( ICalFileWriter::class )
			)
		);

		$container->singleton(
			ExportService::class,
			fn( Container $c ) => new ExportService(
				$c->get( AttendeeCheckInInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class )
			)
		);

		// Interface alias for Pro extensibility.
		$container->singleton(
			ExportServiceInterface::class,
			fn( Container $c ) => $c->get( ExportService::class )
		);

		$container->singleton(
			TicketTypeSaver::class,
			fn( Container $c ) => new TicketTypeSaver(
				$c->get( TicketTypeRepositoryInterface::class ),
				class_exists( 'WooCommerce' ) ? $c->get( \NetterTechEvents\Integrations\WooCommerce\ProductManager::class ) : null
			)
		);

		$container->singleton(
			LayoutService::class,
			fn() => new LayoutService()
		);

		$container->singleton(
			\NetterTechEvents\Services\RecurrenceRuleBuilder::class,
			fn() => new \NetterTechEvents\Services\RecurrenceRuleBuilder()
		);

		$container->singleton(
			\NetterTechEvents\Services\CheckInEmailSaver::class,
			fn() => new \NetterTechEvents\Services\CheckInEmailSaver()
		);

		$container->singleton(
			\NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler::class,
			fn( Container $c ) => new \NetterTechEvents\Admin\Metaboxes\AttendeeFieldsSaveHandler(
				$c->get( AttendeeFieldRepositoryInterface::class ),
				$c->get( AttendeeFieldValueRepositoryInterface::class )
			)
		);

		$container->singleton(
			CsvParser::class,
			fn() => new CsvParser()
		);

		$container->singleton(
			CsvColumnMapper::class,
			fn() => new CsvColumnMapper()
		);

		$container->singleton(
			CsvValidator::class,
			fn() => new CsvValidator()
		);

		$container->singleton(
			CsvImporter::class,
			fn( Container $c ) => new CsvImporter(
				$c->get( CsvParser::class ),
				$c->get( CsvColumnMapper::class ),
				$c->get( CsvValidator::class ),
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				$c->get( OrganizerRepositoryInterface::class ),
				$c->get( CategoryRepositoryInterface::class )
			)
		);
	}

	/**
	 * Register cache manager in the container.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	private function register_cache( Container $container ): void {
		$container->singleton(
			CacheManager::class,
			fn( Container $c ) => new CacheManager(
				ServiceRegistry::wpdb(),
				\NetterTechEvents\TemplateLoader\Templates::get_instance(),
				$c->get( HouseCapacityRepositoryInterface::class )
			)
		);
	}
}
