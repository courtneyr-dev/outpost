<?php
/**
 * Outpost_Bridgy_Publish_Silo_Config
 *
 * Value object wrapping a single Bridgy Publish silo config. F14 ships
 * 5 default silos (Mastodon, Bluesky, Flickr, GitHub, Reddit) — each
 * a Silo_Config instance built from the declarative array shape. Same
 * pattern as F9's `Outpost_Manual_Share_Platform_Config` so the
 * composer can iterate either family with a uniform vocabulary.
 *
 * Configs are DATA, not code. Per-silo accepts_modes drives F2's
 * chips_for_mode() filtering at runtime — there is no per-silo PHP
 * class, branching, or special case.
 *
 * Validates required fields at construction so a malformed
 * filter-registered config produces a clearly-named exception.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Silo_Config {

	private const REQUIRED_KEYS = array(
		'id',
		'label',
		'icon',
		'silo_id',
		'bridgy_url',
		'accepts_modes',
	);

	/**
	 * Normalized config storage.
	 *
	 * @var array<string,mixed>
	 */
	private array $config;

	/**
	 * @param array<string,mixed> $config
	 *
	 * @throws Outpost_Bridgy_Publish_Invalid_Config_Exception When any required
	 *         key is missing or has the wrong type.
	 */
	public function __construct( array $config ) {
		$this->config = $this->normalize_and_validate( $config );
	}

	public function id(): string {
		return (string) $this->config['id'];
	}

	public function label(): string {
		return (string) $this->config['label'];
	}

	public function silo_id(): string {
		return (string) $this->config['silo_id'];
	}

	public function bridgy_url(): string {
		return (string) $this->config['bridgy_url'];
	}

	/**
	 * @return string[]
	 */
	public function accepts_modes(): array {
		return $this->config['accepts_modes'];
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return $this->config;
	}

	/**
	 * Project to the F2 chip shape consumed by
	 * {@see Outpost_Companion_Registry::chips_for_mode()}.
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
			'bridgy_publish'  => array(
				'icon'       => $this->config['icon'],
				'silo_id'    => $this->silo_id(),
				'bridgy_url' => $this->bridgy_url(),
			),
		);
	}

	/**
	 * @param array<string,mixed> $config
	 * @return array<string,mixed>
	 *
	 * @throws Outpost_Bridgy_Publish_Invalid_Config_Exception
	 */
	private function normalize_and_validate( array $config ): array {
		foreach ( self::REQUIRED_KEYS as $key ) {
			if ( ! array_key_exists( $key, $config ) ) {
				throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
					esc_html( sprintf( 'Bridgy silo config missing required key: %s', $key ) )
				);
			}
		}

		$id = $config['id'];
		if ( ! is_string( $id ) || '' === $id ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( 'Bridgy silo `id` must be a non-empty string.' )
			);
		}
		if ( 1 !== preg_match( '/^[a-z0-9][a-z0-9\-]*$/', $id ) ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( sprintf( 'Bridgy silo `id` must be kebab-case: %s', $id ) )
			);
		}

		$bridgy_url = $config['bridgy_url'];
		if ( ! is_string( $bridgy_url ) || '' === $bridgy_url ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( sprintf( 'Bridgy silo `bridgy_url` must be a non-empty string for id %s.', $id ) )
			);
		}
		if ( 0 !== strpos( $bridgy_url, 'https://brid.gy/publish/' )
			&& 0 !== strpos( $bridgy_url, 'https://fed.brid.gy/' ) ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( sprintf( 'Bridgy silo `bridgy_url` must point at brid.gy: %s', $bridgy_url ) )
			);
		}

		$silo_id = $config['silo_id'];
		if ( ! is_string( $silo_id ) || '' === $silo_id ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( sprintf( 'Bridgy silo `silo_id` must be a non-empty string for id %s.', $id ) )
			);
		}

		$label = $config['label'];
		if ( ! is_string( $label ) || '' === $label ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( sprintf( 'Bridgy silo `label` must be a non-empty string for id %s.', $id ) )
			);
		}

		$icon = $config['icon'];
		if ( ! is_string( $icon ) || '' === $icon ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( sprintf( 'Bridgy silo `icon` must be a non-empty string for id %s.', $id ) )
			);
		}

		$accepts_modes = $config['accepts_modes'];
		if ( ! is_array( $accepts_modes ) || empty( $accepts_modes ) ) {
			throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
				esc_html( sprintf( 'Bridgy silo `accepts_modes` must be a non-empty array for id %s.', $id ) )
			);
		}
		foreach ( $accepts_modes as $mode ) {
			if ( ! is_string( $mode ) || '' === $mode ) {
				throw new Outpost_Bridgy_Publish_Invalid_Config_Exception(
					esc_html( sprintf( 'Bridgy silo `accepts_modes` entries must be non-empty strings for id %s.', $id ) )
				);
			}
		}

		$accepts_media = isset( $config['accepts_media'] ) && is_array( $config['accepts_media'] )
			? array_values( array_filter( $config['accepts_media'], 'is_string' ) )
			: array();
		$caveats       = isset( $config['caveats'] ) && is_array( $config['caveats'] )
			? array_values( array_filter( $config['caveats'], 'is_string' ) )
			: array();

		return array(
			'id'            => $id,
			'label'         => $label,
			'icon'          => $icon,
			'silo_id'       => $silo_id,
			'bridgy_url'    => $bridgy_url,
			'accepts_modes' => array_values( $accepts_modes ),
			'accepts_media' => $accepts_media,
			'caveats'       => $caveats,
		);
	}
}
