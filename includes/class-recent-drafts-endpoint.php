<?php
/**
 * Authenticated access to the composer's recent drafts.
 *
 * @package Outpost
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Recent_Drafts_Endpoint {

	use Outpost_Bearer_Auth;

	private const PAGE_SIZE = 10;

	/** Newest first; equal dates fall back to the newest ID so pages never overlap. */
	private const ORDER = array(
		'date' => 'DESC',
		'ID'   => 'DESC',
	);

	/** Fields WordPress runs through the saving user's filters on every update. */
	private const FILTERED_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_content_filtered' );

	public static function register(): void {
		add_action( 'rest_api_init', array( self::class, 'register_route' ) );
	}

	public static function register_route(): void {
		register_rest_route(
			'outpost/v1',
			'/recent-drafts',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( self::class, 'handle' ),
				'permission_callback' => array( self::class, 'permission_check' ),
				'show_in_index'       => false,
				'args'                => array(
					'page'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 10000,
						'default' => 1,
					),
					'id'      => array(
						'type'    => 'integer',
						'minimum' => 1,
					),
					'title'   => array( 'type' => 'string' ),
					'content' => array( 'type' => 'string' ),
					'version' => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Credential first, then capability, each refusing where it runs.
	 *
	 * The picker edits existing drafts, so a token needs the `update` scope.
	 * A cookie session needs a valid REST nonce. Per-draft ownership is
	 * checked in the callback, where the draft ID is known.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool Whether the request may list or edit its own drafts.
	 */
	public static function permission_check( WP_REST_Request $request ): bool {
		if ( ! self::bearer_has_scope( $request, array( 'update' ) ) ) {
			return false;
		}
		return current_user_can( 'edit_posts' );
	}

	/** @return WP_REST_Response|WP_Error */
	public static function handle( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $request->has_param( 'id' ) ) {
			$draft = self::editable_draft( (int) $request['id'], $user_id );
			if ( ! $draft ) {
				return new WP_Error( 'outpost_draft_unavailable', __( 'You cannot edit this draft in Outpost.', 'outpost-mobile-publishing' ), array( 'status' => 403 ) );
			}

			$record = self::format_draft( $draft );
			if ( $request->has_param( 'title' ) ) {
				if ( $record['version'] !== $request['version'] ) {
					return new WP_Error( 'outpost_draft_changed', __( 'The draft has changed. Reopen it before saving.', 'outpost-mobile-publishing' ), array( 'status' => 409 ) );
				}
				// The picker sends both fields back on every save. Write only
				// the ones the author changed.
				$fields = array();
				if ( (string) $request['title'] !== $draft->post_title ) {
					$fields['post_title'] = self::plain_text( (string) $request['title'] );
				}
				if ( null !== $record['content'] && $request->has_param( 'content' ) && $record['content'] !== $request['content'] ) {
					$fields['post_content'] = htmlspecialchars( wp_check_invalid_utf8( (string) $request['content'] ), ENT_QUOTES, 'UTF-8' );
				}
				if ( $fields ) {
					if ( ! self::save_fields( $draft, $fields ) ) {
						return new WP_Error( 'outpost_draft_save_failed', __( 'WordPress did not save the draft. Try again.', 'outpost-mobile-publishing' ), array( 'status' => 500 ) );
					}
					$draft = self::editable_draft( $draft->ID, $user_id );
					if ( ! $draft ) {
						return new WP_Error( 'outpost_draft_unavailable', __( 'You cannot edit this draft in Outpost.', 'outpost-mobile-publishing' ), array( 'status' => 403 ) );
					}
					$record = self::format_draft( $draft );
				}
			}
			$result = $record;
		} else {
			$result = self::query_drafts( $user_id, (int) $request['page'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$response = new WP_REST_Response( $result );
		$response->header( 'Cache-Control', 'private, no-store' );
		return $response;
	}

	/**
	 * Plain text as typed: tags removed and whitespace collapsed, as
	 * sanitize_text_field() does, but percent sequences such as %20 kept.
	 */
	private static function plain_text( string $value ): string {
		$text = wp_check_invalid_utf8( $value );
		if ( str_contains( $text, '<' ) ) {
			$text = wp_strip_all_tags( wp_pre_kses_less_than( $text ) );
		}
		return trim( (string) preg_replace( '/[\r\n\t ]+/', ' ', $text ) );
	}

	/**
	 * Update only the given fields through wp_update_post().
	 *
	 * wp_update_post() merges the stored post and runs every text field
	 * through the saving user's filters, so an author without
	 * unfiltered_html would strip an editor's iframe from content they never
	 * touched. Each untouched field is put back exactly as stored.
	 *
	 * @param array{post_title?: string, post_content?: string} $fields Unslashed fields to write.
	 */
	private static function save_fields( WP_Post $draft, array $fields ): bool {
		$keep_untouched = static function ( array $data, array $postarr ) use ( $draft, $fields ): array {
			if ( (int) ( $postarr['ID'] ?? 0 ) !== $draft->ID ) {
				return $data;
			}
			foreach ( self::FILTERED_FIELDS as $field ) {
				if ( ! array_key_exists( $field, $fields ) ) {
					$data[ $field ] = wp_slash( $draft->$field );
				}
			}
			return $data;
		};
		// With WP_DEBUG and WP_DEBUG_DISPLAY on a single site, wpdb prints a
		// failed query and its SQL into the REST response body.
		global $wpdb;
		$show_errors = $wpdb->hide_errors();
		add_filter( 'wp_insert_post_data', $keep_untouched, PHP_INT_MAX, 2 );
		try {
			$postarr = array( 'ID' => $draft->ID );
			foreach ( $fields as $field => $value ) {
				$postarr[ $field ] = wp_slash( $value );
			}
			$saved = wp_update_post( $postarr, true );
		} finally {
			remove_filter( 'wp_insert_post_data', $keep_untouched, PHP_INT_MAX );
			$wpdb->show_errors( $show_errors );
		}
		return ! is_wp_error( $saved ) && $saved > 0;
	}

	private static function editable_draft( int $id, int $user_id ): ?WP_Post {
		$post = get_post( $id );
		if ( ! $post || 'post' !== $post->post_type || 'draft' !== $post->post_status ||
			! current_user_can( 'edit_post', $post->ID ) || $user_id !== (int) $post->post_author ) {
			return null;
		}
		return $post;
	}

	/** @return array{id: int, title: string, content: string|null, editUrl: string|null, version: string} */
	private static function format_draft( WP_Post $draft ): array {
		$content = $draft->post_content;
		// wp_strip_all_tags() trims, so compare against the trimmed body and
		// return the body with its edge whitespace.
		$is_text = ! has_blocks( $content ) && wp_strip_all_tags( $content ) === trim( $content );
		return array(
			'id'      => $draft->ID,
			'title'   => $draft->post_title,
			'content' => $is_text ? html_entity_decode( $content, ENT_QUOTES, 'UTF-8' ) : null,
			'editUrl' => get_edit_post_link( $draft, 'raw' ),
			'version' => hash( 'sha256', $draft->post_title . "\0" . $content . "\0" . $draft->post_modified_gmt ),
		);
	}

	/** @return array{items: list<array{id: int, title: string}>, hasNext: bool}|WP_Error */
	private static function query_drafts( int $user_id, int $page ) {
		// Core 7.2 ships core/content-query. Older Core keeps the WP_Query path,
		// and wp_get_ability() on an unregistered name raises _doing_it_wrong().
		$ability = function_exists( 'wp_has_ability' ) && wp_has_ability( 'core/content-query' ) ? wp_get_ability( 'core/content-query' ) : null;
		if ( $ability ) {
			// The ability takes no order input and sorts by date alone.
			$order = static function ( WP_Query $query ): void {
				$query->set( 'orderby', self::ORDER );
			};
			add_action( 'pre_get_posts', $order );
			try {
				$result = $ability->execute(
					array(
						'where'      => array(
							'type'   => 'post',
							'status' => array( 'draft' ),
							'author' => array( 'id' => $user_id ),
						),
						'fields'     => array( 'id' ),
						'pagination' => array(
							'page'     => $page,
							'per_page' => self::PAGE_SIZE,
						),
					)
				);
			} finally {
				remove_action( 'pre_get_posts', $order );
			}
			// A page past the last one is an empty page, as on the WP_Query path.
			if ( is_wp_error( $result ) && 'content_invalid_page_number' === $result->get_error_code() ) {
				return array(
					'items'   => array(),
					'hasNext' => false,
				);
			}
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$ids         = wp_list_pluck( $result['items'], 'id' );
			$total_pages = $result['pagination']['total_pages'];
		} else {
			$query       = new WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'draft',
					'author'         => $user_id,
					'paged'          => $page,
					'posts_per_page' => self::PAGE_SIZE,
					'orderby'        => self::ORDER,
					'fields'         => 'ids',
				)
			);
			$ids         = $query->posts;
			$total_pages = $query->max_num_pages;
		}

		$items = array();
		foreach ( $ids as $id ) {
			$draft = self::editable_draft( (int) $id, $user_id );
			if ( $draft ) {
				$items[] = array(
					'id'    => $draft->ID,
					'title' => $draft->post_title,
				);
			}
		}
		return array(
			'items'   => $items,
			'hasNext' => $page < $total_pages,
		);
	}
}
