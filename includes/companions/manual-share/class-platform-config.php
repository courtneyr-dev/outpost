<?php
/**
 * Outpost_Manual_Share_Platform_Config
 *
 * Value object wrapping a single manual-share platform config. F9 ships
 * 10 default platforms (Instagram feed/stories, Facebook, X, LinkedIn,
 * Threads, TikTok, Pinterest, Reddit-manual, Flickr-manual) — each a
 * Platform_Config instance built from the declarative array shape
 * documented in CLAUDE.md F9 entry.
 *
 * Configs are DATA, not code. Per-platform quirks (caption_via,
 * ios_strategy, android_pkg, EXTRA_TEXT honoring, etc.) are config
 * fields the F10/F11 intent-fire logic reads at runtime — there is no
 * per-platform PHP class.
 *
 * The class validates required fields at construction so a malformed
 * filter-registered config produces a clearly-named exception at
 * registration time rather than a silently-broken chip at runtime.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Platform_Config {

	/** Allowed values for `caption_via`. */
	public const CAPTION_VIA_INTENT     = 'intent';
	public const CAPTION_VIA_CLIPBOARD  = 'clipboard';
	public const CAPTION_VIA_WEB_INTENT = 'web_intent';

	/** Allowed values for `after_share`. */
	public const AFTER_SHARE_MARK_DONE       = 'mark_done';
	public const AFTER_SHARE_PROMPT_SILO_URL = 'prompt_for_silo_url';
	public const AFTER_SHARE_SILENT          = 'silent';

	private const REQUIRED_KEYS = array(
		'id',
		'label',
		'icon',
		'accepts_modes',
		'caption_via',
		'after_share',
	);

	private const VALID_CAPTION_VIA = array(
		self::CAPTION_VIA_INTENT,
		self::CAPTION_VIA_CLIPBOARD,
		self::CAPTION_VIA_WEB_INTENT,
	);

	private const VALID_AFTER_SHARE = array(
		self::AFTER_SHARE_MARK_DONE,
		self::AFTER_SHARE_PROMPT_SILO_URL,
		self::AFTER_SHARE_SILENT,
	);

	/**
	 * Normalized config storage.
	 *
	 * @var array<string,mixed>
	 */
	private array $config;

	/**
	 * Build a Platform_Config from a declarative array. Validates strictly.
	 *
	 * @param array<string,mixed> $config Platform config (see class docblock for shape).
	 * @throws Outpost_Manual_Share_Invalid_Config_Exception When any required key is missing
	 *         or has the wrong type, or an enum-typed field has an unrecognized value.
	 */
	public function __construct( array $config ) {
		$this->config = $this->normalize_and_validate( $config );
	}

	/**
	 * Stable platform ID (kebab-case slug).
	 */
	public function id(): string {
		return (string) $this->config['id'];
	}

	/**
	 * Translated display label.
	 */
	public function label(): string {
		return (string) $this->config['label'];
	}

	/**
	 * Composer modes this platform accepts (subset of Outpost's known modes).
	 *
	 * @return string[]
	 */
	public function accepts_modes(): array {
		return $this->config['accepts_modes'];
	}

	/**
	 * Whether this platform should hide its chip when Bridgy Publish is
	 * configured. F9 default: false. Reddit-manual and Flickr-manual set
	 * this to true so F14's Companion_BridgyPublish becomes the preferred
	 * route once detected.
	 */
	public function prefers_bridgy(): bool {
		return (bool) $this->config['prefers_bridgy'];
	}

	/**
	 * Full normalized config array. Used by the chip projection and
	 * by the F10/F11 intent-fire logic.
	 *
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->config;
	}

	/**
	 * Project this config to the chip shape consumed by
	 * {@see Outpost_Companion_Registry::chips_for_mode()}.
	 *
	 * The chip shape mirrors {@see Outpost_Companion_Base::capabilities()}
	 * so the per-mode filter doesn't need to branch on chip origin
	 * (umbrella-platform vs. single-companion).
	 *
	 * @return array<string,mixed>
	 */
	public function to_chip(): array {
		return array(
			'id'              => $this->id(),
			'label'           => $this->label(),
			'detected'        => true,
			'accepts_modes'   => $this->accepts_modes(),
			'accepts_media'   => $this->config['accepts_media'],
			'max_attachments' => null,
			'alt_passthrough' => false,
			'char_limit'      => null,
			'caveats'         => $this->config['caveats'],
			'requires_auth'   => false,
			// Manual-share-specific extensions:
			'manual_share'    => array(
				'icon'           => $this->config['icon'],
				'caption_via'    => $this->config['caption_via'],
				'ios_strategy'   => $this->config['ios_strategy'],
				'app_url_scheme' => $this->config['app_url_scheme'],
				'android_action' => $this->config['android_action'],
				'android_pkg'    => $this->config['android_pkg'],
				'android_mime'   => $this->config['android_mime'],
				'android_extras' => $this->config['android_extras'],
				'web_intent_url' => $this->config['web_intent_url'],
				'after_share'    => $this->config['after_share'],
				'prefers_bridgy' => $this->prefers_bridgy(),
			),
		);
	}

	/**
	 * Validate all required keys are present, types correct, enum values
	 * allowed. Fill optional defaults.
	 *
	 * @param array<string,mixed> $config Raw input.
	 * @return array<string,mixed> Normalized config with defaults applied.
	 *
	 * @throws Outpost_Manual_Share_Invalid_Config_Exception On any validation failure.
	 */
	private function normalize_and_validate( array $config ): array {
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				throw new Outpost_Manual_Share_Invalid_Config_Exception(
					esc_html( sprintf( 'Manual-share platform config missing required key: %s', $key ) )
				);
			}
		}

		$id = $config['id'];
		if ( ! is_string( $id ) || '' === $id ) {
			throw new Outpost_Manual_Share_Invalid_Config_Exception(
				esc_html( 'Manual-share platform `id` must be a non-empty string.' )
			);
		}
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $id ) ) {
			throw new Outpost_Manual_Share_Invalid_Config_Exception(
				esc_html( sprintf( 'Manual-share platform `id` must be kebab-case (a-z, 0-9, hyphens; lowercase): %s', $id ) )
			);
		}

		$label = $config['label'];
		if ( ! is_string( $label ) || '' === $label ) {
			throw new Outpost_Manual_Share_Invalid_Config_Exception(
				esc_html( sprintf( 'Manual-share platform `label` must be a non-empty string for id %s.', $id ) )
			);
		}

		$icon = $config['icon'];
		if ( ! is_string( $icon ) || '' === $icon ) {
			throw new Outpost_Manual_Share_Invalid_Config_Exception(
				esc_html( sprintf( 'Manual-share platform `icon` must be a non-empty string for id %s.', $id ) )
			);
		}

		$accepts_modes = $config['accepts_modes'];
		if ( ! is_array( $accepts_modes ) || empty( $accepts_modes ) ) {
			throw new Outpost_Manual_Share_Invalid_Config_Exception(
				esc_html( sprintf( 'Manual-share platform `accepts_modes` must be a non-empty array for id %s.', $id ) )
			);
		}
		foreach ( $accepts_modes as $mode ) {
			if ( ! is_string( $mode ) || '' === $mode ) {
				throw new Outpost_Manual_Share_Invalid_Config_Exception(
					esc_html( sprintf( 'Manual-share platform `accepts_modes` entries must be non-empty strings for id %s.', $id ) )
				);
			}
		}

		$caption_via = $config['caption_via'];
		if ( ! in_array( $caption_via, self::VALID_CAPTION_VIA, true ) ) {
			$caption_via_label = is_string( $caption_via ) ? $caption_via : gettype( $caption_via );
			throw new Outpost_Manual_Share_Invalid_Config_Exception(
				esc_html(
					sprintf(
						'Manual-share platform `caption_via` must be one of: %s. Got: %s',
						implode( ', ', self::VALID_CAPTION_VIA ),
						$caption_via_label
					)
				)
			);
		}

		$after_share = $config['after_share'];
		if ( ! in_array( $after_share, self::VALID_AFTER_SHARE, true ) ) {
			$after_share_label = is_string( $after_share ) ? $after_share : gettype( $after_share );
			throw new Outpost_Manual_Share_Invalid_Config_Exception(
				esc_html(
					sprintf(
						'Manual-share platform `after_share` must be one of: %s. Got: %s',
						implode( ', ', self::VALID_AFTER_SHARE ),
						$after_share_label
					)
				)
			);
		}

		// Optional fields with sane defaults.
		$accepts_media = isset( $config['accepts_media'] ) && is_array( $config['accepts_media'] )
			? array_values( array_filter( $config['accepts_media'], 'is_string' ) )
			: array();

		// F11: ios_strategy normalized to an array of strategy names.
		// Accepts string (legacy F9 shape) or array; either coerces to
		// a clean array of strings the StrategyRunner walks. Empty
		// array signals "no iOS chain — defer to F9 stub" which is
		// what desktop and unset-platform paths receive.
		$ios_strategy_raw = $config['ios_strategy'] ?? array();
		if ( is_string( $ios_strategy_raw ) ) {
			$ios_strategy = '' === $ios_strategy_raw ? array() : array( $ios_strategy_raw );
		} elseif ( is_array( $ios_strategy_raw ) ) {
			$ios_strategy = array_values( array_filter( $ios_strategy_raw, 'is_string' ) );
		} else {
			$ios_strategy = array();
		}

		// F11: app_url_scheme is the iOS URL-scheme for app launching
		// (e.g. 'instagram://library?AssetPath='). Renamed from F9's
		// `ios_url`; configs that still pass `ios_url` get migrated
		// silently for backward compatibility.
		$app_url_scheme_raw = $config['app_url_scheme'] ?? ( $config['ios_url'] ?? null );
		$app_url_scheme     = is_string( $app_url_scheme_raw ) && '' !== $app_url_scheme_raw
			? $app_url_scheme_raw
			: null;

		$android_action = isset( $config['android_action'] ) && is_string( $config['android_action'] )
			? $config['android_action']
			: '';
		$android_pkg    = isset( $config['android_pkg'] ) && is_string( $config['android_pkg'] )
			? $config['android_pkg']
			: '';
		$android_mime   = isset( $config['android_mime'] ) && is_string( $config['android_mime'] )
			? $config['android_mime']
			: '';
		$android_extras = isset( $config['android_extras'] ) && is_array( $config['android_extras'] )
			? $config['android_extras']
			: array();
		$web_intent_url = isset( $config['web_intent_url'] ) && is_string( $config['web_intent_url'] )
			? $config['web_intent_url']
			: null;
		$caveats        = isset( $config['caveats'] ) && is_array( $config['caveats'] )
			? array_values( array_filter( $config['caveats'], 'is_string' ) )
			: array();
		$prefers_bridgy = isset( $config['prefers_bridgy'] ) && true === $config['prefers_bridgy'];

		return array(
			'id'             => $id,
			'label'          => $label,
			'icon'           => $icon,
			'accepts_modes'  => array_values( $accepts_modes ),
			'accepts_media'  => $accepts_media,
			'caption_via'    => $caption_via,
			'ios_strategy'   => $ios_strategy,
			'app_url_scheme' => $app_url_scheme,
			'android_action' => $android_action,
			'android_pkg'    => $android_pkg,
			'android_mime'   => $android_mime,
			'android_extras' => $android_extras,
			'web_intent_url' => $web_intent_url,
			'after_share'    => $after_share,
			'caveats'        => $caveats,
			'prefers_bridgy' => $prefers_bridgy,
		);
	}
}
