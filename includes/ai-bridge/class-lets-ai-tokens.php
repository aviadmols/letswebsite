<?php
/**
 * Secure API token storage for LETS AI Bridge.
 *
 * Tokens are shown in plaintext only once at creation.
 * Only a hash is stored in the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_AI_Tokens {

	const OPTION_KEY = 'lets_ai_bridge_tokens';

	const PREFIX = 'lets_';

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public static function all() {
		$tokens = get_option( self::OPTION_KEY, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Create a new token. Returns plaintext once; only hash is stored.
	 *
	 * @param string $label Human label (e.g. "Claude", "Cursor").
	 * @return array{id:string,label:string,token:string,created:int,last_used:int|null}
	 */
	public static function create( $label = '' ) {
		$label = sanitize_text_field( $label );
		if ( '' === $label ) {
			$label = 'AI Client';
		}

		$plaintext = self::PREFIX . bin2hex( random_bytes( 24 ) );
		$id        = wp_generate_uuid4();

		$record = array(
			'id'         => $id,
			'label'      => $label,
			'hash'       => wp_hash_password( $plaintext ),
			'prefix'     => substr( $plaintext, 0, 13 ),
			'created'    => time(),
			'last_used'  => null,
			'created_by' => get_current_user_id(),
		);

		$tokens   = self::all();
		$tokens[] = $record;
		update_option( self::OPTION_KEY, $tokens, false );

		return array(
			'id'        => $id,
			'label'     => $label,
			'token'     => $plaintext,
			'created'   => $record['created'],
			'last_used' => null,
		);
	}

	/**
	 * @param string $id Token UUID.
	 * @return bool
	 */
	public static function revoke( $id ) {
		$id     = sanitize_text_field( $id );
		$tokens = self::all();
		$next   = array();
		$found  = false;

		foreach ( $tokens as $token ) {
			if ( isset( $token['id'] ) && $token['id'] === $id ) {
				$found = true;
				continue;
			}
			$next[] = $token;
		}

		if ( $found ) {
			update_option( self::OPTION_KEY, $next, false );

			// A revoked token keeps no grants, so a new token can never inherit them.
			foreach ( Lets_AI_REST::grant_options() as $option ) {
				$granted = get_option( $option, array() );
				if ( is_array( $granted ) ) {
					update_option( $option, array_values( array_diff( $granted, array( $id ) ) ), false );
				}
			}
		}

		return $found;
	}

	/**
	 * Validate Bearer token and touch last_used.
	 *
	 * @param string $plaintext Raw token from Authorization header.
	 * @return array<string,mixed>|false Matched token record without hash, or false.
	 */
	public static function authenticate( $plaintext ) {
		$plaintext = is_string( $plaintext ) ? trim( $plaintext ) : '';
		if ( '' === $plaintext || 0 !== strpos( $plaintext, self::PREFIX ) ) {
			return false;
		}

		$tokens = self::all();

		foreach ( $tokens as $index => $token ) {
			if ( empty( $token['hash'] ) ) {
				continue;
			}
			if ( ! wp_check_password( $plaintext, $token['hash'] ) ) {
				continue;
			}

			// Only write when it moves, so a burst of MCP calls is not a burst of option writes.
			if ( empty( $token['last_used'] ) || time() - (int) $token['last_used'] > 60 ) {
				$tokens[ $index ]['last_used'] = time();
				update_option( self::OPTION_KEY, $tokens, false );
			}

			unset( $token['hash'] );
			return $token;
		}

		return false;
	}

	/**
	 * Extract the token from the current request.
	 *
	 * Some hosts strip the Authorization header before PHP sees it, so an
	 * X-Lets-AI-Token header is accepted as well. There is deliberately no
	 * query-string fallback: tokens in URLs end up in access logs.
	 *
	 * @return string
	 */
	public static function bearer_from_request() {
		$header = '';

		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] );
		} elseif ( isset( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			$header = wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] );
		} elseif ( function_exists( 'apache_request_headers' ) ) {
			foreach ( apache_request_headers() as $key => $value ) {
				if ( 'authorization' === strtolower( $key ) ) {
					$header = $value;
					break;
				}
			}
		}

		if ( preg_match( '/Bearer\s+(\S+)/i', $header, $m ) ) {
			return $m[1];
		}

		if ( isset( $_SERVER['HTTP_X_LETS_AI_TOKEN'] ) ) {
			return trim( wp_unslash( $_SERVER['HTTP_X_LETS_AI_TOKEN'] ) );
		}

		return '';
	}
}
