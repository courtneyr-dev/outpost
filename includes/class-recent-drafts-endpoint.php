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

	public static function permission_check( WP_REST_Request $request ): bool {
		return current_user_can( 'edit_posts' ) && self::bearer_has_scope( $request, array( 'update' ) );
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
				$update = array(
					'ID'         => $draft->ID,
					'post_title' => sanitize_text_field( (string) $request['title'] ),
				);
				if ( null !== $record['content'] && $request->has_param( 'content' ) && $record['content'] !== $request['content'] ) {
					$update['post_content'] = esc_html( sanitize_textarea_field( (string) $request['content'] ) );
				}
				$saved = wp_update_post( wp_slash( $update ), true );
				if ( is_wp_error( $saved ) ) {
					return $saved;
				}
				$draft = self::editable_draft( $saved, $user_id );
				if ( ! $draft ) {
					return new WP_Error( 'outpost_draft_unavailable', __( 'You cannot edit this draft in Outpost.', 'outpost-mobile-publishing' ), array( 'status' => 403 ) );
				}
				$record = self::format_draft( $draft );
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
		$is_text = ! has_blocks( $content ) && wp_strip_all_tags( $content ) === $content;
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
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'core/content-query' ) : null;
		if ( $ability ) {
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
