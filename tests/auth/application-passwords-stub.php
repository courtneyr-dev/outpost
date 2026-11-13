<?php
/**
 * In-memory stand-ins for core's WP_Application_Passwords and WP_User,
 * for unit tests that run without WordPress.
 *
 * @package Outpost\Tests\Auth
 */

declare(strict_types=1);

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound, Squiz.Commenting

if ( ! class_exists( 'WP_User' ) ) {
	class WP_User {
		public int $ID          = 0;
		public string $user_login = '';
	}
}

if ( ! class_exists( 'WP_Application_Passwords' ) ) {
	class WP_Application_Passwords {
		/** @var array<int, array<int, array<string, mixed>>> */
		public static array $store = array();

		public static function reset(): void {
			self::$store = array();
		}

		/** @return array<int, array<string, mixed>> */
		public static function get_user_application_passwords( $user_id ): array {
			return self::$store[ (int) $user_id ] ?? array();
		}

		/** @return array{0: string, 1: array<string, mixed>} */
		public static function create_new_application_password( $user_id, $args = array() ): array {
			$item                            = array(
				'uuid'      => 'uuid-' . ( count( self::$store[ (int) $user_id ] ?? array() ) + 1 ) . '-' . $args['name'],
				'app_id'    => $args['app_id'] ?? '',
				'name'      => $args['name'],
				'created'   => 1700000000,
				'last_used' => null,
			);
			self::$store[ (int) $user_id ][] = $item;
			return array( 'plain-text-password', $item );
		}

		public static function delete_application_password( $user_id, $uuid ): bool {
			foreach ( self::$store[ (int) $user_id ] ?? array() as $i => $item ) {
				if ( $item['uuid'] === $uuid ) {
					unset( self::$store[ (int) $user_id ][ $i ] );
					self::$store[ (int) $user_id ] = array_values( self::$store[ (int) $user_id ] );
					return true;
				}
			}
			return false;
		}
	}
}
