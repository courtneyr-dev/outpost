<?php
/**
 * Outpost site-wide settings.
 *
 * Phase H. Single option `outpost_settings` (array) holding the
 * site-admin's preferences:
 *
 *   - bridgy_auto_suggest (bool, default true) — whether the composer
 *     shows the "Suggested (from target URL)" Bridgy chip when the
 *     reply target host matches a known silo. Some sites prefer
 *     explicit syndication only.
 *   - default_post_variant (string, default 'article') — which Post-tab
 *     variant the composer opens to. 'article' is the writing default;
 *     sites that mostly post short notes can change to 'note'.
 *   - default_post_format_inference (bool, default true) — controls
 *     the Post-Format auto-inference bridge in C5. Sites that prefer
 *     manual format selection can disable it.
 *
 * The options are exposed to the composer via the composer-config
 * REST endpoint (`siteSettings` field) so the client can react.
 *
 * Capability gate: `manage_options` for both register + render.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Settings {

	private const OPTION_NAME    = 'outpost_settings';
	private const SETTINGS_GROUP = 'outpost_settings_group';

	/**
	 * Defaults applied when a setting is missing from the stored array.
	 *
	 * @return array{
	 *     bridgy_auto_suggest: bool,
	 *     default_post_variant: string,
	 *     default_post_format_inference: bool,
	 * }
	 */
	public static function defaults(): array {
		return array(
			'bridgy_auto_suggest'           => true,
			'default_post_variant'          => 'article',
			'default_post_format_inference' => true,
		);
	}

	/**
	 * Read the current settings, merged with defaults so callers always
	 * get a fully-shaped array regardless of what's persisted.
	 *
	 * @return array<string, mixed>
	 */
	public static function get(): array {
		$stored = get_option( self::OPTION_NAME, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::defaults(), $stored );
	}

	public static function register(): void {
		add_action( 'admin_init', array( self::class, 'register_settings' ) );
	}

	public static function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_NAME,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( self::class, 'sanitize' ),
				'default'           => self::defaults(),
				'show_in_rest'      => false,
			)
		);

		add_settings_section(
			'outpost_settings_main',
			__( 'Composer defaults', 'outpost-mobile-publishing' ),
			array( self::class, 'render_section_intro' ),
			'outpost-settings'
		);

		add_settings_field(
			'default_post_variant',
			__( 'Default Post variant', 'outpost-mobile-publishing' ),
			array( self::class, 'render_default_variant_field' ),
			'outpost-settings',
			'outpost_settings_main'
		);

		add_settings_field(
			'bridgy_auto_suggest',
			__( 'Bridgy auto-suggest', 'outpost-mobile-publishing' ),
			array( self::class, 'render_bridgy_field' ),
			'outpost-settings',
			'outpost_settings_main'
		);

		add_settings_field(
			'default_post_format_inference',
			__( 'Auto Post-Format inference', 'outpost-mobile-publishing' ),
			array( self::class, 'render_inference_field' ),
			'outpost-settings',
			'outpost_settings_main'
		);
	}

	/**
	 * Sanitize incoming option values.
	 *
	 * @param mixed $input Submitted option payload.
	 * @return array<string, mixed>
	 */
	public static function sanitize( $input ): array {
		$defaults = self::defaults();
		if ( ! is_array( $input ) ) {
			return $defaults;
		}
		$variant = isset( $input['default_post_variant'] ) ? (string) $input['default_post_variant'] : '';
		if ( ! in_array( $variant, array( 'note', 'status', 'aside', 'article', 'quote' ), true ) ) {
			$variant = $defaults['default_post_variant'];
		}
		return array(
			'bridgy_auto_suggest'           => ! empty( $input['bridgy_auto_suggest'] ),
			'default_post_variant'          => $variant,
			'default_post_format_inference' => ! empty( $input['default_post_format_inference'] ),
		);
	}

	public static function render_section_intro(): void {
		echo '<p>' . esc_html__(
			'Site-wide defaults the Outpost composer respects. Each user can override at post time from the More options panel.',
			'outpost-mobile-publishing'
		) . '</p>';
	}

	public static function render_default_variant_field(): void {
		$settings = self::get();
		$current  = (string) $settings['default_post_variant'];
		$options  = array(
			'article' => __( 'Article (title + body)', 'outpost-mobile-publishing' ),
			'note'    => __( 'Note (auto-format from content length)', 'outpost-mobile-publishing' ),
			'status'  => __( 'Status (forces the Status post format)', 'outpost-mobile-publishing' ),
			'aside'   => __( 'Aside (forces the Aside post format)', 'outpost-mobile-publishing' ),
			'quote'   => __( 'Quote (forces the Quote post format)', 'outpost-mobile-publishing' ),
		);
		echo '<select name="' . esc_attr( self::OPTION_NAME ) . '[default_post_variant]" id="default_post_variant">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%1$s"%2$s>%3$s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
		echo '<p class="description">' . esc_html__(
			'The variant the Post tab opens to on every fresh composer load.',
			'outpost-mobile-publishing'
		) . '</p>';
	}

	public static function render_bridgy_field(): void {
		$settings = self::get();
		$checked  = ! empty( $settings['bridgy_auto_suggest'] );
		printf(
			'<label><input type="checkbox" name="%1$s[bridgy_auto_suggest]" value="1"%2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__(
				'When the Reply / Doing target URL host matches a known silo, pre-check the matching Bridgy publish target.',
				'outpost-mobile-publishing'
			)
		);
	}

	public static function render_inference_field(): void {
		$settings = self::get();
		$checked  = ! empty( $settings['default_post_format_inference'] );
		printf(
			'<label><input type="checkbox" name="%1$s[default_post_format_inference]" value="1"%2$s /> %3$s</label>',
			esc_attr( self::OPTION_NAME ),
			checked( $checked, true, false ),
			esc_html__(
				'Set the WordPress Post Format automatically based on h-entry signals (likes → link, photos → image / gallery, etc.). Disable if you prefer manual format selection only.',
				'outpost-mobile-publishing'
			)
		);
	}

	/**
	 * Render the form (used by the admin page that hosts the settings).
	 */
	public static function render_form(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'options.php' ) ) . '">';
		settings_fields( self::SETTINGS_GROUP );
		do_settings_sections( 'outpost-settings' );
		submit_button();
		echo '</form>';
	}
}
