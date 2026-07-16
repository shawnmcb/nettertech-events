<?php
/**
 * PHPStan stubs for symbols outside the analyzed `paths: includes` set.
 *
 * Loaded only via phpstan.neon bootstrapFiles; WordPress never includes this
 * file. Bracketed namespace syntax because the stubs span three namespaces.
 *
 * - NetterTechEvents\nettertech_events_container(): defined in
 *   nettertech-events.php (plugin root) — 17 function.notFound before the
 *   stub; audit 2026-06-10 R-04, adjudicated as a bootstrap gap.
 * - NetterTechEvents\Services\PdfTicketService: provided by the Pro plugin;
 *   base references it behind class_exists() guards (OrderEmailHandler).
 * - FLPageData: Beaver Builder Themer global; base references it behind
 *   the BB integration guards (Themer\FieldConnections).
 *
 * @package NetterTechEvents
 */

declare(strict_types=1);

namespace NetterTechEvents {

	if ( ! function_exists( 'NetterTechEvents\nettertech_events_container' ) ) {
		/**
		 * Stub matching the signature in nettertech-events.php:87.
		 *
		 * @return \NetterTechEvents\Core\Container
		 */
		function nettertech_events_container(): \NetterTechEvents\Core\Container { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- namespaced; PHPStan-only stub, never executed.
			throw new \RuntimeException( 'PHPStan stub — never executed.' );
		}
	}
}

namespace NetterTechEvents\Services {

	if ( ! class_exists( 'NetterTechEvents\Services\PdfTicketService' ) ) {
		/**
		 * Pro-plugin PDF ticket service stub (signatures mirror Pro).
		 */
		class PdfTicketService {

			/**
			 * Generate a ticket PDF.
			 *
			 * @param mixed ...$args Ticket data.
			 * @return string|false PDF binary, or false on failure (base guards !== false).
			 */
			public function generate_pdf( ...$args ): string|false {
				throw new \RuntimeException( 'PHPStan stub — never executed.' );
			}

			/**
			 * Persist a generated PDF to a temp path.
			 *
			 * @param mixed ...$args PDF data.
			 * @return string|false Temp file path, or false on failure.
			 */
			public function save_to_temp( ...$args ): string|false {
				throw new \RuntimeException( 'PHPStan stub — never executed.' );
			}
		}
	}
}

namespace {

	if ( ! class_exists( 'WP_CLI' ) ) {
		/**
		 * WP-CLI runtime stub (NTE-129 backfill command).
		 */
		class WP_CLI { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- WP-CLI global; PHPStan-only stub.

			/**
			 * Register a command.
			 *
			 * @param string $name     Command name.
			 * @param mixed  $callable Command callable/class.
			 * @param array<string, mixed> $args Optional args.
			 * @return bool
			 */
			public static function add_command( $name, $callable, $args = array() ): bool {
				throw new \RuntimeException( 'PHPStan stub — never executed.' );
			}

			/**
			 * Print an informational line.
			 *
			 * @param string $message Message.
			 * @return void
			 */
			public static function log( $message ): void {}

			/**
			 * Print a success message.
			 *
			 * @param string $message Message.
			 * @return void
			 */
			public static function success( $message ): void {}

			/**
			 * Print a warning message.
			 *
			 * @param string $message Message.
			 * @return void
			 */
			public static function warning( $message ): void {}

			/**
			 * Stop execution with an error.
			 *
			 * @param string $message Message.
			 * @return void
			 */
			public static function error( $message ): void {
				throw new \RuntimeException( 'PHPStan stub — never executed.' );
			}
		}
	}

	if ( ! class_exists( 'FLPageData' ) ) {
		/**
		 * Beaver Builder Themer field-connection registry stub.
		 */
		class FLPageData { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedClassFound -- third-party (Beaver Builder) global; PHPStan-only stub.

			/**
			 * Register a connection group.
			 *
			 * @param mixed ...$args Group args.
			 * @return void
			 */
			public static function add_group( ...$args ): void {}

			/**
			 * Register a post property connection.
			 *
			 * @param mixed ...$args Property args.
			 * @return void
			 */
			public static function add_post_property( ...$args ): void {}

			/**
			 * Register an archive property connection.
			 *
			 * @param mixed ...$args Property args.
			 * @return void
			 */
			public static function add_archive_property( ...$args ): void {}
		}
	}
}

namespace WP_CLI\Utils {

	if ( ! function_exists( 'WP_CLI\Utils\format_items' ) ) {
		/**
		 * Format a list of items for display (WP-CLI helper stub).
		 *
		 * @param string            $format Output format (e.g. 'table').
		 * @param array<int, mixed> $items  Items to render.
		 * @param array<int, string> $fields Field/column names.
		 * @return void
		 */
		function format_items( $format, $items, $fields ): void {} // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- third-party (WP-CLI) namespaced helper; PHPStan-only stub.
	}
}
