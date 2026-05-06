<?php
/**
 * Outpost_Mock_Server_Filter unit tests (G99-mock-server).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Mock_Server_Filter;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class MockServerFilterTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
		WP_Mock::userFunction( 'wp_parse_url' )->andReturnUsing(
			static function ( $url ) { return parse_url( $url ); }
		);
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// ---------- rewrite_url_if_eligible ----------

	public function test_rewrite_url_returns_null_when_constant_undefined(): void {
		// Note: in this test the constant is NOT defined. The filter is
		// production-safe because rewriting is gated on the constant.
		$this->assertFalse( defined( 'OUTPOST_TEST_MOCK_SERVER_URL' ), 'precondition: constant must not be defined globally' );

		$result = Outpost_Mock_Server_Filter::rewrite_url_if_eligible( 'https://api.notion.com/v1/users/me' );

		$this->assertNull( $result );
	}

	public function test_rewritable_hosts_includes_known_oauth_providers(): void {
		$hosts = Outpost_Mock_Server_Filter::rewritable_hosts();

		$this->assertContains( 'api.notion.com', $hosts );
		$this->assertContains( 'api.ouraring.com', $hosts );
		$this->assertContains( 'ridewithgps.com', $hosts );
		$this->assertContains( 'api.ravelry.com', $hosts );
		$this->assertContains( 'api.prod.whoop.com', $hosts );
		$this->assertContains( 'polarremote.com', $hosts );
	}

	public function test_rewritable_hosts_does_not_include_unrelated_hosts(): void {
		$hosts = Outpost_Mock_Server_Filter::rewritable_hosts();

		$this->assertNotContains( 'example.com', $hosts );
		$this->assertNotContains( 'localhost', $hosts );
		$this->assertNotContains( 'wordpress.org', $hosts );
	}

	// The remaining behaviors require the constant to be defined.
	// Use process isolation via a separate test method that defines
	// the constant temporarily and verifies via reflection.

	public function test_maybe_rewrite_passes_through_when_constant_undefined(): void {
		$preempt = Outpost_Mock_Server_Filter::maybe_rewrite( false, array(), 'https://api.notion.com/v1/users/me' );

		// With no constant defined, the filter must return the unchanged
		// preempt value so wp_remote_request continues normally.
		$this->assertFalse( $preempt );
	}

	public function test_maybe_rewrite_respects_existing_preempt(): void {
		// If a higher-priority filter already preempted, our filter
		// must respect that and not double-handle.
		$preempt_response = array( 'response' => array( 'code' => 200 ) );

		$result = Outpost_Mock_Server_Filter::maybe_rewrite( $preempt_response, array(), 'https://api.notion.com/v1/users/me' );

		$this->assertSame( $preempt_response, $result );
	}

	// The remaining tests use the test seam ($mock_base_override) so
	// they don't need to define a global constant — keeps tests parallel-safe.

	public function test_rewrite_url_when_eligible_host_with_override(): void {
		$result = Outpost_Mock_Server_Filter::rewrite_url_if_eligible(
			'https://api.notion.com/v1/users/me',
			'http://localhost:8888'
		);

		$this->assertSame( 'http://localhost:8888/v1/users/me', $result );
	}

	public function test_rewrite_url_preserves_query_string(): void {
		$result = Outpost_Mock_Server_Filter::rewrite_url_if_eligible(
			'https://api.notion.com/v1/users/me?foo=bar&baz=qux',
			'http://localhost:8888'
		);

		$this->assertSame( 'http://localhost:8888/v1/users/me?foo=bar&baz=qux', $result );
	}

	public function test_rewrite_url_passes_through_unknown_host(): void {
		$result = Outpost_Mock_Server_Filter::rewrite_url_if_eligible(
			'https://example.com/api',
			'http://localhost:8888'
		);

		$this->assertNull( $result );
	}

	public function test_rewrite_url_strips_trailing_slash_from_mock_base(): void {
		$result = Outpost_Mock_Server_Filter::rewrite_url_if_eligible(
			'https://api.notion.com/v1/users/me',
			'http://localhost:8888/'
		);

		// No double-slash between base and path.
		$this->assertSame( 'http://localhost:8888/v1/users/me', $result );
	}

	public function test_rewrite_url_uses_root_path_when_url_has_none(): void {
		$result = Outpost_Mock_Server_Filter::rewrite_url_if_eligible(
			'https://api.notion.com',
			'http://localhost:8888'
		);

		$this->assertSame( 'http://localhost:8888/', $result );
	}

	public function test_rewrite_url_lowercases_host_for_match(): void {
		$result = Outpost_Mock_Server_Filter::rewrite_url_if_eligible(
			'https://API.NOTION.COM/v1/users/me',
			'http://localhost:8888'
		);

		$this->assertSame( 'http://localhost:8888/v1/users/me', $result );
	}
}
