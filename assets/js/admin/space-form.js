/**
 * Space admin form widgets.
 *
 * Powers the Featured Image picker, Gallery picker, and the
 * Accessibility editor (preset multi-select with optional count + notes,
 * plus ad hoc custom entries) on the NTE Spaces add/edit page.
 *
 * @package NetterTechEvents
 */

( function () {
	'use strict';

	/**
	 * Bind a single-image media picker.
	 *
	 * @param {HTMLElement} root The .nte-media-picker container.
	 */
	function bindSinglePicker( root ) {
		const targetId = root.dataset.target;
		const input    = document.getElementById( targetId );
		const preview  = root.querySelector( '.nte-media-picker__preview' );
		const select   = root.querySelector( '.nte-media-picker__select' );
		const clear    = root.querySelector( '.nte-media-picker__clear' );

		if ( ! input || ! preview || ! select ) {
			return;
		}

		select.addEventListener( 'click', function () {
			const frame = window.wp.media( {
				title: select.textContent.trim(),
				multiple: false,
				library: { type: 'image' },
			} );

			frame.on( 'select', function () {
				const att = frame.state().get( 'selection' ).first().toJSON();
				input.value = String( att.id );

				while ( preview.firstChild ) {
					preview.removeChild( preview.firstChild );
				}
				const img = document.createElement( 'img' );
				img.src = ( att.sizes && att.sizes.medium ) ? att.sizes.medium.url : att.url;
				img.alt = att.alt || '';
				img.style.maxWidth = '300px';
				img.style.height = 'auto';
				preview.appendChild( img );

				if ( clear ) {
					clear.hidden = false;
				}
			} );

			frame.open();
		} );

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				input.value = '';
				while ( preview.firstChild ) {
					preview.removeChild( preview.firstChild );
				}
				clear.hidden = true;
			} );
		}
	}

	/**
	 * Bind a multi-image gallery picker.
	 *
	 * @param {HTMLElement} root The .nte-media-picker container.
	 */
	function bindGalleryPicker( root ) {
		const targetId = root.dataset.target;
		const input    = document.getElementById( targetId );
		const gallery  = root.querySelector( '.nte-media-picker__gallery' );
		const select   = root.querySelector( '.nte-media-picker__select' );
		const clear    = root.querySelector( '.nte-media-picker__clear' );

		if ( ! input || ! gallery || ! select ) {
			return;
		}

		select.addEventListener( 'click', function () {
			const frame = window.wp.media( {
				title: select.textContent.trim(),
				multiple: 'add',
				library: { type: 'image' },
			} );

			frame.on( 'select', function () {
				const ids = input.value
					? input.value.split( ',' ).map( function ( v ) { return parseInt( v, 10 ); } ).filter( Boolean )
					: [];

				frame.state().get( 'selection' ).each( function ( att ) {
					const json = att.toJSON();
					const id   = parseInt( json.id, 10 );
					if ( ! id || ids.indexOf( id ) !== -1 ) {
						return;
					}
					ids.push( id );

					const span = document.createElement( 'span' );
					span.className = 'nte-media-picker__thumb';
					span.dataset.id = String( id );
					const img = document.createElement( 'img' );
					img.src = ( json.sizes && json.sizes.thumbnail ) ? json.sizes.thumbnail.url : json.url;
					img.alt = json.alt || '';
					span.appendChild( img );
					gallery.appendChild( span );
				} );

				input.value = ids.join( ',' );

				if ( clear ) {
					clear.hidden = ids.length === 0;
				}
			} );

			frame.open();
		} );

		if ( clear ) {
			clear.addEventListener( 'click', function () {
				input.value = '';
				while ( gallery.firstChild ) {
					gallery.removeChild( gallery.firstChild );
				}
				clear.hidden = true;
			} );
		}
	}

	/**
	 * Bind the accessibility-features editor.
	 *
	 * @param {HTMLElement} root The .nte-a11y-editor container.
	 */
	function bindA11yEditor( root ) {
		const targetId = root.dataset.target;
		const input    = document.getElementById( targetId );
		const rowsEl   = root.querySelector( '.nte-a11y-editor__rows' );
		const addPreset = root.querySelector( '.nte-a11y-editor__add-preset' );
		const addCustom = root.querySelector( '.nte-a11y-editor__add-custom' );

		if ( ! input || ! rowsEl ) {
			return;
		}

		let presets = {};
		try {
			presets = JSON.parse( root.dataset.presets || '{}' );
		} catch ( err ) {
			presets = {};
		}

		let entries = [];
		try {
			entries = JSON.parse( input.value || '[]' );
			if ( ! Array.isArray( entries ) ) {
				entries = [];
			}
		} catch ( err ) {
			entries = [];
		}

		function syncInput() {
			input.value = JSON.stringify( entries );
		}

		function render() {
			while ( rowsEl.firstChild ) {
				rowsEl.removeChild( rowsEl.firstChild );
			}
			entries.forEach( function ( entry, index ) {
				rowsEl.appendChild( buildRow( entry, index ) );
			} );
		}

		function buildRow( entry, index ) {
			const row = document.createElement( 'div' );
			row.className = 'nte-a11y-row';

			const isCustom = entry.custom === true || ! presets.hasOwnProperty( entry.key );

			let keyControl;
			if ( isCustom ) {
				keyControl = document.createElement( 'input' );
				keyControl.type = 'text';
				keyControl.placeholder = 'custom_feature_key';
				keyControl.className = 'regular-text';
				keyControl.value = entry.key || '';
				keyControl.addEventListener( 'input', function () {
					entries[ index ].key = keyControl.value.trim();
					entries[ index ].custom = true;
					syncInput();
				} );
			} else {
				keyControl = document.createElement( 'select' );
				Object.keys( presets ).forEach( function ( key ) {
					const opt = document.createElement( 'option' );
					opt.value = key;
					opt.textContent = presets[ key ];
					if ( entry.key === key ) {
						opt.selected = true;
					}
					keyControl.appendChild( opt );
				} );
				keyControl.addEventListener( 'change', function () {
					entries[ index ].key = keyControl.value;
					delete entries[ index ].custom;
					syncInput();
				} );
			}

			const countLabel = document.createElement( 'label' );
			countLabel.textContent = ' Count: ';
			const count = document.createElement( 'input' );
			count.type = 'number';
			count.min = '0';
			count.className = 'small-text';
			count.value = entry.count != null ? String( entry.count ) : '';
			count.addEventListener( 'input', function () {
				const n = parseInt( count.value, 10 );
				if ( count.value === '' || isNaN( n ) || n <= 0 ) {
					delete entries[ index ].count;
				} else {
					entries[ index ].count = n;
				}
				syncInput();
			} );
			countLabel.appendChild( count );

			const notesLabel = document.createElement( 'label' );
			notesLabel.textContent = ' Notes: ';
			const notes = document.createElement( 'input' );
			notes.type = 'text';
			notes.className = 'regular-text';
			notes.value = entry.notes || '';
			notes.addEventListener( 'input', function () {
				const v = notes.value.trim();
				if ( v === '' ) {
					delete entries[ index ].notes;
				} else {
					entries[ index ].notes = v;
				}
				syncInput();
			} );
			notesLabel.appendChild( notes );

			const remove = document.createElement( 'button' );
			remove.type = 'button';
			remove.className = 'button-link-delete';
			remove.textContent = 'Remove';
			remove.addEventListener( 'click', function () {
				entries.splice( index, 1 );
				syncInput();
				render();
			} );

			row.appendChild( keyControl );
			row.appendChild( countLabel );
			row.appendChild( notesLabel );
			row.appendChild( remove );

			return row;
		}

		if ( addPreset ) {
			addPreset.addEventListener( 'click', function () {
				const firstKey = Object.keys( presets )[ 0 ] || '';
				entries.push( { key: firstKey } );
				syncInput();
				render();
			} );
		}

		if ( addCustom ) {
			addCustom.addEventListener( 'click', function () {
				entries.push( { key: '', custom: true } );
				syncInput();
				render();
			} );
		}

		render();
	}

	function init() {
		document.querySelectorAll( '.nte-media-picker' ).forEach( function ( el ) {
			if ( el.dataset.mode === 'multiple' ) {
				bindGalleryPicker( el );
			} else {
				bindSinglePicker( el );
			}
		} );

		document.querySelectorAll( '.nte-a11y-editor' ).forEach( bindA11yEditor );
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
