import { useEffect, useRef } from 'preact/hooks';

/**
 * Shared media-picker UI for composer modes that accept user-attached images.
 *
 * Extracted from PhotoMode's per-entry picker (file upload + alt-text +
 * decorative toggle + reorder + remove) so Doing variants (Checkin / Eat /
 * Drink / Exercise) and Life variants (Mood / Weather) can attach images
 * with the same alt-text discipline PhotoMode enforces. PhotoMode itself
 * retains its inline picker for now — refactoring it onto this component is
 * a future PR; the goal here is to enable the Doing/Life variants WITHOUT
 * destabilizing the 313 unit tests covering PhotoMode.
 *
 * The component is presentational: it owns the per-entry UI (thumbnail,
 * alt input, decorative checkbox, move/remove buttons) but the parent mode
 * holds the entries state, runs the upload pipeline, and decides what to
 * do with the resulting URLs. This keeps MediaPicker reusable across modes
 * with very different submit payloads (photo[] for Photo, photo[] + video
 * for Doing, gallery[] + ingredient-images[] for Recipe later).
 *
 * Alt-text discipline (Hard Contract): every entry must have alt text OR
 * be marked decorative. The parent decides what "complete" means at submit
 * time; the picker exposes the validity state via the onChange callback
 * (each entry reports its own decorative bit + alt string).
 *
 * Blob URL hygiene: the picker creates blob: URLs for thumbnails on file
 * pick and revokes them when the entry is removed or the component
 * unmounts. The ref-via-effect pattern mirrors PhotoMode's fix for the
 * "~4 MB leaked per picked photo" bug (entries captured at mount due to
 * empty deps).
 */

export interface MediaEntry {
	id: string;
	file: File;
	preview_url: string;
	alt: string;
	decorative: boolean;
}

export interface MediaPickerProps {
	entries: MediaEntry[];
	onChange: (entries: MediaEntry[]) => void;
	/**
	 * Disabled state — usually mirrors the parent's submitting flag so the
	 * picker locks down while the upload pipeline runs.
	 */
	disabled?: boolean;
	/**
	 * Unique prefix for ARIA-tying input ids together. Two pickers on the
	 * same page (Recipe gallery + per-ingredient image) need distinct
	 * prefixes so screen readers track them correctly.
	 */
	idPrefix: string;
	/** Optional label shown above the picker when entries is empty. */
	emptyLabel?: string;
	/** Optional label shown above the picker when one or more entries exist. */
	nonEmptyLabel?: string;
	/**
	 * MIME types accepted by the file input. Defaults to PhotoMode's image
	 * allowlist — explicit so callers wanting (eventually) video MIME types
	 * can extend.
	 */
	accept?: string;
	/**
	 * Allow multiple-file selection. PhotoMode + Doing-variant snapshots default
	 * to true. Per-ingredient images in Recipe set this to false (single file
	 * per row).
	 */
	multiple?: boolean;
}

const DEFAULT_IMAGE_MIME = 'image/jpeg,image/png,image/webp,image/gif,image/avif';

let next_id = 1;
const make_id = (): string => `media-${String(next_id++)}`;

/**
 * Whether every entry in the picker has alt text OR is marked decorative.
 * Helper for parent modes' submit-disabled logic — keeps the validity rule
 * in one place so Doing / Life / Recipe variants agree on the meaning of
 * "complete."
 */
export function all_entries_have_alt(entries: MediaEntry[]): boolean {
	return entries.every((entry) => entry.decorative || entry.alt.trim().length > 0);
}

export function MediaPicker({
	entries,
	onChange,
	disabled = false,
	idPrefix,
	emptyLabel = 'Add photos',
	nonEmptyLabel = 'Add more photos',
	accept = DEFAULT_IMAGE_MIME,
	multiple = true,
}: MediaPickerProps): preact.JSX.Element {
	// Revoke blob URLs on unmount. The previous cleanup captured `entries`
	// at mount (empty array) due to the empty deps array, so unmount
	// cleanup did nothing — ~4 MB of File-backed blob URL leaked per
	// picked photo until the page was unloaded. Tracking entries via a
	// ref lets the cleanup see the current list at unmount time without
	// re-running the effect on every keystroke.
	const entries_ref = useRef(entries);
	entries_ref.current = entries;
	useEffect(() => {
		return (): void => {
			for (const entry of entries_ref.current) {
				URL.revokeObjectURL(entry.preview_url);
			}
		};
	}, []);

	const handle_file_change = (event: Event): void => {
		const input = event.target as HTMLInputElement;
		const picked = input.files;
		if (!picked || picked.length === 0) return;
		const new_entries: MediaEntry[] = [];
		for (const file of Array.from(picked)) {
			new_entries.push({
				id: make_id(),
				file,
				preview_url: URL.createObjectURL(file),
				alt: '',
				decorative: false,
			});
		}
		onChange([...entries, ...new_entries]);
		// Clear the file input so the user can pick the same file again
		// after removing it (browsers cache the previous selection
		// otherwise — picking the same filename does nothing).
		input.value = '';
	};

	const remove_entry = (id: string): void => {
		const target = entries.find((e) => e.id === id);
		if (target) URL.revokeObjectURL(target.preview_url);
		onChange(entries.filter((e) => e.id !== id));
	};

	const update_entry = (id: string, patch: Partial<MediaEntry>): void => {
		onChange(entries.map((entry) => (entry.id === id ? { ...entry, ...patch } : entry)));
	};

	const move_entry = (id: string, direction: -1 | 1): void => {
		const idx = entries.findIndex((e) => e.id === id);
		const target_idx = idx + direction;
		if (idx === -1 || target_idx < 0 || target_idx >= entries.length) return;
		const next = [...entries];
		[next[idx], next[target_idx]] = [next[target_idx]!, next[idx]!];
		onChange(next);
	};

	const file_input_id = `${idPrefix}-file`;

	return (
		<div class="outpost-media-picker">
			<label class="outpost-label" for={file_input_id}>
				{entries.length === 0 ? emptyLabel : nonEmptyLabel}
			</label>
			<input
				id={file_input_id}
				class="outpost-input"
				type="file"
				accept={accept}
				multiple={multiple}
				onChange={handle_file_change}
				disabled={disabled}
			/>

			{entries.length > 0 && (
				<ul class="outpost-photo-list">
					{entries.map((entry, index) => (
						<li key={entry.id} class="outpost-photo-list__item">
							<img
								class="outpost-photo-list__thumb"
								src={entry.preview_url}
								alt={
									entry.alt ||
									`Photo ${String(index + 1)} (alt text not yet entered)`
								}
							/>
							<div class="outpost-photo-list__fields">
								<label
									class="outpost-label"
									for={`${idPrefix}-alt-${entry.id}`}
								>
									Alt text{' '}
									{!entry.decorative && (
										<span class="outpost-required">(required)</span>
									)}
								</label>
								<textarea
									id={`${idPrefix}-alt-${entry.id}`}
									class="outpost-textarea"
									rows={2}
									value={entry.alt}
									onInput={(event): void =>
										update_entry(entry.id, {
											alt: (event.target as HTMLTextAreaElement).value,
										})
									}
									placeholder="Describe what's in the photo for screen readers"
									disabled={disabled || entry.decorative}
								/>
								<label class="outpost-checkbox">
									<input
										type="checkbox"
										checked={entry.decorative}
										onChange={(event): void =>
											update_entry(entry.id, {
												decorative: (event.target as HTMLInputElement)
													.checked,
											})
										}
										disabled={disabled}
									/>
									<span>Decorative (no alt text needed)</span>
								</label>
							</div>
							<div class="outpost-photo-list__actions">
								{entries.length > 1 && (
									<>
										<button
											type="button"
											class="outpost-button outpost-button--secondary"
											onClick={(): void => move_entry(entry.id, -1)}
											disabled={disabled || index === 0}
											aria-label={`Move photo ${String(index + 1)} earlier`}
										>
											<span aria-hidden="true">↑</span>
										</button>
										<button
											type="button"
											class="outpost-button outpost-button--secondary"
											onClick={(): void => move_entry(entry.id, 1)}
											disabled={disabled || index === entries.length - 1}
											aria-label={`Move photo ${String(index + 1)} later`}
										>
											<span aria-hidden="true">↓</span>
										</button>
									</>
								)}
								<button
									type="button"
									class="outpost-button outpost-button--secondary"
									onClick={(): void => remove_entry(entry.id)}
									disabled={disabled}
									aria-label={`Remove photo ${String(index + 1)}`}
								>
									Remove
								</button>
							</div>
						</li>
					))}
				</ul>
			)}
		</div>
	);
}
