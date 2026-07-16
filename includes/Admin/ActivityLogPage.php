<?php
/**
 * Activity Log admin page.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Services\ActivityLogService;

/**
 * Admin page for viewing activity logs.
 *
 * Provides OWASP A09 compliance visibility for administrators.
 *
 * @since 0.9.0
 */
class ActivityLogPage {

	/**
	 * Activity log service.
	 *
	 * @var ActivityLogService
	 */
	private ActivityLogService $service;

	/**
	 * Items per page.
	 *
	 * @var int
	 */
	private int $per_page = 25;

	/**
	 * Constructor.
	 *
	 * @param ActivityLogService $service Activity log service.
	 */
	public function __construct( ActivityLogService $service ) {
		$this->service = $service;
	}

	/**
	 * Render the activity log page.
	 *
	 * @return void
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'nettertech-events' ) );
		}

		$filters = $this->get_filters_from_request();
		// Preserve historical (int)-cast semantics: negative values clamp to 1
		// via max(); absint() would flip the sign and break the clamp.
		$page = max( 1, (int) AdminRequest::get_text( 'paged', '1' ) );

		$result = $this->service->get_logs( $page, $this->per_page, $filters );

		$this->render_page( $result, $filters, $page );
	}

	/**
	 * Get filters from request.
	 *
	 * @return array<string, mixed>
	 */
	private function get_filters_from_request(): array {
		$filters = array();

		$action_type = AdminRequest::get_text( 'action_type' );
		if ( '' !== $action_type ) {
			$filters['action'] = $action_type;
		}

		$object_type = AdminRequest::get_text( 'object_type' );
		if ( '' !== $object_type ) {
			$filters['object_type'] = $object_type;
		}

		$user_id = AdminRequest::get_absint( 'user_id' );
		if ( $user_id > 0 ) {
			$filters['user_id'] = $user_id;
		}

		$date_from = AdminRequest::get_text( 'date_from' );
		if ( '' !== $date_from ) {
			$filters['date_from'] = $date_from;
		}

		$date_to = AdminRequest::get_text( 'date_to' );
		if ( '' !== $date_to ) {
			$filters['date_to'] = $date_to;
		}

		$search = AdminRequest::get_text( 's' );
		if ( '' !== $search ) {
			$filters['search'] = $search;
		}

		return $filters;
	}

	/**
	 * Render the page HTML.
	 *
	 * @param array<string, mixed> $result  Query result.
	 * @param array<string, mixed> $filters Active filters.
	 * @param int                  $page    Current page.
	 * @return void
	 */
	private function render_page( array $result, array $filters, int $page ): void {
		$action_types = $this->service->get_action_types();
		$object_types = $this->service->get_object_types();
		?>
		<div class="wrap">
			<?php Branding::render_header(); ?>
			<h1><?php esc_html_e( 'Activity Log', 'nettertech-events' ); ?></h1>

			<p class="description">
				<?php esc_html_e( 'Track administrative actions for security monitoring (OWASP A09).', 'nettertech-events' ); ?>
			</p>

			<?php $this->render_filters( $filters, $action_types, $object_types ); ?>

			<?php if ( empty( $result['items'] ) ) : ?>
				<div class="notice notice-info">
					<p><?php esc_html_e( 'No activity log entries found.', 'nettertech-events' ); ?></p>
				</div>
			<?php else : ?>
				<?php $this->render_table( $result['items'] ); ?>
				<?php $this->render_pagination( $result['total'], $result['pages'], $page ); ?>
			<?php endif; ?>
		</div>

		<?php
	}

	/**
	 * Render filter form.
	 *
	 * @param array<string, mixed> $filters      Active filters.
	 * @param array<string>        $action_types Available action types.
	 * @param array<string>        $object_types Available object types.
	 * @return void
	 */
	private function render_filters( array $filters, array $action_types, array $object_types ): void {
		$base_url = admin_url( 'admin.php?page=nettertech-events-activity-log' );
		?>
		<form method="get" action="<?php echo esc_url( $base_url ); ?>" class="nte-activity-log-filters">
			<input type="hidden" name="page" value="nettertech-events-activity-log">

			<div class="filter-row">
				<div>
					<label for="action_type"><?php esc_html_e( 'Action', 'nettertech-events' ); ?></label>
					<select name="action_type" id="action_type">
						<option value=""><?php esc_html_e( 'All Actions', 'nettertech-events' ); ?></option>
						<?php foreach ( $action_types as $action ) : ?>
							<option value="<?php echo esc_attr( $action ); ?>" <?php selected( $filters['action'] ?? '', $action ); ?>>
								<?php echo esc_html( ucfirst( str_replace( '_', ' ', $action ) ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<label for="object_type"><?php esc_html_e( 'Object Type', 'nettertech-events' ); ?></label>
					<select name="object_type" id="object_type">
						<option value=""><?php esc_html_e( 'All Types', 'nettertech-events' ); ?></option>
						<?php foreach ( $object_types as $type ) : ?>
							<option value="<?php echo esc_attr( $type ); ?>" <?php selected( $filters['object_type'] ?? '', $type ); ?>>
								<?php echo esc_html( ucfirst( $type ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</div>

				<div>
					<label for="date_from"><?php esc_html_e( 'From', 'nettertech-events' ); ?></label>
					<input type="date" name="date_from" id="date_from"
						value="<?php echo esc_attr( $filters['date_from'] ?? '' ); ?>">
				</div>

				<div>
					<label for="date_to"><?php esc_html_e( 'To', 'nettertech-events' ); ?></label>
					<input type="date" name="date_to" id="date_to"
						value="<?php echo esc_attr( $filters['date_to'] ?? '' ); ?>">
				</div>

				<div>
					<label for="s"><?php esc_html_e( 'Search', 'nettertech-events' ); ?></label>
					<input type="search" name="s" id="s"
						value="<?php echo esc_attr( $filters['search'] ?? '' ); ?>"
						placeholder="<?php esc_attr_e( 'Search object name...', 'nettertech-events' ); ?>">
				</div>

				<div>
					<button type="submit" class="button"><?php esc_html_e( 'Filter', 'nettertech-events' ); ?></button>
					<a href="<?php echo esc_url( $base_url ); ?>" class="button"><?php esc_html_e( 'Reset', 'nettertech-events' ); ?></a>
				</div>
			</div>
		</form>
		<?php
	}

	/**
	 * Render the log table.
	 *
	 * @param array<\NetterTechEvents\Models\ActivityLog> $items Log entries.
	 * @return void
	 */
	private function render_table( array $items ): void {
		?>
		<table class="wp-list-table widefat fixed striped nte-activity-log-table">
			<thead>
				<tr>
					<th class="column-time"><?php esc_html_e( 'Time', 'nettertech-events' ); ?></th>
					<th class="column-user"><?php esc_html_e( 'User', 'nettertech-events' ); ?></th>
					<th class="column-action"><?php esc_html_e( 'Action', 'nettertech-events' ); ?></th>
					<th class="column-type"><?php esc_html_e( 'Type', 'nettertech-events' ); ?></th>
					<th><?php esc_html_e( 'Description', 'nettertech-events' ); ?></th>
					<th class="column-ip"><?php esc_html_e( 'IP Address', 'nettertech-events' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $log ) : ?>
					<tr>
						<td>
							<span title="<?php echo esc_attr( $log->created_at ); ?>">
								<?php echo esc_html( $log->get_formatted_time() ); ?>
							</span>
						</td>
						<td>
							<?php if ( $log->user_id ) : ?>
								<?php
								$user_link = get_edit_user_link( $log->user_id );
								if ( $user_link ) :
									?>
									<a href="<?php echo esc_url( $user_link ); ?>">
										<?php echo esc_html( $log->get_username() ); ?>
									</a>
								<?php else : ?>
									<?php echo esc_html( $log->get_username() ); ?>
								<?php endif; ?>
							<?php else : ?>
								<em><?php echo esc_html( $log->get_username() ); ?></em>
							<?php endif; ?>
						</td>
						<td>
							<span class="action-badge action-<?php echo esc_attr( $log->action ); ?>">
								<?php echo esc_html( ucfirst( str_replace( '_', ' ', $log->action ) ) ); ?>
							</span>
						</td>
						<td><?php echo esc_html( ucfirst( $log->object_type ) ); ?></td>
						<td>
							<?php echo esc_html( $log->get_description() ); ?>
							<?php if ( $log->object_id ) : ?>
								<small>(#<?php echo esc_html( (string) $log->object_id ); ?>)</small>
							<?php endif; ?>
						</td>
						<td>
							<code><?php echo esc_html( $log->ip_address ?? '-' ); ?></code>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render pagination.
	 *
	 * @param int $total Total items.
	 * @param int $pages Total pages.
	 * @param int $page  Current page.
	 * @return void
	 */
	private function render_pagination( int $total, int $pages, int $page ): void {
		if ( $pages <= 1 ) {
			return;
		}

		$page_links = paginate_links(
			array(
				'base'      => add_query_arg( 'paged', '%#%' ),
				'format'    => '',
				'prev_text' => '&laquo;',
				'next_text' => '&raquo;',
				'total'     => $pages,
				'current'   => $page,
			)
		);

		?>
		<div class="tablenav bottom">
			<div class="tablenav-pages">
				<span class="displaying-num">
					<?php
					printf(
						/* translators: %s: number of items */
						esc_html( _n( '%s item', '%s items', $total, 'nettertech-events' ) ),
						esc_html( number_format_i18n( $total ) )
					);
					?>
				</span>
				<?php echo wp_kses_post( $page_links ); ?>
			</div>
		</div>
		<?php
	}
}
