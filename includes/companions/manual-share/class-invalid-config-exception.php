<?php
/**
 * Outpost_Manual_Share_Invalid_Config_Exception
 *
 * Raised by {@see Outpost_Manual_Share_Platform_Config} at registration
 * time when a platform config is malformed. Failing fast at registration
 * (rather than at chip-render or intent-fire time) is load-bearing for
 * F9: a typo in a third-party-registered platform config surfaces during
 * `outpost_manual_share_platforms` filter resolution, NOT later when a
 * user taps a chip and finds the intent firing into nothing.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Manual_Share_Invalid_Config_Exception extends \InvalidArgumentException {
}
