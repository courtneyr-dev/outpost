<?php
/**
 * Outpost_Library_Photos_Endpoint
 *
 * Lets the composer reuse an image that is already in the WordPress Media
 * Library. `POST /wp-json/outpost/v1/library-photos` does two things:
 *
 *   - Without `attachmentId`, it lists ready images the user may edit,
 *     optionally filtered by `search`. POST keeps the search text out of
 *     URLs and server logs.
 *   - With `attachmentId`, it saves a draft whose body is one core/image
 *     block that stores that attachment ID. The attachment itself is never
 *     written: its parent, caption, alt text and generated sizes stay as they
 *     are, so every other post using it is unaffected.
 *
 * Cropping and client-side processing stay in WordPress's own media screens.
 * The list returns each image's edit link so the composer can hand off there.
 *
 * On WordPress 7.2, an image uploaded through client-side media processing
 * exists before its sub-sizes do: the browser sideloads them and then calls
 * `/wp/v2/media/<id>/finalize`. Such an image is marked pending on creation
 * and only listed or usable once finalize succeeds, so a draft never points
 * at an image with no derivatives.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Library_Photos_Endpoint {

	use Outpost_Bearer_Auth;

	/** Post meta marking a client-processed upload that is not finalized yet. */
	public const PENDING_META = '_outpost_media_awaiting_finalize';

	/** Images returned per list request. */
	private const LIST_LIMIT = 30;

	/**
	 * Hook the route and the client-upload tracking.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_action( 'rest_after_insert_attachment', array( self::class, 'track_client_upload' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'complete_client_upload' ), 20, 3 );
	}

	/**
	 * Register the REST route. Kept out of the public route index.
	 */
	public static function register_route(): void {
		register_rest_route(
			'outpost/v1',
			'/library-photos',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'permission_check' ),
				'show_in_index'       => false,
				'args'                => array(
					'attachmentId' => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'alt'          => array( 'type' => 'string' ),
					'decorative'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'search'       => array(
						'type'      => 'string',
						'maxLength' => 80,
						'default'   => '',
					),
				),
			)
		);
	}

	/**
	 * Route-level check: a credential with the `create` scope, plus the
	 * capabilities to use media and write posts. Access to a specific
	 * attachment is checked per image in {@see self::usable_image()}.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permission_check( WP_REST_Request $request ): bool {
		// Credential first: a cookie session without a valid REST nonce, or a
		// token without the `create` scope, is refused before any capability.
		if ( ! self::bearer_has_scope( $request, array( 'create' ) ) ) {
			return false;
		}
		if ( ! current_user_can( 'upload_files' ) ) {
			return false;
		}
		return current_user_can( 'edit_posts' );
	}

	/**
	 * List images, or save a draft from one.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle( WP_REST_Request $request ) {
		$response = null !== $request->get_param( 'attachmentId' )
			? self::save_draft( $request )
			: self::list_images( $request );
		if ( $response instanceof WP_REST_Response ) {
			$response->header( 'Cache-Control', 'private, no-store' );
		}
		return $response;
	}

	/**
	 * Save a draft holding one core/image block for the chosen attachment.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	private static function save_draft( WP_REST_Request $request ) {
		$attachment = self::usable_image( (int) $request->get_param( 'attachmentId' ) );
		// One answer for missing, foreign, private and pending images, so the
		// response never reveals which IDs exist.
		if ( null === $attachment ) {
			return new WP_Error(
				'outpost_library_image_unavailable',
				__( 'This image is not ready or you cannot use it.', 'outpost-mobile-publishing' ),
				array( 'status' => 403 )
			);
		}
		$source = wp_get_attachment_image_url( $attachment->ID, 'full' );
		if ( ! is_string( $source ) || '' === $source ) {
			return new WP_Error(
				'outpost_library_image_unavailable',
				__( 'The original image is unavailable.', 'outpost-mobile-publishing' ),
				array( 'status' => 409 )
			);
		}
		$choice = self::alt_choice(
			is_string( $request->get_param( 'alt' ) ) ? (string) $request->get_param( 'alt' ) : null,
			filter_var( $request->get_param( 'decorative' ), FILTER_VALIDATE_BOOLEAN ),
			(string) get_post_meta( $attachment->ID, '_wp_attachment_image_alt', true )
		);
		if ( null === $choice ) {
			return new WP_Error(
				'outpost_library_image_alt_required',
				__( 'Add alt text or mark the image as decorative.', 'outpost-mobile-publishing' ),
				array( 'status' => 400 )
			);
		}

		$id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => 'post',
					'post_title'   => $attachment->post_title,
					'post_content' => self::image_block( $attachment, $source, $choice, self::core_supports_decorative() ),
					'post_status'  => 'draft',
				)
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return new WP_REST_Response(
			array(
				'id'      => $id,
				'editUrl' => get_edit_post_link( $id, 'raw' ),
			),
			201
		);
	}

	/**
	 * List ready images the user may edit.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private static function list_images( WP_REST_Request $request ): WP_REST_Response {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'author'         => current_user_can( 'edit_others_posts' ) ? 0 : get_current_user_id(),
				'posts_per_page' => self::LIST_LIMIT,
				'no_found_rows'  => true,
				's'              => sanitize_text_field( (string) $request->get_param( 'search' ) ),
			)
		);
		$items = array();
		foreach ( $query->posts as $post ) {
			$image = self::usable_image( $post instanceof WP_Post ? $post->ID : (int) $post );
			if ( null === $image ) {
				continue;
			}
			$thumbnail = wp_get_attachment_image_url( $image->ID, 'thumbnail' );
			$items[]   = array(
				'id'          => $image->ID,
				'title'       => $image->post_title,
				'caption'     => wp_strip_all_tags( $image->post_excerpt ),
				'alt'         => (string) get_post_meta( $image->ID, '_wp_attachment_image_alt', true ),
				'embeddedAlt' => self::embedded_alt( $image->ID ),
				'thumbnail'   => is_string( $thumbnail ) ? $thumbnail : null,
				'editUrl'     => get_edit_post_link( $image->ID, 'raw' ),
			);
		}
		return new WP_REST_Response( array( 'items' => $items ) );
	}

	/**
	 * The alt text WordPress read from the file's embedded IPTC metadata on
	 * upload (WordPress 7.0+ stores it as `image_meta.alt`). Kept apart from
	 * the attachment's alt so the composer can offer it as a suggestion the
	 * author reviews, not a value applied for them.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string
	 */
	private static function embedded_alt( int $attachment_id ): string {
		$metadata = wp_get_attachment_metadata( $attachment_id );
		$embedded = is_array( $metadata ) ? ( $metadata['image_meta']['alt'] ?? '' ) : '';
		return is_string( $embedded ) ? sanitize_text_field( $embedded ) : '';
	}

	/**
	 * Resolve the author's alt decision.
	 *
	 * Returns `array{decorative: true, alt: ''}` for a decorative image,
	 * `array{decorative: false, alt: string}` for described text, or null when
	 * a non-decorative image would end up with no alt. An absent `alt` falls
	 * back to the attachment's current alt; a present empty `alt` does not.
	 *
	 * @param string|null $requested  The `alt` field, or null when absent.
	 * @param bool        $decorative The author marked the image decorative.
	 * @param string      $current    The attachment's stored alt.
	 * @return array{decorative: bool, alt: string}|null
	 */
	public static function alt_choice( ?string $requested, bool $decorative, string $current ): ?array {
		if ( $decorative ) {
			return array(
				'decorative' => true,
				'alt'        => '',
			);
		}
		$alt = trim( sanitize_text_field( $requested ?? $current ) );
		if ( '' === $alt ) {
			return null;
		}
		return array(
			'decorative' => false,
			'alt'        => $alt,
		);
	}

	/**
	 * Whether this WordPress's core/image block has the `isDecorative`
	 * attribute (added in 7.2). Earlier versions express decorative only as
	 * `alt=""`; sending the attribute and `role="none"` there would make the
	 * editor flag the block invalid.
	 */
	private static function core_supports_decorative(): bool {
		$block_type = WP_Block_Type_Registry::get_instance()->get_registered( 'core/image' );
		return null !== $block_type && isset( $block_type->attributes['isDecorative'] );
	}

	/**
	 * Return the attachment if the current user may put it in a draft.
	 *
	 * It must be a finished image the user can edit. An image attached to a
	 * post the user cannot read (a private post) is refused even when the
	 * user can edit the image.
	 *
	 * @param int $id Attachment ID.
	 * @return WP_Post|null
	 */
	private static function usable_image( int $id ): ?WP_Post {
		$attachment = get_post( $id );
		if ( ! $attachment instanceof WP_Post || 'attachment' !== $attachment->post_type ) {
			return null;
		}
		if ( 'inherit' !== $attachment->post_status || ! wp_attachment_is_image( $attachment ) ) {
			return null;
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			return null;
		}
		if ( $attachment->post_parent > 0 && ! current_user_can( 'read_post', $attachment->post_parent ) ) {
			return null;
		}
		if ( '' !== (string) get_post_meta( $id, self::PENDING_META, true ) ) {
			return null;
		}
		return $attachment;
	}

	/**
	 * Build the core/image block markup exactly as the block editor saves it,
	 * so the editor opens the draft with a valid block.
	 *
	 * The alt is an HTML attribute (the block sources it from `<img alt>`),
	 * escaped once and otherwise kept literal. A decorative image gets
	 * `alt=""`, plus `isDecorative` and `role="none"` where core supports it.
	 *
	 * @param WP_Post                               $image               Attachment.
	 * @param string                                $source              Full-size image URL.
	 * @param array{decorative: bool, alt: string}  $choice              From {@see self::alt_choice()}.
	 * @param bool                                  $supports_decorative Core image has `isDecorative`.
	 * @return string
	 */
	public static function image_block( WP_Post $image, string $source, array $choice, bool $supports_decorative ): string {
		$attributes = array(
			'id'              => $image->ID,
			'sizeSlug'        => 'full',
			'linkDestination' => 'none',
		);
		$role       = '';
		if ( $choice['decorative'] && $supports_decorative ) {
			$attributes['isDecorative'] = true;
			$role                       = ' role="none"';
		}

		$markup = sprintf(
			'<figure class="wp-block-image size-full"><img src="%1$s" alt="%2$s" class="wp-image-%3$d"%4$s/>',
			esc_url( $source ),
			esc_attr( $choice['decorative'] ? '' : $choice['alt'] ),
			$image->ID,
			$role
		);
		if ( '' !== trim( $image->post_excerpt ) ) {
			$markup .= '<figcaption class="wp-element-caption">' . wp_kses_post( $image->post_excerpt ) . '</figcaption>';
		}
		$markup .= '</figure>';

		return get_comment_delimited_block_content( 'core/image', $attributes, $markup );
	}

	/**
	 * Mark a client-processed image pending when the REST API creates it
	 * without sub-sizes.
	 *
	 * @param WP_Post         $attachment Attachment just inserted.
	 * @param WP_REST_Request $request    Upload request.
	 * @param bool            $creating   True on create.
	 */
	public static function track_client_upload( WP_Post $attachment, WP_REST_Request $request, bool $creating ): void {
		if ( ! $creating || false !== $request->get_param( 'generate_sub_sizes' ) || ! wp_attachment_is_image( $attachment ) ) {
			return;
		}
		if ( ! function_exists( 'wp_is_client_side_media_processing_enabled' ) || ! wp_is_client_side_media_processing_enabled() ) {
			return;
		}
		update_post_meta( $attachment->ID, self::PENDING_META, '1' );
	}

	/**
	 * Clear the pending mark once `/wp/v2/media/<id>/finalize` succeeds.
	 *
	 * @param mixed                $response REST callback result.
	 * @param array<string, mixed> $handler  REST route handler.
	 * @param WP_REST_Request      $request  Current request.
	 * @return mixed
	 */
	public static function complete_client_upload( $response, array $handler, WP_REST_Request $request ) {
		if ( 'POST' === $request->get_method()
			&& $response instanceof WP_REST_Response
			&& 200 === $response->get_status()
			&& preg_match( '#^/wp/v2/media/([1-9][0-9]*)/finalize$#D', $request->get_route(), $matches ) ) {
			delete_post_meta( (int) $matches[1], self::PENDING_META );
		}
		return $response;
	}
}
