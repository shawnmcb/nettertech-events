<?php
/**
 * WooCommerce email header-image default.
 *
 * @package NetterTechEvents\Integrations\WooCommerce
 */

declare(strict_types=1);

namespace NetterTechEvents\Integrations\WooCommerce;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Utilities\ImageHelper;

/**
 * Defaults WooCommerce's email header image to the site logo.
 *
 * WooCommerce's order emails ("Thank you for your order" and the rest)
 * print whatever `woocommerce_email_header_image` holds, and fall back
 * to the store name as text. Stores that set a site logo but never
 * visited WooCommerce → Settings → Emails get text-only headers while
 * the ticket email shows the logo. This collaborator closes that gap:
 * when WooCommerce's own setting is empty, the site logo is used.
 *
 * An explicitly configured WooCommerce header image is never touched.
 * The `nettertech_events_wc_email_header_image` filter lets a site
 * opt out (return an empty string) or substitute another image.
 *
 * WooCommerce 10.x reads the option straight from `get_option()` inside
 * its header template with no dedicated filter, so the override rides
 * WordPress's `option_{name}` filter — but only while an email is being
 * rendered (between `woocommerce_email_header` and
 * `woocommerce_email_footer`), so the Emails settings screen keeps
 * showing the real, empty value.
 *
 * @since 1.4.7
 * @internal
 */
class WCEmailHeaderImage {

	/**
	 * Register the email-lifecycle hooks.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'woocommerce_email_header', array( $this, 'begin_email' ), 1 );
		add_action( 'woocommerce_email_footer', array( $this, 'end_email' ), 99 );
	}

	/**
	 * Start overriding the header-image option for the email being rendered.
	 *
	 * @return void
	 */
	public function begin_email(): void {
		add_filter( 'option_woocommerce_email_header_image', array( $this, 'filter_header_image' ) );
	}

	/**
	 * Stop overriding once the email is rendered.
	 *
	 * @return void
	 */
	public function end_email(): void {
		remove_filter( 'option_woocommerce_email_header_image', array( $this, 'filter_header_image' ) );
	}

	/**
	 * Supply the site logo when WooCommerce has no header image of its own.
	 *
	 * @param mixed $value Stored option value.
	 * @return mixed The stored value when set; otherwise the site logo URL
	 *               (or an empty string when there is none / opted out).
	 */
	public function filter_header_image( $value ) {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			return $value;
		}

		$logo_url = ImageHelper::get_site_logo_url();

		/**
		 * Filter the header image NetterTech Events supplies to WooCommerce
		 * emails when WooCommerce's own header image is unset.
		 *
		 * Return an empty string to leave WooCommerce's text-name header
		 * in place.
		 *
		 * @since 1.4.7
		 *
		 * @param string $logo_url Site logo URL, or empty string when none is set.
		 */
		$logo_url = apply_filters( 'nettertech_events_wc_email_header_image', $logo_url );

		return is_string( $logo_url ) ? $logo_url : '';
	}
}
