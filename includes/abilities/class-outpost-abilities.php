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

	/** Token scopes the matching write routes accept. */
	private const WRITE_SCOPES = array( 'create', 'update' );

	public const OPTION_MANUAL_SHARE        = 'outpost_ability_manual_share';
	public const OPTION_CAPTURE_SYNDICATION = 'outpost_ability_capture_syndication';

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
		// Core's rest_send_allow_header() (rest_post_dispatch, priority 10)
		// runs the permission callback again; keep the request in view.
		add_filter( 'rest_post_dispatch', array( self::class, 'enter_request' ), 9, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'leave_request' ), 11, 3 );
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
	 * Forget the request once its handler finished, with anything tracked
	 * after it: a nested request whose handler threw never reached its own
	 * leave, and it must not outlive the request that started it.
	 *
	 * @param mixed           $response Response (passed through).
	 * @param array<string, mixed> $handler Route handler (unused).
	 * @param WP_REST_Request $request  The request that was handled.
	 * @return mixed
	 */
	public static function leave_request( $response, $handler, $request ) {
		unset( $handler );
		if ( ! $request instanceof WP_REST_Request ) {
			return $response;
		}
		// The last frame for this request: the same object can be handled
		// again while it's still being handled (re-entrant dispatch).
		for ( $index = count( self::$requests ) - 1; $index >= 0; $index-- ) {
			if ( self::$requests[ $index ] === $request ) {
				array_splice( self::$requests, $index );
				break;
			}
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
		self::register_manual_share();
		self::register_capture_syndication();
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
	 * Register the switched manual-share writer.
	 */
	private static function register_manual_share(): void {
		wp_register_ability(
			'outpost/manual-share',
			array(
				'label'               => __( 'Prepare a manual share', 'outpost-mobile-publishing' ),
				'description'         => __( 'Builds a device-specific manual-share payload and records it in the post share log.', 'outpost-mobile-publishing' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'     => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'platform_id' => array( 'type' => 'string' ),
						'device'      => array(
							'type'    => 'string',
							'enum'    => array( 'android', 'ios', 'desktop' ),
							'default' => 'desktop',
						),
						'in_pwa_mode' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'dry_run'     => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'post_id', 'platform_id' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'     => array( 'type' => 'integer' ),
						'platform_id' => array( 'type' => 'string' ),
						'device'      => array(
							'type' => 'string',
							'enum' => array( 'android', 'ios', 'desktop' ),
						),
						'dry_run'     => array( 'type' => 'boolean' ),
						'written'     => array( 'type' => 'boolean' ),
						'payload'     => array( 'type' => array( 'object', 'null' ) ),
					),
					'required'   => array( 'post_id', 'platform_id', 'device', 'dry_run', 'written', 'payload' ),
				),
				'execute_callback'    => array( self::class, 'execute_manual_share' ),
				'permission_callback' => array( self::class, 'can_manual_share' ),
				'meta'                => self::write_meta( false ),
			)
		);
	}

	/**
	 * Register the switched syndication-capture writer.
	 */
	private static function register_capture_syndication(): void {
		wp_register_ability(
			'outpost/capture-syndication',
			array(
				'label'               => __( 'Capture a syndication link', 'outpost-mobile-publishing' ),
				'description'         => __( 'Records a silo URL as a post syndication link and completes its share-log entry.', 'outpost-mobile-publishing' ),
				'category'            => self::CATEGORY,
				'input_schema'        => array(
					'type'                 => 'object',
					'properties'           => array(
						'post_id'          => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
						'audit_log_id'     => array( 'type' => 'string' ),
						'silo_url'         => array( 'type' => 'string' ),
						'confirm_mismatch' => array(
							'type'    => 'boolean',
							'default' => false,
						),
						'dry_run'          => array(
							'type'    => 'boolean',
							'default' => true,
						),
					),
					'required'             => array( 'post_id', 'audit_log_id', 'silo_url' ),
					'additionalProperties' => false,
				),
				'output_schema'       => array(
					'type'       => 'object',
					'properties' => array(
						'status'            => array(
							'type' => 'string',
							'enum' => array( 'recorded', 'mismatch_warning', 'dry_run' ),
						),
						'post_id'           => array( 'type' => 'integer' ),
						'audit_log_id'      => array( 'type' => 'string' ),
						'platform_id'       => array( 'type' => 'string' ),
						'silo_url'          => array( 'type' => 'string' ),
						'written'           => array( 'type' => 'boolean' ),
						'syndication_links' => array(
							'type'  => array( 'array', 'null' ),
							'items' => array( 'type' => 'object' ),
						),
					),
					'required'   => array( 'status', 'post_id', 'audit_log_id', 'platform_id', 'silo_url', 'written', 'syndication_links' ),
				),
				'execute_callback'    => array( self::class, 'execute_capture_syndication' ),
				'permission_callback' => array( self::class, 'can_capture_syndication' ),
				'meta'                => self::write_meta( true ),
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
	 * Execute a manual share without dispatching through REST.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_manual_share( array $input ) {
		if ( ! self::ability_is_enabled( self::OPTION_MANUAL_SHARE ) ) {
			return self::disabled_error();
		}

		$post_id     = (int) $input['post_id'];
		$platform_id = (string) $input['platform_id'];
		$device      = isset( $input['device'] ) ? (string) $input['device'] : Outpost_Manual_Share_Controller::PLATFORM_DESKTOP;
		$in_pwa_mode = isset( $input['in_pwa_mode'] ) && true === $input['in_pwa_mode'];
		$dry_run     = self::is_dry_run( $input );
		$platform    = self::find_platform( $platform_id );

		if ( ! $platform instanceof Outpost_Manual_Share_Platform_Config ) {
			return new WP_Error(
				'outpost_unknown_platform_id',
				sprintf(
					/* translators: %s: platform id supplied by the caller. */
					__( 'Unknown manual-share platform: %s', 'outpost-mobile-publishing' ),
					$platform_id
				),
				array( 'status' => 400 )
			);
		}

		$result = array(
			'post_id'     => $post_id,
			'platform_id' => $platform_id,
			'device'      => $device,
			'dry_run'     => $dry_run,
			'written'     => false,
			'payload'     => null,
		);
		if ( $dry_run ) {
			return $result;
		}

		$entries_before = count( Outpost_Manual_Share_Audit_Log::get_entries( $post_id ) );
		if ( Outpost_Manual_Share_Controller::PLATFORM_ANDROID === $device ) {
			$result['payload'] = Outpost_Manual_Share_Intent_Payload_Builder::build_for_android( $platform, $post_id );
		} elseif ( Outpost_Manual_Share_Controller::PLATFORM_IOS === $device ) {
			$result['payload'] = Outpost_Manual_Share_Intent_Payload_Builder::build_for_ios( $platform, $post_id, $in_pwa_mode );
		} else {
			$result['payload'] = Outpost_Manual_Share_Intent_Payload_Builder::build_stub_response( $platform_id, $post_id );
		}

		$entries_after     = count( Outpost_Manual_Share_Audit_Log::get_entries( $post_id ) );
		$result['written'] = $entries_after > $entries_before;
		return $result;
	}

	/**
	 * Execute the syndication writeback flow without dispatching through REST.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return array<string, mixed>|WP_Error
	 */
	public static function execute_capture_syndication( array $input ) {
		if ( ! self::ability_is_enabled( self::OPTION_CAPTURE_SYNDICATION ) ) {
			return self::disabled_error();
		}

		$post_id          = (int) $input['post_id'];
		$audit_log_id     = trim( (string) $input['audit_log_id'] );
		$silo_url         = trim( (string) $input['silo_url'] );
		$confirm_mismatch = isset( $input['confirm_mismatch'] ) && true === $input['confirm_mismatch'];
		$dry_run          = self::is_dry_run( $input );
		if ( '' === $audit_log_id ) {
			return new WP_Error(
				'outpost_invalid_audit_log_id',
				__( 'audit_log_id is required.', 'outpost-mobile-publishing' ),
				array( 'status' => 400 )
			);
		}
		$validity = Outpost_Manual_Share_Syndication_Writeback::validate_url( $silo_url );
		if ( is_wp_error( $validity ) ) {
			return new WP_Error(
				'outpost_' . $validity->get_error_code(),
				$validity->get_error_message(),
				array( 'status' => 400 )
			);
		}

		$entry = self::find_audit_entry( $post_id, $audit_log_id );
		if ( null === $entry ) {
			return new WP_Error(
				'outpost_audit_log_entry_not_found',
				__( 'No matching audit log entry.', 'outpost-mobile-publishing' ),
				array( 'status' => 404 )
			);
		}

		$platform_id = (string) ( $entry['platform_id'] ?? '' );
		$result      = array(
			'status'            => 'dry_run',
			'post_id'           => $post_id,
			'audit_log_id'      => $audit_log_id,
			'platform_id'       => $platform_id,
			'silo_url'          => $silo_url,
			'written'           => false,
			'syndication_links' => null,
		);

		$mismatch = Outpost_Manual_Share_Syndication_Writeback::is_platform_mismatch( $platform_id, $silo_url );
		if ( $mismatch && ! $confirm_mismatch ) {
			$result['status'] = 'mismatch_warning';
			return $result;
		}

		if ( $dry_run ) {
			return $result;
		}

		$result['syndication_links'] = Outpost_Manual_Share_Syndication_Writeback::add_or_update_link(
			$post_id,
			$platform_id,
			$silo_url
		);
		Outpost_Manual_Share_Audit_Log::update_entry(
			$post_id,
			$audit_log_id,
			array(
				'silo_url'     => $silo_url,
				'completed_at' => gmdate( 'c' ),
				'outcome'      => Outpost_Manual_Share_Audit_Log::OUTCOME_FIRED,
			)
		);
		$result['status']  = 'recorded';
		$result['written'] = true;

		return $result;
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
		if ( ! self::bearer_has_scope( $request, self::READ_SCOPES ) ) {
			return false;
		}
		return current_user_can( 'edit_posts' );
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

		if ( ! self::can_read_outpost() ) {
			return false;
		}
		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Check whether the caller may prepare a manual share.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return bool|WP_Error
	 */
	public static function can_manual_share( array $input ) {
		return self::can_write_post( self::OPTION_MANUAL_SHARE, $input );
	}

	/**
	 * Check whether the caller may capture a syndication link.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 * @return bool|WP_Error
	 */
	public static function can_capture_syndication( array $input ) {
		return self::can_write_post( self::OPTION_CAPTURE_SYNDICATION, $input );
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
	 * Shared metadata for switched write abilities.
	 *
	 * @return array{show_in_rest: bool, annotations: array{readonly: bool, destructive: bool, idempotent: bool}, mcp: array{public: bool}}
	 */
	private static function write_meta( bool $idempotent ): array {
		return array(
			'show_in_rest' => true,
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => false,
				'idempotent'  => $idempotent,
			),
			'mcp'          => array( 'public' => false ),
		);
	}

	/**
	 * Check the switch, write credential, post existence, and capability.
	 *
	 * @param string               $option Option controlling the ability.
	 * @param array<string, mixed> $input  Validated ability input.
	 * @return bool|WP_Error
	 */
	private static function can_write_post( string $option, array $input ) {
		if ( ! self::ability_is_enabled( $option ) ) {
			return self::disabled_error();
		}

		$request = array() === self::$requests ? new WP_REST_Request( 'POST' ) : end( self::$requests );
		if ( ! self::bearer_has_scope( $request, self::WRITE_SCOPES ) ) {
			return false;
		}

		if ( ! isset( $input['post_id'] ) || ! is_int( $input['post_id'] ) || $input['post_id'] < 1 ) {
			return false;
		}

		$post_id = $input['post_id'];
		if ( null === get_post( $post_id ) ) {
			return false;
		}

		return current_user_can( 'edit_post', $post_id );
	}

	/**
	 * Whether a switched ability is enabled by its stored boolean value.
	 */
	public static function ability_is_enabled( string $option ): bool {
		$value = get_option( $option, false );
		return in_array( $value, array( true, 1, '1' ), true );
	}

	/**
	 * Whether a write should stay a dry run. Core validates `dry_run` as a
	 * boolean but doesn't cast it, so "true", "1" and 1 arrive as given;
	 * only an explicit false value turns the write on.
	 *
	 * @param array<string, mixed> $input Validated ability input.
	 */
	private static function is_dry_run( array $input ): bool {
		if ( ! array_key_exists( 'dry_run', $input ) ) {
			return true;
		}
		$value = is_string( $input['dry_run'] ) ? strtolower( $input['dry_run'] ) : $input['dry_run'];
		return ! in_array( $value, array( false, 0, '0', 'false' ), true );
	}

	private static function disabled_error(): WP_Error {
		return new WP_Error(
			'outpost_ability_disabled',
			__( 'This Outpost ability is disabled.', 'outpost-mobile-publishing' ),
			array( 'status' => 403 )
		);
	}

	private static function find_platform( string $platform_id ): ?Outpost_Manual_Share_Platform_Config {
		foreach ( Outpost_Manual_Share_Platform_Registry::all_platforms() as $platform ) {
			if ( $platform->id() === $platform_id ) {
				return $platform;
			}
		}
		return null;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function find_audit_entry( int $post_id, string $audit_log_id ): ?array {
		foreach ( Outpost_Manual_Share_Audit_Log::get_entries( $post_id ) as $entry ) {
			if ( ( $entry['id'] ?? '' ) === $audit_log_id ) {
				return $entry;
			}
		}
		return null;
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
