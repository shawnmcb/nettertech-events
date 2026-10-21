<?php
/**
 * Add-on access to core's shared admin screens.
 *
 * @package NetterTechEvents\Admin
 */

declare(strict_types=1);

namespace NetterTechEvents\Admin;

defined( 'ABSPATH' ) || exit;

use NetterTechEvents\Core\Hooks;

/**
 * Decides who may open the core screens that add-ons extend.
 *
 * Spaces and Settings carry add-on panels and tabs gated on the add-on's own
 * capability. Registered at core's capability alone, those panels and tabs
 * are unreachable for anyone who is not a core manager, however the add-on's
 * capability is granted. Only these two screens can be shared, because only
 * they withhold core's own controls from a user who is not a core manager;
 * opening any other screen this way would hand over all of it.
 *
 * @since 1.5.1
 */
final class ScreenAccess {

	/**
	 * Whether the current user manages core: core's own fields, tabs and actions.
	 *
	 * @return bool
	 */
	public static function can_manage_core(): bool {
		return current_user_can( AdminMenu::CAPABILITY );
	}

	/**
	 * Whether the current user may open a screen.
	 *
	 * @param string $screen Screen slug.
	 * @return bool
	 */
	public static function can_open( string $screen ): bool {
		return '' !== self::held_capability( $screen );
	}

	/**
	 * The capability to register a screen's menu entry with.
	 *
	 * WordPress takes one capability per menu entry, so this names one the
	 * current user holds where there is one, and core's otherwise, which keeps
	 * the entry hidden from them.
	 *
	 * @param string $screen Screen slug.
	 * @return string
	 */
	public static function menu_capability( string $screen ): string {
		$held = self::held_capability( $screen );

		return '' !== $held ? $held : AdminMenu::CAPABILITY;
	}

	/**
	 * The capability a settings tab requires.
	 *
	 * @param string $tab Tab slug.
	 * @return string
	 */
	public static function settings_tab_capability( string $tab ): string {
		return self::settings_tab_capabilities()[ $tab ] ?? AdminMenu::CAPABILITY;
	}

	/**
	 * The first capability that opens the screen and that the user holds.
	 *
	 * Core's comes first so a manager always registers at it.
	 *
	 * @param string $screen Screen slug.
	 * @return string Capability, or '' when the user holds none.
	 */
	private static function held_capability( string $screen ): string {
		if ( self::can_manage_core() ) {
			return AdminMenu::CAPABILITY;
		}

		foreach ( self::extra_capabilities( $screen ) as $capability ) {
			if ( current_user_can( $capability ) ) {
				return $capability;
			}
		}

		return '';
	}

	/**
	 * Capabilities besides core's that open a screen.
	 *
	 * Settings takes its list from the tab map, so a declared tab is all a
	 * user who is not a core manager can reach there. Any screen other than
	 * Spaces and Settings has none.
	 *
	 * @param string $screen Screen slug.
	 * @return string[]
	 */
	private static function extra_capabilities( string $screen ): array {
		if ( AdminMenu::SUBMENU_SETTINGS === $screen ) {
			return array_values( array_unique( self::settings_tab_capabilities() ) );
		}

		if ( SpacesPage::PAGE_SLUG !== $screen ) {
			return array();
		}

		$capabilities = apply_filters( Hooks::ADMIN_SCREEN_CAPABILITIES, array(), $screen );
		if ( ! is_array( $capabilities ) ) {
			return array();
		}

		$clean = array();
		foreach ( $capabilities as $capability ) {
			if ( is_string( $capability ) && '' !== $capability ) {
				$clean[] = $capability;
			}
		}

		return array_values( array_unique( $clean ) );
	}

	/**
	 * Declared extension tab capabilities, with core's tabs removed.
	 *
	 * @return array<string, string> Tab slug => capability.
	 */
	private static function settings_tab_capabilities(): array {
		$declared = apply_filters( Hooks::SETTINGS_TAB_CAPABILITIES, array() );
		if ( ! is_array( $declared ) ) {
			return array();
		}

		$map = array();
		foreach ( $declared as $tab => $capability ) {
			if ( ! is_string( $tab ) || isset( SettingsPage::CORE_TABS[ $tab ] ) ) {
				continue;
			}
			if ( is_string( $capability ) && '' !== $capability ) {
				$map[ $tab ] = $capability;
			}
		}

		return $map;
	}
}
