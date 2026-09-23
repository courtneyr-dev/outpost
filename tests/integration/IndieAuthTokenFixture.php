<?php
/**
 * IndieAuth token fixture for integration tests of Outpost_Bearer_Auth.
 *
 * With IndieAuth loaded (`.wp-env.json` installs it), tokens are real: they
 * are minted with `IndieAuth\Token\User` and verified by IndieAuth's own
 * `determine_current_user` callback, so its `indieauth_response`,
 * `indieauth_scopes` and `map_meta_cap` behavior all apply. Without
 * IndieAuth, a stand-in supplies the parts Outpost reads: a
 * `determine_current_user` validator using IndieAuth's header pattern, and
 * the `indieauth_response` / `indieauth_scopes` filters.
 *
 * reset_indieauth_fixture() removes every filter the fixture added and
 * clears IndieAuth's per-request state; call it from setUp() and
 * tearDown(). IndieAuth's `Authorize` instance
 * keeps the last verified token's response and scopes in properties, and a
 * PHPUnit run is one process, so without the reset a token verified in one
 * test would mark every later request as IndieAuth-authenticated.
 *
 * @package Outpost\Tests\Integration
 */

declare(strict_types=1);

namespace Outpost\Tests\Integration;

trait IndieAuthTokenFixture {

	/** @var array<int, array{0: string, 1: callable, 2: int}> Hook, callback, priority. */
	private array $indieauth_fixture_filters = array();

	/** @var array<string, array<string, mixed>> Stand-in token => token response. */
	private array $indieauth_stand_in_tokens = array();

	/** @var array<string, mixed> Response of the token the stand-in last verified. */
	private array $indieauth_stand_in_response = array();

	private function indieauth_is_loaded(): bool {
		return class_exists( '\IndieAuth\Token\User' ) && null !== $this->indieauth_authorize();
	}

	/**
	 * Issue a token for `$user_id` carrying `$scopes`.
	 *
	 * @param string[] $scopes Token scopes.
	 */
	private function issue_indieauth_token( int $user_id, array $scopes ): string {
		$info = array(
			'token_type' => 'Bearer',
			'scope'      => implode( ' ', $scopes ),
			'client_id'  => 'https://outpost.example.test/',
			'iat'        => time(),
		);

		if ( $this->indieauth_is_loaded() ) {
			$token = ( new \IndieAuth\Token\User( '_indieauth_token_', $user_id ) )->set( $info );
			$this->assertIsString( $token, 'IndieAuth\Token\User::set() must mint a token.' );
			return $token;
		}

		$this->install_indieauth_stand_in();
		$token = 'stand-in-' . wp_generate_password( 32, false );

		$this->indieauth_stand_in_tokens[ $token ] = $info + array( 'user' => $user_id );
		return $token;
	}

	/**
	 * Resolve the current user the way WordPress does before it dispatches
	 * a REST request, and leave it set, as IndieAuth's
	 * `determine_current_user` callback leaves it. The route's
	 * `authenticate_bearer_token()` then returns early without resolving
	 * anything itself.
	 */
	private function resolve_current_user_like_core(): int {
		wp_set_current_user( 0 );
		$user_id = (int) apply_filters( 'determine_current_user', false );
		wp_set_current_user( $user_id );
		return $user_id;
	}

	/**
	 * Remove every filter the fixture added, forget its stand-in tokens,
	 * and clear per-request state.
	 */
	private function reset_indieauth_fixture(): void {
		foreach ( $this->indieauth_fixture_filters as $filter ) {
			remove_filter( $filter[0], $filter[1], $filter[2] );
		}
		$this->indieauth_fixture_filters = array();
		$this->indieauth_stand_in_tokens = array();
		$this->reset_indieauth_request_state();
	}

	/**
	 * Clear what one request leaves behind, as a fresh PHP process would:
	 * the verified-token state (IndieAuth's `Authorize` properties, or the
	 * stand-in's) and the request's Authorization credentials.
	 */
	private function reset_indieauth_request_state(): void {
		$this->indieauth_stand_in_response = array();

		$authorize = $this->indieauth_authorize();
		if ( null !== $authorize ) {
			$authorize->response = array();
			$authorize->scopes   = array();
			$authorize->error    = null;
		}

		unset( $_SERVER['HTTP_AUTHORIZATION'], $_SERVER['REDIRECT_HTTP_AUTHORIZATION'], $_POST['access_token'] );
	}

	/**
	 * IndieAuth never stores its `Authorize` instance; find it through the
	 * `indieauth_response` callback it registers on itself.
	 */
	private function indieauth_authorize(): ?object {
		global $wp_filter;
		if ( ! class_exists( '\IndieAuth\Authorize' ) || ! isset( $wp_filter['indieauth_response'] ) ) {
			return null;
		}
		foreach ( $wp_filter['indieauth_response']->callbacks as $callbacks ) {
			foreach ( $callbacks as $entry ) {
				$callback = $entry['function'] ?? null;
				if ( is_array( $callback ) && ( $callback[0] ?? null ) instanceof \IndieAuth\Authorize ) {
					return $callback[0];
				}
			}
		}
		return null;
	}

	private function install_indieauth_stand_in(): void {
		if ( array() !== $this->indieauth_fixture_filters ) {
			return;
		}
		$this->add_indieauth_fixture_filter(
			'determine_current_user',
			function ( $user_id ) {
				$token = $this->indieauth_stand_in_provided_token();
				if ( null === $token || ! isset( $this->indieauth_stand_in_tokens[ $token ] ) ) {
					return $user_id;
				}
				$this->indieauth_stand_in_response = $this->indieauth_stand_in_tokens[ $token ];
				return (int) $this->indieauth_stand_in_response['user'];
			},
			15
		);
		$this->add_indieauth_fixture_filter(
			'indieauth_response',
			function ( $response ) {
				return $response ? $response : $this->indieauth_stand_in_response;
			},
			9
		);
		$this->add_indieauth_fixture_filter(
			'indieauth_scopes',
			function ( $scopes ) {
				if ( $scopes ) {
					return $scopes;
				}
				return isset( $this->indieauth_stand_in_response['scope'] )
					? explode( ' ', (string) $this->indieauth_stand_in_response['scope'] )
					: array();
			},
			9
		);
	}

	private function add_indieauth_fixture_filter( string $hook, callable $callback, int $priority ): void {
		add_filter( $hook, $callback, $priority );
		$this->indieauth_fixture_filters[] = array( $hook, $callback, $priority );
	}

	/**
	 * The token IndieAuth's `Authorize::get_provided_token()` would read:
	 * the Authorization header through its unanchored pattern, then
	 * `$_POST['access_token']`.
	 */
	private function indieauth_stand_in_provided_token(): ?string {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) && preg_match( '/Bearer ([\x20-\x7E]+)/', trim( (string) $_SERVER[ $key ] ), $matches ) ) {
				return $matches[1];
			}
		}
		if ( isset( $_POST['access_token'] ) && is_string( $_POST['access_token'] ) && '' !== $_POST['access_token'] ) {
			return $_POST['access_token'];
		}
		return null;
	}
}
