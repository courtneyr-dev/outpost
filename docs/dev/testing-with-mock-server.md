# Testing with the mock server

Outpost's integration suite runs against a containerized HTTP mock server (WireMock 3) alongside `wp-env`'s WordPress containers. This unblocks integration tests that previously couldn't run without hitting production OAuth / API endpoints.

## Local-dev workflow

```bash
# Start the mock server (detached). Port 8080, because wp-env claims
# 8888 and 8889, and wp_http_validate_url() only allows ports 80, 443,
# and 8080.
(cd tests/mock-server && docker compose up -d)

# Start wp-env's containers (WordPress, MySQL, tests-cli).
npm run wp-env:start

# Run the integration suite inside the tests-cli container.
npm run test:integration

# Tear down when done.
npm run wp-env:stop
(cd tests/mock-server && docker compose down)
```

You don't need to export anything. `tests/integration/bootstrap.php` defines `OUTPOST_TEST_MOCK_SERVER_URL` from, in order: an `OUTPOST_TEST_MOCK_SERVER_URL` environment variable, if one is set inside the tests-cli container; `http://host.docker.internal:8080` when that name resolves (Docker Desktop on macOS and Windows); `http://172.17.0.1:8080` otherwise (Linux Docker, including CI). Run the suite through `npm run test:integration`, not `composer test:integration` on your host: the bootstrap needs the WordPress test suite that only exists inside the tests-cli container. See [INTEGRATION-TESTING.md](../INTEGRATION-TESTING.md).

The mock server listens on host port 8080 (container port 8080). `Outpost_Mock_Server::reset()` wipes stubs and journaled requests between tests.

## How rewriting works

`Outpost_Mock_Server_Filter` hooks `pre_http_request`. When the `OUTPOST_TEST_MOCK_SERVER_URL` constant is defined AND the outbound URL's host is in the rewritable list, the request gets rewritten to point at the mock server's host + the original path + query string. The hardcoded host list lives in the filter itself (NOT a runtime filter / option) — adding a new upstream means a code change. This is intentional: it prevents accidental rewriting from a third-party plugin and keeps the production attack surface zero (the constant is never defined in production).

When the constant is NOT defined, the filter is a no-op and outbound HTTP behaves exactly as before. Production safety is preserved by construction.

## Writing an integration test

Integration tests extend `PHPUnit\Framework\TestCase`. The integration bootstrap has already loaded WordPress, so WordPress functions work inside the test.

```php
use PHPUnit\Framework\TestCase;

final class MyIntegrationTest extends TestCase {

    public function setUp(): void {
        parent::setUp();
        Outpost_Mock_Server::reset();
    }

    public function test_oauth_token_exchange_persists_credentials(): void {
        Outpost_Mock_Server::stub_from_fixture( 'oauth/token-exchange-success.json' );

        // Exercise the SUT — its outbound POST to api.notion.com gets
        // rewritten to the mock server, which returns the fixture body.
        $result = Outpost_OAuth_Controller::handle_callback( /* ... */ );

        $this->assertTrue( $result );

        // Assert the SUT actually sent the expected upstream request.
        $requests = Outpost_Mock_Server::received_requests( 'POST', '/v1/oauth/token' );
        $this->assertCount( 1, $requests );
        $this->assertNotEmpty( $requests[0]['body'] );
    }
}
```

## Writing a fixture

Fixtures live at `tests/fixtures/mock-server/<provider>/<scenario>.json`. Format is WireMock's native stub mapping format:

```json
{
    "request": {
        "method": "POST",
        "url": "/v1/oauth/token"
    },
    "response": {
        "status": 200,
        "headers": { "Content-Type": "application/json" },
        "jsonBody": {
            "access_token": "fixture-access-token",
            "token_type": "Bearer"
        }
    }
}
```

For path patterns (regex), use `urlPathPattern` instead of `url`. For headers / query / body matchers, see the [WireMock docs](https://wiremock.org/docs/request-matching/). Tradeoff: tied to WireMock, but no abstraction layer to maintain. If we ever switch mock servers, fixtures get rewritten then; v1 simplicity wins.

## Adding a new upstream

To rewrite outbound HTTP for a new host (new OAuth provider, new RSS source, etc.), edit `Outpost_Mock_Server_Filter::REWRITABLE_HOSTS` and add the host. Then commit a fixture under `tests/fixtures/mock-server/<your-provider>/`. The filter's unit test asserts the host is present in the array — keep the fixture and the host list in sync.

## Why WireMock

- Industry-standard, language-agnostic.
- Configurable via JSON files in a mounted directory or via the admin API.
- Records received requests for assertion-based testing.
- Active project, current major version is 3.

The alternative (MSW Node) is JavaScript-native and more popular for JS-side mocking. Outpost's tests are mostly PHPUnit, so HTTP-protocol-level mocking (WireMock) fits better than a Node intercept layer.

## Why no "real network" fallback

When `OUTPOST_TEST_MOCK_SERVER_URL` is defined and a request matches a rewritable host, the request ALWAYS rewrites. There's no "fall through to real network" mode — if the mock server isn't running, integration tests fail loudly with a clear error message. This prevents tests from accidentally making real API calls (which would be flaky, slow, and could leak fixture credentials to upstream logs).

## Migration of existing skipped stubs

Some integration tests in `tests/integration/` still call `markTestSkipped()` until a fixture exists for their upstream. To migrate one, write the fixture, switch the test to `Outpost_Mock_Server::stub_from_fixture(...)`, and remove the skip. Each test comes off the skip list on its own. `docs/dev/g99-stub-migration-inventory.md` tracks the remaining clusters.
