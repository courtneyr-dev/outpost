<?php
/**
 * Outpost_Settings_Fields (G3.5d).
 *
 * Field renderers + sanitizers for the multi-tab settings UI. Five
 * supported types: text, password, url, checkbox, select. Renderer
 * methods echo the input element; sanitize() returns the cleaned
 * scalar suitable for storage.
 *
 * @package Outpost
 * @since   0.1.79
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Settings_Fields {

	/**
	 * Render an `<input>` / `<select>` for a registered field, with a
	 * resolved current value pre-filled.
	 *
	 * @since 0.1.79
	 *
	 * @param string              $field_id Sanitized id (sanitize_key applied).
	 * @param array<string,mixed> $config   Field config from the registry.
	 * @param mixed               $value    Current decrypted value.
	 */
	public static function render( string $field_id, array $config, $value ): void {
		$type        = (string) ( $config['type'] ?? 'text' );
		$label       = (string) ( $config['label'] ?? '' );
		$description = (string) ( $config['description'] ?? '' );
		$name        = 'outpost_settings[' . $field_id . ']';
		$id_attr     = 'outpost-settings-' . $field_id;

		echo '<tr><th scope="row"><label for="' . esc_attr( $id_attr ) . '">' . esc_html( $label ) . '</label></th><td>';

		switch ( $type ) {
			case 'password':
				printf(
					'<input type="password" id="%1$s" name="%2$s" value="%3$s" class="regular-text" autocomplete="new-password" />',
					esc_attr( $id_attr ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
			case 'url':
				printf(
					'<input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id_attr ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
				break;
			case 'checkbox':
				printf(
					'<input type="checkbox" id="%1$s" name="%2$s" value="1"%3$s />',
					esc_attr( $id_attr ),
					esc_attr( $name ),
					! empty( $value ) ? ' checked' : ''
				);
				break;
			case 'select':
				$options = isset( $config['options'] ) && is_array( $config['options'] )
					? $config['options']
					: array();
				printf( '<select id="%1$s" name="%2$s">', esc_attr( $id_attr ), esc_attr( $name ) );
				foreach ( $options as $option_value => $option_label ) {
					printf(
						'<option value="%1$s"%3$s>%2$s</option>',
						esc_attr( (string) $option_value ),
						esc_html( (string) $option_label ),
						(string) $option_value === (string) $value ? ' selected' : ''
					);
				}
				echo '</select>';
				break;
			case 'text':
			default:
				printf(
					'<input type="text" id="%1$s" name="%2$s" value="%3$s" class="regular-text" />',
					esc_attr( $id_attr ),
					esc_attr( $name ),
					esc_attr( (string) $value )
				);
		}

		if ( '' !== $description ) {
			echo '<p class="description">' . esc_html( $description ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Sanitize a submitted value for the given field type.
	 *
	 * @since 0.1.79
	 *
	 * @param string $type  Field type (already normalized).
	 * @param mixed  $value Raw POST value.
	 * @return string|bool  Sanitized value (string for text/url/password/select; bool for checkbox).
	 */
	public static function sanitize( string $type, $value ) {
		switch ( $type ) {
			case 'password':
				// Don't apply sanitize_text_field — it strips characters that
				// some API keys legitimately contain. Only strip control bytes.
				$value = is_scalar( $value ) ? (string) $value : '';
				return preg_replace( '/[\x00-\x1F\x7F]/', '', $value ) ?? '';
			case 'url':
				return is_scalar( $value ) ? esc_url_raw( (string) $value ) : '';
			case 'checkbox':
				return ! empty( $value );
			case 'select':
			case 'text':
			default:
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : '';
		}
	}
}
