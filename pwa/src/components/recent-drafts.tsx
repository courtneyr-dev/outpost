import { useEffect, useRef, useState } from 'preact/hooks';
import { is_safe_http_url } from '../lib/url-validation';

interface DraftSummary {
	id: number;
	title: string;
}

interface Draft extends DraftSummary {
	content: string | null;
	editUrl: string | null;
	version: string;
}

interface DraftPage {
	items: DraftSummary[];
	hasNext: boolean;
}

export interface RecentDraftsEnvironment {
	fetch: typeof fetch;
}

const ENDPOINT_PATH = '/wp-json/outpost/v1/recent-drafts';

const BROWSER_ENV: RecentDraftsEnvironment = {
	fetch: (input, init) => fetch(input, init),
};

async function request_drafts<T>(
	env: RecentDraftsEnvironment,
	token: string,
	fields: Record<string, string>
): Promise<T> {
	const response = await env.fetch(ENDPOINT_PATH, {
		method: 'POST',
		credentials: 'omit',
		redirect: 'error',
		headers: {
			Accept: 'application/json',
			Authorization: 'Bearer ' + token,
		},
		body: new URLSearchParams(fields),
	});
	if (response.status === 409) {
		throw new Error('The draft has changed. Reopen it before saving.');
	}
	if (!response.ok)
		throw new Error(
			'WordPress did not let Outpost load or save this draft.'
		);
	return (await response.json()) as T;
}

export function RecentDrafts({
	token,
	env = BROWSER_ENV,
}: {
	token: string;
	env?: RecentDraftsEnvironment;
}): preact.JSX.Element {
	const [page, setPage] = useState(1);
	const [drafts, setDrafts] = useState<DraftPage | null>(null);
	const [active, setActive] = useState<Draft | null>(null);
	const [busy, setBusy] = useState(false);
	const [error, setError] = useState('');
	const [status, setStatus] = useState('');
	// Where focus goes after the list changes under the user's pointer: the
	// page label after paging or going back (the button pressed may be gone),
	// the title field after opening a draft.
	const [focus_target, setFocusTarget] = useState<'list' | 'draft' | null>(
		null
	);
	const list_label_ref = useRef<HTMLParagraphElement>(null);
	const title_ref = useRef<HTMLInputElement>(null);

	useEffect(() => {
		let current = true;
		setDrafts(null);
		setError('');
		setBusy(true);
		void request_drafts<DraftPage>(env, token, { page: String(page) })
			.then((result) => {
				if (current) setDrafts(result);
			})
			.catch(() => {
				if (current) setError('WordPress did not return your drafts.');
			})
			.finally(() => {
				if (current) setBusy(false);
			});
		return () => {
			current = false;
		};
	}, [page, token, env]);

	useEffect(() => {
		if (busy || !focus_target) return;
		if (focus_target === 'draft') title_ref.current?.focus();
		else list_label_ref.current?.focus();
		setFocusTarget(null);
	}, [busy, focus_target, active, drafts]);

	const go_to_page = (next: number): void => {
		// The pressed button leaves with the old list, so move focus to the
		// page label first; otherwise focus drops to the page while WordPress
		// answers. Busy in the same render keeps the later focus pass waiting
		// for the new list.
		list_label_ref.current?.focus();
		setDrafts(null);
		setBusy(true);
		setFocusTarget('list');
		setPage(next);
	};

	const open = async (id: number): Promise<void> => {
		setBusy(true);
		setError('');
		setStatus('');
		try {
			setActive(
				await request_drafts<Draft>(env, token, { id: String(id) })
			);
			setFocusTarget('draft');
		} catch {
			setError('WordPress did not let Outpost open this draft.');
		} finally {
			setBusy(false);
		}
	};

	const save = async (): Promise<void> => {
		if (!active) return;
		setBusy(true);
		setError('');
		setStatus('');
		try {
			const saved = await request_drafts<Draft>(env, token, {
				id: String(active.id),
				title: active.title,
				version: active.version,
				...(active.content === null ? {} : { content: active.content }),
			});
			setActive(saved);
			setDrafts(
				(previous) =>
					previous && {
						...previous,
						items: previous.items.map((item) =>
							item.id === saved.id
								? { id: saved.id, title: saved.title }
								: item
						),
					}
			);
			setStatus('WordPress draft saved.');
		} catch (failure) {
			setError(
				failure instanceof Error
					? failure.message
					: 'WordPress did not save the draft.'
			);
		} finally {
			setBusy(false);
		}
	};

	const back = (): void => {
		setActive(null);
		setStatus('');
		setError('');
		setFocusTarget('list');
	};

	const edit_url = active?.editUrl;
	const has_editor_link =
		!!edit_url &&
		is_safe_http_url(edit_url) &&
		new URL(edit_url).origin === window.location.origin;

	return (
		<div class="outpost-form-row" aria-busy={busy}>
			{active ? (
				<form
					onSubmit={(event) => {
						event.preventDefault();
						if (!busy) void save();
					}}
				>
					<label class="outpost-label" for="outpost-draft-title">
						Draft title
					</label>
					<input
						ref={title_ref}
						id="outpost-draft-title"
						class="outpost-input"
						value={active.title}
						disabled={busy}
						onInput={(event) =>
							setActive({
								...active,
								title: event.currentTarget.value,
							})
						}
					/>
					{active.content === null ? (
						<p class="outpost-help">
							Edit this draft’s formatted content in WordPress.
						</p>
					) : (
						<>
							<label
								class="outpost-label"
								for="outpost-draft-content"
							>
								Draft content
							</label>
							<textarea
								id="outpost-draft-content"
								class="outpost-textarea"
								value={active.content}
								disabled={busy}
								onInput={(event) =>
									setActive({
										...active,
										content: event.currentTarget.value,
									})
								}
							/>
						</>
					)}
					{has_editor_link && (
						<a href={edit_url}>Open draft in WordPress</a>
					)}
					<div class="outpost-form-actions">
						<button class="outpost-button" type="submit" disabled={busy}>
							Save WordPress draft
						</button>
						<button
							class="outpost-button outpost-button--secondary"
							type="button"
							disabled={busy}
							onClick={() => void open(active.id)}
						>
							Reload draft
						</button>
						<button
							class="outpost-button outpost-button--secondary"
							type="button"
							disabled={busy}
							onClick={back}
						>
							Back to recent drafts
						</button>
					</div>
				</form>
			) : (
				<>
					<p
						id="outpost-recent-drafts-page"
						class="outpost-help"
						tabIndex={-1}
						ref={list_label_ref}
					>
						Your drafts, page {page}
					</p>
					{drafts &&
						(drafts.items.length ? (
							<ul aria-labelledby="outpost-recent-drafts-page">
								{drafts.items.map((draft) => (
									<li key={draft.id}>
										<button
											class="outpost-button outpost-button--secondary"
											type="button"
											disabled={busy}
											onClick={() => void open(draft.id)}
										>
											{draft.title || 'Untitled draft'}
										</button>
									</li>
								))}
							</ul>
						) : (
							<p>No drafts on this page.</p>
						))}
					<div class="outpost-form-actions">
						{page > 1 && (
							<button
								class="outpost-button outpost-button--secondary"
								type="button"
								disabled={busy}
								onClick={() => go_to_page(page - 1)}
							>
								Previous drafts
							</button>
						)}
						{drafts?.hasNext && (
							<button
								class="outpost-button outpost-button--secondary"
								type="button"
								disabled={busy}
								onClick={() => go_to_page(page + 1)}
							>
								Next drafts
							</button>
						)}
					</div>
				</>
			)}
			<p class="outpost-error" role="alert" hidden={!error}>
				{error}
			</p>
			<p class="outpost-status" role="status">
				{busy ? 'Waiting for WordPress…' : status}
			</p>
		</div>
	);
}
