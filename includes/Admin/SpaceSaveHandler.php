<?php
/**
 * Space save handler class.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Catalog\AccessibilityFeature;
use NetterTechEvents\Contracts\SpaceRepositoryInterface;
use NetterTechEvents\Enums\SeatingModel;
use NetterTechEvents\Models\Space;

/**
 * Handles form submission for space add/edit.
 *
 * Validates input, sanitizes, saves via SpaceRepository.
 *
 * @since 2.1.0
 */
class SpaceSaveHandler {

	/**
	 * Space repository.
	 *
	 * @var SpaceRepositoryInterface
	 */
	private SpaceRepositoryInterface $repo;

	/**
	 * Constructor.
	 *
	 * @param SpaceRepositoryInterface $repo Space repository.
	 */
	public function __construct( SpaceRepositoryInterface $repo ) {
		$this->repo = $repo;
	}

	/**
	 * Register the admin_post hook.
	 *
	 * @return void
	 */
	public function register(): void {
		add_action( 'admin_post_nettertech_events_save_space', array( $this, 'handle_save' ) );
	}

	/**
	 * Handle form submission.
	 *
	 * @return void
	 */
	public function handle_save(): void {
		// Check permissions first (before nonce to avoid timing attacks).
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'nettertech-events' ) );
		}

		// Verify nonce inline so PHPCS sees the verification in the same
		// scope as the superglobal read below.
		if ( ! isset( $_POST['_nettertech_events_space_nonce'] ) ||
			! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_nettertech_events_space_nonce'] ) ), 'nettertech_events_space_save' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'nettertech-events' ) );
		}

		// Unslash $_POST once at the nonce-verified boundary so helpers can
		// accept already-unslashed data via parameter and PHPCS sees no
		// cross-method superglobal access. Per-field type-specific
		// sanitization continues at each read site in the helpers.
		$post = wp_unslash( $_POST );

		$space_id = $this->get_posted_space_id( $post );
		$data     = $this->build_space_data( $space_id, $post );

		try {
			$this->repo->save( $data );
			$message = $space_id > 0 ? 'updated' : 'created';
		} catch ( \RuntimeException $e ) {
			$this->redirect_to_error( $space_id, $e );
		}

		$this->redirect_to_list( $message );
	}

	/**
	 * Get the posted space ID.
	 *
	 * @param array<string, mixed> $post Unslashed POST data from the nonce-verified boundary.
	 * @return int Space ID, or 0 for create.
	 */
	private function get_posted_space_id( array $post ): int {
		return isset( $post['space_id'] ) ? absint( $post['space_id'] ) : 0;
	}

	/**
	 * Build sanitized space data from the request.
	 *
	 * Receives already-unslashed POST data from the nonce-verified boundary
	 * in handle_save(); applies per-field type-specific sanitization here.
	 *
	 * @param int                  $space_id Existing space ID, or 0 for create.
	 * @param array<string, mixed> $post     Unslashed POST data.
	 * @return array<string, mixed>
	 */
	private function build_space_data( int $space_id, array $post ): array {
		$name = isset( $post['space_name'] ) ? sanitize_text_field( $post['space_name'] ) : '';
		$slug = $this->sanitize_slug( $name, $post );

		$tagline = isset( $post['space_tagline'] )
			? sanitize_text_field( $post['space_tagline'] )
			: '';

		$raw_square_footage    = isset( $post['space_square_footage'] )
			? sanitize_text_field( $post['space_square_footage'] )
			: null;
		$raw_featured_image_id = isset( $post['space_featured_image_id'] )
			? sanitize_text_field( $post['space_featured_image_id'] )
			: null;
		$raw_amenities         = isset( $post['space_amenities'] )
			? sanitize_textarea_field( $post['space_amenities'] )
			: null;
		$raw_status            = isset( $post['space_status'] )
			? sanitize_text_field( $post['space_status'] )
			: null;
		$raw_seating_model     = isset( $post['space_seating_model'] )
			? sanitize_text_field( $post['space_seating_model'] )
			: null;

		$raw_gallery_image_ids = '';
		if ( isset( $post['space_gallery_image_ids'] ) ) {
			if ( is_array( $post['space_gallery_image_ids'] ) ) {
				$raw_gallery_image_ids = array_map( 'sanitize_text_field', $post['space_gallery_image_ids'] );
			} else {
				$raw_gallery_image_ids = sanitize_text_field( $post['space_gallery_image_ids'] );
			}
		}

		$raw_accessibility_features = null;
		if ( isset( $post['space_accessibility_features'] ) ) {
			if ( is_array( $post['space_accessibility_features'] ) ) {
				$raw_accessibility_features = array_map( 'sanitize_text_field', $post['space_accessibility_features'] );
			} else {
				$raw_accessibility_features = sanitize_text_field( $post['space_accessibility_features'] );
			}
		}

		$data = array(
			'name'                   => $name,
			'slug'                   => $slug,
			'tagline'                => '' === $tagline ? null : $tagline,
			'description'            => isset( $post['space_description'] ) ? sanitize_textarea_field( $post['space_description'] ) : '',
			'capacity'               => isset( $post['space_capacity'] ) ? absint( $post['space_capacity'] ) : 0,
			'square_footage'         => $this->sanitize_optional_absint( $raw_square_footage ),
			'featured_image_id'      => $this->sanitize_featured_image_id( $raw_featured_image_id ),
			'sort_order'             => isset( $post['space_sort_order'] ) ? (int) $post['space_sort_order'] : 0,
			'amenities'              => $this->sanitize_optional_textarea( $raw_amenities ),
			'gallery_image_ids'      => $this->sanitize_gallery_ids( $raw_gallery_image_ids ),
			'status'                 => $this->sanitize_status( $raw_status ),
			'seating_model'          => $this->sanitize_seating_model( $raw_seating_model ),
			'accessibility_features' => $this->sanitize_accessibility_features( $raw_accessibility_features ),
		);

		if ( $space_id > 0 ) {
			$data['id'] = $space_id;
		}

		return $data;
	}

	/**
	 * Sanitize slug, generating from the name when omitted.
	 *
	 * @param string               $name Posted space name.
	 * @param array<string, mixed> $post Unslashed POST data from the nonce-verified boundary.
	 * @return string Sanitized slug.
	 */
	private function sanitize_slug( string $name, array $post ): string {
		$slug = isset( $post['space_slug'] ) ? sanitize_title( $post['space_slug'] ) : '';

		if ( empty( $slug ) && ! empty( $name ) ) {
			return sanitize_title( $name );
		}

		return $slug;
	}

	/**
	 * Sanitize optional positive integer input.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return int|null Sanitized integer, or null.
	 */
	private function sanitize_optional_absint( mixed $raw ): ?int {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		return absint( $raw );
	}

	/**
	 * Sanitize optional textarea input.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return string|null Sanitized text, or null.
	 */
	private function sanitize_optional_textarea( mixed $raw ): ?string {
		if ( null === $raw || '' === $raw ) {
			return null;
		}

		return sanitize_textarea_field( (string) $raw );
	}

	/**
	 * Sanitize featured image attachment ID.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return int|null Image attachment ID, or null.
	 */
	private function sanitize_featured_image_id( mixed $raw ): ?int {
		$image_id = $this->sanitize_optional_absint( $raw );

		if ( null === $image_id || 0 === $image_id ) {
			return null;
		}

		$mime = get_post_mime_type( $image_id );
		if ( ! is_string( $mime ) || 0 !== strpos( $mime, 'image/' ) ) {
			return null;
		}

		return $image_id;
	}

	/**
	 * Sanitize status.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return string Valid space status.
	 */
	private function sanitize_status( mixed $raw ): string {
		$status = null !== $raw ? sanitize_text_field( (string) $raw ) : 'active';

		return in_array( $status, Space::STATUSES, true ) ? $status : 'active';
	}

	/**
	 * Sanitize seating model.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return string Valid seating model value.
	 */
	private function sanitize_seating_model( mixed $raw ): string {
		$seating_model = null !== $raw
			? sanitize_text_field( (string) $raw )
			: SeatingModel::FREE->value;

		return in_array( $seating_model, SeatingModel::values(), true )
			? $seating_model
			: SeatingModel::FREE->value;
	}

	/**
	 * Redirect after a failed save.
	 *
	 * @param int               $space_id Space ID, or 0 for create.
	 * @param \RuntimeException $error    Save error.
	 * @return never
	 */
	private function redirect_to_error( int $space_id, \RuntimeException $error ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => SpacesPage::PAGE_SLUG,
					'action'  => $space_id > 0 ? 'edit' : 'add',
					'id'      => $space_id,
					'message' => 'error',
					'error'   => rawurlencode( $error->getMessage() ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Redirect after a successful save.
	 *
	 * @param string $message Result message key.
	 * @return never
	 */
	private function redirect_to_list( string $message ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => SpacesPage::PAGE_SLUG,
					'message' => $message,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize the gallery image IDs input into a JSON-encoded array of ints.
	 *
	 * Accepts either an array (when posted as multiple inputs) or a
	 * comma-separated string. Drops any IDs that don't resolve to an image
	 * attachment. Returns null when nothing valid remains.
	 *
	 * @param mixed $raw Raw posted value.
	 * @return string|null JSON array of attachment IDs, or null when empty.
	 */
	private function sanitize_gallery_ids( mixed $raw ): ?string {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? array() : explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$ids = array();
		foreach ( $raw as $candidate ) {
			$id = absint( $candidate );
			if ( 0 === $id ) {
				continue;
			}
			$mime = get_post_mime_type( $id );
			if ( is_string( $mime ) && 0 === strpos( $mime, 'image/' ) ) {
				$ids[] = $id;
			}
		}

		if ( empty( $ids ) ) {
			return null;
		}

		return (string) wp_json_encode( array_values( array_unique( $ids ) ) );
	}

	/**
	 * Sanitize the accessibility_features input into a JSON-encoded array.
	 *
	 * Accepts either an already-decoded array of entries or a JSON string.
	 * Each entry must include a `key` (string). Preset keys are validated
	 * against the catalog; unknown keys are flagged with `custom: true`.
	 * Optional fields per entry: `count` (positive int), `notes` (text).
	 *
	 * @param mixed $raw Raw posted value.
	 * @return string|null JSON array, or null when no valid entries.
	 */
	private function sanitize_accessibility_features( mixed $raw ): ?string {
		if ( is_string( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw     = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			return null;
		}

		$entries = array();
		$seen    = array();
		foreach ( $raw as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) || ! is_string( $entry['key'] ) ) {
				continue;
			}
			$key = sanitize_key( $entry['key'] );
			if ( '' === $key || isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;

			$out = array( 'key' => $key );

			if ( ! AccessibilityFeature::is_preset( $key ) ) {
				$out['custom'] = true;
			}

			if ( isset( $entry['count'] ) && '' !== $entry['count'] ) {
				$count = absint( $entry['count'] );
				if ( $count > 0 ) {
					$out['count'] = $count;
				}
			}

			if ( isset( $entry['notes'] ) && '' !== trim( (string) $entry['notes'] ) ) {
				$out['notes'] = sanitize_text_field( (string) $entry['notes'] );
			}

			$entries[] = $out;
		}

		return empty( $entries ) ? null : (string) wp_json_encode( $entries );
	}
}
