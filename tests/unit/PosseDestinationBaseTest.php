<?php
/**
 * Outpost_POSSE_Destination_Base unit tests (G3.5b).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_POSSE_Destination_Base;
use ReflectionMethod;
use WP_Mock;
use WP_Mock\Tools\TestCase;

/**
 * Concrete subclass that exposes the protected helpers for direct test.
 */
final class G35bExposedDestination extends Outpost_POSSE_Destination_Base {

	public string $provider = '';

	public function id(): string {
		return 'exposed-destination';
	}

	public function label(): string {
		return 'Exposed Destination';
	}

	public function provider_id(): string {
		return $this->provider;
	}

	public function dispatch( int $post_id ): array {
		return self::success_result( 'https://example.com/' . $post_id );
	}

	public function exposed_get_credentials( int $user_id ): ?array {
		return $this->get_credentials_for_user( $user_id );
	}

	public static function exposed_success( string $url ): array {
		return self::success_result( $url );
	}

	public static function exposed_failure( string $msg, bool $retryable = false ): array {
		return self::failure_result( $msg, $retryable );
	}
}

final class PosseDestinationBaseTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	public function test_success_result_has_canonical_shape(): void {
		$result = G35bExposedDestination::exposed_success( 'https://example.com/post' );

		$this->assertSame(
			array(
				'success'         => true,
				'syndication_url' => 'https://example.com/post',
				'error'           => null,
				'retryable'       => false,
			),
			$result
		);
	}

	public function test_failure_result_default_is_not_retryable(): void {
		$result = G35bExposedDestination::exposed_failure( 'something broke' );

		$this->assertFalse( $result['success'] );
		$this->assertNull( $result['syndication_url'] );
		$this->assertSame( 'something broke', $result['error'] );
		$this->assertFalse( $result['retryable'] );
	}

	public function test_failure_result_with_retryable_true(): void {
		$result = G35bExposedDestination::exposed_failure( 'gateway timeout', true );

		$this->assertTrue( $result['retryable'] );
	}

	public function test_get_credentials_returns_null_when_provider_empty(): void {
		$dest             = new G35bExposedDestination();
		$dest->provider   = '';

		$this->assertNull( $dest->exposed_get_credentials( 7 ) );
	}

	public function test_get_credentials_with_provider_calls_store(): void {
		// The store is loaded via bootstrap; with no encrypted credentials
		// stored for this user, get() returns null.
		$dest             = new G35bExposedDestination();
		$dest->provider   = 'notion';

		WP_Mock::userFunction( 'sanitize_key' )->andReturnUsing( static function ( $s ) { return $s; } );
		WP_Mock::userFunction( 'get_user_meta' )->andReturn( '' );

		$this->assertNull( $dest->exposed_get_credentials( 7 ) );
	}

	public function test_abstract_methods_are_required(): void {
		$reflection = new \ReflectionClass( Outpost_POSSE_Destination_Base::class );
		$abstract   = array();
		foreach ( $reflection->getMethods() as $method ) {
			if ( $method->isAbstract() ) {
				$abstract[] = $method->getName();
			}
		}
		sort( $abstract );

		$this->assertSame( array( 'dispatch', 'id', 'label', 'provider_id' ), $abstract );
	}
}
