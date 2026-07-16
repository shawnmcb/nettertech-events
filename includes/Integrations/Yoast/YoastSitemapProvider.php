<?php
/**
 * Yoast SEO Sitemap Provider for NTE events.
 *
 * @package NetterTechEvents\Integrations\Yoast
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\Yoast;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\PathHelper;

/**
 * Provides event URLs to Yoast's sitemap system.
 *
 * Implements the WPSEO_Sitemap_Provider interface to register NTE events
 * as a custom sitemap type within Yoast's sitemap index.
 *
 * @since 2.1.0
 * @api
 */
class YoastSitemapProvider implements \WPSEO_Sitemap_Provider {

	/**
	 * Sitemap type identifier.
	 *
	 * @var string
	 */
	private const TYPE = 'nettertech-events';

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
		$this->db                = $db;
		$this->events_table      = $db->prefix . 'nettertech_events_events';
		$this->occurrences_table = $db->prefix . 'nettertech_events_occurrences';
	}

	/**
	 * Check if this provider handles the given sitemap type.
	 *
	 * @param string $type Sitemap type string.
	 * @return bool
	 */
	public function handles_type( $type ): bool {
		return self::TYPE === $type;
	}

	/**
	 * Get sitemap index links for this provider.
	 *
	 * Returns entries for the sitemap index page, one per sitemap page.
	 *
	 * @param int $max_entries Maximum entries per sitemap page.
	 * @return array<int, array<string, string|false>> Index link entries.
	 */
	public function get_index_links( $max_entries ): array {
		$total = $this->get_total_url_count();

		if ( 0 === $total ) {
			return array();
		}

		$pages = (int) ceil( $total / $max_entries );
		$links = array();

		$last_modified = $this->get_last_modified_date();

		for ( $page = 1; $page <= $pages; $page++ ) {
			$link = array(
				'loc' => self::TYPE . '-sitemap' . ( $page > 1 ? $page : '' ) . '.xml',
			);

			if ( $last_modified ) {
				$link['lastmod'] = $last_modified;
			}

			$links[] = $link;
		}

		return $links;
	}

	/**
	 * Get sitemap links for a given page.
	 *
	 * @param string $type         Sitemap type.
	 * @param int    $max_entries  Maximum entries per page.
	 * @param int    $current_page Current page number (1-indexed).
	 * @return array<int, array<string, mixed>> Sitemap URL entries.
	 */
	public function get_sitemap_links( $type, $max_entries, $current_page ): array {
		$offset = ( $current_page - 1 ) * $max_entries;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
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
			$max_entries,
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
				$entry['mod'] = $row->updated_at;
			}

			$url_list[] = $entry;
		}

		return $url_list;
	}

	/**
	 * Get the total count of URLs across events and occurrences.
	 *
	 * @return int
	 */
	private function get_total_url_count(): int {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names from $wpdb->prefix.
		return (int) $this->db->get_var(
			"SELECT
				(SELECT COUNT(*) FROM {$this->events_table} WHERE status = 'published')
				+
				(SELECT COUNT(*) FROM {$this->occurrences_table} o
				 INNER JOIN {$this->events_table} e ON e.id = o.event_id
				 WHERE e.status = 'published' AND e.event_type = 'recurring' AND o.status != 'cancelled'
				)"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Get the most recent modification date across all published events.
	 *
	 * @return string|false ISO 8601 date string or false if no events exist.
	 */
	private function get_last_modified_date() {
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from $wpdb->prefix.
		$date = $this->db->get_var(
			"SELECT MAX(updated_at) FROM {$this->events_table} WHERE status = 'published'"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( ! $date ) {
			return false;
		}

		try {
			return ( new \DateTime( $date, wp_timezone() ) )->format( DATE_W3C );
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
