<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request data is explicitly parsed or sanitized by the workflow that consumes it.

trait PSM_Admin_Generation_Trait {

	/**
	 * Shared preview builder used by both the POST and AJAX entry points.
	 *
	 * Returns the preview payload or a WP_Error that the caller can surface.
	 *
	 * @param array $source Usually $_POST.
	 * @return array|WP_Error
	 */
	private function collect_preview( $source, $input_is_unslashed = false ) {
		global $wpdb;

		$skill_id       = absint( $source['skill_id'] ?? 0 );
		$domain_id      = absint( $source['domain_id'] ?? 0 );
		$tag_ids        = array_map( 'absint', (array) ( $source['tag_ids'] ?? array() ) );
		$difficulty_raw = sanitize_text_field( wp_unslash( $source['difficulty'] ?? 'all' ) );
		$difficulty_id  = is_numeric( $difficulty_raw ) ? min( 3, max( 1, absint( $difficulty_raw ) ) ) : 1;
		if ( is_numeric( $difficulty_raw ) ) {
			$difficulty_raw = (string) $difficulty_id;
		}

		$supported_types = array( 'single_choice', 'multiple_choice', 'two_option', 'true_false' );
		$q_types_sel     = array_map( 'sanitize_key', (array) ( $source['question_types'] ?? array() ) );
		if ( empty( $q_types_sel ) && ! empty( $source['question_type'] ) ) {
			$q_types_sel = array( sanitize_key( $source['question_type'] ) );
		}
		$q_types_sel = array_values( array_intersect( $q_types_sel, $supported_types ) );
		if ( empty( $q_types_sel ) ) {
			$q_types_sel = array( 'single_choice' );
		}
		$question_type = $q_types_sel[0];

		$count          = max( 1, min( 100, absint( $source['question_count'] ?? 50 ) ) );
		$default_status = sanitize_key( $source['default_status'] ?? 'draft' );
		$default_status = in_array( $default_status, array( 'draft', 'published' ), true ) ? $default_status : 'draft';
		$mode           = sanitize_key( $source['import_mode'] ?? 'api' );
		$ai_provider    = sanitize_key( $source['ai_provider_select'] ?? '' );
		$label_name     = sanitize_text_field( wp_unslash( $source['label_name'] ?? '' ) );
		$user_prompt    = sanitize_textarea_field( wp_unslash( $source['user_prompt'] ?? '' ) );
		$rules          = sanitize_textarea_field( wp_unslash( $source['generation_rules'] ?? '' ) );
		$code_language  = sanitize_key( $source['code_language_master'] ?? '' );
		if ( ! array_key_exists( $code_language, PSM_Languages::languages() ) ) {
			$code_language = '';
		}
		$focus_tag_slug = sanitize_title( (string) ( $source['focus_tag_slug'] ?? '' ) );

		$skill = PSM_Cache::get_or_create(
			'generation_skill',
			array( 'skill_id' => $skill_id ),
			static function () use ( $wpdb, $skill_id ) {
				return PSM_Cache::get_row( $wpdb->prepare( 'SELECT * FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE id = %d', $skill_id ), array(), false );
			}
		);
		if ( ! $skill ) {
			return new WP_Error( 'invalid_skill', 'Please select a valid Skill.' );
		}
		if ( ! $domain_id || (int) $skill->domain_id !== $domain_id ) {
			return new WP_Error( 'invalid_domain', 'The selected Skill does not belong to the selected Domain.' );
		}

		if ( 'all' === $difficulty_raw || 'mixed' === $difficulty_raw ) {
			$diff_label = 'All Levels (beginner, intermediate, advanced)';
		} else {
			$diff_row = PSM_Cache::get_or_create(
				'generation_difficulty',
				array(
					'value' => $difficulty_raw,
					'id'    => $difficulty_id,
				),
				static function () use ( $wpdb, $difficulty_raw, $difficulty_id ) {
					return is_numeric( $difficulty_raw )
						? PSM_Cache::get_row( $wpdb->prepare( 'SELECT * FROM ' . PSM_AI_TABLE_PREFIX . 'difficulties WHERE id = %d', $difficulty_id ), array(), false )
						: PSM_Cache::get_row( $wpdb->prepare( 'SELECT * FROM ' . PSM_AI_TABLE_PREFIX . 'difficulties WHERE slug = %s', $difficulty_raw ), array(), false );
				}
			);
			if ( $diff_row && ! is_numeric( $difficulty_raw ) ) {
				$difficulty_id = (int) $diff_row->id;
			}
			$diff_label = $diff_row ? "{$diff_row->id} ({$diff_row->name} / {$diff_row->slug})" : (string) $difficulty_raw;
		}

		$skill_tags = PSM_Cache::get_or_create(
			'generation_active_tags',
			array( 'skill_id' => $skill_id ),
			static function () use ( $wpdb, $skill_id ) {
				return PSM_Cache::get_results(
					$wpdb->prepare( 'SELECT id, name, slug FROM ' . PSM_AI_TABLE_PREFIX . "tags WHERE skill_id = %d AND status = 'active'", $skill_id ),
					array(),
					false
				);
			}
		);

		$allowed_tag_slugs   = array();
		$selected_tag_slugs  = array();
		$selected_tag_labels = array();
		foreach ( $skill_tags as $st ) {
			$allowed_tag_slugs[] = $st->slug;
			if ( in_array( (int) $st->id, $tag_ids, true ) ) {
				$selected_tag_slugs[]  = $st->slug;
				$selected_tag_labels[] = '' !== trim( (string) $st->name ) ? $st->name : $st->slug;
			}
		}

		$used_provider = $ai_provider;
		$used_model    = '';

		if ( 'manual_json' === $mode ) {
			$raw_json = $input_is_unslashed ? (string) ( $source['manual_json_payload'] ?? '' ) : wp_unslash( $source['manual_json_payload'] ?? '' );
			$decoded  = json_decode( $raw_json, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				return new WP_Error( 'invalid_json', 'Invalid JSON pasted: ' . json_last_error_msg() );
			}

			$questions = ( is_array( $decoded ) && isset( $decoded['questions'] ) ) ? $decoded['questions'] : $decoded;
			if ( ! is_array( $questions ) || empty( $questions ) ) {
				return new WP_Error( 'invalid_json', 'JSON payload must contain a non-empty "questions" array.' );
			}

			$used_provider = 'manual_json';
		} else {
			$api_res = PSM_API_Client::generate_questions(
				array(
					'skill_id'          => $skill_id,
					'skill_name'        => $skill->name,
					'skill_slug'        => $skill->slug,
					'tag_slugs'         => $selected_tag_slugs,
					'tag_labels'        => $selected_tag_labels,
					'allowed_tag_slugs' => $allowed_tag_slugs,
					'difficulty'        => $difficulty_id,
					'difficulty_slug'   => $difficulty_raw,
					'difficulty_label'  => $diff_label,
					'question_type'     => $question_type,
					'question_types'    => $q_types_sel,
					'count'             => $count,
					'user_prompt'       => $user_prompt,
					'rules'             => $rules,
					'code_language'     => $code_language,
				),
				$ai_provider
			);

			if ( is_wp_error( $api_res ) ) {
				return $api_res;
			}

			$questions     = $api_res['questions'];
			$used_provider = $api_res['provider'];
			$used_model    = $api_res['model'];
		}

		$questions = PSM_Importer::normalize_question_tags( $questions, $skill->slug, $skill->name );

		if ( ! empty( $selected_tag_slugs ) ) {
			$unfocused = array();
			foreach ( $questions as $idx => $q ) {
				$q_tags = array_map( 'strval', (array) ( $q['tags'] ?? array() ) );
				if ( empty( array_intersect( $selected_tag_slugs, $q_tags ) ) ) {
					$unfocused[] = $idx + 1;
				}
			}
			if ( ! empty( $unfocused ) ) {
				return new WP_Error(
					'focus_tags_missing',
					sprintf(
						'%d question(s) do not carry any selected focus tag (%s). Regenerate the output or adjust the tag selection.',
						count( $unfocused ),
						implode( ', ', $selected_tag_slugs )
					)
				);
			}
		}

		return array(
			'domain_id'          => $domain_id,
			'skill_id'           => $skill_id,
			'skill_name'         => $skill->name,
			'tag_ids'            => $tag_ids,
			'default_status'     => $default_status,
			'default_difficulty' => $difficulty_id,
			'label_name'         => $label_name ? $label_name : ( $skill->name . ' - ' . wp_date( 'Y-m-d H:i' ) ),
			'provider'           => $used_provider,
			'model'              => $used_model,
			'prompt_text'        => $user_prompt,
			'rules_text'         => $rules,
			'code_language'      => $code_language,
			'focus_tag_slug'     => $focus_tag_slug,
			'questions'          => $questions,
		);
	}

	public function handle_fetch_preview() {
		check_admin_referer( 'psm_generate_import_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$preview = $this->collect_preview( $_POST );
		if ( is_wp_error( $preview ) ) {
			set_transient(
				'psm_admin_notice_' . get_current_user_id(),
				array(
					'type' => 'error',
					'text' => $preview->get_error_message(),
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

	public function ajax_fetch_preview() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		try {
			$preview = $this->collect_preview( $_POST );
			if ( is_wp_error( $preview ) ) {
				wp_send_json_error( array( 'message' => $preview->get_error_message() ) );
			}

			set_transient( 'psm_preview_data_' . get_current_user_id(), $preview, 1800 );

			wp_send_json_success(
				array(
					'redirect_url' => add_query_arg(
						array(
							'page'    => 'psm-ai-importer',
							'tab'     => 'generate',
							'preview' => '1',
						),
						admin_url( 'admin.php' )
					),
				)
			);
		} catch ( \Throwable $e ) {
			wp_send_json_error( array( 'message' => 'The preview could not be generated. Please try again.' ) );
		}
	}

	/**
	 * AJAX: Build the copy/download-ready AI prompt for the current form values.
	 */
	public function ajax_build_prompt() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;
		$skill_id  = absint( $_POST['skill_id'] ?? 0 );
		$domain_id = absint( $_POST['domain_id'] ?? 0 );
		$skill = PSM_Cache::get_or_create(
			'generation_skill_summary',
			array( 'skill_id' => $skill_id ),
			static function () use ( $wpdb, $skill_id ) {
				return PSM_Cache::get_row( $wpdb->prepare( 'SELECT id, name, slug, domain_id FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE id = %d', $skill_id ), array(), false );
			}
		);
		if ( ! $skill ) {
			wp_send_json_error( array( 'message' => 'Please select a valid Skill first.' ) );
		}
		if ( ! $domain_id || (int) $skill->domain_id !== $domain_id ) {
			wp_send_json_error( array( 'message' => 'The selected Skill does not belong to the selected Domain.' ) );
		}

		$tag_rows = PSM_Cache::get_or_create(
			'generation_active_tags',
			array( 'skill_id' => $skill_id ),
			static function () use ( $wpdb, $skill_id ) {
				return PSM_Cache::get_results( $wpdb->prepare( 'SELECT id, name, slug FROM ' . PSM_AI_TABLE_PREFIX . "tags WHERE skill_id = %d AND status = 'active'", $skill_id ), array(), false );
			}
		);
		$tags_all    = array();
		$slug_by_id  = array();
		$label_by_id = array();
		foreach ( $tag_rows as $row ) {
			$tags_all[]                    = $row->slug;
			$slug_by_id[ (int) $row->id ]  = $row->slug;
			$label_by_id[ (int) $row->id ] = '' !== trim( (string) $row->name ) ? $row->name : $row->slug;
		}

		$tags_selected = array();
		$tag_labels    = array();
		foreach ( array_map( 'absint', (array) ( $_POST['tag_ids'] ?? array() ) ) as $tid ) {
			if ( isset( $slug_by_id[ $tid ] ) ) {
				$tags_selected[]                   = $slug_by_id[ $tid ];
				$tag_labels[ $slug_by_id[ $tid ] ] = $label_by_id[ $tid ];
			}
		}

		$difficulty_raw = strtolower( sanitize_text_field( wp_unslash( $_POST['difficulty'] ?? 'all' ) ) );
		if ( '' === $difficulty_raw || 'all' === $difficulty_raw || 'mixed' === $difficulty_raw ) {
			$difficulty = 'all';
		} else {
			$diff_id    = PSM_Importer::normalize_difficulty_id( $difficulty_raw );
			$difficulty = array(
				1 => 'beginner',
				2 => 'intermediate',
				3 => 'advanced',
			)[ $diff_id ];
		}

		$types = array_map( 'sanitize_key', (array) ( $_POST['question_types'] ?? array() ) );
		$types = array_values( array_intersect( $types, array( 'single_choice', 'multiple_choice', 'two_option', 'true_false' ) ) );

		$label = sanitize_text_field( wp_unslash( $_POST['label_name'] ?? '' ) );
		$base  = '' !== $label ? sanitize_title( $label ) : $skill->slug;
		if ( '' === $base ) {
			$base = $skill->slug;
		}
		$prefix = strtoupper( $base ) . '-' . wp_date( 'YmdHis' );

		$prompt = PSM_API_Client::build_export_prompt(
			array(
				'skill_name'     => $skill->name,
				'skill_slug'     => $skill->slug,
				'count'          => absint( $_POST['question_count'] ?? 50 ),
				'difficulty'     => $difficulty,
				'question_types' => $types,
				'status'         => sanitize_key( $_POST['default_status'] ?? 'draft' ),
				'tags_all'       => $tags_all,
				'tags_selected'  => $tags_selected,
				'tag_labels'     => $tag_labels,
				'user_prompt'    => sanitize_textarea_field( wp_unslash( $_POST['user_prompt'] ?? '' ) ),
				'rules'          => sanitize_textarea_field( wp_unslash( $_POST['generation_rules'] ?? '' ) ),
				'code_language'  => sanitize_key( $_POST['code_language_master'] ?? '' ),
				'prefix'         => $prefix,
			)
		);

		wp_send_json_success(
			array(
				'prompt'     => $prompt,
				'filename'   => 'prompt-' . sanitize_title( $skill->slug ) . '-' . wp_date( 'Ymd' ) . '.txt',
				'skill_name' => $skill->name,
			)
		);
	}
}
