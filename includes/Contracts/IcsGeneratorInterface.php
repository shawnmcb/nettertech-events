<?php
/**
 * ICS calendar-file generator contract.
 *
 * @package NetterTechEvents\Contracts
 */

declare(strict_types=1);

namespace NetterTechEvents\Contracts;

use NetterTechEvents\Models\Event;
use NetterTechEvents\Models\Occurrence;

/**
 * Generates `.ics` calendar files for email attachments.
 *
 * Extracted from EmailTemplateRenderer (NTE-107) so ICS generation is a single
 * focused responsibility, independent of email body rendering.
 *
 * @since 1.0.3
 */
interface IcsGeneratorInterface {

	/**
	 * Generate an ICS calendar file for a set of tickets (one VEVENT per occurrence).
	 *
	 * @param \NetterTechEvents\Models\Ticket[] $tickets Tickets.
	 * @return string|false Path to a temp ICS file, or false on failure.
	 */
	public function generate_ics_file( array $tickets ): string|false;

	/**
	 * Generate an ICS calendar file for a single occurrence (reminder emails).
	 *
	 * @param Occurrence $occurrence Occurrence.
	 * @param Event      $event      Event.
	 * @return string|false Path to a temp ICS file, or false on failure.
	 */
	public function generate_occurrence_ics( Occurrence $occurrence, Event $event ): string|false;
}
