import { useRef, useState } from 'preact/hooks';
import { list_for_owner, remove_owned_entries, type OfflineQueueEnvironment } from '../lib/offline-queue';

/**
 * "Sign out of this device", with a choice about queued posts (T10-S3-07).
 *
 * When the signed-in account has posts waiting in the offline queue, the
 * person chooses first: keep them on this device (they send after the same
 * account signs in again) or remove them. Removing deletes only this
 * account's posts; posts other accounts or older versions left on the
 * device stay either way. With nothing queued, signing out works as before.
 * If the queue can't be read or changed, the person stays signed in and the
 * status line says so; it never claims a removal that didn't happen.
 */
export interface SignOutControlProps {
	/** Canonical identity of the signed-in account. */
	owner: string | null;
	queueEnv?: OfflineQueueEnvironment;
	/** Clears the sign-in and reloads (lib/sign-out.ts in the app). */
	onSignOut: () => void | Promise<void>;
}

const CHECK_FAILED = 'Couldn’t check this device for queued posts, so you’re still signed in. Try again.';
const REMOVE_FAILED =
	'Couldn’t remove your queued posts, so nothing was removed and you’re still signed in. Try again.';

export function SignOutControl({ owner, queueEnv, onSignOut }: SignOutControlProps) {
	// Number of this account's queued posts once the person has to choose.
	const [queued, setQueued] = useState<number | null>(null);
	const [status, setStatus] = useState('');
	const [busy, setBusy] = useState(false);
	const prompt = useRef<HTMLParagraphElement | null>(null);

	const start = async (): Promise<void> => {
		setStatus('');
		if (owner === null) {
			// No account identity, so no queued post can be this account's.
			await onSignOut();
			return;
		}
		let count: number;
		try {
			count = (await list_for_owner(owner, queueEnv)).own.length;
		} catch (_err) {
			setStatus(CHECK_FAILED);
			return;
		}
		if (count === 0) {
			await onSignOut();
			return;
		}
		setQueued(count);
		requestAnimationFrame(() => prompt.current?.focus());
	};

	const remove_and_sign_out = async (): Promise<void> => {
		setBusy(true);
		setStatus('');
		try {
			await remove_owned_entries(owner, queueEnv);
		} catch (_err) {
			setBusy(false);
			setStatus(REMOVE_FAILED);
			return;
		}
		await onSignOut();
	};

	return (
		<>
			{queued === null ? (
				<div class="outpost-form-actions">
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						onClick={(): void => {
							void start();
						}}
						aria-describedby="outpost-about-signout-help"
					>
						Sign out of this device
					</button>
				</div>
			) : (
				<div role="group" aria-labelledby="outpost-signout-queued">
					<p id="outpost-signout-queued" ref={prompt} tabIndex={-1}>
						{queued === 1
							? 'You have 1 queued post on this device that hasn’t been sent.'
							: `You have ${String(queued)} queued posts on this device that haven’t been sent.`}
					</p>
					<div class="outpost-form-actions">
						<button
							type="button"
							class="outpost-button outpost-button--secondary"
							disabled={busy}
							onClick={(): void => {
								void onSignOut();
							}}
						>
							Keep queued posts on this device
						</button>
						<button
							type="button"
							class="outpost-button outpost-button--secondary"
							disabled={busy}
							onClick={(): void => {
								void remove_and_sign_out();
							}}
						>
							Remove my queued posts and sign out
						</button>
					</div>
				</div>
			)}
			<p class="outpost-status" role="status" aria-live="polite">
				{status}
			</p>
		</>
	);
}
