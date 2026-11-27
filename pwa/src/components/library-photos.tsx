import { useCallback, useEffect, useRef, useState } from 'preact/hooks';
import { is_safe_http_url } from '../lib/url-validation';
import { ExternalLink } from './external-link';
import {
	AUTHORIZATION_HEADER_MISSING,
	LibraryPhotosError,
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
 * Alt text is the author's call. A stored alt someone wrote pre-fills the
 * field. Alt text embedded in the file is offered beside it as a suggestion
 * the author applies with a button, never automatically.
 *
 * Focus stays inside the drawer while WordPress answers (WCAG 2.2 SC 2.4.3,
 * WAI-ARIA modal dialog pattern): buttons that start a request are marked
 * `aria-disabled` rather than `disabled`, and the search field goes
 * read-only, because disabling the focused control drops focus to the page
 * behind the scrim. If the focused control goes away anyway, focus moves to
 * the error, the image list or the chooser itself.
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
	const [next_offset, setNextOffset] = useState<number | null>(null);
	const [query, setQuery] = useState('');
	const root_ref = useRef<HTMLDivElement>(null);
	const draft_link_ref = useRef<HTMLAnchorElement>(null);
	const alt_ref = useRef<HTMLTextAreaElement>(null);
	const error_ref = useRef<HTMLParagraphElement>(null);
	const select_ref = useRef<HTMLSelectElement>(null);

	// Saving disables the focused Save button, which would drop focus to the
	// page. Move it to the link that opens the new draft instead.
	useEffect(() => {
		if (saved) draft_link_ref.current?.focus();
	}, [saved]);

	// After a request settles, keep focus in the drawer if the control that
	// had it went away (a "Load more" button on the last page, for one).
	const was_busy_ref = useRef(false);
	useEffect(() => {
		const settled = was_busy_ref.current && !busy;
		was_busy_ref.current = busy;
		if (!settled) return;
		const root = root_ref.current;
		if (!root || root.contains(document.activeElement)) return;
		const target = (error ? error_ref.current : null) ?? select_ref.current ?? root;
		target.focus();
	}, [busy, error]);

	const load = useCallback(
		async (search_text: string, offset: number): Promise<void> => {
			setBusy(true);
			setError('');
			if (offset === 0) {
				setSelected(null);
				setSaved(null);
			}
			try {
				const page = await list_library_photos(token, search_text, offset, env);
				setImages((current) => {
					if (offset === 0) return page.images;
					const known = new Set(current.map((image) => image.id));
					return current.concat(page.images.filter((image) => !known.has(image.id)));
				});
				setNextOffset(page.nextOffset);
				setQuery(search_text);
				setLoaded(true);
			} catch (caught) {
				setError(failure_message(caught, 'WordPress did not return the Media Library images.'));
			} finally {
				setBusy(false);
			}
		},
		[token, env],
	);

	useEffect(() => {
		void load('', 0);
	}, [load]);

	const choose = (id: number): void => {
		const choice = images.find((image) => image.id === id) ?? null;
		setSelected(choice);
		// WordPress copies embedded alt into the attachment's alt on upload. An
		// alt equal to the embedded one was never written by a person, so it
		// starts as a suggestion to review rather than a pre-filled answer.
		setAlt(choice && choice.alt !== choice.embeddedAlt ? choice.alt : '');
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
		if (busy || saved || !selected || !alt_choice) return;
		setBusy(true);
		setError('');
		try {
			setSaved(await save_library_photo_draft(token, selected.id, alt_choice, env));
		} catch (caught) {
			setError(
				failure_message(
					caught,
					'WordPress did not save the photo draft. The image may be unavailable or still processing.',
				),
			);
		} finally {
			setBusy(false);
		}
	};

	const apply_suggestion = (text: string): void => {
		setAlt(text);
		// The suggestion button disappears once applied; move focus to the
		// field it filled so the author can review or edit the text.
		alt_ref.current?.focus();
	};

	const save_disabled = busy || saved !== null || alt_choice === null;

	const suggestion = selected?.embeddedAlt ?? '';

	return (
		<div class="outpost-form-row outpost-library-photos" aria-busy={busy} ref={root_ref} tabIndex={-1}>
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
					if (!busy) void load(search.trim(), 0);
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
					readOnly={busy}
				/>
				<button class="outpost-button outpost-button--secondary" type="submit" aria-disabled={busy}>
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
						ref={select_ref}
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
			{next_offset !== null && (
				<button
					class="outpost-button outpost-button--secondary"
					type="button"
					aria-disabled={busy}
					onClick={(): void => {
						if (!busy) void load(query, next_offset);
					}}
				>
					Load more images
				</button>
			)}
			{loaded && images.length === 0 && next_offset === null && <p>No matching images are ready to use.</p>}

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
						ref={alt_ref}
						rows={2}
						value={alt}
						readOnly={busy}
						disabled={decorative}
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
								aria-disabled={busy}
								onClick={(): void => {
									if (!busy) apply_suggestion(suggestion);
								}}
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
						aria-disabled={save_disabled}
					>
						Save photo as WordPress draft
					</button>
				</>
			)}

			<p role="alert" ref={error_ref} tabIndex={-1} class={'outpost-error' + (error ? '' : ' outpost-error--empty')}>
				{error}
			</p>
			<p role="status" class="outpost-status">
				{busy && 'Waiting for WordPress…'}
				{saved && (
					<>
						Photo draft saved.{' '}
						{local_url(saved.editUrl) && (
							<a ref={draft_link_ref} href={saved.editUrl}>
								Open photo draft in WordPress
							</a>
						)}
					</>
				)}
			</p>
		</div>
	);
}

/** The message for a failed request, naming the fix when the host dropped the token. */
function failure_message(caught: unknown, fallback: string): string {
	if (caught instanceof LibraryPhotosError && caught.code === AUTHORIZATION_HEADER_MISSING) {
		return 'WordPress did not receive your sign-in: the web server removed the Authorization header. Ask your host to forward it to PHP (on Apache, add "CGIPassAuth On" to .htaccess).';
	}
	return fallback;
}
