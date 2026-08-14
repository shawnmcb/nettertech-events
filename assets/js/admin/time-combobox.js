/**
 * Suggest-and-type time combobox.
 *
 * Upgrades input[type=time][data-nte-time-combobox] into an APG combobox:
 * an editable field with a listbox of 15-minute suggestions that filters as
 * the operator types. Suggestions guide, they never constrain — any parseable
 * time (including off-increment values like 7:05 PM) is accepted verbatim.
 * With this script absent the native time input keeps working unchanged.
 *
 * Upgrade is lazy via focusin delegation, so rows cloned after page load
 * (Add-a-date, ticket rows) are covered without hooking their clone code.
 *
 * @package NetterTechEvents
 * @since 1.4.0
 */

( function() {
	'use strict';

	const config = window.nettertechEventsTimeCombobox || {};
	const i18n = config.i18n || {};
	const STEP_MINUTES = parseInt( config.stepMinutes, 10 ) || 15;
	const IS_12_HOUR = !! config.is12Hour;
	const INIT_FLAG = 'nteTcInit';

	let instanceCount = 0;

	/**
	 * Format minutes-since-midnight per the site time format.
	 *
	 * @param {number} minutes Minutes since midnight (0–1439).
	 * @return {string} Display label, e.g. "7:15 pm" or "19:15".
	 */
	function formatMinutes( minutes ) {
		const h24 = Math.floor( minutes / 60 );
		const m = minutes % 60;
		const mm = String( m ).padStart( 2, '0' );

		if ( ! IS_12_HOUR ) {
			return String( h24 ).padStart( 2, '0' ) + ':' + mm;
		}

		const suffix = h24 >= 12 ? ( i18n.pm || 'pm' ) : ( i18n.am || 'am' );
		let h12 = h24 % 12;
		if ( 0 === h12 ) {
			h12 = 12;
		}
		return h12 + ':' + mm + ' ' + suffix;
	}

	/**
	 * Parse a typed time string to minutes since midnight.
	 *
	 * Accepts "7", "7pm", "7 pm", "7:05pm", "19:00", "1905" — both 12- and
	 * 24-hour entry regardless of the display format. Returns null when the
	 * text is not a valid time.
	 *
	 * @param {string} text Raw operator input.
	 * @return {number|null} Minutes since midnight, or null.
	 */
	function parseTime( text ) {
		const raw = String( text || '' ).trim().toLowerCase();
		if ( '' === raw ) {
			return null;
		}

		const match = raw.match( /^(\d{1,2})(?::(\d{2})|(\d{2}))?\s*(a\.?m\.?|p\.?m\.?|a|p)?$/ );
		if ( ! match ) {
			return null;
		}

		let hours = parseInt( match[ 1 ], 10 );
		const minutes = parseInt( match[ 2 ] || match[ 3 ] || '0', 10 );
		const meridiem = match[ 4 ] ? match[ 4 ].charAt( 0 ) : '';

		if ( minutes > 59 ) {
			return null;
		}

		if ( '' !== meridiem ) {
			if ( hours < 1 || hours > 12 ) {
				return null;
			}
			if ( 'p' === meridiem && hours < 12 ) {
				hours += 12;
			}
			if ( 'a' === meridiem && 12 === hours ) {
				hours = 0;
			}
		} else if ( hours > 23 ) {
			return null;
		}

		return ( 60 * hours ) + minutes;
	}

	/**
	 * Convert minutes since midnight to the native wire value (HH:MM).
	 *
	 * @param {number} minutes Minutes since midnight.
	 * @return {string} 24-hour HH:MM value.
	 */
	function toWireValue( minutes ) {
		const h = String( Math.floor( minutes / 60 ) ).padStart( 2, '0' );
		const m = String( minutes % 60 ).padStart( 2, '0' );
		return h + ':' + m;
	}

	/**
	 * Human-readable duration between two times, for end-time annotations.
	 *
	 * @param {number} fromMinutes Start, minutes since midnight.
	 * @param {number} toMinutes   End, minutes since midnight (same day).
	 * @return {string} Localized duration label, e.g. "2 hrs" or "45 mins".
	 */
	function formatDuration( fromMinutes, toMinutes ) {
		const diff = toMinutes - fromMinutes;
		if ( diff <= 0 ) {
			return '';
		}
		const hours = Math.floor( diff / 60 );
		const mins = diff % 60;
		const parts = [];
		if ( hours > 0 ) {
			parts.push( hours + ' ' + ( 1 === hours ? ( i18n.hour || 'hr' ) : ( i18n.hours || 'hrs' ) ) );
		}
		if ( mins > 0 ) {
			parts.push( mins + ' ' + ( 1 === mins ? ( i18n.minute || 'min' ) : ( i18n.minutes || 'mins' ) ) );
		}
		return parts.join( ' ' );
	}

	/**
	 * One combobox instance bound to a native time input.
	 *
	 * The native input keeps its name/value (HH:MM) so the server sees the
	 * pre-existing wire format; a text input presented to the operator holds
	 * the formatted display value.
	 *
	 * @class
	 * @param {HTMLInputElement} timeInput The native input[type=time].
	 */
	function TimeCombobox( timeInput ) {
		instanceCount++;
		this.id = 'nte-tc-' + instanceCount;
		this.timeInput = timeInput;
		this.activeIndex = -1;
		this.openedText = '';
		this._build();
		this._bind();
	}

	TimeCombobox.prototype._build = function() {
		const wrapper = document.createElement( 'span' );
		wrapper.className = 'nte-time-combobox';

		const display = document.createElement( 'input' );
		display.type = 'text';
		display.className = 'nte-time-combobox__input';
		display.autocomplete = 'off';
		display.spellcheck = false;
		display.id = this.id + '-input';
		display.setAttribute( 'role', 'combobox' );
		display.setAttribute( 'aria-expanded', 'false' );
		display.setAttribute( 'aria-autocomplete', 'list' );
		display.setAttribute( 'aria-controls', this.id + '-listbox' );

		const listbox = document.createElement( 'ul' );
		listbox.className = 'nte-time-combobox__listbox';
		listbox.id = this.id + '-listbox';
		listbox.setAttribute( 'role', 'listbox' );
		listbox.setAttribute( 'aria-label', i18n.listLabel || 'Time suggestions' );
		listbox.hidden = true;

		// Mirror the native input's label onto the visible field.
		const nativeLabel = this._findLabel();
		if ( nativeLabel ) {
			display.setAttribute( 'aria-label', nativeLabel );
		}

		// Hide the native input from view and AT; it stays the submitted field.
		this.timeInput.classList.add( 'nte-time-combobox__native' );
		this.timeInput.tabIndex = -1;
		this.timeInput.setAttribute( 'aria-hidden', 'true' );

		if ( this.timeInput.value ) {
			const minutes = parseTime( this.timeInput.value );
			display.value = null === minutes ? this.timeInput.value : formatMinutes( minutes );
		}

		this.timeInput.parentNode.insertBefore( wrapper, this.timeInput );
		wrapper.appendChild( this.timeInput );
		wrapper.appendChild( display );
		wrapper.appendChild( listbox );

		this.wrapper = wrapper;
		this.display = display;
		this.listbox = listbox;
	};

	TimeCombobox.prototype._findLabel = function() {
		if ( this.timeInput.id ) {
			const forLabel = document.querySelector( 'label[for="' + this.timeInput.id + '"]' );
			if ( forLabel ) {
				return forLabel.textContent.trim();
			}
		}
		const wrapLabel = this.timeInput.closest( 'label' );
		return wrapLabel ? wrapLabel.textContent.trim() : '';
	};

	/**
	 * The start time this field annotates durations against, if configured
	 * via data-nte-duration-from="<selector>".
	 *
	 * @return {number|null} Minutes since midnight, or null.
	 */
	TimeCombobox.prototype._durationBase = function() {
		const selector = this.timeInput.dataset.nteDurationFrom;
		if ( ! selector ) {
			return null;
		}
		const source = document.querySelector( selector );
		return source ? parseTime( source.value ) : null;
	};

	TimeCombobox.prototype._buildOptions = function( filterText ) {
		const base = this._durationBase();
		const startAt = null === base ? 0 : ( base + STEP_MINUTES );
		const filter = String( filterText || '' ).trim().toLowerCase();
		const options = [];

		for ( let cursor = 0; cursor < 1440; cursor += STEP_MINUTES ) {
			const minutes = ( startAt + cursor ) % 1440;
			if ( null !== base && minutes <= base ) {
				continue;
			}
			let label = formatMinutes( minutes );
			if ( null !== base ) {
				const duration = formatDuration( base, minutes );
				if ( duration ) {
					label += ' (' + duration + ')';
				}
			}
			if ( '' !== filter && -1 === label.toLowerCase().indexOf( filter ) && 0 !== toWireValue( minutes ).indexOf( filter ) ) {
				continue;
			}
			options.push( { minutes: minutes, label: label } );
		}
		return options;
	};

	TimeCombobox.prototype._renderList = function( filterText ) {
		const options = this._buildOptions( filterText );
		this.listbox.textContent = '';
		this.activeIndex = -1;

		if ( 0 === options.length ) {
			const empty = document.createElement( 'li' );
			empty.className = 'nte-time-combobox__empty';
			empty.textContent = i18n.noSuggestions || 'No suggestions';
			this.listbox.appendChild( empty );
			return;
		}

		const currentMinutes = parseTime( this.display.value );
		options.forEach( ( option, index ) => {
			const item = document.createElement( 'li' );
			item.id = this.id + '-option-' + index;
			item.className = 'nte-time-combobox__option';
			item.setAttribute( 'role', 'option' );
			item.setAttribute( 'aria-selected', 'false' );
			item.dataset.minutes = String( option.minutes );
			item.textContent = option.label;
			this.listbox.appendChild( item );
			if ( null !== currentMinutes && option.minutes === currentMinutes && -1 === this.activeIndex ) {
				this._setActive( index );
			}
		} );
	};

	TimeCombobox.prototype._setActive = function( index ) {
		const items = this.listbox.querySelectorAll( '[role="option"]' );
		if ( 0 === items.length ) {
			return;
		}
		const bounded = Math.max( 0, Math.min( index, items.length - 1 ) );
		items.forEach( ( item ) => item.setAttribute( 'aria-selected', 'false' ) );
		const active = items[ bounded ];
		active.setAttribute( 'aria-selected', 'true' );
		this.display.setAttribute( 'aria-activedescendant', active.id );
		if ( active.scrollIntoView ) {
			active.scrollIntoView( { block: 'nearest' } );
		}
		this.activeIndex = bounded;
	};

	TimeCombobox.prototype._open = function( filterText ) {
		this.openedText = this.display.value;
		this._renderList( filterText );
		this.listbox.hidden = false;
		this.display.setAttribute( 'aria-expanded', 'true' );
	};

	TimeCombobox.prototype._close = function() {
		this.listbox.hidden = true;
		this.display.setAttribute( 'aria-expanded', 'false' );
		this.display.removeAttribute( 'aria-activedescendant' );
		this.activeIndex = -1;
	};

	TimeCombobox.prototype._isOpen = function() {
		return ! this.listbox.hidden;
	};

	/**
	 * Commit the display text to the native input.
	 *
	 * Parseable text (any minute, not just increments) is normalized; text
	 * that cannot be parsed leaves the native value empty and flags the field
	 * so the validation module can report it. Empty text clears the value.
	 */
	TimeCombobox.prototype._commit = function() {
		const text = this.display.value.trim();
		const oldValue = this.timeInput.value;

		if ( '' === text ) {
			this.timeInput.value = '';
			this.display.removeAttribute( 'aria-invalid' );
		} else {
			const minutes = parseTime( text );
			if ( null === minutes ) {
				this.timeInput.value = '';
				this.display.setAttribute( 'aria-invalid', 'true' );
			} else {
				this.timeInput.value = toWireValue( minutes );
				this.display.value = formatMinutes( minutes );
				this.display.removeAttribute( 'aria-invalid' );
			}
		}

		if ( oldValue !== this.timeInput.value ) {
			this._committing = true;
			this.timeInput.dispatchEvent( new Event( 'change', { bubbles: true } ) );
			this.timeInput.dispatchEvent( new CustomEvent( 'nte:time-change', {
				bubbles: true,
				detail: { value: this.timeInput.value },
			} ) );
			this._committing = false;
		}
	};

	/**
	 * Mirror a programmatic native-value change (e.g. the metabox soft-fill
	 * defaults) into the visible display field.
	 */
	TimeCombobox.prototype._syncFromNative = function() {
		if ( this._committing ) {
			return;
		}
		const minutes = parseTime( this.timeInput.value );
		this.display.value = null === minutes ? this.timeInput.value : formatMinutes( minutes );
		this.display.removeAttribute( 'aria-invalid' );
	};

	TimeCombobox.prototype._selectOption = function( optionEl ) {
		const minutes = parseInt( optionEl.dataset.minutes, 10 );
		this.display.value = formatMinutes( minutes );
		this._commit();
		this._close();
	};

	TimeCombobox.prototype._bind = function() {
		this.timeInput.addEventListener( 'change', () => this._syncFromNative() );

		this.display.addEventListener( 'keydown', ( e ) => this._handleKeydown( e ) );

		this.display.addEventListener( 'input', () => {
			if ( ! this._isOpen() ) {
				this._open( this.display.value );
			} else {
				this._renderList( this.display.value );
			}
		} );

		this.display.addEventListener( 'blur', () => {
			// Delay so an option mousedown can win before the list closes.
			window.setTimeout( () => {
				if ( ! this.wrapper.contains( document.activeElement ) ) {
					this._commit();
					this._close();
				}
			}, 120 );
		} );

		this.listbox.addEventListener( 'mousedown', ( e ) => {
			const option = e.target.closest( '[role="option"]' );
			if ( option ) {
				e.preventDefault();
				this._selectOption( option );
				this.display.focus();
			}
		} );
	};

	TimeCombobox.prototype._handleKeydown = function( e ) {
		switch ( e.key ) {
			case 'ArrowDown':
			case 'ArrowUp':
				e.preventDefault();
				if ( ! this._isOpen() ) {
					this._open( '' );
					if ( -1 === this.activeIndex ) {
						this._setActive( 0 );
					}
				} else {
					this._setActive( this.activeIndex + ( 'ArrowDown' === e.key ? 1 : -1 ) );
				}
				break;
			case 'Enter':
				if ( this._isOpen() && this.activeIndex >= 0 ) {
					e.preventDefault();
					const items = this.listbox.querySelectorAll( '[role="option"]' );
					this._selectOption( items[ this.activeIndex ] );
				} else if ( this._isOpen() ) {
					this._commit();
					this._close();
				}
				break;
			case 'Escape':
				if ( this._isOpen() ) {
					e.preventDefault();
					this.display.value = this.openedText;
					this._close();
				}
				break;
			case 'Tab':
				this._commit();
				this._close();
				break;
		}
	};

	/**
	 * Upgrade an eligible native input if it has not been upgraded yet.
	 *
	 * @param {Element} input Candidate element.
	 * @return {boolean} Whether an upgrade happened.
	 */
	function upgrade( input ) {
		if ( ! input || input.dataset[ INIT_FLAG ] ) {
			return false;
		}
		input.dataset[ INIT_FLAG ] = '1';
		const instance = new TimeCombobox( input );
		if ( document.activeElement === input ) {
			instance.display.focus();
		}
		return true;
	}

	function initAll( root ) {
		const scope = root && root.querySelectorAll ? root : document;
		scope.querySelectorAll( 'input[type="time"][data-nte-time-combobox]' ).forEach( upgrade );
	}

	// Lazy upgrade path for rows cloned after load.
	document.addEventListener( 'focusin', ( e ) => {
		const input = e.target.closest ? e.target.closest( 'input[type="time"][data-nte-time-combobox]' ) : null;
		upgrade( input );
	} );

	// Public API for explicit init after DOM insertion (e.g. row templates).
	window.nettertechEventsTimeCombobox = window.nettertechEventsTimeCombobox || {};
	window.nettertechEventsTimeCombobox.initAll = initAll;
	window.nettertechEventsTimeCombobox.parseTime = parseTime;

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', () => initAll( document ) );
	} else {
		initAll( document );
	}
} )();
