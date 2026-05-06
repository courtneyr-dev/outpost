<?php
/**
 * Outpost_Composite_Inbound (G4).
 *
 * Multi-source enrichment primitive. Consumers declare a source list:
 * primary sources are tried sequentially until one succeeds; fallbacks
 * run only when all primaries fail; enrichers run alongside the
 * successful primary and merge into the result. Failed enrichers are
 * logged at debug level and swallowed.
 *
 * SOURCE LIST SHAPE
 *
 *     [
 *         [
 *             'id'       => 'apple_music_api',  // free-form; participates in cache key
 *             'role'     => 'primary',          // 'primary' | 'fallback' | 'enrich'
 *             'callback' => callable,            // returns array|WP_Error
 *             'timeout'  => 5,                  // seconds; default 5
 *         ],
 *         // ...
 *     ]
 *
 * MERGE STRATEGIES
 *
 * Built-in strategies registered by `register_default_strategies()`:
 * - `first_non_null` — primary's value wins for any key it sets;
 *   fallbacks fill in only the keys the primary left null/missing.
 *   Default for primary→fallback compositions.
 * - `deep_merge` — primary first, enrichers folded in via array_merge
 *   recursively. Default for primary+enrich compositions.
 * - `user_callback` — strategy name reserved for callers that pass
 *   `merge_strategy => 'user_callback'` and `merger => callable`.
 *
 * WALL-CLOCK CAP
 *
 * 15 seconds total. If hit, returns whatever has succeeded so far in
 * a `_composite_meta` envelope showing per-source outcomes + elapsed
 * time. Filterable via `outpost_composite_wall_clock_cap`.
 *
 * CACHE
 *
 * 1-hour transient keyed `outpost_composite_` + md5(url +
 * sorted source-id signature). Pass `force_refresh => true` to bypass.
 *
 * @package Outpost
 * @since   0.1.69
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Composite_Inbound {

	private const CACHE_PREFIX = 'outpost_composite_';

	private const DEFAULT_CACHE_TTL = HOUR_IN_SECONDS;

	private const DEFAULT_WALL_CLOCK_CAP = 15;

	private const DEFAULT_SOURCE_TIMEOUT = 5;

	/**
	 * Merge strategy registry.
	 *
	 * @var array<string,callable>
	 */
	private static array $strategies = array();

	/**
	 * Register a merge strategy.
	 *
	 * @since 0.1.69
	 *
	 * @param string   $name   Strategy name.
	 * @param callable $merger Receives array of per-source results, returns merged array.
	 */
	public static function register_merge_strategy( string $name, callable $merger ): void {
		self::$strategies[ $name ] = $merger;
	}

	/**
	 * Reset registered strategies. Test seam.
	 *
	 * @since 0.1.69
	 */
	public static function reset_strategies_for_tests(): void {
		self::$strategies = array();
	}

	/**
	 * Fetch a URL through a multi-source composite chain.
	 *
	 * @since 0.1.69
	 *
	 * @param string                          $url     Subject URL.
	 * @param array<int,array<string,mixed>>  $sources Source descriptor list.
	 * @param array<string,mixed>             $args    Optional. `force_refresh`,
	 *                                                 `merge_strategy`, `merger`.
	 * @return array<string,mixed>|WP_Error
	 */
	public static function fetch( string $url, array $sources, array $args = array() ) {
		self::ensure_default_strategies_registered();

		$validated = self::validate_source_list( $sources );
		if ( is_wp_error( $validated ) ) {
			return $validated;
		}

		$signature = self::source_signature( $validated );
		$cache_key = self::CACHE_PREFIX . md5( $url . '|' . $signature );

		if ( empty( $args['force_refresh'] ) ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$started  = microtime( true );
		$wall_cap = self::filter_wall_clock_cap();
		$by_role  = self::group_sources_by_role( $validated );
		$meta     = array(
			'sources'    => array(),
			'primary'    => null,
			'elapsed_ms' => 0,
		);

		// Run primaries sequentially until success.
		$primary_result = null;
		foreach ( $by_role['primary'] as $source ) {
			if ( self::elapsed_seconds( $started ) >= $wall_cap ) {
				break;
			}
			$row                              = self::run_source( $source );
			$meta['sources'][ $source['id'] ] = $row;
			if ( ! is_wp_error( $row['result'] ) ) {
				$primary_result  = $row['result'];
				$meta['primary'] = $source['id'];
				break;
			}
		}

		// If primaries failed, try fallbacks.
		if ( null === $primary_result ) {
			foreach ( $by_role['fallback'] as $source ) {
				if ( self::elapsed_seconds( $started ) >= $wall_cap ) {
					break;
				}
				$row                              = self::run_source( $source );
				$meta['sources'][ $source['id'] ] = $row;
				if ( ! is_wp_error( $row['result'] ) ) {
					$primary_result  = $row['result'];
					$meta['primary'] = $source['id'];
					break;
				}
			}
		}

		if ( null === $primary_result ) {
			return new WP_Error(
				'outpost_composite_all_failed',
				__( 'All composite sources failed.', 'outpost' ),
				array(
					'url'  => $url,
					'meta' => $meta,
				)
			);
		}

		// Run enrichers; failures swallowed at debug level.
		$enriched_results = array();
		foreach ( $by_role['enrich'] as $source ) {
			if ( self::elapsed_seconds( $started ) >= $wall_cap ) {
				break;
			}
			$row                              = self::run_source( $source );
			$meta['sources'][ $source['id'] ] = $row;
			if ( ! is_wp_error( $row['result'] ) ) {
				$enriched_results[ $source['id'] ] = $row['result'];
			} elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				/* phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log */
				error_log(
					sprintf(
						'Outpost composite enricher %s failed: %s',
						$source['id'],
						$row['result']->get_error_message()
					)
				);
			}
		}

		$strategy_name = $args['merge_strategy'] ?? ( empty( $by_role['enrich'] ) ? 'first_non_null' : 'deep_merge' );
		$merged        = self::apply_merge_strategy( $strategy_name, $primary_result, $enriched_results, $args );

		$meta['elapsed_ms']        = (int) round( self::elapsed_seconds( $started ) * 1000 );
		$merged['_composite_meta'] = $meta;

		set_transient( $cache_key, $merged, self::filter_cache_ttl() );

		return $merged;
	}

	/**
	 * Validate the source list shape.
	 *
	 * @param array<int,array<string,mixed>> $sources Source descriptors.
	 * @return array<int,array<string,mixed>>|WP_Error
	 */
	private static function validate_source_list( array $sources ) {
		if ( empty( $sources ) ) {
			return new WP_Error(
				'outpost_composite_empty_sources',
				__( 'Source list is empty.', 'outpost' )
			);
		}
		$out = array();
		foreach ( $sources as $i => $source ) {
			if ( ! is_array( $source ) ) {
				return new WP_Error(
					'outpost_composite_invalid_source',
					/* translators: %d: source index */
					sprintf( __( 'Source at index %d is not an array.', 'outpost' ), $i )
				);
			}
			$id   = isset( $source['id'] ) ? (string) $source['id'] : '';
			$role = isset( $source['role'] ) ? (string) $source['role'] : '';
			if ( '' === $id || '' === $role ) {
				return new WP_Error(
					'outpost_composite_invalid_source',
					/* translators: %d: source index */
					sprintf( __( 'Source at index %d missing id or role.', 'outpost' ), $i )
				);
			}
			if ( ! in_array( $role, array( 'primary', 'fallback', 'enrich' ), true ) ) {
				return new WP_Error(
					'outpost_composite_invalid_role',
					/* translators: %s: role string */
					sprintf( __( 'Invalid role: %s', 'outpost' ), $role )
				);
			}
			if ( ! isset( $source['callback'] ) || ! is_callable( $source['callback'] ) ) {
				return new WP_Error(
					'outpost_composite_invalid_callback',
					/* translators: %s: source id */
					sprintf( __( 'Source %s callback is not callable.', 'outpost' ), $id )
				);
			}
			$source['timeout'] = isset( $source['timeout'] ) ? (int) $source['timeout'] : self::DEFAULT_SOURCE_TIMEOUT;
			$out[]             = $source;
		}
		return $out;
	}

	/**
	 * Build a stable signature for the source list. Used in cache key.
	 *
	 * @param array<int,array<string,mixed>> $sources Validated sources.
	 */
	private static function source_signature( array $sources ): string {
		$ids = array_map(
			static function ( array $s ): string {
				return (string) $s['id'];
			},
			$sources
		);
		sort( $ids );
		return wp_json_encode( $ids );
	}

	/**
	 * Group sources by role for execution.
	 *
	 * @param array<int,array<string,mixed>> $sources Validated sources.
	 * @return array<string,array<int,array<string,mixed>>>
	 */
	private static function group_sources_by_role( array $sources ): array {
		$grouped = array(
			'primary'  => array(),
			'fallback' => array(),
			'enrich'   => array(),
		);
		foreach ( $sources as $source ) {
			$grouped[ $source['role'] ][] = $source;
		}
		return $grouped;
	}

	/**
	 * Run one source; return execution row.
	 *
	 * @param array<string,mixed> $source Source descriptor.
	 * @return array{id:string,result:array<string,mixed>|WP_Error,elapsed_ms:int}
	 */
	private static function run_source( array $source ): array {
		$started = microtime( true );
		$result  = null;
		try {
			$result = call_user_func( $source['callback'] );
		} catch ( \Throwable $e ) {
			$result = new WP_Error(
				'outpost_composite_source_threw',
				$e->getMessage(),
				array( 'source' => $source['id'] )
			);
		}
		if ( ! is_array( $result ) && ! is_wp_error( $result ) ) {
			$result = new WP_Error(
				'outpost_composite_source_returned_invalid',
				/* translators: %s: source id */
				sprintf( __( 'Source %s returned non-array, non-WP_Error value.', 'outpost' ), $source['id'] )
			);
		}
		return array(
			'id'         => (string) $source['id'],
			'result'     => $result,
			'elapsed_ms' => (int) round( self::elapsed_seconds( $started ) * 1000 ),
		);
	}

	/**
	 * Apply the named merge strategy.
	 *
	 * @param string                            $name        Strategy name.
	 * @param array<string,mixed>                $primary     Primary result.
	 * @param array<string,array<string,mixed>>  $enrichers   Enricher results keyed by source id.
	 * @param array<string,mixed>                $args        Original fetch args (carries `merger` if user_callback).
	 * @return array<string,mixed>
	 */
	private static function apply_merge_strategy( string $name, array $primary, array $enrichers, array $args ): array {
		if ( 'user_callback' === $name && isset( $args['merger'] ) && is_callable( $args['merger'] ) ) {
			$results = array_merge( array( 'primary' => $primary ), $enrichers );
			$out     = call_user_func( $args['merger'], $results );
			return is_array( $out ) ? $out : $primary;
		}
		if ( isset( self::$strategies[ $name ] ) ) {
			$out = call_user_func( self::$strategies[ $name ], $primary, $enrichers );
			return is_array( $out ) ? $out : $primary;
		}
		return $primary;
	}

	/**
	 * Register the two built-in strategies.
	 */
	private static function ensure_default_strategies_registered(): void {
		if ( isset( self::$strategies['first_non_null'] ) ) {
			return;
		}
		self::register_merge_strategy(
			'first_non_null',
			static function ( array $primary, array $enrichers ): array {
				$out = $primary;
				foreach ( $enrichers as $r ) {
					foreach ( $r as $k => $v ) {
						if ( ! array_key_exists( $k, $out ) || null === $out[ $k ] || '' === $out[ $k ] ) {
							$out[ $k ] = $v;
						}
					}
				}
				return $out;
			}
		);
		self::register_merge_strategy(
			'deep_merge',
			static function ( array $primary, array $enrichers ): array {
				$out = $primary;
				foreach ( $enrichers as $r ) {
					$out = self::array_merge_deep( $out, $r );
				}
				return $out;
			}
		);
	}

	/**
	 * Recursive array merge that doesn't reindex numeric keys.
	 *
	 * @param array<mixed,mixed> $a Base.
	 * @param array<mixed,mixed> $b Overlay.
	 * @return array<mixed,mixed>
	 */
	private static function array_merge_deep( array $a, array $b ): array {
		foreach ( $b as $k => $v ) {
			if ( is_array( $v ) && isset( $a[ $k ] ) && is_array( $a[ $k ] ) ) {
				$a[ $k ] = self::array_merge_deep( $a[ $k ], $v );
			} else {
				$a[ $k ] = $v;
			}
		}
		return $a;
	}

	/**
	 * Wall-clock cap via filter.
	 */
	private static function filter_wall_clock_cap(): int {
		/** @var mixed $cap */
		$cap = apply_filters( 'outpost_composite_wall_clock_cap', self::DEFAULT_WALL_CLOCK_CAP );
		return max( 1, (int) $cap );
	}

	/**
	 * Cache TTL via filter.
	 */
	private static function filter_cache_ttl(): int {
		/** @var mixed $ttl */
		$ttl = apply_filters( 'outpost_composite_cache_ttl', self::DEFAULT_CACHE_TTL );
		return max( 60, (int) $ttl );
	}

	/**
	 * Elapsed seconds since start.
	 *
	 * @param float $started microtime(true) value.
	 */
	private static function elapsed_seconds( float $started ): float {
		return microtime( true ) - $started;
	}
}
