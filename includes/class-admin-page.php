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

	public static function register(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
	}

	public static function register_menu(): void {
		add_menu_page(
			__( 'Outpost', 'outpost' ),
			__( 'Outpost', 'outpost' ),
			'manage_options',
			self::MENU_SLUG,
			array( self::class, 'render_page' ),
			'dashicons-share-alt2',
			76
		);
	}

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'outpost' ) );
		}

		$composer_url = home_url( '/post/' );
		$share_target = home_url( '/post/share-target' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Outpost', 'outpost' ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: composer URL */
					esc_html__( 'Composer lives at %s. Sign in there with IndieAuth, then start posting.', 'outpost' ),
					'<code><a href="' . esc_url( $composer_url ) . '">' . esc_html( $composer_url ) . '</a></code>'
				);
				?>
			</p>

			<h2><?php echo esc_html__( 'Bookmarklets', 'outpost' ); ?></h2>
			<p>
				<?php
				echo esc_html__(
					'Drag any link below to your bookmarks bar. When you click the bookmark on any web page, the current page URL, title, and selected text get sent to Outpost with the chosen Reply variant pre-selected.',
					'outpost'
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Bookmarklet', 'outpost' ); ?></th>
						<th><?php echo esc_html__( 'What it does', 'outpost' ); ?></th>
						<th><?php echo esc_html__( 'Source code', 'outpost' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( self::REPLY_VARIANTS as $variant => $config ) : ?>
						<?php
						$label       = $config['label'];
						$bookmarklet = self::build_bookmarklet( $share_target, $variant );
						?>
						<tr>
							<td>
								<a
									href="<?php echo esc_attr( $bookmarklet ); ?>"
									class="button button-secondary"
								>
									<?php
									/* translators: %s: variant label, e.g. "Reply" */
									echo esc_html( sprintf( __( 'Outpost: %s', 'outpost' ), $label ) );
									?>
								</a>
							</td>
							<td><?php echo esc_html( $config['description'] ); ?></td>
							<td>
								<textarea
									readonly
									rows="3"
									class="large-text code"
									onclick="this.select();"
								><?php echo esc_textarea( $bookmarklet ); ?></textarea>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h3><?php echo esc_html__( 'How it works', 'outpost' ); ?></h3>
			<ol>
				<li><?php echo esc_html__( 'Drag the button to your bookmarks bar.', 'outpost' ); ?></li>
				<li><?php echo esc_html__( 'On any web page, click the saved bookmark.', 'outpost' ); ?></li>
				<li><?php echo esc_html__( 'A new tab opens with the Outpost composer pre-filled with the page URL, title, and any text you had selected.', 'outpost' ); ?></li>
				<li><?php echo esc_html__( 'Edit if you want, then post.', 'outpost' ); ?></li>
			</ol>

			<h3><?php echo esc_html__( 'iOS Shortcut alternative', 'outpost' ); ?></h3>
			<p>
				<?php
				echo esc_html__(
					'On iOS, drag-to-bookmarks-bar isn\'t how Mobile Safari works. Instead, install Outpost as a Home Screen app (the composer prompts for this after your first post), and use the system Share sheet from any app — Outpost will show up as a destination.',
					'outpost'
				);
				?>
			</p>
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
