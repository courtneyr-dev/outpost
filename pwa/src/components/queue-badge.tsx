import { useEffect, useRef, useState } from 'preact/hooks';
import {
	list as list_queue,
	flush as flush_queue,
	remove as remove_from_queue,
	subscribe_queue_changes,
	type QueueEntry,
	type OfflineQueueEnvironment,
} from '../lib/offline-queue';
import type { MicropubEnvironment } from '../lib/micropub';
import { Drawer } from './drawer';

/**
 * Queue badge — small affordance in the composer header showing
 * the number of queued offline posts.
 *
 * Phase DS-3b. Replaces the full-width QueueBanner. The badge renders
 * nothing when the queue is empty. Tapping it opens a Drawer with the
 * per-entry list (retry-now, per-entry dismiss).
 *
 * The count re-reads IndexedDB whenever any tab writes the queue
 * (offline-queue.ts notifies this tab and the others), so a post queued
 * a moment ago shows up without a reload.
 *
 * Replays run on first mount when the browser reports online, on the
 * browser's `online` event, shortly after a post is queued while the
 * browser still reports online (a network blip), and on a bounded backoff
 * while retryable failures remain. The claim in flush() keeps two open
 * composers from publishing the same entry.
 *
 * Per Design System Section 5.26: aria-label announces the count;
 * `aria-live="polite"` on the badge text so screen readers narrate
 * count changes.
 */

/** Waits between automatic replays while retryable failures remain; `online` starts over. */
export const QUEUE_RETRY_DELAYS_MS = [5_000, 15_000, 45_000, 120_000];

/** Wait before replaying a post queued while the browser still reports online. */
export const NEW_ENTRY_REPLAY_DELAY_MS = 3_000;

export interface QueueBadgeProps {
	micropubEnv?: MicropubEnvironment;
	queueEnv?: OfflineQueueEnvironment;
}

function browser_online(): boolean {
	return typeof navigator === 'undefined' || navigator.onLine !== false;
}

export function QueueBadge({ micropubEnv, queueEnv }: QueueBadgeProps) {
	const [entries, setEntries] = useState<QueueEntry[]>([]);
	const [busy, setBusy] = useState(false);
	const [open, setOpen] = useState(false);
	// Refs, not state: the window listeners are attached once and must see
	// the current values.
	const busy_ref = useRef(false);
	// Set when a replay is requested mid-flush (an `online` event, Retry all
	// now); the running flush starts one more pass instead of dropping it.
	const rerun_ref = useRef(false);
	const retry_step = useRef(0);
	const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

	const refresh = async (): Promise<QueueEntry[]> => {
		try {
			const next = await list_queue(queueEnv);
			setEntries(next);
			return next;
		} catch (_err) {
			// Queue read failures are non-fatal — badge stays as it was.
			return [];
		}
	};

	const cancel_timer = (): void => {
		if (timer.current !== null) {
			clearTimeout(timer.current);
			timer.current = null;
		}
	};

	const schedule = (delay: number): void => {
		if (timer.current !== null) return;
		timer.current = setTimeout(() => {
			timer.current = null;
			void try_flush();
		}, delay);
	};

	const try_flush = async (): Promise<void> => {
		if (busy_ref.current) {
			rerun_ref.current = true;
			return;
		}
		busy_ref.current = true;
		setBusy(true);
		cancel_timer();
		let left: QueueEntry[];
		try {
			left = await flush_queue(micropubEnv, queueEnv);
			setEntries(left);
		} catch (_err) {
			// flush() records per-entry failures itself; this is IndexedDB.
			left = await refresh();
		}
		busy_ref.current = false;
		setBusy(false);
		if (rerun_ref.current) {
			rerun_ref.current = false;
			void try_flush();
			return;
		}
		if (left.length === 0) {
			retry_step.current = 0;
			return;
		}
		const delay = QUEUE_RETRY_DELAYS_MS[retry_step.current];
		if (delay !== undefined && browser_online() && left.some((e) => e.retryable !== false)) {
			retry_step.current += 1;
			schedule(delay);
		}
	};

	const on_queue_change = async (): Promise<void> => {
		const next = await refresh();
		const fresh = next.some(
			(e) => e.attempts === 0 && (e.claimedUntil ?? 0) <= Date.now(),
		);
		if (fresh && !busy_ref.current && browser_online()) {
			schedule(NEW_ENTRY_REPLAY_DELAY_MS);
		}
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
		void (async (): Promise<void> => {
			const initial = await refresh();
			if (browser_online() && initial.some((e) => e.retryable !== false)) {
				await try_flush();
			}
		})();
		const on_online = (): void => {
			retry_step.current = 0;
			void try_flush();
		};
		const on_offline = (): void => cancel_timer();
		window.addEventListener('online', on_online);
		window.addEventListener('offline', on_offline);
		const unsubscribe = subscribe_queue_changes(() => {
			void on_queue_change();
		});
		return (): void => {
			window.removeEventListener('online', on_online);
			window.removeEventListener('offline', on_offline);
			unsubscribe();
			cancel_timer();
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
								retry_step.current = 0;
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
	const photos = entry.media?.length ?? 0;
	const text = name ?? content ?? '';
	const suffix = photos === 0 ? '' : photos === 1 ? ' (1 photo)' : ` (${String(photos)} photos)`;
	if (text.length === 0) return photos === 0 ? '(no preview)' : suffix.trim();
	if (text.length <= 200) return text + suffix;
	return text.slice(0, 197) + '…' + suffix;
}
