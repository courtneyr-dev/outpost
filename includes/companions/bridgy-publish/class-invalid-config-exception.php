<?php
/**
 * Outpost_Bridgy_Publish_Invalid_Config_Exception
 *
 * Raised by {@see Outpost_Bridgy_Publish_Silo_Config} at registration
 * time when a silo config is malformed. Same fail-fast posture as the
 * F9 Manual_Share invalid-config exception.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Bridgy_Publish_Invalid_Config_Exception extends \InvalidArgumentException {
}
