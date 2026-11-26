<?php
/**
 * Non-caching database write boundary for shared plugin tables.
 *
 * @package PSM_AI_Question_Importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Writes must not be cached; workflows invalidate reads after a successful commit.
// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SQL structure is fixed here and values are prepared.
// phpcs:disable PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic identifiers are validated against the fixed plugin table list before use.

/**
 * Executes validated plugin writes without caching their results.
 */
class PSM_Database {

	const TABLES = array( 'domains', 'skill_types', 'question_types', 'difficulties', 'skills', 'tags', 'questions', 'question_tags', 'batches', 'batch_questions' );

	/**
	 * Insert a row into a shared plugin table.
	 *
	 * @param string $table  Validated table name.
	 * @param array  $data   Column values.
	 * @param array  $format Optional value formats.
	 * @return int|false
	 */
	public static function insert( $table, $data, $format = null ) {
		global $wpdb;

		if ( ! self::is_plugin_table( $table ) ) {
			return false;
		}

		return $wpdb->insert( $table, $data, $format );
	}

	/**
	 * Update rows in a shared plugin table.
	 *
	 * @param string $table       Validated table name.
	 * @param array  $data        Column values.
	 * @param array  $where       Row conditions.
	 * @param array  $format      Optional value formats.
	 * @param array  $where_format Optional condition formats.
	 * @return int|false
	 */
	public static function update( $table, $data, $where, $format = null, $where_format = null ) {
		global $wpdb;

		if ( ! self::is_plugin_table( $table ) ) {
			return false;
		}

		return $wpdb->update( $table, $data, $where, $format, $where_format );
	}

	/**
	 * Delete rows from a shared plugin table.
	 *
	 * @param string $table  Validated table name.
	 * @param array  $where  Row conditions.
	 * @param array  $format Optional condition formats.
	 * @return int|false
	 */
	public static function delete( $table, $where, $format = null ) {
		global $wpdb;

		if ( ! self::is_plugin_table( $table ) ) {
			return false;
		}

		return $wpdb->delete( $table, $where, $format );
	}

	/**
	 * Replace a row in a shared plugin table.
	 *
	 * @param string $table  Validated table name.
	 * @param array  $data   Column values.
	 * @param array  $format Optional value formats.
	 * @return int|false
	 */
	public static function replace( $table, $data, $format = null ) {
		global $wpdb;

		if ( ! self::is_plugin_table( $table ) ) {
			return false;
		}

		return $wpdb->replace( $table, $data, $format );
	}

	/**
	 * Start a database transaction.
	 *
	 * @return int|false
	 */
	public static function start_transaction() {
		global $wpdb;
		return $wpdb->query( 'START TRANSACTION' );
	}

	/**
	 * Commit the active database transaction.
	 *
	 * @return int|false
	 */
	public static function commit() {
		global $wpdb;
		return $wpdb->query( 'COMMIT' );
	}

	/**
	 * Roll back the active database transaction.
	 *
	 * @return int|false
	 */
	public static function rollback() {
		global $wpdb;
		return $wpdb->query( 'ROLLBACK' );
	}

	/**
	 * Clear a validated shared plugin table inside a restore transaction.
	 *
	 * @param string $table Validated table name.
	 * @return int|false
	 */
	public static function clear_table( $table ) {
		global $wpdb;

		if ( ! self::is_plugin_table( $table ) ) {
			return false;
		}

		return $wpdb->query( 'DELETE FROM `' . $table . '`' );
	}

	/**
	 * Upsert one validated backup row using prepared values.
	 *
	 * @param string $table Validated table name.
	 * @param array  $row   Column values already checked against the live schema.
	 * @return int|false
	 */
	public static function upsert_row( $table, $row ) {
		global $wpdb;

		if ( ! self::is_plugin_table( $table ) || ! is_array( $row ) || empty( $row ) ) {
			return empty( $row ) ? 0 : false;
		}

		$columns      = array_keys( $row );
		$placeholders = array();
		$values       = array();
		$updates      = array();
		foreach ( $row as $column => $value ) {
			if ( ! self::is_identifier( $column ) ) {
				return false;
			}
			$quoted_column = '`' . $column . '`';
			if ( null === $value ) {
				$placeholders[] = 'NULL';
			} else {
				$placeholders[] = '%s';
				$values[]       = $value;
			}
			$updates[] = $quoted_column . '=VALUES(' . $quoted_column . ')';
		}

		$column_sql = '`' . implode( '`, `', $columns ) . '`';
		$sql        = 'INSERT INTO `' . $table . '` (' . $column_sql . ') VALUES (' . implode( ', ', $placeholders ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		$sql        = empty( $values ) ? $sql : $wpdb->prepare( $sql, $values );

		return $wpdb->query( $sql );
	}

	/**
	 * Assign a skill type ID to legacy rows that still match its name.
	 *
	 * @param string $skills_table Validated skills table name.
	 * @param int    $type_id      Skill type ID.
	 * @param string $type_name    Skill type name.
	 * @return int|false
	 */
	public static function assign_skill_type_ids( $skills_table, $type_id, $type_name ) {
		global $wpdb;

		$type_id = absint( $type_id );
		if ( ! self::is_plugin_table( $skills_table ) || PSM_AI_TABLE_PREFIX . 'skills' !== $skills_table || ! $type_id ) {
			return false;
		}

		return $wpdb->query(
			$wpdb->prepare(
				'UPDATE ' . $skills_table . ' SET skill_type_id = %d WHERE skill_type_id = 0 AND LOWER(skill_type) = LOWER(%s)',
				$type_id,
				(string) $type_name
			)
		);
	}

	/**
	 * Delete plugin-owned option rows by a fixed prefix during uninstall.
	 *
	 * @param string $prefix Option-name prefix.
	 * @return int|false
	 */
	public static function delete_options_like( $prefix ) {
		global $wpdb;

		$prefix = (string) $prefix;
		if ( ! preg_match( '/^[a-z0-9_]+_$/i', $prefix ) ) {
			return false;
		}

		$like = '%' . $wpdb->esc_like( $prefix ) . '%';
		return $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s',
				$like
			)
		);
	}

	/**
	 * Check a table name against the fixed shared-table allowlist.
	 *
	 * @param string $table Table name.
	 * @return bool
	 */
	private static function is_plugin_table( $table ) {
		if ( ! defined( 'PSM_AI_TABLE_PREFIX' ) || ! is_string( $table ) ) {
			return false;
		}

		$prefix = PSM_AI_TABLE_PREFIX;
		if ( 0 !== strpos( $table, $prefix ) ) {
			return false;
		}

		return in_array( substr( $table, strlen( $prefix ) ), self::TABLES, true );
	}

	/**
	 * Check a column identifier before quoting it into SQL.
	 *
	 * @param string $identifier Column name.
	 * @return bool
	 */
	private static function is_identifier( $identifier ) {
		return is_string( $identifier ) && preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier );
	}
}
