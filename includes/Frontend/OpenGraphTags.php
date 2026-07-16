<?php
/**
 * Open Graph and Twitter Card meta tags for event pages.
 *
 * @package NetterTechEvents\Frontend
 */

declare(strict_types=1);

namespace NetterTechEvents\Frontend;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;
use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Outputs Open Graph and Twitter Card meta tags on single event pages.
 *
 * Enables rich social sharing previews when events are shared on Facebook,
 * Twitter/X, LinkedIn, and other platforms that consume OG metadata.
 *
 * @since 1.6.0
 */
class OpenGraphTags {

	/**
	 * Initialize Open Graph meta tag output.
	 *
	 * @return void
	 */
	public static function init(): void {
		if ( self::seo_plugin_handles_og() ) {
			return;
		}

		add_action( 'wp_head', array( static::class, 'output' ), 4 );
	}

	/**
	 * Output OG and Twitter Card meta tags if on a single event page.
	 *
	 * @return void
	 */
	public static function output(): void {
		$event = Router::get_current_event();
		if ( ! $event || ! $event->is_published() ) {
			return;
		}

		$occurrence = Router::get_current_occurrence();
		$tags       = self::build_tags( $event, $occurrence );

		if ( empty( $tags ) ) {
			return;
		}

		/**
		 * Filter the Open Graph meta tags before output.
		 *
		 * @since 1.0.2
		 *
		 * @param array<string, string>  $tags       Associative array of property => content.
		 * @param Event                  $event      Event model.
		 * @param Occurrence|null        $occurrence Current occurrence (if viewing specific one).
		 */
		$tags = apply_filters( 'nettertech_events_open_graph_tags', $tags, $event, $occurrence );

		if ( empty( $tags ) ) {
			return;
		}

		echo "\n<!-- NetterTech Events Open Graph -->\n";
		foreach ( $tags as $property => $content ) {
			if ( '' === $content ) {
				continue;
			}

			$attr = str_starts_with( $property, 'twitter:' ) ? 'name' : 'property';

			$is_url_property = in_array( $property, array( 'og:image', 'og:url', 'twitter:image' ), true );
			printf(
				'<meta %1$s="%2$s" content="%3$s" />' . "\n",
				esc_attr( $attr ),
				esc_attr( $property ),
				$is_url_property ? esc_url( $content ) : esc_attr( $content )
			);
		}
		echo "<!-- / NetterTech Events Open Graph -->\n";
	}

	/**
	 * Build the Open Graph and Twitter Card tag array.
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Specific occurrence (optional).
	 * @return array<string, string> Associative array of property => content.
	 */
	public static function build_tags( Event $event, ?Occurrence $occurrence = null ): array {
		$title = self::get_title( $event, $occurrence );
		$desc  = self::get_description( $event );
		$url   = self::get_url( $event, $occurrence );
		$image = self::get_image( $event, $occurrence );

		$tags = array(
			'og:type'        => 'event',
			'og:title'       => $title,
			'og:description' => $desc,
			'og:url'         => $url,
			'og:site_name'   => get_bloginfo( 'name' ),
		);

		if ( '' !== $image ) {
			$tags['og:image'] = $image;
		}

		// Event date tags (ISO 8601 format for OG event type).
		$dates = self::get_event_dates( $occurrence );
		if ( '' !== $dates['start'] ) {
			$tags['event:start_time'] = $dates['start'];
		}
		if ( '' !== $dates['end'] ) {
			$tags['event:end_time'] = $dates['end'];
		}

		// Twitter Card tags.
		$tags['twitter:card']        = '' !== $image ? 'summary_large_image' : 'summary';
		$tags['twitter:title']       = $title;
		$tags['twitter:description'] = $desc;

		if ( '' !== $image ) {
			$tags['twitter:image'] = $image;
		}

		return $tags;
	}

	/**
	 * Get the title for OG tags.
	 *
	 * Uses occurrence title_override if available, otherwise event title.
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Occurrence model.
	 * @return string
	 */
	private static function get_title( Event $event, ?Occurrence $occurrence ): string {
		if ( $occurrence && ! empty( $occurrence->title_override ) ) {
			return $occurrence->title_override;
		}

		return $event->title ?? '';
	}

	/**
	 * Get the description for OG tags.
	 *
	 * Uses event excerpt if available, otherwise first 200 chars of description.
	 *
	 * @param Event $event Event model.
	 * @return string
	 */
	private static function get_description( Event $event ): string {
		if ( ! empty( $event->excerpt ) ) {
			return wp_strip_all_tags( $event->excerpt );
		}

		if ( ! empty( $event->description ) ) {
			$stripped = wp_strip_all_tags( $event->description );
			if ( mb_strlen( $stripped ) > 200 ) {
				return mb_substr( $stripped, 0, 197 ) . '...';
			}
			return $stripped;
		}

		return '';
	}

	/**
	 * Get the canonical URL for OG tags.
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Occurrence model.
	 * @return string
	 */
	private static function get_url( Event $event, ?Occurrence $occurrence ): string {
		if ( $occurrence ) {
			$url = $occurrence->get_url();
			if ( $url ) {
				return $url;
			}
		}

		return $event->get_permalink();
	}

	/**
	 * Get the image URL for OG tags.
	 *
	 * Prefers occurrence featured image, falls back to event featured image.
	 *
	 * @param Event           $event      Event model.
	 * @param Occurrence|null $occurrence Occurrence model.
	 * @return string
	 */
	private static function get_image( Event $event, ?Occurrence $occurrence ): string {
		$image_url = null;

		if ( $occurrence ) {
			$image_url = $occurrence->get_featured_image_url( 'large' );
		}

		if ( ! $image_url ) {
			$image_url = $event->get_featured_image_url( 'large' );
		}

		return $image_url ? $image_url : '';
	}

	/**
	 * Get ISO 8601 start/end dates from the current occurrence.
	 *
	 * @param Occurrence|null $occurrence Occurrence model.
	 * @return array{start: string, end: string}
	 */
	private static function get_event_dates( ?Occurrence $occurrence ): array {
		if ( ! $occurrence ) {
			return array(
				'start' => '',
				'end'   => '',
			);
		}

		$tz = new \DateTimeZone( ! empty( $occurrence->timezone ) ? $occurrence->timezone : 'UTC' );

		$start = '';
		if ( ! empty( $occurrence->start_datetime ) ) {
			$dt    = new \DateTime( $occurrence->start_datetime, $tz );
			$start = $dt->format( \DateTime::ATOM );
		}

		$end = '';
		if ( ! empty( $occurrence->end_datetime ) ) {
			$dt  = new \DateTime( $occurrence->end_datetime, $tz );
			$end = $dt->format( \DateTime::ATOM );
		}

		return array(
			'start' => $start,
			'end'   => $end,
		);
	}

	/**
	 * Detect if an SEO plugin is already handling Open Graph output.
	 *
	 * Checks for Yoast SEO and Rank Math — the two most common plugins
	 * that output their own OG tags. Avoids duplicate meta tags.
	 *
	 * @return bool True if an SEO plugin is handling OG tags.
	 */
	private static function seo_plugin_handles_og(): bool {
		// Yoast SEO.
		if ( defined( 'WPSEO_VERSION' ) ) {
			return true;
		}

		// Rank Math.
		if ( defined( 'RANK_MATH_VERSION' ) ) {
			return true;
		}

		// All in One SEO.
		if ( defined( 'AIOSEO_VERSION' ) ) {
			return true;
		}

		// SEOPress.
		if ( defined( 'SEOPRESS_VERSION' ) ) {
			return true;
		}

		return false;
	}
}
