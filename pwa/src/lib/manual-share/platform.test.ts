/**
 * Tests for the platform detector.
 *
 * Covers User-Agent string heuristics, the iPadOS desktop-UA
 * fallback, and the default-environment helper. All tests inject a
 * deterministic environment — none touch real `navigator` or
 * `window.matchMedia`.
 */

import { describe, expect, it, vi, afterEach, beforeEach } from 'vitest';
import { detect_platform, is_pwa_installed_on_ios } from './platform';

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

describe( 'is_pwa_installed_on_ios', () => {
	let original_match_media: typeof window.matchMedia;
	let original_standalone: unknown;

	beforeEach( () => {
		original_match_media = window.matchMedia;
		original_standalone  = ( navigator as unknown as { standalone?: boolean } ).standalone;
	} );

	afterEach( () => {
		window.matchMedia = original_match_media;
		// Restore via conditional spread to satisfy
		// `exactOptionalPropertyTypes` (CLAUDE.md B0a #4 / F10 follow-up).
		const nav = navigator as unknown as { standalone?: boolean };
		if ( typeof original_standalone === 'boolean' ) {
			nav.standalone = original_standalone;
		} else {
			delete nav.standalone;
		}
	} );

	it( 'returns false on non-iOS platforms', () => {
		const env = {
			user_agent: 'Mozilla/5.0 (Linux; Android 14)',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		};
		expect( is_pwa_installed_on_ios( env ) ).toBe( false );
	} );

	it( 'returns true on iOS when display-mode standalone matches', () => {
		const env = {
			user_agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		};
		window.matchMedia = vi.fn().mockReturnValue( { matches: true } ) as unknown as typeof window.matchMedia;

		expect( is_pwa_installed_on_ios( env ) ).toBe( true );
	} );

	it( 'returns true on iOS when navigator.standalone is true (legacy Apple)', () => {
		const env = {
			user_agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		};
		window.matchMedia = vi.fn().mockReturnValue( { matches: false } ) as unknown as typeof window.matchMedia;
		( navigator as unknown as { standalone?: boolean } ).standalone = true;

		expect( is_pwa_installed_on_ios( env ) ).toBe( true );
	} );

	it( 'returns false on iOS when neither signal indicates standalone', () => {
		const env = {
			user_agent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X)',
			max_touch_points: 5,
			matches_pointer_coarse: true,
		};
		window.matchMedia = vi.fn().mockReturnValue( { matches: false } ) as unknown as typeof window.matchMedia;
		( navigator as unknown as { standalone?: boolean } ).standalone = false;

		expect( is_pwa_installed_on_ios( env ) ).toBe( false );
	} );
} );
