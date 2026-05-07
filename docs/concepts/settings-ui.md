# Settings UI tabs (G3.5d)

Multi-tab settings page foundation. Other phases (scripture API keys in G10b, self-hosted Pretalx URL in G13b, Sessionize per-event URLs in G13c, future per-platform credential UI) register their fields into existing tabs without editing core.

## Components

- **`Outpost_Settings_Registry`** — declarative tab + field registry. Tabs register via `outpost_settings_tabs` filter; per-tab fields register via `outpost_settings_fields_{tab_id}` filters. Defensive against malformed callers (drops entries missing required keys).
- **`Outpost_Settings_Fields`** — five field-type renderers + sanitizers: text, password, url, checkbox, select. Sensitive fields encrypt via `Outpost_Encryption` from G3.5a.
- **`Outpost_Settings_Handler`** — admin-post.php save handler. Verifies per-tab nonce + capability, sanitizes per type, encrypts sensitive fields, persists to `outpost_settings_{tab_id}` option.
- **`Outpost_Settings_Page`** — top-level "Outpost" admin menu page. Renders tab navigation + active tab body. Surfaces "Settings saved" notice after successful redirects.
- **`Outpost_Settings_Tab_Api_Keys`** — demonstration tab. Renders empty by default; concrete platforms add fields via the per-tab filter.

## Why server-rendered, not React

Matches F-phase + the disconnect-button-fix pattern from G99. The Settings page is admin-only, low-frequency, low-interactivity UI. A React surface here costs more in tooling (separate `@wordpress/scripts` build target, hydration, REST endpoints) than it saves. Server-rendered `<form>` elements with admin-post.php, nonce-protected, are the right tool.

## Why separate from the OAuth Connections page

The G3.5a OAuth Connections page (`outpost-oauth`) stays where it is for v1. Migrating it into this tab system is a separate follow-up — refactoring shipped UI inside a foundation PR adds risk without a corresponding feature. When the migration lands, OAuth Connections becomes the default tab and this PR's "API Keys" tab moves second.

## Storage shape

Each tab persists to its own option:

```
outpost_settings_api_keys = [
    'api_bible_key'  => [ 'encrypted' => '<base64>' ],   // sensitive
    'enable_caching' => true,                             // checkbox, plaintext
    'cache_ttl_url'  => 'https://example.com/cache',     // url, plaintext
]
```

Sensitive fields are stored as `[ 'encrypted' => '<base64>' ]` rather than as plain strings. The wrapper distinguishes encrypted data from plaintext on the same key — matters for migrations where a previously-non-sensitive field becomes sensitive (or vice versa).

## Adding a new tab

```php
add_filter( 'outpost_settings_tabs', function( $tabs ) {
    $tabs['instances'] = [
        'label'      => __( 'Self-hosted instances', 'outpost' ),
        'callback'   => [ My_Plugin_Instances_Tab::class, 'render' ],
        'capability' => 'manage_options',
    ];
    return $tabs;
} );
```

The callback receives the tab id as its single argument and is responsible for rendering the tab body. The standard pattern is to read fields via the registry and delegate to `Outpost_Settings_Page::render_tab_form()`:

```php
public static function render( string $tab_id ): void {
    $intro  = __( 'Configure self-hosted instance URLs.', 'outpost' );
    $fields = Outpost_Settings_Registry::get_fields( $tab_id );
    Outpost_Settings_Page::render_tab_form( $tab_id, $fields, $intro );
}
```

## Adding a field to a tab

```php
add_filter( 'outpost_settings_fields_api_keys', function( $fields ) {
    $fields['api_bible_key'] = [
        'label'       => __( 'API.Bible API key', 'outpost' ),
        'type'        => 'password',
        'sensitive'   => true,
        'description' => __( 'Get a free key at scripture.api.bible.', 'outpost' ),
        'default'     => '',
    ];
    return $fields;
} );
```

Supported field types in v1: `text`, `password`, `url`, `checkbox`, `select`. Textarea, file, and complex types deferred until a registered platform needs them.

For `select` fields, also pass `options`:

```php
$fields['edition'] = [
    'label'   => __( 'Edition', 'outpost' ),
    'type'    => 'select',
    'options' => [
        'esv' => __( 'ESV', 'outpost' ),
        'web' => __( 'WEB', 'outpost' ),
        'kjv' => __( 'KJV', 'outpost' ),
    ],
    'default' => 'web',
];
```

## Reading values

```php
$values = Outpost_Settings_Handler::read_tab( 'api_keys' );
$key    = $values['api_bible_key']; // already decrypted if sensitive
```

The handler decrypts sensitive fields transparently. Code that consumes settings doesn't need to know which fields are encrypted.

## Security model

- **Nonces.** Every save POST carries `outpost_settings_save_{tab_id}` nonce. `Outpost_Settings_Handler::handle_save()` verifies before any write.
- **Capability.** Tab-level capability check (default `manage_options`); enforced both on render and on save. A user without the capability can't see the tab nav entry, can't view the body, can't save.
- **Encryption-at-rest.** `Outpost_Encryption` (G3.5a) wraps sensitive values with AES-GCM via the resolved encryption key. If the key isn't configured (no constant + no stashed option), the page renders an admin notice and disables form submission rather than silently dropping sensitive data.
- **No SSRF.** The settings UI never fetches URLs entered by users — `url`-type fields are stored, not dereferenced. Concrete consumers that DO fetch (preview endpoint, source extractors) carry their own SSRF defenses.

## Deferred OAuth migration

Outpost has TWO admin settings surfaces today: the G3.5a OAuth Connections page (`outpost-oauth`) and this G3.5d multi-tab page (`outpost-settings`). The migration plan is:

1. Future PR adds an "OAuth Connections" tab to `outpost-settings`.
2. Migrates the per-provider connect/disconnect buttons from `outpost-oauth`'s render method into the new tab's render callback.
3. Redirects `outpost-oauth` requests to `outpost-settings?tab=oauth_connections`.
4. After one minor version with the redirect, removes `outpost-oauth` entirely.

The migration is mechanical but unrelated to G3.5d's foundation. Keeping it out of this PR keeps the diff small and the two surfaces independent.
