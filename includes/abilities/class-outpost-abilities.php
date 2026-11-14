<?php
/**
 * Native WordPress abilities exposed by Outpost.
 *
 * @package Outpost
 */

declare(strict_types=1);

// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Outpost_Abilities {

	use Outpost_Bearer_Auth;

	private const CATEGORY = 'outpost';

	/** Token scopes the matching read routes accept. */
	private const READ_SCOPES = array( 'create', 'update', 'read' );

	/**
	 * The REST requests whose handlers are running, innermost last.
	 *
	 * @var array<int, WP_REST_Request>
	 */
	private static array $requests = array();

	/**
	 * Hook category and ability registration into the Abilities API lifecycle.
	 */
	public static function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( self::class, 'register_category' ) );
		add_action( 'wp_abilities_api_init', array( self::class, 'register_abilities' ) );
		add_filter( 'rest_request_before_callbacks', array( self::class, 'enter_request' ), 10, 3 );
		add_filter( 'rest_request_after_callbacks', array( self::class, 'leave_request' ), 10, 3 );
	}

	/**
	 * Remember the request whose permission callback core is about to run,
	 * so the credential gate sees the same headers and body the routes see.
	 *
	 * @param mixed           $response Response so far (passed through).
	 * @param array<string, mixed> $handler Route handler (unused).
	 * @param WP_REST_Request $request  The request being handled.
	 * @return mixed
	 */
	public static function enter_request( $response, $handler, $request ) {
		unset( $handler );
		if ( $request instanceof WP_REST_Request ) {
			self::$requests[] = $request;
		}
		return $response;
	}

	/**
	 * Forget the request once its handler finished.
	 *
	 * @param mixed           $response Response (passed through).
	 * @param array<string, mixed> $handler Route handler (unused).
	 * @param WP_REST_Request $request  The request that was handled.
	 * @return mixed
	 */
	public static function leave_request( $response, $handler, $request ) {
		unset( $handler );
		if ( $request instanceof WP_REST_Request && end( self::$requests ) === $request ) {
			array_pop( self::$requests );
		}
		return $response;
	}

	/**
	 * Register the Outpost ability category when the API is available.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			array(
				'label'       => __( 'Outpost', 'outpost-mobile-publishing' ),
				'description' => __( 'Read publishing and syndication information from Outpost.', 'outpost-mobile-publishing' ),
			)
		);
	}

	/**
	 * Register all Outpost abilities when the API is available.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		self::register_get_syndicate_targets();
		self::register_get_share_status();
		self::register_get_composer_config();
	}

	/**
	 * Register the mode-aware syndication target reader.
	 */
	private static function register_get_syndicate_targets(): void {
		wp_register_ability(
			'outpost/get-syndicate-targets',
			array(
				'label'               => __( 'Get syndication targets', 'outpost-mobile-publishing' ),
				'description'         => __( 'Returns the available syndication targets for an optional composer mode.', 'outpost-mobile-publishing' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'mode' => array( 'type' => 'string' ),
					),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'mode_requested'  => array( 'type' => array( 'string', 'null' ) ),
						'mode_applied'    => array( 'type' => array( 'string', 'null' ) ),
						'mode_recognized' => array( 'type' => 'boolean' ),
						'chips'           => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'mode_requested', 'mode_applied', 'mode_recognized', 'chips' ),
				),
				'execute_callback'    => array( self::class, 'execute_get_syndicate_targets' ),
				'permission_callback' => array( self::class, 'can_read_outpost' ),
				'meta'                => self::read_meta(),
			)
		);
	}

	/**
	 * Register the per-post manual-share status reader.
	 */
	private static function register_get_share_status(): void {
		wp_register_ability(
			'outpost/get-share-status',
			array(
				'label'               => __( 'Get share status', 'outpost-mobile-publishing' ),
				'description'         => __( 'Returns the manual-share status and log for one post.', 'outpost-mobile-publishing' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
					'required'             => array( 'post_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id' => array( 'type' => 'integer' ),
						'status'  => array( 'type' => 'string' ),
						'summary' => array( 'type' => 'object' ),
						'entries' => array(
							'type'  => 'array',
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'post_id', 'status', 'summary', 'entries' ),
				),
				'execute_callback'    => array( self::class, 'execute_get_share_status' ),
				'permission_callback' => array( self::class, 'can_get_share_status' ),
				'meta'                => self::read_meta(),
			)
		);
	}

	/**
	 * Register the composer configuration reader.
	 */
	private static function register_get_composer_config(): void {
		wp_register_ability(
			'outpost/get-composer-config',
			array(
				'label'               => __( 'Get composer configuration', 'outpost-mobile-publishing' ),
				'description'         => __( 'Returns the local configuration used by the Outpost composer.', 'outpost-mobile-publishing' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(),
					'additionalProperties' => false,
					'default'              => array(),
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'companions'         => array( 'type' => 'object' ),
						'postFormats'        => array(
							'type'  => array( 'array', 'null' ),
							'items' => array( 'type' => 'string' ),
						),
						'xfnRels'            => array(
							'type'  => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'existingCategories' => self::term_list_schema(),
						'existingTags'       => self::term_list_schema(),
						'bridgyHostMap'      => array( 'type' => 'object' ),
						'siteSettings'       => array(
							'type'       => 'object',
							'properties' => array(
								'bridgyAutoSuggest'  => array( 'type' => 'boolean' ),
								'defaultPostVariant' => array( 'type' => 'string' ),
								'defaultCategories'  => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'defaultTags'        => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
							),
							'required'   => array( 'bridgyAutoSuggest', 'defaultPostVariant', 'defaultCategories', 'defaultTags' ),
						),
					),
					'required'   => array( 'companions', 'postFormats', 'xfnRels', 'existingCategories', 'existingTags', 'bridgyHostMap', 'siteSettings' ),
				),
				'execute_callback'    => array( self::class, 'execute_get_composer_config' ),
				'permission_callback' => array( self::class, 'can_read_outpost' ),
				'meta'                => self::read_meta(),
			)
		);
	}

	/**
	 * Execute the syndication target reader.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array{mode_requested: string|null, mode_applied: string|null, mode_recognized: bool, chips: array<int, array<string, mixed>>}
	 */
	public static function execute_get_syndicate_targets( array $input ): array {
		$mode = isset( $input['mode'] ) && is_string( $input['mode'] )
			? $input['mode']
			: null;

		return Outpost_Syndicate_Targets_Endpoint::build_payload( $mode );
	}

	/**
	 * Execute the manual-share status reader.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array{post_id: int, status: string, summary: array{total: int, complete: int, pending: int, abandoned: int}, entries: array<int, array<string, mixed>>}
	 */
	public static function execute_get_share_status( array $input ): array {
		return Outpost_Manual_Share_Status_Controller::build_status_payload( (int) $input['post_id'] );
	}

	/**
	 * Execute the composer configuration reader.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array{
	 *     companions: array<string, string>,
	 *     postFormats: array<int, string>|null,
	 *     xfnRels: array<int, string>,
	 *     existingCategories: array<int, array{slug: string, name: string}>,
	 *     existingTags: array<int, array{slug: string, name: string}>,
	 *     bridgyHostMap: array<string, array{name: string, uid: string}>,
	 *     siteSettings: array{
	 *         bridgyAutoSuggest: bool,
	 *         defaultPostVariant: string,
	 *         defaultCategories: array<int, string>,
	 *         defaultTags: array<int, string>
	 *     }
	 * }
	 */
	public static function execute_get_composer_config( array $input ): array {
		unset( $input );
		return Outpost_Composer_Config_Endpoint::build_payload();
	}

	/**
	 * Check the gate the matching read routes apply: edit_posts, plus the
	 * credential check every Outpost route makes (a cookie session needs a
	 * valid REST nonce; a token needs create, update or read scope). Core's
	 * Abilities REST controller calls only this callback, so it can't be
	 * weaker than the routes (F5-01). Inside a REST request it checks that
	 * request, body token included; a PHP caller with no cookie session and
	 * no token keeps the capability check alone.
	 *
	 * @return bool
	 */
	public static function can_read_outpost(): bool {
		$request = array() === self::$requests ? new WP_REST_Request( 'GET' ) : end( self::$requests );
		return current_user_can( 'edit_posts' ) && self::bearer_has_scope( $request, self::READ_SCOPES );
	}

	/**
	 * Check per-post access for the share-status reader: the read gate the
	 * status route applies, plus edit_post on that post.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return bool
	 */
	public static function can_get_share_status( array $input ): bool {
		if ( ! isset( $input['post_id'] ) || ! is_int( $input['post_id'] ) || $input['post_id'] < 1 ) {
			return false;
		}

		$post_id = $input['post_id'];
		if ( null === get_post( $post_id ) ) {
			return false;
		}

		return self::can_read_outpost() && current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Shared metadata for public, read-only abilities.
	 *
	 * @return array{show_in_rest: bool, annotations: array{readonly: bool, destructive: bool, idempotent: bool}, mcp: array{public: bool}}
	 */
	private static function read_meta(): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'mcp'          => array( 'public' => true ),
		);
	}

	/**
	 * Schema for a list of taxonomy term identifiers and names.
	 *
	 * @return array<string, mixed>
	 */
	private static function term_list_schema(): array {
		return array(
			'type'  => 'array',
			'items' => array(
				'type'       => 'object',
				'properties' => array(
					'slug' => array( 'type' => 'string' ),
					'name' => array( 'type' => 'string' ),
				),
				'required'   => array( 'slug', 'name' ),
			),
		);
	}
}
