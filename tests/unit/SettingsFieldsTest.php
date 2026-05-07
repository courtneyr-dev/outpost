<?php
/**
 * Outpost_Settings_Fields unit tests (G3.5d).
 *
 * @package Outpost
 */

declare(strict_types=1);

namespace Outpost\Tests\Unit;

use Outpost_Settings_Fields;
use WP_Mock;
use WP_Mock\Tools\TestCase;

final class SettingsFieldsTest extends TestCase {

	public function setUp(): void {
		WP_Mock::setUp();
	}

	public function tearDown(): void {
		WP_Mock::tearDown();
	}

	// ---------- sanitize() ----------

	public function test_text_type_uses_sanitize_text_field(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static function ( $s ) { return trim( strip_tags( (string) $s ) ); }
		);

		$this->assertSame( 'hello', Outpost_Settings_Fields::sanitize( 'text', '<b>hello</b>' ) );
	}

	public function test_url_type_uses_esc_url_raw(): void {
		WP_Mock::userFunction( 'esc_url_raw' )->andReturnUsing(
			static function ( $s ) { return (string) $s; }
		);

		$this->assertSame( 'https://example.com', Outpost_Settings_Fields::sanitize( 'url', 'https://example.com' ) );
	}

	public function test_password_type_strips_control_bytes_but_preserves_punctuation(): void {
		// API keys legitimately contain `:`, `.`, `=`, `+`, `/`, etc. Don't
		// strip those — only control bytes.
		$result = Outpost_Settings_Fields::sanitize( 'password', "abc\x00def:ghi.123/45+6=" );

		$this->assertSame( 'abcdef:ghi.123/45+6=', $result );
	}

	public function test_checkbox_returns_boolean(): void {
		$this->assertTrue( Outpost_Settings_Fields::sanitize( 'checkbox', '1' ) );
		$this->assertFalse( Outpost_Settings_Fields::sanitize( 'checkbox', '' ) );
		$this->assertFalse( Outpost_Settings_Fields::sanitize( 'checkbox', null ) );
	}

	public function test_select_uses_sanitize_text_field(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static function ( $s ) { return (string) $s; }
		);

		$this->assertSame( 'option-a', Outpost_Settings_Fields::sanitize( 'select', 'option-a' ) );
	}

	public function test_non_scalar_input_returns_empty_string(): void {
		WP_Mock::userFunction( 'sanitize_text_field' )->andReturnUsing(
			static function ( $s ) { return (string) $s; }
		);

		$this->assertSame( '', Outpost_Settings_Fields::sanitize( 'text', array( 'array' ) ) );
		$this->assertSame( '', Outpost_Settings_Fields::sanitize( 'text', new \stdClass() ) );
	}

	// ---------- render() smoke tests via output buffering ----------

	public function test_render_text_field_outputs_input_with_value(): void {
		ob_start();
		Outpost_Settings_Fields::render(
			'sample_text',
			array(
				'label' => 'Sample text',
				'type'  => 'text',
			),
			'pre-filled'
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="text"', $html );
		$this->assertStringContainsString( 'name="outpost_settings[sample_text]"', $html );
		$this->assertStringContainsString( 'value="pre-filled"', $html );
		$this->assertStringContainsString( 'Sample text', $html );
	}

	public function test_render_password_field_uses_password_input(): void {
		ob_start();
		Outpost_Settings_Fields::render(
			'sample_pw',
			array(
				'label' => 'Password',
				'type'  => 'password',
			),
			'secret-value'
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="password"', $html );
		$this->assertStringContainsString( 'value="secret-value"', $html );
	}

	public function test_render_checkbox_marks_checked_when_truthy(): void {
		ob_start();
		Outpost_Settings_Fields::render(
			'sample_cb',
			array(
				'label' => 'Toggle',
				'type'  => 'checkbox',
			),
			true
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'type="checkbox"', $html );
		$this->assertStringContainsString( ' checked', $html );
	}

	public function test_render_select_marks_selected_option(): void {
		ob_start();
		Outpost_Settings_Fields::render(
			'sample_select',
			array(
				'label'   => 'Choose',
				'type'    => 'select',
				'options' => array(
					'a' => 'Alpha',
					'b' => 'Beta',
				),
			),
			'b'
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( '<option value="a">Alpha</option>', $html );
		$this->assertStringContainsString( '<option value="b" selected>Beta</option>', $html );
	}

	public function test_render_includes_description_when_provided(): void {
		ob_start();
		Outpost_Settings_Fields::render(
			'sample',
			array(
				'label'       => 'Sample',
				'type'        => 'text',
				'description' => 'Helpful description goes here.',
			),
			''
		);
		$html = ob_get_clean();

		$this->assertStringContainsString( 'Helpful description goes here.', $html );
		$this->assertStringContainsString( 'class="description"', $html );
	}
}
