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
 *   - default_categories (int[], default []) — category term IDs the
 *     composer pre-selects in More options, and the Micropub bridge
 *     applies to a post that named no category. Empty means WordPress's
 *     own Settings > Writing default category applies, as before.
 *   - default_tags (string[], default []) — tag names the composer
 *     pre-selects in More options, and the bridge appends to a post
 *     whose request carried no `category[]`.
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

	/** Upper bound on default tags — a default list, not a taxonomy. */
	private const MAX_DEFAULT_TAGS = 25;

	/**
	 * Defaults applied when a setting is missing from the stored array.
	 *
	 * @return array{
	 *     bridgy_auto_suggest: bool,
	 *     default_post_variant: string,
	 *     default_post_format_inference: bool,
	 *     default_categories: int[],
	 *     default_tags: string[],
	 * }
	 */
	public static function defaults(): array {
		return array(
			'bridgy_auto_suggest'           => true,
			'default_post_variant'          => 'article',
			'default_post_format_inference' => true,
			'default_categories'            => array(),
			'default_tags'                  => array(),
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
			'default_categories',
			__( 'Default categories', 'outpost-mobile-publishing' ),
			array( self::class, 'render_default_categories_field' ),
			'outpost-settings',
			'outpost_settings_main'
		);

		add_settings_field(
			'default_tags',
			__( 'Default tags', 'outpost-mobile-publishing' ),
			array( self::class, 'render_default_tags_field' ),
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
			'default_categories'            => self::sanitize_category_ids( $input['default_categories'] ?? array() ),
			'default_tags'                  => self::sanitize_tag_names( $input['default_tags'] ?? '' ),
		);
	}

	/**
	 * Checkbox values from the settings form → positive, unique term IDs.
	 * Existence is checked at read time (default_category_ids()), not
	 * here, so a category deleted later just drops out.
	 *
	 * @param mixed $raw Submitted values.
	 * @return int[]
	 */
	private static function sanitize_category_ids( $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$ids = array();
		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$id = (int) $value;
			if ( $id > 0 && ! in_array( $id, $ids, true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * The comma-separated text field (or an array) → trimmed names,
	 * de-duplicated case-insensitively, capped at MAX_DEFAULT_TAGS.
	 *
	 * @param mixed $raw Submitted value.
	 * @return string[]
	 */
	private static function sanitize_tag_names( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = explode( ',', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$names = array();
		$seen  = array();
		foreach ( $raw as $value ) {
			if ( ! is_scalar( $value ) ) {
				continue;
			}
			$clean = trim( sanitize_text_field( (string) $value ) );
			if ( '' === $clean ) {
				continue;
			}
			$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $clean ) : strtolower( $clean );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$names[]      = $clean;
			if ( count( $names ) >= self::MAX_DEFAULT_TAGS ) {
				break;
			}
		}
		return $names;
	}

	/**
	 * Stored default category IDs that still exist, in stored order.
	 *
	 * @return int[]
	 */
	public static function default_category_ids(): array {
		$settings = self::get();
		$stored   = is_array( $settings['default_categories'] ) ? $settings['default_categories'] : array();
		$ids      = array();
		foreach ( $stored as $raw ) {
			$id = (int) $raw;
			if ( $id > 0 && term_exists( $id, 'category' ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Default category names for the composer (it picks terms by name).
	 *
	 * @return string[]
	 */
	public static function default_category_names(): array {
		$names = array();
		foreach ( self::default_category_ids() as $id ) {
			$term = get_term( $id, 'category' );
			if ( $term instanceof \WP_Term && '' !== (string) $term->name ) {
				$names[] = (string) $term->name;
			}
		}
		return $names;
	}

	/**
	 * Default tag names, as stored.
	 *
	 * @return string[]
	 */
	public static function default_tag_names(): array {
		$settings = self::get();
		$tags     = is_array( $settings['default_tags'] ) ? $settings['default_tags'] : array();
		$out      = array();
		foreach ( $tags as $tag ) {
			if ( is_scalar( $tag ) && '' !== (string) $tag ) {
				$out[] = (string) $tag;
			}
		}
		return $out;
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

	public static function render_default_categories_field(): void {
		$settings = self::get();
		$selected = array_map( 'intval', is_array( $settings['default_categories'] ) ? $settings['default_categories'] : array() );
		$terms    = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 200,
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $terms ) || empty( $terms ) ) {
			echo '<p class="description">' . esc_html__(
				'No categories exist yet. Create one under Posts > Categories first.',
				'outpost-mobile-publishing'
			) . '</p>';
			return;
		}
		echo '<fieldset><legend class="screen-reader-text">' . esc_html__( 'Default categories', 'outpost-mobile-publishing' ) . '</legend>';
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			printf(
				'<label style="display:block;margin-bottom:4px"><input type="checkbox" name="%1$s[default_categories][]" value="%2$d"%3$s /> %4$s</label>',
				esc_attr( self::OPTION_NAME ),
				(int) $term->term_id,
				checked( in_array( (int) $term->term_id, $selected, true ), true, false ),
				esc_html( $term->name )
			);
		}
		echo '</fieldset>';
		echo '<p class="description">' . esc_html__(
			'Pre-selected in the composer\'s More options on every post, and applied to a post from Outpost that names no category. Leave every box unticked to keep WordPress\'s own default category (Settings > Writing).',
			'outpost-mobile-publishing'
		) . '</p>';
	}

	public static function render_default_tags_field(): void {
		$settings = self::get();
		$tags     = is_array( $settings['default_tags'] ) ? $settings['default_tags'] : array();
		printf(
			'<input type="text" class="regular-text" id="default_tags" name="%1$s[default_tags]" value="%2$s" placeholder="%3$s" />',
			esc_attr( self::OPTION_NAME ),
			esc_attr( implode( ', ', $tags ) ),
			esc_attr__( 'indieweb, photos', 'outpost-mobile-publishing' )
		);
		echo '<p class="description">' . esc_html__(
			'Comma-separated tag names, pre-selected in the composer\'s More options. A name that doesn\'t exist yet is created the first time a post uses it. Leave empty for no default tags.',
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
