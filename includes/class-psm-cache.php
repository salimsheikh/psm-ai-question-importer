<?php
/**
 * Namespaced one-hour cache for plugin database reads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_Cache {

	const GROUP             = 'psm_ai_queries';
	const KEY_PREFIX        = 'psm_ai_query_';
	const EXPIRATION        = HOUR_IN_SECONDS;
	const GENERATION_OPTION = 'psm_ai_cache_generation';

	private static $generation;

	public static function get_key( $name, $context = array() ) {
		$name    = sanitize_key( (string) $name );
		$payload = array(
			'blog'         => (int) get_current_blog_id(),
			'table_prefix' => PSM_AI_TABLE_PREFIX,
			'name'         => $name,
			'context'      => self::normalize( $context ),
		);
		$json    = wp_json_encode( $payload, JSON_INVALID_UTF8_SUBSTITUTE );
		$hash    = md5( (string) $json );

		return self::KEY_PREFIX . self::get_generation() . '_' . ( '' !== $name ? $name : 'data' ) . '_' . $hash;
	}

	public static function create_cache( $key, $data, $expiration = self::EXPIRATION ) {
		return wp_cache_add( $key, $data, self::GROUP, self::normalize_expiration( $expiration ) );
	}

	public static function get_cache( $key ) {
		return wp_cache_get( $key, self::GROUP );
	}

	public static function set_cache( $key, $data, $expiration = self::EXPIRATION ) {
		return wp_cache_set( $key, $data, self::GROUP, self::normalize_expiration( $expiration ) );
	}

	public static function create_hour_cache( $key, $data ) {
		return self::create_cache( $key, $data, HOUR_IN_SECONDS );
	}

	public static function get_or_create( $name, $context, $callback, $expiration = self::EXPIRATION ) {
		$key     = self::get_key( $name, $context );
		$cached  = self::get_cache( $key );
		$expires = self::normalize_expiration( $expiration );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = call_user_func( $callback );
		if ( false !== $result && ! is_wp_error( $result ) ) {
			self::set_cache( $key, $result, $expires );
		}

		return $result;
	}

	public static function delete_cache( $key ) {
		return wp_cache_delete( $key, self::GROUP );
	}

	public static function delete_all() {
		$previous = self::get_generation();
		$next     = $previous + 1;

		update_option( self::GENERATION_OPTION, $next, false );
		self::$generation = $next;
		self::delete_cache( self::KEY_PREFIX . $previous );

		if ( function_exists( 'wp_cache_flush_group' ) ) {
			wp_cache_flush_group( self::GROUP );
		}

		return $next;
	}

	public static function get_generation() {
		if ( null === self::$generation ) {
			$generation       = (int) get_option( self::GENERATION_OPTION, 1 );
			self::$generation = $generation > 0 ? $generation : 1;
		}

		return self::$generation;
	}

	private static function normalize_expiration( $expiration ) {
		$expiration = (int) $expiration;
		return $expiration > 0 ? $expiration : self::EXPIRATION;
	}

	private static function normalize( $value ) {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}

		if ( is_array( $value ) ) {
			$normalized = array();
			foreach ( $value as $key => $item ) {
				if ( is_string( $key ) ) {
					$lower_key = strtolower( $key );
					if ( false !== strpos( $lower_key, 'nonce' ) || in_array( $lower_key, array( 'action', 'submit', 'security', '_wp_http_referer' ), true ) ) {
						continue;
					}
				}
				$normalized[ $key ] = self::normalize( $item );
			}
			if ( array_keys( $value ) === array_keys( $normalized ) && ! empty( $value ) && array_keys( $value ) === range( 0, count( $value ) - 1 ) ) {
				return $normalized;
			}
			ksort( $normalized );
			return $normalized;
		}

		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : (string) $value;
		}

		if ( is_bool( $value ) || is_int( $value ) ) {
			return $value;
		}

		if ( null === $value ) {
			return '';
		}

		return (string) $value;
	}
}
