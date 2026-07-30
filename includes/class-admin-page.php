<?php
/**
 * Admin page — bookmarklet generator + composer entry point.
 *
 * Phase E1. Adds a top-level "Outpost" menu in wp-admin that hosts
 * the bookmarklet generator. The generator outputs ready-to-drag
 * bookmarklets that, when clicked from any web page, hand the
 * current URL + title + text-selection to Outpost's share-target
 * endpoint with a variant-tag in the query string. The composer
 * opens with the right Reply variant pre-selected.
 *
 * Bookmarklets are URL-shaped JavaScript (`javascript:...`) snippets
 * the user drags to their browser bookmarks bar. The user clicks the
 * bookmarklet on any page; the page's URL/title/selection get
 * encoded as query params on a window.open() call to
 * `/post/share-target`. Web Share Target (E0) handles the rest.
 *
 * Capability: `manage_options` per WP convention for top-level
 * admin pages. Output is properly escaped per the Security Trinity.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Admin_Page {

	/** Menu slug. Used by add_menu_page + the page's URL. */
	private const MENU_SLUG = 'outpost';

	/**
	 * Bookmarklet-supported Reply variants. Each maps to the variant
	 * the share-target route forwards to ReplyMode.
	 *
	 * @var array<string, array{label: string, description: string}>
	 */
	private const REPLY_VARIANTS = array(
		'reply'    => array(
			'label'       => 'Reply',
			'description' => 'Post a reply that links back to the source page (in-reply-to).',
		),
		'like'     => array(
			'label'       => 'Like',
			'description' => 'Post a like with the source page as the like-of target.',
		),
		'repost'   => array(
			'label'       => 'Repost',
			'description' => 'Post a repost with the source page as the repost-of target.',
		),
		'bookmark' => array(
			'label'       => 'Bookmark',
			'description' => 'Save the source page as a bookmark with optional commentary.',
		),
	);

	/**
	 * Hook suffix returned by add_menu_page, used to scope asset loading to
	 * this screen. Null until admin_menu has run.
	 */
	private static ?string $hook_suffix = null;

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue_assets' ) );
	}

	public static function register_menu(): void {
		$suffix = add_menu_page(
			__( 'Outpost', 'outpost-mobile-publishing' ),
			__( 'Outpost', 'outpost-mobile-publishing' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-share-alt2',
			76
		);

		self::$hook_suffix = ( is_string( $suffix ) && '' !== $suffix ) ? $suffix : null;
	}

	/**
	 * Register this screen's CSS and JS through the enqueue API.
	 *
	 * Both are inline-only: the CSS is a few dozen layout rules and the JS is
	 * one copy-to-clipboard delegate, so neither earns a separate HTTP
	 * request. Registering with a `false` src and attaching via
	 * wp_add_inline_style/wp_add_inline_script keeps them inside the
	 * dependency system — other plugins can dequeue or filter them, and they
	 * print in the documented order — instead of being echoed mid-markup.
	 *
	 * @param string $hook_suffix Current admin screen.
	 */
	public static function enqueue_assets( string $hook_suffix ): void {
		if ( null === self::$hook_suffix || $hook_suffix !== self::$hook_suffix ) {
			return;
		}

		wp_register_style( 'outpost-admin-page', false, array(), OUTPOST_VERSION );
		wp_enqueue_style( 'outpost-admin-page' );
		wp_add_inline_style( 'outpost-admin-page', self::inline_css() );

		wp_register_script( 'outpost-admin-page', false, array(), OUTPOST_VERSION, true );
		wp_enqueue_script( 'outpost-admin-page' );
		wp_add_inline_script( 'outpost-admin-page', self::inline_js() );
	}

	/** Layout rules for the bookmarklet grid and step list. */
	private static function inline_css(): string {
		return '
			.outpost-admin__steps,
			.outpost-admin__platform-list {
				max-width: 60em;
				line-height: 1.6;
			}
			.outpost-admin__bookmarklets {
				display: grid;
				grid-template-columns: repeat(auto-fill, minmax(min(100%, 22em), 1fr));
				gap: 1rem;
				margin: 1rem 0 1.5rem;
			}
			.outpost-admin__bookmarklet {
				padding: 1rem;
			}
			.outpost-admin__bookmarklet-title {
				margin: 0 0 0.5rem;
				font-size: 1.05rem;
			}
			.outpost-admin__bookmarklet-desc {
				margin: 0 0 0.75rem;
			}
			.outpost-admin__bookmarklet-actions {
				display: flex;
				flex-wrap: wrap;
				gap: 0.5rem;
				align-items: center;
				margin: 0;
			}
			.outpost-admin__drag-handle {
				min-height: 44px;
				display: inline-flex;
				align-items: center;
			}
			.outpost-admin__bookmarklet-details {
				flex-basis: 100%;
			}
			.outpost-admin__bookmarklet-details summary {
				cursor: pointer;
				padding: 0.5rem 0;
			}
			@media (max-width: 600px) {
				.outpost-admin__bookmarklet-actions .button {
					width: 100%;
				}
			}
		';
	}

	/** Copy-to-clipboard delegate for the bookmarklet and share-target fields. */
	private static function inline_js(): string {
		return '
			( function () {
				document.addEventListener( "click", function ( event ) {
					var trigger = event.target && event.target.closest && event.target.closest( "[data-outpost-copy-source]" );
					if ( ! trigger ) return;
					var sourceId = trigger.getAttribute( "data-outpost-copy-source" );
					var source = document.getElementById( sourceId );
					if ( ! source ) return;
					event.preventDefault();
					var text = source.value;
					var done = function () {
						var original = trigger.textContent;
						trigger.textContent = "' . esc_js( __( 'Copied!', 'outpost-mobile-publishing' ) ) . '";
						setTimeout( function () {
							trigger.textContent = original;
						}, 1500 );
					};
					if ( navigator.clipboard && navigator.clipboard.writeText ) {
						navigator.clipboard.writeText( text ).then( done, function () {
							source.select();
							document.execCommand( "copy" );
							done();
						} );
					} else {
						source.select();
						document.execCommand( "copy" );
						done();
					}
				} );
			} )();
		';
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost-mobile-publishing' ) );
		}

		$composer_url = home_url( '/post/' );
		$share_target = home_url( '/post/share-target' );
		?>
		<div class="wrap outpost-admin">
			<h1><?php echo esc_html__( 'Outpost', 'outpost-mobile-publishing' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: composer URL */
					esc_html__( 'Composer lives at %s. Sign in there with IndieAuth, then start posting.', 'outpost-mobile-publishing' ),
					'<code><a href="' . esc_url( $composer_url ) . '">' . esc_html( $composer_url ) . '</a></code>'
				);
				?>
			</p>

			<h2><?php echo esc_html__( 'On your phone (recommended)', 'outpost-mobile-publishing' ); ?></h2>
			<p>
				<?php
				echo esc_html__(
					'On mobile the share sheet beats bookmarklets. Install Outpost once, then any app with a Share button can send pages straight to the composer.',
					'outpost-mobile-publishing'
				);
				?>
			</p>
			<ol class="outpost-admin__steps">
				<li>
					<?php
					printf(
						/* translators: %s: composer URL link */
						esc_html__( 'Open %s in Safari (iPhone) or Chrome (Android).', 'outpost-mobile-publishing' ),
						'<a href="' . esc_url( $composer_url ) . '">' . esc_html( $composer_url ) . '</a>'
					);
					?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'iPhone:', 'outpost-mobile-publishing' ); ?></strong>
					<?php
					echo esc_html__(
						'tap the Share button, scroll, tap "Add to Home Screen." Outpost appears as an app icon.',
						'outpost-mobile-publishing'
					);
					?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Android:', 'outpost-mobile-publishing' ); ?></strong>
					<?php
					echo esc_html__(
						'tap the menu (⋮), then "Install app" or "Add to Home screen."',
						'outpost-mobile-publishing'
					);
					?>
				</li>
				<li>
					<?php
					echo esc_html__(
						'On any web page, tap Share → Outpost. The composer opens with the page URL and title pre-filled.',
						'outpost-mobile-publishing'
					);
					?>
				</li>
			</ol>

			<h2><?php echo esc_html__( 'Bookmarklets', 'outpost-mobile-publishing' ); ?></h2>
			<p>
				<?php
				echo esc_html__(
					'Pick a Reply variant. On desktop, drag the button to your bookmarks bar. On mobile, long-press the button and choose "Add Bookmark." Tap the saved bookmark from any page to compose a reply against that page.',
					'outpost-mobile-publishing'
				);
				?>
			</p>

			<div class="outpost-admin__bookmarklets" role="list">
				<?php foreach ( self::REPLY_VARIANTS as $variant => $config ) : ?>
					<?php
					$label       = $config['label'];
					$bookmarklet = self::build_bookmarklet( $share_target, $variant );
					$source_id   = 'outpost-bookmarklet-source-' . $variant;
					?>
					<section
						class="outpost-admin__bookmarklet card"
						role="listitem"
						aria-labelledby="outpost-bookmarklet-heading-<?php echo esc_attr( $variant ); ?>"
					>
						<h3 id="outpost-bookmarklet-heading-<?php echo esc_attr( $variant ); ?>" class="outpost-admin__bookmarklet-title">
							<?php
							/* translators: %s: variant label, e.g. "Reply" */
							echo esc_html( sprintf( __( 'Outpost: %s', 'outpost-mobile-publishing' ), $label ) );
							?>
						</h3>
						<p class="outpost-admin__bookmarklet-desc">
							<?php echo esc_html( $config['description'] ); ?>
						</p>
						<p class="outpost-admin__bookmarklet-actions">
							<a
								href="<?php echo esc_attr( $bookmarklet ); ?>"
								class="button button-primary outpost-admin__drag-handle"
								draggable="true"
							>
								<?php
								/* translators: %s: variant label, e.g. "Reply" */
								echo esc_html( sprintf( __( 'Drag or long-press: %s', 'outpost-mobile-publishing' ), $label ) );
								?>
							</a>
							<button
								type="button"
								class="button"
								data-outpost-copy-source="<?php echo esc_attr( $source_id ); ?>"
							>
								<?php echo esc_html__( 'Copy source', 'outpost-mobile-publishing' ); ?>
							</button>
							<details class="outpost-admin__bookmarklet-details">
								<summary><?php echo esc_html__( 'Show source', 'outpost-mobile-publishing' ); ?></summary>
								<textarea
									readonly
									rows="3"
									class="large-text code"
									id="<?php echo esc_attr( $source_id ); ?>"
									onclick="this.select();"
								><?php echo esc_textarea( $bookmarklet ); ?></textarea>
							</details>
						</p>
					</section>
				<?php endforeach; ?>
			</div>

			<h3><?php echo esc_html__( 'How it works', 'outpost-mobile-publishing' ); ?></h3>
			<ul class="outpost-admin__platform-list">
				<li>
					<strong><?php echo esc_html__( 'Desktop:', 'outpost-mobile-publishing' ); ?></strong>
					<?php echo esc_html__( 'drag the colored button to your bookmarks bar. Click it from any page.', 'outpost-mobile-publishing' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'iPhone Safari:', 'outpost-mobile-publishing' ); ?></strong>
					<?php echo esc_html__( 'long-press the button, choose "Add Bookmark." Later, tap the bookmarks icon, find the saved bookmark, tap to run on the current page.', 'outpost-mobile-publishing' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html__( 'Android Chrome:', 'outpost-mobile-publishing' ); ?></strong>
					<?php echo esc_html__( 'long-press the button, choose "Copy link," then save it as a bookmark from the menu.', 'outpost-mobile-publishing' ); ?>
				</li>
				<li>
					<?php echo esc_html__( 'Either way, the page URL, title, and any text you had selected get sent to Outpost with the right variant pre-selected.', 'outpost-mobile-publishing' ); ?>
				</li>
			</ul>


			<hr style="margin: 2rem 0;" />

			<h2><?php echo esc_html__( 'Settings', 'outpost-mobile-publishing' ); ?></h2>
			<?php Outpost_Settings::render_form(); ?>
		</div>
		<?php
	}

	/**
	 * Build a `javascript:...` bookmarklet that opens the share-target
	 * route with the current page's metadata.
	 *
	 * The body extracts location.href, document.title, and the active
	 * selection, encodes them, and opens the share URL. We URL-encode
	 * the whole thing for safe embedding in href attributes.
	 *
	 * @param string $share_target Absolute URL of /post/share-target.
	 * @param string $variant      One of REPLY_VARIANTS keys.
	 * @return string javascript: bookmarklet URL.
	 */
	private static function build_bookmarklet( string $share_target, string $variant ): string {
		// Build the variant-aware share URL with `{u}`, `{t}`, `{s}`
		// placeholders that the bookmarklet body fills in at click time.
		$separator          = ( false === strpos( $share_target, '?' ) ) ? '?' : '&';
		$share_url_template = $share_target . $separator . 'variant=' . rawurlencode( $variant );

		// JS body — single line, no semicolons inside the var defs that
		// would terminate the javascript: URL early on some browsers.
		$js = 'javascript:(function(){'
			. 'var u=encodeURIComponent(location.href),'
			. 't=encodeURIComponent(document.title),'
			. "s=encodeURIComponent(window.getSelection?String(window.getSelection()):'');"
			. "var w=window.open('"
			. esc_js( $share_url_template )
			. "&url='+u+'&title='+t+'&text='+s,'_blank');"
			. 'if(w)w.focus();'
			. '})();';

		return $js;
	}
}
