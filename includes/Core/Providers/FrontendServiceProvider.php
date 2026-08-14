<?php
/**
 * Frontend service provider.
 *
 * @package NetterTechEvents\Core\Providers
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Providers;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Container;
use NetterTechEvents\Core\ServiceProviderInterface;

use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CapacityCalculatorInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Frontend\OccurrenceAvailabilityPresenter;
use NetterTechEvents\Frontend\Shortcodes\CalendarShortcode;
use NetterTechEvents\Frontend\Shortcodes\CarouselShortcode;
use NetterTechEvents\Frontend\Shortcodes\EventListShortcode;
use NetterTechEvents\Frontend\Shortcodes\RegularsShortcode;
use NetterTechEvents\Frontend\Shortcodes\RSVPFormShortcode;
use NetterTechEvents\Services\PaletteResolver;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Services\RRuleParser;
use NetterTechEvents\TemplateLoader\Templates;

/**
 * Registers frontend services: shortcodes, check-in page, palette resolver.
 *
 * @since 1.7.0
 * @api
 */
class FrontendServiceProvider implements ServiceProviderInterface {

	/**
	 * Register frontend services in the container.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Container param required by factory signature.

		$container->singleton(
			PaletteResolver::class,
			fn() => new PaletteResolver()
		);

		$container->singleton(
			OccurrenceAvailabilityPresenter::class,
			fn( Container $c ) => new OccurrenceAvailabilityPresenter(
				$c->get( CapacityServiceInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class )
			)
		);

		$container->singleton(
			EventListShortcode::class,
			fn( Container $c ) => new EventListShortcode(
				$c->get( OccurrenceRepositoryInterface::class ),
				Templates::get_instance(),
				$c->get( \NetterTechEvents\Contracts\CategoryRepositoryInterface::class ),
				$c->get( \NetterTechEvents\Contracts\TagRepositoryInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( OccurrenceAvailabilityPresenter::class )
			)
		);

		$container->singleton(
			RegularsShortcode::class,
			fn( Container $c ) => new RegularsShortcode(
				$c->get( EventRepositoryInterface::class ),
				$c->get( OccurrenceRepositoryInterface::class ),
				new RRuleParser(),
				Templates::get_instance()
			)
		);

		$container->singleton(
			CarouselShortcode::class,
			fn( Container $c ) => new CarouselShortcode(
				$c->get( OccurrenceRepositoryInterface::class ),
				Templates::get_instance(),
				$c->get( \NetterTechEvents\Contracts\TagRepositoryInterface::class ),
				$c->get( TicketTypeRepositoryInterface::class ),
				$c->get( OccurrenceAvailabilityPresenter::class )
			)
		);

		$container->singleton(
			CalendarShortcode::class,
			fn( Container $c ) => new CalendarShortcode(
				$c->get( OccurrenceRepositoryInterface::class )
			)
		);

		$container->singleton(
			RSVPFormShortcode::class,
			function ( Container $c ): RSVPFormShortcode {
				$occurrence_repo = $c->get( OccurrenceRepositoryInterface::class );
				if ( ! $occurrence_repo instanceof \NetterTechEvents\Repositories\OccurrenceRepository ) {
					throw new \InvalidArgumentException( 'Expected OccurrenceRepository instance.' );
				}
				$ticket_type_repo = $c->get( TicketTypeRepositoryInterface::class );
				if ( ! $ticket_type_repo instanceof \NetterTechEvents\Repositories\TicketTypeRepository ) {
					throw new \InvalidArgumentException( 'Expected TicketTypeRepository instance.' );
				}
				$attendee_repo = $c->get( AttendeeRepositoryInterface::class );
				if ( ! $attendee_repo instanceof \NetterTechEvents\Repositories\AttendeeRepository ) {
					throw new \InvalidArgumentException( 'Expected AttendeeRepository instance.' );
				}
				return new RSVPFormShortcode(
					$occurrence_repo,
					$ticket_type_repo,
					$attendee_repo,
					$c->get( CapacityCalculatorInterface::class ),
					$c->get( RateLimitService::class )
				);
			}
		);

		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	}
}
