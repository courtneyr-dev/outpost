import { useEffect, useState } from 'preact/hooks';
import {
	list as list_queue,
	flush as flush_queue,
	remove as remove_from_queue,
	type QueueEntry,
	type OfflineQueueEnvironment,
} from '../lib/offline-queue';
import type { MicropubEnvironment } from '../lib/micropub';

/**
 * Queue banner — surfaces queued offline posts above the composer.
 *
 * Phase D1. Shows a small banner above the tab strip when there are
 * posts that failed to send because the network was down. Each entry
 * has a retry-now and dismiss button; the whole queue can be retried
 * with one tap.
 *
 * Auto-flush triggers:
 *   - The browser's `online` event fires (network came back).
 *   - The component mounts (in case the user came back to the page
 *     while the queue had stale entries).
 *
 * The banner is dismissed automatically when the queue drains. It
 * doesn't render at all when the queue is empty, so it's invisible
 * during normal use.
 */

export interface QueueBannerProps {
	micropubEnv?: MicropubEnvironment;
	queueEnv?: OfflineQueueEnvironment;
	/**
	 * Called whenever the queue contents change so parents (test harness,
	 * future settings UI) can surface the count if they want. Optional.
	 */
	onQueueChange?: (entries: QueueEntry[]) => void;
}

export function QueueBanner({ micropubEnv, queueEnv, onQueueChange }: QueueBannerProps) {
	const [entries, setEntries] = useState<QueueEntry[]>([]);
	const [busy, setBusy] = useState(false);

	const refresh = async (): Promise<void> => {
		try {
			const next = await list_queue(queueEnv);
			setEntries(next);
			if (onQueueChange) onQueueChange(next);
		} catch (_err) {
			// Queue read failures are non-fatal — banner just stays hidden.
		}
	};

	const try_flush = async (): Promise<void> => {
		if (busy) return;
		setBusy(true);
		try {
			await flush_queue(micropubEnv, queueEnv);
		} catch (_err) {
			// flush() handles per-entry errors internally; if the whole call
			// throws, the queue stays where it was.
		}
		await refresh();
		setBusy(false);
	};

	const dismiss = async (id: number): Promise<void> => {
		try {
			await remove_from_queue(id, queueEnv);
		} catch (_err) {
			// no-op — refresh() will show whatever is currently persisted.
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
		// eslint-disable-next-line react-hooks/exhaustive-deps -- refresh + try_flush close over current props
	}, []);

	if (entries.length === 0) {
		return null;
	}

	return (
		<aside class="outpost-queue-banner" aria-label="Offline post queue">
			<div class="outpost-queue-banner__header">
				<strong>
					{entries.length === 1
						? '1 post saved for later'
						: `${String(entries.length)} posts saved for later`}
				</strong>
				<button
					class="outpost-button outpost-button--secondary"
					type="button"
					onClick={(): void => {
						void try_flush();
					}}
					disabled={busy}
				>
					{busy ? 'Retrying…' : 'Retry now'}
				</button>
			</div>
			<ul class="outpost-queue-banner__list">
				{entries.map((entry) => (
					<li key={entry.id} class="outpost-queue-banner__item">
						<span class="outpost-queue-banner__source">{entry.source}</span>
						<span class="outpost-queue-banner__excerpt">
							{summarize_entry(entry)}
						</span>
						{entry.lastError && (
							<span class="outpost-queue-banner__error" title={entry.lastError}>
								Last try: {entry.lastError}
							</span>
						)}
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
					</li>
				))}
			</ul>
		</aside>
	);
}

function summarize_entry(entry: QueueEntry): string {
	const props = entry.properties as Record<string, unknown>;
	const name = typeof props.name === 'string' ? props.name : null;
	const content = typeof props.content === 'string' ? props.content : null;
	const text = name ?? content ?? '';
	if (text.length === 0) return '(no preview)';
	if (text.length <= 80) return text;
	return text.slice(0, 77) + '…';
}
