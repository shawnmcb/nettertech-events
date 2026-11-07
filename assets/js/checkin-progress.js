/**
 * Sticky check-in progress row (NTE-379).
 *
 * Redraws every progress row on the page from a server payload. The figures
 * always come from the check-in response (CheckInProgress::to_array()); this
 * script never counts rows, so search, pagination and filters cannot change
 * the denominator.
 *
 * Screens dispatch:
 *
 *     document.dispatchEvent( new CustomEvent( 'nettertech-events:check-in-progress', {
 *         detail: { progress: response.progress },
 *     } ) );
 *
 * with `progress: null` (or missing) when a check-in request failed, which
 * keeps the last confirmed figure but marks it out of date.
 *
 * No live region: the screens already announce the check-in action, and the
 * row must not interrupt scanning with its own announcements.
 *
 * @since 1.5.3
 * @package NetterTechEvents
 */
( function() {
	'use strict';

	const EVENT_NAME = 'nettertech-events:check-in-progress';

	/**
	 * Redraw one row from a confirmed payload.
	 *
	 * @param {HTMLElement} row      Progress row element.
	 * @param {Object}      progress CheckInProgress::to_array() payload.
	 */
	function render( row, progress ) {
		const meter = row.querySelector( '.nte-checkin-progress__meter' );
		const percentEl = row.querySelector( '.nte-checkin-progress__percent' );
		const fill = row.querySelector( '.nte-checkin-progress__fill' );
		const status = row.querySelector( '.nte-checkin-progress__status' );

		let state = 'partial';
		if ( progress.empty ) {
			state = 'empty';
		} else if ( progress.complete ) {
			state = 'complete';
		}

		row.dataset.state = state;
		delete row.dataset.stale;

		if ( percentEl ) {
			percentEl.textContent = progress.percentLabel;
		}

		if ( fill ) {
			const percent = Number.isInteger( progress.percent ) ? progress.percent : 0;
			fill.style.setProperty( '--nte-checkin-progress-value', percent + '%' );
		}

		if ( meter ) {
			if ( Number.isInteger( progress.percent ) ) {
				meter.setAttribute( 'aria-valuenow', String( progress.percent ) );
			} else {
				meter.removeAttribute( 'aria-valuenow' );
			}
			meter.dataset.description = progress.description;
			meter.setAttribute( 'aria-valuetext', progress.description );
		}

		if ( status ) {
			status.textContent = progress.empty ? progress.description : '';
		}
	}

	/**
	 * Keep the last confirmed figure but say it is out of date.
	 *
	 * @param {HTMLElement} row Progress row element.
	 */
	function markStale( row ) {
		const meter = row.querySelector( '.nte-checkin-progress__meter' );
		const status = row.querySelector( '.nte-checkin-progress__status' );
		const staleText = row.dataset.staleText || '';

		row.dataset.stale = 'true';

		if ( status ) {
			status.textContent = staleText;
		}

		if ( meter ) {
			const description = meter.dataset.description || '';
			meter.setAttribute( 'aria-valuetext', description ? description + '. ' + staleText : staleText );
		}
	}

	/**
	 * Whether a payload has the fields render() draws from.
	 *
	 * @param {*} progress Candidate payload.
	 * @return {boolean} True when the payload is usable.
	 */
	function isConfirmed( progress ) {
		return !! progress &&
			'object' === typeof progress &&
			'string' === typeof progress.percentLabel &&
			'string' === typeof progress.description;
	}

	document.addEventListener( EVENT_NAME, function( event ) {
		const progress = event.detail ? event.detail.progress : null;
		const confirmed = isConfirmed( progress );

		document.querySelectorAll( '[data-nte-checkin-progress]' ).forEach( function( row ) {
			if ( confirmed ) {
				render( row, progress );
			} else {
				markStale( row );
			}
		} );
	} );
}() );
