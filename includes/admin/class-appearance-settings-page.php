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
			__( 'Appearance', 'outpost' ),
			__( 'Appearance', 'outpost' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( self::class, 'render' )
		);
	}

	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost' ) );
		}
		$user_id   = (int) get_current_user_id();
		$mode_pref = Outpost_Mode_Controller::get_mode( $user_id );
		$day       = Outpost_Token_Resolver::resolve( $user_id, 'day' );
		$night     = Outpost_Token_Resolver::resolve( $user_id, 'night' );

		?>
		<div class="wrap outpost-appearance-wrap">
			<h1><?php esc_html_e( 'Outpost Appearance', 'outpost' ); ?></h1>

			<?php self::render_admin_notice(); ?>

			<p><?php esc_html_e( 'Customize how the Outpost composer paints itself. Color and font defaults inherit from your active theme; you can override any individual token below. Day/night mode is a per-user preference — two contributors on the same site can have different settings.', 'outpost' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="outpost-appearance-form">
				<input type="hidden" name="action" value="<?php echo esc_attr( self::SAVE_ACTION ); ?>">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>

				<h2><?php esc_html_e( 'Mode', 'outpost' ); ?></h2>
				<fieldset class="outpost-appearance-mode">
					<legend class="screen-reader-text"><?php esc_html_e( 'Day / night mode preference', 'outpost' ); ?></legend>
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
				self::render_mode_fields( 'day', __( 'Day mode', 'outpost' ), $day );
				self::render_mode_fields( 'night', __( 'Night mode', 'outpost' ), $night );
				?>

				<h2><?php esc_html_e( 'Live preview', 'outpost' ); ?></h2>
				<p class="description"><?php esc_html_e( 'Reflects unsaved values. Switch the preview between day and night to verify both modes before saving.', 'outpost' ); ?></p>

				<div class="outpost-appearance-preview-controls">
					<label>
						<input type="radio" name="preview_mode" value="day" checked>
						<?php esc_html_e( 'Day preview', 'outpost' ); ?>
					</label>
					<label>
						<input type="radio" name="preview_mode" value="night">
						<?php esc_html_e( 'Night preview', 'outpost' ); ?>
					</label>
				</div>

				<iframe
					class="outpost-appearance-preview"
					title="<?php esc_attr_e( 'Outpost composer preview', 'outpost' ); ?>"
					sandbox="allow-same-origin"
					srcdoc="<?php echo esc_attr( self::build_preview_html( $day, 'day' ) ); ?>"
					data-day-tokens="<?php echo esc_attr( wp_json_encode( $day ) ); ?>"
					data-night-tokens="<?php echo esc_attr( wp_json_encode( $night ) ); ?>"
					style="width:100%;height:520px;border:1px solid var(--cr-border, #ccc);background:#fff;"
				></iframe>

				<p class="submit">
					<button type="submit" class="button button-primary">
						<?php esc_html_e( 'Save appearance settings', 'outpost' ); ?>
					</button>
				</p>
			</form>

			<?php self::render_inline_script(); ?>
			<?php self::render_inline_styles(); ?>
		</div>
		<?php
	}

	/**
	 * admin-post.php handler — validates nonce + cap, forwards into
	 * the REST controller's POST handler so there's a single write
	 * path. Redirects back with notice on success/failure.
	 */
	public static function handle_save(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Verified by guard_post_request() at the top of this handler.
		self::guard_post_request();

		$user_id   = (int) get_current_user_id();
		$mode_pref = isset( $_POST['mode_preference'] ) ? sanitize_key( wp_unslash( (string) $_POST['mode_preference'] ) ) : '';
		// Bypass-contrast checkboxes — names like `bypass_contrast[text]` etc.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Keys only; every entry is passed through sanitize_key() two lines down.
		$bypass_in = isset( $_POST['bypass_contrast'] ) && is_array( $_POST['bypass_contrast'] )
			? array_keys( wp_unslash( (array) $_POST['bypass_contrast'] ) )
			: array();
		$bypass_in = array_filter( $bypass_in, 'is_string' );
		$bypass_in = array_values( array_map( static fn ( string $s ): string => sanitize_key( $s ), $bypass_in ) );

		$payload = array(
			'mode_preference' => $mode_pref,
			'bypass_contrast' => $bypass_in,
		);
		foreach ( array( 'day', 'night' ) as $mode ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; sanitized per-token downstream before save.
			$colors_in = isset( $_POST[ 'colors_' . $mode ] ) && is_array( $_POST[ 'colors_' . $mode ] )
				? wp_unslash( (array) $_POST[ 'colors_' . $mode ] )
				: array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Unslashed here; sanitized per-token downstream before save.
			$fonts_in         = isset( $_POST[ 'fonts_' . $mode ] ) && is_array( $_POST[ 'fonts_' . $mode ] )
				? wp_unslash( (array) $_POST[ 'fonts_' . $mode ] )
				: array();
			$payload[ $mode ] = array(
				'colors' => self::clean_string_map( $colors_in ),
				'fonts'  => self::clean_string_map( $fonts_in ),
			);
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		// Forward into REST handler. We stand up a synthetic
		// WP_REST_Request so validation + persistence + cache
		// invalidation logic stays in one place.
		$request = new \WP_REST_Request( 'POST', '/outpost/v1' . Outpost_Appearance_REST_Controller::ROUTE );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( $payload ) );

		$response = Outpost_Appearance_REST_Controller::handle_post( $request );

		if ( $response instanceof \WP_Error ) {
			self::redirect_with_notice( 'error', $response->get_error_message() );
			return;
		}

		self::redirect_with_notice( 'saved', '' );
	}

	private static function guard_post_request(): void {
		$nonce = isset( $_POST[ self::NONCE_NAME ] )
			? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) )
			: '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			wp_die( esc_html__( 'Security check failed.', 'outpost' ) );
		}
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage appearance preferences.', 'outpost' ) );
		}
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
					<th colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'Colors', 'outpost' ); ?></h3></th>
				</tr>
				<?php foreach ( self::color_field_labels() as $slug => $field_label ) : ?>
					<?php self::render_color_row( $mode_key, $slug, $field_label, $resolved ); ?>
				<?php endforeach; ?>
				<tr>
					<th colspan="2"><h3 style="margin:0;"><?php esc_html_e( 'Fonts', 'outpost' ); ?></h3></th>
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
						<strong><?php esc_html_e( 'Contrast adjustment applied.', 'outpost' ); ?></strong>
						<?php
						printf(
							/* translators: 1: original color hex, 2: adjusted hex, 3: ratio before, 4: ratio after. */
							esc_html__( 'Your %1$s rendered at %3$s:1 against the surface; auto-adjusted to %2$s for %4$s:1.', 'outpost' ),
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
							<?php esc_html_e( 'Override anyway (keep my color, accept the lower contrast)', 'outpost' ); ?>
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
					<?php esc_html_e( 'Paste a CSS font-family value. Outpost picks up custom fonts your theme already loads — installing fonts is your theme\'s job.', 'outpost' ); ?>
				</p>
			</td>
		</tr>
		<?php
	}

	private static function render_admin_notice(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display flag.
		$notice = isset( $_GET['notice'] ) ? sanitize_key( wp_unslash( (string) $_GET['notice'] ) ) : '';
		switch ( $notice ) {
			case 'saved':
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html__( 'Appearance settings saved.', 'outpost' )
				);
				break;
			case 'error':
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only display.
				$message = isset( $_GET['message'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['message'] ) ) : '';
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					esc_html( '' === $message ? __( 'Save failed.', 'outpost' ) : $message )
				);
				break;
		}
	}

	private static function redirect_with_notice( string $notice, string $message ): void {
		$args = array(
			'page'   => self::PAGE_SLUG,
			'notice' => $notice,
		);
		if ( '' !== $message && 'error' === $notice ) {
			$args['message'] = $message;
		}
		$url = add_query_arg( $args, admin_url( 'admin.php' ) );
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
		$tokens_css = Outpost_Token_Resolver::to_css( $resolved );
		$root_class = 'outpost-mode-' . ( 'night' === $mode ? 'night' : 'day' );
		$styles     = self::preview_static_styles();
		$body       = self::preview_body_html();
		$lang       = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'language' ) : 'en-US';
		$preview    = '<!doctype html>'
			. '<html lang="' . esc_attr( $lang ) . '">'
			. '<head>'
			. '<meta charset="utf-8">'
			. '<title>Outpost preview</title>'
			. '<style id="outpost-preview-tokens">' . $tokens_css . '</style>'
			. '<style id="outpost-preview-static">' . $styles . '</style>'
			. '</head>'
			. '<body class="' . esc_attr( $root_class ) . '">'
			. $body
			. '</body>'
			. '</html>';
		return $preview;
	}

	private static function preview_static_styles(): string {
		// Mini structural styles for the preview only. All paint goes
		// through --outpost-* references; no hex values here.
		return '
			body { margin: 0; padding: 16px; font-family: var(--outpost-font-body); background: var(--outpost-bg); color: var(--outpost-text); }
			.outpost-preview-tabs { display: flex; gap: 8px; border-bottom: 1px solid var(--outpost-border); padding-bottom: 8px; margin-bottom: 16px; }
			.outpost-preview-tabs button { background: transparent; border: 0; padding: 8px 12px; font-family: inherit; font-size: var(--outpost-size-body); color: var(--outpost-text-secondary); cursor: pointer; }
			.outpost-preview-tabs button[aria-selected="true"] { color: var(--outpost-text); border-bottom: 2px solid var(--outpost-accent); }
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
			<div class="outpost-preview-tabs">
				<button type="button">Post</button>
				<button type="button" aria-selected="true">Doing</button>
				<button type="button">Photo</button>
			</div>
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
			Outpost_Mode_Controller::MODE_SYSTEM => __( 'Match system', 'outpost' ),
			Outpost_Mode_Controller::MODE_DAY    => __( 'Always day', 'outpost' ),
			Outpost_Mode_Controller::MODE_NIGHT  => __( 'Always night', 'outpost' ),
		);
	}

	/** @return array<string,string> */
	private static function color_field_labels(): array {
		return array(
			'bg'             => __( 'Background', 'outpost' ),
			'surface'        => __( 'Surface', 'outpost' ),
			'text'           => __( 'Text', 'outpost' ),
			'text_secondary' => __( 'Text (secondary)', 'outpost' ),
			'accent'         => __( 'Accent (primary)', 'outpost' ),
			'accent_2'       => __( 'Accent (second)', 'outpost' ),
			'border'         => __( 'Border', 'outpost' ),
		);
	}

	/** @return array<string,string> */
	private static function font_field_labels(): array {
		return array(
			'body'      => __( 'Body font', 'outpost' ),
			'display'   => __( 'Display font (headings)', 'outpost' ),
			'monospace' => __( 'Monospace (URL fields)', 'outpost' ),
		);
	}

	private static function source_label( string $source ): string {
		switch ( $source ) {
			case 'override':
				return __( 'overridden', 'outpost' );
			case 'theme':
				return __( 'from theme', 'outpost' );
			case 'default':
			default:
				return __( 'default', 'outpost' );
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
		// Minimal vanilla JS: watch form inputs, rebuild the iframe's
		// preview HTML whenever a value changes. No bundler, no
		// dependencies. Inline and printed via wp_print_inline_script_tag
		// when available (WP 5.7+); falls back to a plain <script> block.
		$script = <<<'JS'
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
					var styleEl = doc.getElementById('outpost-preview-tokens');
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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $script is a hardcoded heredoc with no user input.
		echo '<script>' . $script . '</script>';
	}

	private static function render_inline_styles(): void {
		// Settings page chrome only — nothing about the composer paint.
		echo '<style>'
			. '.outpost-appearance-source-badge { font-size: 0.85em; padding: 2px 6px; margin-left: 8px; border-radius: 3px; background: #f0f0f1; color: #50575e; vertical-align: middle; }'
			. '.outpost-appearance-source-badge--override { background: #d4f0e0; color: #1a5f3f; }'
			. '.outpost-appearance-warning { background: #fff8e5; border-left: 4px solid #d4901a; padding: 8px 12px; margin: 8px 0; }'
			. '.outpost-appearance-bypass { display: block; margin-top: 4px; }'
			. '.outpost-appearance-mode__option { display: inline-block; margin-right: 16px; }'
			. '.outpost-appearance-preview-controls { margin: 12px 0; }'
			. '.outpost-appearance-preview-controls label { margin-right: 12px; }'
			. '</style>';
	}
}
