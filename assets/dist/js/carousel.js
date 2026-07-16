/**
 * NetterTech Events - Carousel
 *
 * Handles navigation and autoplay for event carousels.
 *
 * Playback modes (active when autoplay is enabled):
 *   - 'rewind' (default): snap back to start on end-of-track.
 *   - 'loop': continuous seamless belt via leading-card clones.
 *
 * Accessibility:
 *   - prefers-reduced-motion: reduce forces 'rewind' regardless of operator setting.
 *   - Cloned cards used by 'loop' mode are aria-hidden so screen readers
 *     don't announce duplicate cards.
 *   - Live region announcements normalize the wrapped index so positions
 *     always read back as 1..totalCards.
 *
 * @package NetterTechEvents
 */

( function() {
	'use strict';

	/**
	 * Initialize a carousel instance.
	 *
	 * @param {HTMLElement} container The carousel container element.
	 */
	function initCarousel( container ) {
		const track = container.querySelector( '.nte-carousel__track' );
		const originalCards = track ? track.querySelectorAll( '.nte-event-card' ) : [];
		const prevBtn = container.querySelector( '.nte-carousel__nav--prev' );
		const nextBtn = container.querySelector( '.nte-carousel__nav--next' );
		const liveRegion = container.querySelector( '.nte-carousel__live-region' );

		if ( ! track || originalCards.length === 0 ) {
			return;
		}

		const columns = parseInt( container.dataset.columns, 10 ) || 3;
		const autoplay = container.dataset.autoplay === 'true';
		const interval = parseInt( container.dataset.interval, 10 ) || 5000;
		const requestedMode = container.dataset.playbackMode === 'loop' ? 'loop' : 'rewind';

		// prefers-reduced-motion forces rewind regardless of operator choice.
		const prefersReducedMotion = !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
		const playbackMode = prefersReducedMotion ? 'rewind' : requestedMode;

		const totalCards = originalCards.length;
		const maxIndex = Math.max( 0, totalCards - columns );
		const isLoop = 'loop' === playbackMode && totalCards > columns;

		// Loop mode clones the leading `columns` cards onto the end so the
		// strip can keep translating past the original last card without a
		// visible jump back to start. Clones are hidden from assistive tech
		// to avoid duplicate announcements via the live region.
		if ( isLoop ) {
			for ( let i = 0; i < columns; i++ ) {
				const clone = originalCards[ i ].cloneNode( true );
				clone.setAttribute( 'aria-hidden', 'true' );
				clone.dataset.nteCarouselClone = 'true';
				track.appendChild( clone );
			}
		}

		let currentIndex = 0;
		let autoplayTimer = null;
		let isWrapping = false;

		/**
		 * Announce navigation to screen readers.
		 *
		 * @param {string} message The message to announce.
		 */
		function announce( message ) {
			if ( liveRegion ) {
				liveRegion.textContent = message;
			}
		}

		/**
		 * Build the position announcement, normalizing for loop-wrap.
		 *
		 * @return {string} The announcement string.
		 */
		function positionAnnouncement() {
			// Normalize visual position so wrap reads back as 1..totalCards.
			const visual = ( ( currentIndex % totalCards ) + totalCards ) % totalCards;
			const first = visual + 1;
			const last = Math.min( visual + columns, totalCards );
			return `Showing events ${ first } to ${ last } of ${ totalCards }`;
		}

		/**
		 * Update the carousel position.
		 *
		 * @param {boolean} animate Whether to animate the transition. Defaults to true.
		 */
		function updatePosition( animate ) {
			const firstCard = track.querySelector( '.nte-event-card' );
			if ( ! firstCard ) {
				return;
			}
			const cardWidth = firstCard.offsetWidth;
			const gap = parseInt( getComputedStyle( track ).gap, 10 ) || 20;
			const offset = currentIndex * ( cardWidth + gap );

			if ( false === animate ) {
				const previousTransition = track.style.transition;
				track.style.transition = 'none';
				track.style.transform = `translateX(-${ offset }px)`;
				// Force reflow so the snap applies before any later transition is restored.
				void track.offsetWidth;
				track.style.transition = previousTransition;
			} else {
				track.style.transform = `translateX(-${ offset }px)`;
			}

			// Update button states. Loop mode allows infinite navigation,
			// so buttons stay enabled whenever there are more cards than columns.
			if ( isLoop ) {
				if ( prevBtn ) {
					prevBtn.disabled = false;
					prevBtn.classList.remove( 'nte-carousel__nav--disabled' );
				}
				if ( nextBtn ) {
					nextBtn.disabled = false;
					nextBtn.classList.remove( 'nte-carousel__nav--disabled' );
				}
			} else {
				if ( prevBtn ) {
					prevBtn.disabled = currentIndex === 0;
					prevBtn.classList.toggle( 'nte-carousel__nav--disabled', currentIndex === 0 );
				}
				if ( nextBtn ) {
					nextBtn.disabled = currentIndex >= maxIndex;
					nextBtn.classList.toggle( 'nte-carousel__nav--disabled', currentIndex >= maxIndex );
				}
			}
		}

		/**
		 * Run a callback once the next track transition completes, with an
		 * 800ms safety net in case `transitionend` never fires (zero-duration
		 * transitions, browser quirks, prefers-reduced-motion CSS overrides).
		 *
		 * The safety net's `if ( isWrapping )` guard ensures the callback runs
		 * exactly once — it's a no-op if `transitionend` already ran.
		 *
		 * @param {Function} cb Callback invoked after the transition settles.
		 */
		function afterTransition( cb ) {
			let done = false;
			const onEnd = function() {
				if ( done ) {
					return;
				}
				done = true;
				track.removeEventListener( 'transitionend', onEnd );
				cb();
			};
			track.addEventListener( 'transitionend', onEnd );
			window.setTimeout( function() {
				if ( ! done ) {
					onEnd();
				}
			}, 800 );
		}

		/**
		 * After a forward loop-wrap into the cloned segment, snap back to
		 * index 0 once the in-flight transition ends. Visually identical
		 * because clones are exact copies, so the snap is invisible.
		 */
		function handleForwardLoopWrap() {
			afterTransition( function() {
				currentIndex = 0;
				updatePosition( false );
				isWrapping = false;
			} );
		}

		/**
		 * Go to next slide.
		 *
		 * @param {boolean} shouldAnnounce Whether to announce the navigation.
		 */
		function next( shouldAnnounce ) {
			if ( isLoop ) {
				if ( isWrapping ) {
					return;
				}
				currentIndex++;
				updatePosition( true );
				if ( shouldAnnounce !== false ) {
					announce( positionAnnouncement() );
				}
				if ( currentIndex >= totalCards ) {
					// Slid into the cloned segment - snap back invisibly after transition.
					isWrapping = true;
					handleForwardLoopWrap();
				}
				return;
			}

			// Rewind mode (and the reduced-motion fallback).
			if ( currentIndex < maxIndex ) {
				currentIndex++;
				updatePosition( true );
				if ( shouldAnnounce !== false ) {
					announce( positionAnnouncement() );
				}
			} else if ( autoplay ) {
				// Loop back to start for autoplay.
				currentIndex = 0;
				updatePosition( true );
			}
		}

		/**
		 * Go to previous slide.
		 *
		 * @param {boolean} shouldAnnounce Whether to announce the navigation.
		 */
		function prev( shouldAnnounce ) {
			if ( isLoop ) {
				if ( isWrapping ) {
					return;
				}
				if ( currentIndex === 0 ) {
					// Jump invisibly to the clone segment, then animate one step back.
					// Visual: clones at the leading edge are identical to originals,
					// so the snap is invisible; the animation reveals the actual last card.
					// Lock is held through the full backward animation (not just the rAF
					// snap) so autoplay or fast user input can't fire mid-transition.
					isWrapping = true;
					currentIndex = totalCards;
					updatePosition( false );
					window.requestAnimationFrame( function() {
						window.requestAnimationFrame( function() {
							currentIndex = totalCards - 1;
							updatePosition( true );
							if ( shouldAnnounce !== false ) {
								announce( positionAnnouncement() );
							}
							afterTransition( function() {
								isWrapping = false;
							} );
						} );
					} );
					return;
				}
				currentIndex--;
				updatePosition( true );
				if ( shouldAnnounce !== false ) {
					announce( positionAnnouncement() );
				}
				return;
			}

			// Rewind mode.
			if ( currentIndex > 0 ) {
				currentIndex--;
				updatePosition( true );
				if ( shouldAnnounce !== false ) {
					announce( positionAnnouncement() );
				}
			}
		}

		/**
		 * Start autoplay.
		 */
		function startAutoplay() {
			if ( autoplay && ! autoplayTimer ) {
				autoplayTimer = setInterval( function() {
					next( false ); // Don't announce autoplay transitions
				}, interval );
			}
		}

		/**
		 * Stop autoplay.
		 */
		function stopAutoplay() {
			if ( autoplayTimer ) {
				clearInterval( autoplayTimer );
				autoplayTimer = null;
			}
		}

		/**
		 * Reset autoplay timer.
		 */
		function resetAutoplay() {
			stopAutoplay();
			startAutoplay();
		}

		// Bind navigation events.
		if ( prevBtn ) {
			prevBtn.addEventListener( 'click', function() {
				prev();
				resetAutoplay();
			} );
		}

		if ( nextBtn ) {
			nextBtn.addEventListener( 'click', function() {
				next();
				resetAutoplay();
			} );
		}

		// Pause autoplay on hover.
		container.addEventListener( 'mouseenter', stopAutoplay );
		container.addEventListener( 'mouseleave', startAutoplay );

		// Touch/swipe support.
		let touchStartX = 0;
		let touchEndX = 0;

		track.addEventListener( 'touchstart', function( e ) {
			touchStartX = e.changedTouches[ 0 ].screenX;
			stopAutoplay();
		}, { passive: true } );

		track.addEventListener( 'touchend', function( e ) {
			touchEndX = e.changedTouches[ 0 ].screenX;
			handleSwipe();
			startAutoplay();
		}, { passive: true } );

		function handleSwipe() {
			const swipeThreshold = 50;
			const diff = touchStartX - touchEndX;

			if ( diff > swipeThreshold ) {
				next();
			} else if ( diff < -swipeThreshold ) {
				prev();
			}
		}

		// Keyboard navigation.
		container.setAttribute( 'tabindex', '0' );
		let isPaused = false;
		container.addEventListener( 'keydown', function( e ) {
			if ( e.key === 'ArrowLeft' ) {
				prev();
				resetAutoplay();
			} else if ( e.key === 'ArrowRight' ) {
				next();
				resetAutoplay();
			} else if ( e.key === ' ' || e.key === 'Enter' ) {
				e.preventDefault();
				if ( isPaused ) {
					startAutoplay();
					isPaused = false;
					announce( 'Carousel resumed' );
				} else {
					stopAutoplay();
					isPaused = true;
					announce( 'Carousel paused' );
				}
			}
		} );

		// Handle window resize.
		let resizeTimer;
		window.addEventListener( 'resize', function() {
			clearTimeout( resizeTimer );
			resizeTimer = setTimeout( function() {
				updatePosition( false );
			}, 100 );
		} );

		// Initialize.
		updatePosition( false );
		startAutoplay();
	}

	/**
	 * Initialize all carousels on the page.
	 */
	function initAllCarousels() {
		const carousels = document.querySelectorAll( '.nte-carousel' );
		carousels.forEach( initCarousel );
	}

	// Initialize on DOM ready.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initAllCarousels );
	} else {
		initAllCarousels();
	}

} )();
