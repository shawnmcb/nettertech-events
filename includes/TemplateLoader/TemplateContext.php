<?php
/**
 * Template context value object.
 *
 * @package NetterTechEvents\TemplateLoader
 */

declare(strict_types=1);

namespace NetterTechEvents\TemplateLoader;

defined( 'ABSPATH' ) || exit;

/**
 * Typed wrapper around the data array passed into a plugin template.
 *
 * Replaces the prior `extract( $args, EXTR_SKIP )` injection in
 * TemplateLoader::load_template(). Templates receive a single
 * `$context` (instance of this class) and reach into it via
 * property-style access (`$context->event`) — eliminating the
 * unprefixed locals that the WP.org Plugin Directory's
 * NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound rule
 * was flagging across 38 sites.
 *
 * Theme overrides receive the same instance, so the contract for
 * theme-side templates is unchanged in shape (it just changed
 * from a wide bag of injected locals to one `$context` parameter).
 *
 * @since 1.0.2
 * @api
 *
 * @template TValue
 */
class TemplateContext {

	/**
	 * Underlying data array. Read-only after construction.
	 *
	 * @var array<string, mixed>
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $data Data array (the prior $args).
	 */
	public function __construct( array $data ) {
		$this->data = $data;
	}

	/**
	 * Property-style read. Returns null when the key is absent.
	 *
	 * @param string $name Property name.
	 * @return mixed
	 */
	public function __get( string $name ) {
		return $this->data[ $name ] ?? null;
	}

	/**
	 * Property-style existence check (drives `isset( $context->foo )`).
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function __isset( string $name ): bool {
		return isset( $this->data[ $name ] );
	}

	/**
	 * Get a value with a fallback when the key is absent.
	 *
	 * @param string $name     Property name.
	 * @param mixed  $fallback Value to return when absent.
	 * @return mixed
	 */
	public function get( string $name, $fallback = null ) {
		return $this->data[ $name ] ?? $fallback;
	}

	/**
	 * Check whether a key is present.
	 *
	 * @param string $name Property name.
	 * @return bool
	 */
	public function has( string $name ): bool {
		return array_key_exists( $name, $this->data );
	}

	/**
	 * Return the underlying data as a plain array.
	 *
	 * Provided so callers that pass the context onward to legacy
	 * shortcode/filter callbacks (which expect an array) can do so
	 * without unwrapping property-by-property.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
