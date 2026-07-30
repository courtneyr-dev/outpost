<?php
/**
 * Outpost_Settings_Tab_Api_Keys (G3.5d).
 *
 * Demonstration tab for the multi-tab settings UI. Renders an empty
 * form by default — concrete platforms (api.bible, sunnah.com,
 * Quran.com, etc.) register their fields into this tab via the
 * `outpost_settings_fields_api_keys` filter in their own load files.
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Settings_Tab_Api_Keys {

	/**
	 * Render the API Keys tab body. Called from Outpost_Settings_Page::render
	 * when this tab is active.
	 *
	 * @since 0.1.79
	 *
	 * @param string $tab_id Active tab id ('api_keys' for this tab).
	 */
	public static function render( string $tab_id ): void {
		$intro  = __(
			'Some platforms (such as scripture sources or scholarly databases) use API keys instead of OAuth. Add keys here for the platforms you use.',
			'outpost-mobile-publishing'
		);
		$fields = Outpost_Settings_Registry::get_fields( $tab_id );
		Outpost_Settings_Page::render_tab_form( $tab_id, $fields, $intro );
	}
}
