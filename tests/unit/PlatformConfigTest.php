<?php
/**
 * Unit tests for Outpost_Manual_Share_Platform_Config (F9).
 *
 * Validates the strict construction-time checks: required keys, type
 * coercion, enum validation for caption_via and after_share, kebab-case
 * id constraint, and default normalization for optional fields.
 *
 * @package Outpost\Tests\Unit
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Manual_Share_Platform_Config;
use Outpost_Manual_Share_Invalid_Config_Exception;
use WP_Mock;

final class PlatformConfigTest extends \WP_Mock\Tools\TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	/**
	 * Minimal valid config — every required key present with valid values.
	 *
	 * @return array<string,mixed>
	 */
	private function valid_config(): array {
		return array(
			'id'            => 'example-platform',
			'label'         => 'Example Platform',
			'icon'          => 'example',
			'accepts_modes' => array( 'photo', 'gallery' ),
			'caption_via'   => 'clipboard',
			'after_share'   => 'mark_done',
		);
	}

	public function test_minimal_valid_config_constructs_without_error(): void {
		$config = new Outpost_Manual_Share_Platform_Config( $this->valid_config() );
		$this->assertSame( 'example-platform', $config->id() );
		$this->assertSame( 'Example Platform', $config->label() );
		$this->assertSame( array( 'photo', 'gallery' ), $config->accepts_modes() );
	}

	public function test_optional_fields_default_to_safe_values(): void {
		$config = new Outpost_Manual_Share_Platform_Config( $this->valid_config() );
		$arr    = $config->to_array();

		$this->assertSame( array(), $arr['accepts_media'] );
		$this->assertSame( '', $arr['ios_strategy'] );
		$this->assertNull( $arr['ios_url'] );
		$this->assertSame( '', $arr['android_action'] );
		$this->assertSame( '', $arr['android_pkg'] );
		$this->assertSame( '', $arr['android_mime'] );
		$this->assertSame( array(), $arr['android_extras'] );
		$this->assertNull( $arr['web_intent_url'] );
		$this->assertSame( array(), $arr['caveats'] );
		$this->assertFalse( $arr['prefers_bridgy'] );
	}

	public function test_to_chip_produces_companion_capabilities_shape(): void {
		$config = new Outpost_Manual_Share_Platform_Config( $this->valid_config() );
		$chip   = $config->to_chip();

		$this->assertSame( 'example-platform', $chip['id'] );
		$this->assertSame( 'Example Platform', $chip['label'] );
		$this->assertTrue( $chip['detected'] );
		$this->assertSame( array( 'photo', 'gallery' ), $chip['accepts_modes'] );
		$this->assertArrayHasKey( 'manual_share', $chip );
		$this->assertSame( 'example', $chip['manual_share']['icon'] );
		$this->assertSame( 'clipboard', $chip['manual_share']['caption_via'] );
		$this->assertSame( 'mark_done', $chip['manual_share']['after_share'] );
		$this->assertFalse( $chip['manual_share']['prefers_bridgy'] );
	}

	public function test_missing_required_key_throws_invalid_config_exception(): void {
		$config = $this->valid_config();
		unset( $config['caption_via'] );

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		$this->expectExceptionMessage( 'caption_via' );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_empty_id_throws(): void {
		$config       = $this->valid_config();
		$config['id'] = '';

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		$this->expectExceptionMessage( 'id' );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_non_kebab_case_id_throws(): void {
		$config       = $this->valid_config();
		$config['id'] = 'Bad_ID_With_Underscores';

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		$this->expectExceptionMessage( 'kebab-case' );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_id_with_uppercase_throws(): void {
		$config       = $this->valid_config();
		$config['id'] = 'Instagram';

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_id_starting_with_hyphen_throws(): void {
		$config       = $this->valid_config();
		$config['id'] = '-platform';

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_empty_accepts_modes_throws(): void {
		$config                  = $this->valid_config();
		$config['accepts_modes'] = array();

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		$this->expectExceptionMessage( 'accepts_modes' );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_non_string_accepts_mode_entry_throws(): void {
		$config                  = $this->valid_config();
		$config['accepts_modes'] = array( 'photo', 42 );

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_unknown_caption_via_value_throws(): void {
		$config                = $this->valid_config();
		$config['caption_via'] = 'magic';

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		$this->expectExceptionMessage( 'caption_via' );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_unknown_after_share_value_throws(): void {
		$config                = $this->valid_config();
		$config['after_share'] = 'beam_to_mars';

		$this->expectException( Outpost_Manual_Share_Invalid_Config_Exception::class );
		$this->expectExceptionMessage( 'after_share' );
		new Outpost_Manual_Share_Platform_Config( $config );
	}

	public function test_prefers_bridgy_strict_true_only(): void {
		// Only literal `true` enables prefers_bridgy. String "true",
		// integer 1, etc. produce false — strict-truthy avoids accidental
		// enabling from misencoded filter values.
		$config                    = $this->valid_config();
		$config['prefers_bridgy']  = 'true';
		$instance                  = new Outpost_Manual_Share_Platform_Config( $config );
		$this->assertFalse( $instance->prefers_bridgy() );

		$config['prefers_bridgy'] = 1;
		$instance                 = new Outpost_Manual_Share_Platform_Config( $config );
		$this->assertFalse( $instance->prefers_bridgy() );

		$config['prefers_bridgy'] = true;
		$instance                 = new Outpost_Manual_Share_Platform_Config( $config );
		$this->assertTrue( $instance->prefers_bridgy() );
	}

	public function test_caveats_filtered_to_strings_only(): void {
		$config             = $this->valid_config();
		$config['caveats']  = array( 'A real caveat', 42, null, 'Another caveat' );
		$instance           = new Outpost_Manual_Share_Platform_Config( $config );

		$this->assertSame(
			array( 'A real caveat', 'Another caveat' ),
			$instance->to_array()['caveats']
		);
	}

	public function test_accepts_media_filtered_to_strings_only(): void {
		$config                  = $this->valid_config();
		$config['accepts_media'] = array( 'image', 99, 'video', false );
		$instance                = new Outpost_Manual_Share_Platform_Config( $config );

		$this->assertSame(
			array( 'image', 'video' ),
			$instance->to_array()['accepts_media']
		);
	}

	public function test_full_config_round_trips_through_to_array(): void {
		$config = array(
			'id'             => 'fancy-platform',
			'label'          => 'Fancy Platform',
			'icon'           => 'fancy',
			'accepts_modes'  => array( 'photo' ),
			'accepts_media'  => array( 'image' ),
			'caption_via'    => 'web_intent',
			'ios_strategy'   => 'web_intent',
			'ios_url'        => 'https://fancy.example/share?caption=@caption_encoded',
			'android_action' => 'android.intent.action.SEND',
			'android_pkg'    => 'com.fancy.app',
			'android_mime'   => 'image/*',
			'android_extras' => array( 'EXTRA_STREAM' => '@image_uri' ),
			'web_intent_url' => 'https://fancy.example/share?caption=@caption_encoded',
			'after_share'    => 'prompt_for_silo_url',
			'caveats'        => array( 'Caveat one' ),
			'prefers_bridgy' => false,
		);
		$instance = new Outpost_Manual_Share_Platform_Config( $config );

		$arr = $instance->to_array();
		$this->assertSame( 'web_intent', $arr['caption_via'] );
		$this->assertSame( 'https://fancy.example/share?caption=@caption_encoded', $arr['ios_url'] );
		$this->assertSame( 'com.fancy.app', $arr['android_pkg'] );
	}
}
