/**
 * NTE Multi-Select Disclosure Widget
 *
 * Enhances native `<select multiple>` controls into accessible button-disclosure
 * + listbox widgets. Keeps the native select as the form-submit source of truth
 * so multi-value POST/GET serialization works unchanged.
 *
 * Pattern: WAI-ARIA Authoring Practices 1.2 — Listbox with multi-select.
 *   https://www.w3.org/WAI/ARIA/apg/patterns/listbox/
 *
 * Button text reflects state:
 *   - 0 selected   -> the label (e.g., "Category")
 *   - 1 selected   -> the selected option text (e.g., "Live Performance")
 *   - 2+ selected  -> count ("3 selected")
 *
 * Keyboard:
 *   - Tab focuses the trigger; Enter / Space / ArrowDown open the listbox.
 *   - In open state: ArrowUp/Down move active option; Enter / Space toggle;
 *     Escape closes and returns focus to the trigger; Tab closes naturally.
 *   - Outside click closes the listbox.
 *
 * Announces selection changes via wp.a11y.speak when available.
 *
 * Activation: any <select multiple> with class `nte-multiselect-source` and
 * a `data-nte-multiselect-label` attribute providing the static label text.
 *
 * @package NetterTechEvents
 * @since 1.0.4
 */

( function() {
	'use strict';

	/**
	 * Compute the trigger label based on current selections.
	 *
	 * @param {string[]} selectedValues   Array of selected option `value` strings.
	 * @param {Object}   optionLabelMap   Map from value to display text.
	 * @param {string}   fallbackLabel    Label when nothing is selected.
	 * @param {string}   countFormat      Format with `{n}` placeholder for multi-count.
	 * @return {string} Display text for the trigger.
	 */
	function computeTriggerText( selectedValues, optionLabelMap, fallbackLabel, countFormat ) {
		if ( selectedValues.length === 0 ) {
			return fallbackLabel;
		}
		if ( selectedValues.length === 1 ) {
			return optionLabelMap[ selectedValues[ 0 ] ] || fallbackLabel;
		}
		return countFormat.replace( '{n}', String( selectedValues.length ) );
	}

	/**
	 * Speak via wp.a11y.speak if available.
	 *
	 * @param {string} message  Text to announce.
	 * @param {string} priority `polite` or `assertive`.
	 */
	function announce( message, priority ) {
		if ( window.wp && window.wp.a11y && typeof window.wp.a11y.speak === 'function' ) {
			window.wp.a11y.speak( message, priority || 'polite' );
		}
	}

	/**
	 * Enhance one native <select multiple> into the disclosure widget.
	 *
	 * @param {HTMLSelectElement} nativeSelect The select to enhance.
	 */
	function enhance( nativeSelect ) {
		if ( nativeSelect.dataset.nteMultiselectInit === 'true' ) {
			return;
		}
		nativeSelect.dataset.nteMultiselectInit = 'true';

		var fallbackLabel = nativeSelect.dataset.nteMultiselectLabel || 'Filter';
		// Translator-aware count format. Plain English fallback when localization
		// payload isn't wired; admins can override via the `nte_multiselect_l10n`
		// global if needed.
		var l10n = window.nteMultiselectL10n || {};
		var countFormat = l10n.countFormat || '{n} selected';

		// Build option label map for trigger-text computation.
		var optionLabelMap = {};
		Array.prototype.forEach.call( nativeSelect.options, function( opt ) {
			optionLabelMap[ opt.value ] = opt.textContent.trim();
		} );

		// Wrap the select in a container.
		var wrapper = document.createElement( 'div' );
		wrapper.className = 'nte-multiselect';
		nativeSelect.parentNode.insertBefore( wrapper, nativeSelect );
		wrapper.appendChild( nativeSelect );

		// Mark native select as hidden from a11y tree (custom UI takes over),
		// but keep it in the form for submission. Visually-hidden via class.
		nativeSelect.setAttribute( 'aria-hidden', 'true' );
		nativeSelect.setAttribute( 'tabindex', '-1' );
		nativeSelect.classList.add( 'nte-multiselect__native' );
		nativeSelect.classList.remove( 'nte-filters__select' );
		// Drop the size attribute so the underlying box doesn't reserve list-box height.
		nativeSelect.removeAttribute( 'size' );

		// Build the trigger button.
		var trigger = document.createElement( 'button' );
		trigger.type = 'button';
		trigger.className = 'nte-multiselect__trigger';
		trigger.setAttribute( 'aria-haspopup', 'listbox' );
		trigger.setAttribute( 'aria-expanded', 'false' );
		var listboxId = ( nativeSelect.id || 'nte-multiselect' ) + '-listbox';
		trigger.setAttribute( 'aria-controls', listboxId );

		var triggerLabelSpan = document.createElement( 'span' );
		triggerLabelSpan.className = 'nte-multiselect__label';
		trigger.appendChild( triggerLabelSpan );

		var caret = document.createElement( 'span' );
		caret.className = 'nte-multiselect__caret';
		caret.setAttribute( 'aria-hidden', 'true' );
		trigger.appendChild( caret );

		wrapper.appendChild( trigger );

		// Build the listbox popup.
		var listbox = document.createElement( 'ul' );
		listbox.id = listboxId;
		listbox.className = 'nte-multiselect__listbox';
		listbox.setAttribute( 'role', 'listbox' );
		listbox.setAttribute( 'aria-multiselectable', 'true' );
		listbox.setAttribute( 'aria-label', fallbackLabel );
		listbox.hidden = true;

		Array.prototype.forEach.call( nativeSelect.options, function( opt, index ) {
			var li = document.createElement( 'li' );
			li.setAttribute( 'role', 'option' );
			li.setAttribute( 'aria-selected', opt.selected ? 'true' : 'false' );
			li.setAttribute( 'data-value', opt.value );
			li.id = listboxId + '-opt-' + index;
			li.className = 'nte-multiselect__option';

			var checkmark = document.createElement( 'span' );
			checkmark.className = 'nte-multiselect__option-check';
			checkmark.setAttribute( 'aria-hidden', 'true' );
			li.appendChild( checkmark );

			var labelSpan = document.createElement( 'span' );
			labelSpan.className = 'nte-multiselect__option-label';
			labelSpan.textContent = opt.textContent;
			li.appendChild( labelSpan );

			listbox.appendChild( li );
		} );

		wrapper.appendChild( listbox );

		// State: current active option index (for keyboard navigation).
		var activeIndex = -1;
		var options = listbox.querySelectorAll( '.nte-multiselect__option' );

		// Defer the `change` dispatch (and the downstream fetch + re-render it
		// triggers) until the listbox closes. Without this, every option toggle
		// caused a layout shift mid-selection. The native select still tracks
		// each toggle synchronously, so form submits during the open state
		// capture the correct selection set.
		var pendingChange = false;

		function getSelectedValues() {
			var values = [];
			Array.prototype.forEach.call( nativeSelect.options, function( opt ) {
				if ( opt.selected ) {
					values.push( opt.value );
				}
			} );
			return values;
		}

		function updateTrigger() {
			var selected = getSelectedValues();
			var text = computeTriggerText( selected, optionLabelMap, fallbackLabel, countFormat );
			triggerLabelSpan.textContent = text;
			// State class for selected indicator on the trigger itself.
			if ( selected.length > 0 ) {
				trigger.classList.add( 'nte-multiselect__trigger--has-selection' );
			} else {
				trigger.classList.remove( 'nte-multiselect__trigger--has-selection' );
			}
		}

		function toggleOption( index ) {
			if ( index < 0 || index >= options.length ) {
				return;
			}
			var li = options[ index ];
			var value = li.getAttribute( 'data-value' );
			var nativeOpt = nativeSelect.querySelector( 'option[value="' + CSS.escape( value ) + '"]' );
			if ( ! nativeOpt ) {
				return;
			}
			nativeOpt.selected = ! nativeOpt.selected;
			li.setAttribute( 'aria-selected', nativeOpt.selected ? 'true' : 'false' );
			// Deferred: do not dispatch `change` here. close() flushes a single
			// change event after the user finishes toggling, preventing the
			// downstream events list from re-fetching + relayout mid-selection.
			pendingChange = true;
			updateTrigger();
			announce(
				nativeOpt.selected
					? ( optionLabelMap[ value ] + ', selected' )
					: ( optionLabelMap[ value ] + ', unselected' ),
				'polite'
			);
		}

		function setActiveOption( index ) {
			if ( activeIndex >= 0 && options[ activeIndex ] ) {
				options[ activeIndex ].classList.remove( 'nte-multiselect__option--active' );
			}
			activeIndex = index;
			if ( index >= 0 && options[ index ] ) {
				options[ index ].classList.add( 'nte-multiselect__option--active' );
				listbox.setAttribute( 'aria-activedescendant', options[ index ].id );
				// Scroll into view if needed.
				options[ index ].scrollIntoView( { block: 'nearest' } );
			} else {
				listbox.removeAttribute( 'aria-activedescendant' );
			}
		}

		function open() {
			if ( ! listbox.hidden ) {
				return;
			}
			listbox.hidden = false;
			trigger.setAttribute( 'aria-expanded', 'true' );
			wrapper.classList.add( 'nte-multiselect--open' );
			// Initialize active option to first selected or first option.
			var firstSelected = Array.prototype.findIndex.call( options, function( li ) {
				return li.getAttribute( 'aria-selected' ) === 'true';
			} );
			setActiveOption( firstSelected >= 0 ? firstSelected : 0 );
		}

		function close() {
			if ( listbox.hidden ) {
				return;
			}
			listbox.hidden = true;
			trigger.setAttribute( 'aria-expanded', 'false' );
			wrapper.classList.remove( 'nte-multiselect--open' );
			setActiveOption( -1 );
			// Flush a single deferred `change` event if the user toggled
			// any options during this open cycle. Downstream filter-submission
			// JS sees one event instead of N, so the events list re-renders
			// once at the natural break instead of per-toggle.
			if ( pendingChange ) {
				pendingChange = false;
				nativeSelect.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			}
		}

		function toggleOpen() {
			if ( listbox.hidden ) {
				open();
			} else {
				close();
			}
		}

		// Wire trigger interactions.
		trigger.addEventListener( 'click', function() {
			toggleOpen();
		} );

		trigger.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'ArrowDown' || e.key === 'Down' ) {
				e.preventDefault();
				open();
			} else if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				toggleOpen();
			}
		} );

		// Listbox keyboard navigation.
		listbox.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'Escape' || e.key === 'Esc' ) {
				e.preventDefault();
				close();
				trigger.focus();
			} else if ( e.key === 'ArrowDown' || e.key === 'Down' ) {
				e.preventDefault();
				setActiveOption( Math.min( activeIndex + 1, options.length - 1 ) );
			} else if ( e.key === 'ArrowUp' || e.key === 'Up' ) {
				e.preventDefault();
				setActiveOption( Math.max( activeIndex - 1, 0 ) );
			} else if ( e.key === 'Home' ) {
				e.preventDefault();
				setActiveOption( 0 );
			} else if ( e.key === 'End' ) {
				e.preventDefault();
				setActiveOption( options.length - 1 );
			} else if ( e.key === 'Enter' || e.key === ' ' ) {
				e.preventDefault();
				toggleOption( activeIndex );
			}
		} );

		// Make listbox keyboard-focusable while open so keydown lands on it.
		listbox.setAttribute( 'tabindex', '-1' );

		// Option click.
		Array.prototype.forEach.call( options, function( li, index ) {
			li.addEventListener( 'click', function() {
				toggleOption( index );
				setActiveOption( index );
			} );
		} );

		// When the trigger receives focus and the listbox opens via Enter/Space,
		// move keyboard focus into the listbox so arrow keys work.
		trigger.addEventListener( 'click', function() {
			if ( ! listbox.hidden ) {
				listbox.focus();
			}
		} );

		// Outside-click closes the listbox.
		document.addEventListener( 'click', function( e ) {
			if ( ! wrapper.contains( e.target ) ) {
				close();
			}
		} );

		// Initial state sync.
		updateTrigger();
	}

	function init() {
		var sources = document.querySelectorAll( 'select[multiple].nte-multiselect-source' );
		Array.prototype.forEach.call( sources, enhance );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
