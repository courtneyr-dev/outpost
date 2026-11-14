<?php
/**
 * The stored Polar token goes only to Polar's own exercise URLs (T10-S4-01, F-22).
 *
 * Polar's transaction response lists exercise URLs, and the picker GETs
 * each one with the user's stored bearer token. The permitted destination
 * is exactly:
 *
 *   https://www.polaraccesslink.com/v3/users/{user}/exercise-transactions/{txn}/exercises/{id}
 *
 * for the transaction this fetch opened, with no user info, port, query,
 * or fragment, and no redirect following. Anything else is never requested.
 *
 * The tests run fetch_items() through the real wp_remote_request() path
 * and record each request at `pre_http_request`, the transport's own
 * short-circuit point, so they see the URL and arguments core would send.
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

use Outpost_Credentials_Store;
use Outpost_Fetch_Recent_Polar;
use PHPUnit\Framework\TestCase;

final class PolarExerciseHostBindingTest extends TestCase {

	private const TOKEN = 'SYNTHETIC-POLAR-TOKEN';
	private const TXN   = '7781';

	private int $user_id = 0;

	/** @var array<int, array{url: string, args: array<string, mixed>}> */
	private array $sent = array();

	/** @var array<int, string> Exercise URLs the transaction response lists. */
	private array $listed = array();

	/** @var callable */
	private $recorder;

	protected function setUp(): void {
		parent::setUp();
		if ( ! class_exists( 'Outpost_Fetch_Recent_Polar' ) || ! function_exists( 'wp_insert_user' ) ) {
			$this->markTestSkipped( 'Skipped under unit bootstrap.' );
		}
		Outpost_Fetch_Recent_Polar::set_http_resolver_for_tests( null );
		\Outpost_Encryption_Key_Resolver::resolve();
		$this->user_id = (int) wp_insert_user(
			array(
				'user_login' => 's401_' . uniqid(),
				'user_pass'  => wp_generate_password( 24, true ),
				'user_email' => 's401_' . uniqid() . '@example.test',
				'role'       => 'author',
			)
		);
		wp_set_current_user( $this->user_id );
		Outpost_Credentials_Store::set( 'polar', array( 'access_token' => self::TOKEN ), $this->user_id );

		$this->recorder = function ( $pre, $args, $url ) {
			$this->sent[] = array(
				'url'  => (string) $url,
				'args' => is_array( $args ) ? $args : array(),
			);
			$base = 'https://www.polaraccesslink.com/v3/users/' . $this->user_id . '/exercise-transactions';
			if ( $base === $url ) {
				return self::json( 201, array( 'transaction-id' => self::TXN ) );
			}
			if ( $base . '/' . self::TXN === $url ) {
				return self::json( 200, array( 'exercises' => $this->listed ) );
			}
			return self::json(
				200,
				array(
					'id'         => 'ex-' . count( $this->sent ),
					'sport'      => 'RUNNING',
					'start-time' => '2026-11-12T07:00:00',
				)
			);
		};
		add_filter( 'pre_http_request', $this->recorder, 0, 3 );
	}

	protected function tearDown(): void {
		remove_filter( 'pre_http_request', $this->recorder, 0 );
		if ( $this->user_id > 0 ) {
			require_once ABSPATH . 'wp-admin/includes/user.php';
			wp_delete_user( $this->user_id );
		}
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** @param array<string, mixed> $body */
	private static function json( int $code, array $body ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $body ),
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function exercise_url( string $id = '9001', ?int $user = null, string $txn = self::TXN ): string {
		return 'https://www.polaraccesslink.com/v3/users/' . ( $user ?? $this->user_id ) . '/exercise-transactions/' . $txn . '/exercises/' . $id;
	}

	/** @return array<int, string> */
	private function sent_urls(): array {
		return array_column( $this->sent, 'url' );
	}

	public function test_a_permitted_exercise_url_is_fetched_with_the_token_and_no_redirects(): void {
		$this->listed = array( $this->exercise_url( '9001' ), array( 'url' => $this->exercise_url( 'a-9_2' ) ) );

		$items = Outpost_Fetch_Recent_Polar::fetch_items( 10 );

		$this->assertCount( 2, $items, 'Both permitted exercises are listed.' );
		$this->assertContains( $this->exercise_url( '9001' ), $this->sent_urls() );
		$this->assertContains( $this->exercise_url( 'a-9_2' ), $this->sent_urls() );
		foreach ( $this->sent as $request ) {
			$this->assertSame( 'Bearer ' . self::TOKEN, $request['args']['headers']['Authorization'] ?? null );
			$this->assertSame( 0, $request['args']['redirection'] ?? null, 'A token-bearing Polar request never follows a redirect: ' . $request['url'] );
		}
	}

	/** @return array<string, array{0: string}> */
	public function denied_destinations(): array {
		return array(
			'other host'                => array( 'https://attacker.example/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'look-alike suffix host'    => array( 'https://www.polaraccesslink.com.attacker.example/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'look-alike prefix host'    => array( 'https://evil-www.polaraccesslink.com/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'bare apex host'            => array( 'https://polaraccesslink.com/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'plain http'                => array( 'http://www.polaraccesslink.com/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'user info'                 => array( 'https://www.polaraccesslink.com@attacker.example/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'explicit port'             => array( 'https://www.polaraccesslink.com:8443/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'another user'              => array( 'https://www.polaraccesslink.com/v3/users/999999/exercise-transactions/7781/exercises/1' ),
			'another transaction'       => array( 'https://www.polaraccesslink.com/v3/users/{u}/exercise-transactions/1/exercises/1' ),
			'another API path'          => array( 'https://www.polaraccesslink.com/v3/users/{u}' ),
			'dot segments'              => array( 'https://www.polaraccesslink.com/v3/users/{u}/exercise-transactions/7781/exercises/../../../../../v3/users/{u}' ),
			'encoded dot segments'      => array( 'https://www.polaraccesslink.com/v3/users/{u}/exercise-transactions/7781/exercises/%2e%2e/%2e%2e' ),
			'query string'              => array( 'https://www.polaraccesslink.com/v3/users/{u}/exercise-transactions/7781/exercises/1?x=1' ),
			'fragment'                  => array( 'https://www.polaraccesslink.com/v3/users/{u}/exercise-transactions/7781/exercises/1#x' ),
			'internal address'          => array( 'https://127.0.0.1/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
			'scheme-relative'           => array( '//attacker.example/v3/users/{u}/exercise-transactions/7781/exercises/1' ),
		);
	}

	/**
	 * @dataProvider denied_destinations
	 */
	public function test_a_listed_url_outside_the_contract_is_never_requested( string $template ): void {
		$denied       = str_replace( '{u}', (string) $this->user_id, $template );
		$this->listed = array( $denied, $this->exercise_url( '9001' ) );

		$items = Outpost_Fetch_Recent_Polar::fetch_items( 10 );

		$this->assertNotContains( $denied, $this->sent_urls(), 'The token never goes to a URL outside the destination contract.' );
		$this->assertCount( 3, $this->sent, 'Only the transaction start, the listing, and the permitted exercise are requested.' );
		$this->assertCount( 1, $items, 'The permitted exercise is still listed.' );
	}
}
