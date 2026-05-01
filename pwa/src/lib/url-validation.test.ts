import { describe, it, expect } from 'vitest';
import { is_safe_http_url } from './url-validation';

describe('is_safe_http_url', () => {
	it.each([
		'http://example.test/',
		'https://example.test/',
		'https://example.test/post/123',
		'https://example.test:8080/path?query=1#frag',
		'http://192.168.1.1/',
		'https://localhost/',
	])('accepts http(s) URL: %s', (url) => {
		expect(is_safe_http_url(url)).toBe(true);
	});

	it.each([
		['javascript:alert(1)', 'javascript scheme'],
		['data:text/html,<script>alert(1)</script>', 'data scheme'],
		['file:///etc/passwd', 'file scheme'],
		['mailto:foo@example.com', 'mailto scheme'],
		['ws://example.test/', 'ws scheme'],
		['wss://example.test/', 'wss scheme'],
		['ftp://example.test/', 'ftp scheme'],
		['JavaScript:alert(1)', 'mixed-case javascript scheme'],
	])('rejects non-http(s) scheme (%s)', (url) => {
		expect(is_safe_http_url(url)).toBe(false);
	});

	it.each([
		['', 'empty string'],
		['   ', 'whitespace'],
		['not a url', 'plain text'],
		['/relative/path', 'absolute path without scheme'],
		['example.test', 'bare hostname'],
		['//example.test/', 'protocol-relative URL'],
	])('rejects malformed input (%s)', (url) => {
		expect(is_safe_http_url(url)).toBe(false);
	});
});
