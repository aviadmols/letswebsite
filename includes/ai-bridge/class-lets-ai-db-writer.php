<?php
/**
 * Post meta and option edits for LETS AI Bridge, plus the change log that
 * every write (these and raw SQL) goes into.
 *
 * Meta and options go through the WordPress API rather than SQL, so object
 * caches and hooks behave exactly as for an edit made in wp-admin. Every
 * change keeps a copy of what it replaced, which is what db_revert puts back.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_AI_DB_Writer {

	/** Summaries of recent changes, oldest first. Each full change lives in its own option. */
	const LOG_INDEX_OPTION = 'lets_ai_bridge_db_changes';

	const LOG_ENTRY_PREFIX = 'lets_ai_bridge_db_change_';

	/** How many changes stay revertable. Older ones are dropped. */
	const LOG_SIZE = 200;

	/**
	 * Options no token may touch: they would let it widen its own access or take the site down.
	 *
	 * @param string $option Option name.
	 * @return bool
	 */
	protected static function is_protected_option( $option ) {
		global $wpdb;

		$protected = array(
			'siteurl',
			'home',
			'active_plugins',
			'template',
			'stylesheet',
			'users_can_register',
			'default_role',
			'admin_email',
			$wpdb->prefix . 'user_roles',
		);

		return in_array( $option, $protected, true ) || 0 === strpos( $option, 'lets_ai_bridge' );
	}

	/**
	 * POST /db/post-meta  { post_id, meta_key, meta_value, expect_value? }
	 *
	 * Pass expect_value (null for "does not exist yet") to make the write
	 * conditional on the field still holding what you last read.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_post_meta( $request ) {
		$target = self::post_meta_target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		list( $post_id, $meta_key ) = $target;

		if ( ! $request->has_param( 'meta_value' ) ) {
			return new WP_Error( 'lets_ai_bad_value', 'meta_value is required.', array( 'status' => 400 ) );
		}

		$value   = $request->get_param( 'meta_value' );
		$existed = metadata_exists( 'post', $post_id, $meta_key );
		$before  = $existed ? get_post_meta( $post_id, $meta_key, false ) : array();

		$expected = self::check_expected( $request, $existed ? $before[0] : null );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}

		if ( 1 === count( $before ) && self::same_value( $before[0], $value ) ) {
			return rest_ensure_response( array( 'ok' => true, 'changed' => false, 'post_id' => $post_id, 'meta_key' => $meta_key ) );
		}

		// update_post_meta() unslashes, so slash first or backslashes in the value are lost.
		update_post_meta( $post_id, $meta_key, wp_slash( $value ) );
		self::purge_post_cache( $post_id, $meta_key );

		$change = self::record(
			array(
				'tool'          => 'db_update_post_meta',
				'target'        => 'post_meta',
				'post_id'       => $post_id,
				'meta_key'      => $meta_key,
				'before_exists' => $existed,
				'before'        => $before,
				'after_exists'  => true,
				'after'         => $value,
			),
			$request
		);

		return rest_ensure_response(
			array(
				'ok'        => true,
				'changed'   => true,
				'change_id' => $change['id'],
				'post_id'   => $post_id,
				'meta_key'  => $meta_key,
				'before'    => $before,
				'after'     => get_post_meta( $post_id, $meta_key, true ),
			)
		);
	}

	/**
	 * POST /db/post-meta/delete  { post_id, meta_key, expect_value? }
	 *
	 * Removes every row with that key on the post.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_post_meta( $request ) {
		$target = self::post_meta_target( $request );
		if ( is_wp_error( $target ) ) {
			return $target;
		}
		list( $post_id, $meta_key ) = $target;

		if ( ! metadata_exists( 'post', $post_id, $meta_key ) ) {
			return rest_ensure_response( array( 'ok' => true, 'changed' => false, 'post_id' => $post_id, 'meta_key' => $meta_key ) );
		}

		$before   = get_post_meta( $post_id, $meta_key, false );
		$expected = self::check_expected( $request, $before[0] );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}

		delete_post_meta( $post_id, $meta_key );
		self::purge_post_cache( $post_id, $meta_key );

		$change = self::record(
			array(
				'tool'          => 'db_delete_post_meta',
				'target'        => 'post_meta',
				'post_id'       => $post_id,
				'meta_key'      => $meta_key,
				'before_exists' => true,
				'before'        => $before,
				'after_exists'  => false,
				'after'         => null,
			),
			$request
		);

		return rest_ensure_response(
			array(
				'ok'        => true,
				'changed'   => true,
				'change_id' => $change['id'],
				'post_id'   => $post_id,
				'meta_key'  => $meta_key,
				'before'    => $before,
			)
		);
	}

	/**
	 * POST /db/option  { option, value, key?, expect_value? }
	 *
	 * With key, only that entry of an array option changes — the rest of the
	 * array is left exactly as it is, which is the safe way to flip one setting
	 * inside a large serialized option.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function update_option( $request ) {
		$option = trim( (string) $request->get_param( 'option' ) );

		if ( '' === $option ) {
			return new WP_Error( 'lets_ai_bad_option', 'option is required.', array( 'status' => 400 ) );
		}
		if ( self::is_protected_option( $option ) ) {
			return new WP_Error( 'lets_ai_protected_option', 'Refused: ' . $option . ' is protected.', array( 'status' => 403 ) );
		}
		if ( ! $request->has_param( 'value' ) ) {
			return new WP_Error( 'lets_ai_bad_value', 'value is required.', array( 'status' => 400 ) );
		}

		$value   = $request->get_param( 'value' );
		$key     = $request->get_param( 'key' );
		$has_key = null !== $key && '' !== $key;
		$missing = new stdClass();
		$current = get_option( $option, $missing );
		$existed = $current !== $missing;

		if ( $has_key ) {
			if ( ! $existed || ! is_array( $current ) ) {
				return new WP_Error( 'lets_ai_not_array', 'key can only be used on an existing option that holds an array.', array( 'status' => 400 ) );
			}
			$before_exists = array_key_exists( $key, $current );
			$before        = $before_exists ? $current[ $key ] : null;
			$new           = $current;
			$new[ $key ]   = $value;
		} else {
			$before_exists = $existed;
			$before        = $existed ? $current : null;
			$new           = $value;
		}

		$expected = self::check_expected( $request, $before );
		if ( is_wp_error( $expected ) ) {
			return $expected;
		}

		if ( $before_exists && self::same_value( $before, $value ) ) {
			return rest_ensure_response( array( 'ok' => true, 'changed' => false, 'option' => $option, 'key' => $has_key ? $key : null ) );
		}

		if ( $existed ) {
			update_option( $option, $new );
		} else {
			add_option( $option, $new, '', 'no' );
		}

		$change = self::record(
			array(
				'tool'          => 'db_update_option',
				'target'        => 'option',
				'option'        => $option,
				'key'           => $has_key ? $key : null,
				'before_exists' => $before_exists,
				'before'        => $before,
				'after_exists'  => true,
				'after'         => $value,
			),
			$request
		);

		return rest_ensure_response(
			array(
				'ok'        => true,
				'changed'   => true,
				'change_id' => $change['id'],
				'option'    => $option,
				'key'       => $has_key ? $key : null,
				'before'    => $before,
				'after'     => $value,
			)
		);
	}

	/**
	 * POST /db/revert  { change_id, force? }
	 *
	 * Refuses when the value has been changed again since, because putting the
	 * old value back would then silently throw away that later edit. force
	 * overrides that.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function revert( $request ) {
		$id     = sanitize_key( (string) $request->get_param( 'change_id' ) );
		$change = '' === $id ? false : get_option( self::LOG_ENTRY_PREFIX . $id );
		$force  = (bool) $request->get_param( 'force' );

		if ( ! is_array( $change ) ) {
			return new WP_Error( 'lets_ai_no_change', 'No such change (it may be older than the last ' . self::LOG_SIZE . ').', array( 'status' => 404 ) );
		}
		if ( ! empty( $change['reverted'] ) ) {
			return new WP_Error( 'lets_ai_already_reverted', 'That change was already reverted.', array( 'status' => 409 ) );
		}

		if ( 'sql' === $change['target'] ) {
			$done = Lets_AI_SQL::revert( $change, $force );
			if ( is_wp_error( $done ) ) {
				return $done;
			}
		} else {
			list( $exists_now, $value_now ) = self::read_target( $change );

			$untouched = $exists_now === $change['after_exists']
				&& ( ! $exists_now || self::same_value( $value_now, $change['after'] ) );

			if ( ! $untouched && ! $force ) {
				return new WP_Error(
					'lets_ai_conflict',
					'Refused: the value was changed again after this change. Pass force to revert anyway.',
					array( 'status' => 409, 'current' => $exists_now ? $value_now : null )
				);
			}

			if ( 'post_meta' === $change['target'] ) {
				delete_post_meta( $change['post_id'], $change['meta_key'] );
				foreach ( (array) $change['before'] as $row ) {
					add_post_meta( $change['post_id'], $change['meta_key'], wp_slash( $row ) );
				}
				self::purge_post_cache( $change['post_id'], $change['meta_key'] );
			} elseif ( null !== $change['key'] ) {
				$current = get_option( $change['option'], array() );
				$current = is_array( $current ) ? $current : array();
				if ( $change['before_exists'] ) {
					$current[ $change['key'] ] = $change['before'];
				} else {
					unset( $current[ $change['key'] ] );
				}
				update_option( $change['option'], $current );
			} elseif ( $change['before_exists'] ) {
				update_option( $change['option'], $change['before'] );
			} else {
				delete_option( $change['option'] );
			}
		}

		$change['reverted'] = time();
		update_option( self::LOG_ENTRY_PREFIX . $id, $change, false );

		$index = get_option( self::LOG_INDEX_OPTION, array() );
		if ( is_array( $index ) && isset( $index[ $id ] ) ) {
			$index[ $id ]['reverted'] = $change['reverted'];
			update_option( self::LOG_INDEX_OPTION, $index, false );
		}

		return rest_ensure_response( array( 'ok' => true, 'change_id' => $id, 'restored' => $change['before'] ) );
	}

	/**
	 * GET /db/changes?change_id=…  — recent changes, newest first, or one in full.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function changes( $request ) {
		$id = sanitize_key( (string) $request->get_param( 'change_id' ) );

		if ( '' !== $id ) {
			$change = get_option( self::LOG_ENTRY_PREFIX . $id );
			if ( ! is_array( $change ) ) {
				return new WP_Error( 'lets_ai_no_change', 'No such change.', array( 'status' => 404 ) );
			}
			return rest_ensure_response( $change );
		}

		$index = get_option( self::LOG_INDEX_OPTION, array() );
		$index = is_array( $index ) ? array_reverse( array_values( $index ) ) : array();

		return rest_ensure_response( array( 'count' => count( $index ), 'changes' => $index ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return array{0:int,1:string}|WP_Error
	 */
	protected static function post_meta_target( $request ) {
		$post_id  = (int) $request->get_param( 'post_id' );
		$meta_key = trim( (string) $request->get_param( 'meta_key' ) );

		if ( $post_id < 1 || ! get_post( $post_id ) ) {
			return new WP_Error( 'lets_ai_no_post', 'No such post: ' . $post_id, array( 'status' => 404 ) );
		}
		if ( '' === $meta_key ) {
			return new WP_Error( 'lets_ai_bad_key', 'meta_key is required.', array( 'status' => 400 ) );
		}

		return array( $post_id, $meta_key );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @param mixed           $current Value the target holds now (null when absent).
	 * @return true|WP_Error
	 */
	protected static function check_expected( $request, $current ) {
		if ( ! $request->has_param( 'expect_value' ) ) {
			return true;
		}

		if ( self::same_value( $current, $request->get_param( 'expect_value' ) ) ) {
			return true;
		}

		return new WP_Error(
			'lets_ai_conflict',
			'Refused: the value changed since you read it.',
			array( 'status' => 409, 'current' => $current )
		);
	}

	/**
	 * Compare a stored value with one that came in as JSON.
	 *
	 * The database hands back strings where JSON has numbers and booleans, so
	 * scalars are compared as strings rather than strictly.
	 *
	 * @param mixed $a Value.
	 * @param mixed $b Value.
	 * @return bool
	 */
	protected static function same_value( $a, $b ) {
		return self::normalize( $a ) === self::normalize( $b );
	}

	/**
	 * @param mixed $value Value.
	 * @return mixed
	 */
	protected static function normalize( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'normalize' ), $value );
		}
		if ( null === $value ) {
			return null;
		}
		return (string) $value;
	}

	/**
	 * @param array<string,mixed> $change Recorded change.
	 * @return array{0:bool,1:mixed} Whether the target exists now, and its value.
	 */
	protected static function read_target( array $change ) {
		if ( 'post_meta' === $change['target'] ) {
			$exists = metadata_exists( 'post', $change['post_id'], $change['meta_key'] );
			return array( $exists, $exists ? get_post_meta( $change['post_id'], $change['meta_key'], true ) : null );
		}

		$missing = new stdClass();
		$current = get_option( $change['option'], $missing );

		if ( $current === $missing ) {
			return array( false, null );
		}
		if ( null === $change['key'] ) {
			return array( true, $current );
		}
		if ( ! is_array( $current ) || ! array_key_exists( $change['key'], $current ) ) {
			return array( false, null );
		}

		return array( true, $current[ $change['key'] ] );
	}

	/**
	 * Store a change so it can be listed and reverted.
	 *
	 * Each change is its own non-autoloaded option, so a large "before" value
	 * (a whole serialized settings array, or rows from a SQL write) never
	 * inflates the index or autoload.
	 *
	 * @param array<string,mixed> $change  Change details.
	 * @param WP_REST_Request     $request Request that made it.
	 * @return array<string,mixed>
	 */
	public static function record( array $change, $request ) {
		$token = $request->get_param( '_lets_ai_token' );

		$change['id']       = str_replace( '-', '', wp_generate_uuid4() );
		$change['time']     = time();
		$change['token']    = is_array( $token ) && isset( $token['label'] ) ? $token['label'] : '';
		$change['reverted'] = null;

		add_option( self::LOG_ENTRY_PREFIX . $change['id'], $change, '', 'no' );

		if ( 'post_meta' === $change['target'] ) {
			$target = 'post ' . $change['post_id'] . ' / ' . $change['meta_key'];
		} elseif ( 'option' === $change['target'] ) {
			$target = 'option ' . $change['option'] . ( null !== $change['key'] ? ' [' . $change['key'] . ']' : '' );
		} else {
			$target = 'sql ' . $change['summary'];
		}

		$index = get_option( self::LOG_INDEX_OPTION, array() );
		$index = is_array( $index ) ? $index : array();

		$index[ $change['id'] ] = array(
			'id'       => $change['id'],
			'time'     => gmdate( 'Y-m-d H:i:s', $change['time'] ) . ' UTC',
			'tool'     => $change['tool'],
			'target'   => $target,
			'token'    => $change['token'],
			'reverted' => null,
		);

		while ( count( $index ) > self::LOG_SIZE ) {
			$oldest = array_key_first( $index );
			unset( $index[ $oldest ] );
			delete_option( self::LOG_ENTRY_PREFIX . $oldest );
		}

		update_option( self::LOG_INDEX_OPTION, $index, false );

		return $change;
	}

	/**
	 * Meta written directly does not trigger a save, so clear the caches a save would have.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $meta_key Meta key that changed.
	 */
	protected static function purge_post_cache( $post_id, $meta_key = '' ) {
		clean_post_cache( $post_id );

		// Elementor keeps a compiled CSS file per post, built from _elementor_data.
		if ( 0 === strpos( $meta_key, '_elementor' ) && class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			\Elementor\Core\Files\CSS\Post::create( $post_id )->delete();
		}

		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		do_action( 'litespeed_purge_post', $post_id );
	}

	/**
	 * Clear page caches site-wide (after raw SQL, which can touch anything).
	 */
	public static function purge_page_caches() {
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		do_action( 'litespeed_purge_all' );
	}
}
