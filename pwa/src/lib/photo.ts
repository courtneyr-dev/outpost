/**
 * Photo processing — EXIF strip + optional downscale via canvas round-trip.
 *
 * Privacy: EXIF metadata on phone-camera photos typically includes GPS
 * coordinates, device serial, and timestamp. Drawing an image onto a
 * canvas and exporting via `toBlob` drops all metadata as a side effect
 * of re-encoding the pixel data — there's no path for EXIF to survive
 * the round-trip.
 *
 * Performance: phone cameras produce 12+ MP images (4032×3024 ≈ 12 MB+
 * unencoded). Even modern phones spend a real moment loading these into
 * an Image element. The default downscale to 2048 on the long edge keeps
 * it manageable while preserving display quality for any post.
 *
 * MIME allowlist: JPEG, PNG, WebP, GIF, AVIF. SVG is excluded explicitly
 * because SVG can carry executable script and our canvas render is not
 * the right sanitizer for it.
 */

const ALLOWED_MIME_PREFIXES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];

export class PhotoError extends Error {
	constructor(
		message: string,
		public readonly code: 'unsupported_type' | 'too_large' | 'load_failed' | 'encode_failed',
	) {
		super(message);
		this.name = 'PhotoError';
	}
}

export interface ProcessOptions {
	/** Max dimension on the long edge (default 2048). */
	maxDimension?: number;
	/** JPEG quality 0–1 (default 0.9). */
	quality?: number;
	/** Hard size cap in bytes (default 10 MB). */
	maxBytes?: number;
}

export interface ProcessedPhoto {
	blob: Blob;
	mimeType: string;
	width: number;
	height: number;
}

/**
 * Validate file type + size, then load → draw → encode through a canvas
 * to strip EXIF and (if needed) downscale.
 */
export async function process_photo(
	file: File,
	options: ProcessOptions = {},
): Promise<ProcessedPhoto> {
	const max_dimension = options.maxDimension ?? 2048;
	const quality = options.quality ?? 0.9;
	const max_bytes = options.maxBytes ?? 10 * 1024 * 1024;

	if (!is_supported_image_type(file.type)) {
		throw new PhotoError(
			'Unsupported image type: ' + (file.type || 'unknown') + '. Allowed: JPEG, PNG, WebP, GIF, AVIF.',
			'unsupported_type',
		);
	}
	if (file.size > max_bytes) {
		throw new PhotoError(
			'Image is too large (' + Math.round(file.size / 1024 / 1024) + ' MB). Max ' + Math.round(max_bytes / 1024 / 1024) + ' MB.',
			'too_large',
		);
	}

	const image = await load_image(file);
	const { width, height } = scale_to_fit(image.naturalWidth, image.naturalHeight, max_dimension);

	const canvas = document.createElement('canvas');
	canvas.width = width;
	canvas.height = height;
	const ctx = canvas.getContext('2d');
	if (!ctx) {
		throw new PhotoError('Browser refused to provide a 2d canvas context.', 'encode_failed');
	}
	ctx.drawImage(image, 0, 0, width, height);

	const blob = await canvas_to_blob(canvas, 'image/jpeg', quality);
	return { blob, mimeType: 'image/jpeg', width, height };
}

export function is_supported_image_type(mime: string): boolean {
	const lower = mime.toLowerCase();
	return ALLOWED_MIME_PREFIXES.some((prefix) => lower === prefix);
}

/**
 * Compute the scaled-down dimensions that fit inside `max` on the long
 * edge while preserving aspect ratio. If the image already fits, return
 * the original dimensions.
 */
export function scale_to_fit(
	width: number,
	height: number,
	max: number,
): { width: number; height: number } {
	if (width <= max && height <= max) {
		return { width, height };
	}
	const ratio = Math.min(max / width, max / height);
	return {
		width: Math.round(width * ratio),
		height: Math.round(height * ratio),
	};
}

function load_image(file: File): Promise<HTMLImageElement> {
	return new Promise((resolve, reject) => {
		const url = URL.createObjectURL(file);
		const img = new Image();
		img.onload = (): void => {
			URL.revokeObjectURL(url);
			resolve(img);
		};
		img.onerror = (): void => {
			URL.revokeObjectURL(url);
			reject(new PhotoError('Could not load image. File may be corrupt.', 'load_failed'));
		};
		img.src = url;
	});
}

function canvas_to_blob(canvas: HTMLCanvasElement, type: string, quality: number): Promise<Blob> {
	return new Promise((resolve, reject) => {
		canvas.toBlob(
			(blob) => {
				if (blob) {
					resolve(blob);
				} else {
					reject(new PhotoError('Browser refused to encode the canvas.', 'encode_failed'));
				}
			},
			type,
			quality,
		);
	});
}
