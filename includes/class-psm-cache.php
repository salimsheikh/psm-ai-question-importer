<?php
/**
 * Namespaced one-hour cache for plugin database reads.
 *
 * @package PSM_AI_Question_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provides cache keys, cached reads, and generation-wide invalidation.
 */
class PSM_Cache {

	const GROUP                   = 'psm_ai_queries';
	const KEY_PREFIX              = 'psm_ai_query_';
	const EXPIRATION              = HOUR_IN_SECONDS;
	const DURATION_OPTION         = 'psm_ai_cache_duration';
	const DEFAULT_DURATION_MINS   = 60;
	const MIN_DURATION_MINS       = 5;
	const MAX_DURATION_MINS       = 1440;
	const GENERATION_OPTION       = 'psm_ai_cache_generation';

	/**
	 * Cache generation loaded for this request.
	 *
	 * @var int|null
	 */
	private static $generation;

	/**
	 * Build a normalized cache key for the current site and table prefix.
	 *
	 * @param string $name    Dataset name.
	 * @param array  $context Dataset context.
	 * @return string
	 */
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

	/**
	 * Add a value without replacing an existing cache entry.
	 *
	 * @param string $key        Cache key.
	 * @param mixed  $data       Cached value.
	 * @param int    $expiration Lifetime in seconds.
	 * @return bool
	 */
	public static function create_cache( $key, $data, $expiration = 0 ) {
		return wp_cache_add( $key, $data, self::GROUP, self::normalize_expiration( $expiration ) );
	}

	/**
	 * Retrieve a cached value.
	 *
	 * @param string $key Cache key.
	 * @return mixed
	 */
	public static function get_cache( $key ) {
		return wp_cache_get( $key, self::GROUP );
	}

	/**
	 * Store a value in the plugin cache group.
	 *
	 * @param string $key        Cache key.
	 * @param mixed  $data       Cached value.
	 * @param int    $expiration Lifetime in seconds.
	 * @return bool
	 */
	public static function set_cache( $key, $data, $expiration = 0 ) {
		return wp_cache_set( $key, $data, self::GROUP, self::normalize_expiration( $expiration ) );
	}

	/**
	 * Add a value with a one-hour lifetime.
	 *
	 * @param string $key  Cache key.
	 * @param mixed  $data Cached value.
	 * @return bool
	 */
	public static function create_hour_cache( $key, $data ) {
		return self::create_cache( $key, $data, HOUR_IN_SECONDS );
	}

	/**
	 * Read one database row, checking the cache before querying.
	 *
	 * @param string $query  SQL query with optional placeholders.
	 * @param array  $args   Prepared query arguments.
	 * @param bool   $cache  Whether a successful result may be cached.
	 * @param array  $context Additional cache-key context.
	 * @param string $output wpdb output format.
	 * @return object|array|null
	 */
	public static function get_row( $query, $args = array(), $cache = true, $context = array(), $output = OBJECT ) {
		return self::read( 'get_row', $query, $args, $cache, $context, $output );
	}

	/**
	 * Read database result rows, checking the cache before querying.
	 *
	 * @param string $query   SQL query with optional placeholders.
	 * @param array  $args    Prepared query arguments.
	 * @param bool   $cache   Whether a successful result may be cached.
	 * @param array  $context Additional cache-key context.
	 * @return array
	 */
	public static function get_results( $query, $args = array(), $cache = true, $context = array() ) {
		return self::read( 'get_results', $query, $args, $cache, $context );
	}

	/**
	 * Read one scalar database value, checking the cache before querying.
	 *
	 * @param string $query   SQL query with optional placeholders.
	 * @param array  $args    Prepared query arguments.
	 * @param bool   $cache   Whether a successful result may be cached.
	 * @param array  $context Additional cache-key context.
	 * @return string|null
	 */
	public static function get_var( $query, $args = array(), $cache = true, $context = array() ) {
		return self::read( 'get_var', $query, $args, $cache, $context );
	}

	/**
	 * Read one database column, checking the cache before querying.
	 *
	 * @param string $query   SQL query with optional placeholders.
	 * @param array  $args    Prepared query arguments.
	 * @param bool   $cache   Whether a successful result may be cached.
	 * @param array  $context Additional cache-key context.
	 * @return array
	 */
	public static function get_col( $query, $args = array(), $cache = true, $context = array() ) {
		return self::read( 'get_col', $query, $args, $cache, $context );
	}

	/**
	 * Execute a read through the cache-first database boundary.
	 *
	 * @param string $method  Supported wpdb read method.
	 * @param string $query   SQL query with optional placeholders.
	 * @param array  $args    Prepared query arguments.
	 * @param bool   $cache   Whether a successful result may be cached.
	 * @param array  $context Additional cache-key context.
	 * @param string $output  wpdb row output format.
	 * @return mixed
	 */
	public static function read( $method, $query, $args = array(), $cache = true, $context = array(), $output = OBJECT ) {
		global $wpdb;

		if ( ! in_array( $method, array( 'get_row', 'get_results', 'get_var', 'get_col' ), true ) ) {
			return null;
		}

		$query = (string) $query;
		if ( $cache && $wpdb->options && false !== strpos( $query, $wpdb->options ) ) {
			$cache = false;
		}

		if ( ! $cache ) {
			return self::execute_read( $method, $query, $args, $output );
		}

		$key = self::get_key(
			'db_' . $method,
			array(
				'query'   => $query,
				'args'    => $args,
				'context' => $context,
				'output'  => $output,
			)
		);

		$cached = self::get_cache( $key );
		if ( false !== $cached ) {
			return $cached;
		}

		$result = self::execute_read( $method, $query, $args, $output );
		if ( '' === (string) $wpdb->last_error ) {
			self::set_cache( $key, $result );
		}

		return $result;
	}

	/**
	 * Return a cached dataset or compute and store it.
	 *
	 * @param string   $name       Dataset name.
	 * @param array    $context    Dataset context.
	 * @param callable $callback   Dataset loader.
	 * @param int      $expiration Lifetime in seconds.
	 * @return mixed
	 */
	public static function get_or_create( $name, $context, $callback, $expiration = 0 ) {
		global $wpdb;

		$key     = self::get_key( $name, $context );
		$cached  = self::get_cache( $key );
		$expires = self::normalize_expiration( $expiration );

		if ( false !== $cached ) {
			return $cached;
		}

		$result = call_user_func( $callback );
		if ( false !== $result && ! is_wp_error( $result ) && '' === (string) $wpdb->last_error ) {
			self::set_cache( $key, $result, $expires );
		}

		return $result;
	}

	/**
	 * Delete one plugin cache entry.
	 *
	 * @param string $key Cache key.
	 * @return bool
	 */
	public static function delete_cache( $key ) {
		return wp_cache_delete( $key, self::GROUP );
	}

	/**
	 * Invalidate all plugin datasets by advancing the cache generation.
	 *
	 * @return int
	 */
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

	/**
	 * Get the active cache generation.
	 *
	 * @return int
	 */
	public static function get_generation() {
		if ( null === self::$generation ) {
			$generation       = (int) get_option( self::GENERATION_OPTION, 1 );
			self::$generation = $generation > 0 ? $generation : 1;
		}

		return self::$generation;
	}

	/**
	 * Get the configured query cache duration in minutes.
	 *
	 * @return int
	 */
	public static function get_duration_minutes() {
		$minutes = absint( get_option( self::DURATION_OPTION, self::DEFAULT_DURATION_MINS ) );
		return min( self::MAX_DURATION_MINS, max( self::MIN_DURATION_MINS, $minutes ) );
	}

	/**
	 * Get the configured query cache duration in seconds.
	 *
	 * @return int
	 */
	public static function get_expiration() {
		return self::get_duration_minutes() * MINUTE_IN_SECONDS;
	}

	/**
	 * Execute one prepared wpdb read.
	 *
	 * @param string $method Supported wpdb read method.
	 * @param string $query  SQL query with optional placeholders.
	 * @param array  $args   Prepared query arguments.
	 * @param string $output wpdb row output format.
	 * @return mixed
	 */
	private static function execute_read( $method, $query, $args, $output ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- This is the centralized cache-aware read boundary.
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Query values are prepared below.
		// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Only fixed read methods are dispatched and values are prepared.
		$sql = empty( $args ) ? $query : $wpdb->prepare( $query, $args );
		switch ( $method ) {
			case 'get_row':
				return $wpdb->get_row( $sql, $output );
			case 'get_results':
				return $wpdb->get_results( $sql );
			case 'get_var':
				return $wpdb->get_var( $sql );
			case 'get_col':
				return $wpdb->get_col( $sql );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		return null;
	}

	/**
	 * Normalize a positive cache lifetime.
	 *
	 * @param int $expiration Lifetime in seconds.
	 * @return int
	 */
	private static function normalize_expiration( $expiration ) {
		$expiration = (int) $expiration;
		if ( $expiration <= 0 ) {
			$expiration = self::get_expiration();
		}
		return $expiration > 0 ? $expiration : self::EXPIRATION;
	}

	/**
	 * Normalize context values for deterministic cache keys.
	 *
	 * @param mixed $value Context value.
	 * @return mixed
	 */
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
