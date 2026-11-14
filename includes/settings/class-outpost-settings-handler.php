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
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_FIELD ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::nonce_action( $tab_id ) ) ) {
			wp_die( esc_html__( 'Security check failed. Please reload and try again.', 'outpost-mobile-publishing' ), '', array( 'response' => 403 ) );
		}

		$fields = Outpost_Settings_Registry::get_fields( $tab_id );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$submitted = isset( $_POST['outpost_settings'] ) && is_array( $_POST['outpost_settings'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['outpost_settings'] ) )
			: array();

		// "Remove the saved …" checkboxes (T10-S3-11): explicit deletion only.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified above.
		$clear_in = isset( $_POST['outpost_settings_clear'] ) && is_array( $_POST['outpost_settings_clear'] )
			? array_map( 'sanitize_text_field', wp_unslash( $_POST['outpost_settings_clear'] ) )
			: array();
		$clear    = array_map( 'sanitize_key', array_keys( $clear_in ) );

		self::save_submission( $tab_id, $submitted, $clear );

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
	 * Save one tab's submission. Called by handle_save() after its nonce and
	 * capability checks; public so the save rules can be tested directly.
	 *
	 * @since 1.0.27
	 *
	 * @param string             $tab_id    Tab id.
	 * @param array<string,mixed> $submitted Submitted values (POST['outpost_settings']).
	 * @param string[]           $clear     Field ids whose saved secret the person chose to remove.
	 */
	public static function save_submission( string $tab_id, array $submitted, array $clear ): void {
		$option   = Outpost_Settings_Registry::option_name_for_tab( $tab_id );
		$existing = get_option( $option, array() );
		$saved    = self::sanitize_and_encrypt(
			Outpost_Settings_Registry::get_fields( $tab_id ),
			$submitted,
			is_array( $existing ) ? $existing : array(),
			$clear
		);
		update_option( $option, $saved, false );
	}

	/**
	 * Walk registered fields and produce the storage shape: scalars for
	 * non-sensitive fields; `[ 'encrypted' => '<base64>' ]` for sensitive.
	 *
	 * Sensitive fields (T10-S3-11): a non-empty submission replaces the
	 * stored secret; a blank one keeps it, so saving any other setting never
	 * erases it; listing the field in `$clear` deletes it. If encryption
	 * fails, the stored secret is kept and nothing is written in plaintext.
	 *
	 * @since 0.1.79
	 *
	 * @param array<string,array<string,mixed>> $fields    Registered fields.
	 * @param array<string,mixed>               $submitted Raw POST['outpost_settings'].
	 * @param array<string,mixed>               $existing  The tab's stored option.
	 * @param string[]                          $clear     Field ids to delete.
	 * @return array<string,mixed>
	 */
	public static function sanitize_and_encrypt( array $fields, array $submitted, array $existing = array(), array $clear = array() ): array {
		$saved = array();
		foreach ( $fields as $field_id => $config ) {
			$type      = (string) ( $config['type'] ?? 'text' );
			$sensitive = ! empty( $config['sensitive'] ) || 'password' === $type;
			$raw       = $submitted[ $field_id ] ?? null;
			$clean     = Outpost_Settings_Fields::sanitize( $type, $raw );

			if ( ! $sensitive ) {
				$saved[ $field_id ] = $clean;
				continue;
			}
			if ( in_array( $field_id, $clear, true ) ) {
				continue;
			}
			$kept = self::stored_secret( $existing[ $field_id ] ?? null );
			if ( is_string( $clean ) && '' !== $clean ) {
				try {
					$saved[ $field_id ] = array( 'encrypted' => Outpost_Encryption::encrypt( $clean ) );
					continue;
				} catch ( Outpost_Encryption_Exception $e ) {
					unset( $e );
					// Encryption unavailable: keep what was stored, never plaintext.
				}
			}
			if ( null !== $kept ) {
				$saved[ $field_id ] = $kept;
			}
		}
		return $saved;
	}

	/**
	 * The stored encrypted wrapper for a secret, or null when none is saved.
	 *
	 * @param mixed $stored Raw stored value.
	 * @return array{encrypted: string}|null
	 */
	private static function stored_secret( $stored ): ?array {
		if ( is_array( $stored ) && isset( $stored['encrypted'] ) && is_string( $stored['encrypted'] ) && '' !== $stored['encrypted'] ) {
			return array( 'encrypted' => $stored['encrypted'] );
		}
		return null;
	}

	/**
	 * A tab's values for its settings form: non-secret values as stored, and
	 * for each secret only whether one is saved (bool). Never decrypts.
	 *
	 * @since 1.0.27
	 *
	 * @return array<string,mixed>
	 */
	public static function read_tab_for_form( string $tab_id ): array {
		$stored = get_option( Outpost_Settings_Registry::option_name_for_tab( $tab_id ), array() );
		$stored = is_array( $stored ) ? $stored : array();
		$out    = array();
		foreach ( Outpost_Settings_Registry::get_fields( $tab_id ) as $field_id => $config ) {
			$secret = ! empty( $config['sensitive'] ) || 'password' === ( $config['type'] ?? '' );
			if ( $secret ) {
				$out[ $field_id ] = null !== self::stored_secret( $stored[ $field_id ] ?? null );
			} elseif ( array_key_exists( $field_id, $stored ) ) {
				$out[ $field_id ] = $stored[ $field_id ];
			}
		}
		return $out;
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
