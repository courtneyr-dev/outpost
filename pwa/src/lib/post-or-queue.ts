/**
 * Post an h-entry now, or keep it in the offline queue when the network is down.
 *
 * Every composer mode submits through here, so the offline rules live in
 * one place instead of six copies:
 *
 *   - The Micropub endpoint comes from the mode's session state, then from
 *     discovery. When discovery fails because the network is down, the post
 *     is queued with the endpoint the last successful discovery stored
 *     (endpoint-cache.ts), or with none on a device that never discovered
 *     one; the replay then discovers it from `me`.
 *   - Photos upload before the post. A network failure during discovery,
 *     upload, or the post itself queues the processed image bytes together
 *     with the URLs of any photos that already uploaded, so none uploads twice.
 *   - Anything the server answers with an error (4xx, 5xx, no endpoint in
 *     the HTML) is thrown for the mode to show, as before.
 */

import {
	discover_media_endpoint,
	discover_micropub_endpoint,
	post_h_entry,
	upload_media,
	type HEntryProperties,
	type MicropubEnvironment,
} from './micropub';
import { recall_endpoints, remember_endpoints } from './endpoint-cache';
import {
	enqueue,
	is_network_error,
	type OfflineQueueEnvironment,
	type QueuedMedia,
	type QueueSource,
} from './offline-queue';

export type SubmitStage =
	| { kind: 'discovering' }
	| { kind: 'uploading'; current: number; total: number }
	| { kind: 'posting' };

export interface PendingPhoto {
	blob: Blob;
	filename: string;
}

export interface PostOrQueueInput {
	source: QueueSource;
	me: string;
	accessToken: string;
	/** h-entry properties. With `photos`, the uploaded URLs become `photo` (a bare string for one). */
	properties: HEntryProperties;
	photos?: PendingPhoto[];
	/** Endpoints the mode already resolved this session. */
	micropubEndpoint?: string | null;
	mediaEndpoint?: string | null;
	onStage?: (stage: SubmitStage) => void;
}

export type PostOrQueueResult =
	| { kind: 'posted'; location?: string; micropubEndpoint: string; mediaEndpoint: string | null }
	| {
			kind: 'queued';
			id: number;
			micropubEndpoint: string | null;
			mediaEndpoint: string | null;
	  };

export async function post_or_queue(
	input: PostOrQueueInput,
	micropubEnv?: MicropubEnvironment,
	queueEnv?: OfflineQueueEnvironment,
): Promise<PostOrQueueResult> {
	const known = recall_endpoints(input.me);
	const photos = input.photos ?? [];
	const uploaded: string[] = [];
	let micropub = input.micropubEndpoint ?? null;
	let media = input.mediaEndpoint ?? known.media;

	try {
		if (!micropub) {
			input.onStage?.({ kind: 'discovering' });
			micropub = await discover_micropub_endpoint(input.me, micropubEnv);
			remember_endpoints(input.me, { micropub });
		}

		if (photos.length > 0) {
			if (!media) {
				input.onStage?.({ kind: 'discovering' });
				media = await discover_media_endpoint(micropub, input.accessToken, micropubEnv);
				remember_endpoints(input.me, { media });
			}
			for (let i = 0; i < photos.length; i++) {
				input.onStage?.({ kind: 'uploading', current: i + 1, total: photos.length });
				const photo = photos[i]!;
				const upload = await upload_media(
					{
						blob: photo.blob,
						filename: photo.filename,
						accessToken: input.accessToken,
						mediaEndpoint: media,
					},
					micropubEnv,
				);
				uploaded.push(upload.location);
			}
		}

		input.onStage?.({ kind: 'posting' });
		const properties: HEntryProperties =
			photos.length > 0
				? { ...input.properties, photo: uploaded.length === 1 ? uploaded[0]! : uploaded }
				: input.properties;
		const result = await post_h_entry(
			{ properties, accessToken: input.accessToken, micropubEndpoint: micropub },
			micropubEnv,
		);
		return {
			kind: 'posted',
			...(result.location ? { location: result.location } : {}),
			micropubEndpoint: micropub,
			mediaEndpoint: media,
		};
	} catch (err) {
		if (!is_network_error(err)) throw err;
		const endpoint = micropub ?? known.micropub;
		try {
			const queued_media: QueuedMedia[] = [];
			for (let i = 0; i < photos.length; i++) {
				const photo = photos[i]!;
				const type = photo.blob.type || 'image/jpeg';
				const url = uploaded[i];
				queued_media.push(
					url !== undefined
						? { filename: photo.filename, type, url }
						: { filename: photo.filename, type, bytes: await photo.blob.arrayBuffer() },
				);
			}
			const id = await enqueue(
				{
					source: input.source,
					properties: input.properties,
					micropubEndpoint: endpoint,
					me: input.me,
					...(photos.length > 0 ? { media: queued_media, mediaEndpoint: media } : {}),
				},
				queueEnv,
			);
			return { kind: 'queued', id, micropubEndpoint: endpoint, mediaEndpoint: media };
		} catch {
			// The queue itself failed (storage blocked or full): show the
			// network error that sent the post here.
			throw err;
		}
	}
}
