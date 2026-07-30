<?php
/**
 * Outpost_Settings_Handler (G3.5d).
 *
 * admin-post.php handler for tab save actions. Per-tab routes register
 * as `admin_post_outpost_save_settings_{tab_id}`. The handler:
 *   1. Verifies the per-tab nonce.
 *   2. Verifies the current user has the tab's capability.
 *   3. Sanitizes each registered field per its type.
 *   4. Encrypts sensitive fields via Outpost_Encryption.
 *   5. Saves the merged result to `outpost_settings_{tab_id}`.
 *   6. Redirects back to the tab with a settings-saved notice.
 *
 * Stored values: sensitive fields are stored as a small wrapper array
 * `[ 'encrypted' => '<base64>' ]` so reads can distinguish encrypted
 * data from plaintext on the same key (matters for migrations).
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Settings_Handler {

	public const NONCE_FIELD = 'outpost_settings_nonce';

	/**
	 * Hook the per-tab admin_post handlers. Called once at `init`
	 * (never earlier — iterating tabs translates their labels, and
	 * pre-init translation trips WP 6.7's JIT textdomain notice).
	 *
	 * @since 0.1.79
	 */
	public static function register(): void {
		foreach ( Outpost_Settings_Registry::get_tabs() as $tab_id => $tab_config ) {
			add_action(
				'admin_post_outpost_save_settings_' . $tab_id,
				static function () use ( $tab_id, $tab_config ): void {
					Outpost_Settings_Handler::handle_save( $tab_id, $tab_config );
				}
			);
		}
	}

	/**
	 * Build the nonce action string for a tab.
	 *
	 * @since 0.1.79
	 */
	public static function nonce_action( string $tab_id ): string {
		return 'outpost_settings_save_' . sanitize_key( $tab_id );
	}

	/**
	 * Process a save POST. Public entry point so the registered closure
	 * can delegate; also makes the method directly testable.
	 *
	 * @since 0.1.79
	 *
	 * @param array<string,mixed> $tab_config Tab config from the registry.
	 */
	public static function handle_save( string $tab_id, array $tab_config ): void {
		$tab_id = sanitize_key( $tab_id );
		if ( '' === $tab_id ) {
			wp_die( esc_html__( 'Invalid settings tab.', 'outpost-mobile-publishing' ), '', array( 'response' => 400 ) );
		}

		$capability = isset( $tab_config['capability'] ) ? (string) $tab_config['capability'] : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
			wp_die( esc_html__( 'You do not have permission to update these settings.', 'outpost-mobile-publishing' ), '', array( 'response' => 403 ) );
		}

		$nonce = isset( $_POST[ self::NONCE_FIELD ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $tab_id ) ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload and try again.', 'outpost-mobile-publishing' ), '', array( 'response' => 403 ) );
		}

		$fields = Outpost_Settings_Registry::get_fields( $tab_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$submitted = isset( $_POST['outpost_settings'] ) && is_array( $_POST['outpost_settings'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['outpost_settings'] ) )
			: array();

		$saved = self::sanitize_and_encrypt( $fields, $submitted );

		update_option( Outpost_Settings_Registry::option_name_for_tab( $tab_id ), $saved, false );

		$redirect = add_query_arg(
			array(
				'page'             => Outpost_Settings_Page::PAGE_SLUG,
				'tab'              => $tab_id,
				'settings-updated' => 'true',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Walk registered fields and produce the storage shape: scalars for
	 * non-sensitive fields; `[ 'encrypted' => '<base64>' ]` for sensitive.
	 *
	 * @since 0.1.79
	 *
	 * @param array<string,array<string,mixed>> $fields    Registered fields.
	 * @param array<string,mixed>               $submitted Raw POST['outpost_settings'].
	 * @return array<string,mixed>
	 */
	public static function sanitize_and_encrypt( array $fields, array $submitted ): array {
		$saved = array();
		foreach ( $fields as $field_id => $config ) {
			$type      = (string) ( $config['type'] ?? 'text' );
			$sensitive = ! empty( $config['sensitive'] );
			$raw       = $submitted[ $field_id ] ?? null;
			$clean     = Outpost_Settings_Fields::sanitize( $type, $raw );

			if ( $sensitive && is_string( $clean ) && '' !== $clean ) {
				try {
					$saved[ $field_id ] = array( 'encrypted' => Outpost_Encryption::encrypt( $clean ) );
				} catch ( Outpost_Encryption_Exception $e ) {
					unset( $e );
					// Encryption unavailable; persist nothing for this field
					// rather than leaking plaintext into the option store.
					continue;
				}
			} else {
				$saved[ $field_id ] = $clean;
			}
		}
		return $saved;
	}

	/**
	 * Read and decrypt the stored value for a single field.
	 *
	 * @since 0.1.79
	 *
	 * @param array<string,mixed> $config Field config.
	 * @param mixed               $stored Raw stored value (scalar or wrapper array).
	 * @return mixed Decrypted scalar.
	 */
	public static function decrypt_stored( array $config, $stored ) {
		$sensitive = ! empty( $config['sensitive'] );
		if ( ! $sensitive ) {
			return $stored ?? ( $config['default'] ?? '' );
		}
		if ( is_array( $stored ) && isset( $stored['encrypted'] ) && is_string( $stored['encrypted'] ) ) {
			try {
				return Outpost_Encryption::decrypt( $stored['encrypted'] );
			} catch ( Outpost_Encryption_Exception $e ) {
				unset( $e );
				return '';
			}
		}
		return $config['default'] ?? '';
	}

	/**
	 * Read all settings for a tab with sensitive fields decrypted.
	 *
	 * @since 0.1.79
	 *
	 * @return array<string,mixed>
	 */
	public static function read_tab( string $tab_id ): array {
		$fields = Outpost_Settings_Registry::get_fields( $tab_id );
		$stored = get_option( Outpost_Settings_Registry::option_name_for_tab( $tab_id ), array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$out = array();
		foreach ( $fields as $field_id => $config ) {
			$out[ $field_id ] = self::decrypt_stored( $config, $stored[ $field_id ] ?? null );
		}
		return $out;
	}
}
