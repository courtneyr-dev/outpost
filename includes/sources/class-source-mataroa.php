<?php
/**
 * Outpost_Source_Mataroa (G6).
 *
 * Mataroa is an open-source minimalist blogging platform; user blogs
 * run at `{slug}.mataroa.blog`. Site owners running a self-hosted
 * Mataroa extend recognition via `outpost_mataroa_domain_patterns`.
 *
 * Inbound capture mode: read.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Source_Mataroa extends Outpost_Source_Base {

	public const ID = 'mataroa';

	/**
	 * @return array<string,mixed>
	 */
	public function capabilities(): array {
		$caps     = array(
			'id'               => self::ID,
			'label'            => __( 'Mataroa', 'outpost' ),
			'host_patterns'    => array( '*.mataroa.blog' ),
			'ambiguity'        => 'unambiguous',
			'mode'             => 'read',
			'mode_options'     => null,
			'mode_default'     => null,
			'extractor'        => 'og_tags',
			'recipe'           => array(
				'fetch_url' => '@source_url',
			),
			'mapping'          => array(
				'og:title'       => 'p-name',
				'og:description' => 'p-summary',
				'og:image'       => 'u-photo',
				'@source_url'    => 'u-read-of',
			),
			'h_entry_property' => 'u-read-of',
			'auth_required'    => false,
			'tags_default'     => array( 'read' ),
			'caveats'          => array(),
		);
		$filtered = apply_filters( 'outpost_source_capabilities', $caps, self::ID );
		return is_array( $filtered ) ? $filtered : $caps;
	}

	/**
	 * Override to support self-hosted Mataroa instances on custom
	 * domains. See Bear Blog adapter for the same pattern.
	 *
	 * @param string $url URL the user shared.
	 */
	public function matches_url( string $url ): bool {
		if ( parent::matches_url( $url ) ) {
			return true;
		}
		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( (string) $parts['host'] );
		/**
		 * Filter the additional host patterns Mataroa claims.
		 *
		 * @param string[] $patterns Default empty; users append.
		 */
		$patterns = apply_filters( 'outpost_mataroa_domain_patterns', array() );
		if ( ! is_array( $patterns ) ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			$pattern = strtolower( (string) $pattern );
			if ( '' === $pattern ) {
				continue;
			}
			if ( $host === $pattern ) {
				return true;
			}
			if ( 0 === strpos( $pattern, '*.' ) ) {
				$suffix = substr( $pattern, 1 );
				if ( strlen( $host ) > strlen( $suffix )
					&& substr( $host, -strlen( $suffix ) ) === $suffix
				) {
					return true;
				}
			}
		}
		return false;
	}
}
