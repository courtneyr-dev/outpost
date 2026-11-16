<?php
/**
 * The syndication badge prints the same markup after escaping moved to the
 * echo (T11 F-50).
 *
 * The badge prints in two places: the posts-list column and the classic
 * editor's pending-syndication notice. Both now call print_badge(), which
 * escapes each value as it prints, instead of echoing a built string under
 * a phpcs:ignore. The expected strings below are the output of c3d5a08 for
 * the same inputs, so any change to the markup, the glyph, the visible text
 * or the aria-label fails here. (wp_kses() was tried first; it rewrote a
 * translated apostrophe's &#039; as &apos;.)
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use PHPUnit\Framework\TestCase;

final class SyndicationBadgeOutputTest extends TestCase {

	private int $user_id = 0;
	private int $post_id = 0;

	protected function setUp(): void {
		parent::setUp();
		if ( ! function_exists( 'wp_insert_post' ) || ! class_exists( 'Outpost_Syndication_Admin_Column' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 'badge_test_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'badge_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->user_id );
		$this->post_id = (int) wp_insert_post(
			array(
				'post_title'  => 'Badge output test post',
				'post_status' => 'publish',
				'post_type'   => 'post',
				'post_author' => $this->user_id,
			),
			true
		);
	}

	protected function tearDown(): void {
		remove_all_filters( 'gettext' );
		if ( $this->post_id > 0 ) {
			wp_delete_post( $this->post_id, true );
		}
		if ( $this->user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/**
	 * One audit-log entry. `completed` marks it done; `abandoned` snoozes it
	 * past the 10-year sentinel.
	 *
	 * @return array<string, mixed>
	 */
	private static function entry( string $id, bool $completed, bool $abandoned = false ): array {
		return array(
			'id'                       => $id,
			'version'                  => 1,
			'platform_id'              => 'instagram-feed',
			'fired_at'                 => '2026-05-04T12:00:00+00:00',
			'strategy'                 => 'navigator_share',
			'outcome'                  => 'fired',
			'completed_at'             => $completed ? '2026-05-04T13:00:00+00:00' : null,
			'silo_url'                 => $completed ? 'https://example.com/p/' . $id : null,
			'reminder_dismissed_until' => $abandoned ? '2099-01-01T00:00:00+00:00' : null,
		);
	}

	/**
	 * Audit-log entries per status, and the badge c3d5a08 printed for them.
	 *
	 * @return array<string, array{0: array<int, array<string, mixed>>, 1: string}>
	 */
	public static function statuses(): array {
		$badge = static fn( string $status, string $label, string $glyph, string $text ): string =>
			'<span class="outpost-syndication-badge outpost-syndication-badge--' . $status . '" data-status="' . $status . '" aria-label="' . $label . '" role="img">'
			. '<span class="outpost-syndication-badge__glyph" aria-hidden="true">' . $glyph . '</span> '
			. '<span class="outpost-syndication-badge__text">' . $text . '</span></span>';

		return array(
			'no syndication' => array( array(), $badge( 'no_syndication', 'No syndication', '—', 'none' ) ),
			'complete'       => array(
				array( self::entry( 'a', true ), self::entry( 'b', true ) ),
				$badge( 'complete', 'Syndication complete: 2 platforms', '✓', '2/2' ),
			),
			'partial'        => array(
				array( self::entry( 'a', true ), self::entry( 'b', false ) ),
				$badge( 'partial', 'Syndication partial: 1 of 2 completed', '⏳', '1/2' ),
			),
			'pending'        => array(
				array( self::entry( 'a', false ), self::entry( 'b', false ) ),
				$badge( 'pending', 'Syndication pending: 2 platforms', '⏳', '0/2' ),
			),
			'abandoned'      => array(
				array( self::entry( 'a', false, true ), self::entry( 'b', false, true ) ),
				$badge( 'abandoned', 'Syndication abandoned: 2 platforms', '⚠', 'abandoned' ),
			),
		);
	}

	private function column_output(): string {
		ob_start();
		\Outpost_Syndication_Admin_Column::render_column( 'outpost_syndication', $this->post_id );
		return (string) ob_get_clean();
	}

	private function notice_output( array $entries ): string {
		$render = new \ReflectionMethod( \Outpost_Pending_Syndication_Notice::class, 'render' );
		ob_start();
		$render->invoke( null, $this->post_id, $entries );
		return (string) ob_get_clean();
	}

	/**
	 * @dataProvider statuses
	 *
	 * @param array<int, array<string, mixed>> $entries Audit-log entries.
	 * @param string                           $expected Badge markup c3d5a08 printed.
	 */
	public function test_the_posts_list_column_prints_the_same_badge( array $entries, string $expected ): void {
		update_post_meta( $this->post_id, 'outpost_manual_share_log', $entries );

		$this->assertSame( $expected, $this->column_output() );
	}

	/**
	 * @dataProvider statuses
	 *
	 * @param array<int, array<string, mixed>> $entries Audit-log entries.
	 * @param string                           $expected Badge markup c3d5a08 printed.
	 */
	public function test_the_pending_notice_prints_the_same_badge( array $entries, string $expected ): void {
		update_post_meta( $this->post_id, 'outpost_manual_share_log', $entries );

		$html = $this->notice_output( $entries );

		$this->assertSame( 1, substr_count( $html, '<span class="outpost-syndication-badge ' ), 'The notice prints one badge.' );
		$this->assertStringContainsString( "<p>\n\t\t\t\t" . $expected, $html, 'The badge opens the notice, unchanged.' );
	}

	/**
	 * A translation carrying markup characters stays escaped text in the
	 * aria-label and the visible label, exactly as c3d5a08 printed it.
	 */
	public function test_a_translation_with_markup_characters_prints_as_escaped_text(): void {
		add_filter(
			'gettext',
			static function ( $translation, $text ) {
				if ( 'No syndication' === $text ) {
					return 'No <b>"sync"</b> & \'co\'';
				}
				if ( 'none' === $text ) {
					return '<img src=x onerror=alert(1)>';
				}
				return $translation;
			},
			10,
			2
		);

		$expected = '<span class="outpost-syndication-badge outpost-syndication-badge--no_syndication" data-status="no_syndication" aria-label="No &lt;b&gt;&quot;sync&quot;&lt;/b&gt; &amp; &#039;co&#039;" role="img">'
			. '<span class="outpost-syndication-badge__glyph" aria-hidden="true">—</span> '
			. '<span class="outpost-syndication-badge__text">&lt;img src=x onerror=alert(1)&gt;</span></span>';

		$this->assertSame( $expected, $this->column_output() );
		$this->assertStringContainsString( $expected, $this->notice_output( array() ) );
	}
}
