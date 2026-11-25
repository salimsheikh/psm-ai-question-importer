<?php
/**
 * Backup and restore for the configured shared question tables.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table and column identifiers come from fixed whitelists; all restored values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.

class PSM_Backup_Restore {

	use PSM_UI_Components_Trait;

	const FORMAT_VERSION = 1;
	const MAX_FILE_SIZE  = 25194240;

	public function __construct() {
		add_action( 'admin_post_psm_export_backup', array( $this, 'export_backup' ) );
		add_action( 'admin_post_psm_restore_backup', array( $this, 'restore_backup' ) );
	}

	public function render() {
		$table_counts = array();
		foreach ( $this->get_table_order() as $suffix ) {
			$table = PSM_AI_TABLE_PREFIX . $suffix;
			if ( $this->table_exists( $table ) ) {
				$table_counts[ $suffix ] = (int) PSM_Cache::get_var( 'SELECT COUNT(*) FROM `' . $table . '`', array() );
			}
		}
		?>
		<div class="psm-table-card">
			<h2>Backup &amp; Restore</h2>
			<p class="description">
				Download a portable JSON backup of the configured <code><?php echo esc_html( PSM_AI_TABLE_PREFIX ); ?></code> tables,
				or restore a previously downloaded backup. Provider credentials and WordPress options are never included.
			</p>

			<div class="psm-backup-grid">
				<section class="psm-backup-card">
					<h3>Create Backup</h3>
					<p>Includes table structure for reference and all rows from the configured shared master, question, tag, and batch tables.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
						<?php wp_nonce_field( 'psm_export_backup' ); ?>
						<input type="hidden" name="action" value="psm_export_backup">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Download Backup',
							'psm-btn-primary',
							array( 'type' => 'submit' )
						);
						?>
					</form>
				</section>

				<section class="psm-backup-card">
					<h3>Restore Backup</h3>
					<p>Maximum upload size is 24 MB. Restore uses the current table structure and never executes SQL from the backup.</p>
					<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
						data-psm-confirm
						data-confirm-title="Restore this backup?"
						data-confirm-message="Review the restore mode carefully. Existing matching records may be updated or replaced."
						data-confirm-label="Restore Backup">
						<?php wp_nonce_field( 'psm_restore_backup' ); ?>
						<input type="hidden" name="action" value="psm_restore_backup">
						<p>
							<label for="psm_restore_mode"><strong>Restore mode</strong></label><br>
							<select name="restore_mode" id="psm_restore_mode">
								<option value="merge">Merge: insert new records and update matching records</option>
								<option value="replace">Replace all: clear these tables before restoring the full backup</option>
							</select>
						</p>
						<p>
							<label for="psm_restore_file"><strong>Backup file</strong></label><br>
							<input type="file" name="backup_file" id="psm_restore_file" accept=".json,application/json" required>
						</p>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Restore Backup',
							'psm-btn-primary',
							array( 'type' => 'submit' )
						);
						?>
					</form>
				</section>
			</div>

			<h3>Included Tables</h3>
			<table class="widefat striped">
				<thead><tr><th>Table</th><th>Current Rows</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ( $this->get_table_order() as $suffix ) : ?>
					<tr>
						<td><code><?php echo esc_html( PSM_AI_TABLE_PREFIX . $suffix ); ?></code></td>
						<td><?php echo isset( $table_counts[ $suffix ] ) ? esc_html( number_format_i18n( $table_counts[ $suffix ] ) ) : '&mdash;'; ?></td>
						<td><?php echo isset( $table_counts[ $suffix ] ) ? 'Available' : 'Missing'; ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	public function export_backup() {
		$this->authorize( 'psm_export_backup' );

		global $wpdb;
		$payload = array(
			'format'         => 'psm-ai-question-importer-backup',
			'format_version' => self::FORMAT_VERSION,
			'created_at'     => gmdate( 'c' ),
			'source_prefix'  => PSM_AI_TABLE_PREFIX,
			'tables'         => array(),
		);

		foreach ( $this->get_table_order() as $suffix ) {
			$table = PSM_AI_TABLE_PREFIX . $suffix;
			if ( ! $this->table_exists( $table ) ) {
				continue;
			}
			$create                       = PSM_Cache::get_row( 'SHOW CREATE TABLE `' . $table . '`', array(), false, array(), ARRAY_A );
			$payload['tables'][ $suffix ] = array(
				'schema' => isset( $create['Create Table'] ) ? $create['Create Table'] : '',
				'rows'   => array_map(
					'get_object_vars',
					PSM_Cache::get_results( 'SELECT * FROM `' . $table . '`', array(), false )
				),
			);
		}

		$json = wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		if ( false === $json ) {
			$this->redirect_with_notice( 'The backup could not be encoded as JSON.', true );
		}

		$filename = 'psm-backup-' . gmdate( 'Ymd-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=' . get_option( 'blog_charset' ) );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . strlen( $json ) );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- This is a JSON attachment, not HTML output.
		echo $json;
		exit;
	}

	public function restore_backup() {
		$this->authorize( 'psm_restore_backup' );

		global $wpdb;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- authorize() verifies the nonce; upload fields are validated below.
		$file  = isset( $_FILES['backup_file'] ) ? $_FILES['backup_file'] : array();
		$error = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $error ) {
			$this->redirect_with_notice( 'Please choose a valid backup file to restore.', true );
		}

		$tmp_name  = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
		$size      = isset( $file['size'] ) ? (int) $file['size'] : 0;
		$name      = isset( $file['name'] ) ? (string) $file['name'] : '';
		$extension = strtolower( pathinfo( $name, PATHINFO_EXTENSION ) );
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			$this->redirect_with_notice( 'The backup upload could not be verified.', true );
		}
		if ( $size <= 0 || $size > self::MAX_FILE_SIZE ) {
			$this->redirect_with_notice( 'The backup file must be between 1 byte and 24 MB.', true );
		}
		if ( 'json' !== $extension ) {
			$this->redirect_with_notice( 'Only JSON backup files downloaded from this plugin are supported.', true );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions -- Reads a verified local upload, not a remote URL.
		$raw = file_get_contents( $tmp_name );
		if ( false === $raw ) {
			$this->redirect_with_notice( 'The backup file could not be read.', true );
		}
		$payload = json_decode( $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $payload ) ) {
			$this->redirect_with_notice( 'The backup is not valid JSON.', true );
		}
		if ( 'psm-ai-question-importer-backup' !== ( $payload['format'] ?? '' ) || self::FORMAT_VERSION !== (int) ( $payload['format_version'] ?? 0 ) ) {
			$this->redirect_with_notice( 'This is not a supported PSM AI Question Importer backup.', true );
		}
		if ( ! isset( $payload['tables'] ) || ! is_array( $payload['tables'] ) || empty( $payload['tables'] ) ) {
			$this->redirect_with_notice( 'The backup does not contain any table data.', true );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- authorize() verifies the nonce before restore processing.
		$mode = sanitize_key( wp_unslash( $_POST['restore_mode'] ?? 'merge' ) );
		if ( ! in_array( $mode, array( 'merge', 'replace' ), true ) ) {
			$this->redirect_with_notice( 'Invalid restore mode.', true );
		}

		$order = $this->get_table_order();
		if ( 'replace' === $mode ) {
			$missing = array();
			foreach ( $order as $suffix ) {
				if ( ! isset( $payload['tables'][ $suffix ] ) ) {
					$missing[] = PSM_AI_TABLE_PREFIX . $suffix;
				}
			}
			if ( ! empty( $missing ) ) {
				$this->redirect_with_notice( 'Replace-all mode requires a complete backup. Missing: ' . implode( ', ', $missing ), true );
			}
		}

		$plan = array();
		foreach ( $payload['tables'] as $suffix => $table_data ) {
			if ( ! in_array( $suffix, $order, true ) || ! is_array( $table_data ) || ! isset( $table_data['rows'] ) || ! is_array( $table_data['rows'] ) ) {
				$this->redirect_with_notice( 'The backup contains an invalid or unsupported table.', true );
			}
			$table = PSM_AI_TABLE_PREFIX . $suffix;
			if ( ! $this->table_exists( $table ) ) {
				$this->redirect_with_notice( 'A required plugin table is missing: ' . $table, true );
			}
			$columns = PSM_Cache::get_col( 'SHOW COLUMNS FROM `' . $table . '`', array(), false );
			foreach ( $table_data['rows'] as $row ) {
				if ( ! is_array( $row ) ) {
					$this->redirect_with_notice( 'The backup contains an invalid row in ' . $table . '.', true );
				}
				foreach ( array_keys( $row ) as $column ) {
					if ( ! in_array( $column, $columns, true ) ) {
						$this->redirect_with_notice( 'The backup contains an unsupported column in ' . $table . '.', true );
					}
				}
				foreach ( $row as $value ) {
					if ( ! is_scalar( $value ) && null !== $value ) {
						$this->redirect_with_notice( 'The backup contains a non-scalar value in ' . $table . '.', true );
					}
				}
			}
			$plan[ $suffix ] = $table_data['rows'];
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			$this->redirect_with_notice( 'The restore transaction could not be started.', true );
		}
		try {
			if ( 'replace' === $mode ) {
				foreach ( array_reverse( $order ) as $suffix ) {
					$table  = PSM_AI_TABLE_PREFIX . $suffix;
					$result = $wpdb->query( 'DELETE FROM `' . $table . '`' );
					if ( false === $result ) {
						throw new RuntimeException( 'Could not clear ' . $table . '.' );
					}
				}
			}

			$affected = 0;
			foreach ( $order as $suffix ) {
				if ( ! isset( $plan[ $suffix ] ) ) {
					continue;
				}
				$table = PSM_AI_TABLE_PREFIX . $suffix;
				foreach ( $plan[ $suffix ] as $row ) {
					$result = $this->upsert_row( $table, $row );
					if ( false === $result ) {
						throw new RuntimeException( 'Could not restore ' . $table . '.' );
					}
					$affected += (int) $result;
				}
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				throw new RuntimeException( 'Could not commit the restore.' );
			}
		} catch ( Throwable $error ) {
			$wpdb->query( 'ROLLBACK' );
			$this->redirect_with_notice( 'Restore failed and was rolled back. ' . $error->getMessage(), true );
		}

		PSM_Cache::delete_all();
		$this->redirect_with_notice( sprintf( 'Backup restored successfully in %s mode. %d rows affected.', $mode, $affected ), false );
	}

	private function upsert_row( $table, $row ) {
		global $wpdb;
		if ( empty( $row ) ) {
			return 0;
		}

		$columns      = array_keys( $row );
		$placeholders = array();
		$values       = array();
		$updates      = array();
		foreach ( $row as $column => $value ) {
			$quoted_column = '`' . $column . '`';
			if ( null === $value ) {
				$placeholders[] = 'NULL';
			} else {
				$placeholders[] = '%s';
				$values[]       = $value;
			}
			$updates[] = $quoted_column . '=VALUES(' . $quoted_column . ')';
		}

		$column_sql = implode(
			', ',
			array_map(
				static function ( $column ) {
					return '`' . $column . '`';
				},
				$columns
			)
		);
		$sql        = 'INSERT INTO `' . $table . '` (' . $column_sql . ') VALUES (' . implode( ', ', $placeholders ) . ') ON DUPLICATE KEY UPDATE ' . implode( ', ', $updates );
		if ( empty( $values ) ) {
			return $wpdb->query( $sql );
		}
		return $wpdb->query( $wpdb->prepare( $sql, $values ) );
	}

	private function get_table_order() {
		return array( 'domains', 'skill_types', 'question_types', 'difficulties', 'skills', 'tags', 'questions', 'question_tags', 'batches', 'batch_questions' );
	}

	private function table_exists( $table ) {
		global $wpdb;
		return (bool) PSM_Cache::get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ), array(), false );
	}

	private function authorize( $action ) {
		check_admin_referer( $action );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'psm-ai-question-importer' ) );
		}
	}

	private function redirect_with_notice( $message, $is_error ) {
		set_transient(
			'psm_admin_notice_' . get_current_user_id(),
			array(
				'type' => $is_error ? 'error' : 'success',
				'text' => $message,
			),
			60
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'psm-ai-importer',
					'tab'  => 'backup',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
