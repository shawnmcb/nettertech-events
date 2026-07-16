<?php
/**
 * Path conflict detection service.
 *
 * Detects when the nettertech-events base path conflicts with other plugins'
 * rewrite rules, preventing routing issues.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\PathHelper;

/**
 * Detects URL path conflicts with other plugins.
 *
 * @since 0.9.0
 * @api
 */
class PathConflictDetector {

	/**
	 * Cache key prefix for conflict detection results (per scope).
	 */
	private const CACHE_KEY_PREFIX = 'nettertech_events_path_conflicts_';

	/**
	 * Cache expiration in seconds (1 hour).
	 */
	private const CACHE_EXPIRATION = HOUR_IN_SECONDS;

	/**
	 * Known plugin post types that commonly use /events/.
	 *
	 * @var array<string, string>
	 */
	private const KNOWN_EVENT_POST_TYPES = array(
		'tribe_events'    => 'The Events Calendar',
		'event'           => 'Events Manager',
		'tribe_venue'     => 'The Events Calendar (Venues)',
		'tribe_organizer' => 'The Events Calendar (Organizers)',
		'em_event'        => 'Events Manager',
		'mec-events'      => 'Modern Events Calendar',
		'ajde_events'     => 'EventON',
	);

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_notices', array( $this, 'maybe_show_conflict_notice' ) );
		add_action( 'update_option_nettertech_events_settings', array( $this, 'clear_cache' ) );
		add_action( 'update_option_rewrite_rules', array( $this, 'clear_cache' ) );
		add_action( 'activated_plugin', array( $this, 'clear_cache' ) );
		add_action( 'deactivated_plugin', array( $this, 'clear_cache' ) );
	}

	/**
	 * Clear the conflict detection cache (all scopes).
	 *
	 * @return void
	 */
	public function clear_cache(): void {
		foreach ( $this->scopes() as $scope => $_ ) {
			delete_transient( self::CACHE_KEY_PREFIX . $scope );
		}
	}

	/**
	 * Get the scopes this detector watches.
	 *
	 * Each scope is a (key, base-path) pair; conflict detection runs per scope.
	 *
	 * @return array<string, string> Scope key => base path.
	 */
	private function scopes(): array {
		return array(
			'events' => PathHelper::get_base_path(),
			'spaces' => PathHelper::get_spaces_base_path(),
		);
	}

	/**
	 * Get detected conflicts across all scopes (events + spaces).
	 *
	 * @param bool $force_refresh Force refresh of cached results.
	 * @return array<array{type: string, source: string, description: string, scope: string, scope_path: string}>
	 */
	public function get_conflicts( bool $force_refresh = false ): array {
		$all = array();
		foreach ( $this->scopes() as $scope => $path ) {
			$all = array_merge( $all, $this->get_conflicts_for_scope( $scope, $path, $force_refresh ) );
		}
		return $all;
	}

	/**
	 * Get detected conflicts for a single scope.
	 *
	 * @param string $scope         Scope key (e.g., 'events' or 'spaces').
	 * @param string $path          Base path for the scope.
	 * @param bool   $force_refresh Force refresh of cached results.
	 * @return array<array{type: string, source: string, description: string, scope: string, scope_path: string}>
	 */
	private function get_conflicts_for_scope( string $scope, string $path, bool $force_refresh ): array {
		$cache_key = self::CACHE_KEY_PREFIX . $scope;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( false !== $cached ) {
				return $cached;
			}
		}

		$conflicts = array_merge(
			$this->detect_post_type_conflicts( $path ),
			$this->detect_rewrite_rule_conflicts( $path ),
			$this->detect_page_conflicts( $path )
		);

		// Decorate each conflict with its scope and path.
		foreach ( $conflicts as &$c ) {
			$c['scope']      = $scope;
			$c['scope_path'] = $path;
		}
		unset( $c );

		set_transient( $cache_key, $conflicts, self::CACHE_EXPIRATION );

		return $conflicts;
	}

	/**
	 * Check if any conflicts exist (any scope).
	 *
	 * @return bool
	 */
	public function has_conflicts(): bool {
		return ! empty( $this->get_conflicts() );
	}

	/**
	 * Detect conflicts with registered post types.
	 *
	 * @param string $our_path Base path being checked.
	 * @return array<array{type: string, source: string, description: string}>
	 */
	private function detect_post_type_conflicts( string $our_path ): array {
		$conflicts = array();

		$post_types = get_post_types( array( 'public' => true ), 'objects' );

		foreach ( $post_types as $post_type ) {
			// Skip if no rewrite rules.
			if ( empty( $post_type->rewrite ) || ! is_array( $post_type->rewrite ) ) {
				continue;
			}

			$slug = $post_type->rewrite['slug'] ?? $post_type->name;

			// Check for exact match or prefix match.
			if ( $slug === $our_path || strpos( $slug, $our_path . '/' ) === 0 || strpos( $our_path, $slug . '/' ) === 0 ) {
				$plugin_name = self::KNOWN_EVENT_POST_TYPES[ $post_type->name ] ?? $post_type->label;

				$conflicts[] = array(
					'type'        => 'post_type',
					'source'      => $post_type->name,
					'description' => sprintf(
						/* translators: 1: plugin/post type name, 2: conflicting slug */
						__( '%1$s uses the same URL path "/%2$s/".', 'nettertech-events' ),
						$plugin_name,
						$slug
					),
				);
			}
		}

		return $conflicts;
	}

	/**
	 * Detect conflicts with registered rewrite rules.
	 *
	 * Pipeline: get rules -> filter own rules -> find conflicts -> deduplicate.
	 *
	 * @param string $our_path Base path being checked.
	 * @return array<array{type: string, source: string, description: string}>
	 */
	private function detect_rewrite_rule_conflicts( string $our_path ): array {
		$rules = get_option( 'rewrite_rules', array() );

		if ( ! is_array( $rules ) ) {
			return array();
		}

		$rules     = $this->filter_own_rewrite_rules( $rules, $our_path );
		$conflicts = $this->find_pattern_conflicts( $rules, $our_path );
		$conflicts = $this->deduplicate_conflicts_by_source( $conflicts );

		return $conflicts;
	}

	/**
	 * Filter out the plugin's own rewrite rules from a rule set.
	 *
	 * Removes rules whose query strings contain NTE-specific query var prefixes,
	 * and rules that don't match our base path.
	 *
	 * @param array<string, string> $rules    Rewrite rules (pattern => query).
	 * @param string                $our_path Base path being checked.
	 * @return array<string, string> Filtered rules containing only potential conflicts.
	 */
	private function filter_own_rewrite_rules( array $rules, string $our_path ): array {
		$our_patterns = array(
			'nettertech_event',
			'nettertech_events_occurrence',
			'nettertech_events_checkin',
			'nettertech_events_ticket',
			'nettertech_events_space',
			'nettertech_events_archive',
			'nettertech_events_past_archive',
		);

		$filtered = array();

		foreach ( $rules as $pattern => $query ) {
			// Only include rules that match our base path.
			if ( strpos( $pattern, '^' . $our_path ) !== 0 ) {
				continue;
			}

			// Skip our own rules.
			$is_ours = false;
			foreach ( $our_patterns as $our_pattern ) {
				if ( strpos( $query, $our_pattern ) !== false ) {
					$is_ours = true;
					break;
				}
			}

			if ( ! $is_ours ) {
				$filtered[ $pattern ] = $query;
			}
		}

		return $filtered;
	}

	/**
	 * Find conflicts from filtered rewrite rules.
	 *
	 * Builds conflict entries from rules that overlap with our base path.
	 *
	 * @param array<string, string> $rules    Filtered rewrite rules (our own rules removed).
	 * @param string                $our_path Base path being checked.
	 * @return array<array{type: string, source: string, description: string}>
	 */
	private function find_pattern_conflicts( array $rules, string $our_path ): array {
		$conflicts = array();

		foreach ( $rules as $pattern => $query ) {
			$source = $this->identify_rule_source( $query );

			$conflicts[] = array(
				'type'        => 'rewrite_rule',
				'source'      => $source,
				'description' => sprintf(
					/* translators: 1: source identifier, 2: URL pattern */
					__( '%1$s has a rewrite rule matching "/%2$s/...".', 'nettertech-events' ),
					$source,
					$our_path
				),
			);
		}

		return $conflicts;
	}

	/**
	 * Deduplicate conflicts from the same source.
	 *
	 * When multiple rewrite rules from the same plugin conflict, only one
	 * conflict entry is needed per source.
	 *
	 * @param array<array{type: string, source: string, description: string}> $conflicts Raw conflicts.
	 * @return array<array{type: string, source: string, description: string}> Deduplicated conflicts.
	 */
	private function deduplicate_conflicts_by_source( array $conflicts ): array {
		$seen         = array();
		$deduplicated = array();

		foreach ( $conflicts as $conflict ) {
			if ( ! isset( $seen[ $conflict['source'] ] ) ) {
				$seen[ $conflict['source'] ] = true;
				$deduplicated[]              = $conflict;
			}
		}

		return $deduplicated;
	}

	/**
	 * Detect conflicts with WordPress pages.
	 *
	 * @param string $our_path Base path being checked.
	 * @return array<array{type: string, source: string, description: string}>
	 */
	private function detect_page_conflicts( string $our_path ): array {
		$conflicts = array();

		// Check for a page with our slug.
		$page = get_page_by_path( $our_path );

		if ( $page && 'publish' === $page->post_status ) {
			$conflicts[] = array(
				'type'        => 'page',
				'source'      => 'page_' . $page->ID,
				'description' => sprintf(
					/* translators: 1: page title, 2: page ID */
					__( 'A WordPress page "%1$s" (ID: %2$d) uses the same URL path.', 'nettertech-events' ),
					$page->post_title,
					$page->ID
				),
			);
		}

		return $conflicts;
	}

	/**
	 * Identify the source of a rewrite rule from its query string.
	 *
	 * @param string $query Rewrite rule query string.
	 * @return string Identified source name.
	 */
	private function identify_rule_source( string $query ): string {
		// Check for known post types in query.
		foreach ( self::KNOWN_EVENT_POST_TYPES as $post_type => $name ) {
			if ( strpos( $query, 'post_type=' . $post_type ) !== false ) {
				return $name;
			}
			if ( strpos( $query, $post_type . '=' ) !== false ) {
				return $name;
			}
		}

		// Try to extract post_type from query.
		if ( preg_match( '/post_type=([a-z_]+)/', $query, $matches ) ) {
			$post_type = get_post_type_object( $matches[1] );
			if ( $post_type ) {
				return $post_type->label;
			}
			return $matches[1];
		}

		// Fallback to generic identifier.
		return __( 'Another plugin', 'nettertech-events' );
	}

	/**
	 * Show admin notice if conflicts exist.
	 *
	 * @return void
	 */
	public function maybe_show_conflict_notice(): void {
		// Only show on NetterTech Events admin pages or plugins page.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		$show_on_screens = array(
			'toplevel_page_nettertech-events',
			'nettertech-events_page_nettertech-events-settings',
			'plugins',
		);

		// Also show on any nettertech-events admin page.
		$is_nettertech_events_page = strpos( $screen->id, 'nettertech-events' ) !== false;

		if ( ! in_array( $screen->id, $show_on_screens, true ) && ! $is_nettertech_events_page ) {
			return;
		}

		$conflicts = $this->get_conflicts();

		if ( empty( $conflicts ) ) {
			return;
		}

		// Check if user dismissed this notice. Dismissal is keyed by the
		// combined "events_path|spaces_path" string so the notice re-appears
		// after either path changes.
		$dismissed_key = $this->dismissal_key();
		$dismissed     = get_user_meta( get_current_user_id(), 'nettertech_events_path_conflict_dismissed', true );

		if ( $dismissed && $dismissed !== $dismissed_key ) {
			delete_user_meta( get_current_user_id(), 'nettertech_events_path_conflict_dismissed' );
			$dismissed = false;
		}

		if ( $dismissed ) {
			return;
		}

		$this->render_conflict_notice( $conflicts );
	}

	/**
	 * Build the per-path dismissal key.
	 *
	 * @return string
	 */
	private function dismissal_key(): string {
		return PathHelper::get_base_path() . '|' . PathHelper::get_spaces_base_path();
	}

	/**
	 * Render the conflict warning notice.
	 *
	 * Conflicts are grouped by scope (events / spaces) so the notice clearly
	 * tells the admin which base path is colliding.
	 *
	 * @param array<array{type: string, source: string, description: string, scope?: string, scope_path?: string}> $conflicts Detected conflicts.
	 * @return void
	 */
	private function render_conflict_notice( array $conflicts ): void {
		$settings_url = admin_url( 'admin.php?page=nettertech-events-settings' );
		$dismiss_url  = wp_nonce_url(
			add_query_arg( 'nettertech_events_dismiss_conflict', '1' ),
			'dismiss_conflict'
		);

		// Group by scope.
		$grouped = array();
		foreach ( $conflicts as $c ) {
			$scope               = $c['scope'] ?? 'events';
			$grouped[ $scope ][] = $c;
		}

		?>
		<div class="notice notice-warning is-dismissible" id="nettertech-events-path-conflict-notice">
			<p>
				<strong><?php esc_html_e( 'NetterTech Events: URL Path Conflict Detected', 'nettertech-events' ); ?></strong>
			</p>
			<?php foreach ( $grouped as $scope => $scope_conflicts ) : ?>
				<?php
				$scope_path  = $scope_conflicts[0]['scope_path'] ?? '';
				$scope_label = 'spaces' === $scope
					? __( 'spaces', 'nettertech-events' )
					: __( 'events', 'nettertech-events' );
				?>
				<p>
					<?php
					printf(
						/* translators: 1: scope label (events/spaces), 2: current base path */
						esc_html__( 'Your %1$s base path "/%2$s/" conflicts with:', 'nettertech-events' ),
						esc_html( $scope_label ),
						esc_html( $scope_path )
					);
					?>
				</p>
				<ul style="list-style: disc; margin-left: 20px;">
					<?php foreach ( $scope_conflicts as $conflict ) : ?>
						<li><?php echo esc_html( $conflict['description'] ); ?></li>
					<?php endforeach; ?>
				</ul>
			<?php endforeach; ?>
			<p>
				<?php esc_html_e( 'This will cause URL conflicts. Change the conflicting Base Path in settings to resolve.', 'nettertech-events' ); ?>
			</p>
			<p>
				<a href="<?php echo esc_url( $settings_url ); ?>" class="button button-primary">
					<?php esc_html_e( 'Change Base Paths', 'nettertech-events' ); ?>
				</a>
				<a href="<?php echo esc_url( $dismiss_url ); ?>" class="button" style="margin-left: 10px;">
					<?php esc_html_e( 'Dismiss', 'nettertech-events' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Handle dismiss action.
	 *
	 * Call this from admin_init hook.
	 *
	 * @return void
	 */
	public function handle_dismiss(): void {
		if ( ! isset( $_GET['nettertech_events_dismiss_conflict'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ?? '' ) ), 'dismiss_conflict' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Store the combined paths so the notice re-appears if either path changes.
		update_user_meta(
			get_current_user_id(),
			'nettertech_events_path_conflict_dismissed',
			$this->dismissal_key()
		);

		// Redirect to remove query args.
		wp_safe_redirect( remove_query_arg( array( 'nettertech_events_dismiss_conflict', '_wpnonce' ) ) );
		exit;
	}

	/**
	 * Get conflict status for settings page display.
	 *
	 * @param string|null $scope Optional scope filter ('events' or 'spaces').
	 *                           When null, all scopes are aggregated.
	 * @return array{has_conflicts: bool, conflicts: array<array{type: string, source: string, description: string, scope: string, scope_path: string}>, message: string}
	 */
	public function get_status_for_settings( ?string $scope = null ): array {
		$conflicts = $this->get_conflicts();

		if ( null !== $scope ) {
			$conflicts = array_values(
				array_filter(
					$conflicts,
					static fn( array $c ): bool => ( $c['scope'] ?? '' ) === $scope
				)
			);
		}

		if ( empty( $conflicts ) ) {
			return array(
				'has_conflicts' => false,
				'conflicts'     => array(),
				'message'       => __( 'No conflicts detected.', 'nettertech-events' ),
			);
		}

		$sources = array_map(
			static fn ( array $c ): string => $c['source'],
			$conflicts
		);

		return array(
			'has_conflicts' => true,
			'conflicts'     => $conflicts,
			'message'       => sprintf(
				/* translators: %s: comma-separated list of conflicting sources */
				__( 'Conflicts with: %s', 'nettertech-events' ),
				implode( ', ', array_unique( $sources ) )
			),
		);
	}
}
