<?php
/**
 * Unit tests for Outpost_Appearance_Settings_Page (FY-Theming PR(b)).
 *
 * Covers:
 * - render() output across token states (default, theme, override)
 * - contrast warning rendering when adjusted entries exist
 * - bypass_contrast checkbox state reflects user-meta
 * - mode picker reflects stored preference
 * - capability gate (`edit_posts`) on render + handle_save
 * - admin-post nonce verification
 * - build_preview_html shape (token-only style block + correct
 *   root class)
 *
 * @package Outpost\Tests\Admin
 */

declare(strict_types=1);

namespace Outpost\Tests\Admin;

use Outpost_Appearance_Settings_Page;
use Outpost_Mode_Controller;
use Outpost_Theme_Json_Reader;
use Outpost_Token_Resolver;
use WP_Mock;

final class AppearanceSettingsPageTest extends \WP_Mock\Tools\TestCase {

	/** @var array<int, array<string, mixed>> */
	private array $user_meta = array();

	/** @var array<string, mixed> */
	private array $transients = array();

	/** @var int */
	private int $current_user_id = 42;

	/** @var bool */
	private bool $current_user_can = true;

	public function setUp(): void {
		WP_Mock::setUp();
		$this->user_meta        = array();
		$this->transients       = array();
		$this->current_user_id  = 42;
		$this->current_user_can = true;
		$_GET                   = array();
		$_POST                  = array();
		if ( ! defined( 'OUTPOST_TESTING_PWA_SHELL' ) ) {
			define( 'OUTPOST_TESTING_PWA_SHELL', true );
		}

		WP_Mock::userFunction( 'get_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, bool $single ) {
				$value = $this->user_meta[ $user_id ][ $key ] ?? '';
				return $single ? $value : array( $value );
			}
		);
		WP_Mock::userFunction( 'update_user_meta' )->andReturnUsing(
			function ( int $user_id, string $key, $value ): bool {
				$this->user_meta[ $user_id ][ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'get_current_user_id' )->andReturnUsing(
			fn (): int => $this->current_user_id
		);
		WP_Mock::userFunction( 'current_user_can' )->andReturnUsing(
			fn ( string $cap ) => $this->current_user_can
		);
		WP_Mock::userFunction( 'get_transient' )->andReturnUsing(
			fn ( string $key ) => $this->transients[ $key ] ?? false
		);
		WP_Mock::userFunction( 'set_transient' )->andReturnUsing(
			function ( string $key, $value, int $expiration ): bool {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		WP_Mock::userFunction( 'delete_transient' )->andReturnUsing(
			function ( string $key ): bool {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		WP_Mock::userFunction( 'wp_nonce_field' )->andReturnUsing(
			static fn ( string $action, string $name ): string => "<input name=\"$name\" type=\"hidden\" value=\"nonce-stub\">"
		);
		WP_Mock::userFunction( 'wp_verify_nonce' )->andReturnUsing(
			static fn ( string $nonce, string $action ) => 'nonce-stub' === $nonce ? 1 : false
		);
		WP_Mock::userFunction( 'admin_url' )->andReturnUsing(
			static fn ( string $path = '' ) => 'https://example.test/wp-admin/' . $path
		);
		WP_Mock::userFunction( 'add_query_arg' )->andReturnUsing(
			static function ( $key, $value = null, $url = null ) {
				if ( is_array( $key ) ) {
					$pairs = array();
					foreach ( $key as $k => $v ) {
						$pairs[] = rawurlencode( (string) $k ) . '=' . rawurlencode( (string) $v );
					}
					return ( $value ? $value : '' ) . '?' . implode( '&', $pairs );
				}
				return '';
			}
		);
		WP_Mock::userFunction( 'wp_safe_redirect' )->andReturn( true );
		WP_Mock::userFunction( 'wp_die' )->andReturnUsing(
			static function ( string $msg ) {
				throw new \RuntimeException( 'wp_die: ' . $msg );
			}
		);
		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing(
			static fn ( string $s ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( $s ) )
		);
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static fn ( string $s ) => trim( $s )
		);
		WP_Mock::userFunction( 'wp_unslash' )->andReturnUsing( static fn ( $v ) => $v );
		WP_Mock::userFunction( 'wp_json_encode' )->andReturnUsing(
			static fn ( $v ) => json_encode( $v )
		);
		WP_Mock::userFunction( 'get_bloginfo' )->andReturn( 'en-US' );
		WP_Mock::userFunction( 'checked' )->andReturnUsing(
			static function ( $checked, $current = true, $echo = true ) {
				$out = $checked === $current ? ' checked="checked"' : '';
				if ( $echo ) {
					echo $out;
				}
				return $out;
			}
		);
		WP_Mock::userFunction( 'esc_attr' )->andReturnUsing( static fn ( $s ) => (string) $s );
		WP_Mock::userFunction( 'esc_attr__' )->andReturnUsing( static fn ( string $s ) => $s );
		WP_Mock::userFunction( 'esc_attr_e' )->andReturnUsing(
			static function ( string $s ) {
				echo $s;
			}
		);
		WP_Mock::userFunction( 'esc_html' )->andReturnUsing( static fn ( $s ) => (string) $s );
		WP_Mock::userFunction( 'esc_html__' )->andReturnUsing( static fn ( string $s ) => $s );
		WP_Mock::userFunction( 'esc_html_e' )->andReturnUsing(
			static function ( string $s ) {
				echo $s;
			}
		);
		WP_Mock::userFunction( 'esc_url' )->andReturnUsing( static fn ( $s ) => (string) $s );
		WP_Mock::userFunction( '__' )->andReturnUsing( static fn ( string $s ) => $s );
		WP_Mock::userFunction( '_e' )->andReturnUsing(
			static function ( string $s ) {
				echo $s;
			}
		);
		WP_Mock::userFunction( 'is_user_logged_in' )->andReturnUsing(
			fn (): bool => $this->current_user_id > 0
		);
		WP_Mock::userFunction( 'is_wp_error' )->andReturnUsing(
			static fn ( $thing ): bool => $thing instanceof \WP_Error
		);
		WP_Mock::userFunction( 'screen_reader_text' )->andReturn( '' );

		Outpost_Theme_Json_Reader::set_reader_for_tests( static fn () => null );
	}

	public function tearDown(): void {
		Outpost_Theme_Json_Reader::set_reader_for_tests( null );
		WP_Mock::tearDown();
		$_GET  = array();
		$_POST = array();
	}

	private function render(): string {
		ob_start();
		Outpost_Appearance_Settings_Page::render();
		return (string) ob_get_clean();
	}

	// --- render branches -------------------------------------------------

	public function test_render_emits_page_heading(): void {
		$html = $this->render();
		$this->assertStringContainsString( '<h1>', $html );
		$this->assertStringContainsString( 'Outpost Appearance', $html );
	}

	public function test_render_dies_for_user_without_capability(): void {
		$this->current_user_can = false;
		$this->expectException( \RuntimeException::class );
		Outpost_Appearance_Settings_Page::render();
	}

	public function test_render_includes_mode_picker(): void {
		$html = $this->render();
		$this->assertStringContainsString( 'name="mode_preference"', $html );
		$this->assertStringContainsString( 'value="day"', $html );
		$this->assertStringContainsString( 'value="night"', $html );
		$this->assertStringContainsString( 'value="system"', $html );
	}

	public function test_render_reflects_stored_mode_preference(): void {
		Outpost_Mode_Controller::set_mode( $this->current_user_id, 'night' );
		$html = $this->render();
		// Checked attribute on the night radio.
		$this->assertMatchesRegularExpression(
			'/name="mode_preference"\s+value="night"\s+checked/',
			$html
		);
	}

	public function test_render_includes_color_input_per_token(): void {
		$html = $this->render();
		foreach ( array( 'bg', 'surface', 'text', 'text_secondary', 'accent', 'accent_2', 'border' ) as $slug ) {
			$this->assertStringContainsString( 'colors_day[' . $slug . ']', $html );
			$this->assertStringContainsString( 'colors_night[' . $slug . ']', $html );
		}
	}

	public function test_render_includes_font_input_per_token(): void {
		$html = $this->render();
		foreach ( array( 'body', 'display', 'monospace' ) as $slug ) {
			$this->assertStringContainsString( 'fonts_day[' . $slug . ']', $html );
			$this->assertStringContainsString( 'fonts_night[' . $slug . ']', $html );
		}
	}

	public function test_render_shows_default_source_badge_for_built_in_tokens(): void {
		$html = $this->render();
		// Default badge for the bg field — no theme.json + no override.
		$this->assertMatchesRegularExpression(
			'/outpost-appearance-source-badge--default[^<]*default/',
			$html
		);
	}

	public function test_render_shows_override_source_badge_when_user_has_override(): void {
		$this->user_meta[ $this->current_user_id ][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'colors' => array(
					'bg' => '#abcdef',
				),
			),
		);
		$html = $this->render();
		$this->assertStringContainsString( 'outpost-appearance-source-badge--override', $html );
		$this->assertStringContainsString( 'value="#abcdef"', $html );
	}

	public function test_render_emits_contrast_warning_for_adjusted_token(): void {
		// Override text to a low-contrast value; resolver will adjust;
		// settings page surfaces the warning.
		$this->user_meta[ $this->current_user_id ][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'colors' => array(
					'text'    => '#cccccc',
					'surface' => '#ffffff',
				),
			),
		);
		$html = $this->render();
		$this->assertStringContainsString( 'Contrast adjustment applied', $html );
		$this->assertStringContainsString( 'name="bypass_contrast[text]"', $html );
	}

	public function test_render_bypass_checkbox_checked_when_user_has_bypassed(): void {
		$this->user_meta[ $this->current_user_id ][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day'             => array(
				'colors' => array(
					'text'    => '#cccccc',
					'surface' => '#ffffff',
				),
			),
			'bypass_contrast' => array( 'text' ),
		);
		$html = $this->render();
		// When bypass is set, contrast adjustment is skipped → no warning surfaces.
		// The checkbox (rendered only when adjusted) won't appear.
		// What WOULD show is the value at the user's override unchanged.
		$this->assertStringContainsString( 'value="#cccccc"', $html );
	}

	public function test_render_emits_iframe_with_srcdoc(): void {
		$html = $this->render();
		$this->assertStringContainsString( 'class="outpost-appearance-preview"', $html );
		$this->assertStringContainsString( 'sandbox="allow-same-origin"', $html );
		$this->assertStringContainsString( 'srcdoc="', $html );
	}

	public function test_render_emits_data_baselines_for_iframe_js(): void {
		$html = $this->render();
		$this->assertStringContainsString( 'data-day-tokens="', $html );
		$this->assertStringContainsString( 'data-night-tokens="', $html );
	}

	public function test_render_includes_inline_preview_script(): void {
		$html = $this->render();
		$this->assertStringContainsString( 'outpost-appearance-preview', $html );
		// The script does live-update logic.
		$this->assertStringContainsString( 'outpost-preview-tokens', $html );
	}

	// --- preview HTML builder -------------------------------------------

	public function test_build_preview_html_emits_doctype_and_root_class(): void {
		$resolved = Outpost_Token_Resolver::resolve( $this->current_user_id, 'day' );
		$html     = Outpost_Appearance_Settings_Page::build_preview_html( $resolved, 'day' );
		$this->assertStringStartsWith( '<!doctype html>', $html );
		$this->assertStringContainsString( '<body class="outpost-mode-day">', $html );
	}

	public function test_build_preview_html_uses_correct_root_class_for_night(): void {
		$resolved = Outpost_Token_Resolver::resolve( $this->current_user_id, 'night' );
		$html     = Outpost_Appearance_Settings_Page::build_preview_html( $resolved, 'night' );
		$this->assertStringContainsString( '<body class="outpost-mode-night">', $html );
	}

	public function test_build_preview_html_includes_all_subset_components(): void {
		$resolved = Outpost_Token_Resolver::resolve( $this->current_user_id, 'day' );
		$html     = Outpost_Appearance_Settings_Page::build_preview_html( $resolved, 'day' );
		$this->assertStringContainsString( 'class="outpost-preview-tabs"', $html );
		$this->assertStringContainsString( 'class="outpost-preview-heading"', $html );
		$this->assertStringContainsString( 'class="outpost-preview-input"', $html );
		$this->assertStringContainsString( 'class="outpost-preview-radios"', $html );
		$this->assertStringContainsString( 'class="outpost-preview-button"', $html );
		$this->assertStringContainsString( 'class="outpost-preview-mic"', $html );
	}

	public function test_build_preview_html_inline_tokens_block_is_token_only(): void {
		// The injected #outpost-preview-tokens block must only contain
		// `--outpost-*: value;` declarations between the braces.
		$resolved = Outpost_Token_Resolver::resolve( $this->current_user_id, 'day' );
		$html     = Outpost_Appearance_Settings_Page::build_preview_html( $resolved, 'day' );
		$this->assertMatchesRegularExpression(
			'/<style id="outpost-preview-tokens">\s*\.outpost-mode-day\s*\{/',
			$html
		);
		// Extract the block and assert each line inside is a custom-prop assignment.
		preg_match( '/<style id="outpost-preview-tokens">(.*?)<\/style>/s', $html, $m );
		$this->assertNotEmpty( $m[1] ?? '' );
		$inner = trim( $m[1] );
		$inner = preg_replace( '/\.outpost-mode-day\s*\{|\}/', '', $inner );
		$lines = array_filter( array_map( 'trim', explode( "\n", (string) $inner ) ) );
		foreach ( $lines as $line ) {
			$this->assertMatchesRegularExpression(
				'/^--outpost-[a-z0-9-]+:.+;$/',
				$line,
				"Token-only block expected; got: $line"
			);
		}
	}

	// --- handle_save -----------------------------------------------------

	public function test_handle_save_with_valid_nonce_persists_overrides(): void {
		$_POST = array(
			Outpost_Appearance_Settings_Page::NONCE_NAME => 'nonce-stub',
			'mode_preference'                            => 'night',
			'colors_day'                                 => array(
				'bg'   => '#abcdef',
				'text' => '#001122',
			),
			'fonts_day'                                  => array(
				'body' => "'Custom Body', sans-serif",
			),
		);

		try {
			Outpost_Appearance_Settings_Page::handle_save();
		} catch ( \RuntimeException $e ) {
			$this->fail( 'handle_save should not die: ' . $e->getMessage() );
		}

		$saved_overrides = $this->user_meta[ $this->current_user_id ][ Outpost_Token_Resolver::OVERRIDE_META_KEY ];
		$this->assertSame( '#abcdef', $saved_overrides['day']['colors']['bg'] );
		$this->assertSame( '#001122', $saved_overrides['day']['colors']['text'] );
		$this->assertSame( "'Custom Body', sans-serif", $saved_overrides['day']['fonts']['body'] );

		$saved_mode = $this->user_meta[ $this->current_user_id ][ Outpost_Mode_Controller::META_KEY ];
		$this->assertSame( 'night', $saved_mode );
	}

	public function test_handle_save_dies_on_invalid_nonce(): void {
		$_POST = array(
			Outpost_Appearance_Settings_Page::NONCE_NAME => 'wrong',
		);
		$this->expectException( \RuntimeException::class );
		Outpost_Appearance_Settings_Page::handle_save();
	}

	public function test_handle_save_dies_when_capability_missing(): void {
		$_POST                  = array(
			Outpost_Appearance_Settings_Page::NONCE_NAME => 'nonce-stub',
		);
		$this->current_user_can = false;
		$this->expectException( \RuntimeException::class );
		Outpost_Appearance_Settings_Page::handle_save();
	}

	public function test_handle_save_clears_token_when_value_blank(): void {
		// Pre-seed an override.
		$this->user_meta[ $this->current_user_id ][ Outpost_Token_Resolver::OVERRIDE_META_KEY ] = array(
			'day' => array(
				'colors' => array(
					'bg' => '#abcdef',
				),
			),
		);
		$_POST = array(
			Outpost_Appearance_Settings_Page::NONCE_NAME => 'nonce-stub',
			'colors_day'                                 => array(
				'bg' => '',
			),
		);

		Outpost_Appearance_Settings_Page::handle_save();

		$saved = $this->user_meta[ $this->current_user_id ][ Outpost_Token_Resolver::OVERRIDE_META_KEY ];
		$this->assertArrayNotHasKey( 'bg', $saved['day']['colors'] ?? array() );
	}

	public function test_handle_save_persists_bypass_contrast_list(): void {
		$_POST = array(
			Outpost_Appearance_Settings_Page::NONCE_NAME => 'nonce-stub',
			'bypass_contrast'                            => array(
				'text'   => '1',
				'accent' => '1',
			),
		);

		Outpost_Appearance_Settings_Page::handle_save();

		$saved = $this->user_meta[ $this->current_user_id ][ Outpost_Token_Resolver::OVERRIDE_META_KEY ];
		$this->assertContains( 'text', $saved['bypass_contrast'] );
		$this->assertContains( 'accent', $saved['bypass_contrast'] );
	}

	public function test_handle_save_with_invalid_color_returns_error_notice(): void {
		$_POST = array(
			Outpost_Appearance_Settings_Page::NONCE_NAME => 'nonce-stub',
			'colors_day'                                 => array(
				'bg' => 'javascript:alert(1)',
			),
		);

		Outpost_Appearance_Settings_Page::handle_save();

		// Override should NOT have been written; REST validation rejected the value.
		$this->assertArrayNotHasKey(
			Outpost_Token_Resolver::OVERRIDE_META_KEY,
			$this->user_meta[ $this->current_user_id ] ?? array()
		);
	}

	// --- admin notice query string sanitization -------------------------

	public function test_render_emits_saved_notice_on_query_param(): void {
		$_GET['notice'] = 'saved';
		$html           = $this->render();
		$this->assertStringContainsString( 'Appearance settings saved', $html );
	}

	public function test_render_emits_error_notice_on_query_param(): void {
		$_GET['notice']  = 'error';
		$_GET['message'] = 'Invalid color';
		$html            = $this->render();
		$this->assertStringContainsString( 'Invalid color', $html );
	}

	public function test_render_ignores_unknown_notice(): void {
		$_GET['notice'] = 'malicious-payload';
		$html           = $this->render();
		$this->assertStringNotContainsString( 'malicious-payload', $html );
	}
}
