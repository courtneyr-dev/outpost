/**
 * iOS navigator.share files strategy.
 *
 * iOS Safari 16.4+ supports `navigator.share({ files })` ONLY in
 * installed PWAs. The strategy:
 *
 *   1. Verify env.navigator_share exists (PWA-mode-gated by caller).
 *   2. Fetch each file URL into a Blob, construct File objects.
 *   3. Optionally check navigator.canShare({ files }) before invoking
 *      navigator.share — Safari may reject some MIME types.
 *   4. Invoke navigator.share. On Promise resolution: 'fired'. On
 *      AbortError rejection: 'aborted' (user cancelled). On any
 *      other rejection: 'rejected' (try next strategy).
 *
 * iOS-specific subtleties:
 *
 *   - iOS Safari sometimes resolves the navigator.share Promise even
 *     when the user cancels — it lacks a reliable "did the user
 *     actually share?" signal. The 'fired' outcome therefore means
 *     "the share sheet was presented and dismissed without error",
 *     not "the user definitely shared." F12 silo URL capture is the
 *     real confirmation; F11 just records that the share UI was
 *     presented.
 */

import type { IosStrategyFn } from './types';

export const try_navigator_share_files: IosStrategyFn = async ( payload, env ) => {
	if ( ! env.navigator_share ) {
		return 'rejected';
	}

	if ( payload.files.length === 0 ) {
		// No files to share via Web Share — defer to next strategy.
		return 'rejected';
	}

	if ( ! env.fetch ) {
		return 'rejected';
	}

	let files: File[];
	try {
		files = await fetch_files_as_blobs( payload.files, env.fetch );
	} catch {
		return 'rejected';
	}

	const share_data: ShareData = {
		title: payload.caption,
		text:  payload.caption,
		files,
	};

	if ( env.navigator_can_share && ! env.navigator_can_share( share_data ) ) {
		return 'rejected';
	}

	try {
		await env.navigator_share( share_data );
		return 'fired';
	} catch ( err ) {
		const name = err instanceof Error ? err.name : '';
		if ( name === 'AbortError' ) {
			// iOS reports user cancellation as AbortError. Do not fall
			// through to the next strategy — the user explicitly stopped.
			return 'aborted';
		}
		return 'rejected';
	}
};

async function fetch_files_as_blobs(
	descriptors: ReadonlyArray<{ url: string; mime: string }>,
	fetcher: typeof fetch,
): Promise<File[]> {
	const promises = descriptors.map( async ( file ) => {
		const response = await fetcher( file.url );
		if ( ! response.ok ) {
			throw new Error( `Fetch ${ file.url } returned ${ response.status }` );
		}
		const blob = await response.blob();
		const name = filename_from_url( file.url );
		return new File( [ blob ], name, { type: file.mime } );
	} );
	return Promise.all( promises );
}

function filename_from_url( url: string ): string {
	try {
		const parsed = new URL( url );
		const tail   = parsed.pathname.split( '/' ).pop() ?? '';
		return tail !== '' ? tail : 'shared-file';
	} catch {
		return 'shared-file';
	}
}
