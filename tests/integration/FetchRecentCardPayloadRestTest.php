<?php
/**
 * F-43: the fetch-recent REST route keeps the Post Kinds card payload.
 *
 * `normalize_item()` used to rebuild `post_payload` from title, content,
 * post_meta and syndication_source_url only, so the `blocks`, `terms` and
 * `date` the Oura and Polar mappers emit never reached the editor: a Polar
 * run arrived as the fallback paragraph instead of the private-by-default
 * exercise card. These tests drive the real route
 * (`rest_do_request` -> permission_callback -> handle_request ->
 * resolve_items -> normalize_item) as a cookie editor with a valid REST
 * nonce, the sidebar's apiFetch shape, with provider HTTP stubbed through
 * the providers' resolver seams.
 *
 * The same route must drop what it can't vouch for: block names outside
 * `post-kinds-indieweb/`, unregistered blocks, undeclared or non-scalar
 * attributes, more than five blocks, terms of unknown taxonomies or
 * nonexistent ids, and any date that isn't noon.
 *
 * Post Kinds isn't installed in the test site, so the cards and the `kind`
 * taxonomy are registered the way Post Kinds 1.9.1 registers them.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use Outpost_Fetch_Recent_Oura;
use Outpost_Fetch_Recent_Polar;
use PHPUnit\Framework\TestCase;
use WP_REST_Request;

/**
 * @coversNothing
 */
final class FetchRecentCardPayloadRestTest extends TestCase {

	private const SLEEP_CARD    = 'post-kinds-indieweb/sleep-card';
	private const EXERCISE_CARD = 'post-kinds-indieweb/exercise-card';
	private const TEST_PROVIDER = 'f43probe';

	/** Oura sleep: 7 h of sleep, the night of 2026-11-11 (UTC site). */
	private const SLEEP = array(
		'id'                   => 'f43-sleep',
		'day'                  => '2026-11-12',
		'bedtime_start'        => '2026-11-11T23:17:00+00:00',
		'bedtime_end'          => '2026-11-12T06:58:00+00:00',
		'total_sleep_duration' => 25200,
		'score'                => 81,
	);

	/** Polar run: 5.23 km in 29 min 12.6 s. */
	private const RUN = array(
		'id'         => 'f43ex',
		'sport'      => 'RUNNING',
		'start-time' => '2026-11-12T06:41:09',
		'distance'   => 5234.0,
		'duration'   => 'PT29M12.6S',
		'calories'   => 412,
	);

	private int $editor_id = 0;

	/** @var array<string,mixed> */
	private array $saved_server = array();

	/** @var mixed */
	private $saved_cookie = null;

	/** @var list<array<string,mixed>> Items the probe provider returns. */
	private array $probe_items = array();

	/** @var callable|null */
	private $probe_filter = null;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_Oura' ) || ! function_exists( 'rest_do_request' ) ) {
			$this->markTestSkipped( 'Requires wp-env with Outpost loaded.' );
		}
		$this->saved_server = $_SERVER;
		$this->saved_cookie = $GLOBALS['wp_rest_auth_cookie'] ?? null;
		$this->editor_id    = (int) wp_insert_user(
			array(
				'user_login' => 'f43_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 'f43_' . uniqid() . '@example.test',
				'role'       => 'editor',
			)
		);
		wp_set_current_user( $this->editor_id );
		Outpost_Credentials_Store::set( 'oura', array( 'access_token' => 'synthetic-oura-token' ), $this->editor_id );
		Outpost_Credentials_Store::set( 'polar', array( 'access_token' => 'synthetic-polar-token' ), $this->editor_id );

		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests(
			static function ( string $url ) {
				if ( false !== strpos( $url, '/usercollection/sleep' ) ) {
					return array( 'data' => array( self::SLEEP ) );
				}
				return array( 'data' => array() );
			}
		);
		$editor = $this->editor_id;
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests(
			static function ( string $method, string $url ) use ( $editor ) {
				$base = 'https://www.polaraccesslink.com/v3/users/' . $editor . '/exercise-transactions';
				if ( 'POST' === $method && $url === $base ) {
					return array( 'transaction-id' => 'txn1' );
				}
				if ( 'GET' === $method && $url === $base . '/txn1' ) {
					return array( 'exercises' => array( $base . '/txn1/exercises/f43ex' ) );
				}
				if ( 'GET' === $method && $url === $base . '/txn1/exercises/f43ex' ) {
					return self::RUN;
				}
				return null;
			}
		);

		$this->probe_filter = function ( $providers ) {
			$providers[ self::TEST_PROVIDER ] = array(
				'label'    => 'F-43 probe',
				'callback' => function (): array {
					return $this->probe_items;
				},
			);
			return $providers;
		};
		add_filter( 'outpost_fetch_recent_providers', $this->probe_filter );
	}

	protected function tearDown(): void {
		remove_filter( 'outpost_fetch_recent_providers', $this->probe_filter );
		Outpost_Fetch_Recent_Oura::set_http_resolver_for_tests( null );
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( null );
		$this->unregister_post_kinds();
		$_SERVER                        = $this->saved_server;
		$GLOBALS['wp_rest_auth_cookie'] = $this->saved_cookie;
		wp_set_current_user( 0 );
		require_once ABSPATH . 'wp-admin/includes/user.php';
		wp_delete_user( $this->editor_id );
		parent::tearDown();
	}

	/**
	 * Register the two cards and the `kind` taxonomy with both terms.
	 *
	 * @return array{exercise:int,sleep:int} Term ids.
	 */
	private function register_post_kinds(): array {
		register_block_type(
			self::EXERCISE_CARD,
			array(
				'attributes' => array(
					'activity'            => array( 'type' => 'string' ),
					'distance'            => array( 'type' => 'number' ),
					'distanceUnit'        => array(
						'type'    => 'string',
						'default' => 'km',
					),
					'duration'            => array( 'type' => 'string' ),
					'splits'              => array( 'type' => 'array' ),
					'showDetailsPublicly' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_block_type(
			self::SLEEP_CARD,
			array(
				'attributes' => array(
					'durationMinutes'   => array( 'type' => 'integer' ),
					'bedtime'           => array( 'type' => 'string' ),
					'wakeTime'          => array( 'type' => 'string' ),
					'showTimesPublicly' => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_taxonomy(
			'kind',
			'post',
			array(
				'show_in_rest' => true,
				'rest_base'    => 'kind',
			)
		);
		$exercise = wp_insert_term( 'Exercise', 'kind', array( 'slug' => 'exercise' ) );
		$sleep    = wp_insert_term( 'Sleep', 'kind', array( 'slug' => 'sleep' ) );
		$this->assertIsArray( $exercise );
		$this->assertIsArray( $sleep );
		return array(
			'exercise' => (int) $exercise['term_id'],
			'sleep'    => (int) $sleep['term_id'],
		);
	}

	private function unregister_post_kinds(): void {
		foreach ( array( self::EXERCISE_CARD, self::SLEEP_CARD ) as $block ) {
			if ( \WP_Block_Type_Registry::get_instance()->is_registered( $block ) ) {
				unregister_block_type( $block );
			}
		}
		if ( taxonomy_exists( 'kind' ) ) {
			foreach ( array( 'exercise', 'sleep' ) as $slug ) {
				$term = get_term_by( 'slug', $slug, 'kind' );
				if ( $term instanceof \WP_Term ) {
					wp_delete_term( $term->term_id, 'kind' );
				}
			}
			unregister_taxonomy( 'kind' );
		}
	}

	/**
	 * GET the route as the sidebar does: cookie session plus REST nonce.
	 *
	 * @return array<string,mixed> The first item's post_payload.
	 */
	private function first_payload( string $provider ): array {
		$GLOBALS['wp_rest_auth_cookie'] = true;
		unset( $_SERVER['HTTP_AUTHORIZATION'] );
		$_SERVER['HTTP_X_WP_NONCE'] = wp_create_nonce( 'wp_rest' );
		$response                   = rest_do_request( new WP_REST_Request( 'GET', '/outpost/v1/fetch-recent/' . $provider ) );
		$data                       = rest_get_server()->response_to_data( $response, false );
		$this->assertSame( 200, $response->get_status(), (string) wp_json_encode( $data ) );
		$this->assertIsArray( $data['items'][0] ?? null, 'The route returned an item.' );
		return $data['items'][0]['post_payload'];
	}

	/**
	 * A probe item whose payload carries the given extra fields.
	 *
	 * @param array<string,mixed> $extra Fields merged into post_payload.
	 */
	private function probe( array $extra ): void {
		$this->probe_items = array(
			array(
				'id'           => 'probe-1',
				'title'        => 'Probe',
				'fetched_at'   => '2026-11-12',
				'post_payload' => array(
					'title'   => 'Probe',
					'content' => '<p>Probe</p>',
				) + $extra,
			),
		);
	}

	// --- Real providers, cards registered ---------------------------------

	/**
	 * @test
	 */
	public function polar_run_reaches_the_editor_as_the_exercise_card(): void {
		$terms   = $this->register_post_kinds();
		$payload = $this->first_payload( 'polar' );

		$this->assertSame(
			array(
				array(
					'name'       => self::EXERCISE_CARD,
					'attributes' => array(
						'activity'     => 'Running',
						'distance'     => 5.23,
						'distanceUnit' => 'km',
						'duration'     => 'PT29M13S',
					),
				),
			),
			$payload['blocks'] ?? null
		);
		$this->assertSame( array( 'kind' => array( $terms['exercise'] ) ), $payload['terms'] ?? null );
		$this->assertArrayNotHasKey( 'showDetailsPublicly', $payload['blocks'][0]['attributes'], 'Details stay private by default.' );
	}

	/**
	 * @test
	 */
	public function oura_sleep_reaches_the_editor_as_the_sleep_card_at_noon(): void {
		$terms   = $this->register_post_kinds();
		$payload = $this->first_payload( 'oura' );

		$this->assertSame(
			array(
				array(
					'name'       => self::SLEEP_CARD,
					'attributes' => array( 'durationMinutes' => 420 ),
				),
			),
			$payload['blocks'] ?? null
		);
		$this->assertSame( array( 'kind' => array( $terms['sleep'] ) ), $payload['terms'] ?? null );
		$this->assertSame( '2026-11-11T12:00:00', $payload['date'] ?? null );
		$this->assertArrayNotHasKey( 'showTimesPublicly', $payload['blocks'][0]['attributes'], 'Times stay private by default.' );
	}

	/**
	 * @test
	 */
	public function without_the_cards_the_route_sends_the_paragraph_alone(): void {
		foreach ( array( 'polar', 'oura' ) as $provider ) {
			$payload = $this->first_payload( $provider );
			$this->assertStringStartsWith( '<p>', $payload['content'], $provider );
			$this->assertArrayNotHasKey( 'blocks', $payload, $provider );
			$this->assertArrayNotHasKey( 'terms', $payload, $provider );
			$this->assertArrayNotHasKey( 'date', $payload, $provider );
		}
	}

	// --- Validation at the route ------------------------------------------

	/**
	 * @test
	 */
	public function a_registered_card_with_declared_scalar_attributes_passes(): void {
		$terms = $this->register_post_kinds();
		$this->probe(
			array(
				'blocks' => array(
					array(
						'name'       => self::SLEEP_CARD,
						'attributes' => array( 'durationMinutes' => 462 ),
					),
				),
				'terms'  => array( 'kind' => array( $terms['sleep'] ) ),
				'date'   => '2026-11-12T12:00:00',
			)
		);
		$payload = $this->first_payload( self::TEST_PROVIDER );

		$this->assertSame(
			array(
				array(
					'name'       => self::SLEEP_CARD,
					'attributes' => array( 'durationMinutes' => 462 ),
				),
			),
			$payload['blocks'] ?? null,
			'Positive control: a valid card survives the route.'
		);
		$this->assertSame( array( 'kind' => array( $terms['sleep'] ) ), $payload['terms'] ?? null );
		$this->assertSame( '2026-11-12T12:00:00', $payload['date'] ?? null );
	}

	/**
	 * @return array<string,array{0:array<int,mixed>}>
	 */
	public function disallowed_blocks(): array {
		$card = array(
			'name'       => self::SLEEP_CARD,
			'attributes' => array( 'durationMinutes' => 462 ),
		);
		return array(
			'core block'               => array(
				array(
					array(
						'name'       => 'core/html',
						'attributes' => array( 'content' => '<b>x</b>' ),
					),
				),
			),
			'other namespace'          => array(
				array(
					array(
						'name'       => 'acme/sleep-card',
						'attributes' => array(),
					),
				),
			),
			'prefix look-alike'        => array(
				array(
					array(
						'name'       => 'post-kinds-indieweb-evil/sleep-card',
						'attributes' => array(),
					),
				),
			),
			'uppercase name'           => array(
				array(
					array(
						'name'       => 'post-kinds-indieweb/Sleep-Card',
						'attributes' => array(),
					),
				),
			),
			'unregistered Post Kinds'  => array(
				array(
					array(
						'name'       => 'post-kinds-indieweb/not-a-card',
						'attributes' => array(),
					),
				),
			),
			'name not a string'        => array( array( array( 'name' => array( self::SLEEP_CARD ) ) ) ),
			'entry not an array'       => array( array( self::SLEEP_CARD ) ),
			'one bad entry among good' => array( array( $card, array( 'name' => 'core/html' ) ) ),
			'more than five entries'   => array( array( $card, $card, $card, $card, $card, $card ) ),
			'keyed map, not a list'    => array( array( 'a' => $card ) ),
		);
	}

	/**
	 * @test
	 * @dataProvider disallowed_blocks
	 *
	 * @param array<int|string,mixed> $blocks Blocks the provider sends.
	 */
	public function disallowed_blocks_are_dropped( array $blocks ): void {
		$this->register_post_kinds();
		$this->probe( array( 'blocks' => $blocks ) );
		$payload = $this->first_payload( self::TEST_PROVIDER );

		$this->assertArrayNotHasKey( 'blocks', $payload );
		$this->assertSame( '<p>Probe</p>', $payload['content'], 'The paragraph fallback stays.' );
	}

	/**
	 * @test
	 */
	public function five_cards_is_the_limit_that_still_passes(): void {
		$this->register_post_kinds();
		$card = array(
			'name'       => self::SLEEP_CARD,
			'attributes' => array( 'durationMinutes' => 462 ),
		);
		$this->probe( array( 'blocks' => array( $card, $card, $card, $card, $card ) ) );

		$this->assertCount( 5, $this->first_payload( self::TEST_PROVIDER )['blocks'] ?? array() );
	}

	/**
	 * @test
	 */
	public function undeclared_and_non_scalar_attributes_are_dropped(): void {
		$this->register_post_kinds();
		$this->probe(
			array(
				'blocks' => array(
					array(
						'name'       => self::EXERCISE_CARD,
						'attributes' => array(
							'activity'  => 'Running',
							'distance'  => 5.23,
							'onclick'   => 'alert(1)',
							'className' => 'x',
							'splits'    => array( array( 'km' => 1 ) ),
							7           => 'numeric key',
						),
					),
				),
			)
		);
		$payload = $this->first_payload( self::TEST_PROVIDER );

		$this->assertSame(
			array(
				'activity' => 'Running',
				'distance' => 5.23,
			),
			$payload['blocks'][0]['attributes'] ?? null
		);
	}

	/**
	 * @test
	 */
	public function a_card_without_attributes_gets_an_empty_attribute_list(): void {
		$this->register_post_kinds();
		$this->probe( array( 'blocks' => array( array( 'name' => self::SLEEP_CARD ) ) ) );

		$this->assertSame(
			array(
				array(
					'name'       => self::SLEEP_CARD,
					'attributes' => array(),
				),
			),
			$this->first_payload( self::TEST_PROVIDER )['blocks'] ?? null
		);
	}

	/**
	 * @return array<string,array{0:mixed}>
	 */
	public function disallowed_dates(): array {
		return array(
			'clock time'       => array( '2026-11-11T23:17:00' ),
			'midnight'         => array( '2026-11-12T00:00:00' ),
			'noon with offset' => array( '2026-11-12T12:00:00+00:00' ),
			'noon with Z'      => array( '2026-11-12T12:00:00Z' ),
			'date only'        => array( '2026-11-12' ),
			'impossible date'  => array( '2026-02-30T12:00:00' ),
			'trailing newline' => array( "2026-11-12T12:00:00\n" ),
			'not a string'     => array( 1762948800 ),
		);
	}

	/**
	 * @test
	 * @dataProvider disallowed_dates
	 *
	 * @param mixed $date Date the provider sends.
	 */
	public function non_noon_dates_are_dropped( $date ): void {
		$this->probe( array( 'date' => $date ) );

		$this->assertArrayNotHasKey( 'date', $this->first_payload( self::TEST_PROVIDER ) );
	}

	/**
	 * @test
	 */
	public function terms_keep_only_existing_ids_of_registered_rest_bases(): void {
		$terms    = $this->register_post_kinds();
		$category = (int) get_option( 'default_category' );
		$this->probe(
			array(
				'terms' => array(
					'kind'       => array( $terms['sleep'], 999999, -3, 0, '5', 2.0 ),
					'categories' => array( $category ),
					'nope'       => array( $terms['sleep'] ),
					'tags'       => array( 999998 ),
					'kinds'      => 'not-a-list',
				),
			)
		);
		$payload = $this->first_payload( self::TEST_PROVIDER );

		$this->assertSame(
			array(
				'kind'       => array( $terms['sleep'] ),
				'categories' => array( $category ),
			),
			$payload['terms'] ?? null
		);
	}

	/**
	 * @test
	 */
	public function an_id_from_another_taxonomy_is_dropped(): void {
		$terms = $this->register_post_kinds();
		$this->probe( array( 'terms' => array( 'categories' => array( $terms['sleep'] ) ) ) );

		$this->assertArrayNotHasKey( 'terms', $this->first_payload( self::TEST_PROVIDER ) );
	}
}
