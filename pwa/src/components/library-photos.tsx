import { useCallback, useEffect, useState } from 'preact/hooks';
import { is_safe_http_url } from '../lib/url-validation';
import { ExternalLink } from './external-link';
import {
	list_library_photos,
	save_library_photo_draft,
	type AltChoice,
	type LibraryImage,
	type LibraryPhotosEnvironment,
	type SavedLibraryDraft,
} from '../lib/library-photos';

/**
 * Chooser for reusing a Media Library image in a WordPress draft.
 *
 * Rendered inside a Drawer that PhotoMode places outside its publish form,
 * so pressing Enter in any field here can never publish the photo post. The
 * search field has its own form; the other fields have no form at all.
 *
 * Alt text is the author's call. The image's stored alt pre-fills the field,
 * and alt text embedded in the file is offered beside it as a suggestion the
 * author applies with a button, never automatically.
 */

export interface LibraryPhotosProps {
	token: string;
	libraryUrl: string;
	env?: LibraryPhotosEnvironment;
}

/** Only follow links back to this WordPress site. */
function local_url(url: string | null): url is string {
	return !!url && is_safe_http_url(url) && new URL(url).origin === window.location.origin;
}

export function LibraryPhotos({ token, libraryUrl, env }: LibraryPhotosProps): preact.JSX.Element {
	const [images, setImages] = useState<LibraryImage[]>([]);
	const [search, setSearch] = useState('');
	const [selected, setSelected] = useState<LibraryImage | null>(null);
	const [alt, setAlt] = useState('');
	const [decorative, setDecorative] = useState(false);
	const [saved, setSaved] = useState<SavedLibraryDraft | null>(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [loaded, setLoaded] = useState(false);

	const load = useCallback(
		async (query: string): Promise<void> => {
			setBusy(true);
			setError('');
			setSelected(null);
			setSaved(null);
			try {
				setImages(await list_library_photos(token, query, env));
				setLoaded(true);
			} catch {
				setError('WordPress did not return the Media Library images.');
			} finally {
				setBusy(false);
			}
		},
		[token, env],
	);

	useEffect(() => {
		void load('');
	}, [load]);

	const choose = (id: number): void => {
		const choice = images.find((image) => image.id === id) ?? null;
		setSelected(choice);
		setAlt(choice?.alt ?? '');
		setDecorative(false);
		setSaved(null);
		setError('');
	};

	const alt_choice: AltChoice | null = decorative
		? { kind: 'decorative' }
		: alt.trim()
			? { kind: 'text', alt: alt.trim() }
			: null;

	const save = async (): Promise<void> => {
		if (!selected || !alt_choice) return;
		setBusy(true);
		setError('');
		try {
			setSaved(await save_library_photo_draft(token, selected.id, alt_choice, env));
		} catch {
			setError('WordPress did not save the photo draft. The image may be unavailable or still processing.');
		} finally {
			setBusy(false);
		}
	};

	const suggestion = selected?.embeddedAlt ?? '';

	return (
		<div class="outpost-form-row outpost-library-photos" aria-busy={busy}>
			{local_url(libraryUrl) && (
				<p>
					<ExternalLink href={libraryUrl}>Upload or process images in WordPress</ExternalLink>
				</p>
			)}
			<form
				role="search"
				class="outpost-form-row"
				onSubmit={(event): void => {
					event.preventDefault();
					void load(search.trim());
				}}
			>
				<label class="outpost-label" for="outpost-library-search">
					Search images by title
				</label>
				<input
					class="outpost-input"
					id="outpost-library-search"
					type="search"
					value={search}
					onInput={(event): void => setSearch(event.currentTarget.value)}
					disabled={busy}
				/>
				<button class="outpost-button outpost-button--secondary" type="submit" disabled={busy}>
					Search Media Library
				</button>
			</form>

			{images.length > 0 && (
				<>
					<label class="outpost-label" for="outpost-library-image">
						WordPress image
					</label>
					<select
						class="outpost-input"
						id="outpost-library-image"
						value={selected ? String(selected.id) : ''}
						disabled={busy}
						onChange={(event): void => choose(Number(event.currentTarget.value))}
					>
						<option value="">Choose an image</option>
						{images.map((image) => (
							<option key={image.id} value={String(image.id)}>
								{(image.title || 'Untitled image') + ' (' + String(image.id) + ')'}
							</option>
						))}
					</select>
				</>
			)}
			{loaded && images.length === 0 && <p>No matching images are ready to use.</p>}

			{selected && (
				<>
					{local_url(selected.thumbnail) && (
						// The alt field below describes the image; the preview adds nothing for a screen reader.
						<img class="outpost-photo-list__thumb" width="150" src={selected.thumbnail} alt="" />
					)}
					{selected.caption && (
						<dl class="outpost-library-photos__caption">
							<dt>Caption, kept from the Media Library</dt>
							<dd>{selected.caption}</dd>
						</dl>
					)}
					<label class="outpost-label" for="outpost-library-alt">
						Alt text {!decorative && <span class="outpost-required">(required)</span>}
					</label>
					<textarea
						class="outpost-textarea"
						id="outpost-library-alt"
						rows={2}
						value={alt}
						disabled={busy || decorative}
						onInput={(event): void => setAlt(event.currentTarget.value)}
					/>
					{suggestion && suggestion !== alt && !decorative && (
						<div class="outpost-library-photos__suggestion">
							<p class="outpost-help" id="outpost-library-embedded-alt">
								Alt text embedded in the file: {suggestion}
							</p>
							<button
								class="outpost-button outpost-button--secondary"
								type="button"
								aria-describedby="outpost-library-embedded-alt"
								disabled={busy}
								onClick={(): void => setAlt(suggestion)}
							>
								Use embedded alt text
							</button>
						</div>
					)}
					<label class="outpost-checkbox">
						<input
							type="checkbox"
							checked={decorative}
							disabled={busy}
							onChange={(event): void => setDecorative(event.currentTarget.checked)}
						/>
						<span>Decorative (no alt text needed)</span>
					</label>
					{local_url(selected.editUrl) && (
						<p>
							<ExternalLink href={selected.editUrl}>Edit or crop this image in WordPress</ExternalLink>
						</p>
					)}
					<button
						class="outpost-button"
						type="button"
						onClick={(): void => void save()}
						disabled={busy || saved !== null || alt_choice === null}
					>
						Save photo as WordPress draft
					</button>
				</>
			)}

			<p role="alert" class={'outpost-error' + (error ? '' : ' outpost-error--empty')}>
				{error}
			</p>
			<p role="status" class="outpost-status">
				{busy && 'Waiting for WordPress…'}
				{saved && (
					<>
						Photo draft saved.{' '}
						{local_url(saved.editUrl) && <a href={saved.editUrl}>Open photo draft in WordPress</a>}
					</>
				)}
			</p>
		</div>
	);
}
