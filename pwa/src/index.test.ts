import { describe, it, expect } from 'vitest';
import { detect_route } from './index';

describe('detect_route', () => {
	it.each([
		['/post/', 'composer'],
		['/post', 'composer'],
		['/post/share-target', 'share-target'],
		['/post/share-target/', 'share-target'],
		['/post/share-target?title=X&url=Y', 'share-target'],
		['/post/auth/callback', 'auth-callback'],
		['/post/auth/callback/', 'auth-callback'],
		['/post/auth/callback?code=abc&state=xyz', 'auth-callback'],
		['/wp-admin/index.php', 'unknown'],
		['/', 'unknown'],
		['/post-name', 'unknown'],
	] as const)('maps %s -> %s', (pathname, expected) => {
		expect(detect_route(pathname)).toBe(expected);
	});
});
