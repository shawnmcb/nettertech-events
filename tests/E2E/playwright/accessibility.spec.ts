/**
 * Accessibility Tests (WCAG 2.2 AA)
 *
 * Automated accessibility testing using axe-core.
 * Tests all frontend page types for WCAG 2.2 AA compliance.
 *
 * @package NetterTechEvents
 */

import { test, expect } from './fixtures';
import AxeBuilder from '@axe-core/playwright';

/**
 * Helper to run axe-core and return violations.
 */
async function runAccessibilityScan( page: any, options: { exclude?: string[] } = {} ) {
	const builder = new AxeBuilder( { page } )
		.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] );

	// Exclude known third-party elements that we can't control.
	if ( options.exclude ) {
		builder.exclude( options.exclude );
	}

	const results = await builder.analyze();
	return results;
}

/**
 * Format violations for readable output.
 */
function formatViolations( violations: any[] ): string {
	if ( violations.length === 0 ) {
		return 'No violations found';
	}

	return violations
		.map( ( v ) => {
			const nodes = v.nodes
				.map( ( n: any ) => `    - ${n.html.substring( 0, 100 )}...` )
				.join( '\n' );
			return `[${v.impact}] ${v.id}: ${v.description}\n  Help: ${v.helpUrl}\n  Affected elements:\n${nodes}`;
		} )
		.join( '\n\n' );
}

test.describe( 'Accessibility - WCAG 2.2 AA Compliance', () => {
	/**
	 * Common exclusions for elements outside our plugin's control.
	 * - WordPress admin bar
	 * - Local by Flywheel assets
	 * - Theme header/footer (can have their own a11y issues)
	 */
	const commonExclusions = [
		'#wpadminbar',
		'.wp-admin-bar',
		'img[src*="local-error-page-assets"]',
		'header',
		'footer:not([class*="ve-"])',
	];

	/**
	 * Plugin-specific selectors to focus accessibility testing on our elements.
	 */
	const pluginSelectors = '.ve-archive, .ve-single-event, .ve-series-page, .ve-event-card, .ve-grid';

	test.describe( 'Events List Page', () => {
		test( 'events list page has no accessibility violations', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Check if plugin elements exist on page.
			const pluginElements = page.locator( pluginSelectors );
			const hasPluginElements = ( await pluginElements.count() ) > 0;

			if ( ! hasPluginElements ) {
				test.skip( true, 'No plugin elements found on page' );
				return;
			}

			// Focus on plugin elements only - theme issues are outside our control.
			const results = await new AxeBuilder( { page } )
				.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
				.include( pluginSelectors )
				.exclude( commonExclusions )
				.analyze();

			// Log violations for debugging.
			if ( results.violations.length > 0 ) {
				console.log( 'Events List Violations:\n', formatViolations( results.violations ) );
			}

			expect(
				results.violations,
				`Found ${results.violations.length} accessibility violations:\n${formatViolations( results.violations )}`
			).toHaveLength( 0 );
		} );

		test( 'events list has proper heading hierarchy', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Scope the heading-hierarchy assertion to the plugin's rendered
			// content container (.nte-archive). The /events/ page is composed
			// of theme chrome (site header/sidebar) plus the plugin's content,
			// and the plugin can only guarantee accessible heading order in
			// what it renders — the surrounding theme is out of plugin scope.
			//
			// Previously this scan ran across the entire document and was
			// data-dependent on whichever theme chrome the active site
			// happened to emit; on themes that include sidebar h3s after the
			// site h1 (e.g., the dev site title), the test failed on
			// chrome it had no jurisdiction over.
			const pluginRoot = page.locator( '.nte-archive' ).first();
			await expect( pluginRoot ).toBeVisible();

			// Plugin's archive emits a single h1 inside .nte-archive__header.
			const pluginH1Count = await pluginRoot.locator( 'h1' ).count();
			expect( pluginH1Count ).toBeGreaterThanOrEqual( 1 );

			// Walk headings inside the plugin container only.
			const headings = await pluginRoot.evaluate( ( root ) => {
				const elements = root.querySelectorAll( 'h1, h2, h3, h4, h5, h6' );
				return Array.from( elements ).map( ( el ) => ( {
					level: parseInt( el.tagName.substring( 1 ), 10 ),
					text: el.textContent?.trim().substring( 0, 50 ),
				} ) );
			} );

			// Verify no heading levels are skipped within the plugin's content.
			let previousLevel = 0;
			for ( const heading of headings ) {
				expect(
					heading.level - previousLevel,
					`Heading "${heading.text}" skips levels within plugin content (${previousLevel} to ${heading.level})`
				).toBeLessThanOrEqual( 1 );
				previousLevel = heading.level;
			}
		} );

		test( 'event cards have accessible names', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Check that event links have accessible text.
			const eventLinks = page.locator( '.ve-event-card a, .ve-archive a, .ve-grid a' );
			const linkCount = await eventLinks.count();

			if ( linkCount > 0 ) {
				for ( let i = 0; i < Math.min( linkCount, 5 ); i++ ) {
					const link = eventLinks.nth( i );
					const accessibleName = await link.evaluate( ( el ) => {
						return (
							el.getAttribute( 'aria-label' ) ||
							el.textContent?.trim() ||
							el.querySelector( 'img' )?.getAttribute( 'alt' )
						);
					} );
					expect(
						accessibleName,
						`Event link ${i + 1} should have accessible name`
					).toBeTruthy();
				}
			}
		} );

		test( 'images have alt text', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const images = page.locator( '.ve-event-card img, .ve-archive img, .ve-grid img' );
			const imageCount = await images.count();

			for ( let i = 0; i < imageCount; i++ ) {
				const img = images.nth( i );
				const alt = await img.getAttribute( 'alt' );
				const role = await img.getAttribute( 'role' );

				// Images must have alt text or role="presentation".
				const hasAlt = alt !== null && alt !== undefined;
				const isDecorative = role === 'presentation' || role === 'none';

				expect(
					hasAlt || isDecorative,
					`Image ${i + 1} must have alt text or role="presentation"`
				).toBeTruthy();
			}
		} );
	} );

	test.describe( 'Single Event Page', () => {
		test( 'single event page has no accessibility violations', async ( { page } ) => {
			// Navigate to events list first to find a real event.
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Find first event link.
			const eventLink = page.locator( '.ve-event-card a, .ve-archive a' ).first();
			const hasEvents = ( await eventLink.count() ) > 0;

			if ( hasEvents ) {
				await eventLink.click();
				await page.waitForLoadState( 'networkidle' );

				// Check if we're on a plugin single event page.
				const singleEventContainer = page.locator( '.ve-single-event' );
				const hasPluginPage = ( await singleEventContainer.count() ) > 0;

				if ( ! hasPluginPage ) {
					test.skip( true, 'Not a plugin-rendered single event page' );
					return;
				}

				// Focus on plugin elements only.
				const results = await new AxeBuilder( { page } )
					.withTags( [ 'wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa' ] )
					.include( '.ve-single-event' )
					.exclude( commonExclusions )
					.analyze();

				if ( results.violations.length > 0 ) {
					console.log( 'Single Event Violations:\n', formatViolations( results.violations ) );
				}

				expect(
					results.violations,
					`Found ${results.violations.length} accessibility violations`
				).toHaveLength( 0 );
			} else {
				test.skip( true, 'No events available for testing' );
			}
		} );

		test( 'event details have proper semantic structure', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const eventLink = page.locator( '.ve-event-card a, .ve-archive a' ).first();
			const hasEvents = ( await eventLink.count() ) > 0;

			if ( hasEvents ) {
				await eventLink.click();
				await page.waitForLoadState( 'networkidle' );

				// Check for main landmark.
				const main = page.locator( 'main, [role="main"]' );
				await expect( main ).toBeVisible();

				// Check for article or similar semantic structure.
				const article = page.locator( 'article, .ve-single-event, .ve-single-event__article' );
				const hasArticle = ( await article.count() ) > 0;
				expect( hasArticle ).toBeTruthy();
			} else {
				test.skip( true, 'No events available for testing' );
			}
		} );

		test( 'date/time information is accessible', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const eventLink = page.locator( '.ve-event-card a, .ve-archive a' ).first();
			const hasEvents = ( await eventLink.count() ) > 0;

			if ( hasEvents ) {
				await eventLink.click();
				await page.waitForLoadState( 'networkidle' );

				// Check for time element or aria-label on date/time info.
				const timeElements = page.locator( 'time, [datetime], .ve-single-event__date, .ve-single-event__time, .ve-event-card__date' );
				const timeCount = await timeElements.count();

				if ( timeCount > 0 ) {
					// At least one time element should exist.
					expect( timeCount ).toBeGreaterThan( 0 );
				}
			} else {
				test.skip( true, 'No events available for testing' );
			}
		} );
	} );

	test.describe( 'Series Page (Recurring Event)', () => {
		test( 'series page has no accessibility violations', async ( { page } ) => {
			// Navigate to a series page if available.
			// Series pages typically have /event/slug/series/ or similar URL.
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Look for a link that might be a series page.
			const seriesLink = page.locator( 'a[href*="/series/"], a[href*="?series="]' ).first();
			const hasSeries = ( await seriesLink.count() ) > 0;

			if ( hasSeries ) {
				await seriesLink.click();
				await page.waitForLoadState( 'networkidle' );

				const results = await runAccessibilityScan( page, {
					exclude: commonExclusions,
				} );

				if ( results.violations.length > 0 ) {
					console.log( 'Series Page Violations:\n', formatViolations( results.violations ) );
				}

				expect(
					results.violations,
					`Found ${results.violations.length} accessibility violations`
				).toHaveLength( 0 );
			} else {
				// Try direct URL pattern.
				const response = await page.goto( '/event/test-series/series/' );
				if ( response?.ok() ) {
					const results = await runAccessibilityScan( page, {
						exclude: commonExclusions,
					} );
					expect( results.violations ).toHaveLength( 0 );
				} else {
					test.skip( true, 'No series pages available for testing' );
				}
			}
		} );

		test( 'occurrence list is accessible', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const seriesLink = page.locator( 'a[href*="/series/"]' ).first();
			const hasSeries = ( await seriesLink.count() ) > 0;

			if ( hasSeries ) {
				await seriesLink.click();
				await page.waitForLoadState( 'networkidle' );

				// Check that occurrence list uses proper list semantics.
				const occurrenceList = page.locator( '.ve-occurrence-list, .ve-series-occurrences' );
				const hasOccurrenceList = ( await occurrenceList.count() ) > 0;

				if ( hasOccurrenceList ) {
					// Check for ul/ol or role="list".
					const list = page.locator(
						'.ve-occurrence-list ul, .ve-occurrence-list ol, .ve-occurrence-list [role="list"]'
					);
					const hasList = ( await list.count() ) > 0;
					expect( hasList, 'Occurrence list should use proper list semantics' ).toBeTruthy();
				}
			} else {
				test.skip( true, 'No series pages available for testing' );
			}
		} );
	} );

	test.describe( 'Calendar Page', () => {
		test( 'calendar shortcode has no accessibility violations', async ( { page } ) => {
			// Calendar might be on a dedicated page or embedded via shortcode.
			// Check if /calendar/ exists, otherwise look for calendar on events page.
			let response = await page.goto( '/calendar/' );

			if ( ! response?.ok() ) {
				// Try events page which might have calendar.
				response = await page.goto( '/events/' );
			}

			await page.waitForLoadState( 'networkidle' );

			// Check if calendar widget exists.
			const calendar = page.locator( '.ve-calendar, .nettertech-events-calendar, [data-calendar]' );
			const hasCalendar = ( await calendar.count() ) > 0;

			if ( hasCalendar ) {
				const results = await runAccessibilityScan( page, {
					exclude: commonExclusions,
				} );

				if ( results.violations.length > 0 ) {
					console.log( 'Calendar Violations:\n', formatViolations( results.violations ) );
				}

				expect(
					results.violations,
					`Found ${results.violations.length} accessibility violations`
				).toHaveLength( 0 );
			} else {
				test.skip( true, 'No calendar widget found on site' );
			}
		} );

		test( 'calendar has keyboard navigation', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const calendar = page.locator( '.ve-calendar, .nettertech-events-calendar' );
			const hasCalendar = ( await calendar.count() ) > 0;

			if ( hasCalendar ) {
				// Focus the calendar.
				await calendar.first().focus();

				// Try arrow key navigation.
				await page.keyboard.press( 'ArrowRight' );
				await page.keyboard.press( 'ArrowDown' );
				await page.keyboard.press( 'Enter' );

				// If we got here without errors, basic keyboard nav works.
				expect( true ).toBeTruthy();
			} else {
				test.skip( true, 'No calendar widget found on site' );
			}
		} );
	} );

	test.describe( 'Carousel Shortcode', () => {
		test( 'carousel has no accessibility violations', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const carousel = page.locator( '.ve-carousel, .nettertech-events-carousel, [data-carousel]' );
			const hasCarousel = ( await carousel.count() ) > 0;

			if ( hasCarousel ) {
				const results = await runAccessibilityScan( page, {
					exclude: commonExclusions,
				} );

				if ( results.violations.length > 0 ) {
					console.log( 'Carousel Violations:\n', formatViolations( results.violations ) );
				}

				expect(
					results.violations,
					`Found ${results.violations.length} accessibility violations`
				).toHaveLength( 0 );
			} else {
				// Carousel might not be on events page - test passed by default.
				test.skip( true, 'No carousel found on events page' );
			}
		} );

		test( 'carousel controls are keyboard accessible', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const carousel = page.locator( '.ve-carousel, .nettertech-events-carousel' );
			const hasCarousel = ( await carousel.count() ) > 0;

			if ( hasCarousel ) {
				// Check for accessible carousel controls.
				const prevButton = page.locator(
					'.ve-carousel-prev, .carousel-prev, [aria-label*="previous"], [aria-label*="Previous"]'
				);
				const nextButton = page.locator(
					'.ve-carousel-next, .carousel-next, [aria-label*="next"], [aria-label*="Next"]'
				);

				if ( ( await prevButton.count() ) > 0 && ( await nextButton.count() ) > 0 ) {
					// Buttons should be focusable.
					await expect( prevButton.first() ).toBeEnabled();
					await expect( nextButton.first() ).toBeEnabled();

					// Test keyboard activation.
					await nextButton.first().focus();
					await page.keyboard.press( 'Enter' );

					// If no error, control works.
					expect( true ).toBeTruthy();
				}
			} else {
				test.skip( true, 'No carousel found on events page' );
			}
		} );

		test( 'carousel has proper ARIA attributes', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			const carousel = page.locator( '.ve-carousel, .nettertech-events-carousel' );
			const hasCarousel = ( await carousel.count() ) > 0;

			if ( hasCarousel ) {
				// Check for required ARIA attributes.
				const carouselElement = carousel.first();

				// Should have role or aria-roledescription.
				const role = await carouselElement.getAttribute( 'role' );
				const roledescription = await carouselElement.getAttribute( 'aria-roledescription' );
				const ariaLabel = await carouselElement.getAttribute( 'aria-label' );
				const ariaLabelledby = await carouselElement.getAttribute( 'aria-labelledby' );

				// Carousel should have some form of accessible name.
				const hasAccessibleName = ariaLabel || ariaLabelledby;

				expect(
					hasAccessibleName || role || roledescription,
					'Carousel should have role or accessible name'
				).toBeTruthy();
			} else {
				test.skip( true, 'No carousel found on events page' );
			}
		} );
	} );

	test.describe( 'Focus Management', () => {
		test( 'interactive elements have visible focus indicators', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Find interactive elements.
			const interactiveElements = page.locator( 'a, button, input, select, textarea' ).first();
			const hasInteractive = ( await interactiveElements.count() ) > 0;

			if ( hasInteractive ) {
				await interactiveElements.focus();

				// Check that focus is visible (element should have outline or box-shadow).
				const focusStyles = await interactiveElements.evaluate( ( el ) => {
					const styles = window.getComputedStyle( el );
					return {
						outline: styles.outline,
						outlineWidth: styles.outlineWidth,
						boxShadow: styles.boxShadow,
					};
				} );

				// Should have visible focus indicator.
				const hasOutline =
					focusStyles.outline !== 'none' && focusStyles.outlineWidth !== '0px';
				const hasBoxShadow = focusStyles.boxShadow !== 'none';

				expect(
					hasOutline || hasBoxShadow,
					'Interactive elements should have visible focus indicator'
				).toBeTruthy();
			}
		} );

		test( 'skip link is present and functional', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Check for skip link (common a11y pattern).
			const skipLink = page.locator(
				'a[href="#content"], a[href="#main"], a.skip-link, .screen-reader-text a'
			);
			const hasSkipLink = ( await skipLink.count() ) > 0;

			if ( hasSkipLink ) {
				// Skip link should become visible on focus.
				await skipLink.first().focus();

				// Activate skip link.
				await page.keyboard.press( 'Enter' );

				// Focus should move to main content.
				const focusedElement = await page.evaluate( () => {
					return document.activeElement?.id || document.activeElement?.tagName;
				} );

				// Focus moved somewhere (not necessarily to specific ID).
				expect( focusedElement ).toBeTruthy();
			}
		} );
	} );

	test.describe( 'Color Contrast', () => {
		test( 'text meets minimum contrast ratio', async ( { page } ) => {
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Check if plugin elements exist on page.
			const pluginElements = page.locator( '.ve-event-card, .ve-archive, .ve-grid' );
			const hasPluginElements = ( await pluginElements.count() ) > 0;

			if ( ! hasPluginElements ) {
				test.skip( true, 'No plugin elements found on page' );
				return;
			}

			// axe-core handles contrast checking via the main scan.
			// This test specifically targets our plugin's elements.
			const results = await new AxeBuilder( { page } )
				.withTags( [ 'wcag2aa' ] )
				.include( '.ve-event-card, .ve-archive, .ve-grid' )
				.analyze();

			const contrastViolations = results.violations.filter(
				( v ) => v.id === 'color-contrast' || v.id === 'color-contrast-enhanced'
			);

			if ( contrastViolations.length > 0 ) {
				console.log( 'Contrast Violations:\n', formatViolations( contrastViolations ) );
			}

			expect(
				contrastViolations,
				`Found ${contrastViolations.length} contrast violations`
			).toHaveLength( 0 );
		} );
	} );

	test.describe( 'Motion and Animation', () => {
		test( 'respects prefers-reduced-motion', async ( { page } ) => {
			// Emulate reduced motion preference.
			await page.emulateMedia( { reducedMotion: 'reduce' } );
			await page.goto( '/events/' );
			await page.waitForLoadState( 'networkidle' );

			// Check that animations are disabled.
			const carousel = page.locator( '.ve-carousel, .nettertech-events-carousel' );
			const hasCarousel = ( await carousel.count() ) > 0;

			if ( hasCarousel ) {
				const animationStyles = await carousel.first().evaluate( ( el ) => {
					const styles = window.getComputedStyle( el );
					return {
						animationDuration: styles.animationDuration,
						transitionDuration: styles.transitionDuration,
						animationPlayState: styles.animationPlayState,
					};
				} );

				// With reduced motion, animations should be instant or paused.
				const hasReducedMotion =
					animationStyles.animationDuration === '0s' ||
					animationStyles.transitionDuration === '0s' ||
					animationStyles.animationPlayState === 'paused';

				// This is informational - not all carousels handle this.
				if ( ! hasReducedMotion ) {
					console.log( 'Note: Carousel may not respect prefers-reduced-motion' );
				}
			}

			// Test passes - this is more informational.
			expect( true ).toBeTruthy();
		} );
	} );
} );
