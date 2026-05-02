import { useEffect, useState } from 'preact/hooks';
import {
	list as list_queue,
	flush as flush_queue,
	remove as remove_from_queue,
	type QueueEntry,
	type OfflineQueueEnvironment,
} from '../lib/offline-queue';
import type { MicropubEnvironment } from '../lib/micropub';
import { Drawer } from './drawer';

/**
 * Queue badge — small affordance in the composer header showing
 * the number of queued offline posts.
 *
 * Phase DS-3b. Replaces the full-width QueueBanner. The badge is
 * always present in the DOM but visually hidden when the queue is
 * empty (preserves layout, avoids reflow on auto-flush). Tapping it
 * opens a Drawer with the existing per-entry list (retry-now,
 * per-entry dismiss).
 *
 * Auto-flush triggers identical to the prior banner: the browser's
 * `online` event AND first mount.
 *
 * Per Design System Section 5.26: aria-label announces the count;
 * `aria-live="polite"` on the badge text so screen readers narrate
 * count changes.
 */

export interface QueueBadgeProps {
	micropubEnv?: MicropubEnvironment;
	queueEnv?: OfflineQueueEnvironment;
}

export function QueueBadge({ micropubEnv, queueEnv }: QueueBadgeProps) {
	const [entries, setEntries] = useState<QueueEntry[]>([]);
	const [busy, setBusy] = useState(false);
	const [open, setOpen] = useState(false);

	const refresh = async (): Promise<void> => {
		try {
			const next = await list_queue(queueEnv);
			setEntries(next);
		} catch (_err) {
			// Queue read failures are non-fatal — badge stays hidden.
		}
	};

	const try_flush = async (): Promise<void> => {
		if (busy) return;
		setBusy(true);
		try {
			await flush_queue(micropubEnv, queueEnv);
		} catch (_err) {
			// flush() handles per-entry errors internally.
		}
		await refresh();
		setBusy(false);
	};

	const dismiss = async (id: number): Promise<void> => {
		try {
			await remove_from_queue(id, queueEnv);
		} catch (_err) {
			// no-op — refresh shows current persisted state.
		}
		await refresh();
	};

	useEffect(() => {
		void refresh();
		const on_online = (): void => {
			void try_flush();
		};
		window.addEventListener('online', on_online);
		return (): void => {
			window.removeEventListener('online', on_online);
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps -- intentional one-time setup
	}, []);

	const count = entries.length;
	if (count === 0) {
		return null;
	}

	const aria_label =
		count === 1
			? '1 queued post. Tap to inspect.'
			: `${String(count)} queued posts. Tap to inspect.`;

	return (
		<>
			<button
				type="button"
				class="outpost-queue-badge"
				onClick={(): void => setOpen(true)}
				aria-label={aria_label}
			>
				<span class="outpost-queue-badge__icon" aria-hidden="true">
					⚐
				</span>
				<span class="outpost-queue-badge__count" aria-live="polite">
					{count}
				</span>
				<span class="outpost-queue-badge__label">queued</span>
			</button>
			<Drawer
				open={open}
				onClose={(): void => setOpen(false)}
				title={count === 1 ? '1 post saved for later' : `${String(count)} posts saved for later`}
			>
				<div class="outpost-queue-inspector">
					<div class="outpost-queue-inspector__actions">
						<button
							class="outpost-button"
							type="button"
							onClick={(): void => {
								void try_flush();
							}}
							disabled={busy}
						>
							{busy ? 'Retrying…' : 'Retry all now'}
						</button>
					</div>
					<ul class="outpost-queue-inspector__list">
						{entries.map((entry) => (
							<li key={entry.id} class="outpost-queue-inspector__item">
								<div class="outpost-queue-inspector__head">
									<span class="outpost-queue-inspector__source">
										{entry.source}
									</span>
									<button
										class="outpost-button outpost-button--secondary"
										type="button"
										onClick={(): void => {
											void dismiss(entry.id);
										}}
										disabled={busy}
										aria-label={`Dismiss queued ${entry.source} post`}
									>
										Dismiss
									</button>
								</div>
								<p class="outpost-queue-inspector__excerpt">
									{summarize_entry(entry)}
								</p>
								{entry.lastError && (
									<p class="outpost-queue-inspector__error">
										Last try: {entry.lastError}
									</p>
								)}
							</li>
						))}
					</ul>
				</div>
			</Drawer>
		</>
	);
}

function summarize_entry(entry: QueueEntry): string {
	const props = entry.properties as Record<string, unknown>;
	const name = typeof props.name === 'string' ? props.name : null;
	const content = typeof props.content === 'string' ? props.content : null;
	const text = name ?? content ?? '';
	if (text.length === 0) return '(no preview)';
	if (text.length <= 200) return text;
	return text.slice(0, 197) + '…';
}
