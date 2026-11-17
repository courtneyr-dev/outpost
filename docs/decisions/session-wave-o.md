## Decision — Wave O composer controls and submit feedback

Recorded 2026-11-13 for Outpost 1.0.27. This amends the Session C0 tab decision.

### Composer modes use native buttons

Courtney asked to replace the cramped scrolling tab strip with seven buttons in two rows.
Each control is a native button in the normal Tab order. The selected mode carries
`aria-current="true"`; the other buttons omit the attribute. All seven panels stay mounted
and toggle with `hidden`, so switching modes keeps form state.

Rejected: `aria-pressed`, because that describes independent toggles. Rejected: a
radiogroup, because it keeps the composite arrow-key model Courtney asked to retire.

### A closed drawer hides itself

The dialog and scrim carry native `hidden` when closed, plus the existing `data-open` and
`inert` states. CSS overrides `hidden` when it loads so the slide animation remains. If a
page optimizer blocks the stylesheet, the browser still removes the closed drawer from view.

### Submit feedback stays beside Publish

Every composing mode renders its persistent submit alert and posted or queued status after
More options and immediately before Publish. A new non-empty error calls
`scrollIntoView({ block: 'nearest' })`; repeated renders of the same message don't scroll.
