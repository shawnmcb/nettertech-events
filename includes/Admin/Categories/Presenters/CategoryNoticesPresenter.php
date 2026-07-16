<?php
/**
 * Category Notices Presenter (T4.2.4 Pages cluster).
 *
 * @package NetterTechEvents\Admin\Categories\Presenters
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin\Categories\Presenters;

defined( 'ABSPATH' ) || exit;

/**
 * Pure data-prep value object for the Category admin-notice template.
 *
 * Translates the URL ?message= flag into a notice message + type pair.
 * Does NOT call `esc_*()` (template's job), does NOT output, does NOT read
 * from $_GET/$_POST/get_option, does NOT call $wpdb.
 *
 * @since 1.x.x
 */
final class CategoryNoticesPresenter {

	/**
	 * Wire the URL message key and optional custom error text.
	 *
	 * @param string|null $message_key Sanitized value from $_GET['message'] (null = no notice).
	 * @param string|null $error_text  Sanitized value from $_GET['error'] when message_key is 'error'.
	 */
	public function __construct(
		private readonly ?string $message_key,
		private readonly ?string $error_text = null
	) {}

	/**
	 * Whether a notice should render at all.
	 *
	 * @return bool
	 */
	public function should_render(): bool {
		return null !== $this->message_key && '' !== $this->message_key && '' !== $this->message();
	}

	/**
	 * Notice text to display.
	 *
	 * @return string
	 */
	public function message(): string {
		return match ( $this->message_key ) {
			'created' => __( 'Category created.', 'nettertech-events' ),
			'updated' => __( 'Category updated.', 'nettertech-events' ),
			'deleted' => __( 'Category deleted.', 'nettertech-events' ),
			'error'   => null !== $this->error_text && '' !== $this->error_text
				? $this->error_text
				: __( 'An error occurred.', 'nettertech-events' ),
			default => '',
		};
	}

	/**
	 * Notice CSS type ('success' or 'error').
	 *
	 * @return string
	 */
	public function type(): string {
		return 'error' === $this->message_key ? 'error' : 'success';
	}
}
