<?php
/**
 * Integration service provider.
 *
 * @package NetterTechEvents\Core\Providers
 */

declare(strict_types=1);

namespace NetterTechEvents\Core\Providers;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Container;
use NetterTechEvents\Core\ServiceProviderInterface;

use NetterTechEvents\Contracts\CategoryRepositoryInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Integrations\WooCommerce\CategoryProductCatMapper;
use NetterTechEvents\Integrations\WooCommerce\ProductManager;

/**
 * Registers third-party integration services: WooCommerce, Beaver Builder.
 *
 * @since 1.7.0
 * @api
 */
class IntegrationServiceProvider implements ServiceProviderInterface {

	/**
	 * Register integration services in the container.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public function register( Container $container ): void {
		$container->singleton(
			ProductManager::class,
			function ( Container $c ): ProductManager {
				$ticket_type_repo = $c->get( TicketTypeRepositoryInterface::class );
				if ( ! $ticket_type_repo instanceof \NetterTechEvents\Repositories\TicketTypeRepository ) {
					throw new \InvalidArgumentException( 'Expected TicketTypeRepository instance.' );
				}
				$occurrence_repo = $c->get( OccurrenceRepositoryInterface::class );
				if ( ! $occurrence_repo instanceof \NetterTechEvents\Repositories\OccurrenceRepository ) {
					throw new \InvalidArgumentException( 'Expected OccurrenceRepository instance.' );
				}
				$category_repo = $c->get( CategoryRepositoryInterface::class );
				if ( ! $category_repo instanceof CategoryRepositoryInterface ) {
					throw new \InvalidArgumentException( 'Expected CategoryRepositoryInterface instance.' );
				}
				return new ProductManager(
					$ticket_type_repo,
					$occurrence_repo,
					new CategoryProductCatMapper( $category_repo ),
					$c->get( \NetterTechEvents\Contracts\HouseCapacityRepositoryInterface::class ),
					$c->get( \NetterTechEvents\Contracts\CapacityCalculatorInterface::class ),
					$c->get( \NetterTechEvents\Contracts\EventRepositoryInterface::class )
				);
			}
		);
	}
}
