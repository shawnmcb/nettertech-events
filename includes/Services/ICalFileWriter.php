<?php
/**
 * Static iCal feed file writer.
 *
 * Renders the published-events calendar feed to a file on disk so the feed
 * can be served as a static file (near-zero PHP per poll) instead of being
 * generated dynamically on every request. See NTE-016.
 *
 * @package NetterTechEvents\Services
 */

declare(strict_types=1);

namespace NetterTechEvents\Services;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\DebugLogger;

/**
 * Writes the calendar feed to wp-content/uploads/nettertech-events/feed.ics.
 *
 * Reuses ICalService::export_calendar_feed() for composition (the file is the
 * same bytes the dynamic endpoint would return, including the NTE-014 RRULE
 * horizon cap). Writes are atomic (temp file + rename) and never throw — a
 * failure is logged and reported via the bool return so callers can fall back
 * to the dynamic endpoint.
 *
 * @since 3.13.0
 */
class ICalFileWriter {

	/**
	 * Upload subdirectory holding the feed file.
	 *
	 * @var string
	 */
	private const SUBDIR = 'nettertech-events';

	/**
	 * Static feed filename.
	 *
	 * @var string
	 */
	private const FILENAME = 'feed.ics';

	/**
	 * Event cap for the static build. High enough to mean "all published
	 * events" without an unbounded query.
	 *
	 * @var int
	 */
	private const FEED_LIMIT = 100000;

	/**
	 * Service used to compose the iCal feed.
	 *
	 * @var ICalService
	 */
	private ICalService $ical_service;

	/**
	 * Constructor.
	 *
	 * @param ICalService $ical_service iCal composition service.
	 */
	public function __construct( ICalService $ical_service ) {
		$this->ical_service = $ical_service;
	}

	/**
	 * Absolute filesystem path of the static feed file.
	 *
	 * Static so the serve layer (Router) can resolve the path without a
	 * container instance; path computation needs no collaborators.
	 *
	 * @return string
	 */
	public static function feed_path(): string {
		$upload = wp_upload_dir();

		return trailingslashit( $upload['basedir'] ) . self::SUBDIR . '/' . self::FILENAME;
	}

	/**
	 * (Re)build the static feed file from current published events.
	 *
	 * Atomic: composes into a temp file in the target directory, then renames
	 * it into place. On any failure the method logs and returns false without
	 * throwing, leaving any existing file untouched.
	 *
	 * @return bool True on success, false on any failure.
	 */
	public function regenerate(): bool {
		$path = self::feed_path();
		$dir  = dirname( $path );

		if ( ! wp_mkdir_p( $dir ) ) {
			DebugLogger::log( 'Static iCal feed: directory not writable: ' . $dir, 'ICalFileWriter' );
			return false;
		}

		try {
			$content = $this->ical_service->export_calendar_feed(
				array(
					'status' => 'published',
					'limit'  => self::FEED_LIMIT,
				)
			);
		} catch ( \Throwable $e ) {
			DebugLogger::exception( $e, 'ICalFileWriter' );
			return false;
		}

		$tmp = $path . '.tmp';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem is not instantiated in the cron context; atomic temp+rename is used to publish the prebuilt feed.
		if ( false === file_put_contents( $tmp, $content ) ) {
			DebugLogger::log( 'Static iCal feed: failed writing temp file: ' . $tmp, 'ICalFileWriter' );
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- atomic publish of the prebuilt feed onto the same filesystem; WP_Filesystem is not instantiated in the cron context.
		if ( ! rename( $tmp, $path ) ) {
			DebugLogger::log( 'Static iCal feed: failed publishing feed file: ' . $path, 'ICalFileWriter' );
			wp_delete_file( $tmp );
			return false;
		}

		return true;
	}
}
