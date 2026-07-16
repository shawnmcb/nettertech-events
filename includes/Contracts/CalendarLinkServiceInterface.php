<?php
/**
 * Calendar Link Service Interface.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Models\Occurrence;

/**
 * Interface for CalendarLinkService implementations.
 *
 * Generates "Add to Calendar" URLs for external calendar services.
 *
 * @since 2.0.0
 * @api
 */
interface CalendarLinkServiceInterface {

	/**
	 * Generate a Google Calendar event creation URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Google Calendar URL.
	 */
	public function google_url( Occurrence $occurrence ): string;

	/**
	 * Generate an Outlook Live calendar event creation URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Outlook Live URL.
	 */
	public function outlook_live_url( Occurrence $occurrence ): string;

	/**
	 * Generate an Outlook 365 calendar event creation URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string Outlook 365 URL.
	 */
	public function outlook_365_url( Occurrence $occurrence ): string;

	/**
	 * Generate the iCal download REST URL.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return string iCal REST endpoint URL.
	 */
	public function ical_url( Occurrence $occurrence ): string;

	/**
	 * Get all calendar links for an occurrence.
	 *
	 * @param Occurrence $occurrence The occurrence.
	 * @return array<string, array{url: string, label: string}> Keyed by provider slug.
	 */
	public function all_links( Occurrence $occurrence ): array;
}
