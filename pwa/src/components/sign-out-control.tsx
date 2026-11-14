import { useRef, useState } from 'preact/hooks';
import { list_for_owner, remove_owned_entries, type OfflineQueueEnvironment } from '../lib/offline-queue';

/**
 * "Sign out of this device", with a choice about queued posts (T10-S3-07).
 *
 * When the signed-in account has posts waiting in the offline queue, the
 * person chooses first: keep them on this device (they send after the same
 * account signs in again) or remove them. Removing deletes only this
 * account's posts; posts other accounts or older versions left on the device
 * stay either way. With nothing queued, signing out works as before.
 *
 * Order (v1.1): the sign-in lives in one IndexedDB database and the queue in
 * another, so the two can't change in one transaction. The sign-in is always
 * cleared first and queued posts are removed only after that succeeded, so
 * retained work is never deleted while a credential that could publish it is
 * still on the device. Each step's outcome is reported before the page
 * reloads:
 *
 * - the sign-in can't be cleared → nothing else happens; still signed in;
 * - the posts can't be removed after the sign-in is gone → the person is
 *   told they're signed out but the posts are still here, and can retry the
 *   removal or finish signing out and keep them. No token is needed or
 *   recreated for the retry.
 *
 * The account identity is captured when the choice opens and carried through
 * Remove and every retry, so a rerender with a different `owner` prop can't
 * change which posts an open sign-out removes.
 */
export interface SignOutControlProps {
	/** Canonical identity of the signed-in account. */
	owner: string | null;
	queueEnv?: OfflineQueueEnvironment;
	/** Remove the sign-in (lib/sign-out.ts clear_sign_in); rejects when the token store fails. */
	clearSignIn: () => Promise<void>;
	/** Forget drafts and the appearance copy, then reload (lib/sign-out.ts finish_sign_out). */
	finishSignOut: () => void;
}

const CHECK_FAILED = 'Couldn’t check this device for queued posts, so you’re still signed in. Try again.';
const SIGN_IN_NOT_CLEARED =
	'Couldn’t remove the sign-in from this device, so you’re still signed in and nothing was removed. Try again.';
const PARTIAL =
	'You’re signed out of this device, but your queued posts couldn’t be removed and are still here. Try removing them again, or finish signing out and keep them.';

type Phase =
	| { kind: 'idle' }
	/** `owner` is captured here, so a rerender can't change which posts Remove deletes. */
	| { kind: 'choose'; queued: number; owner: string }
	/** Signed out, but this account's posts are still queued. */
	| { kind: 'partial'; owner: string };

export function SignOutControl({ owner, queueEnv, clearSignIn, finishSignOut }: SignOutControlProps) {
	const [phase, setPhase] = useState<Phase>({ kind: 'idle' });
	const [status, setStatus] = useState('');
	const [busy, setBusy] = useState(false);
	const prompt = useRef<HTMLParagraphElement | null>(null);

	/** Clear the sign-in; on failure say so and resolve false. */
	const cleared = async (): Promise<boolean> => {
		try {
			await clearSignIn();
			return true;
		} catch (_err) {
			setStatus(SIGN_IN_NOT_CLEARED);
			return false;
		}
	};

	const run = async (step: () => Promise<void>): Promise<void> => {
		setBusy(true);
		setStatus('');
		try {
			await step();
		} finally {
			setBusy(false);
		}
	};

	const sign_out_keeping_queue = (): Promise<void> =>
		run(async () => {
			if (await cleared()) finishSignOut();
		});

	const start = (): Promise<void> =>
		run(async () => {
			if (owner === null) {
				// No account identity, so no queued post can be this account's.
				if (await cleared()) finishSignOut();
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
				if (await cleared()) finishSignOut();
				return;
			}
			setPhase({ kind: 'choose', queued: count, owner });
			requestAnimationFrame(() => prompt.current?.focus());
		});

	/** Delete the captured account's posts; the sign-in is already gone. */
	const remove_queued = async (account: string): Promise<void> => {
		try {
			await remove_owned_entries(account, queueEnv);
		} catch (_err) {
			setPhase({ kind: 'partial', owner: account });
			setStatus(PARTIAL);
			return;
		}
		finishSignOut();
	};

	const remove_and_sign_out = (account: string): Promise<void> =>
		run(async () => {
			if (!(await cleared())) return;
			await remove_queued(account);
		});

	return (
		<>
			{phase.kind === 'idle' ? (
				<div class="outpost-form-actions">
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						disabled={busy}
						onClick={(): void => {
							void start();
						}}
						aria-describedby="outpost-about-signout-help"
					>
						Sign out of this device
					</button>
				</div>
			) : phase.kind === 'choose' ? (
				<div role="group" aria-labelledby="outpost-signout-queued">
					<p id="outpost-signout-queued" ref={prompt} tabIndex={-1}>
						{phase.queued === 1
							? 'You have 1 queued post on this device that hasn’t been sent.'
							: `You have ${String(phase.queued)} queued posts on this device that haven’t been sent.`}
					</p>
					<div class="outpost-form-actions">
						<button
							type="button"
							class="outpost-button outpost-button--secondary"
							disabled={busy}
							onClick={(): void => {
								void sign_out_keeping_queue();
							}}
						>
							Keep queued posts on this device
						</button>
						<button
							type="button"
							class="outpost-button outpost-button--secondary"
							disabled={busy}
							onClick={(): void => {
								void remove_and_sign_out(phase.owner);
							}}
						>
							Remove my queued posts and sign out
						</button>
					</div>
				</div>
			) : (
				<div class="outpost-form-actions">
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						disabled={busy}
						onClick={(): void => {
							void run(() => remove_queued(phase.owner));
						}}
					>
						Try removing my queued posts again
					</button>
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						disabled={busy}
						onClick={finishSignOut}
					>
						Finish signing out
					</button>
				</div>
			)}
			<p class="outpost-status" role="status" aria-live="polite">
				{status}
			</p>
		</>
	);
}
