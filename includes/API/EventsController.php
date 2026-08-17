<?php
/**
 * Events REST API controller.
 *
 * @package NetterTechEvents\API
 */

declare(strict_types=1);

namespace NetterTechEvents\API;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Contracts\CapacityServiceInterface;
use NetterTechEvents\Contracts\OccurrenceRepositoryInterface;
use NetterTechEvents\Contracts\TicketTypeRepositoryInterface;
use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Frontend\OccurrenceAvailabilityPresenter;
use NetterTechEvents\Services\RateLimitService;
use NetterTechEvents\Utilities\ImageHelper;
use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST API controller for events and occurrences.
 */
class EventsController extends WP_REST_Controller {

	/**
	 * Namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'nettertech-events/v1';

	/**
	 * Resource name.
	 *
	 * @var string
	 */
	protected $rest_base = 'events';

	/**
	 * Occurrence repository.
	 *
	 * @var OccurrenceRepositoryInterface
	 */
	private OccurrenceRepositoryInterface $occurrence_repo;

	/**
	 * Ticket type repository.
	 *
	 * @var TicketTypeRepositoryInterface
	 */
	private TicketTypeRepositoryInterface $ticket_type_repo;

	/**
	 * Capacity service.
	 *
	 * @var CapacityServiceInterface
	 */
	private CapacityServiceInterface $capacity_service;

	/**
	 * Rate limit service.
	 *
	 * @var RateLimitService
	 */
	private RateLimitService $rate_limit_service;

	/**
	 * Constructor.
	 *
	 * @param OccurrenceRepositoryInterface $occurrence_repo    Occurrence repository.
	 * @param TicketTypeRepositoryInterface $ticket_type_repo   Ticket type repository.
	 * @param CapacityServiceInterface      $capacity_service   Capacity service.
	 * @param RateLimitService              $rate_limit_service Rate limit service.
	 */
	public function __construct(
		OccurrenceRepositoryInterface $occurrence_repo,
		TicketTypeRepositoryInterface $ticket_type_repo,
		CapacityServiceInterface $capacity_service,
		RateLimitService $rate_limit_service
	) {
		$this->occurrence_repo    = $occurrence_repo;
		$this->ticket_type_repo   = $ticket_type_repo;
		$this->capacity_service   = $capacity_service;
		$this->rate_limit_service = $rate_limit_service;
	}

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/upcoming',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_upcoming' ),
					'permission_callback' => array( $this, 'public_events_permission_check' ),
					'args'                => $this->get_upcoming_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/range',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_range' ),
					'permission_callback' => array( $this, 'public_events_permission_check' ),
					'args'                => $this->get_range_args(),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'public_events_permission_check' ),
					'args'                => array(
						'id' => array(
							'required'          => true,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Occurrences with filtering (for grid/list views).
		register_rest_route(
			$this->namespace,
			'/occurrences',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_occurrences' ),
					'permission_callback' => array( $this, 'public_events_permission_check' ),
					'args'                => $this->get_occurrences_args(),
				),
			)
		);
	}

	/**
	 * Get upcoming events/occurrences.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_upcoming( WP_REST_Request $request ): WP_REST_Response {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$limit = $request->get_param( 'limit' ) ?? 10;

		$occurrences = $this->occurrence_repo->upcoming( (int) $limit );

		$data = array_map( array( $this, 'prepare_occurrence' ), $occurrences );

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_events_upcoming_response', $data, $request );

		return $this->rate_limit_service->add_headers( new WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Get events in date range.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_range( WP_REST_Request $request ): WP_REST_Response {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$start = $request->get_param( 'start' );
		$end   = $request->get_param( 'end' );

		if ( ! $start || ! $end ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array( 'error' => __( 'Start and end dates are required.', 'nettertech-events' ) ),
					400
				)
			);
		}

		if ( strtotime( $start ) > strtotime( $end ) ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array( 'error' => __( 'Start date must be before or equal to end date.', 'nettertech-events' ) ),
					400
				)
			);
		}

		$occurrences = $this->occurrence_repo->in_range( $start, $end );

		$data = array_map( array( $this, 'prepare_occurrence' ), $occurrences );

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_events_range_response', $data, $request );

		return $this->rate_limit_service->add_headers( new WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Get single occurrence.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_item( $request ): WP_REST_Response {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$id = $request->get_param( 'id' );

		$occurrence = $this->occurrence_repo->find_with_event( (int) $id );

		// Public endpoint: only return occurrences whose parent event is published.
		// Drafts and cancelled events are hidden from unauthenticated callers.
		$event = $occurrence ? $occurrence->get_event() : null;
		if ( ! $occurrence || ! $event || ! $event->is_published() ) {
			return $this->rate_limit_service->add_headers(
				new WP_REST_Response(
					array( 'error' => __( 'Occurrence not found.', 'nettertech-events' ) ),
					404
				)
			);
		}

		$data = $this->prepare_occurrence( $occurrence );

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_events_get_response', $data, $request );

		return $this->rate_limit_service->add_headers(
			new WP_REST_Response( $data, 200 )
		);
	}

	/**
	 * Prepare occurrence for response.
	 *
	 * @param \NetterTechEvents\Models\Occurrence $occurrence Occurrence object.
	 * @return array<string, mixed>
	 */
	private function prepare_occurrence( $occurrence ): array {
		$event = $occurrence->get_event();

		$data = array(
			'id'             => $occurrence->id,
			'event_id'       => $occurrence->event_id,
			'start_datetime' => $occurrence->start_datetime,
			'end_datetime'   => $occurrence->end_datetime,
			'all_day'        => $occurrence->all_day,
			'status'         => $occurrence->status,
			'formatted'      => array(
				'date'       => $this->format_date( $occurrence ),
				'time'       => $this->format_time( $occurrence ),
				'date_range' => $this->format_date_range( $occurrence ),
			),
		);

		if ( $event ) {
			$data['event'] = array(
				'id'            => $event->id,
				'title'         => $event->title,
				'slug'          => $event->slug,
				'excerpt'       => $event->excerpt,
				'venue_name'    => $event->venue_name ?? '',
				'venue_address' => $event->venue_address ?? '',
				// Occurrence-resolved: consumers render one card/tooltip per
				// occurrence, so the link and image must be the occurrence's
				// (per-occurrence overrides, occurrence URL), not the series'
				// (NTE-179 / NTE-181).
				'permalink'     => $occurrence->get_url(),
				'image'         => $this->get_image_data( $occurrence->get_featured_image_id() ),
			);
		}

		// Add ticket availability information.
		$data['tickets'] = null !== $occurrence->id
			? $this->get_ticket_availability( $occurrence->id )
			: array(
				'available'   => false,
				'sold_out'    => false,
				'low_stock'   => false,
				'min_price'   => null,
				'max_price'   => null,
				'price_label' => '',
				'total_left'  => null,
				'types'       => array(),
			);

		return $data;
	}

	/**
	 * Get ticket availability for an occurrence.
	 *
	 * @param int $occurrence_id Occurrence ID.
	 * @return array<string, mixed>
	 */
	private function get_ticket_availability( int $occurrence_id ): array {
		$ticket_types = $this->ticket_type_repo->for_occurrence( $occurrence_id );

		if ( empty( $ticket_types ) ) {
			return array(
				'available'   => false,
				'sold_out'    => false,
				'low_stock'   => false,
				'min_price'   => null,
				'max_price'   => null,
				'price_label' => '',
				'total_left'  => null,
				'types'       => array(),
			);
		}

		// Price range comes from the on-sale types only, through the same presenter
		// the PHP card and single-event page use, so the AJAX-paged grid renders the
		// identical string (NTE-215). The `types` list below still enumerates every
		// type for consumers that inspect them.
		$price_range = OccurrenceAvailabilityPresenter::price_range(
			$this->ticket_type_repo->get_on_sale_for_occurrence( $occurrence_id )
		);
		$min_price   = $price_range['min'];
		$max_price   = $price_range['max'];

		$all_sold_out  = true;
		$any_low_stock = false;
		$types_data    = array();

		foreach ( $ticket_types as $ticket_type ) {
			$ticket_type_id = $ticket_type->id;
			if ( null === $ticket_type_id ) {
				continue;
			}

			// Get capacity summary.
			$summary = $this->capacity_service->get_capacity_summary( $ticket_type_id );

			if ( $summary['is_unlimited'] || ! $summary['is_sold_out'] ) {
				$all_sold_out = false;
			}

			if ( $summary['is_low_stock'] && ! $summary['is_sold_out'] ) {
				$any_low_stock = true;
			}

			// Include type details for frontend flexibility.
			$types_data[] = array(
				'id'        => $ticket_type->id,
				'name'      => $ticket_type->name,
				'price'     => $ticket_type->price,
				'available' => $summary['effective_available'],
				'sold_out'  => $summary['is_sold_out'],
				'low_stock' => $summary['is_low_stock'],
				'unlimited' => $summary['is_unlimited'],
			);
		}

		// Seats left in the room, resolved once. Summing each tier's availability counts
		// the same seats once per tier — the failure that reported 638 seats in a
		// 250-seat hall (ADR-019).
		$occurrence_capacity = $this->capacity_service->get_occurrence_capacity( $occurrence_id );
		$total_left          = $occurrence_capacity['total_available'];

		return array(
			'available'   => ! $all_sold_out,
			'sold_out'    => $all_sold_out,
			'low_stock'   => $any_low_stock && ! $all_sold_out,
			'min_price'   => $min_price,
			'max_price'   => $max_price,
			'price_label' => $price_range['label'],
			'total_left'  => $total_left,
			'types'       => $types_data,
		);
	}

	/**
	 * Format occurrence date.
	 *
	 * @param \NetterTechEvents\Models\Occurrence $occurrence Occurrence.
	 * @return string
	 */
	private function format_date( $occurrence ): string {
		$timestamp = strtotime( $occurrence->start_datetime );
		return date_i18n( get_option( 'date_format' ), $timestamp );
	}

	/**
	 * Format occurrence time.
	 *
	 * @param \NetterTechEvents\Models\Occurrence $occurrence Occurrence.
	 * @return string
	 */
	private function format_time( $occurrence ): string {
		if ( $occurrence->all_day ) {
			return __( 'All Day', 'nettertech-events' );
		}

		$start_time = date_i18n( get_option( 'time_format' ), strtotime( $occurrence->start_datetime ) );
		$end_time   = date_i18n( get_option( 'time_format' ), strtotime( $occurrence->end_datetime ) );

		return $start_time . ' - ' . $end_time;
	}

	/**
	 * Format date range for multi-day events.
	 *
	 * @param \NetterTechEvents\Models\Occurrence $occurrence Occurrence.
	 * @return string
	 */
	private function format_date_range( $occurrence ): string {
		$start_date = gmdate( 'Y-m-d', (int) strtotime( $occurrence->start_datetime ) );
		$end_date   = gmdate( 'Y-m-d', (int) strtotime( $occurrence->end_datetime ) );

		if ( $start_date === $end_date ) {
			return $this->format_date( $occurrence );
		}

		$start = date_i18n( get_option( 'date_format' ), strtotime( $occurrence->start_datetime ) );
		$end   = date_i18n( get_option( 'date_format' ), strtotime( $occurrence->end_datetime ) );

		return $start . ' - ' . $end;
	}

	/**
	 * Get image data for an attachment.
	 *
	 * @param int|null $image_id Attachment ID.
	 * @return array<string, mixed>|null
	 */
	private function get_image_data( ?int $image_id ): ?array {
		if ( ! $image_id ) {
			return null;
		}

		$image = ImageHelper::get_attachment_image_src( $image_id, 'medium' );
		$full  = ImageHelper::get_attachment_image_src( $image_id, 'full' );

		if ( ! $image ) {
			return null;
		}

		return array(
			'id'     => $image_id,
			'url'    => $image[0],
			'width'  => $image[1],
			'height' => $image[2],
			'full'   => $full ? $full[0] : $image[0],
			'alt'    => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
		);
	}

	/**
	 * Get item schema.
	 *
	 * Describes the occurrence response shape returned by this controller.
	 *
	 * @return array<string, mixed>
	 */
	public function get_item_schema(): array {
		return array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'occurrence',
			'type'       => 'object',
			'properties' => array(
				'id'             => array(
					'type'        => 'integer',
					'description' => __( 'Unique occurrence identifier.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'event_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Parent event ID.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'start_datetime' => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'Start date and time (MySQL format).', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'end_datetime'   => array(
					'type'        => 'string',
					'format'      => 'date-time',
					'description' => __( 'End date and time (MySQL format).', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'all_day'        => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this is an all-day occurrence.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'status'         => array(
					'type'        => 'string',
					'description' => __( 'Occurrence status.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'formatted'      => array(
					'type'        => 'object',
					'description' => __( 'Localized formatted date/time strings.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'date'       => array(
							'type'     => 'string',
							'readonly' => true,
						),
						'time'       => array(
							'type'     => 'string',
							'readonly' => true,
						),
						'date_range' => array(
							'type'     => 'string',
							'readonly' => true,
						),
					),
				),
				'event'          => array(
					'type'        => array( 'object', 'null' ),
					'description' => __( 'Parent event data.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'id'            => array( 'type' => 'integer' ),
						'title'         => array( 'type' => 'string' ),
						'slug'          => array( 'type' => 'string' ),
						'excerpt'       => array( 'type' => array( 'string', 'null' ) ),
						'venue_name'    => array( 'type' => 'string' ),
						'venue_address' => array( 'type' => 'string' ),
						'permalink'     => array(
							'type'   => 'string',
							'format' => 'uri',
						),
						'image'         => array( 'type' => array( 'object', 'null' ) ),
					),
				),
				'tickets'        => array(
					'type'        => 'object',
					'description' => __( 'Ticket availability summary.', 'nettertech-events' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
					'properties'  => array(
						'available'  => array( 'type' => 'boolean' ),
						'sold_out'   => array( 'type' => 'boolean' ),
						'low_stock'  => array( 'type' => 'boolean' ),
						'min_price'  => array( 'type' => array( 'number', 'null' ) ),
						'max_price'  => array( 'type' => array( 'number', 'null' ) ),
						'total_left' => array( 'type' => array( 'integer', 'null' ) ),
						'types'      => array( 'type' => 'array' ),
					),
				),
			),
		);
	}

	/**
	 * Get arguments for upcoming endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_upcoming_args(): array {
		return array(
			'limit' => array(
				'type'              => 'integer',
				'default'           => 10,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
		);
	}

	/**
	 * Get arguments for range endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_range_args(): array {
		return array(
			'start' => array(
				'type'              => 'string',
				'required'          => true,
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_date_param' ),
			),
			'end'   => array(
				'type'              => 'string',
				'required'          => true,
				'format'            => 'date',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_date_param' ),
			),
		);
	}

	/**
	 * Get occurrences with filtering.
	 *
	 * Supports two modes:
	 * 1. Date range mode (start/end params) - for calendar views
	 * 2. Paginated mode (page/per_page params) - for grid/list views
	 *
	 * @param WP_REST_Request $request Request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @return WP_REST_Response
	 */
	public function get_occurrences( WP_REST_Request $request ): WP_REST_Response {
		$rate_limit_response = $this->check_rate_limit();
		if ( $rate_limit_response ) {
			return $rate_limit_response;
		}

		$start = $request->get_param( 'start' );
		$end   = $request->get_param( 'end' );

		// Date range mode for calendar views.
		if ( $start && $end ) {
			if ( strtotime( $start ) > strtotime( $end ) ) {
				return $this->rate_limit_service->add_headers(
					new WP_REST_Response(
						array( 'error' => __( 'Start date must be before or equal to end date.', 'nettertech-events' ) ),
						400
					)
				);
			}

			$occurrences = $this->occurrence_repo->in_range( $start, $end );
			$data        = array_map( array( $this, 'prepare_occurrence' ), $occurrences );

			/** This filter is documented in includes/Core/Hooks.php */
			$data = apply_filters( 'nettertech_events_rest_occurrences_list_response', $data, $request );

			return $this->rate_limit_service->add_headers( new WP_REST_Response( $data, 200 ) );
		}

		// Paginated mode for grid/list views.
		$page      = (int) ( $request->get_param( 'page' ) ?? 1 );
		$per_page  = (int) ( $request->get_param( 'per_page' ) ?? 12 );
		$category  = $request->get_param( 'category' );
		$tag       = $request->get_param( 'tag' );
		$search    = $request->get_param( 'search' );
		$date_from = $request->get_param( 'date_from' );
		$date_to   = $request->get_param( 'date_to' );
		$upcoming  = $request->get_param( 'upcoming' ) ?? true;
		$past      = $request->get_param( 'past' ) ?? false;

		// Build query args.
		$past_bool = filter_var( $past, FILTER_VALIDATE_BOOLEAN );
		$args      = array(
			'page'     => $page,
			'per_page' => $per_page,
			'upcoming' => $past_bool ? false : filter_var( $upcoming, FILTER_VALIDATE_BOOLEAN ),
			'past'     => $past_bool,
		);

		if ( ! empty( $category ) ) {
			$args['category'] = is_array( $category ) ? array_map( 'absint', $category ) : array( absint( $category ) );
		}

		if ( ! empty( $tag ) ) {
			$args['tag'] = sanitize_text_field( (string) $tag );
		}

		if ( ! empty( $search ) ) {
			$args['search'] = sanitize_text_field( $search );
		}

		if ( ! empty( $date_from ) ) {
			$args['date_from'] = sanitize_text_field( (string) $date_from );
		}

		if ( ! empty( $date_to ) ) {
			$args['date_to'] = sanitize_text_field( (string) $date_to );
		}

		$result = $this->occurrence_repo->get_filtered( $args );

		$data = array(
			'events'      => array_map( array( $this, 'prepare_occurrence' ), $result['items'] ),
			'total'       => $result['total'],
			'total_pages' => $result['total_pages'],
			'page'        => $page,
			'per_page'    => $per_page,
		);

		/** This filter is documented in includes/Core/Hooks.php */
		$data = apply_filters( 'nettertech_events_rest_occurrences_list_response', $data, $request );

		return $this->rate_limit_service->add_headers( new WP_REST_Response( $data, 200 ) );
	}

	/**
	 * Validate a date parameter is a parseable date string.
	 *
	 * @param string          $value   The date value.
	 * @param WP_REST_Request $request The request object.
	 * @phpstan-param WP_REST_Request<array<string, mixed>> $request
	 * @param string          $param   The parameter name.
	 * @return true|\WP_Error True if valid, WP_Error otherwise.
	 */
	public function validate_date_param( string $value, WP_REST_Request $request, string $param ) {
		if ( false === strtotime( $value ) ) {
			return new \WP_Error(
				'rest_invalid_date',
				/* translators: %s: parameter name */
				sprintf( __( 'Invalid date format for %s.', 'nettertech-events' ), $param ),
				array( 'status' => 400 )
			);
		}

		return true;
	}

	/**
	 * Check rate limit for the current request.
	 *
	 * Bypasses rate limiting for authenticated admins.
	 *
	 * @return WP_REST_Response|null Returns 429 response if limited, null otherwise.
	 */
	private function check_rate_limit(): ?WP_REST_Response {
		if ( $this->rate_limit_service->should_bypass() ) {
			return null;
		}

		return $this->rate_limit_service->check_and_increment();
	}

	/**
	 * Permission callback for public events endpoints.
	 *
	 * These endpoints are intentionally public to support:
	 * - Event listing pages (grid, list, calendar views)
	 * - AJAX-powered event browsing and filtering
	 * - Third-party integrations consuming event data
	 *
	 * Security is enforced at the data layer:
	 * - Only published events are returned
	 * - Draft/private events are excluded from all queries
	 * - Rate limiting protects against abuse (see check_rate_limit())
	 *
	 * @since 1.0.0
	 * @return true Always returns true for public access.
	 */
	public function public_events_permission_check(): bool {
		return true;
	}

	/**
	 * Get arguments for occurrences endpoint.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private function get_occurrences_args(): array {
		return array(
			'start'     => array(
				'type'              => 'string',
				'description'       => 'Start date for calendar range (ISO 8601)',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_date_param' ),
			),
			'end'       => array(
				'type'              => 'string',
				'description'       => 'End date for calendar range (ISO 8601)',
				'sanitize_callback' => 'sanitize_text_field',
				'validate_callback' => array( $this, 'validate_date_param' ),
			),
			'page'      => array(
				'type'              => 'integer',
				'default'           => 1,
				'minimum'           => 1,
				'sanitize_callback' => 'absint',
			),
			'per_page'  => array(
				'type'              => 'integer',
				'default'           => 12,
				'minimum'           => 1,
				'maximum'           => 100,
				'sanitize_callback' => 'absint',
			),
			'category'  => array(
				'type'              => array( 'integer', 'array' ),
				'sanitize_callback' => function ( $value ) {
					if ( is_array( $value ) ) {
						return array_map( 'absint', $value );
					}
					return absint( $value );
				},
			),
			'tag'       => array(
				'type'              => 'string',
				'description'       => 'Filter by tag slug or ID',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'search'    => array(
				'type'              => 'string',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_from' => array(
				'type'              => 'string',
				'description'       => 'Filter events starting from this date (Y-m-d)',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'date_to'   => array(
				'type'              => 'string',
				'description'       => 'Filter events up to this date (Y-m-d)',
				'sanitize_callback' => 'sanitize_text_field',
			),
			'upcoming'  => array(
				'type'    => 'boolean',
				'default' => true,
			),
			'past'      => array(
				'type'        => 'boolean',
				'default'     => false,
				'description' => 'Return past events only (overrides upcoming)',
			),
		);
	}
}
