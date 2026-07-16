<?php
/**
 * WordPress Core Sitemap Provider for events.
 *
 * @package NetterTechEvents\SEO
 */

declare(strict_types=1);

namespace NetterTechEvents\SEO;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\PathHelper;

/**
 * Provides event URLs to the WordPress core sitemaps system.
 *
 * Queries the nettertech_events_events and nettertech_events_occurrences custom tables to generate
 * sitemap entries for all published events and their occurrences.
 *
 * @since 1.6.0
 */
class EventSitemapProvider extends \WP_Sitemaps_Provider {

	/**
	 * Database instance.
	 *
	 * @var \wpdb
	 */
	private \wpdb $db;

	/**
	 * Events table name.
	 *
	 * @var string
	 */
	private string $events_table;

	/**
	 * Occurrences table name.
	 *
	 * @var string
	 */
	private string $occurrences_table;

	/**
	 * Constructor.
	 *
	 * @param \wpdb $db WordPress database instance.
	 */
	public function __construct( \wpdb $db ) {
		$this->name              = 'nettertechevents';
		$this->object_type       = 'nettertech_event';
		$this->db                = $db;
		$this->events_table      = $db->prefix . 'nettertech_events_events';
		$this->occurrences_table = $db->prefix . 'nettertech_events_occurrences';
	}

	/**
	 * Get a list of event URLs for the sitemap.
	 *
	 * Includes published event series URLs plus individual occurrence URLs
	 * for recurring events.
	 *
	 * @param int    $page_num       Page number (1-indexed).
	 * @param string $object_subtype Not used.
	 * @return array<int, array<string, string>> Array of URL entries.
	 */
	public function get_url_list( $page_num, $object_subtype = '' ): array {
		$max_urls = wp_sitemaps_get_max_urls( $this->object_type );
		$offset   = ( $page_num - 1 ) * $max_urls;

		// Build a UNION query: published event URLs + recurring occurrence URLs.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are constructed from $wpdb->prefix.
		$sql = $this->db->prepare(
			"(SELECT e.slug, e.updated_at, 'event' AS entry_type, NULL AS start_datetime, NULL AS timezone
			  FROM {$this->events_table} e
			  WHERE e.status = 'published'
			)
			UNION ALL
			(SELECT e.slug, o.updated_at, 'occurrence' AS entry_type, o.start_datetime, o.timezone
			  FROM {$this->occurrences_table} o
			  INNER JOIN {$this->events_table} e ON e.id = o.event_id
			  WHERE e.status = 'published'
			    AND e.event_type = 'recurring'
			    AND o.status != 'cancelled'
			)
			ORDER BY slug ASC, entry_type ASC, start_datetime ASC
			LIMIT %d OFFSET %d",
			$max_urls,
			$offset
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$results = $this->db->get_results( $sql );

		if ( ! $results ) {
			return array();
		}

		$url_list = array();

		foreach ( $results as $row ) {
			if ( 'event' === $row->entry_type ) {
				$loc = PathHelper::get_event_url( $row->slug );
			} else {
				$dt_slug = ( new \DateTime( $row->start_datetime, new \DateTimeZone( $row->timezone ?? 'UTC' ) ) )
					->format( 'Y-m-d-Hi' );
				$loc     = PathHelper::get_occurrence_url( $row->slug, $dt_slug );
			}

			$entry = array( 'loc' => $loc );

			if ( ! empty( $row->updated_at ) ) {
				$entry['lastmod'] = ( new \DateTime( $row->updated_at, wp_timezone() ) )->format( DATE_W3C );
			}

			$url_list[] = $entry;
		}

		return $url_list;
	}

	/**
	 * Get the maximum number of sitemap pages.
	 *
	 * @param string $object_subtype Not used.
	 * @return int Number of pages.
	 */
	public function get_max_num_pages( $object_subtype = '' ): int {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		$total = (int) $this->db->get_var(
			"SELECT
				(SELECT COUNT(*) FROM {$this->events_table} WHERE status = 'published')
				+
				(SELECT COUNT(*) FROM {$this->occurrences_table} o
				 INNER JOIN {$this->events_table} e ON e.id = o.event_id
				 WHERE e.status = 'published' AND e.event_type = 'recurring' AND o.status != 'cancelled'
				)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (int) ceil( $total / wp_sitemaps_get_max_urls( $this->object_type ) );
	}

	/**
	 * Get object subtypes.
	 *
	 * Events do not use subtypes.
	 *
	 * @return array<string, mixed> Empty array.
	 */
	public function get_object_subtypes(): array {
		return array();
	}
}
