/**
 * Add to Calendar dropdown toggle.
 *
 * @package NetterTechEvents
 */
( function() {
	'use strict';

	function init() {
		document.addEventListener( 'click', handleClick );
		document.addEventListener( 'keydown', handleKeydown );
	}

	function handleClick( event ) {
		var trigger = event.target.closest( '.nte-add-to-calendar__trigger' );

		if ( trigger ) {
			event.preventDefault();
			toggle( trigger );
			return;
		}

		// Close any open menus when clicking outside.
		if ( ! event.target.closest( '.nte-add-to-calendar' ) ) {
			closeAll();
		}
	}

	function handleKeydown( event ) {
		if ( 'Escape' === event.key ) {
			closeAll();
		}
	}

	function toggle( trigger ) {
		var isExpanded = 'true' === trigger.getAttribute( 'aria-expanded' );

		// Close others first.
		closeAll();

		if ( ! isExpanded ) {
			trigger.setAttribute( 'aria-expanded', 'true' );
		}
	}

	function closeAll() {
		var triggers = document.querySelectorAll( '.nte-add-to-calendar__trigger[aria-expanded="true"]' );
		for ( var i = 0; i < triggers.length; i++ ) {
			triggers[ i ].setAttribute( 'aria-expanded', 'false' );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
