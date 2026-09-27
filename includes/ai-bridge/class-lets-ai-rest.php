<?php
/**
 * REST + lightweight MCP endpoints for LETS AI Bridge.
 *
 * Every token can read the database. Writing (post meta, options and raw
 * SQL) needs a token that was granted write access on the admin page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_AI_REST {

	const NS = 'lets-ai/v1';

	/** Token IDs allowed to write to the database. Managed on the AI Bridge admin page. */
	const WRITE_TOKENS_OPTION = 'lets_ai_bridge_write_tokens';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	/**
	 * Options that hold per-token grants (cleared when a token is revoked).
	 *
	 * @return string[]
	 */
	public static function grant_options() {
		return array( self::WRITE_TOKENS_OPTION );
	}

	public static function register_routes() {
		$read  = array( __CLASS__, 'permission_check' );
		$write = array( __CLASS__, 'write_permission_check' );

		$routes = array(
			'/status'                          => array( 'GET', array( __CLASS__, 'status' ), $read ),
			'/schema'                          => array( 'GET', array( __CLASS__, 'schema' ), $read ),
			'/table/(?P<table>[a-zA-Z0-9_$]+)' => array( 'GET', array( __CLASS__, 'describe_table' ), $read ),
			'/query'                           => array( 'POST', array( __CLASS__, 'query' ), $read ),
			'/wp-query'                        => array( 'POST', array( __CLASS__, 'wp_query' ), $read ),
			'/db/changes'                      => array( 'GET', array( 'Lets_AI_DB_Writer', 'changes' ), $read ),
			'/execute'                         => array( 'POST', array( 'Lets_AI_SQL', 'execute' ), $write ),
			'/db/post-meta'                    => array( 'POST', array( 'Lets_AI_DB_Writer', 'update_post_meta' ), $write ),
			'/db/post-meta/delete'             => array( 'POST', array( 'Lets_AI_DB_Writer', 'delete_post_meta' ), $write ),
			'/db/option'                       => array( 'POST', array( 'Lets_AI_DB_Writer', 'update_option' ), $write ),
			'/db/revert'                       => array( 'POST', array( 'Lets_AI_DB_Writer', 'revert' ), $write ),
		);

		foreach ( $routes as $route => $def ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => $def[1],
					'permission_callback' => $def[2],
				)
			);
		}

		// Lightweight MCP JSON-RPC (initialize, tools/list, tools/call) for Claude / Cursor.
		register_rest_route(
			self::NS,
			'/mcp',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( __CLASS__, 'mcp' ),
					'permission_callback' => $read,
				),
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'mcp_info' ),
					'permission_callback' => $read,
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function permission_check( $request ) {
		$auth = Lets_AI_Tokens::authenticate( Lets_AI_Tokens::bearer_from_request() );

		if ( ! $auth ) {
			return new WP_Error( 'lets_ai_unauthorized', 'Invalid or missing LETS AI Bridge token.', array( 'status' => 401 ) );
		}

		$request->set_param( '_lets_ai_token', $auth );
		return true;
	}

	/**
	 * Same as permission_check, plus the token must have been granted write access.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function write_permission_check( $request ) {
		$allowed = self::permission_check( $request );

		if ( is_wp_error( $allowed ) ) {
			return $allowed;
		}

		if ( ! self::can_write( $request->get_param( '_lets_ai_token' ) ) ) {
			return new WP_Error( 'lets_ai_read_only', 'This token is read-only. Grant it write access under LETS -> AI Bridge.', array( 'status' => 403 ) );
		}

		return true;
	}

	/**
	 * @param array<string,mixed>|null $token Authenticated token record.
	 * @return bool
	 */
	protected static function can_write( $token ) {
		$granted = get_option( self::WRITE_TOKENS_OPTION, array() );
		return ! empty( $token['id'] ) && is_array( $granted ) && in_array( $token['id'], $granted, true );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function status( $request ) {
		global $wpdb;

		$token = $request->get_param( '_lets_ai_token' );

		return rest_ensure_response(
			array(
				'ok'          => true,
				'site'        => get_bloginfo( 'name' ),
				'url'         => home_url( '/' ),
				'wp_version'  => get_bloginfo( 'version' ),
				'php_version' => PHP_VERSION,
				'db_prefix'   => $wpdb->prefix,
				'token'       => is_array( $token ) ? $token['label'] : '',
				'mode'        => self::can_write( $token ) ? 'read-write' : 'read-only',
				'mcp'         => rest_url( self::NS . '/mcp' ),
			)
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function schema() {
		global $wpdb;

		$tables = self::tables();
		$types  = array();

		foreach ( get_post_types( array( 'public' => true ), 'objects' ) as $pt ) {
			$types[] = array(
				'name'  => $pt->name,
				'label' => $pt->label,
			);
		}

		return rest_ensure_response(
			array(
				'db_prefix'   => $wpdb->prefix,
				'table_count' => count( $tables ),
				'tables'      => $tables,
				'post_types'  => $types,
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function describe_table( $request ) {
		global $wpdb;

		$table = (string) $request['table'];
		if ( ! self::table_exists( $table ) ) {
			return new WP_Error( 'lets_ai_unknown_table', 'Table not found.', array( 'status' => 404 ) );
		}

		return rest_ensure_response(
			array(
				'table'   => $table,
				'columns' => $wpdb->get_results( "DESCRIBE `{$table}`", ARRAY_A ), // phpcs:ignore WordPress.DB.PreparedSQL -- table validated against SHOW TABLES.
				'rows'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}`" ), // phpcs:ignore WordPress.DB.PreparedSQL
			)
		);
	}

	/**
	 * POST /query  { sql | sql_b64, limit? } — read-only SQL.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function query( $request ) {
		global $wpdb;

		$sql = Lets_AI_SQL::from_request( $request );
		if ( is_wp_error( $sql ) ) {
			return $sql;
		}

		$limit = (int) $request->get_param( 'limit' );
		$limit = $limit < 1 ? 100 : min( 1000, $limit );
		$safe  = Lets_AI_SQL::validate_read( $sql, $limit );

		if ( is_wp_error( $safe ) ) {
			return $safe;
		}

		$rows = $wpdb->get_results( $safe, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL -- validated read-only statement.

		if ( $wpdb->last_error ) {
			return new WP_Error( 'lets_ai_sql_error', $wpdb->last_error, array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'sql'   => $safe,
				'count' => is_array( $rows ) ? count( $rows ) : 0,
				'rows'  => $rows ? $rows : array(),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function wp_query( $request ) {
		$params = $request->get_json_params();
		if ( ! is_array( $params ) || array() === $params ) {
			$params = $request->get_params();
		}

		return rest_ensure_response( self::run_wp_query( is_array( $params ) ? $params : array() ) );
	}

	/**
	 * @param array<string,mixed> $params Query params.
	 * @return array<string,mixed>
	 */
	public static function run_wp_query( array $params ) {
		$allowed = array( 'post_type', 'post_status', 'posts_per_page', 'paged', 's', 'p', 'name', 'orderby', 'order', 'meta_key', 'meta_value', 'cat', 'tag', 'tax_query', 'meta_query', 'date_query', 'author', 'post__in', 'post__not_in' );
		$args    = array( 'no_found_rows' => false );

		foreach ( $allowed as $key ) {
			if ( array_key_exists( $key, $params ) ) {
				$args[ $key ] = $params[ $key ];
			}
		}

		$args['posts_per_page'] = empty( $args['posts_per_page'] ) ? 20 : min( 100, max( 1, (int) $args['posts_per_page'] ) );
		$args['post_status']    = empty( $args['post_status'] ) ? 'any' : $args['post_status'];

		$q     = new WP_Query( $args );
		$items = array();

		foreach ( $q->posts as $post ) {
			$items[] = array(
				'ID'            => $post->ID,
				'post_type'     => $post->post_type,
				'post_status'   => $post->post_status,
				'post_title'    => $post->post_title,
				'post_name'     => $post->post_name,
				'post_date'     => $post->post_date,
				'post_modified' => $post->post_modified,
				'permalink'     => get_permalink( $post ),
			);
		}

		return array(
			'found' => (int) $q->found_posts,
			'count' => count( $items ),
			'posts' => $items,
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function mcp_info() {
		return rest_ensure_response(
			array(
				'name'        => 'LETS AI Bridge',
				'transport'   => 'json-rpc',
				'description' => 'POST JSON-RPC 2.0: initialize, tools/list, tools/call, ping',
				'endpoint'    => rest_url( self::NS . '/mcp' ),
			)
		);
	}

	/**
	 * Minimal MCP-compatible JSON-RPC handler.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function mcp( $request ) {
		$body = $request->get_json_params();
		if ( ! is_array( $body ) ) {
			return new WP_Error( 'lets_ai_bad_json', 'Expected JSON-RPC body.', array( 'status' => 400 ) );
		}

		$token = $request->get_param( '_lets_ai_token' );

		if ( array() !== $body && array_keys( $body ) === range( 0, count( $body ) - 1 ) ) {
			$out = array();
			foreach ( $body as $msg ) {
				$out[] = self::mcp_handle_one( is_array( $msg ) ? $msg : array(), $token );
			}
			return rest_ensure_response( $out );
		}

		return rest_ensure_response( self::mcp_handle_one( $body, $token ) );
	}

	/**
	 * @param array<string,mixed>      $msg   JSON-RPC message.
	 * @param array<string,mixed>|null $token Authenticated token.
	 * @return array<string,mixed>
	 */
	protected static function mcp_handle_one( array $msg, $token ) {
		$id     = array_key_exists( 'id', $msg ) ? $msg['id'] : null;
		$method = isset( $msg['method'] ) ? (string) $msg['method'] : '';
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		switch ( $method ) {
			case 'initialize':
				return self::mcp_result(
					$id,
					array(
						'protocolVersion' => '2024-11-05',
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'lets-ai-bridge',
							'version' => '1.0.0',
						),
					)
				);

			case 'notifications/initialized':
			case 'initialized':
			case 'ping':
				return self::mcp_result( $id, new stdClass() );

			case 'tools/list':
				return self::mcp_result( $id, array( 'tools' => self::mcp_tools() ) );

			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

				try {
					$text  = self::mcp_call_tool( $name, $args, $token );
					$error = false;
				} catch ( Exception $e ) {
					$text  = $e->getMessage();
					$error = true;
				}

				return self::mcp_result(
					$id,
					array(
						'content' => array(
							array(
								'type' => 'text',
								'text' => $text,
							),
						),
						'isError' => $error,
					)
				);

			case '':
				return self::mcp_error( $id, -32600, 'Invalid Request' );

			default:
				return self::mcp_error( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	protected static function mcp_tools() {
		$sql_props = array(
			'sql'     => array(
				'type'        => 'string',
				'description' => 'One SQL statement',
			),
			'sql_b64' => array(
				'type'        => 'string',
				'description' => 'The same statement base64-encoded, for when a firewall blocks SQL in request bodies',
			),
		);

		return array(
			array(
				'name'        => 'db_status',
				'description' => 'Site info, table prefix, and whether this token can write.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
			),
			array(
				'name'        => 'db_schema',
				'description' => 'List every table in the WordPress database and the public post types.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => new stdClass(),
				),
			),
			array(
				'name'        => 'db_describe_table',
				'description' => 'Columns and row count of one table.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'table' => array( 'type' => 'string' ) ),
					'required'   => array( 'table' ),
				),
			),
			array(
				'name'        => 'db_query',
				'description' => 'Run a read-only statement (SELECT / SHOW / DESCRIBE / EXPLAIN). A SELECT without LIMIT gets one.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$sql_props,
						array(
							'limit' => array(
								'type'        => 'integer',
								'description' => 'Default 100, max 1000',
							),
						)
					),
				),
			),
			array(
				'name'        => 'db_execute',
				'description' => 'Run one INSERT / UPDATE / DELETE / REPLACE on any table. Needs a token with write access. UPDATE/DELETE must be single-table with a WHERE (or allow_all_rows), touch at most 500 rows, and are snapshotted so db_revert can undo them. Schema changes are not allowed.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array_merge(
						$sql_props,
						array(
							'allow_all_rows' => array(
								'type'        => 'boolean',
								'description' => 'Allow UPDATE/DELETE without WHERE',
							),
							'allow_no_undo'  => array(
								'type'        => 'boolean',
								'description' => 'Allow statements that cannot be undone (REPLACE, ON DUPLICATE KEY, tables without a primary key)',
							),
						)
					),
				),
			),
			array(
				'name'        => 'wp_query',
				'description' => 'Run a WP_Query and return post summaries.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_type'      => array( 'type' => 'string' ),
						'post_status'    => array( 'type' => 'string' ),
						'posts_per_page' => array( 'type' => 'integer' ),
						's'              => array( 'type' => 'string' ),
						'paged'          => array( 'type' => 'integer' ),
					),
				),
			),
			array(
				'name'        => 'db_update_post_meta',
				'description' => 'Set one post meta value through WordPress (ACF, Elementor _elementor_data, SEO fields _lets_seo_*). Clears caches and Elementor CSS. Needs write access. Undoable with db_revert.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer' ),
						'meta_key'     => array( 'type' => 'string' ),
						'meta_value'   => array( 'description' => 'String, number, or array/object (stored serialized)' ),
						'expect_value' => array( 'description' => 'Optional: only write if the field still holds this value (null = does not exist)' ),
					),
					'required'   => array( 'post_id', 'meta_key', 'meta_value' ),
				),
			),
			array(
				'name'        => 'db_delete_post_meta',
				'description' => 'Delete every row of one meta key on a post. Needs write access. Undoable.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'post_id'      => array( 'type' => 'integer' ),
						'meta_key'     => array( 'type' => 'string' ),
						'expect_value' => array( 'description' => 'Optional: only delete if the field still holds this value' ),
					),
					'required'   => array( 'post_id', 'meta_key' ),
				),
			),
			array(
				'name'        => 'db_update_option',
				'description' => 'Set a WordPress option, or with key one entry inside an array option. Needs write access. Site URL, plugin list, theme, roles and the bridge\'s own settings are refused. Undoable.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'option'       => array( 'type' => 'string' ),
						'value'        => array( 'description' => 'New value' ),
						'key'          => array(
							'type'        => array( 'string', 'integer' ),
							'description' => 'Optional: top-level array key to change',
						),
						'expect_value' => array( 'description' => 'Optional: only write if it still holds this value' ),
					),
					'required'   => array( 'option', 'value' ),
				),
			),
			array(
				'name'        => 'db_changes',
				'description' => 'Recent writes made through the bridge (newest first), or one in full with its before/after values.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array( 'change_id' => array( 'type' => 'string' ) ),
				),
			),
			array(
				'name'        => 'db_revert',
				'description' => 'Undo one write made through the bridge. Refuses if the data was changed again since, unless force.',
				'inputSchema' => array(
					'type'       => 'object',
					'properties' => array(
						'change_id' => array( 'type' => 'string' ),
						'force'     => array( 'type' => 'boolean' ),
					),
					'required'   => array( 'change_id' ),
				),
			),
		);
	}

	/**
	 * @param string                   $name  Tool name.
	 * @param array<string,mixed>      $args  Arguments.
	 * @param array<string,mixed>|null $token Authenticated token.
	 * @return string JSON text payload.
	 * @throws Exception On failure.
	 */
	protected static function mcp_call_tool( $name, array $args, $token ) {
		$map = array(
			'db_status'           => array( __CLASS__, 'status', false ),
			'db_schema'           => array( __CLASS__, 'schema', false ),
			'db_describe_table'   => array( __CLASS__, 'describe_table', false ),
			'db_query'            => array( __CLASS__, 'query', false ),
			'db_changes'          => array( 'Lets_AI_DB_Writer', 'changes', false ),
			'db_execute'          => array( 'Lets_AI_SQL', 'execute', true ),
			'db_update_post_meta' => array( 'Lets_AI_DB_Writer', 'update_post_meta', true ),
			'db_delete_post_meta' => array( 'Lets_AI_DB_Writer', 'delete_post_meta', true ),
			'db_update_option'    => array( 'Lets_AI_DB_Writer', 'update_option', true ),
			'db_revert'           => array( 'Lets_AI_DB_Writer', 'revert', true ),
		);

		if ( 'wp_query' === $name ) {
			return self::encode( self::run_wp_query( $args ) );
		}
		if ( ! isset( $map[ $name ] ) ) {
			throw new Exception( 'Unknown tool: ' . $name );
		}

		list( $class, $method, $needs_write ) = $map[ $name ];

		if ( $needs_write && ! self::can_write( $token ) ) {
			throw new Exception( 'This token is read-only. Grant it write access under LETS -> AI Bridge.' );
		}

		$req = new WP_REST_Request( $needs_write ? 'POST' : 'GET' );
		foreach ( $args as $key => $value ) {
			$req->set_param( $key, $value );
		}
		$req->set_param( '_lets_ai_token', $token );

		$result = call_user_func( array( $class, $method ), $req );

		if ( is_wp_error( $result ) ) {
			throw new Exception( $result->get_error_message() );
		}

		return self::encode( $result instanceof WP_REST_Response ? $result->get_data() : $result );
	}

	/**
	 * @param mixed $data Data.
	 * @return string
	 */
	protected static function encode( $data ) {
		return (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
	}

	/**
	 * @param mixed $id     Request id.
	 * @param mixed $result Result payload.
	 * @return array<string,mixed>
	 */
	protected static function mcp_result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * @param mixed  $id      Request id.
	 * @param int    $code    Error code.
	 * @param string $message Message.
	 * @return array<string,mixed>
	 */
	protected static function mcp_error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * @return string[]
	 */
	protected static function tables() {
		global $wpdb;
		static $tables = null;

		if ( null === $tables ) {
			$tables = (array) $wpdb->get_col( 'SHOW TABLES' );
		}

		return $tables;
	}

	/**
	 * @param string $table Table name.
	 * @return bool
	 */
	public static function table_exists( $table ) {
		return in_array( $table, self::tables(), true );
	}
}
