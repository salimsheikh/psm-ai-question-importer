<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request data is explicitly parsed or sanitized by the workflow that consumes it.

trait PSM_Admin_Batches_Trait {

	public function get_batches_list_data( $requested_page = 1 ) {
		global $wpdb;
		$per_page    = $this->psm_get_per_page();
		$batch_total = (int) PSM_Cache::get_or_create(
			'admin_batches_count',
			array( 'table' => PSM_AI_TABLE_PREFIX . 'batches' ),
			static function () {
				return PSM_Cache::get_var( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'batches', array(), false );
			},
			PSM_Cache::get_expiration()
		);
		$total_pages  = max( 1, (int) ceil( $batch_total / $per_page ) );
		$page         = min( $total_pages, max( 1, absint( $requested_page ) ) );
		$offset       = ( $page - 1 ) * $per_page;
		$batches      = PSM_Cache::get_or_create(
			'admin_batches',
			array(
				'page'     => $page,
				'per_page' => $per_page,
			),
			static function () use ( $wpdb, $offset, $per_page ) {
				return PSM_Cache::get_results(
					$wpdb->prepare(
						'SELECT b.*, s.name AS skill_name,
						        (SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'batch_questions bq WHERE bq.batch_id = b.id) AS linked_questions_count
						 FROM ' . PSM_AI_TABLE_PREFIX . 'batches b
						 LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON b.skill_id = s.id
						 ORDER BY b.id DESC LIMIT %d OFFSET %d',
						$per_page,
						$offset
					),
					array(),
					false
				);
			},
			PSM_Cache::get_expiration()
		);

		return array(
			'batches'     => $batches,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => $batch_total,
			'total_pages' => $total_pages,
		);
	}

	public function render_batches_list_html( $data ) {
		$batches = $data['batches'];
		$html    = '<div class="psm-batches-list" id="psm_batches_list" data-page="' . esc_attr( $data['page'] ) . '">';
		$html   .= '<table class="widefat striped psm-batch-table"><thead><tr><th>ID</th><th>Batch Code</th><th>Label Name</th><th>Skill</th><th>AI / Source</th><th>Imported / Linked</th><th>Skipped</th><th>Created</th><th>Actions</th></tr></thead><tbody>';
		if ( empty( $batches ) ) {
			$html .= '<tr><td colspan="9">No batches recorded yet.</td></tr>';
		} else {
			foreach ( $batches as $batch ) {
				$html .= '<tr id="psm-row-batch-' . esc_attr( $batch->id ) . '">';
				$html .= '<td>' . esc_html( $batch->id ) . '</td>';
				$html .= '<td><code>' . esc_html( $batch->batch_code ) . '</code></td>';
				$html .= '<td><strong>' . esc_html( $batch->label_name ) . '</strong></td>';
				$html .= '<td><span class="psm-badge psm-badge-type">' . esc_html( $batch->skill_name ? $batch->skill_name : '—' ) . '</span></td>';
				$html .= '<td>' . esc_html( $batch->provider . ( $batch->model ? ' (' . $batch->model . ')' : '' ) ) . '</td>';
				$html .= '<td>' . esc_html( $batch->total_imported . ' / ' . $batch->linked_questions_count ) . '</td>';
				$html .= '<td>' . esc_html( $batch->total_skipped ) . '</td>';
				$html .= '<td>' . esc_html( $this->local_time( $batch->created_at ) ) . '</td><td>';
				$html .= '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psm_download_labels&batch_id=' . $batch->id ), 'psm_download_labels_nonce' ) ) . '" class="psm-icon-btn" style="background:#dcfce7;color:#15803d;" data-tooltip="Download Label File" aria-label="Download Label File"><span class="dashicons dashicons-download" aria-hidden="true"></span></a>';
				$html .= $this->psm_button_html(
					'',
					'psm-icon-btn psm-btn-edit psm-open-modal',
					array(
						'data-tooltip' => 'Edit Batch Label',
						'aria-label'  => 'Edit Batch Label',
						'data-entity'  => 'batch',
						'data-record'  => (string) wp_json_encode( $batch, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
					),
					'dashicons-edit',
					''
				);
				$html .= $this->psm_button_html(
					'',
					'psm-icon-btn psm-btn-delete psm-ajax-delete',
					array(
						'data-tooltip' => 'Delete Batch',
						'aria-label'  => 'Delete Batch',
						'data-entity'  => 'batch',
						'data-id'      => $batch->id,
						'data-name'    => $batch->batch_code,
					),
					'dashicons-trash',
					''
				);
				$html .= '</td></tr>';
			}
		}
		$html .= '</tbody></table>';
		$html .= $this->psm_pagination_html(
			$data['page'],
			$data['total_pages'],
			$data['total'],
			$data['per_page'],
			'batch_page',
			'batches',
			add_query_arg(
				array(
					'page' => 'psm-ai-importer',
					'tab'  => 'batches',
				),
				admin_url( 'admin.php' )
			)
		);
		$html .= '</div>';
		return $html;
	}

	public function ajax_get_batches() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$data = $this->get_batches_list_data( absint( $_POST['batch_page'] ?? 1 ) );
		wp_send_json_success(
			array(
				'html'        => $this->render_batches_list_html( $data ),
				'page'        => $data['page'],
				'total_pages' => $data['total_pages'],
				'total'       => $data['total'],
			)
		);
	}

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
			$batch = PSM_Cache::get_row( $wpdb->prepare( "SELECT * FROM $b_table WHERE id = %d", $batch_id ), array(), false );
			if ( ! $batch ) {
				wp_die( 'Batch not found.' );
			}
			$rows     = PSM_Cache::get_results(
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
				),
				array(),
				false
			);
			$filename = sanitize_file_name( $batch->batch_code . '-labels.csv' );
		} else {
			$rows     = PSM_Cache::get_results(
				"SELECT q.id AS question_id, q.difficulty, df.name AS difficulty_name, q.question_type, q.question_text, q.status,
				        s.name AS skill_name,
				        COALESCE((SELECT b.batch_code FROM $bq_table bq INNER JOIN $b_table b ON bq.batch_id = b.id WHERE bq.question_id = q.id ORDER BY bq.batch_id DESC LIMIT 1), '') AS batch_code,
				        COALESCE((SELECT b.label_name FROM $bq_table bq INNER JOIN $b_table b ON bq.batch_id = b.id WHERE bq.question_id = q.id ORDER BY bq.batch_id DESC LIMIT 1), '') AS label_name
				 FROM $q_table q
				 LEFT JOIN $s_table s ON q.skill_id = s.id
				 LEFT JOIN $d_table df ON q.difficulty = df.id
				 ORDER BY q.id DESC LIMIT 500",
				array(),
				false
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
			$tag_slugs = PSM_Cache::get_col(
				$wpdb->prepare(
					"SELECT t.slug FROM $qt_table qt INNER JOIN $t_table t ON qt.tag_id = t.id WHERE qt.question_id = %d",
					$r->question_id
				),
				array(),
				false
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
