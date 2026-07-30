<?php
/**
 * Outpost_Settings_Page (G3.5d).
 *
 * Top-level "Outpost" admin page with multi-tab navigation. Tabs come
 * from `Outpost_Settings_Registry::get_tabs()`; the active tab's
 * callback renders the form body. Save submissions go through
 * Outpost_Settings_Handler via admin-post.php.
 *
 * The existing G3.5a OAuth Connections page (`outpost-oauth`) stays
 * where it is for v1 — migrating it into this tab system is a separate
 * follow-up so this PR doesn't touch shipped UI.
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Settings_Page {

	public const PAGE_SLUG = 'outpost-settings';

	/**
	 * Hook the admin menu registration. Called once on plugins_loaded.
	 *
	 * @since 0.1.79
	 */
	public static function register(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu_page' ) );
	}

	/**
	 * Register the top-level admin menu. Called from the admin_menu action.
	 *
	 * @since 0.1.79
	 */
	public static function add_menu_page(): void {
		add_menu_page(
			__( 'Outpost', 'outpost-mobile-publishing' ),
			__( 'Outpost', 'outpost-mobile-publishing' ),
			'manage_options',
			self::PAGE_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-share',
			80
		);
	}

	/**
	 * Resolve the active tab from query params with fallback to default.
	 * Unknown tab → default tab.
	 *
	 * @since 0.1.79
	 */
	public static function resolve_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';
		$tabs      = Outpost_Settings_Registry::get_tabs();
		if ( '' !== $requested && isset( $tabs[ $requested ] ) ) {
			return $requested;
		}
		// Default: first registered tab, falling back to the registry constant.
		$first = array_key_first( $tabs );
		return is_string( $first ) ? $first : Outpost_Settings_Registry::DEFAULT_TAB;
	}

	/**
	 * Render the settings page chrome (h1, tab nav, active tab body).
	 *
	 * @since 0.1.79
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view these settings.', 'outpost-mobile-publishing' ), '', array( 'response' => 403 ) );
		}

		$tabs       = Outpost_Settings_Registry::get_tabs();
		$active_tab = self::resolve_active_tab();
		$tab_config = $tabs[ $active_tab ] ?? null;

		if ( null === $tab_config ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Outpost Settings', 'outpost-mobile-publishing' ) . '</h1>';
			echo '<p>' . esc_html__( 'No settings tabs are registered.', 'outpost-mobile-publishing' ) . '</p></div>';
			return;
		}

		// Per-tab capability gate.
		$capability = isset( $tab_config['capability'] ) ? (string) $tab_config['capability'] : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to view this settings tab.', 'outpost-mobile-publishing' ), '', array( 'response' => 403 ) );
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Outpost Settings', 'outpost-mobile-publishing' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['settings-updated'] ) && 'true' === $_GET['settings-updated'] ) {
			echo '<div class="notice notice-success is-dismissible"><p>'
				. esc_html__( 'Settings saved.', 'outpost-mobile-publishing' )
				. '</p></div>';
		}
		self::render_tab_nav( $tabs, $active_tab );

		echo '<div class="outpost-settings-tab-body">';
		if ( is_callable( $tab_config['callback'] ) ) {
			call_user_func( $tab_config['callback'], $active_tab );
		} else {
			echo '<p>' . esc_html__( 'Tab callback is not callable.', 'outpost-mobile-publishing' ) . '</p>';
		}
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Render the tab navigation strip using the WP-standard nav-tab-wrapper.
	 *
	 * @since 0.1.79
	 *
	 * @param array<string,array<string,mixed>> $tabs       All tabs.
	 * @param string                            $active_tab Active tab id.
	 */
	private static function render_tab_nav( array $tabs, string $active_tab ): void {
		echo '<nav class="nav-tab-wrapper" aria-label="' . esc_attr__( 'Outpost settings tabs', 'outpost-mobile-publishing' ) . '">';
		foreach ( $tabs as $tab_id => $config ) {
			$capability = isset( $config['capability'] ) ? (string) $config['capability'] : 'manage_options';
			if ( ! current_user_can( $capability ) ) {
				continue;
			}
			$url          = add_query_arg(
				array(
					'page' => self::PAGE_SLUG,
					'tab'  => $tab_id,
				),
				admin_url( 'admin.php' )
			);
			$active_class = $tab_id === $active_tab ? ' nav-tab-active' : '';
			printf(
				'<a href="%1$s" class="nav-tab%2$s">%3$s</a>',
				esc_url( $url ),
				esc_attr( $active_class ),
				esc_html( (string) $config['label'] )
			);
		}
		echo '</nav>';
	}

	/**
	 * Render the form chrome around a tab's fields. Tab callbacks invoke
	 * this helper to get a consistent form, nonce, and submit button
	 * without repeating boilerplate.
	 *
	 * @since 0.1.79
	 *
	 * @param string                                $tab_id    Active tab id.
	 * @param array<string,array<string,mixed>>     $fields    Registered fields.
	 * @param string                                $intro     Optional intro paragraph.
	 */
	public static function render_tab_form( string $tab_id, array $fields, string $intro = '' ): void {
		if ( '' !== $intro ) {
			echo '<p>' . esc_html( $intro ) . '</p>';
		}

		if ( empty( $fields ) ) {
			echo '<p>' . esc_html__( 'No settings registered for this tab yet. Concrete platforms add fields in follow-up updates.', 'outpost-mobile-publishing' ) . '</p>';
			return;
		}

		// Surface a key-not-resolvable banner if any sensitive field is registered
		// but the encryption key isn't available — fail-loud parity with G3.5a.
		$has_sensitive = false;
		foreach ( $fields as $config ) {
			if ( ! empty( $config['sensitive'] ) ) {
				$has_sensitive = true;
				break;
			}
		}
		if ( $has_sensitive ) {
			$key_resolvable = Outpost_Encryption_Key_Resolver::constant_is_defined()
				|| Outpost_Encryption_Key_Resolver::option_value_exists();
			if ( ! $key_resolvable ) {
				echo '<div class="notice notice-error"><p>'
					. esc_html__( 'Outpost encryption key is not configured. Sensitive settings cannot be saved until the key is set.', 'outpost-mobile-publishing' )
					. '</p></div>';
				return;
			}
		}

		$values     = Outpost_Settings_Handler::read_tab( $tab_id );
		$action_url = admin_url( 'admin-post.php' );

		echo '<form method="post" action="' . esc_url( $action_url ) . '">';
		printf( '<input type="hidden" name="action" value="outpost_save_settings_%s" />', esc_attr( $tab_id ) );
		wp_nonce_field( Outpost_Settings_Handler::nonce_action( $tab_id ), Outpost_Settings_Handler::NONCE_FIELD );
		echo '<table class="form-table" role="presentation"><tbody>';
		foreach ( $fields as $field_id => $config ) {
			Outpost_Settings_Fields::render( $field_id, $config, $values[ $field_id ] ?? ( $config['default'] ?? '' ) );
		}
		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'outpost-mobile-publishing' ) );
		echo '</form>';
	}
}
