/**
 * Tests for the F12 capture API helpers.
 */

import { describe, expect, it, vi } from 'vitest';
import {
	fetch_pending,
	submit_capture,
	default_capture_api_environment,
	type CaptureApiEnvironment,
} from './capture-api';

function mock_fetch( response: { status: number; body: unknown; ok?: boolean } ): typeof fetch {
	const ok = response.ok ?? ( response.status >= 200 && response.status < 300 );
	return vi.fn( async () => ( {
		ok,
		status:  response.status,
		json:    async () => response.body,
		text:    async () => JSON.stringify( response.body ),
		headers: new Headers(),
	} as unknown as Response ) ) as unknown as typeof fetch;
}

describe( 'fetch_pending', () => {
	it( 'GETs /manual-share/pending and returns the parsed body', async () => {
		const fetch_mock = mock_fetch( {
			status: 200,
			body:   { pending: [] },
		} );
		const env: CaptureApiEnvironment = {
			fetch:        fetch_mock,
			bearer_token: 'test-token',
		};

		const result = await fetch_pending( env );

		expect( result.pending ).toEqual( [] );
		const call = ( fetch_mock as unknown as { mock: { calls: unknown[][] } } ).mock.calls[0]!;
		expect( call[0] ).toBe( '/wp-json/outpost/v1/manual-share/pending' );
		const init = call[1] as RequestInit;
		expect( init.method ).toBe( 'GET' );
		const headers = init.headers as Record<string, string>;
		expect( headers.Authorization ).toBe( 'Bearer test-token' );
	} );

	it( 'omits Authorization header when no bearer token', async () => {
		const fetch_mock = mock_fetch( { status: 200, body: { pending: [] } } );
		const env: CaptureApiEnvironment = { fetch: fetch_mock };

		await fetch_pending( env );

		const init = ( fetch_mock as unknown as { mock: { calls: unknown[][] } } )
			.mock.calls[0]?.[1] as RequestInit;
		const headers = init.headers as Record<string, string>;
		expect( headers.Authorization ).toBeUndefined();
	} );

	it( 'throws on non-2xx response', async () => {
		const fetch_mock = mock_fetch( { status: 401, body: {}, ok: false } );
		const env: CaptureApiEnvironment = { fetch: fetch_mock };

		await expect( fetch_pending( env ) ).rejects.toThrow( /401/ );
	} );

	it( 'honors api_base override', async () => {
		const fetch_mock = mock_fetch( { status: 200, body: { pending: [] } } );
		const env: CaptureApiEnvironment = {
			fetch:    fetch_mock,
			api_base: '/custom/api',
		};

		await fetch_pending( env );

		const url = ( fetch_mock as unknown as { mock: { calls: unknown[][] } } ).mock.calls[0]?.[0];
		expect( url ).toBe( '/custom/api/manual-share/pending' );
	} );
} );

describe( 'submit_capture', () => {
	it( "returns 'recorded' shape on success", async () => {
		const recorded = {
			status:             'recorded',
			audit_log_id:       'a1',
			silo_url:           'https://example.com/p/abc',
			platform_id:        'instagram-feed',
			syndication_links:  [],
			mismatch_confirmed: false,
		};
		const fetch_mock = mock_fetch( { status: 200, body: recorded } );
		const env: CaptureApiEnvironment = { fetch: fetch_mock };

		const result = await submit_capture(
			{
				post_id:      42,
				audit_log_id: 'a1',
				silo_url:     'https://example.com/p/abc',
			},
			env,
		);

		expect( result.status ).toBe( 'recorded' );
	} );

	it( "returns 'mismatch_warning' when server flags domain mismatch", async () => {
		const fetch_mock = mock_fetch( {
			status: 200,
			body:   {
				status:      'mismatch_warning',
				platform_id: 'instagram-feed',
				silo_url:    'https://twitter.com/x',
				message:     'Mismatch',
			},
		} );
		const env: CaptureApiEnvironment = { fetch: fetch_mock };

		const result = await submit_capture(
			{
				post_id:      42,
				audit_log_id: 'a1',
				silo_url:     'https://twitter.com/x',
			},
			env,
		);

		expect( result.status ).toBe( 'mismatch_warning' );
	} );

	it( "returns 'error' shape on validation failure", async () => {
		const fetch_mock = mock_fetch( {
			status: 400,
			body:   { code: 'invalid_scheme', message: 'Only http and https URLs are accepted.' },
			ok:     false,
		} );
		const env: CaptureApiEnvironment = { fetch: fetch_mock };

		const result = await submit_capture(
			{
				post_id:      42,
				audit_log_id: 'a1',
				silo_url:     'javascript:alert(1)',
			},
			env,
		);

		expect( result.status ).toBe( 'error' );
		if ( result.status === 'error' ) {
			expect( result.code ).toBe( 'invalid_scheme' );
			expect( result.message ).toContain( 'http' );
		}
	} );

	it( 'POSTs JSON body with all fields including confirm_mismatch', async () => {
		const fetch_mock = mock_fetch( {
			status: 200,
			body:   {
				status:             'recorded',
				audit_log_id:       'a1',
				silo_url:           'x',
				platform_id:        'p',
				syndication_links:  [],
				mismatch_confirmed: true,
			},
		} );
		const env: CaptureApiEnvironment = { fetch: fetch_mock };

		await submit_capture(
			{
				post_id:          42,
				audit_log_id:     'a1',
				silo_url:         'https://twitter.com/x',
				confirm_mismatch: true,
			},
			env,
		);

		const init = ( fetch_mock as unknown as { mock: { calls: unknown[][] } } )
			.mock.calls[0]?.[1] as RequestInit;
		const body = JSON.parse( init.body as string );
		expect( body.confirm_mismatch ).toBe( true );
		expect( body.post_id ).toBe( 42 );
		expect( body.silo_url ).toBe( 'https://twitter.com/x' );
	} );
} );

describe( 'default_capture_api_environment', () => {
	it( 'omits bearer_token when none provided', () => {
		const env = default_capture_api_environment();
		expect( env.bearer_token ).toBeUndefined();
	} );

	it( 'sets bearer_token when provided', () => {
		const env = default_capture_api_environment( 'token-1' );
		expect( env.bearer_token ).toBe( 'token-1' );
	} );

	it( 'omits bearer_token for empty string', () => {
		const env = default_capture_api_environment( '' );
		expect( env.bearer_token ).toBeUndefined();
	} );
} );
