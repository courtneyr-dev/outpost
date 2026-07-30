<?php
/**
 * Outpost_Syndication_Admin_Column
 *
 * Adds a "Syndication" column to the WP admin posts list with a
 * status badge per post. Sortable: complete > partial > pending >
 * abandoned > no_syndication, then by oldest fired_at within partial
 * + pending so neglected items rise.
 *
 * Default rendering uses Unicode glyphs (✓ ⏳ ⚠ —) so the badge
 * conveys state without color (Hard Contract). Glyphs are paired
 * with `aria-label` text for screen readers; theme/site CSS can
 * apply color styling via the column's class.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Syndication_Admin_Column {

	private const COLUMN_KEY = 'outpost_syndication';

	/** Glyphs (color-independent state conveyance). */
	private const GLYPHS = array(
		'no_syndication' => '—',
		'complete'       => '✓',
		'partial'        => '⏳',
		'pending'        => '⏳',
		'abandoned'      => '⚠',
	);

	public static function register(): void {
		add_filter( 'manage_posts_columns', array( self::class, 'add_column' ) );
		add_action( 'manage_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
	}

	/**
	 * @param array<string,string> $columns
	 * @return array<string,string>
	 */
	public static function add_column( array $columns ): array {
		$columns[ self::COLUMN_KEY ] = __( 'Syndication', 'outpost-mobile-publishing' );
		return $columns;
	}

	/**
	 * @param string $column  Column key the WP admin is rendering.
	 * @param int    $post_id Current row's post id.
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( self::COLUMN_KEY !== $column ) {
			return;
		}
		echo self::render_badge_html( $post_id ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

	/**
	 * Render a status badge for a post. Public so the post-edit notice
	 * + tests can call it directly.
	 *
	 * @param int $post_id Post id.
	 */
	public static function render_badge_html( int $post_id ): string {
		$entries = Outpost_Manual_Share_Audit_Log::get_entries( $post_id );
		$status  = Outpost_Manual_Share_Status_Computer::compute_status_for_entries( $entries );
		$summary = Outpost_Manual_Share_Status_Computer::summarize_entries( $entries );

		$glyph = self::GLYPHS[ $status ] ?? '—';
		$label = self::aria_label( $status, $summary );
		$text  = self::display_text( $status, $summary );

		return sprintf(
			'<span class="outpost-syndication-badge outpost-syndication-badge--%1$s" data-status="%1$s" aria-label="%2$s" role="img"><span class="outpost-syndication-badge__glyph" aria-hidden="true">%3$s</span> <span class="outpost-syndication-badge__text">%4$s</span></span>',
			esc_attr( $status ),
			esc_attr( $label ),
			esc_html( $glyph ),
			esc_html( $text )
		);
	}

	/**
	 * Build the aria-label for a badge — full text-equivalent of the
	 * state. Never relies on glyph or color alone.
	 *
	 * @param array{total:int,complete:int,pending:int,abandoned:int} $summary
	 */
	private static function aria_label( string $status, array $summary ): string {
		switch ( $status ) {
			case 'complete':
				return sprintf(
					/* translators: %d: total syndicated platforms. */
					__( 'Syndication complete: %d platforms', 'outpost-mobile-publishing' ),
					$summary['total']
				);
			case 'partial':
				return sprintf(
					/* translators: %1$d: completed; %2$d: total. */
					__( 'Syndication partial: %1$d of %2$d completed', 'outpost-mobile-publishing' ),
					$summary['complete'],
					$summary['total']
				);
			case 'pending':
				return sprintf(
					/* translators: %d: pending platforms. */
					__( 'Syndication pending: %d platforms', 'outpost-mobile-publishing' ),
					$summary['total']
				);
			case 'abandoned':
				return sprintf(
					/* translators: %d: abandoned platforms. */
					__( 'Syndication abandoned: %d platforms', 'outpost-mobile-publishing' ),
					$summary['total']
				);
			case 'no_syndication':
			default:
				return __( 'No syndication', 'outpost-mobile-publishing' );
		}
	}

	/**
	 * Visible text shown next to the glyph. Compact "X/Y" labels for
	 * the partial case; full state name otherwise.
	 *
	 * @param array{total:int,complete:int,pending:int,abandoned:int} $summary
	 */
	private static function display_text( string $status, array $summary ): string {
		if ( 'partial' === $status ) {
			return sprintf( '%d/%d', $summary['complete'], $summary['total'] );
		}
		if ( 'pending' === $status ) {
			return sprintf( '0/%d', $summary['total'] );
		}
		if ( 'complete' === $status ) {
			return sprintf( '%1$d/%1$d', $summary['total'] );
		}
		if ( 'abandoned' === $status ) {
			return __( 'abandoned', 'outpost-mobile-publishing' );
		}
		return __( 'none', 'outpost-mobile-publishing' );
	}
}
