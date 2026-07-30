<?php
/**
 * Outpost_Settings_Registry (G3.5d).
 *
 * Static registry for the multi-tab settings UI. Tabs register
 * declaratively via the `outpost_settings_tabs` filter; per-tab fields
 * register via `outpost_settings_fields_{tab_id}` filters. Concrete
 * platforms add their own tabs and fields without editing core.
 *
 * Tab shape:
 *   [
 *     'label'      => string,
 *     'callback'   => callable,
 *     'capability' => string,
 *   ]
 *
 * Field shape:
 *   [
 *     'label'       => string,
 *     'type'        => 'text' | 'password' | 'url' | 'checkbox' | 'select',
 *     'sensitive'   => bool,
 *     'description' => string,
 *     'default'     => mixed,
 *     'options'     => array<string,string>, // select only
 *   ]
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Settings_Registry {

	public const DEFAULT_TAB = 'api_keys';

	/**
	 * Return all registered tabs, applying the `outpost_settings_tabs`
	 * filter. Defensive: drops malformed entries (missing label or
	 * callback).
	 *
	 * @since 0.1.79
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_tabs(): array {
		$default_tabs = array(
			'api_keys' => array(
				'label'      => __( 'API Keys', 'outpost-mobile-publishing' ),
				'callback'   => array( 'Outpost_Settings_Tab_Api_Keys', 'render' ),
				'capability' => 'manage_options',
			),
		);

		/**
		 * Filter the registered settings tabs. Site owners and concrete
		 * platforms register additional tabs through this filter.
		 *
		 * @since 0.1.79
		 *
		 * @param array<string,array<string,mixed>> $tabs Tab id → tab config.
		 */
		$tabs = apply_filters( 'outpost_settings_tabs', $default_tabs );
		if ( ! is_array( $tabs ) ) {
			return $default_tabs;
		}

		$valid = array();
		foreach ( $tabs as $tab_id => $config ) {
			if ( ! is_string( $tab_id ) || '' === $tab_id ) {
				continue;
			}
			if ( ! is_array( $config ) ) {
				continue;
			}
			if ( empty( $config['label'] ) || empty( $config['callback'] ) ) {
				continue;
			}
			if ( empty( $config['capability'] ) ) {
				$config['capability'] = 'manage_options';
			}
			$valid[ sanitize_key( $tab_id ) ] = $config;
		}
		return $valid;
	}

	/**
	 * Return the config for a single tab, or null if unknown.
	 *
	 * @since 0.1.79
	 *
	 * @return array<string,mixed>|null
	 */
	public static function get_tab( string $tab_id ): ?array {
		$tabs = self::get_tabs();
		return $tabs[ $tab_id ] ?? null;
	}

	/**
	 * Return all fields registered for a tab, applying the per-tab
	 * `outpost_settings_fields_{tab_id}` filter. Drops malformed entries.
	 *
	 * @since 0.1.79
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function get_fields( string $tab_id ): array {
		$tab_id = sanitize_key( $tab_id );
		if ( '' === $tab_id ) {
			return array();
		}

		/**
		 * Filter the fields registered into a specific settings tab.
		 *
		 * @since 0.1.79
		 *
		 * @param array<string,array<string,mixed>> $fields Field id → config.
		 */
		$fields = apply_filters( 'outpost_settings_fields_' . $tab_id, array() );
		if ( ! is_array( $fields ) ) {
			return array();
		}

		$valid = array();
		foreach ( $fields as $field_id => $config ) {
			if ( ! is_string( $field_id ) || '' === $field_id ) {
				continue;
			}
			if ( ! is_array( $config ) ) {
				continue;
			}
			if ( empty( $config['label'] ) || empty( $config['type'] ) ) {
				continue;
			}
			$config['type']        = self::normalize_type( (string) $config['type'] );
			$config['sensitive']   = ! empty( $config['sensitive'] );
			$config['description'] = isset( $config['description'] ) ? (string) $config['description'] : '';
			$config['default']     = $config['default'] ?? '';
			if ( 'select' === $config['type'] ) {
				$config['options'] = isset( $config['options'] ) && is_array( $config['options'] )
					? $config['options']
					: array();
			}
			$valid[ sanitize_key( $field_id ) ] = $config;
		}
		return $valid;
	}

	/**
	 * Coerce field type to one of the supported values; default to text.
	 *
	 * @since 0.1.79
	 */
	public static function normalize_type( string $type ): string {
		$allowed = array( 'text', 'password', 'url', 'checkbox', 'select' );
		return in_array( $type, $allowed, true ) ? $type : 'text';
	}

	/**
	 * Storage option name for a tab's settings.
	 *
	 * @since 0.1.79
	 */
	public static function option_name_for_tab( string $tab_id ): string {
		return 'outpost_settings_' . sanitize_key( $tab_id );
	}
}
