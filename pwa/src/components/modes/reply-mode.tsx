/**
 * Reply mode — placeholder for Phase C1.
 *
 * Will handle Reply / Like / Repost / Bookmark / RSVP / Follow shapes per
 * the IndieWeb post-kinds vocabulary. The form takes a target URL (the
 * post being replied to) and runs B2's mf2 preview to pre-fill the
 * citation context. Submission posts an h-entry with `in-reply-to`,
 * `like-of`, `repost-of`, `bookmark-of`, etc. depending on the chosen
 * kind.
 *
 * Phase C1 lands the Reply variant first (most-used kind); the rest
 * follow as a sub-mode picker once the foundation is in place. Phase
 * C1 also requires B2 (server-side mf2 preview) as a hard prerequisite.
 */

export function ReplyMode() {
	return (
		<section class="outpost-card" aria-labelledby="outpost-reply-mode-title">
			<h2 id="outpost-reply-mode-title" class="outpost-card__title">
				Reply
			</h2>
			<p class="outpost-card__lede">
				Reply, Like, Repost, Bookmark, RSVP, and Follow modes land in Phase C1. Hard prereq: B2
				(server-side mf2 preview endpoint) for citation context.
			</p>
		</section>
	);
}
