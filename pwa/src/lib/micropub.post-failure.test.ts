import { describe, it, expect } from 'vitest';
import { MicropubError, describe_post_failure } from './micropub';

// What the composer must never show: the internal code, the function name, or
// the server's echoed status and body (implementation brief section 7: "Keep
// diagnostics out of ordinary composer copy").
const DIAGNOSTIC =
	/post_h_entry|upload_media|post_failed|discovery_failed|no_endpoint|micropub endpoint returned|fetch threw|\b[45]\d\d\b|insufficient_scope/;

const raw = (status: number): MicropubError =>
	new MicropubError(
		'post_h_entry: micropub endpoint returned ' +
			String(status) +
			' — {"error":"insufficient_scope"}',
		'post_failed',
		status
	);

describe('describe_post_failure', () => {
	it('tells an expired sign-in, a refusal and a server failure apart', () => {
		const expired = describe_post_failure(raw(401));
		const refused = describe_post_failure(raw(403));
		const broken = describe_post_failure(raw(500));
		expect(expired.kind).toBe('expired_sign_in');
		expect(refused.kind).toBe('forbidden');
		expect(broken.kind).toBe('server_error');
		expect(
			new Set([expired.message, refused.message, broken.message]).size
		).toBe(3);
	});

	it('treats a query rejected with the unauthorized code as an expired sign-in', () => {
		const err = new MicropubError(
			'micropub_query: the site no longer accepts this sign-in (401).',
			'unauthorized',
			401
		);
		expect(describe_post_failure(err).kind).toBe('expired_sign_in');
	});

	it('counts every 5xx status as a server failure', () => {
		for (const status of [500, 502, 503, 504]) {
			expect(describe_post_failure(raw(status)).kind).toBe(
				'server_error'
			);
		}
	});

	it('gives other refusals, missing endpoints, bad links and unknown errors their own plain message', () => {
		const cases = [
			describe_post_failure(raw(400)),
			describe_post_failure(
				new MicropubError(
					'discover_endpoint: no rel="micropub" found',
					'no_endpoint'
				)
			),
			describe_post_failure(
				new MicropubError(
					'discover_endpoint: fetch threw — boom',
					'discovery_failed'
				)
			),
			describe_post_failure(
				new MicropubError(
					'post_h_entry: micropub returned an unsafe Location URL: javascript:x',
					'invalid_location'
				)
			),
			describe_post_failure(new Error('something internal broke')),
			describe_post_failure('not even an error'),
		];
		expect(cases.map((c) => c.kind)).toEqual([
			'not_accepted',
			'no_endpoint',
			'no_endpoint',
			'bad_link',
			'unknown',
			'unknown',
		]);
		for (const c of cases)
			expect(c.message).not.toMatch(
				/something internal broke|javascript:/
			);
	});

	it('never puts diagnostics in the message', () => {
		const errors: unknown[] = [
			raw(401),
			raw(403),
			raw(500),
			raw(400),
			new MicropubError(
				'upload_media: media endpoint response is missing the Location header',
				'no_location'
			),
			new MicropubError(
				'discover_endpoint: fetch threw — TypeError',
				'discovery_failed'
			),
			new Error('post_h_entry: boom'),
		];
		for (const err of errors) {
			expect(describe_post_failure(err).message).not.toMatch(DIAGNOSTIC);
		}
	});

	it('tells the person their text is kept, except where the post may already exist', () => {
		for (const status of [401, 403, 500, 400]) {
			expect(describe_post_failure(raw(status)).message).toMatch(
				/still here/
			);
		}
		// A bad link comes back only after the site answered 2xx: the post may exist.
		expect(
			describe_post_failure(new MicropubError('x', 'invalid_location'))
				.message
		).toMatch(/check your site/i);
	});
});
