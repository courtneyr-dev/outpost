<?php
/**
 * Outpost_Library_Photos_Endpoint
 *
 * Lets the composer reuse an image that is already in the WordPress Media
 * Library. `POST /wp-json/outpost/v1/library-photos` does two things:
 *
 *   - Without `attachmentId`, it lists ready images the user may edit,
 *     newest first, optionally filtered by `search`, one page at a time:
 *     `offset` continues from the `nextOffset` the previous page returned.
 *     POST keeps the search text out of URLs and server logs.
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
 * at an image with no derivatives. An image whose metadata WordPress has not
 * written yet is refused too, which covers the moment between Core inserting
 * the attachment and the pending mark landing.
 *
 * ActivityPub (when active) describes an image block with an empty alt using
 * the attachment's shared alt. For an image the author marked decorative,
 * the federated object keeps the empty description instead.
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

	/** Post meta on a draft: attachment IDs the author marked decorative. */
	public const DECORATIVE_META = '_outpost_decorative_images';

	/** Images returned per list request. */
	private const LIST_LIMIT = 30;

	/** Attachment rows read per query while filling a page. */
	private const SCAN_BATCH = 60;

	/** Attachment rows one list request reads at most before handing back a cursor. */
	private const SCAN_CAP = 600;

	/**
	 * Hook the route and the client-upload tracking.
	 */
	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
		add_action( 'rest_after_insert_attachment', array( self::class, 'track_client_upload' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'complete_client_upload' ), 20, 3 );
		add_filter( 'activitypub_attachments', array( self::class, 'keep_decorative_alt_empty' ), 20, 2 );
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
					'offset'       => array(
						'type'    => 'integer',
						'minimum' => 0,
						'default' => 0,
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
	 * A token reaches this route only in an `Authorization: Bearer` header
	 * (or its `REDIRECT_HTTP_AUTHORIZATION` copy). IndieAuth also signs a
	 * request in from a form-encoded `access_token` field before the route
	 * runs, so a request carrying an `access_token` parameter is refused even
	 * beside an Authorization header, and one IndieAuth authenticated without
	 * a Bearer header is refused too. So is a
	 * request with no user and no Authorization header at all. Both get the
	 * same 401: the PWA always sends the header, so its absence means the
	 * web server dropped it, and the composer can say how to fix that
	 * instead of reporting a generic failure. A wp-admin cookie session with
	 * its REST nonce sends no token and is unaffected.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permission_check( WP_REST_Request $request ) {
		$header       = Outpost_Request_Headers::authorization();
		$header_token = 1 === preg_match( '/Bearer\s+\S/i', $header );
		$body_token   = null !== $request->get_param( 'access_token' );
		$token_signin = ! empty( function_exists( 'indieauth_get_response' ) ? indieauth_get_response() : null );
		// A body or query token is refused even beside a header: IndieAuth ignores some header forms and signs in from the body instead.
		if ( $body_token || ( 0 === get_current_user_id() && '' === $header ) || ( $token_signin && ! $header_token ) ) {
			return new WP_Error(
				'outpost_authorization_header_missing',
				__( 'WordPress did not receive the Authorization header. Ask your host to forward it to PHP (on Apache, add "CGIPassAuth On" to .htaccess).', 'outpost-mobile-publishing' ),
				array( 'status' => 401 )
			);
		}
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
		if ( $choice['decorative'] ) {
			// Lets the ActivityPub filter tell this decorative choice from an
			// alt someone left empty, on cores without `isDecorative`.
			add_post_meta( $id, self::DECORATIVE_META, $attachment->ID );
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
	 * List one page of ready images the user may edit, newest first.
	 *
	 * Rows are filtered for readiness and access before they count toward the
	 * page, so a run of pending or foreign images never ends the list early.
	 * `nextOffset` is the number of rows read through the page's last image,
	 * or null once no usable image remains. One request reads at most
	 * SCAN_CAP rows; past that it returns what it has with a cursor.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	private static function list_images( WP_REST_Request $request ): WP_REST_Response {
		$offset = max( 0, (int) $request->get_param( 'offset' ) );
		$search = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$author = current_user_can( 'edit_others_posts' ) ? 0 : get_current_user_id();
		$items  = array();
		$next   = null;
		$read   = 0;

		while ( null === $next && $read < self::SCAN_CAP ) {
			$ids = get_posts(
				array(
					'post_type'        => 'attachment',
					'post_status'      => 'inherit',
					'post_mime_type'   => 'image',
					'author'           => $author,
					'fields'           => 'ids',
					'orderby'          => array(
						'date' => 'DESC',
						'ID'   => 'DESC',
					),
					'posts_per_page'   => self::SCAN_BATCH,
					'offset'           => $offset + $read,
					'no_found_rows'    => true,
					'suppress_filters' => false,
					's'                => $search,
				)
			);
			foreach ( $ids as $id ) {
				++$read;
				$image = self::usable_image( (int) $id );
				if ( null === $image ) {
					continue;
				}
				if ( count( $items ) === self::LIST_LIMIT ) {
					// One more usable image exists: the next page starts at it.
					$next = $offset + $read - 1;
					break;
				}
				$items[] = self::list_item( $image );
			}
			if ( count( $ids ) < self::SCAN_BATCH ) {
				break;
			}
		}
		if ( null === $next && $read >= self::SCAN_CAP ) {
			$next = $offset + $read;
		}
		return new WP_REST_Response(
			array(
				'items'      => $items,
				'nextOffset' => $next,
			)
		);
	}

	/**
	 * One image as the composer lists it.
	 *
	 * @param WP_Post $image Usable attachment.
	 * @return array<string, mixed>
	 */
	private static function list_item( WP_Post $image ): array {
		$thumbnail = wp_get_attachment_image_url( $image->ID, 'thumbnail' );
		return array(
			'id'          => $image->ID,
			'title'       => $image->post_title,
			'caption'     => wp_strip_all_tags( $image->post_excerpt ),
			'alt'         => (string) get_post_meta( $image->ID, '_wp_attachment_image_alt', true ),
			'embeddedAlt' => self::embedded_alt( $image->ID ),
			'thumbnail'   => is_string( $thumbnail ) ? $thumbnail : null,
			'editUrl'     => get_edit_post_link( $image->ID, 'raw' ),
		);
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
	 * attribute (7.1.5 and 7.2 do). Versions without it express decorative
	 * only as `alt=""`; sending the attribute and `role="none"` there would
	 * make the editor flag the block invalid.
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
	 * user can edit the image. Finished means WordPress has written its
	 * metadata (Core does that after `rest_after_insert_attachment`, where the
	 * pending mark is set) and no finalize is outstanding. A small image that
	 * needed no sub-sizes has metadata with an empty `sizes` and stays usable.
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
		$metadata = wp_get_attachment_metadata( $id );
		if ( ! is_array( $metadata ) || empty( $metadata['file'] ) || (int) ( $metadata['width'] ?? 0 ) <= 0 ) {
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
	 * Clear the pending mark once Core's finalize succeeds.
	 *
	 * Core authorizes and finalizes `$request['id']` (the same value as
	 * `get_param( 'id' )`), which WordPress reads from the body or query
	 * string before the URL, so the ID in the route
	 * may not be the attachment Core finalized. The mark is cleared only when
	 * the handler is Core's finalize and both IDs agree.
	 *
	 * @param mixed                $response REST callback result.
	 * @param array<string, mixed> $handler  REST route handler.
	 * @param WP_REST_Request      $request  Current request.
	 * @return mixed
	 */
	public static function complete_client_upload( $response, array $handler, WP_REST_Request $request ) {
		if ( 'POST' !== $request->get_method()
			|| ! $response instanceof WP_REST_Response
			|| 200 !== $response->get_status()
			|| ! preg_match( '#^/wp/v2/media/([1-9][0-9]*)/finalize$#D', $request->get_route(), $matches ) ) {
			return $response;
		}
		$callback = $handler['callback'] ?? null;
		if ( ! is_array( $callback )
			|| ! ( $callback[0] ?? null ) instanceof WP_REST_Attachments_Controller
			|| 'finalize_item' !== ( $callback[1] ?? null ) ) {
			return $response;
		}
		$finalized = (int) $request->get_param( 'id' );
		if ( $finalized === (int) $matches[1] ) {
			delete_post_meta( $finalized, self::PENDING_META );
		}
		return $response;
	}

	/**
	 * Keep a decorative image's description empty in ActivityPub output.
	 *
	 * ActivityPub 9.4 falls back to the attachment's shared alt when a block's
	 * alt is empty, so a decorative choice would federate whatever
	 * description the image carries elsewhere. For each image the post marks
	 * decorative (core's `isDecorative`, or the choice Outpost recorded on
	 * the draft for a block with `alt=""`), the matching attachment's `name`
	 * is set to ''. The attachment itself is not changed.
	 *
	 * When a post shows the same attachment more than once, ActivityPub sends
	 * the alt of one use (Outpost_Image_Block_Uses::activitypub_selected()),
	 * so only that use decides: a decorative first use followed by a
	 * described one keeps the description.
	 *
	 * ActivityPub builds each image URL from `wp_get_attachment_image_src()`
	 * at `large`, passed through `activitypub_get_image`; matching uses the
	 * same URL.
	 *
	 * @param mixed $attachments ActivityPub attachment objects.
	 * @param mixed $post        Post being transformed.
	 * @return mixed
	 */
	public static function keep_decorative_alt_empty( $attachments, $post ) {
		if ( ! is_array( $attachments ) || ! $post instanceof WP_Post ) {
			return $attachments;
		}
		$recorded = array_map( 'intval', (array) get_post_meta( $post->ID, self::DECORATIVE_META, false ) );
		$urls     = array();
		foreach ( Outpost_Image_Block_Uses::activitypub_selected( (string) $post->post_content ) as $use ) {
			$decorative = $use['decorative'] || ( '' === $use['alt'] && in_array( $use['id'], $recorded, true ) );
			if ( ! $decorative ) {
				continue;
			}
			$image = apply_filters( 'activitypub_get_image', wp_get_attachment_image_src( $use['id'], 'large' ), $use['id'], 'large' );
			if ( is_array( $image ) && isset( $image[0] ) && is_string( $image[0] ) ) {
				$urls[] = esc_url_raw( $image[0] );
			}
		}
		if ( array() === $urls ) {
			return $attachments;
		}
		foreach ( $attachments as $key => $attachment ) {
			if ( is_array( $attachment )
				&& 'Image' === ( $attachment['type'] ?? '' )
				&& in_array( $attachment['url'] ?? null, $urls, true ) ) {
				$attachments[ $key ]['name'] = '';
			}
		}
		return $attachments;
	}
}
