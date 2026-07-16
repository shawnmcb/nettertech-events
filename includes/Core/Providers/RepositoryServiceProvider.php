<?php
/**
 * Repository service provider.
 *
 * @package NetterTechEvents\Core\Providers
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Providers;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Container;
use NetterTechEvents\Core\ServiceProviderInterface;
use NetterTechEvents\Core\ServiceRegistry;

use NetterTechEvents\Contracts\AttendeeCheckInInterface;
use NetterTechEvents\Contracts\AttendeeFieldRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeFieldValueRepositoryInterface;
use NetterTechEvents\Contracts\AttendeeRepositoryInterface;
use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\EventQueryRepositoryInterface;
use NetterTechEvents\Contracts\EventRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceFilterRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceQueryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\OrganizerRepositoryInterface;
use NetterTechEvents\Contracts\ReminderLogRepositoryInterface;
use NetterTechEvents\Contracts\RevisionRepositoryInterface;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Contracts\TagRepositoryInterface;
use NetterTechEvents\Contracts\TicketRepositoryInterface;
use NetterTechEvents\Contracts\HouseCapacityRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeQueryRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeStockRepositoryInterface;
use NetterTechEvents\Contracts\WaitlistRepositoryInterface;

use NetterTechEvents\Repositories\AttendeeCheckInRepository;
use NetterTechEvents\Repositories\HouseCapacityRepository;
use NetterTechEvents\Repositories\AttendeeFieldRepository;
use NetterTechEvents\Repositories\AttendeeFieldValueRepository;
use NetterTechEvents\Repositories\AttendeeRepository;
use NetterTechEvents\Repositories\CategoryRepository;
use NetterTechEvents\Repositories\EventRepository;
use NetterTechEvents\Repositories\OccurrenceFilterRepository;
use NetterTechEvents\Repositories\OccurrenceQueryRepository;
use NetterTechEvents\Repositories\OccurrenceRepository;
use NetterTechEvents\Repositories\OrganizerRepository;
use NetterTechEvents\Repositories\ReminderLogRepository;
use NetterTechEvents\Repositories\RevisionRepository;
use NetterTechEvents\Repositories\SpaceRepository;
use NetterTechEvents\Repositories\TagRepository;
use NetterTechEvents\Repositories\TicketRepository;
use NetterTechEvents\Repositories\TicketTypeRepository;
use NetterTechEvents\Repositories\WaitlistRepository;

/**
 * Registers all repository interface-to-implementation bindings.
 *
 * @since 1.7.0
 * @api
 */
class RepositoryServiceProvider implements ServiceProviderInterface {

	/**
	 * Register repository services in the container.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		// phpcs:disable Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Container param required by factory signature.

		$container->singleton(
			OccurrenceFilterRepositoryInterface::class,
			fn( Container $c ) => new OccurrenceFilterRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			OccurrenceQueryRepositoryInterface::class,
			fn( Container $c ) => new OccurrenceQueryRepository(
				ServiceRegistry::wpdb(),
				$c->get( OccurrenceFilterRepositoryInterface::class )
			)
		);

		$container->singleton(
			OccurrenceRepositoryInterface::class,
			fn( Container $c ) => new OccurrenceRepository(
				ServiceRegistry::wpdb(),
				$c->get( OccurrenceQueryRepositoryInterface::class )
			)
		);

		$container->singleton(
			TicketTypeRepositoryInterface::class,
			fn( Container $c ) => new TicketTypeRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			HouseCapacityRepositoryInterface::class,
			fn( Container $c ) => new HouseCapacityRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			EventRepositoryInterface::class,
			fn( Container $c ) => new EventRepository(
				ServiceRegistry::wpdb(),
				null
			)
		);

		$container->singleton(
			AttendeeRepositoryInterface::class,
			fn( Container $c ) => new AttendeeRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			AttendeeCheckInInterface::class,
			fn( Container $c ) => new AttendeeCheckInRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			TicketRepositoryInterface::class,
			fn( Container $c ) => new TicketRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			OrganizerRepositoryInterface::class,
			fn( Container $c ) => new OrganizerRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			CategoryRepositoryInterface::class,
			fn( Container $c ) => new CategoryRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			TagRepositoryInterface::class,
			fn( Container $c ) => new TagRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			WaitlistRepositoryInterface::class,
			fn( Container $c ) => new WaitlistRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			AttendeeFieldRepositoryInterface::class,
			fn( Container $c ) => new AttendeeFieldRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			AttendeeFieldValueRepositoryInterface::class,
			fn( Container $c ) => new AttendeeFieldValueRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			SpaceRepositoryInterface::class,
			fn( Container $c ) => new SpaceRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			RevisionRepositoryInterface::class,
			fn( Container $c ) => new RevisionRepository( ServiceRegistry::wpdb() )
		);

		$container->singleton(
			ReminderLogRepositoryInterface::class,
			fn( Container $c ) => new ReminderLogRepository( ServiceRegistry::wpdb() )
		);

		// phpcs:enable Generic.CodeAnalysis.UnusedFunctionParameter.Found
	}
}
