/**
 * iPhone Safari step-by-step bookmarklet install guide.
 *
 * Renders inline below the BookmarkletList cards because iOS Safari
 * doesn't offer "Add Bookmark" on long-press for `javascript:` URLs.
 * The workaround — bookmark a regular page first, then edit the
 * bookmark's URL field to paste the bookmarklet — has been the only
 * reliable iOS bookmarklet path since iOS 12.
 *
 * Each step has a checkbox the user can tap to mark progress. State is
 * component-local (useState) — no localStorage. The user runs the
 * walkthrough fresh per variant; persistence would imply order
 * across variants, which isn't the actual flow.
 *
 * Design intent: not hidden behind a `<details>` toggle. The user told
 * us "guide the user through doing it" — the steps must be visible,
 * tappable, and obvious next to the bookmarklet they're installing.
 */

import { useState } from 'preact/hooks';

interface Step {
	title: string;
	body: preact.ComponentChildren;
}

const STEPS: Step[] = [
	{
		title: 'Tap Copy source',
		body: (
			<>
				Pick the variant you want above (Reply, Like, Repost, or Bookmark) and
				tap its <strong>Copy source</strong> button. The button briefly says
				&ldquo;Copied!&rdquo; — the bookmarklet code is now on your clipboard.
			</>
		),
	},
	{
		title: 'Bookmark this page',
		body: (
			<>
				Tap the <strong>Share</strong> button at the bottom of Safari (square
				with up-arrow), then <strong>Add Bookmark</strong>. Change the title to
				something memorable like <em>Outpost: Reply</em> so you can find it.
				Tap <strong>Save</strong>.
			</>
		),
	},
	{
		title: 'Open your bookmarks',
		body: (
			<>
				Tap the open-book icon at the bottom of Safari. Find the bookmark you
				just saved (it&apos;ll be at the top of the list). Tap{' '}
				<strong>Edit</strong> in the bottom-right corner of the bookmarks panel.
			</>
		),
	},
	{
		title: 'Replace the Address',
		body: (
			<>
				Tap your saved bookmark to open its detail. Tap the <strong>Address</strong>{' '}
				field, select all the text, then paste from your clipboard. The address
				should now start with <code>javascript:</code> instead of{' '}
				<code>https:</code>.
			</>
		),
	},
	{
		title: 'Save and try it',
		body: (
			<>
				Tap <strong>Done</strong> in the top-right. Navigate to any web page,
				tap the bookmarks icon, and tap your saved bookmark. Outpost opens with
				that page&apos;s URL and title pre-filled.
			</>
		),
	},
];

export function IosBookmarkletGuide(): preact.JSX.Element {
	const [done, setDone] = useState<boolean[]>(() => STEPS.map(() => false));

	const toggle = (index: number): void => {
		setDone((current) => current.map((value, i) => (i === index ? !value : value)));
	};

	const reset = (): void => {
		setDone(STEPS.map(() => false));
	};

	const completed = done.every(Boolean);
	const completed_count = done.filter(Boolean).length;

	return (
		<section
			class="outpost-ios-guide"
			aria-labelledby="outpost-ios-guide-title"
		>
			<h4 id="outpost-ios-guide-title" class="outpost-ios-guide__title">
				iPhone Safari — guided install ({completed_count} of {STEPS.length})
			</h4>
			<p class="outpost-ios-guide__lede">
				iOS Safari refuses to bookmark <code>javascript:</code> URLs directly.
				These five steps work around that by bookmarking a normal page first,
				then editing its address. Tap each step as you finish it.
			</p>
			<ol class="outpost-ios-guide__steps">
				{STEPS.map((step, index) => {
					const checked = done[index] ?? false;
					const id = `outpost-ios-guide-step-${index}`;
					return (
						<li
							class={`outpost-ios-guide__step${checked ? ' outpost-ios-guide__step--done' : ''}`}
						>
							<label class="outpost-ios-guide__step-label" for={id}>
								<input
									id={id}
									type="checkbox"
									class="outpost-ios-guide__check"
									checked={checked}
									onChange={() => toggle(index)}
								/>
								<span class="outpost-ios-guide__step-num">
									Step {index + 1}
								</span>
								<span class="outpost-ios-guide__step-title">{step.title}</span>
							</label>
							<p class="outpost-ios-guide__step-body">{step.body}</p>
						</li>
					);
				})}
			</ol>
			{completed ? (
				<p class="outpost-ios-guide__done" role="status">
					<strong>Done!</strong> The bookmark now runs the bookmarklet on any
					page. Repeat for each variant you want — tap reset and start with a
					different Copy source.{' '}
					<button
						type="button"
						class="outpost-button outpost-button--secondary"
						onClick={reset}
					>
						Reset progress
					</button>
				</p>
			) : (
				<p class="outpost-ios-guide__hint">
					Tip: keep this tab open while you&apos;re doing it. Switch back to
					Safari for each step, then return here to mark it done.
				</p>
			)}
		</section>
	);
}
