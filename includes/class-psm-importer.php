<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_Importer {

	public static function normalize_question_type_slug( $raw_type ) {
		$slug = sanitize_key( $raw_type );
		$aliases = array(
			'single'          => 'single_choice',
			'four_option'     => 'single_choice',
			'single_choice'   => 'single_choice',
			'multiple'        => 'multiple_choice',
			'multiple_select' => 'multiple_choice',
			'multiple_choice' => 'multiple_choice',
			'two_option'      => 'two_option',
			'true_false'      => 'true_false',
		);
		return $aliases[ $slug ] ?? ( $slug ?: 'single_choice' );
	}

	public static function normalize_difficulty_id( $raw_diff ) {
		if ( is_numeric( $raw_diff ) ) {
			return max( 1, absint( $raw_diff ) );
		}
		$map = array(
			'beginner'     => 1,
			'intermediate' => 2,
			'advanced'     => 3,
		);
		$lower = strtolower( trim( (string) $raw_diff ) );
		return $map[ $lower ] ?? 1;
	}

	public static function import_questions( $questions, $skill_id, $forced_tag_ids = array(), $default_status = 'draft', $batch_meta = array() ) {
		global $wpdb;

		$skills_table          = $wpdb->prefix . 'psm_skills';
		$tags_table            = $wpdb->prefix . 'psm_tags';
		$questions_table       = $wpdb->prefix . 'psm_questions';
		$question_tags_table   = $wpdb->prefix . 'psm_question_tags';
		$batches_table         = $wpdb->prefix . 'psm_batches';
		$batch_questions_table = $wpdb->prefix . 'psm_batch_questions';

		$skill_id = absint( $skill_id );
		$skill    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $skills_table WHERE id = %d", $skill_id ) );

		if ( ! $skill ) {
			return new WP_Error( 'invalid_skill', 'Target Skill ID does not exist.' );
		}

		// Load ONLY tags belonging to this exact Skill ID (Backend Ownership Enforcement)
		$valid_skill_tags = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, slug FROM $tags_table WHERE skill_id = %d AND status = 'active'", $skill_id ),
			OBJECT_K
		);

		$slug_to_tag_id = array();
		$valid_tag_ids  = array();
		foreach ( $valid_skill_tags as $tid => $trow ) {
			$slug_to_tag_id[ $trow->slug ] = (int) $tid;
			$valid_tag_ids[]               = (int) $tid;
		}

		$sanitized_forced_tags = array();
		foreach ( (array) $forced_tag_ids as $ftid ) {
			$ftid = absint( $ftid );
			if ( in_array( $ftid, $valid_tag_ids, true ) ) {
				$sanitized_forced_tags[] = $ftid;
			}
		}

		$now     = current_time( 'mysql' );
		$user_id = get_current_user_id();

		// Create Batch record
		$batch_code = ! empty( $batch_meta['batch_code'] )
			? sanitize_text_field( $batch_meta['batch_code'] )
			: 'BATCH-' . strtoupper( wp_generate_password( 8, false, false ) );
		$label_name = ! empty( $batch_meta['label_name'] )
			? sanitize_text_field( $batch_meta['label_name'] )
			: $skill->name . ' - ' . wp_date( 'Y-m-d H:i' );

		$wpdb->insert(
			$batches_table,
			array(
				'batch_code'      => $batch_code,
				'label_name'      => $label_name,
				'skill_id'        => $skill_id,
				'provider'        => sanitize_text_field( $batch_meta['provider'] ?? 'manual' ),
				'model'           => sanitize_text_field( $batch_meta['model'] ?? '' ),
				'prompt_text'     => sanitize_textarea_field( $batch_meta['prompt_text'] ?? '' ),
				'rules_text'      => sanitize_textarea_field( $batch_meta['rules_text'] ?? '' ),
				'total_generated' => count( (array) $questions ),
				'total_imported'  => 0,
				'total_skipped'   => 0,
				'status'          => 'completed',
				'created_by'      => $user_id,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);
		$batch_id = (int) $wpdb->insert_id;

		$result = array(
			'batch_id'          => $batch_id,
			'batch_code'        => $batch_code,
			'inserted'          => 0,
			'skipped_duplicate' => 0,
			'skipped_invalid'   => 0,
			'rejected_tags'     => array(),
			'messages'          => array(),
		);

		$allowed_langs = array_keys( PSM_Languages::languages() );

		$order_num = 1;
		foreach ( (array) $questions as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				$result['skipped_invalid']++;
				$result['messages'][] = "Row #" . ( $idx + 1 ) . ": Invalid question item format.";
				continue;
			}

			$raw_q_text = $item['question_text'] ?? ( $item['question'] ?? '' );
			$raw_opts   = $item['options'] ?? array();
			$raw_corr   = $item['correct_keys'] ?? ( $item['correct_answers'] ?? array() );

			if ( empty( $raw_q_text ) || empty( $raw_opts ) || empty( $raw_corr ) ) {
				$result['skipped_invalid']++;
				$result['messages'][] = "Row #" . ( $idx + 1 ) . ": Missing required question/question_text, options, or correct_answers/correct_keys.";
				continue;
			}

			$question_text = wp_kses_post( trim( (string) $raw_q_text ) );
			$code_snippet  = isset( $item['code_snippet'] ) && null !== $item['code_snippet'] ? (string) $item['code_snippet'] : '';
			$code_language = isset( $item['code_language'] ) ? sanitize_key( (string) $item['code_language'] ) : '';
			if ( ! in_array( $code_language, $allowed_langs, true ) ) {
				$code_language = '';
			}

			$difficulty    = self::normalize_difficulty_id( $item['difficulty'] ?? ( $batch_meta['default_difficulty'] ?? 1 ) );
			$question_type = self::normalize_question_type_slug( $item['question_type'] ?? 'single_choice' );
			$explanation   = isset( $item['explanation'] ) ? wp_kses_post( trim( (string) $item['explanation'] ) ) : '';
			$external_id   = isset( $item['external_id'] ) ? sanitize_text_field( substr( (string) $item['external_id'], 0, 100 ) ) : '';

			if ( ! is_array( $raw_opts ) || count( $raw_opts ) < 2 ) {
				$result['skipped_invalid']++;
				$result['messages'][] = "Row #" . ( $idx + 1 ) . ": Must provide at least 2 options.";
				continue;
			}

			$clean_options = array();
			foreach ( $raw_opts as $opt_key => $opt_val ) {
				if ( is_array( $opt_val ) && isset( $opt_val['key'] ) ) {
					$clean_key                   = strtoupper( sanitize_key( (string) $opt_val['key'] ) );
					$clean_options[ $clean_key ] = wp_kses_post( trim( (string) ( $opt_val['text'] ?? '' ) ) );
				} else {
					$clean_key                   = strtoupper( sanitize_key( (string) $opt_key ) );
					$clean_options[ $clean_key ] = wp_kses_post( trim( (string) $opt_val ) );
				}
			}

			$clean_correct = array();
			foreach ( (array) $raw_corr as $ck ) {
				$ck = strtoupper( sanitize_key( (string) $ck ) );
				if ( isset( $clean_options[ $ck ] ) ) {
					$clean_correct[] = $ck;
				}
			}

			if ( empty( $clean_correct ) ) {
				$result['skipped_invalid']++;
				$result['messages'][] = "Row #" . ( $idx + 1 ) . ": Correct key does not match any provided option.";
				continue;
			}

			$is_multiple = ( $question_type === 'multiple_choice' || $question_type === 'multiple_select' || count( $clean_correct ) > 1 )
				? 1
				: ( ! empty( $item['is_multiple'] ) ? 1 : 0 );

			$normalized_stem = preg_replace( '/\s+/', ' ', mb_strtolower( wp_strip_all_tags( $question_text ) ) );
			$sorted_opts     = $clean_options;
			ksort( $sorted_opts );
			$content_hash = sha1( $skill_id . '|' . $normalized_stem . '|' . wp_json_encode( $sorted_opts ) );

			$dup_by_hash = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $questions_table WHERE skill_id = %d AND content_hash = %s LIMIT 1",
					$skill_id,
					$content_hash
				)
			);

			if ( $dup_by_hash ) {
				$result['skipped_duplicate']++;
				continue;
			}

			if ( ! empty( $external_id ) ) {
				$dup_by_ext = $wpdb->get_var(
					$wpdb->prepare(
						"SELECT id FROM $questions_table WHERE skill_id = %d AND external_id = %s LIMIT 1",
						$skill_id,
						$external_id
					)
				);
				if ( $dup_by_ext ) {
					$result['skipped_duplicate']++;
					continue;
				}
			}

			$resolved_tag_ids = $sanitized_forced_tags;
			if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
				foreach ( $item['tags'] as $raw_tag ) {
					$tag_slug = sanitize_title( (string) $raw_tag );
					if ( $tag_slug === 'c-sharp-oop' ) {
						$tag_slug = 'csharp-oop';
					}
					if ( isset( $slug_to_tag_id[ $tag_slug ] ) ) {
						$resolved_tag_ids[] = $slug_to_tag_id[ $tag_slug ];
					} else {
						$result['rejected_tags'][] = $tag_slug;
					}
				}
			}
			$resolved_tag_ids = array_values( array_unique( $resolved_tag_ids ) );

			$inserted = $wpdb->insert(
				$questions_table,
				array(
					'skill_id'      => $skill_id,
					'difficulty'    => $difficulty,
					'question_type' => $question_type,
					'question_text' => $question_text,
					'code_snippet'  => $code_snippet,
					'code_language' => $code_language,
					'image_id'      => 0,
					'options'       => wp_json_encode( $clean_options ),
					'correct_keys'  => wp_json_encode( $clean_correct ),
					'is_multiple'   => $is_multiple,
					'explanation'   => $explanation,
					'status'        => in_array( $default_status, array( 'draft', 'published' ), true ) ? $default_status : 'draft',
					'created_by'    => $user_id,
					'created_at'    => $now,
					'updated_at'    => $now,
					'external_id'   => $external_id,
					'content_hash'  => $content_hash,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
			);

			if ( $inserted ) {
				$question_id = (int) $wpdb->insert_id;

				foreach ( $resolved_tag_ids as $tid ) {
					$wpdb->replace(
						$question_tags_table,
						array(
							'question_id' => $question_id,
							'tag_id'      => $tid,
						),
						array( '%d', '%d' )
					);
				}

				if ( $batch_id > 0 ) {
					$wpdb->replace(
						$batch_questions_table,
						array(
							'batch_id'      => $batch_id,
							'question_id'   => $question_id,
							'import_order'  => $order_num++,
							'import_status' => 'imported',
							'created_at'    => $now,
						),
						array( '%d', '%d', '%d', '%s', '%s' )
					);
				}

				$result['inserted']++;
			} else {
				$result['skipped_invalid']++;
				$result['messages'][] = "Database insert error on row #" . ( $idx + 1 ) . ": " . $wpdb->last_error;
			}
		}

		if ( $batch_id > 0 ) {
			$total_skipped = $result['skipped_duplicate'] + $result['skipped_invalid'];
			$wpdb->update(
				$batches_table,
				array(
					'total_imported' => $result['inserted'],
					'total_skipped'  => $total_skipped,
					'updated_at'     => $now,
				),
				array( 'id' => $batch_id ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);
		}

		$result['rejected_tags'] = array_values( array_unique( $result['rejected_tags'] ) );
		return $result;
	}
}
