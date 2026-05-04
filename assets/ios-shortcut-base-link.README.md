# iOS Shortcut Base Link

`ios-shortcut-base-link.txt` is bundled at plugin distribution time and
read by the iOS Shortcut Bridge settings page. It contains a single
public iCloud Shortcut share URL (`https://www.icloud.com/shortcuts/...`)
that anyone running iOS 13+ can open in Safari to import the base
Shortcut into the iOS Shortcuts app.

## Authoring the Shortcut

The base Shortcut is authored manually in the iOS Shortcuts app, exported
once, and the public link captured. The Shortcut is GENERIC — same for
every Outpost user. Per-user customization happens client-side via the
text-input actions inside the Shortcut after the user imports it:

1. **Site URL**: text input, user pastes their Outpost site origin
   (e.g. `https://example.com`). The Shortcut concatenates this with
   `/wp-json/outpost/v1/shortcut` to compute the POST target.
2. **Bearer token**: text input, user pastes the token from the
   Outpost iOS Shortcut Bridge settings page.
3. **POST**: the Shortcut packages the share-sheet payload (URL or
   text) as JSON, POSTs to the computed endpoint with the Bearer
   token in the `Authorization` header, and reads the response's
   `redirect_url` to open Safari at the composer.

## Why the link is a placeholder

The actual iCloud link is captured AFTER the Shortcut is authored. As
of this commit the Shortcut is not yet published; the placeholder
sentinel string lets the settings page render the field with a
"link coming soon" indicator, and CI gates can verify the file exists
and parses correctly without depending on iCloud availability.

## Replacing the placeholder

When the Shortcut is published:

1. Author the Shortcut in iOS Shortcuts app.
2. Tap `Share` → `Copy iCloud Link`.
3. Replace the contents of `ios-shortcut-base-link.txt` with the URL
   (no trailing newline issues — the settings page trims).
4. Bump `OUTPOST_VERSION` per A2 #16 since the user-observable settings
   page output changes.
