<?php
/**
 * Outpost_Appearance_Settings_Page
 *
 * Settings → Outpost (top-level) → Appearance.
 *
 * The page renders:
 *   - Mode picker (Always day / Always night / Match system)
 *   - Per-mode color override fields with "from theme / default"
 *     source badges + per-token contrast warnings + "Override
 *     anyway" toggles for failing tokens
 *   - Per-mode font override fields (paste-the-font-family-value text
 *     fields — Outpost doesn't host a font library)
 *   - Live preview iframe (srcdoc-based, sandboxed) showing a subset
 *     of composer components rendered with the current form values
 *
 * The form persists via admin-post.php → REST POST forwarder so the
 * REST endpoint stays the single write path. The page enqueues a
 * small inline script that updates the iframe's srcdoc when any
 * form input changes — instant feedback, no save required.
 *
 * Rendered as a sub-menu under the existing top-level Outpost admin
 * menu (slug `outpost`).
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Appearance_Settings_Page {

	public const PARENT_SLUG  = 'outpost';
	public const PAGE_SLUG    = 'outpost-appearance';
	public const NONCE_NAME   = 'outpost_appearance_nonce';
	public const NONCE_ACTION = 'outpost_appearance_save';
	public const SAVE_ACTION  = 'outpost_appearance_save_settings';

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_post_' . self::SAVE_ACTION, array( self::class, 'handle_save' ) );
	}

	public static function register_menu(): void {
		add_submenu_page(
			self::PARENT_SLUG,
			__( 'Appearance', 'outpost-mobile-publishing' ),
			__( 'Appearance', 'outpost-mobile-publishing' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost-mobile-publishing' ) );
		}
		$user_id   = (int) get_current_user_id();
		$mode_pref = Outpost_Mode_Controller::get_mode( $user_id );
		$source    = Outpost_Style_Source::get_source( $user_id );
		$day       = Outpost_Token_Resolver::resolve( $user_id, 'day', $source );
		$night     = Outpost_Token_Resolver::resolve( $user_id, 'night', $source );

		?>
		<div class="wrap outpost-appearance-wrap">
			<h1><?php esc_html_e( 'Outpost Appearance', 'outpost-mobile-publishing' ); ?></h1>

			<?php self::render_admin_notice(); ?>

			<p><?php esc_html_e( 'Choose how the Outpost composer looks for you. Style source picks where colors and fonts come from: the built-in Outpost Default design, or your active theme. Appearance picks Light, Dark, or System. Both are per-user, and the composer\'s Preferences (the gear button, or About › Preferences) change the same values. You can also override individual tokens below.', 'outpost-mobile-publishing' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="outpost-appearance-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<h2><?php esc_html_e( 'Style source', 'outpost-mobile-publishing' ); ?></h2>
				<fieldset class="outpost-appearance-source">
					<legend class="screen-reader-text"><?php esc_html_e( 'Style source', 'outpost-mobile-publishing' ); ?></legend>
					<?php foreach ( self::source_options() as $value => $label ) : ?>
						<label class="outpost-appearance-mode__option">
							<input
								type="radio"
								name="style_source"
								value="<?php echo esc_attr( $value ); ?>"
								<?php checked( $source, $value ); ?>
							>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<h2><?php esc_html_e( 'Appearance', 'outpost-mobile-publishing' ); ?></h2>
				<fieldset class="outpost-appearance-mode">
					<legend class="screen-reader-text"><?php esc_html_e( 'Light, Dark, or System', 'outpost-mobile-publishing' ); ?></legend>
					<?php foreach ( self::mode_options() as $value => $label ) : ?>
						<label class="outpost-appearance-mode__option">
							<input
								type="radio"
								name="mode_preference"
								value="<?php echo esc_attr( $value ); ?>"
								<?php checked( $mode_pref, $value ); ?>
							>
							<?php echo esc_html( $label ); ?>
						</label>
					<?php endforeach; ?>
				</fieldset>

				<?php
				self::render_mode_fields( 'day', __( 'Day mode', 'outpost-mobile-publishing' ), $day );
				self::render_mode_fields( 'night', __( 'Night mode', 'outpost-mobile-publishing' ), $night );
				?>

				<h2><?php esc_html_e( 'Live preview', 'outpost-mobile-publishing' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Reflects unsaved values. Switch the preview between day and night to verify both modes before saving.', 'outpost-mobile-publishing' ); ?></p>

				<div class="outpost-appearance-preview-controls">
					<label>
						<input type="radio" name="preview_mode" value="day" checked>
						<?php esc_html_e( 'Day preview', 'outpost-mobile-publishing' ); ?>
					</label>
					<label>
						<input type="radio" name="preview_mode" value="night">
						<?php esc_html_e( 'Night preview', 'outpost-mobile-publishing' ); ?>
					</label>
				</div>

				<iframe
					class="outpost-appearance-preview"
					title="<?php esc_attr_e( 'Outpost composer preview', 'outpost-mobile-publishing' ); ?>"
					sandbox="allow-same-origin"
					srcdoc="<?php echo esc_attr( self::build_preview_html( $day, 'day' ) ); ?>"
					data-day-tokens="<?php echo esc_attr( wp_json_encode( $day ) ); ?>"
					data-night-tokens="<?php echo esc_attr( wp_json_encode( $night ) ); ?>"
					style="width:100%;height:520px;border:1px solid var(--cr-border, #ccc);background:#fff;"
				></iframe>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save appearance settings', 'outpost-mobile-publishing' ); ?>
					</button>
				</p>
			</form>

			<?php self::render_inline_script(); ?>
			<?php self::render_inline_styles(); ?>
		</div>
		<?php
	}

	/**
	 * admin-post.php handler. The nonce and the `edit_posts` check run
	 * first, here, and stop the request when either fails; then the
	 * fields are forwarded into the REST controller's POST handler so
	 * there's a single write path. Redirects back with a notice.
	 */
	public static function handle_save(): void {
		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'outpost-mobile-publishing' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage appearance preferences.', 'outpost-mobile-publishing' ) );
		}

		$user_id   = (int) get_current_user_id();
		$mode_pref = isset( $_POST['mode_preference'] ) ? sanitize_key( wp_unslash( $_POST['mode_preference'] ) ) : '';
		$source_in = isset( $_POST['style_source'] ) ? sanitize_key( wp_unslash( $_POST['style_source'] ) ) : '';
		// Bypass-contrast checkboxes — names like `bypass_contrast[text]` etc.
		$bypass_in = isset( $_POST['bypass_contrast'] ) && is_array( $_POST['bypass_contrast'] )
			? self::checked_keys( array_map( 'rest_sanitize_boolean', wp_unslash( (array) $_POST['bypass_contrast'] ) ) )
			: array();

		$payload = array(
			'mode_preference' => $mode_pref,
			'style_source'    => $source_in,
			'bypass_contrast' => $bypass_in,
		);
		foreach ( array( 'day', 'night' ) as $mode ) {
			$colors_in        = isset( $_POST[ 'colors_' . $mode ] ) && is_array( $_POST[ 'colors_' . $mode ] )
				? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST[ 'colors_' . $mode ] ) )
				: array();
			$fonts_in         = isset( $_POST[ 'fonts_' . $mode ] ) && is_array( $_POST[ 'fonts_' . $mode ] )
				? array_map( 'sanitize_text_field', wp_unslash( (array) $_POST[ 'fonts_' . $mode ] ) )
				: array();
			$payload[ $mode ] = array(
				'colors' => self::clean_string_map( $colors_in ),
				'fonts'  => self::clean_string_map( $fonts_in ),
			);
		}

		// Forward into REST handler. We stand up a synthetic
		// WP_REST_Request so validation + persistence + cache
		// invalidation logic stays in one place.
		$request = new \WP_REST_Request( 'POST', '/outpost/v1' . Outpost_Appearance_REST_Controller::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $payload ) );

		$response = Outpost_Appearance_REST_Controller::handle_post( $request );

		if ( $response instanceof \WP_Error ) {
			// The message rides in a short-lived per-user transient, not the
			// redirect URL, so the notice never prints text a link supplied
			// (T10-S3-13, F-11). It is shown once, escaped, on this screen.
			set_transient( self::notice_key( $user_id ), $response->get_error_message(), MINUTE_IN_SECONDS );
			self::redirect_with_notice( 'error' );
			return;
		}

		self::redirect_with_notice( 'saved' );
	}

	/**
	 * @param array<string,mixed> $resolved
	 */
	private static function render_mode_fields( string $mode_key, string $label, array $resolved ): void {
		?>
		<h2><?php echo esc_html( $label ); ?></h2>
		<table class="form-table outpost-appearance-mode-fields" role="presentation" data-mode="<?php echo esc_attr( $mode_key ); ?>">
			<tbody>
				<tr>
					<th colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'Colors', 'outpost-mobile-publishing' ); ?></h3></th>
				</tr>
				<?php foreach ( self::color_field_labels() as $slug => $field_label ) : ?>
					<?php self::render_color_row( $mode_key, $slug, $field_label, $resolved ); ?>
				<?php endforeach; ?>
				<tr>
					<th colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'Fonts', 'outpost-mobile-publishing' ); ?></h3></th>
				</tr>
				<?php foreach ( self::font_field_labels() as $slug => $field_label ) : ?>
					<?php self::render_font_row( $mode_key, $slug, $field_label, $resolved ); ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string,mixed> $resolved
	 */
	private static function render_color_row( string $mode_key, string $slug, string $field_label, array $resolved ): void {
		$value      = (string) ( $resolved['colors'][ $slug ] ?? '' );
		$source     = (string) ( $resolved['sources'][ 'colors.' . $slug ] ?? 'default' );
		$adjusted   = $resolved['adjusted'][ $slug ] ?? null;
		$input_id   = 'outpost-color-' . $mode_key . '-' . $slug;
		$input_name = 'colors_' . $mode_key . '[' . $slug . ']';
		?>
		<tr class="outpost-appearance-row outpost-appearance-row--color">
			<th scope="row">
				<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field_label ); ?></label>
			</th>
			<td>
				<input
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $input_name ); ?>"
					type="text"
					class="regular-text code outpost-appearance-color-input"
					value="<?php echo esc_attr( self::value_or_blank( $value, $source ) ); ?>"
					placeholder="<?php echo esc_attr( $value ); ?>"
					data-mode="<?php echo esc_attr( $mode_key ); ?>"
					data-slug="<?php echo esc_attr( $slug ); ?>"
				>
				<span class="outpost-appearance-source-badge outpost-appearance-source-badge--<?php echo esc_attr( $source ); ?>">
					<?php echo esc_html( self::source_label( $source ) ); ?>
				</span>
				<?php if ( is_array( $adjusted ) ) : ?>
					<p class="outpost-appearance-warning">
						<strong><?php esc_html_e( 'Contrast adjustment applied.', 'outpost-mobile-publishing' ); ?></strong>
						<?php
						printf(
							/* translators: 1: original color hex, 2: adjusted hex, 3: ratio before, 4: ratio after. */
							esc_html__( 'Your %1$s rendered at %3$s:1 against the surface; auto-adjusted to %2$s for %4$s:1.', 'outpost-mobile-publishing' ),
							esc_html( (string) ( $adjusted['original'] ?? '' ) ),
							esc_html( (string) ( $adjusted['applied'] ?? '' ) ),
							esc_html( (string) round( (float) ( $adjusted['ratio_before'] ?? 0 ), 2 ) ),
							esc_html( (string) round( (float) ( $adjusted['ratio_after'] ?? 0 ), 2 ) )
						);
						?>
						<label class="outpost-appearance-bypass">
							<input
								type="checkbox"
								name="bypass_contrast[<?php echo esc_attr( $slug ); ?>]"
								value="1"
								<?php checked( self::is_bypassed( $slug ) ); ?>
							>
							<?php esc_html_e( 'Override anyway (keep my color, accept the lower contrast)', 'outpost-mobile-publishing' ); ?>
						</label>
					</p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}

	/**
	 * @param array<string,mixed> $resolved
	 */
	private static function render_font_row( string $mode_key, string $slug, string $field_label, array $resolved ): void {
		$value      = (string) ( $resolved['fonts'][ $slug ] ?? '' );
		$source     = (string) ( $resolved['sources'][ 'fonts.' . $slug ] ?? 'default' );
		$input_id   = 'outpost-font-' . $mode_key . '-' . $slug;
		$input_name = 'fonts_' . $mode_key . '[' . $slug . ']';
		?>
		<tr class="outpost-appearance-row outpost-appearance-row--font">
			<th scope="row">
				<label for="<?php echo esc_attr( $input_id ); ?>"><?php echo esc_html( $field_label ); ?></label>
			</th>
			<td>
				<input
					id="<?php echo esc_attr( $input_id ); ?>"
					name="<?php echo esc_attr( $input_name ); ?>"
					type="text"
					class="regular-text outpost-appearance-font-input"
					value="<?php echo esc_attr( self::value_or_blank( $value, $source ) ); ?>"
					placeholder="<?php echo esc_attr( $value ); ?>"
					data-mode="<?php echo esc_attr( $mode_key ); ?>"
					data-slug="<?php echo esc_attr( $slug ); ?>"
				>
				<span class="outpost-appearance-source-badge outpost-appearance-source-badge--<?php echo esc_attr( $source ); ?>">
					<?php echo esc_html( self::source_label( $source ) ); ?>
				</span>
				<p class="description">
					<?php esc_html_e( 'Paste a CSS font-family value. Outpost picks up custom fonts your theme already loads — installing fonts is your theme\'s job.', 'outpost-mobile-publishing' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	/** Per-user transient that carries a save error to the next page view. */
	private static function notice_key( int $user_id ): string {
		return 'outpost_appearance_notice_' . $user_id;
	}

	private static function render_admin_notice(): void {
		$notice = Outpost_Request_Headers::query_key( 'notice' );
		switch ( $notice ) {
			case 'saved':
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html__( 'Appearance settings saved.', 'outpost-mobile-publishing' )
				);
				break;
			case 'error':
				$key     = self::notice_key( (int) get_current_user_id() );
				$stored  = get_transient( $key );
				$message = is_string( $stored ) ? $stored : '';
				delete_transient( $key );
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					esc_html( '' === $message ? __( 'Save failed.', 'outpost-mobile-publishing' ) : $message )
				);
				break;
		}
	}

	private static function redirect_with_notice( string $notice ): void {
		$args = array(
			'page'   => self::PAGE_SLUG,
			'notice' => $notice,
		);
		$url  = add_query_arg( $args, admin_url( 'admin.php' ) );
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			wp_safe_redirect( $url );
			exit;
		}
	}

	/**
	 * Build the iframe srcdoc HTML — a self-contained mini-document
	 * that demonstrates the composer's key components rendered with
	 * the resolved tokens. Sandboxed (allow-same-origin only — no
	 * scripts) so it can't escape into the parent admin page.
	 *
	 * Subset of components shown:
	 *   - Tab bar (3 tabs, middle one active)
	 *   - Section heading ("Doing")
	 *   - Form input (text field with placeholder)
	 *   - Radio group (3 options, one selected)
	 *   - Primary button ("Post")
	 *   - Voice icon
	 *
	 * @param array<string,mixed> $resolved
	 */
	public static function build_preview_html( array $resolved, string $mode ): string {
		$root_class = 'outpost-mode-' . ( 'night' === $mode ? 'night' : 'day' );
		$body       = self::preview_body_html();
		$lang       = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : 'en-US';
		$preview    = '<!doctype html>'
			. '<html lang="' . esc_attr( $lang ) . '">'
			. '<head>'
			. '<meta charset="utf-8">'
			. '<title>Outpost preview</title>'
			. self::preview_style_markup( $resolved, $mode )
			. '</head>'
			. '<body class="' . esc_attr( $root_class ) . '">'
			. $body
			. '</body>'
			. '</html>';
		return $preview;
	}

	/**
	 * Build the srcdoc document's style markup through the enqueue API.
	 *
	 * The preview is a self-contained iframe srcdoc document, so its
	 * styles can't ride the parent page's wp_head — instead they are
	 * registered as inline styles and printed with do_items() against an
	 * explicit handle list, the same standalone-document pattern the PWA
	 * shell uses. Handles are per-mode because WP_Styles marks a handle
	 * done after printing, and the day and night previews each need their
	 * own token block.
	 *
	 * The live-update script in the parent page finds the printed token
	 * block by its id prefix (style[id^="outpost-preview-tokens"]) and
	 * rewrites its contents as the user edits — keep the handle names in
	 * step with that selector.
	 *
	 * The token block carries the resolved component tokens too: the tab
	 * rules paint from them, with the same contrast-checked values
	 * to_scoped_css() gives /post/.
	 *
	 * @param array<string,mixed> $resolved {@see Outpost_Token_Resolver::resolve()} output.
	 * @param string              $mode     'day' or 'night'.
	 * @return string The printed style tags for the srcdoc head.
	 */
	private static function preview_style_markup( array $resolved, string $mode ): string {
		$suffix        = 'night' === $mode ? 'night' : 'day';
		$tokens_handle = 'outpost-preview-tokens-' . $suffix;
		$static_handle = 'outpost-preview-static-' . $suffix;

		wp_register_style( $tokens_handle, false, array(), OUTPOST_VERSION );
		wp_add_inline_style( $tokens_handle, Outpost_Token_Resolver::to_css( $resolved ) . self::component_declarations( $resolved, $suffix ) );
		wp_register_style( $static_handle, false, array(), OUTPOST_VERSION );
		wp_add_inline_style( $static_handle, self::preview_static_styles() );

		ob_start();
		wp_styles()->do_items( array( $tokens_handle, $static_handle ) );
		return (string) ob_get_clean();
	}

	/**
	 * The resolved component tokens as one `.outpost-mode-{mode}` block of
	 * `--outpost-*` declarations. Names and values pass the same checks
	 * to_scoped_css() applies.
	 *
	 * @param array<string,mixed> $resolved
	 */
	private static function component_declarations( array $resolved, string $mode ): string {
		$components = is_array( $resolved['components'] ?? null ) ? $resolved['components'] : array();
		$lines      = array();
		foreach ( $components as $name => $value ) {
			if ( 1 !== preg_match( '/^[a-z0-9-]+$/', (string) $name ) ) {
				continue;
			}
			$clean = Outpost_Token_Resolver::safe_css_value( (string) $value );
			if ( '' !== $clean ) {
				$lines[] = "\t--outpost-" . $name . ': ' . $clean . ';';
			}
		}
		if ( array() === $lines ) {
			return '';
		}
		return "\n.outpost-mode-" . $mode . " {\n" . implode( "\n", $lines ) . "\n}";
	}

	private static function preview_static_styles(): string {
		// Mini structural styles for the preview only. All paint goes
		// through --outpost-* references; no hex values here. The tab
		// rules are structure.css's own, unchanged (#324); a unit test
		// fails when they drift apart.
		return '
			body { margin: 0; padding: 16px; font-family: var(--outpost-font-body); background: var(--outpost-bg); color: var(--outpost-text); }
			.outpost-tablist-wrap {
				position: relative;
				border-block-end: 1px solid var(--outpost-border);
			}
			.outpost-tablist {
				display: grid;
				grid-template-columns: repeat(4, minmax(0, 1fr));
				gap: 0.25rem;
				padding: 0.5rem 0.25rem 0;
			}
			.outpost-tab {
				min-height: 2.75rem;
				padding: 0.5rem 0.125rem;
				border: 1px solid var(--outpost-card-border, currentColor);
				border-radius: var(--outpost-radius, 0.375rem);
				font: inherit;
				font-weight: 500;
				text-align: center;
				overflow-wrap: anywhere;
				cursor: pointer;
				background: transparent;
				color: inherit;
			}
			.outpost-tab:focus {
				outline: 2px solid var(--outpost-focus, currentColor);
				outline-offset: 2px;
			}
			.outpost-tab[aria-current="true"] {
				background: var(--outpost-tab-bg-active);
				color: var(--outpost-tab-fg-active);
				border-color: var(--outpost-tab-indicator);
				box-shadow: inset 0 0 0 2px var(--outpost-tab-indicator);
				font-weight: 700;
			}
			.outpost-tab:not([aria-current="true"]) {
				color: var(--outpost-tab-fg);
			}
			.outpost-mode-day .outpost-tab:not(.outpost-tab--plain),
			.outpost-mode-night .outpost-tab:not(.outpost-tab--plain),
			.outpost-mode-system .outpost-tab:not(.outpost-tab--plain) {
				font-family: var(--outpost-font-family-display);
				font-style: italic;
				font-size: 1rem;
				padding-inline: 0.125rem;
			}
			@media (forced-colors: active) {
				.outpost-tab[aria-current="true"] {
					outline: 2px solid Highlight;
					outline-offset: -2px;
				}
			}
			h2.outpost-preview-heading { font-family: var(--outpost-font-display); font-size: var(--outpost-size-display); margin: 16px 0 8px; color: var(--outpost-text); }
			.outpost-preview-input { width: 100%; padding: 8px 12px; border: 1px solid var(--outpost-border); border-radius: 4px; background: var(--outpost-surface); color: var(--outpost-text); font-family: inherit; font-size: var(--outpost-size-body); }
			.outpost-preview-radios { display: flex; gap: 16px; margin: 12px 0; flex-wrap: wrap; }
			.outpost-preview-radios label { display: inline-flex; align-items: center; gap: 8px; }
			.outpost-preview-button { background: var(--outpost-accent); color: var(--outpost-surface); border: 0; padding: 12px 24px; font-family: inherit; font-size: var(--outpost-size-body); border-radius: 4px; cursor: pointer; margin-top: 12px; }
			.outpost-preview-mic { display: inline-flex; align-items: center; justify-content: center; width: 40px; height: 40px; border-radius: 50%; border: 1px solid var(--outpost-border); background: var(--outpost-surface); color: var(--outpost-accent); font-size: 1.5rem; vertical-align: middle; margin-left: 8px; }
		';
	}

	private static function preview_body_html(): string {
		return '
			<div class="outpost-tablist-wrap"><div role="group" aria-label="Composer modes" class="outpost-tablist">'
			. '<button type="button" class="outpost-tab">Post</button>'
			. '<button type="button" class="outpost-tab">Reply</button>'
			. '<button type="button" class="outpost-tab">Photo</button>'
			. '<button type="button" class="outpost-tab" aria-current="true">Doing</button>'
			. '<button type="button" class="outpost-tab">Life</button>'
			. '<button type="button" class="outpost-tab">Recipe</button>'
			. '<button type="button" class="outpost-tab">About</button>'
			. '</div></div>
			<h2 class="outpost-preview-heading">Doing</h2>
			<input class="outpost-preview-input" type="text" placeholder="https://example.com/track" readonly>
			<div class="outpost-preview-radios">
				<label><input type="radio" disabled> Listen</label>
				<label><input type="radio" disabled checked> Watch</label>
				<label><input type="radio" disabled> Read</label>
			</div>
			<button type="button" class="outpost-preview-button">Post</button>
			<span class="outpost-preview-mic" aria-hidden="true">🎤</span>
		';
	}

	/** @return array<string,string> */
	private static function mode_options(): array {
		return array(
			Outpost_Mode_Controller::MODE_DAY    => __( 'Light', 'outpost-mobile-publishing' ),
			Outpost_Mode_Controller::MODE_NIGHT  => __( 'Dark', 'outpost-mobile-publishing' ),
			Outpost_Mode_Controller::MODE_SYSTEM => __( 'System', 'outpost-mobile-publishing' ),
		);
	}

	/** @return array<string,string> */
	private static function source_options(): array {
		return array(
			Outpost_Style_Source::SOURCE_OUTPOST => __( 'Outpost Default', 'outpost-mobile-publishing' ),
			Outpost_Style_Source::SOURCE_SITE    => __( 'Use Site Theme', 'outpost-mobile-publishing' ),
		);
	}

	/** @return array<string,string> */
	private static function color_field_labels(): array {
		return array(
			'bg'             => __( 'Background', 'outpost-mobile-publishing' ),
			'surface'        => __( 'Surface', 'outpost-mobile-publishing' ),
			'text'           => __( 'Text', 'outpost-mobile-publishing' ),
			'text_secondary' => __( 'Text (secondary)', 'outpost-mobile-publishing' ),
			'accent'         => __( 'Accent (primary)', 'outpost-mobile-publishing' ),
			'accent_2'       => __( 'Accent (second)', 'outpost-mobile-publishing' ),
			'border'         => __( 'Border', 'outpost-mobile-publishing' ),
		);
	}

	/** @return array<string,string> */
	private static function font_field_labels(): array {
		return array(
			'body'      => __( 'Body font', 'outpost-mobile-publishing' ),
			'display'   => __( 'Display font (headings)', 'outpost-mobile-publishing' ),
			'monospace' => __( 'Monospace (URL fields)', 'outpost-mobile-publishing' ),
		);
	}

	private static function source_label( string $source ): string {
		switch ( $source ) {
			case 'override':
				return __( 'overridden', 'outpost-mobile-publishing' );
			case 'theme':
				return __( 'from theme', 'outpost-mobile-publishing' );
			case 'default':
			default:
				return __( 'default', 'outpost-mobile-publishing' );
		}
	}

	private static function value_or_blank( string $value, string $source ): string {
		// Show the override value when set; show empty when the value
		// is inherited (theme/default) so the user knows the field is
		// empty unless they override.
		return 'override' === $source ? $value : '';
	}

	private static function is_bypassed( string $slug ): bool {
		$user_id  = (int) get_current_user_id();
		$override = get_user_meta( $user_id, Outpost_Token_Resolver::OVERRIDE_META_KEY, true );
		if ( ! is_array( $override ) ) {
			return false;
		}
		$bypass = $override['bypass_contrast'] ?? array();
		return is_array( $bypass ) && in_array( $slug, $bypass, true );
	}

	/**
	 * @param array<string,mixed> $map
	 * @return array<string,string>
	 */
	private static function clean_string_map( array $map ): array {
		$out = array();
		foreach ( $map as $key => $value ) {
			$key = is_string( $key ) ? sanitize_key( $key ) : '';
			if ( '' === $key ) {
				continue;
			}
			$out[ $key ] = is_string( $value ) ? trim( $value ) : '';
		}
		return $out;
	}

	private static function render_inline_script(): void {
		// Attached to a src-less handle so it prints through the
		// WordPress script API (in the admin footer, after the form and
		// iframe markup it reads).
		if ( ! wp_script_is( 'outpost-appearance-preview', 'registered' ) ) {
			wp_register_script( 'outpost-appearance-preview', false, array(), OUTPOST_VERSION, true );
		}
		wp_enqueue_script( 'outpost-appearance-preview' );
		wp_add_inline_script( 'outpost-appearance-preview', self::preview_script() );
	}

	/**
	 * Minimal vanilla JS: watch form inputs, rewrite the iframe's token
	 * block whenever a value changes. No bundler, no dependencies.
	 */
	public static function preview_script(): string {
		return <<<'JS'
		(function () {
			var iframe = document.querySelector('.outpost-appearance-preview');
			if (!iframe) return;
			var form = iframe.closest('form');
			if (!form) return;
			var dayBaseline = JSON.parse(iframe.getAttribute('data-day-tokens') || '{}');
			var nightBaseline = JSON.parse(iframe.getAttribute('data-night-tokens') || '{}');

			function applyOverrides(baseline, modeKey) {
				var resolved = JSON.parse(JSON.stringify(baseline));
				if (!resolved.colors) resolved.colors = {};
				if (!resolved.fonts) resolved.fonts = {};
				form.querySelectorAll('.outpost-appearance-color-input').forEach(function (input) {
					if (input.dataset.mode !== modeKey) return;
					var v = input.value.trim();
					if (v) resolved.colors[input.dataset.slug] = v;
				});
				form.querySelectorAll('.outpost-appearance-font-input').forEach(function (input) {
					if (input.dataset.mode !== modeKey) return;
					var v = input.value.trim();
					if (v) resolved.fonts[input.dataset.slug] = v;
				});
				return resolved;
			}

			function tokensCss(resolved, modeKey) {
				var lines = ['.outpost-mode-' + modeKey + ' {'];
				Object.keys(resolved.colors || {}).forEach(function (slug) {
					var name = '--outpost-' + slug.replace(/_/g, '-');
					lines.push('\t' + name + ': ' + resolved.colors[slug] + ';');
				});
				Object.keys(resolved.fonts || {}).forEach(function (slug) {
					var name = '--outpost-font-' + slug.replace(/_/g, '-');
					lines.push('\t' + name + ': ' + resolved.fonts[slug] + ';');
				});
				Object.keys(resolved.sizes || {}).forEach(function (slug) {
					var name = '--outpost-size-' + slug.replace(/_/g, '-');
					lines.push('\t' + name + ': ' + resolved.sizes[slug] + ';');
				});
				lines.push('}');
				// The saved component tokens for this mode; the tab rules
				// paint from them. Same name and value checks as the server.
				var components = resolved.components || {};
				var componentLines = [];
				Object.keys(components).forEach(function (name) {
					var value = String(components[name]).trim();
					if (!/^[a-z0-9-]+$/.test(name) || !value || value.length > 256) return;
					if (/[\x00-\x1F\x7F;{}<>\\]|\/\*|\*\/|url\s*\(|expression\s*\(|@import/i.test(value)) return;
					componentLines.push('\t--outpost-' + name + ': ' + value + ';');
				});
				if (componentLines.length) {
					lines.push('.outpost-mode-' + modeKey + ' {');
					lines.push.apply(lines, componentLines);
					lines.push('}');
				}
				return lines.join('\n');
			}

			function refreshIframe() {
				var modeRadio = form.querySelector('input[name="preview_mode"]:checked');
				var modeKey = modeRadio ? modeRadio.value : 'day';
				var baseline = modeKey === 'night' ? nightBaseline : dayBaseline;
				var resolved = applyOverrides(baseline, modeKey);
				var css = tokensCss(resolved, modeKey);
				try {
					var doc = iframe.contentDocument;
					if (!doc) return;
					var styleEl = doc.querySelector('style[id^="outpost-preview-tokens"]');
					if (styleEl) {
						styleEl.textContent = css;
					}
					doc.body.className = 'outpost-mode-' + modeKey;
				} catch (e) {
					// cross-origin sandboxing; falls back to no live update.
				}
			}

			form.addEventListener('input', refreshIframe);
			form.addEventListener('change', refreshIframe);
		})();
JS;
	}

	private static function render_inline_styles(): void {
		// Settings page chrome only — nothing about the composer paint.
		$css = '.outpost-appearance-source-badge { font-size: 0.85em; padding: 2px 6px; margin-left: 8px; border-radius: 3px; background: #f0f0f1; color: #50575e; vertical-align: middle; }'
			. '.outpost-appearance-source-badge--override { background: #d4f0e0; color: #1a5f3f; }'
			. '.outpost-appearance-warning { background: #fff8e5; border-left: 4px solid #d4901a; padding: 8px 12px; margin: 8px 0; }'
			. '.outpost-appearance-bypass { display: block; margin-top: 4px; }'
			. '.outpost-appearance-mode__option { display: inline-block; margin-right: 16px; }'
			. '.outpost-appearance-preview-controls { margin: 12px 0; }'
			. '.outpost-appearance-preview-controls label { margin-right: 12px; }';

		if ( ! wp_style_is( 'outpost-appearance-inline', 'registered' ) ) {
			wp_register_style( 'outpost-appearance-inline', false, array(), OUTPOST_VERSION );
		}
		wp_enqueue_style( 'outpost-appearance-inline' );
		wp_add_inline_style( 'outpost-appearance-inline', $css );
	}

	/**
	 * The sanitized names of the checked boxes in a `name[key]` checkbox
	 * group, read in the same expression as the request value (F-62).
	 *
	 * @param array<mixed> $checked Key => rest_sanitize_boolean() result.
	 * @return list<string>
	 */
	private static function checked_keys( array $checked ): array {
		$keys = array();
		foreach ( $checked as $key => $on ) {
			if ( true === $on && is_string( $key ) ) {
				$keys[] = sanitize_key( $key );
			}
		}
		return $keys;
	}
}
