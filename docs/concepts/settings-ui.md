# Settings UI tabs (G3.5d)

Multi-tab settings page under **Outpost → Settings** (`admin.php?page=outpost-settings`). Code registers tabs and fields through filters without editing core. Today Outpost registers one tab, **API Keys**, and the four POSSE destinations (Beehiiv, Buttondown, Kit, write.as) are the only code that adds fields to it.

## Components

- **`Outpost_Settings_Registry`** — declarative tab + field registry. Tabs register via `outpost_settings_tabs` filter; per-tab fields register via `outpost_settings_fields_{tab_id}` filters. Defensive against malformed callers (drops entries missing required keys).
- **`Outpost_Settings_Fields`** — five field-type renderers + sanitizers: text, password, url, checkbox, select. Sensitive fields encrypt via `Outpost_Encryption` from G3.5a.
- **`Outpost_Settings_Handler`** — admin-post.php save handler. Verifies per-tab nonce + capability, sanitizes per type, encrypts sensitive fields, persists to `outpost_settings_{tab_id}` option.
- **`Outpost_Settings_Page`** — the **Settings** submenu under the Outpost admin menu (`PARENT_SLUG = 'outpost'`; a top-level menu before 1.0.7). Renders tab navigation + active tab body. Surfaces "Settings saved" notice after successful redirects.
- **`Outpost_Settings_Tab_Api_Keys`** — the API Keys tab. Its fields come from the `outpost_settings_fields_api_keys` filter; the POSSE destinations register theirs there.

## Why server-rendered, not React

Matches F-phase + the disconnect-button-fix pattern from G99. The Settings page is admin-only, low-frequency, low-interactivity UI. A React surface here costs more in tooling (separate `@wordpress/scripts` build target, hydration, REST endpoints) than it saves. Server-rendered `<form>` elements with admin-post.php, nonce-protected, are the right tool.

## Why separate from the OAuth Connections page

The G3.5a OAuth Connections page (`outpost-oauth`, **Outpost → OAuth Connections**) is a separate submenu page, not a tab here. Moving it into this tab system wasn't done in G3.5d because refactoring shipped UI inside a foundation PR adds risk without a corresponding feature.

## Storage shape

Each tab persists to its own option:

```
outpost_settings_api_keys = [
    'beehiiv_enabled'        => true,                          // checkbox, plaintext
    'beehiiv_api_key'        => [ 'encrypted' => '<base64>' ], // sensitive
    'beehiiv_publication_id' => 'pub_123',                     // text, plaintext
]
```

Sensitive fields are stored as `[ 'encrypted' => '<base64>' ]` rather than as plain strings. The wrapper distinguishes encrypted data from plaintext on the same key — matters for migrations where a previously-non-sensitive field becomes sensitive (or vice versa).

## Adding a new tab

```php
add_filter( 'outpost_settings_tabs', function( $tabs ) {
    $tabs['instances'] = [
        'label'      => __( 'Self-hosted instances', 'outpost-mobile-publishing' ),
        'callback'   => [ My_Plugin_Instances_Tab::class, 'render' ],
        'capability' => 'manage_options',
    ];
    return $tabs;
} );
```

The callback receives the tab id as its single argument and is responsible for rendering the tab body. The standard pattern is to read fields via the registry and delegate to `Outpost_Settings_Page::render_tab_form()`:

```php
public static function render( string $tab_id ): void {
    $intro  = __( 'Configure self-hosted instance URLs.', 'outpost-mobile-publishing' );
    $fields = Outpost_Settings_Registry::get_fields( $tab_id );
    Outpost_Settings_Page::render_tab_form( $tab_id, $fields, $intro );
}
```

## Adding a field to a tab

```php
add_filter( 'outpost_settings_fields_api_keys', function( $fields ) {
    $fields['example_api_key'] = [
        'label'       => __( 'Example service API key', 'outpost-mobile-publishing' ),
        'type'        => 'password',
        'sensitive'   => true,
        'description' => __( 'Stored encrypted.', 'outpost-mobile-publishing' ),
        'default'     => '',
    ];
    return $fields;
} );
```

Supported field types: `text`, `password`, `url`, `checkbox`, `select`. Textarea, file, and complex types aren't available.

For `select` fields, also pass `options`:

```php
$fields['example_format'] = [
    'label'   => __( 'Body format', 'outpost-mobile-publishing' ),
    'type'    => 'select',
    'options' => [
        'html'     => __( 'HTML', 'outpost-mobile-publishing' ),
        'markdown' => __( 'Markdown', 'outpost-mobile-publishing' ),
    ],
    'default' => 'html',
];
```

## Reading values

```php
$values = Outpost_Settings_Handler::read_tab( 'api_keys' );
$key    = $values['beehiiv_api_key']; // already decrypted if sensitive
```

The handler decrypts sensitive fields transparently. Code that consumes settings doesn't need to know which fields are encrypted.

## Security model

- **Nonces.** Every save POST carries `outpost_settings_save_{tab_id}` nonce. `Outpost_Settings_Handler::handle_save()` verifies before any write.
- **Capability.** Tab-level capability check (default `manage_options`); enforced both on render and on save. A user without the capability can't see the tab nav entry, can't view the body, can't save.
- **Encryption-at-rest.** `Outpost_Encryption` (G3.5a) wraps sensitive values with `sodium_crypto_secretbox()` (XSalsa20-Poly1305) via the resolved encryption key. If a tab has a sensitive field and no key exists (no `OUTPOST_ENCRYPTION_KEY` constant and no `outpost_encryption_key` option), `render_tab_form()` prints "Outpost encryption key is not configured" and doesn't render the form at all, rather than silently dropping sensitive data. See [Encryption key](encryption-key.md).
- **No SSRF.** The settings UI never fetches URLs entered by users — `url`-type fields are stored, not dereferenced. Concrete consumers that DO fetch (preview endpoint, source extractors) carry their own SSRF defenses.

## Two admin surfaces

Outpost keeps third-party credentials on two admin pages: the G3.5a OAuth Connections page (`outpost-oauth`) and this G3.5d multi-tab page (`outpost-settings`). Both are submenus of the Outpost menu. There's no redirect between them and no OAuth Connections tab on `outpost-settings`.
