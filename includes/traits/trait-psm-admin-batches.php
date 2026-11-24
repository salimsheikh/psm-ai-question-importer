<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request data is explicitly parsed or sanitized by the workflow that consumes it.

trait PSM_Admin_Batches_Trait {

	public function handle_download_labels() {
		check_admin_referer( 'psm_download_labels_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$batch_id = absint( $_GET['batch_id'] ?? 0 );

		$q_table  = PSM_AI_TABLE_PREFIX . 'questions';
		$s_table  = PSM_AI_TABLE_PREFIX . 'skills';
		$b_table  = PSM_AI_TABLE_PREFIX . 'batches';
		$bq_table = PSM_AI_TABLE_PREFIX . 'batch_questions';
		$qt_table = PSM_AI_TABLE_PREFIX . 'question_tags';
		$t_table  = PSM_AI_TABLE_PREFIX . 'tags';
		$d_table  = PSM_AI_TABLE_PREFIX . 'difficulties';

		if ( $batch_id > 0 ) {
			$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $b_table WHERE id = %d", $batch_id ) );
			if ( ! $batch ) {
				wp_die( 'Batch not found.' );
			}
			$rows     = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT q.id AS question_id, q.difficulty, df.name AS difficulty_name, q.question_type, q.question_text, q.status,
					        s.name AS skill_name, b.batch_code, b.label_name
					 FROM $bq_table bq
					 INNER JOIN $q_table q ON bq.question_id = q.id
					 INNER JOIN $b_table b ON bq.batch_id = b.id
					 LEFT JOIN $s_table s ON q.skill_id = s.id
					 LEFT JOIN $d_table df ON q.difficulty = df.id
					 WHERE bq.batch_id = %d
					 ORDER BY bq.import_order ASC, q.id ASC",
					$batch_id
				)
			);
			$filename = sanitize_file_name( $batch->batch_code . '-labels.csv' );
		} else {
			$rows     = $wpdb->get_results(
				"SELECT q.id AS question_id, q.difficulty, df.name AS difficulty_name, q.question_type, q.question_text, q.status,
				        s.name AS skill_name,
				        COALESCE((SELECT b.batch_code FROM $bq_table bq INNER JOIN $b_table b ON bq.batch_id = b.id WHERE bq.question_id = q.id ORDER BY bq.batch_id DESC LIMIT 1), '') AS batch_code,
				        COALESCE((SELECT b.label_name FROM $bq_table bq INNER JOIN $b_table b ON bq.batch_id = b.id WHERE bq.question_id = q.id ORDER BY bq.batch_id DESC LIMIT 1), '') AS label_name
				 FROM $q_table q
				 LEFT JOIN $s_table s ON q.skill_id = s.id
				 LEFT JOIN $d_table df ON q.difficulty = df.id
				 ORDER BY q.id DESC LIMIT 500"
			);
			$filename = 'psm-question-labels.csv';
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Label', 'Batch Code', 'Question ID', 'Skill', 'Difficulty ID', 'Difficulty', 'Question Type Slug', 'Tags', 'Status', 'Question Text' ) );

		foreach ( $rows as $r ) {
			$tag_slugs = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT t.slug FROM $qt_table qt INNER JOIN $t_table t ON qt.tag_id = t.id WHERE qt.question_id = %d",
					$r->question_id
				)
			);
			fputcsv(
				$out,
				array(
					$this->csv_cell( $r->label_name ? $r->label_name : ( $r->skill_name . ' #' . $r->question_id ) ),
					$this->csv_cell( $r->batch_code ),
					$this->csv_cell( $r->question_id ),
					$this->csv_cell( $r->skill_name ),
					$this->csv_cell( $r->difficulty ),
					$this->csv_cell( $r->difficulty_name ? $r->difficulty_name : $r->difficulty ),
					$this->csv_cell( $r->question_type ),
					$this->csv_cell( implode( '|', $tag_slugs ) ),
					$this->csv_cell( $r->status ),
					$this->csv_cell( wp_strip_all_tags( $r->question_text ) ),
				)
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- WP_Filesystem cannot manage the php://output stream.
		fclose( $out );
		exit;
	}

	/**
	 * Prevent spreadsheet formula injection in exported cells.
	 */
	private function csv_cell( $value ) {
		$value = (string) $value;
		if ( '' !== $value && preg_match( '/^(?:[\s]*[=+\-@]|[\t\r])/', $value ) ) {
			return "'" . $value;
		}
		return $value;
	}

	/**
	 * Convert a stored UTC datetime to the site's local time for display.
	 */
	private function local_time( $gmt_mysql ) {
		if ( empty( $gmt_mysql ) ) {
			return '';
		}
		$timestamp = strtotime( $gmt_mysql . ' UTC' );
		return $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : (string) $gmt_mysql;
	}
}
