/**
 * Bombadil exploration of the composer PWA at /post/.
 *
 * Bombadil clicks, types, scrolls, reloads, and goes back and forward at
 * random, and checks the properties below in every state it reaches, on top
 * of its defaults (no console errors, uncaught exceptions, unhandled
 * rejections, or HTTP 4xx/5xx responses).
 *
 * Sign-in: `.auth/session.json` (git ignored) holds a disposable editor's
 * IndieAuth token, minted the way tests/e2e/global-setup.ts does. The
 * `signIn` custom action stores it the way pwa/src/lib/token-store.ts does
 * and is offered whenever the login screen shows, so a random sign-out
 * doesn't end the run. The token stays out of the action's arguments, so it
 * never reaches the trace.
 *
 * Run (from tests/bombadil, against a local site with Outpost active):
 *   bombadil browser test --headless --time-limit 3m --output-path output \
 *     --output-path-overwrite --allow-url 'http://localhost:8883/post/*' \
 *     http://localhost:8883/post/ spec.ts
 * Bombadil exits 2 on a property violation and prints the matching
 * `--reproduce output` command.
 */
import { extract, always, now, eventually, actions } from "@antithesishq/bombadil";
import { registerCustomAction } from "@antithesishq/bombadil/browser";
export * from "@antithesishq/bombadil/browser/defaults";
import session from "./.auth/session.json" with { type: "json" };

const DRAFTS_KEY = "outpost:drafts";

// ---- State --------------------------------------------------------------

/** Changes on every page load, so a property can tell a reload from an edit. */
const pageId = extract((s) => s.window.performance.timeOrigin);

const signedOut = extract((s) => s.document.getElementById("outpost-login-title") !== null);

/** The note body's text, or null when the field isn't on the page. */
const noteContent = extract((s) => {
	const el = s.document.getElementById("outpost-note-content") as HTMLTextAreaElement | null;
	return el ? el.value : null;
});

/** The note body as the draft store holds it, or null when none is saved. */
const storedNote = extract((s) => {
	try {
		const raw = JSON.parse(s.window.localStorage.getItem(DRAFTS_KEY) ?? "null");
		const content = raw?.modes?.note?.fields?.content;
		return typeof content === "string" ? content : null;
	} catch {
		return null;
	}
});

const openDrawers = extract(
	(s) => s.document.querySelectorAll('.outpost-drawer[data-open="true"]').length,
);

const bodyOverflow = extract((s) => s.document.body.style.overflow);

/** The queue badge's visible count and the count its accessible name announces. */
const badge = extract((s) => {
	const button = s.document.querySelector(".outpost-queue-badge");
	if (!button) return null;
	const shown = Number(button.querySelector(".outpost-queue-badge__count")?.textContent ?? "NaN");
	const announced = Number((button.getAttribute("aria-label") ?? "").match(/^\d+/)?.[0] ?? "NaN");
	return { shown, announced };
});

// ---- Properties ---------------------------------------------------------

/**
 * Text typed into the note body reaches the draft store within 5 seconds,
 * unless the person edits it on the same page load first or signs out
 * (which clears drafts on purpose). A reload before the save counts as lost.
 */
export const noteDraftIsSaved = always(() => {
	const typed = noteContent.current;
	const page = pageId.current;
	return now(() => typed !== null && typed.trim() !== "" && !signedOut.current).implies(
		eventually(
			() =>
				storedNote.current === typed ||
				(pageId.current === page && noteContent.current !== typed) ||
				signedOut.current,
		).within(5, "seconds"),
	);
});

/** With every drawer closed, the page behind them scrolls again. */
export const scrollLockReleased = always(
	() => openDrawers.current > 0 || bodyOverflow.current !== "hidden",
);

/** Drawers are modal: never more than one open at a time. */
export const oneDrawerAtATime = always(() => openDrawers.current <= 1);

/** The queue badge announces the same count it shows. */
export const badgeCountMatchesLabel = always(
	() => badge.current === null || badge.current.shown === badge.current.announced,
);

// ---- Actions ------------------------------------------------------------

const signInHandler = registerCustomAction("signIn", async (document, window) => {
	const { token, me } = session as { token: string; me: string };
	const db = await new Promise<IDBDatabase>((res, rej) => {
		const r = window.indexedDB.open("outpost", 1);
		r.onupgradeneeded = (): void => {
			if (!r.result.objectStoreNames.contains("crypto-keys")) r.result.createObjectStore("crypto-keys");
			if (!r.result.objectStoreNames.contains("tokens")) r.result.createObjectStore("tokens");
		};
		r.onsuccess = (): void => res(r.result);
		r.onerror = (): void => rej(r.error);
	});
	const put = (store: string, key: string, value: unknown): Promise<void> =>
		new Promise((res, rej) => {
			const tx = db.transaction(store, "readwrite");
			tx.objectStore(store).put(value, key);
			tx.oncomplete = (): void => res();
			tx.onerror = (): void => rej(tx.error);
		});
	const subtle = window.crypto.subtle;
	const key = await subtle.generateKey({ name: "AES-GCM", length: 256 }, false, ["encrypt", "decrypt"]);
	await put("crypto-keys", "token-encryption-key", key);
	const iv = window.crypto.getRandomValues(new Uint8Array(12));
	const ciphertext = new Uint8Array(
		await subtle.encrypt({ name: "AES-GCM", iv }, key, new TextEncoder().encode(token)),
	);
	await put("tokens", "micropub", {
		iv,
		ciphertext,
		meta: { tokenType: "Bearer", scope: "create update media", me, storedAt: Date.now() },
	});
	db.close();
	await window.navigator.serviceWorker.register("/post/sw", { scope: "/post/" });
	await window.navigator.serviceWorker.ready;
	document.location.reload();
});

/** Offered only while the login screen shows. */
export const signIn = actions(() => (signedOut.current ? [signInHandler()] : []));
