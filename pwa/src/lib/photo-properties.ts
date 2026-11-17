/**
 * Build the Micropub photo properties for a photo post.
 *
 * Outpost sends the parallel-array shape: a `photo` list of uploaded URLs
 * alongside `mp-photo-alt` and `mp-photo-caption` lists paired to it by index.
 * Single-photo posts collapse each list to a bare string, which is the shape
 * Outpost has always sent and which older Micropub servers expect.
 *
 * Alt and caption answer different questions and neither is derived from the
 * other. Alt describes the image for someone who cannot see it, and a
 * decorative photo deliberately sends an empty one. A caption is prose
 * everyone reads, and a decorative photo can still have one.
 */

export interface PhotoPropertyEntry {
	alt: string;
	decorative: boolean;
	caption: string;
}

export interface PhotoProperties {
	photo: string | string[];
	'mp-photo-alt': string | string[];
	'mp-photo-caption'?: string | string[];
}

/** Collapse a single-element list to its one value, matching the photo shape. */
function collapse( values: string[] ): string | string[] {
	return values.length === 1 ? values[0]! : values;
}

export function build_photo_properties(
	uploaded_urls: string[],
	entries: PhotoPropertyEntry[]
): PhotoProperties {
	const alts = entries.map( ( e ) => ( e.decorative ? '' : e.alt.trim() ) );
	const captions = entries.map( ( e ) => e.caption.trim() );

	const properties: PhotoProperties = {
		photo: collapse( uploaded_urls ),
		'mp-photo-alt': collapse( alts ),
	};

	// Omit the property entirely when nothing is captioned, so the common
	// case sends exactly what it sent before this feature existed. When any
	// photo has one, every position is sent — including the empty ones — or
	// the server could not pair captions to photos by index.
	if ( captions.some( ( c ) => c !== '' ) ) {
		properties[ 'mp-photo-caption' ] = collapse( captions );
	}

	return properties;
}
