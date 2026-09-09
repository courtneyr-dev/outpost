/**
 * Tests for build_photo_properties — the Micropub photo properties a photo
 * post sends.
 */
import { describe, it, expect } from 'vitest';
import { build_photo_properties } from './photo-properties';

describe('build_photo_properties', () => {
	it('sends a caption array paired to the photos by index', () => {
		const props = build_photo_properties(
			['https://example.test/a.jpg', 'https://example.test/b.jpg'],
			[
				{ alt: 'Alt A', decorative: false, caption: 'Caption A' },
				{ alt: 'Alt B', decorative: false, caption: 'Caption B' },
			]
		);

		expect(props.photo).toEqual([
			'https://example.test/a.jpg',
			'https://example.test/b.jpg',
		]);
		expect(props['mp-photo-alt']).toEqual(['Alt A', 'Alt B']);
		expect(props['mp-photo-caption']).toEqual(['Caption A', 'Caption B']);
	});

	it('omits the caption property entirely when no photo has one', () => {
		const props = build_photo_properties(
			['https://example.test/a.jpg'],
			[{ alt: 'Alt A', decorative: false, caption: '' }]
		);

		expect(props['mp-photo-caption']).toBeUndefined();
	});

	it('keeps a gap for an uncaptioned photo so indexes still line up', () => {
		const props = build_photo_properties(
			['https://example.test/a.jpg', 'https://example.test/b.jpg'],
			[
				{ alt: 'Alt A', decorative: false, caption: '' },
				{ alt: 'Alt B', decorative: false, caption: 'Only B' },
			]
		);

		expect(props['mp-photo-caption']).toEqual(['', 'Only B']);
	});

	it('collapses a single photo to the string shape, caption included', () => {
		const props = build_photo_properties(
			['https://example.test/a.jpg'],
			[{ alt: 'Alt A', decorative: false, caption: 'Just one' }]
		);

		expect(props.photo).toBe('https://example.test/a.jpg');
		expect(props['mp-photo-alt']).toBe('Alt A');
		expect(props['mp-photo-caption']).toBe('Just one');
	});

	it('sends empty alt for a decorative photo but keeps its caption', () => {
		const props = build_photo_properties(
			['https://example.test/a.jpg'],
			[{ alt: 'ignored', decorative: true, caption: 'Still captioned' }]
		);

		expect(props['mp-photo-alt']).toBe('');
		expect(props['mp-photo-caption']).toBe('Still captioned');
	});
});
