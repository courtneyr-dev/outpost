/**
 * Tests for the platform detector.
 *
 * Covers User-Agent string heuristics, the iPadOS desktop-UA
 * fallback, and the default-environment helper. All tests inject a
 * deterministic environment — none touch real `navigator` or
 * `window.matchMedia`.
 */

import { describe, expect, it } from 'vitest';
import { detect_platform } from './platform';

describe( 'detect_platform', () => {
	it( 'classifies Android user agents', () => {
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		} ) ).toBe( 'android' );
	} );

	it( 'classifies iPhone user agents', () => {
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		} ) ).toBe( 'ios' );
	} );

	it( 'classifies iPad user agents (legacy iOS UA, before iPadOS 13)', () => {
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (iPad; CPU OS 12_0 like Mac OS X)',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		} ) ).toBe( 'ios' );
	} );

	it( 'classifies iPod touch user agents', () => {
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (iPod touch; CPU iPhone OS 16_0 like Mac OS X)',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		} ) ).toBe( 'ios' );
	} );

	it( 'classifies Windows user agents as desktop', () => {
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
			max_touch_points: 0,
			matches_pointer_coarse: false,
		} ) ).toBe( 'desktop' );
	} );

	it( 'classifies real macOS user agents as desktop', () => {
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1',
			max_touch_points: 0,
			matches_pointer_coarse: false,
		} ) ).toBe( 'desktop' );
	} );

	it( 'classifies iPadOS 13+ desktop-UA + touch + coarse pointer as ios', () => {
		// Safari on iPadOS 13+ defaults to a Mac-shaped UA. The
		// touch-points + pointer-coarse fallback recovers the iOS
		// classification without the UA naming the platform.
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		} ) ).toBe( 'ios' );
	} );

	it( 'does NOT misclassify a real Mac with a connected touchscreen as ios', () => {
		// Mac UA + touch points but pointer-coarse is false (real
		// trackpad/mouse) → desktop.
		expect( detect_platform( {
			user_agent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15',
			max_touch_points: 5,
			matches_pointer_coarse: false,
		} ) ).toBe( 'desktop' );
	} );

	it( 'classifies an empty user agent as desktop', () => {
		expect( detect_platform( {
			user_agent: '',
			max_touch_points: 0,
			matches_pointer_coarse: false,
		} ) ).toBe( 'desktop' );
	} );
} );
