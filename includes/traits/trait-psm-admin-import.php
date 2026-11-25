<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request data is explicitly parsed or sanitized by the workflow that consumes it.

trait PSM_Admin_Import_Trait {

	/**
	 * Import questions from an uploaded .json file, then show the normal preview.
	 */
	public function handle_import_json_file() {
		check_admin_referer( 'psm_import_json_file_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$skill_id  = absint( $_POST['skill_id'] ?? 0 );
		$domain_id = absint( $_POST['domain_id'] ?? 0 );
		$skill     = PSM_Cache::get_or_create(
			'import_skill',
			array( 'skill_id' => $skill_id ),
			static function () use ( $wpdb, $skill_id ) {
				return PSM_Cache::get_row( $wpdb->prepare( 'SELECT id, name, slug, domain_id FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE id = %d', $skill_id ), array(), false );
			}
		);
		if ( ! $skill ) {
			$this->redirect_with_notice( 'Please select a valid Skill for the JSON import.' );
		}
		if ( ! $domain_id || (int) $skill->domain_id !== $domain_id ) {
			$this->redirect_with_notice( 'The selected Skill does not belong to the selected Domain.' );
		}

		$focus_tag_id = absint( $_POST['focus_tag_id'] ?? 0 );
		$focus_tag    = null;
		if ( $focus_tag_id ) {
			$focus_tag = PSM_Cache::get_or_create(
				'import_focus_tag',
				array(
					'tag_id'   => $focus_tag_id,
					'skill_id' => $skill_id,
				),
				static function () use ( $wpdb, $focus_tag_id, $skill_id ) {
					return PSM_Cache::get_row(
						$wpdb->prepare(
							'SELECT id, name, slug FROM ' . PSM_AI_TABLE_PREFIX . "tags WHERE id = %d AND skill_id = %d AND status = 'active'",
							$focus_tag_id,
							$skill_id
						),
						array(),
						false
					);
				}
			);
			if ( ! $focus_tag ) {
				$this->redirect_with_notice( 'The selected Tag Focus does not belong to this skill.' );
			}
		}

		$source_mode = sanitize_key( $_POST['import_source'] ?? 'file' );
		$raw         = '';
		$source_name = 'Pasted JSON';
		if ( 'paste' === $source_mode ) {
			$raw = wp_unslash( $_POST['manual_json_payload'] ?? '' );
			if ( '' === trim( $raw ) ) {
				$this->redirect_with_notice( 'Paste JSON code before importing.' );
			}
			$raw_size = function_exists( 'mb_strlen' ) ? mb_strlen( $raw, '8bit' ) : strlen( $raw );
			if ( $raw_size > 5 * 1024 * 1024 ) {
				$this->redirect_with_notice( 'Pasted JSON must be 5 MB or smaller.' );
			}
		} else {
			$file       = isset( $_FILES['json_file'] ) ? $_FILES['json_file'] : array();
			$error_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
			if ( UPLOAD_ERR_OK !== $error_code ) {
				$this->redirect_with_notice( $this->upload_error_message( $error_code ) );
			}

			$tmp_name = isset( $file['tmp_name'] ) ? (string) $file['tmp_name'] : '';
			if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
				$this->redirect_with_notice( 'The JSON upload could not be verified. Please try again.' );
			}

			$size = (int) ( $file['size'] ?? 0 );
			if ( $size <= 0 || $size > 5 * 1024 * 1024 ) {
				$this->redirect_with_notice( 'The JSON file must be between 1 byte and 5 MB.' );
			}

			$ext = strtolower( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_EXTENSION ) );
			if ( 'json' !== $ext ) {
				$this->redirect_with_notice( 'Only .json files are supported.' );
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions -- Reads a verified local upload, not a remote URL.
			$raw = file_get_contents( $tmp_name );
			if ( false === $raw || '' === trim( $raw ) ) {
				$this->redirect_with_notice( 'The uploaded JSON file is empty or unreadable.' );
			}
			$source_name = sanitize_text_field( pathinfo( (string) ( $file['name'] ?? '' ), PATHINFO_FILENAME ) );
		}

		$validated = $this->validate_json_import_payload( $raw, $skill );
		if ( is_wp_error( $validated ) ) {
			$this->redirect_with_notice( $validated->get_error_message() );
		}

		if ( $focus_tag ) {
			$normalized_questions = PSM_Importer::normalize_question_tags( $validated, $skill->slug, $skill->name );
			$missing_rows         = array();
			foreach ( $normalized_questions as $idx => $q ) {
				$q_tags = array_map( 'strval', (array) ( $q['tags'] ?? array() ) );
				if ( ! in_array( $focus_tag->slug, $q_tags, true ) ) {
					$missing_rows[] = $idx + 1;
				}
			}
			if ( ! empty( $missing_rows ) ) {
				$this->redirect_with_notice(
					sprintf(
						'%1$d of %2$d questions do not include the required Tag Focus "%3$s" (row(s): %4$s). Regenerate the file with the Create Prompt tool or remove the Tag Focus.',
						count( $missing_rows ),
						count( $normalized_questions ),
						$focus_tag->slug,
						implode( ', ', array_slice( $missing_rows, 0, 10 ) ) . ( count( $missing_rows ) > 10 ? ', ...' : '' )
					)
				);
			}
		}

		$label = sanitize_text_field( wp_unslash( $_POST['label_name'] ?? '' ) );
		if ( '' === $label ) {
			$label = $source_name;
		}
		$label = mb_substr( $label, 0, 190 );

		$default_status = sanitize_key( $_POST['default_status'] ?? 'draft' );
		if ( ! in_array( $default_status, array( 'draft', 'published' ), true ) ) {
			$this->redirect_with_notice( 'Import Status must be Draft or Published.' );
		}

		$envelope  = json_decode( $raw, true );
		$file_diff = ( is_array( $envelope ) && isset( $envelope['generation']['difficulty'] ) )
			? sanitize_text_field( (string) $envelope['generation']['difficulty'] )
			: 'all';

		$preview = $this->collect_preview(
			array(
				'domain_id'           => $domain_id,
				'skill_id'            => $skill_id,
				'tag_ids'             => array(),
				'difficulty'          => '' !== $file_diff ? $file_diff : 'all',
				'default_status'      => $default_status,
				'label_name'          => $label,
				'import_mode'         => 'manual_json',
				'manual_json_payload' => $raw,
				'focus_tag_slug'      => $focus_tag ? $focus_tag->slug : '',
			),
			true
		);
		if ( is_wp_error( $preview ) ) {
			$this->redirect_with_notice( $preview->get_error_message() );
		}

		set_transient( 'psm_preview_data_' . get_current_user_id(), $preview, 1800 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => 'psm-ai-importer',
					'tab'     => 'generate',
					'preview' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Validate an uploaded JSON envelope against the import schema.
	 *
	 * @return array|WP_Error Questions array on success.
	 */
	private function validate_json_import_payload( $raw, $skill ) {
		$decoded = json_decode( (string) $raw, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error( 'invalid_json', 'Invalid JSON: ' . json_last_error_msg() );
		}
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'invalid_json', 'The JSON root must be an object or an array.' );
		}

		if ( isset( $decoded['schema_version'] ) && '1.0' !== (string) $decoded['schema_version'] ) {
			return new WP_Error( 'unsupported_schema', 'Unsupported schema_version. Expected "1.0".' );
		}

		if ( isset( $decoded['skill'] ) && is_array( $decoded['skill'] ) ) {
			$envelope_slug = PSM_Seeder::make_slug( (string) ( $decoded['skill']['slug'] ?? '' ) );
			if ( '' !== $envelope_slug && $envelope_slug !== $skill->slug ) {
				return new WP_Error(
					'skill_mismatch',
					'This JSON file is for skill "' . $envelope_slug . '" but the selected skill is "' . $skill->slug . '". Select the matching skill and try again.'
				);
			}
		}

		$questions = array_key_exists( 'questions', $decoded ) ? $decoded['questions'] : $decoded;
		if ( ! is_array( $questions ) || empty( $questions ) ) {
			return new WP_Error( 'missing_questions', 'The JSON file must contain a non-empty "questions" array.' );
		}

		if ( isset( $decoded['generation']['total_questions'] ) ) {
			$declared = $decoded['generation']['total_questions'];
			if ( ! is_numeric( $declared ) || count( $questions ) !== (int) $declared ) {
				return new WP_Error(
					'count_mismatch',
					'generation.total_questions must equal the number of questions in the file (' . count( $questions ) . ').'
				);
			}
		}

		$difficulty_mode = 'all';
		if ( isset( $decoded['generation']['difficulty'] ) ) {
			$difficulty_mode = strtolower( sanitize_text_field( (string) $decoded['generation']['difficulty'] ) );
			if ( 'mixed' === $difficulty_mode ) {
				$difficulty_mode = 'all';
			}
			if ( ! in_array( $difficulty_mode, array( 'all', 'beginner', 'intermediate', 'advanced' ), true ) ) {
				return new WP_Error( 'invalid_difficulty', 'generation.difficulty must be one of: "all", "beginner", "intermediate", "advanced".' );
			}
		}

		$allowed_types = array( 'single_choice', 'multiple_choice', 'two_option', 'true_false' );
		$external_ids  = array();
		$parsed_ids    = array();

		foreach ( $questions as $idx => $q ) {
			$row = $idx + 1;
			if ( ! is_array( $q ) ) {
				return new WP_Error( 'invalid_question', 'Question #' . $row . ' is not a JSON object.' );
			}

			$q_type = sanitize_key( (string) ( $q['question_type'] ?? '' ) );
			if ( ! in_array( $q_type, $allowed_types, true ) ) {
				return new WP_Error(
					'invalid_question_type',
					'Question #' . $row . ' has an invalid "question_type". Allowed values: ' . implode( ', ', $allowed_types ) . '.'
				);
			}

			$q_diff = strtolower( trim( (string) ( $q['difficulty'] ?? '' ) ) );
			if ( ! in_array( $q_diff, array( 'beginner', 'intermediate', 'advanced' ), true ) ) {
				return new WP_Error(
					'invalid_question_difficulty',
					'Question #' . $row . ' must have "difficulty" set to beginner, intermediate, or advanced.'
				);
			}
			if ( 'all' !== $difficulty_mode && $q_diff !== $difficulty_mode ) {
				return new WP_Error(
					'difficulty_mismatch',
					'Question #' . $row . ' uses difficulty "' . $q_diff . '" but generation.difficulty is "' . $difficulty_mode . '". All questions must match the file difficulty.'
				);
			}

			$answer_mode   = strtolower( trim( (string) ( $q['answer_mode'] ?? '' ) ) );
			$expected_mode = ( 'multiple_choice' === $q_type ) ? 'multiple' : 'single';
			if ( $answer_mode !== $expected_mode ) {
				return new WP_Error(
					'answer_mode_mismatch',
					'Question #' . $row . ' must use "answer_mode": "' . $expected_mode . '" for ' . $q_type . '.'
				);
			}

			$options          = $q['options'] ?? array();
			$opt_count        = is_array( $options ) ? count( $options ) : 0;
			$expected_options = ( 'two_option' === $q_type || 'true_false' === $q_type ) ? 2 : 4;
			if ( $opt_count !== $expected_options ) {
				return new WP_Error(
					'option_count_mismatch',
					'Question #' . $row . ' must have exactly ' . $expected_options . ' options for ' . $q_type . ' (found ' . $opt_count . ').'
				);
			}

			$correct_raw = $q['correct_answers'] ?? ( $q['correct_keys'] ?? array() );
			if ( is_string( $correct_raw ) ) {
				$correct_raw = preg_split( '/[,;|]/', $correct_raw, -1, PREG_SPLIT_NO_EMPTY );
			}
			$correct       = is_array( $correct_raw )
				? array_values( array_filter( array_map( 'trim', array_map( 'strval', $correct_raw ) ), 'strlen' ) )
				: array();
			$correct_count = count( $correct );
			if ( 'multiple_choice' === $q_type ) {
				if ( $correct_count < 2 ) {
					return new WP_Error( 'too_few_correct', 'Question #' . $row . ' (multiple_choice) needs at least two correct answers.' );
				}
			} elseif ( 1 !== $correct_count ) {
				return new WP_Error( 'invalid_correct_count', 'Question #' . $row . ' (' . $q_type . ') needs exactly one correct answer.' );
			}

			$eid = trim( (string) ( $q['external_id'] ?? '' ) );
			if ( '' === $eid ) {
				return new WP_Error( 'missing_external_id', 'Question #' . $row . ' is missing "external_id".' );
			}
			if ( isset( $external_ids[ $eid ] ) ) {
				return new WP_Error( 'duplicate_external_id', 'Duplicate "external_id" found: "' . $eid . '". Every external_id must be unique.' );
			}
			$external_ids[ $eid ] = true;

			if ( preg_match( '/^(.*)-(\d+)$/', $eid, $m ) ) {
				$parsed_ids[ $eid ] = array( $m[1], (int) $m[2] );
			}
		}

		if ( count( $parsed_ids ) === count( $questions ) ) {
			$prefixes = array_unique(
				array_map(
					static function ( $pair ) {
						return $pair[0];
					},
					$parsed_ids
				)
			);
			if ( 1 === count( $prefixes ) ) {
				$sequence = array_map(
					static function ( $pair ) {
						return $pair[1];
					},
					array_values( $parsed_ids )
				);
				sort( $sequence );
				if ( range( 1, count( $questions ) ) !== $sequence ) {
					return new WP_Error( 'external_id_sequence', 'external_id values must increment sequentially from 001 to ' . str_pad( (string) count( $questions ), 3, '0', STR_PAD_LEFT ) . ' within the same prefix.' );
				}
			}
		}

		return $questions;
	}

	private function upload_error_message( $code ) {
		$messages = array(
			UPLOAD_ERR_INI_SIZE   => 'The file exceeds the server upload_max_filesize limit.',
			UPLOAD_ERR_FORM_SIZE  => 'The file exceeds the form MAX_FILE_SIZE limit.',
			UPLOAD_ERR_PARTIAL    => 'The file was only partially uploaded. Please try again.',
			UPLOAD_ERR_NO_FILE    => 'Please choose a .json file to import.',
			UPLOAD_ERR_NO_TMP_DIR => 'The server has no temporary folder for uploads.',
			UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file.',
			UPLOAD_ERR_EXTENSION  => 'A PHP extension stopped the upload.',
		);
		return $messages[ $code ] ?? 'The JSON upload failed (error code ' . (int) $code . ').';
	}

	private function redirect_with_notice( $message, $type = 'error' ) {
		set_transient(
			'psm_admin_notice_' . get_current_user_id(),
			array(
				'type' => $type,
				'text' => $message,
			),
			60
		);
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'psm-ai-importer',
					'tab'  => 'generate',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_confirm_import() {
		check_admin_referer( 'psm_confirm_import_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$user_id = get_current_user_id();
		$preview = get_transient( 'psm_preview_data_' . $user_id );

		if ( empty( $preview ) || empty( $preview['questions'] ) ) {
			set_transient(
				'psm_admin_notice_' . $user_id,
				array(
					'type' => 'error',
					'text' => 'Preview expired or not found.',
				),
				60
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'psm-ai-importer',
						'tab'  => 'generate',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		if ( ! empty( $_POST['cancel_preview'] ) ) {
			delete_transient( 'psm_preview_data_' . $user_id );
			set_transient(
				'psm_admin_notice_' . $user_id,
				array(
					'type' => 'info',
					'text' => 'Preview discarded.',
				),
				60
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'psm-ai-importer',
						'tab'  => 'generate',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$selected_indices = array_map( 'intval', (array) ( $_POST['selected_rows'] ?? array() ) );
		$chosen_questions = array();
		foreach ( $preview['questions'] as $idx => $q ) {
			if ( in_array( (int) $idx, $selected_indices, true ) ) {
				$chosen_questions[] = $q;
			}
		}

		if ( empty( $chosen_questions ) ) {
			set_transient(
				'psm_admin_notice_' . $user_id,
				array(
					'type' => 'error',
					'text' => 'Select at least one row to import.',
				),
				60
			);
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'    => 'psm-ai-importer',
						'tab'     => 'generate',
						'preview' => '1',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$import_res = PSM_Importer::import_questions(
			$chosen_questions,
			$preview['skill_id'],
			$preview['tag_ids'],
			$preview['default_status'],
			array(
				'label_name'         => $preview['label_name'],
				'provider'           => $preview['provider'],
				'model'              => $preview['model'],
				'prompt_text'        => $preview['prompt_text'],
				'rules_text'         => $preview['rules_text'],
				'default_difficulty' => $preview['default_difficulty'] ?? 1,
			)
		);

		delete_transient( 'psm_preview_data_' . $user_id );

		if ( is_wp_error( $import_res ) ) {
			set_transient(
				'psm_admin_notice_' . $user_id,
				array(
					'type' => 'error',
					'text' => $import_res->get_error_message(),
				),
				60
			);
		} else {
			$msg = sprintf(
				'Batch [%s] imported for %s: %d inserted, %d duplicates skipped, %d invalid skipped.',
				$import_res['batch_code'],
				$preview['skill_name'],
				$import_res['inserted'],
				$import_res['skipped_duplicate'],
				$import_res['skipped_invalid']
			);

			if ( (int) $import_res['skipped_duplicate'] > 0 ) {
				$msg .= ' Duplicate rows were not inserted again; their imported tags were synchronized onto the existing questions.';
			}

			$messages = is_array( $import_res['messages'] ) ? $import_res['messages'] : array();
			if ( ! empty( $messages ) ) {
				$shown = array_slice( $messages, 0, 5 );
				$msg  .= ' Details: ' . implode( ' | ', $shown );
				if ( count( $messages ) > count( $shown ) ) {
					$msg .= ' | ...';
				}
			}

			$created = is_array( $import_res['created_tags'] ) ? $import_res['created_tags'] : array();
			if ( ! empty( $created ) ) {
				$msg .= ' New tags created for this skill: ' . implode( ', ', array_slice( $created, 0, 10 ) ) . ( count( $created ) > 10 ? ', ...' : '' ) . '.';
			}

			$rejected = is_array( $import_res['rejected_tags'] ) ? $import_res['rejected_tags'] : array();
			if ( ! empty( $rejected ) ) {
				$msg .= ' Rejected tag slugs (inactive, owned by another skill, or could not be created): ' . implode( ', ', array_slice( $rejected, 0, 10 ) ) . '.';
			}

			$notice_type = ( 0 === (int) $import_res['inserted'] ) ? 'warning' : 'success';
			set_transient(
				'psm_admin_notice_' . $user_id,
				array(
					'type' => $notice_type,
					'text' => $msg,
				),
				60
			);
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'psm-ai-importer',
					'tab'  => 'generate',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
