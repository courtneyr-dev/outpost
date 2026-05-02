import { describe, it, expect } from 'vitest';
import {
	is_supported_image_type,
	scale_to_fit,
	process_photo,
	PhotoError,
} from './photo';

describe('is_supported_image_type', () => {
	it.each([
		['image/jpeg', true],
		['image/png', true],
		['image/webp', true],
		['image/gif', true],
		['image/avif', true],
		['IMAGE/JPEG', true], // case-insensitive
	])('accepts %s → %s', (mime, expected) => {
		expect(is_supported_image_type(mime)).toBe(expected);
	});

	it.each([
		['image/svg+xml', false], // SVG explicitly excluded
		['image/heic', false], // not in allowlist
		['application/pdf', false],
		['text/plain', false],
		['', false],
	])('rejects %s → %s', (mime, expected) => {
		expect(is_supported_image_type(mime)).toBe(expected);
	});
});

describe('scale_to_fit', () => {
	it('returns original dimensions when already within max', () => {
		expect(scale_to_fit(800, 600, 2048)).toEqual({ width: 800, height: 600 });
	});

	it('scales down landscape to fit max long edge', () => {
		expect(scale_to_fit(4096, 3072, 2048)).toEqual({ width: 2048, height: 1536 });
	});

	it('scales down portrait to fit max long edge', () => {
		expect(scale_to_fit(3072, 4096, 2048)).toEqual({ width: 1536, height: 2048 });
	});

	it('scales square to fit', () => {
		expect(scale_to_fit(4000, 4000, 2048)).toEqual({ width: 2048, height: 2048 });
	});

	it('preserves aspect ratio precisely', () => {
		const result = scale_to_fit(3000, 2000, 1500);
		// 3000 / 2 = 1500; 2000 / 2 = 1000
		expect(result).toEqual({ width: 1500, height: 1000 });
	});
});

describe('process_photo validation', () => {
	function make_file(type: string, size: number): File {
		// Create a File with a fake size by using a Blob with the given content length.
		// happy-dom's File constructor honors the size of the underlying parts.
		const filler = 'x'.repeat(size);
		return new File([filler], 'photo.bin', { type });
	}

	it('rejects unsupported MIME types before attempting to load', async () => {
		const file = make_file('image/svg+xml', 1000);
		await expect(process_photo(file)).rejects.toMatchObject({ code: 'unsupported_type' });
	});

	it('rejects HEIC explicitly', async () => {
		const file = make_file('image/heic', 1000);
		await expect(process_photo(file)).rejects.toMatchObject({ code: 'unsupported_type' });
	});

	it('rejects oversized files', async () => {
		const file = make_file('image/jpeg', 11 * 1024 * 1024);
		await expect(process_photo(file, { maxBytes: 10 * 1024 * 1024 })).rejects.toMatchObject({
			code: 'too_large',
		});
	});

	it('error is an instance of PhotoError', async () => {
		const file = make_file('application/pdf', 100);
		await expect(process_photo(file)).rejects.toBeInstanceOf(PhotoError);
	});
});
