<?php
/**
 * Service provider interface for domain-specific DI registration.
 *
 * @package NetterTechEvents\Core
 */

declare(strict_types=1);

namespace NetterTechEvents\Core;

defined( 'ABSPATH' ) || exit;

/**
 * Contract for domain service providers.
 *
 * Each provider is responsible for registering a cohesive set of
 * services in the DI container. ServiceRegistry iterates over
 * providers during bootstrap.
 *
 * @since 1.7.0
 * @api
 */
interface ServiceProviderInterface {

	/**
	 * Register services in the container.
	 *
	 * @param Container $container The DI container.
	 * @return void
	 */
	public function register( Container $container ): void;
}
