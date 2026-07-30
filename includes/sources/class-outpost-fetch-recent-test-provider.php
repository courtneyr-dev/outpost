<?php
/**
 * Outpost_Fetch_Recent_Test_Provider (G-fetch-recent-picker).
 *
 * Demo fetch-recent provider returning three hardcoded items. Lets the
 * picker work end-to-end without any real API integration so developers
 * can verify the modal flow on local + staging. Real providers (Oura,
 * WHOOP, Polar source classes) replace this with their own callbacks
 * in follow-up PRs.
 *
 * Registers itself via the `outpost_fetch_recent_providers` filter when
 * `register()` is called from outpost.php's plugins_loaded.
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Fetch_Recent_Test_Provider {

	public const PROVIDER_ID = 'test';

	/**
	 * Hook the provider into the registry.
	 *
	 * @since 0.1.79
	 */
	public static function register(): void {
		add_filter( 'outpost_fetch_recent_providers', array( __CLASS__, 'add_to_registry' ) );
	}

	/**
	 * Append the test provider to the registry.
	 *
	 * @since 0.1.79
	 *
	 * @param array<string,array<string,mixed>> $providers Existing providers.
	 * @return array<string,array<string,mixed>>
	 */
	public static function add_to_registry( $providers ): array {
		if ( ! is_array( $providers ) ) {
			$providers = array();
		}
		$providers[ self::PROVIDER_ID ] = array(
			'label'          => __( 'Test (sample data)', 'outpost-mobile-publishing' ),
			'callback'       => array( __CLASS__, 'fetch_items' ),
			'capability'     => 'edit_posts',
			'oauth_provider' => null,
		);
		return $providers;
	}

	/**
	 * Return three hardcoded sample items in the canonical fetch-recent
	 * shape. Caller respects the `$count` cap; we only emit three.
	 *
	 * @since 0.1.79
	 *
	 * @param int $count Caller-requested cap.
	 * @return array<int,array<string,mixed>>
	 */
	public static function fetch_items( int $count = 3 ): array {
		unset( $count ); // Test provider always emits 3.
		$now      = time();
		$one_hour = HOUR_IN_SECONDS;
		return array(
			array(
				'id'           => 'test-1',
				'title'        => __( 'Sample workout: Running 5.2 miles', 'outpost-mobile-publishing' ),
				'subtitle'     => __( '32 minutes, 478 kcal', 'outpost-mobile-publishing' ),
				'icon_url'     => null,
				'fetched_at'   => gmdate( 'c', $now - ( 2 * $one_hour ) ),
				'post_kind'    => 'workout',
				'post_payload' => array(
					'title'                  => __( 'Morning run', 'outpost-mobile-publishing' ),
					'content'                => '<p>' . esc_html__( 'Sample running workout — 5.2 miles in 32 minutes, 478 kcal.', 'outpost-mobile-publishing' ) . '</p>',
					'post_meta'              => array(
						'_outpost_test_distance' => '5.2 miles',
						'_outpost_test_duration' => '32 minutes',
					),
					'syndication_source_url' => null,
				),
			),
			array(
				'id'           => 'test-2',
				'title'        => __( 'Sample sleep session', 'outpost-mobile-publishing' ),
				'subtitle'     => __( '7 hours 14 minutes, score 82', 'outpost-mobile-publishing' ),
				'icon_url'     => null,
				'fetched_at'   => gmdate( 'c', $now - ( 14 * $one_hour ) ),
				'post_kind'    => 'sleep',
				'post_payload' => array(
					'title'                  => __( 'Last night sleep', 'outpost-mobile-publishing' ),
					'content'                => '<p>' . esc_html__( 'Sample sleep summary — 7h14m, score 82.', 'outpost-mobile-publishing' ) . '</p>',
					'post_meta'              => array(),
					'syndication_source_url' => null,
				),
			),
			array(
				'id'           => 'test-3',
				'title'        => __( 'Sample reading session', 'outpost-mobile-publishing' ),
				'subtitle'     => __( '20 minutes, 12 highlights', 'outpost-mobile-publishing' ),
				'icon_url'     => null,
				'fetched_at'   => gmdate( 'c', $now - ( 24 * $one_hour ) ),
				'post_kind'    => 'read',
				'post_payload' => array(
					'title'                  => __( 'Reading: sample book', 'outpost-mobile-publishing' ),
					'content'                => '<p>' . esc_html__( 'Sample reading session — 20 minutes, 12 highlights.', 'outpost-mobile-publishing' ) . '</p>',
					'post_meta'              => array(),
					'syndication_source_url' => null,
				),
			),
		);
	}
}
