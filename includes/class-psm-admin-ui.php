<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_Admin_UI {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_post_psm_fetch_preview', array( $this, 'handle_fetch_preview' ) );
		add_action( 'admin_post_psm_confirm_import', array( $this, 'handle_confirm_import' ) );
		add_action( 'admin_post_psm_run_seeder', array( $this, 'handle_run_seeder' ) );
		add_action( 'admin_post_psm_download_labels', array( $this, 'handle_download_labels' ) );
		add_action( 'admin_post_psm_import_json_file', array( $this, 'handle_import_json_file' ) );

		// AJAX endpoints for Master CRUD (Domains, Skill Types, Question Types, Difficulties, Skills, Tags, Questions, Batches)
		add_action( 'wp_ajax_psm_ajax_save_master', array( $this, 'ajax_save_master' ) );
		add_action( 'wp_ajax_psm_ajax_delete_master', array( $this, 'ajax_delete_master' ) );
		add_action( 'wp_ajax_psm_ajax_get_question_master', array( $this, 'ajax_get_question_master' ) );
		add_action( 'wp_ajax_psm_ajax_get_master_list', array( $this, 'ajax_get_master_list' ) );

		// AJAX endpoint for saving individual AI provider forms (Gemini / OpenAI / Custom)
		add_action( 'wp_ajax_psm_ajax_save_ai_provider', array( $this, 'ajax_save_ai_provider' ) );
		add_action( 'wp_ajax_psm_ajax_fetch_preview', array( $this, 'ajax_fetch_preview' ) );
		add_action( 'wp_ajax_psm_ajax_build_prompt', array( $this, 'ajax_build_prompt' ) );
	}

	public function register_menu() {
		add_menu_page(
			'PSM AI Importer',
			'PSM AI Importer',
			'manage_options',
			'psm-ai-importer',
			array( $this, 'render_page' ),
			'dashicons-database-import',
			26
		);
	}

	/**
	 * AJAX: Save individual AI provider settings card (Gemini, OpenAI, or Custom)
	 */
	public function ajax_save_ai_provider() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$provider     = sanitize_key( $_POST['provider'] ?? '' );
		$valid_provs  = PSM_API_Client::get_providers();
		if ( ! isset( $valid_provs[ $provider ] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid AI provider.' ) );
		}

		$model        = sanitize_text_field( $_POST['model'] ?? '' );
		$endpoint     = esc_url_raw( $_POST['endpoint_url'] ?? '' );
		$daily_limit  = max( 1, absint( $_POST['daily_limit'] ?? 200 ) );
		$weekly_limit = max( 1, absint( $_POST['weekly_limit'] ?? 1000 ) );
		$set_default  = ! empty( $_POST['set_as_default'] );

		$settings     = get_option( 'psm_ai_api_settings', array() );
		$existing_key = (string) ( $settings['providers'][ $provider ]['api_key'] ?? '' );
		$posted_key   = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( ! empty( $_POST['clear_api_key'] ) ) {
			$api_key = '';
		} elseif ( '' !== $posted_key ) {
			$api_key = $posted_key;
		} else {
			// A blank field means "keep the stored key"; it is never echoed back.
			$api_key = $existing_key;
		}

		$settings['providers'][ $provider ] = array(
			'api_key'      => $api_key,
			'model'        => $model ?: ( PSM_API_Client::get_default_models()[ $provider ] ?? '' ),
			'endpoint_url' => $endpoint,
		);
		$settings['limits'][ $provider ] = array(
			'daily_limit'  => $daily_limit,
			'weekly_limit' => $weekly_limit,
		);

		if ( $set_default || empty( $settings['provider'] ) ) {
			$settings['provider'] = $provider;
		}

		// The legacy single-key format is no longer read; drop it when present.
		unset( $settings['api_key'] );

		update_option( 'psm_ai_api_settings', $settings, false );
		$usage = PSM_API_Client::get_usage_status( $provider );

		wp_send_json_success( array(
			'message' => $valid_provs[ $provider ] . ' settings saved successfully.',
			'usage'   => $usage,
			'default' => $settings['provider'],
			'has_key' => '' !== $api_key,
		) );
	}

	/**
	 * AJAX: Add or Edit any Master entity from Modal Popup
	 */
	public function ajax_save_master() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;
		$entity    = sanitize_key( $_POST['entity'] ?? '' );
		$record_id = absint( $_POST['record_id'] ?? 0 );
		$now       = current_time( 'mysql', true );

		if ( 'question' === $entity ) {
			$this->save_question_master( $record_id, $now );
		}

		$name        = sanitize_text_field( $_POST['name'] ?? '' );
		$raw_slug    = sanitize_text_field( $_POST['slug'] ?? '' );
		$description = sanitize_textarea_field( $_POST['description'] ?? '' );
		$sort_order  = absint( $_POST['sort_order'] ?? 10 );
		$status      = in_array( $_POST['status'] ?? 'active', array( 'active', 'inactive' ), true ) ? $_POST['status'] : 'active';

		if ( $entity === 'batch' ) {
			$label_name = sanitize_text_field( $_POST['label_name'] ?? '' );
			$b_status   = sanitize_key( $_POST['status'] ?? 'completed' );
			$b_status   = in_array( $b_status, array( 'completed', 'processing', 'failed' ), true ) ? $b_status : 'completed';
			if ( ! $record_id || empty( $label_name ) ) {
				wp_send_json_error( array( 'message' => 'Batch ID and Label Name are required.' ) );
			}
			$wpdb->update(
				$wpdb->prefix . 'psm_batches',
				array( 'label_name' => $label_name, 'status' => $b_status, 'updated_at' => $now ),
				array( 'id' => $record_id )
			);
			wp_send_json_success( array( 'message' => 'Batch updated.' ) );
		}

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Name is required.' ) );
		}

		$slug = ( $entity === 'question_type' )
			? sanitize_key( $raw_slug ?: $name )
			: PSM_Seeder::make_slug( $raw_slug, $name );

		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => 'Valid slug is required.' ) );
		}

		$table_map = array(
			'domain'        => $wpdb->prefix . 'psm_domains',
			'skill_type'    => $wpdb->prefix . 'psm_skill_types',
			'question_type' => $wpdb->prefix . 'psm_question_types',
			'difficulty'    => $wpdb->prefix . 'psm_difficulties',
			'skill'         => $wpdb->prefix . 'psm_skills',
			'tag'           => $wpdb->prefix . 'psm_tags',
		);

		if ( ! isset( $table_map[ $entity ] ) ) {
			wp_send_json_error( array( 'message' => 'Unknown master entity.' ) );
		}
		$table = $table_map[ $entity ];

		// Check duplicate slug
		$dup_id = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM $table WHERE slug = %s AND id != %d", $slug, $record_id )
		);
		if ( $dup_id ) {
			wp_send_json_error( array( 'message' => "Slug '{$slug}' already exists. Slugs must be unique." ) );
		}

		$data = array(
			'name'        => $name,
			'slug'        => $slug,
			'description' => $description,
			'sort_order'  => $sort_order,
			'status'      => $status,
			'updated_at'  => $now,
		);

		if ( $entity === 'domain' ) {
			$data['icon'] = sanitize_text_field( $_POST['icon'] ?? 'dashicons-category' );
		} elseif ( $entity === 'skill' ) {
			$domain_id = absint( $_POST['domain_id'] ?? 0 );
			$parent_id = absint( $_POST['parent_skill_id'] ?? 0 );
			$type_id   = absint( $_POST['skill_type_id'] ?? 0 );
			$type_name = $type_id ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM {$wpdb->prefix}psm_skill_types WHERE id = %d", $type_id ) ) : 'Subject';

			if ( $record_id && $parent_id === $record_id ) {
				wp_send_json_error( array( 'message' => 'A skill cannot be its own parent.' ) );
			}

			$data['domain_id']       = $domain_id;
			$data['parent_id']       = $parent_id;
			$data['parent_skill_id'] = $parent_id ?: null;
			$data['skill_type_id']   = $type_id;
			$data['skill_type']      = $type_name ?: 'Subject';
		} elseif ( $entity === 'tag' ) {
			$skill_id = absint( $_POST['skill_id'] ?? 0 );
			if ( ! $skill_id ) {
				wp_send_json_error( array( 'message' => 'Parent Skill is required for a Tag.' ) );
			}
			$data['skill_id'] = $skill_id;
		}

		if ( $record_id > 0 ) {
			$wpdb->update( $table, $data, array( 'id' => $record_id ) );
			if ( $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ) );
			}
			wp_send_json_success( array( 'message' => ucfirst( str_replace( '_', ' ', $entity ) ) . ' updated successfully.' ) );
		} else {
			$data['created_at'] = $now;
			$wpdb->insert( $table, $data );
			if ( $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ) );
			}
			wp_send_json_success( array( 'message' => ucfirst( str_replace( '_', ' ', $entity ) ) . ' added successfully.' ) );
		}
	}

	private function save_question_master( $record_id, $now ) {
		global $wpdb;

		$skill_id = absint( $_POST['skill_id'] ?? 0 );
		$skill    = $skill_id ? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psm_skills WHERE id = %d", $skill_id ) ) : null;
		if ( ! $skill ) {
			wp_send_json_error( array( 'message' => 'Select a valid Skill for the question.' ) );
		}

		$existing = null;
		if ( $record_id ) {
			$existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psm_questions WHERE id = %d", $record_id ) );
			if ( ! $existing ) {
				wp_send_json_error( array( 'message' => 'Question not found.' ) );
			}
		}

		$status      = sanitize_key( $_POST['question_status'] ?? 'draft' );
		$difficulty  = PSM_Importer::normalize_difficulty_id( $_POST['difficulty'] ?? 1 );
		$raw_correct = array_map( 'sanitize_key', (array) ( $_POST['correct_keys'] ?? array() ) );
		$item        = array(
			'question_text'  => wp_unslash( $_POST['question_text'] ?? '' ),
			'question_type'  => wp_unslash( $_POST['question_type'] ?? 'four_option' ),
			'difficulty'     => $difficulty,
			'code_snippet'   => wp_unslash( $_POST['code_snippet'] ?? '' ),
			'code_language'  => wp_unslash( $_POST['code_language'] ?? '' ),
			'options'        => array(
				'A' => wp_unslash( $_POST['option_a'] ?? '' ),
				'B' => wp_unslash( $_POST['option_b'] ?? '' ),
				'C' => wp_unslash( $_POST['option_c'] ?? '' ),
				'D' => wp_unslash( $_POST['option_d'] ?? '' ),
			),
			'correct_keys'   => $raw_correct,
			'explanation'    => wp_unslash( $_POST['explanation'] ?? '' ),
			'status'         => $status,
			'external_id'    => wp_unslash( $_POST['external_id'] ?? '' ),
		);
		$inspected = PSM_Importer::inspect_question( $item, $status, $difficulty );
		if ( empty( $inspected['data'] ) ) {
			wp_send_json_error( array(
				'message' => implode( ' ', $inspected['errors'] ),
				'errors'  => $inspected['errors'],
			) );
		}
		$data = $inspected['data'];

		$questions_table = $wpdb->prefix . 'psm_questions';
		$duplicate_id    = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM $questions_table WHERE skill_id = %d AND content_hash = %s AND id != %d LIMIT 1",
				$skill_id,
				$data['content_hash'],
				$record_id
			)
		);
		if ( ! $duplicate_id && '' !== $data['external_id'] ) {
			$duplicate_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM $questions_table WHERE skill_id = %d AND external_id = %s AND id != %d LIMIT 1",
					$skill_id,
					$data['external_id'],
					$record_id
				)
			);
		}
		if ( $duplicate_id ) {
			wp_send_json_error( array( 'message' => "A question with the same content or external ID already exists in this skill (#{$duplicate_id})." ) );
		}

		$tag_slugs = array();
		$raw_tags  = preg_split( '/[\r\n,]+/', (string) wp_unslash( $_POST['question_tags'] ?? '' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( (array) $raw_tags as $raw_tag ) {
			$tag_slug = PSM_Importer::normalize_tag_slug( $raw_tag, $skill->slug, $skill->name );
			if ( '' !== $tag_slug ) {
				$tag_slugs[] = $tag_slug;
			}
		}
		$tag_slugs = array_values( array_unique( $tag_slugs ) );

		$tags_table          = $wpdb->prefix . 'psm_tags';
		$question_tags_table = $wpdb->prefix . 'psm_question_tags';
		$created_tags        = array();
		$rejected_tags       = array();
		$tag_ids             = array();
		$stored              = array(
			'skill_id'      => $skill_id,
			'difficulty'    => $data['difficulty'],
			'question_type' => $data['question_type'],
			'question_text' => $data['question_text'],
			'code_snippet'  => $data['code_snippet'],
			'code_language' => $data['code_language'],
			'image_id'      => $existing ? (int) $existing->image_id : 0,
			'options'       => wp_json_encode( $data['options'] ),
			'correct_keys'  => wp_json_encode( $data['correct_keys'] ),
			'is_multiple'   => $data['is_multiple'],
			'explanation'   => $data['explanation'],
			'status'        => $data['status'],
			'external_id'   => $data['external_id'],
			'content_hash'  => $data['content_hash'],
			'updated_at'    => $now,
		);

		$wpdb->query( 'START TRANSACTION' );

		foreach ( $tag_slugs as $tag_slug ) {
			$tag_id = PSM_Importer::resolve_or_create_tag( $tag_slug, $skill, $tags_table, $now, $created_tags );
			if ( $tag_id ) {
				$tag_ids[] = (int) $tag_id;
			} else {
				$rejected_tags[] = $tag_slug;
			}
		}
		if ( ! empty( $rejected_tags ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => 'These tags are inactive or owned by another skill: ' . implode( ', ', $rejected_tags ) ) );
		}

		if ( $existing ) {
			$saved = $wpdb->update( $questions_table, $stored, array( 'id' => $record_id ) );
		} else {
			$stored['created_by'] = get_current_user_id();
			$stored['created_at'] = $now;
			$saved = $wpdb->insert( $questions_table, $stored );
		}
		if ( false === $saved ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => $wpdb->last_error ?: 'Could not save the question.' ) );
		}

		$question_id = $existing ? $record_id : (int) $wpdb->insert_id;
		if ( false === $wpdb->delete( $question_tags_table, array( 'question_id' => $question_id ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => $wpdb->last_error ?: 'Could not update question tags.' ) );
		}
		foreach ( array_values( array_unique( $tag_ids ) ) as $tag_id ) {
			if ( ! $wpdb->insert( $question_tags_table, array( 'question_id' => $question_id, 'tag_id' => $tag_id ), array( '%d', '%d' ) ) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_send_json_error( array( 'message' => $wpdb->last_error ?: 'Could not link question tags.' ) );
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => $wpdb->last_error ?: 'Could not commit the question.' ) );
		}

		$message = $existing ? 'Question updated successfully.' : 'Question added successfully.';
		if ( ! empty( $created_tags ) ) {
			$message .= ' Created tag(s): ' . implode( ', ', $created_tags ) . '.';
		}
		wp_send_json_success( array( 'message' => $message, 'question_id' => $question_id ) );
	}

	public function ajax_get_question_master() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;
		$question_id = absint( $_POST['question_id'] ?? 0 );
		$question    = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psm_questions WHERE id = %d", $question_id )
		);
		if ( ! $question ) {
			wp_send_json_error( array( 'message' => 'Question not found.' ) );
		}

		$filters  = $this->get_question_filters( $_POST );
		$neighbors = $this->get_question_neighbor_ids( $question, $filters );
		wp_send_json_success(
			array(
				'record'      => $this->get_question_master_record( $question_id ),
				'previous_id' => $neighbors['previous'],
				'next_id'     => $neighbors['next'],
			)
		);
	}

	private function get_question_filters( $source ) {
		$status = sanitize_key( $source['question_status'] ?? '' );
		if ( ! in_array( $status, array( 'draft', 'published' ), true ) ) {
			$status = '';
		}

		return array(
			'search'   => sanitize_text_field( wp_unslash( $source['question_search'] ?? '' ) ),
			'skill_id' => absint( $source['question_skill'] ?? 0 ),
			'tag_id'   => absint( $source['question_tag'] ?? 0 ),
			'status'   => $status,
		);
	}

	private function get_question_filter_sql( $filters ) {
		global $wpdb;
		$where = ' WHERE 1=1';
		$args  = array();

		if ( '' !== $filters['search'] ) {
			$where  .= ' AND q.question_text LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}
		if ( $filters['skill_id'] > 0 ) {
			$where  .= ' AND q.skill_id = %d';
			$args[]  = $filters['skill_id'];
		}
		if ( $filters['tag_id'] > 0 ) {
			$where  .= ' AND EXISTS (SELECT 1 FROM ' . $wpdb->prefix . 'psm_question_tags qtf WHERE qtf.question_id = q.id AND qtf.tag_id = %d)';
			$args[]  = $filters['tag_id'];
		}
		if ( '' !== $filters['status'] ) {
			$where  .= ' AND q.status = %s';
			$args[]  = $filters['status'];
		}

		return array( $where, $args );
	}

	private function get_question_neighbor_ids( $question, $filters ) {
		global $wpdb;
		list( $where, $args ) = $this->get_question_filter_sql( $filters );
		$questions_table      = $wpdb->prefix . 'psm_questions';

		$previous_args = array_merge( $args, array( $question->updated_at, $question->updated_at, $question->id ) );
		$previous      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT q.id FROM $questions_table q
				 $where
				 AND (q.updated_at > %s OR (q.updated_at = %s AND q.id > %d))
				 ORDER BY q.updated_at DESC, q.id DESC LIMIT 1",
				$previous_args
			)
		);

		$next_args = array_merge( $args, array( $question->updated_at, $question->updated_at, $question->id ) );
		$next      = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT q.id FROM $questions_table q
				 $where
				 AND (q.updated_at < %s OR (q.updated_at = %s AND q.id < %d))
				 ORDER BY q.updated_at ASC, q.id ASC LIMIT 1",
				$next_args
			)
		);

		return array( 'previous' => $previous, 'next' => $next );
	}

	private function get_question_master_record( $question_id ) {
		global $wpdb;
		$question = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT q.*, s.name AS skill_name, d.name AS difficulty_name
				 FROM {$wpdb->prefix}psm_questions q
				 LEFT JOIN {$wpdb->prefix}psm_skills s ON s.id = q.skill_id
				 LEFT JOIN {$wpdb->prefix}psm_difficulties d ON d.id = q.difficulty
				 WHERE q.id = %d",
				$question_id
			)
		);
		if ( ! $question ) {
			return null;
		}

		$options  = json_decode( $question->options, true );
		$correct  = json_decode( $question->correct_keys, true );
		$tag_rows = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT t.slug FROM {$wpdb->prefix}psm_question_tags qt
				 INNER JOIN {$wpdb->prefix}psm_tags t ON t.id = qt.tag_id
				 WHERE qt.question_id = %d ORDER BY t.sort_order ASC, t.name ASC",
				$question_id
			)
		);

		return array(
			'id'             => (int) $question->id,
			'skill_id'       => (int) $question->skill_id,
			'difficulty'     => (int) $question->difficulty,
			'question_type'  => $question->question_type,
			'question_text'  => $question->question_text,
			'code_snippet'   => $question->code_snippet,
			'code_language'  => $question->code_language,
			'options'        => is_array( $options ) ? $options : array(),
			'correct_keys'   => is_array( $correct ) ? array_values( $correct ) : array(),
			'is_multiple'    => (int) $question->is_multiple,
			'explanation'    => $question->explanation,
			'status'         => $question->status,
			'external_id'    => $question->external_id,
			'tag_slugs'      => array_map( 'strval', $tag_rows ),
		);
	}

	private function master_pagination_html( $page, $total_pages, $total_items, $per_page, $page_param, $item_label ) {
		$total_pages = max( 1, (int) $total_pages );
		$page        = max( 1, min( $total_pages, (int) $page ) );
		$start       = $total_items > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$end         = min( $page * $per_page, $total_items );

		$button = static function( $target, $label, $icon, $tooltip, $disabled = false, $current = false ) use ( $page_param, $page ) {
			$classes = 'psm-page-btn' . ( $disabled ? ' is-disabled' : '' ) . ( $current ? ' is-current' : '' );
			$content = '<span class="dashicons ' . esc_attr( $icon ) . '" aria-hidden="true"></span>';
			if ( '' !== $label ) {
				$content .= '<span>' . esc_html( $label ) . '</span>';
			}
			$attr = ' data-page="' . esc_attr( $target ) . '" data-page-param="' . esc_attr( $page_param ) . '"';
			if ( $disabled || $current ) {
				return '<span class="' . $classes . '" aria-disabled="true" data-tooltip="' . esc_attr( $tooltip ) . '"' . $attr . '>' . $content . '</span>';
			}
			return '<button type="button" class="' . $classes . '" aria-label="' . esc_attr( $tooltip ) . '" data-tooltip="' . esc_attr( $tooltip ) . '"' . $attr . '>' . $content . '</button>';
		};

		$html  = '<div class="psm-pagination"><div class="psm-pagination-info">Showing <strong>' . number_format_i18n( $start ) . '&ndash;' . number_format_i18n( $end ) . '</strong> of <strong>' . number_format_i18n( $total_items ) . '</strong> ' . esc_html( $item_label ) . '</div><nav class="psm-pagination-links" aria-label="' . esc_attr( ucfirst( $item_label ) . ' pagination' ) . '">';
		$html .= $button( 1, '', 'dashicons-controls-skipback', 'First page', 1 === $page );
		$html .= $button( $page - 1, '', 'dashicons-arrow-left-alt', 'Previous page', 1 === $page );

		$page_numbers = array( 1, $total_pages );
		for ( $number = max( 1, $page - 2 ); $number <= min( $total_pages, $page + 2 ); $number++ ) {
			$page_numbers[] = $number;
		}
		$page_numbers = array_values( array_unique( $page_numbers ) );
		sort( $page_numbers );
		$last_number = 0;
		foreach ( $page_numbers as $number ) {
			if ( $last_number && $number - $last_number > 1 ) {
				$html .= '<span class="psm-page-ellipsis">&hellip;</span>';
			}
			$html       .= $button( $number, (string) $number, '', 'Page ' . $number, false, $number === $page );
			$last_number = $number;
		}

		$html .= $button( $page + 1, '', 'dashicons-arrow-right-alt', 'Next page', $page === $total_pages );
		$html .= $button( $total_pages, '', 'dashicons-controls-skipforward', 'Last page', $page === $total_pages );
		$html .= '</nav></div>';

		return $html;
	}

	private function get_master_counts() {
		global $wpdb;
		$tables = array(
			'domain'        => 'psm_domains',
			'skill_type'    => 'psm_skill_types',
			'question_type' => 'psm_question_types',
			'difficulty'    => 'psm_difficulties',
			'skill'         => 'psm_skills',
			'tag'           => 'psm_tags',
			'question'      => 'psm_questions',
		);
		$counts = array();
		foreach ( $tables as $entity => $table ) {
			$counts[ $entity ] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$table}" );
		}
		return $counts;
	}

	private function get_master_filters( $entity, $source ) {
		$search_key = $entity . '_search';
		$filters    = array(
			'search' => sanitize_text_field( wp_unslash( $source[ $search_key ] ?? '' ) ),
		);

		if ( 'skill_type' === $entity ) {
			$filters['domain_id'] = absint( $source['skill_type_domain'] ?? 0 );
		} elseif ( 'question_type' === $entity || 'difficulty' === $entity || 'skill' === $entity ) {
			$status = sanitize_key( $source[ $entity . '_status' ] ?? '' );
			$filters['status'] = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : '';
			if ( 'skill' === $entity ) {
				$filters['domain_id']     = absint( $source['skill_domain'] ?? 0 );
				$filters['skill_type_id'] = absint( $source['skill_type'] ?? 0 );
			}
		} elseif ( 'tag' === $entity ) {
			$filters['skill_id'] = absint( $source['tag_skill'] ?? 0 );
		} elseif ( 'question' === $entity ) {
			$filters = $this->get_question_filters( $source );
		}

		return $filters;
	}

	private function get_master_list_data( $entity, $source ) {
		global $wpdb;
		$per_page = 25;
		$filters  = $this->get_master_filters( $entity, $source );
		$data     = array(
			'entity'        => $entity,
			'filters'       => $filters,
			'rows'          => array(),
			'total_count'   => 0,
			'filtered_count'=> 0,
			'page'          => 1,
			'total_pages'   => 1,
			'per_page'      => $per_page,
			'question_order'=> array(),
			'skills'        => $wpdb->get_results( "SELECT id, name, slug FROM {$wpdb->prefix}psm_skills ORDER BY name ASC" ),
			'domains'       => $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}psm_domains ORDER BY sort_order ASC, name ASC" ),
			'skill_types'   => $wpdb->get_results( "SELECT id, name FROM {$wpdb->prefix}psm_skill_types ORDER BY sort_order ASC, name ASC" ),
			'tags'          => $wpdb->get_results( "SELECT id, name, slug, skill_id, status FROM {$wpdb->prefix}psm_tags ORDER BY name ASC" ),
		);

		if ( 'question' === $entity ) {
			return $this->get_question_master_list_data( $data, $source );
		}
		if ( 'tag' === $entity ) {
			return $this->get_tag_master_list_data( $data, $source );
		}
		if ( 'skill' === $entity ) {
			return $this->get_skill_master_list_data( $data, $source );
		}

		$tables = array(
			'domain'        => 'psm_domains',
			'skill_type'    => 'psm_skill_types',
			'question_type' => 'psm_question_types',
			'difficulty'    => 'psm_difficulties',
		);
		if ( ! isset( $tables[ $entity ] ) ) {
			return $data;
		}

		$table  = $wpdb->prefix . $tables[ $entity ];
		$where  = ' WHERE 1=1';
		$args   = array();
		if ( '' !== $filters['search'] ) {
			$where  .= ' AND m.name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}
		if ( 'skill_type' === $entity && $filters['domain_id'] > 0 ) {
			$where  .= ' AND EXISTS (SELECT 1 FROM ' . $wpdb->prefix . 'psm_skills s WHERE s.skill_type_id = m.id AND s.domain_id = %d)';
			$args[]  = $filters['domain_id'];
		}
		if ( isset( $filters['status'] ) && '' !== $filters['status'] ) {
			$where .= ' AND m.status = %s';
			$args[] = $filters['status'];
		}

		$count_sql = "SELECT COUNT(*) FROM $table m $where";
		$data['total_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$data['filtered_count'] = (int) ( empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );
		$data['total_pages'] = max( 1, (int) ceil( $data['filtered_count'] / $per_page ) );
		$data['page'] = min( $data['total_pages'], max( 1, absint( $source[ $entity . '_page' ] ?? 1 ) ) );
		$offset = ( $data['page'] - 1 ) * $per_page;
		$data['rows'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.* FROM $table m $where ORDER BY m.sort_order ASC, m.name ASC LIMIT %d, %d",
				array_merge( $args, array( $offset, $per_page ) )
			)
		);

		return $data;
	}

	private function get_skill_master_list_data( $data, $source ) {
		global $wpdb;
		$filters = $data['filters'];
		$where   = ' WHERE 1=1';
		$args    = array();
		if ( '' !== $filters['search'] ) {
			$where  .= ' AND s.name LIKE %s';
			$args[]  = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}
		if ( $filters['domain_id'] > 0 ) {
			$where  .= ' AND s.domain_id = %d';
			$args[]  = $filters['domain_id'];
		}
		if ( $filters['skill_type_id'] > 0 ) {
			$where  .= ' AND s.skill_type_id = %d';
			$args[]  = $filters['skill_type_id'];
		}
		if ( '' !== $filters['status'] ) {
			$where .= ' AND s.status = %s';
			$args[] = $filters['status'];
		}

		$from = "FROM {$wpdb->prefix}psm_skills s
			LEFT JOIN {$wpdb->prefix}psm_domains d ON s.domain_id = d.id
			LEFT JOIN {$wpdb->prefix}psm_skill_types st ON s.skill_type_id = st.id
			LEFT JOIN {$wpdb->prefix}psm_skills p ON s.parent_id = p.id";
		$count_sql = "SELECT COUNT(*) $from $where";
		$data['total_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_skills" );
		$data['filtered_count'] = (int) ( empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );
		$data['total_pages'] = max( 1, (int) ceil( $data['filtered_count'] / $data['per_page'] ) );
		$data['page'] = min( $data['total_pages'], max( 1, absint( $source['skill_page'] ?? 1 ) ) );
		$offset = ( $data['page'] - 1 ) * $data['per_page'];
		$data['rows'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.*, d.name AS domain_name, st.name AS master_type_name, p.name AS parent_skill_name
				 $from $where ORDER BY d.sort_order ASC, s.sort_order ASC, s.name ASC LIMIT %d, %d",
				array_merge( $args, array( $offset, $data['per_page'] ) )
			)
		);
		return $data;
	}

	private function get_tag_master_list_data( $data, $source ) {
		global $wpdb;
		$filters = $data['filters'];
		$where   = ' WHERE 1=1';
		$args    = array();
		if ( '' !== $filters['search'] ) {
			$pattern = str_replace( array( '*', '?' ), array( '%', '_' ), $wpdb->esc_like( $filters['search'] ) );
			$where  .= ' AND (t.name LIKE %s OR t.slug LIKE %s)';
			$args[]  = '%' . $pattern . '%';
			$args[]  = '%' . $pattern . '%';
		}
		if ( $filters['skill_id'] > 0 ) {
			$where  .= ' AND t.skill_id = %d';
			$args[]  = $filters['skill_id'];
		}
		$from = "FROM {$wpdb->prefix}psm_tags t
			LEFT JOIN {$wpdb->prefix}psm_skills s ON s.id = t.skill_id
			LEFT JOIN {$wpdb->prefix}psm_question_tags qt ON qt.tag_id = t.id";
		$count_sql = "SELECT COUNT(DISTINCT t.id) $from $where";
		$data['total_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_tags" );
		$data['filtered_count'] = (int) ( empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );
		$data['total_pages'] = max( 1, (int) ceil( $data['filtered_count'] / $data['per_page'] ) );
		$data['page'] = min( $data['total_pages'], max( 1, absint( $source['tag_page'] ?? 1 ) ) );
		$offset = ( $data['page'] - 1 ) * $data['per_page'];
		$data['rows'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.*, s.name AS skill_name, COUNT(qt.question_id) AS question_count
				 $from $where GROUP BY t.id ORDER BY t.sort_order ASC, t.name ASC LIMIT %d, %d",
				array_merge( $args, array( $offset, $data['per_page'] ) )
			)
		);
		return $data;
	}

	private function get_question_master_list_data( $data, $source ) {
		global $wpdb;
		list( $where, $args ) = $this->get_question_filter_sql( $data['filters'] );
		$table = $wpdb->prefix . 'psm_questions';
		$count_sql = "SELECT COUNT(*) FROM $table q $where";
		$data['total_count'] = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$data['filtered_count'] = (int) ( empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );
		$data['total_pages'] = max( 1, (int) ceil( $data['filtered_count'] / $data['per_page'] ) );
		$data['page'] = min( $data['total_pages'], max( 1, absint( $source['question_page'] ?? 1 ) ) );
		$offset = ( $data['page'] - 1 ) * $data['per_page'];
		$ordered_sql = "SELECT q.id FROM $table q $where ORDER BY q.updated_at DESC, q.id DESC";
		$data['question_order'] = array_map( 'intval', empty( $args ) ? $wpdb->get_col( $ordered_sql ) : $wpdb->get_col( $wpdb->prepare( $ordered_sql, $args ) ) );
		$positions = array_flip( $data['question_order'] );
		$data['rows'] = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT q.*, s.name AS skill_name, d.name AS difficulty_name
				 FROM $table q
				 LEFT JOIN {$wpdb->prefix}psm_skills s ON s.id = q.skill_id
				 LEFT JOIN {$wpdb->prefix}psm_difficulties d ON d.id = q.difficulty
				 $where ORDER BY q.updated_at DESC, q.id DESC LIMIT %d, %d",
				array_merge( $args, array( $offset, $data['per_page'] ) )
			)
		);

		$question_ids = array();
		foreach ( $data['rows'] as $row ) {
			$question_ids[] = (int) $row->id;
		}
		$tags_by_question = array();
		if ( ! empty( $question_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
			$tag_rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT qt.question_id, t.slug FROM {$wpdb->prefix}psm_question_tags qt
					 INNER JOIN {$wpdb->prefix}psm_tags t ON t.id = qt.tag_id
					 WHERE qt.question_id IN ($placeholders) ORDER BY t.sort_order ASC, t.name ASC",
					$question_ids
				)
			);
			foreach ( $tag_rows as $tag_row ) {
				$tags_by_question[ (int) $tag_row->question_id ][] = $tag_row->slug;
			}
		}
		foreach ( $data['rows'] as $row ) {
			$options = json_decode( $row->options, true );
			$correct = json_decode( $row->correct_keys, true );
			$row->options = is_array( $options ) ? $options : array();
			$row->correct_keys = is_array( $correct ) ? array_values( $correct ) : array();
			$row->tag_slugs = $tags_by_question[ (int) $row->id ] ?? array();
			$position = $positions[ (int) $row->id ] ?? 0;
			$row->previous_id = $position > 0 ? (int) $data['question_order'][ $position - 1 ] : 0;
			$row->next_id = isset( $data['question_order'][ $position + 1 ] ) ? (int) $data['question_order'][ $position + 1 ] : 0;
		}
		return $data;
	}

	public function ajax_get_master_list() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}
		$entity = sanitize_key( $_POST['entity'] ?? '' );
		if ( ! in_array( $entity, array( 'domain', 'skill_type', 'question_type', 'difficulty', 'skill', 'tag', 'question' ), true ) ) {
			wp_send_json_error( array( 'message' => 'Invalid master entity.' ) );
		}
		$data = $this->get_master_list_data( $entity, $_POST );
		wp_send_json_success(
			array(
				'html'            => $this->render_master_list_html( $data ),
				'counts'          => $this->get_master_counts(),
				'filters'         => $data['filters'],
				'page'            => $data['page'],
				'total_pages'     => $data['total_pages'],
				'question_order'  => $data['question_order'],
				'tags'            => $data['tags'],
				'skills'          => $data['skills'],
			)
		);
	}

	private function render_master_filters_html( $data ) {
		$entity  = $data['entity'];
		$filters = $data['filters'];
		$compact = in_array( $entity, array( 'question', 'tag' ), true );
		$html    = '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="psm-filter-bar' . ( $compact ? ' psm-filter-compact' : '' ) . '" id="psm_master_filters" data-entity="' . esc_attr( $entity ) . '">';
		$html   .= '<input type="hidden" name="page" value="psm-ai-importer"><input type="hidden" name="tab" value="masters"><input type="hidden" name="entity" value="' . esc_attr( $entity ) . '">';
		$search_name = $entity . '_search';
		$placeholders = array(
			'domain'        => 'Search domain name',
			'skill_type'    => 'Search skill type name',
			'question_type' => 'Search question type name',
			'difficulty'    => 'Search difficulty name',
			'skill'         => 'Search skill name',
			'tag'           => 'Example: csharp-* or *oop',
			'question'      => 'Search question text',
		);
		$html .= '<label class="psm-filter-field psm-filter-search"><span>' . esc_html( 'tag' === $entity ? 'Tag name or slug' : 'Search' ) . '</span><input type="search" name="' . esc_attr( $search_name ) . '" value="' . esc_attr( $filters['search'] ) . '" placeholder="' . esc_attr( $placeholders[ $entity ] ) . '" data-tooltip="' . esc_attr( 'tag' === $entity ? 'Use * for any characters and ? for one character' : 'Search by name' ) . '"></label>';

		if ( 'skill_type' === $entity ) {
			$html .= '<label class="psm-filter-field"><span>Domain</span><select name="skill_type_domain" data-tooltip="Show skill types used in this domain"><option value="">All domains</option>';
			foreach ( $data['domains'] as $domain ) {
				$html .= '<option value="' . esc_attr( $domain->id ) . '" ' . selected( $filters['domain_id'], $domain->id, false ) . '>' . esc_html( $domain->name ) . '</option>';
			}
			$html .= '</select></label>';
		}
		if ( 'question' === $entity ) {
			$html .= '<label class="psm-filter-field"><span>Skill</span><select name="question_skill" id="psm_question_skill_filter" data-tooltip="Filter by skill"><option value="">All skills</option>';
			foreach ( $data['skills'] as $skill ) {
				$html .= '<option value="' . esc_attr( $skill->id ) . '" ' . selected( $filters['skill_id'], $skill->id, false ) . '>' . esc_html( $skill->name ) . '</option>';
			}
			$html .= '</select></label><label class="psm-filter-field"><span>Tag</span><select name="question_tag" id="psm_question_tag_filter" data-tooltip="Filter by a tag owned by the selected skill"><option value="">All tags</option>';
			foreach ( $data['tags'] as $tag ) {
				$html .= '<option value="' . esc_attr( $tag->id ) . '" data-skill-id="' . esc_attr( $tag->skill_id ) . '" ' . selected( $filters['tag_id'], $tag->id, false ) . '>' . esc_html( $tag->name . ' [' . $tag->slug . ']' ) . '</option>';
			}
			$html .= '</select></label><label class="psm-filter-field"><span>Status</span><select name="question_status" data-tooltip="Filter by draft or published status"><option value="">All statuses</option><option value="draft" ' . selected( $filters['status'], 'draft', false ) . '>Draft</option><option value="published" ' . selected( $filters['status'], 'published', false ) . '>Published</option></select></label>';
		} elseif ( 'tag' === $entity ) {
			$html .= '<label class="psm-filter-field"><span>Skill</span><select name="tag_skill" data-tooltip="Filter tags by their owning skill"><option value="">All skills</option>';
			foreach ( $data['skills'] as $skill ) {
				$html .= '<option value="' . esc_attr( $skill->id ) . '" ' . selected( $filters['skill_id'], $skill->id, false ) . '>' . esc_html( $skill->name ) . '</option>';
			}
			$html .= '</select></label>';
		} elseif ( 'skill' === $entity ) {
			$html .= '<label class="psm-filter-field"><span>Domain</span><select name="skill_domain" data-tooltip="Filter skills by domain"><option value="">All domains</option>';
			foreach ( $data['domains'] as $domain ) {
				$html .= '<option value="' . esc_attr( $domain->id ) . '" ' . selected( $filters['domain_id'], $domain->id, false ) . '>' . esc_html( $domain->name ) . '</option>';
			}
			$html .= '</select></label><label class="psm-filter-field"><span>Skill Type</span><select name="skill_type" data-tooltip="Filter skills by skill type"><option value="">All skill types</option>';
			foreach ( $data['skill_types'] as $type ) {
				$html .= '<option value="' . esc_attr( $type->id ) . '" ' . selected( $filters['skill_type_id'], $type->id, false ) . '>' . esc_html( $type->name ) . '</option>';
			}
			$html .= '</select></label><label class="psm-filter-field"><span>Status</span><select name="skill_status" data-tooltip="Filter skills by status"><option value="">All statuses</option><option value="active" ' . selected( $filters['status'], 'active', false ) . '>Active</option><option value="inactive" ' . selected( $filters['status'], 'inactive', false ) . '>Inactive</option></select></label>';
		} elseif ( 'question_type' === $entity || 'difficulty' === $entity ) {
			$html .= '<label class="psm-filter-field"><span>Status</span><select name="' . esc_attr( $entity . '_status' ) . '" data-tooltip="Filter by status"><option value="">All statuses</option><option value="active" ' . selected( $filters['status'], 'active', false ) . '>Active</option><option value="inactive" ' . selected( $filters['status'], 'inactive', false ) . '>Inactive</option></select></label>';
		}
		$html .= '<div class="psm-filter-actions"><button type="submit" class="psm-btn psm-btn-primary" data-tooltip="Apply filters"><span class="dashicons dashicons-filter" aria-hidden="true"></span> Filter</button><button type="button" class="psm-btn psm-btn-secondary" data-psm-reset-filters data-tooltip="Clear all filters">Reset</button></div></form>';
		return $html;
	}

	private function render_master_table_html( $data ) {
		$entity = $data['entity'];
		$rows   = $data['rows'];
		$html   = '<table class="widefat striped" id="psm_master_table"><thead><tr><th style="width:65px;">ID</th><th>Name</th><th>Slug</th>';
		if ( 'question' === $entity ) {
			$html .= '<th>Skill</th><th>Type</th><th>Difficulty</th><th>Correct</th><th>Tags</th><th style="width:90px;">Status</th><th style="width:140px;">Updated</th><th style="width:110px;">Actions</th>';
		} else {
			if ( 'skill' === $entity ) {
				$html .= '<th>Domain</th><th>Skill Type</th><th>Parent Skill</th><th>Description</th>';
			} elseif ( 'tag' === $entity ) {
				$html .= '<th>Skill</th><th>Description</th>';
			} else {
				$html .= '<th>Description</th>';
			}
			$html .= '<th style="width:90px;">Status</th><th style="width:110px;">Actions</th>';
		}
		$html .= '</tr></thead><tbody>';

		if ( empty( $rows ) ) {
			$colspan = 'question' === $entity ? 10 : ( 'skill' === $entity ? 9 : ( 'tag' === $entity ? 7 : 6 ) );
			$html   .= '<tr><td colspan="' . $colspan . '" class="psm-empty-state">No records match the selected filters.</td></tr>';
		} elseif ( 'question' === $entity ) {
			$type_labels = array( 'four_option' => 'Four Options (Single / Multiple)', 'two_option' => 'Two Options', 'true_false' => 'True / False' );
			foreach ( $rows as $q ) {
				$plain = preg_replace( '/\s+/u', ' ', $q->question_text );
				$excerpt = mb_substr( $plain, 0, 180 );
				if ( mb_strlen( $plain ) > 180 ) {
					$excerpt .= '…';
				}
				$record = array(
					'id' => $q->id, 'skill_id' => $q->skill_id, 'difficulty' => $q->difficulty,
					'question_type' => $q->question_type, 'question_text' => $q->question_text,
					'code_snippet' => $q->code_snippet, 'code_language' => $q->code_language,
					'options' => $q->options, 'correct_keys' => $q->correct_keys, 'is_multiple' => $q->is_multiple,
					'explanation' => $q->explanation, 'status' => $q->status, 'external_id' => $q->external_id,
					'tag_slugs' => $q->tag_slugs, 'previous_id' => (int) $q->previous_id, 'next_id' => (int) $q->next_id,
				);
				$badge = 'published' === $q->status ? 'psm-badge-active' : 'psm-badge-draft';
				$html .= '<tr id="psm-row-question-' . esc_attr( $q->id ) . '"><td><strong>#' . esc_html( $q->id ) . '</strong></td><td><strong>' . esc_html( $excerpt ) . '</strong></td><td><span class="psm-badge psm-badge-domain">' . esc_html( $q->skill_name ?: '—' ) . '</span></td><td><span class="psm-badge psm-badge-type">' . esc_html( $type_labels[ $q->question_type ] ?? $q->question_type ) . '</span></td><td>' . esc_html( $q->difficulty_name ?: $q->difficulty ) . '</td><td><code>' . esc_html( implode( ', ', $q->correct_keys ) ?: '—' ) . '</code>';
				if ( $q->is_multiple ) {
					$html .= '<br><small>Multi choice</small>';
				}
				$html .= '</td><td>';
				if ( empty( $q->tag_slugs ) ) {
					$html .= '—';
				} else {
					foreach ( $q->tag_slugs as $slug ) {
						$html .= '<span class="psm-badge psm-badge-type">' . esc_html( $slug ) . '</span> ';
					}
				}
				$html .= '</td><td><span class="psm-badge ' . $badge . '">' . esc_html( ucfirst( $q->status ) ) . '</span></td><td>' . esc_html( $this->local_time( $q->updated_at ) ) . '</td><td><button type="button" class="psm-icon-btn psm-btn-edit psm-open-modal" title="Edit question" data-tooltip="Edit question" data-entity="question" data-record=\'' . esc_attr( wp_json_encode( $record ) ) . '\'><span class="dashicons dashicons-edit"></span></button><button type="button" class="psm-icon-btn psm-btn-delete psm-ajax-delete" title="Delete question" data-tooltip="Delete question" data-entity="question" data-id="' . esc_attr( $q->id ) . '" data-name="' . esc_attr( $excerpt ) . '"><span class="dashicons dashicons-trash"></span></button></td></tr>';
			}
		} else {
			foreach ( $rows as $r ) {
				$html .= '<tr id="psm-row-' . esc_attr( $entity . '-' . $r->id ) . '"><td><strong>#' . esc_html( $r->id ) . '</strong></td><td><strong>' . esc_html( $r->name ) . '</strong></td><td><code>' . esc_html( $r->slug ) . '</code></td>';
				if ( 'skill' === $entity ) {
					$html .= '<td><span class="psm-badge psm-badge-domain">' . esc_html( $r->domain_name ?: '—' ) . '</span></td><td><span class="psm-badge psm-badge-type">' . esc_html( $r->master_type_name ?: $r->skill_type ) . '</span></td><td>' . esc_html( $r->parent_skill_name ?: '—' ) . '</td><td>' . esc_html( $r->description ?: '—' ) . '</td>';
				} elseif ( 'tag' === $entity ) {
					$html .= '<td><span class="psm-badge psm-badge-type">' . esc_html( $r->skill_name ?: $r->skill_id ) . '</span></td><td>' . esc_html( $r->description ?: '—' ) . '</td>';
				} else {
					$html .= '<td>' . esc_html( $r->description ?: '—' ) . '</td>';
				}
				$badge = 'active' === $r->status ? 'psm-badge-active' : 'psm-badge-inactive';
				$html .= '<td><span class="psm-badge ' . $badge . '">' . esc_html( ucfirst( $r->status ) ) . '</span></td><td><button type="button" class="psm-icon-btn psm-btn-edit psm-open-modal" title="Edit record" data-tooltip="Edit record" data-entity="' . esc_attr( $entity ) . '" data-record=\'' . esc_attr( wp_json_encode( $r ) ) . '\'><span class="dashicons dashicons-edit"></span></button><button type="button" class="psm-icon-btn psm-btn-delete psm-ajax-delete" title="Delete record" data-tooltip="Delete record" data-entity="' . esc_attr( $entity ) . '" data-id="' . esc_attr( $r->id ) . '" data-name="' . esc_attr( $r->name ) . '"><span class="dashicons dashicons-trash"></span></button></td></tr>';
			}
		}
		$html .= '</tbody></table>';
		$labels = array( 'domain' => 'domains', 'skill_type' => 'skill types', 'question_type' => 'question types', 'difficulty' => 'difficulties', 'skill' => 'skills', 'tag' => 'tags', 'question' => 'questions' );
		$html .= $this->master_pagination_html( $data['page'], $data['total_pages'], $data['filtered_count'], $data['per_page'], $entity . '_page', $labels[ $entity ] );
		return $html;
	}

	private function render_master_list_html( $data ) {
		return '<div class="psm-master-list" id="psm_master_list" data-entity="' . esc_attr( $data['entity'] ) . '">' . $this->render_master_filters_html( $data ) . $this->render_master_table_html( $data ) . '</div>';
	}

	/**
	 * AJAX: Delete Master record with strict dependency validation for Skills and Tags.
	 */
	public function ajax_delete_master() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;
		$entity    = sanitize_key( $_POST['entity'] ?? '' );
		$record_id = absint( $_POST['record_id'] ?? 0 );

		if ( ! $record_id ) {
			wp_send_json_error( array( 'message' => 'Invalid record ID.' ) );
		}

		if ( 'question' === $entity ) {
			$questions_table = $wpdb->prefix . 'psm_questions';
			$exists          = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $questions_table WHERE id = %d", $record_id ) );
			if ( ! $exists ) {
				wp_send_json_error( array( 'message' => 'Question not found.' ) );
			}

			$wpdb->query( 'START TRANSACTION' );
			$unlinked_tags = $wpdb->delete( $wpdb->prefix . 'psm_question_tags', array( 'question_id' => $record_id ), array( '%d' ) );
			$unlinked_batches = $wpdb->delete( $wpdb->prefix . 'psm_batch_questions', array( 'question_id' => $record_id ), array( '%d' ) );
			$deleted = $wpdb->delete( $questions_table, array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $unlinked_tags || false === $unlinked_batches || false === $deleted || $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				wp_send_json_error( array( 'message' => $wpdb->last_error ?: 'Could not delete the question.' ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_send_json_error( array( 'message' => $wpdb->last_error ?: 'Could not commit the deletion.' ) );
			}
			wp_send_json_success( array( 'message' => 'Question and its tag and batch links deleted.' ) );
		}

		if ( $entity === 'skill' ) {
			$q_count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_questions WHERE skill_id = %d", $record_id )
			);
			$t_count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_tags WHERE skill_id = %d", $record_id )
			);
			$child_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}psm_skills WHERE parent_id = %d OR parent_skill_id = %d",
					$record_id,
					$record_id
				)
			);

			if ( $q_count > 0 || $t_count > 0 || $child_count > 0 ) {
				$reasons = array();
				if ( $q_count > 0 ) {
					$reasons[] = "{$q_count} question(s)";
				}
				if ( $t_count > 0 ) {
					$reasons[] = "{$t_count} tag(s)";
				}
				if ( $child_count > 0 ) {
					$reasons[] = "{$child_count} child skill(s)";
				}
				wp_send_json_error( array(
					'message' => 'Cannot delete Skill because it is linked to ' . implode( ', ', $reasons ) . '. Remove or reassign them first.',
				) );
			}

			$wpdb->delete( $wpdb->prefix . 'psm_skills', array( 'id' => $record_id ), array( '%d' ) );
			wp_send_json_success( array( 'message' => 'Skill deleted successfully.' ) );
		}

		if ( $entity === 'tag' ) {
			$q_count = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_question_tags WHERE tag_id = %d", $record_id )
			);
			if ( $q_count > 0 ) {
				wp_send_json_error( array(
					'message' => "Cannot delete Tag because {$q_count} question(s) are assigned to it. Unlink those questions first.",
				) );
			}

			$wpdb->delete( $wpdb->prefix . 'psm_tags', array( 'id' => $record_id ), array( '%d' ) );
			wp_send_json_success( array( 'message' => 'Tag deleted successfully.' ) );
		}

		if ( $entity === 'domain' ) {
			$s_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_skills WHERE domain_id = %d", $record_id ) );
			if ( $s_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Domain because {$s_count} skill(s) belong to it." ) );
			}
			$wpdb->delete( $wpdb->prefix . 'psm_domains', array( 'id' => $record_id ), array( '%d' ) );
			wp_send_json_success( array( 'message' => 'Domain deleted successfully.' ) );
		}

		if ( $entity === 'skill_type' ) {
			$s_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_skills WHERE skill_type_id = %d", $record_id ) );
			if ( $s_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Skill Type because {$s_count} skill(s) use it." ) );
			}
			$wpdb->delete( $wpdb->prefix . 'psm_skill_types', array( 'id' => $record_id ), array( '%d' ) );
			wp_send_json_success( array( 'message' => 'Skill Type deleted successfully.' ) );
		}

		if ( $entity === 'question_type' ) {
			$slug = (string) $wpdb->get_var( $wpdb->prepare( "SELECT slug FROM {$wpdb->prefix}psm_question_types WHERE id = %d", $record_id ) );
			$q_count = $slug ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_questions WHERE question_type = %s", $slug ) ) : 0;
			if ( $q_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Question Type because {$q_count} question(s) use slug '{$slug}'." ) );
			}
			$wpdb->delete( $wpdb->prefix . 'psm_question_types', array( 'id' => $record_id ), array( '%d' ) );
			wp_send_json_success( array( 'message' => 'Question Type deleted successfully.' ) );
		}

		if ( $entity === 'difficulty' ) {
			$q_count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_questions WHERE difficulty = %d", $record_id ) );
			if ( $q_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Difficulty because {$q_count} question(s) reference ID {$record_id}." ) );
			}
			$wpdb->delete( $wpdb->prefix . 'psm_difficulties', array( 'id' => $record_id ), array( '%d' ) );
			wp_send_json_success( array( 'message' => 'Difficulty deleted successfully.' ) );
		}

		if ( $entity === 'batch' ) {
			$wpdb->delete( $wpdb->prefix . 'psm_batch_questions', array( 'batch_id' => $record_id ), array( '%d' ) );
			$wpdb->delete( $wpdb->prefix . 'psm_batches', array( 'id' => $record_id ), array( '%d' ) );
			wp_send_json_success( array( 'message' => 'Batch and its question links deleted.' ) );
		}

		wp_send_json_error( array( 'message' => 'Unsupported entity.' ) );
	}

	public function handle_run_seeder() {
		check_admin_referer( 'psm_seeder_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		$summary = PSM_Seeder::run_seed();
		set_transient( 'psm_admin_notice_' . get_current_user_id(), array(
			'type' => 'success',
			'text' => sprintf(
				'Idempotent seed completed with descriptions & fixed slugs: %d new domains, %d new skills, %d new tags added. Existing records preserved.',
				$summary['domains'],
				$summary['skills'],
				$summary['tags']
			),
		), 60 );

		wp_safe_redirect( add_query_arg( array( 'page' => 'psm-ai-importer', 'tab' => 'masters' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Shared preview builder used by both the POST and AJAX entry points.
	 *
	 * Returns the preview payload or a WP_Error that the caller can surface.
	 *
	 * @param array $source Usually $_POST.
	 * @return array|WP_Error
	 */
	private function collect_preview( $source ) {
		global $wpdb;

		$skill_id       = absint( $source['skill_id'] ?? 0 );
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

		$skill = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psm_skills WHERE id = %d", $skill_id ) );
		if ( ! $skill ) {
			return new WP_Error( 'invalid_skill', 'Please select a valid Skill.' );
		}

		if ( 'all' === $difficulty_raw || 'mixed' === $difficulty_raw ) {
			$diff_label = 'All Levels (beginner, intermediate, advanced)';
		} else {
			$diff_row = is_numeric( $difficulty_raw )
				? $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psm_difficulties WHERE id = %d", $difficulty_id ) )
				: $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}psm_difficulties WHERE slug = %s", $difficulty_raw ) );
			if ( $diff_row && ! is_numeric( $difficulty_raw ) ) {
				$difficulty_id = (int) $diff_row->id;
			}
			$diff_label = $diff_row ? "{$diff_row->id} ({$diff_row->name} / {$diff_row->slug})" : (string) $difficulty_raw;
		}

		$skill_tags = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, name, slug FROM {$wpdb->prefix}psm_tags WHERE skill_id = %d AND status = 'active'", $skill_id )
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
			$raw_json = wp_unslash( $source['manual_json_payload'] ?? '' );
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
			'skill_id'           => $skill_id,
			'skill_name'         => $skill->name,
			'tag_ids'            => $tag_ids,
			'default_status'     => $default_status,
			'default_difficulty' => $difficulty_id,
			'label_name'         => $label_name ?: ( $skill->name . ' - ' . wp_date( 'Y-m-d H:i' ) ),
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
			set_transient( 'psm_admin_notice_' . get_current_user_id(), array( 'type' => 'error', 'text' => $preview->get_error_message() ), 60 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'psm-ai-importer', 'tab' => 'generate' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		set_transient( 'psm_preview_data_' . get_current_user_id(), $preview, 1800 );

		wp_safe_redirect( add_query_arg( array( 'page' => 'psm-ai-importer', 'tab' => 'generate', 'preview' => '1' ), admin_url( 'admin.php' ) ) );
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

			wp_send_json_success( array(
				'redirect_url' => add_query_arg(
					array( 'page' => 'psm-ai-importer', 'tab' => 'generate', 'preview' => '1' ),
					admin_url( 'admin.php' )
				),
			) );
		} catch ( \Throwable $e ) {
			error_log( '[PSM AI Importer] ajax_fetch_preview fatal error: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
			wp_send_json_error( array(
				'message' => 'Server Error (' . get_class( $e ) . '): ' . $e->getMessage() . ' in ' . basename( $e->getFile() ) . ':' . $e->getLine(),
			) );
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
		$skill_id = absint( $_POST['skill_id'] ?? 0 );
		$skill    = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, slug FROM {$wpdb->prefix}psm_skills WHERE id = %d", $skill_id ) );
		if ( ! $skill ) {
			wp_send_json_error( array( 'message' => 'Please select a valid Skill first.' ) );
		}

		$tag_rows   = $wpdb->get_results( $wpdb->prepare( "SELECT id, name, slug FROM {$wpdb->prefix}psm_tags WHERE skill_id = %d AND status = 'active'", $skill_id ) );
		$tags_all   = array();
		$slug_by_id = array();
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
				$tags_selected[]             = $slug_by_id[ $tid ];
				$tag_labels[ $slug_by_id[ $tid ] ] = $label_by_id[ $tid ];
			}
		}

		$difficulty_raw = strtolower( sanitize_text_field( wp_unslash( $_POST['difficulty'] ?? 'all' ) ) );
		if ( '' === $difficulty_raw || 'all' === $difficulty_raw || 'mixed' === $difficulty_raw ) {
			$difficulty = 'all';
		} else {
			$diff_id    = PSM_Importer::normalize_difficulty_id( $difficulty_raw );
			$difficulty = array( 1 => 'beginner', 2 => 'intermediate', 3 => 'advanced' )[ $diff_id ];
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

	/**
	 * Import questions from an uploaded .json file, then show the normal preview.
	 */
	public function handle_import_json_file() {
		check_admin_referer( 'psm_import_json_file_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$skill_id = absint( $_POST['skill_id'] ?? 0 );
		$skill    = $wpdb->get_row( $wpdb->prepare( "SELECT id, name, slug FROM {$wpdb->prefix}psm_skills WHERE id = %d", $skill_id ) );
		if ( ! $skill ) {
			$this->redirect_with_notice( 'Please select a valid Skill for the JSON import.' );
		}

		$focus_tag_id = absint( $_POST['focus_tag_id'] ?? 0 );
		$focus_tag    = null;
		if ( $focus_tag_id ) {
			$focus_tag = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name, slug FROM {$wpdb->prefix}psm_tags WHERE id = %d AND skill_id = %d AND status = 'active'",
					$focus_tag_id,
					$skill_id
				)
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

		$envelope      = json_decode( $raw, true );
		$file_diff     = ( is_array( $envelope ) && isset( $envelope['generation']['difficulty'] ) )
			? sanitize_text_field( (string) $envelope['generation']['difficulty'] )
			: 'all';

		$preview = $this->collect_preview(
			array(
				'skill_id'            => $skill_id,
				'tag_ids'             => array(),
				'difficulty'          => '' !== $file_diff ? $file_diff : 'all',
				'default_status'      => $default_status,
				'label_name'          => $label,
				'import_mode'         => 'manual_json',
				'manual_json_payload' => $raw,
				'focus_tag_slug'      => $focus_tag ? $focus_tag->slug : '',
			)
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
			$envelope_slug = sanitize_title( (string) ( $decoded['skill']['slug'] ?? '' ) );
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
			if ( ! is_numeric( $declared ) || (int) $declared !== count( $questions ) ) {
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

			$answer_mode = strtolower( trim( (string) ( $q['answer_mode'] ?? '' ) ) );
			$expected_mode = ( 'multiple_choice' === $q_type ) ? 'multiple' : 'single';
			if ( $answer_mode !== $expected_mode ) {
				return new WP_Error(
					'answer_mode_mismatch',
					'Question #' . $row . ' must use "answer_mode": "' . $expected_mode . '" for ' . $q_type . '.'
				);
			}

			$options   = $q['options'] ?? array();
			$opt_count = is_array( $options ) ? count( $options ) : 0;
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
			$correct = is_array( $correct_raw )
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
			$prefixes = array_unique( array_map( static function ( $pair ) { return $pair[0]; }, $parsed_ids ) );
			if ( 1 === count( $prefixes ) ) {
				$sequence = array_map( static function ( $pair ) { return $pair[1]; }, array_values( $parsed_ids ) );
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
			set_transient( 'psm_admin_notice_' . $user_id, array( 'type' => 'error', 'text' => 'Preview expired or not found.' ), 60 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'psm-ai-importer', 'tab' => 'generate' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		if ( ! empty( $_POST['cancel_preview'] ) ) {
			delete_transient( 'psm_preview_data_' . $user_id );
			set_transient( 'psm_admin_notice_' . $user_id, array( 'type' => 'info', 'text' => 'Preview discarded.' ), 60 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'psm-ai-importer', 'tab' => 'generate' ), admin_url( 'admin.php' ) ) );
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
			set_transient( 'psm_admin_notice_' . $user_id, array( 'type' => 'error', 'text' => 'Select at least one row to import.' ), 60 );
			wp_safe_redirect( add_query_arg( array( 'page' => 'psm-ai-importer', 'tab' => 'generate', 'preview' => '1' ), admin_url( 'admin.php' ) ) );
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
			set_transient( 'psm_admin_notice_' . $user_id, array( 'type' => 'error', 'text' => $import_res->get_error_message() ), 60 );
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
				$shown  = array_slice( $messages, 0, 5 );
				$msg   .= ' Details: ' . implode( ' | ', $shown );
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
			set_transient( 'psm_admin_notice_' . $user_id, array( 'type' => $notice_type, 'text' => $msg ), 60 );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => 'psm-ai-importer', 'tab' => 'generate' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_download_labels() {
		check_admin_referer( 'psm_download_labels_nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Unauthorized' );
		}

		global $wpdb;
		$batch_id = absint( $_GET['batch_id'] ?? 0 );

		$q_table  = $wpdb->prefix . 'psm_questions';
		$s_table  = $wpdb->prefix . 'psm_skills';
		$b_table  = $wpdb->prefix . 'psm_batches';
		$bq_table = $wpdb->prefix . 'psm_batch_questions';
		$qt_table = $wpdb->prefix . 'psm_question_tags';
		$t_table  = $wpdb->prefix . 'psm_tags';
		$d_table  = $wpdb->prefix . 'psm_difficulties';

		if ( $batch_id > 0 ) {
			$batch = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $b_table WHERE id = %d", $batch_id ) );
			if ( ! $batch ) {
				wp_die( 'Batch not found.' );
			}
			$rows = $wpdb->get_results(
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
			$rows = $wpdb->get_results(
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
			fputcsv( $out, array(
				$this->csv_cell( $r->label_name ?: ( $r->skill_name . ' #' . $r->question_id ) ),
				$this->csv_cell( $r->batch_code ),
				$this->csv_cell( $r->question_id ),
				$this->csv_cell( $r->skill_name ),
				$this->csv_cell( $r->difficulty ),
				$this->csv_cell( $r->difficulty_name ?: $r->difficulty ),
				$this->csv_cell( $r->question_type ),
				$this->csv_cell( implode( '|', $tag_slugs ) ),
				$this->csv_cell( $r->status ),
				$this->csv_cell( wp_strip_all_tags( $r->question_text ) ),
			) );
		}

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

	public function render_page() {
		global $wpdb;
		$user_id = get_current_user_id();
		$tab     = sanitize_key( $_GET['tab'] ?? 'generate' );
		$entity  = sanitize_key( $_GET['entity'] ?? 'domain' );
		$is_prev = isset( $_GET['preview'] ) && $_GET['preview'] === '1';

		$notice = get_transient( 'psm_admin_notice_' . $user_id );
		if ( $notice ) {
			delete_transient( 'psm_admin_notice_' . $user_id );
		}

		$domains        = $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}psm_domains ORDER BY sort_order ASC, name ASC" );
		$skill_types    = PSM_Seeder::get_skill_types();
		$question_types = PSM_Seeder::get_question_types();
		$difficulties   = PSM_Seeder::get_difficulties();
		$languages      = PSM_Languages::languages();

		$skills = $wpdb->get_results(
			"SELECT s.*, d.name AS domain_name, st.name AS master_type_name, p.name AS parent_skill_name
			 FROM {$wpdb->prefix}psm_skills s
			 LEFT JOIN {$wpdb->prefix}psm_domains d ON s.domain_id = d.id
			 LEFT JOIN {$wpdb->prefix}psm_skill_types st ON s.skill_type_id = st.id
			 LEFT JOIN {$wpdb->prefix}psm_skills p ON s.parent_id = p.id
			 ORDER BY d.sort_order ASC, s.sort_order ASC, s.name ASC"
		);

		// $tags = $wpdb->get_results(
		// 	"SELECT t.*, s.name AS skill_name
		// 	 FROM {$wpdb->prefix}psm_tags t
		// 	 LEFT JOIN {$wpdb->prefix}psm_skills s ON t.skill_id = s.id
		// 	 ORDER BY t.sort_order ASC, t.name ASC"
		// );

		$tags_all = $wpdb->get_results(
			"SELECT
				t.*,
				s.name AS skill_name,
				COUNT(qt.question_id) AS question_count
			FROM {$wpdb->prefix}psm_tags t
			LEFT JOIN {$wpdb->prefix}psm_skills s ON s.id = t.skill_id
			LEFT JOIN {$wpdb->prefix}psm_question_tags qt ON qt.tag_id = t.id
			GROUP BY t.id
			ORDER BY t.sort_order ASC, t.name ASC"
		);

		$batches = $wpdb->get_results(
			"SELECT b.*, s.name AS skill_name,
			        (SELECT COUNT(*) FROM {$wpdb->prefix}psm_batch_questions bq WHERE bq.batch_id = b.id) AS linked_questions_count
			 FROM {$wpdb->prefix}psm_batches b
			 LEFT JOIN {$wpdb->prefix}psm_skills s ON b.skill_id = s.id
			 ORDER BY b.id DESC LIMIT 100"
		);

		$settings        = get_option( 'psm_ai_api_settings', array() );
		$active_provider = ! empty( $settings['provider'] ) ? $settings['provider'] : 'gemini';
		$providers_list  = PSM_API_Client::get_providers();
		$usage_status    = PSM_API_Client::get_usage_status( $active_provider );

		$tags_by_skill = array();
		foreach ( $tags_all as $t ) {
			if ( 'active' === $t->status ) {
				$tags_by_skill[ (int) $t->skill_id ][] = array(
					'id'    => (int) $t->id,
					'name'  => $t->name,
					'label' => $t->name . ( $t->question_count > 0 ? " ({$t->question_count})" : '' ),
					'slug'  => $t->slug,
				);
			}
		}
		$skill_meta = array();
		foreach ( $skills as $skill_row ) {
			$skill_meta[ (int) $skill_row->id ] = array(
				'name' => $skill_row->name,
				'slug' => $skill_row->slug,
			);
		}

		$question_type_labels = array(
			'four_option' => 'Four Options (Single / Multiple)',
			'two_option'  => 'Two Options',
			'true_false'  => 'True / False',
		);
		$master_counts    = $this->get_master_counts();
		$master_list_data = array();
		if ( $tab === 'masters' ) {
			$master_list_data = $this->get_master_list_data( $entity, $_GET );
		}
		$question_filters    = ( $tab === 'masters' && $entity === 'question' )
			? ( $master_list_data['filters'] ?? $this->get_question_filters( array() ) )
			: $this->get_question_filters( $_GET );
		$question_ordered_ids = $master_list_data['question_order'] ?? array();

		$master_qtype_labels = array();
		foreach ( $question_types as $qt_row ) {
			if ( 'active' === $qt_row->status ) {
				$master_qtype_labels[ $qt_row->slug ] = $qt_row->name;
			}
		}

		$supported_generation_types = array(
			'single_choice'   => 'Single Choice',
			'multiple_choice' => 'Multiple Choice',
			'two_option'      => 'Two Options',
			'true_false'      => 'True / False',
		);
		$generation_qtypes = array();
		foreach ( $supported_generation_types as $qt_slug => $qt_fallback ) {
			if ( isset( $master_qtype_labels[ $qt_slug ] ) ) {
				$generation_qtypes[ $qt_slug ] = $master_qtype_labels[ $qt_slug ];
			}
		}
		if ( empty( $generation_qtypes ) ) {
			$generation_qtypes = $supported_generation_types;
		}

		$default_qtypes_selected = array_keys( $generation_qtypes );
		$initial_skill_name      = 'Git';
		$initial_skill_slug      = 'git';
		foreach ( $skills as $sk_item ) {
			if ( $sk_item->slug === 'git' ) {
				$initial_skill_name = $sk_item->name;
				$initial_skill_slug = $sk_item->slug;
				break;
			}
		}
		$initial_prompt_text = PSM_API_Client::build_prompt_text( array(
			'skill_name'      => $initial_skill_name,
			'skill_slug'      => $initial_skill_slug,
			'difficulty'      => 'all',
			'difficulty_slug' => 'all',
			'question_types'  => $default_qtypes_selected,
			'count'           => 50,
		) );

		$ajax_nonce = wp_create_nonce( 'psm_ajax_nonce' );
		?>
		<style>
			.psm-wrap { max-width: 1360px; }
			.psm-header-banner {
				background: linear-gradient(135deg, #1e3a8a 0%, #4f46e5 55%, #0ea5e9 100%);
				color: #fff; padding: 18px 24px; border-radius: 10px; margin: 15px 0;
				display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 14px rgba(30,58,138,0.18);
			}
			.psm-header-banner h1 { color: #fff; margin: 0; font-size: 22px; font-weight: 700; }
			.psm-header-banner .psm-ver { background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; }
			.psm-pill-nav { display: flex; flex-wrap: wrap; gap: 8px; margin: 16px 0; }
			.psm-pill {
				padding: 9px 16px; border-radius: 7px; text-decoration: none; font-weight: 600; font-size: 13px;
				background: #eef2ff; color: #3730a3; border: 1px solid #c7d2fe;
				transition: background-color .15s, color .15s, border-color .15s;
			}
			.psm-pill:hover { background: #e0e7ff; color: #1e1b4b; border-color: #a5b4fc; }
			.psm-pill.active { background: #3730a3; color: #fff; border-color: #3730a3; }
			.psm-table-card {
				background: #fff; border-radius: 10px; padding: 18px; border: 1px solid #e2e8f0;
				box-shadow: 0 2px 10px rgba(15,23,42,0.05);
			}
			.psm-table-card table.widefat thead th {
				background: linear-gradient(90deg, #1e293b, #334155); color: #fff; font-weight: 600; border: none;
			}
			.psm-full-width-control {
				width: 100% !important;
				max-width: 100% !important;
				box-sizing: border-box;
				display: block;
			}
			.psm-break-box {
				display: flex;
				flex-direction: column;
				gap: 12px;
				background: #f8fafc;
				border: 1px solid #cbd5e1;
				border-radius: 8px;
				padding: 12px 14px;
			}
			.psm-break-item {
				display: flex;
				flex-direction: column;
				gap: 6px;
			}
			.psm-qtype-header {
				display: flex;
				align-items: center;
				justify-content: space-between;
				gap: 8px;
			}
			.psm-qtype-checks {
				display: flex;
				flex-wrap: wrap;
				gap: 12px;
			}
			.psm-qtype-checks label {
				display: inline-flex;
				align-items: center;
				gap: 5px;
				margin: 0;
				font-weight: 500;
			}
			.psm-badge { display: inline-block; padding: 3px 9px; border-radius: 12px; font-size: 11px; font-weight: 600; }
			.psm-badge-active { background: #dcfce7; color: #166534; }
			.psm-badge-inactive { background: #fee2e2; color: #991b1b; }
			.psm-badge-type { background: #e0f2fe; color: #075985; }
			.psm-badge-domain { background: #f3e8ff; color: #6b21a8; }
			.psm-badge-draft { background: #fef3c7; color: #92400e; }
			.psm-wrap .psm-modal.psm-modal-question { max-width: 920px; }
			.psm-wrap .psm-question-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
			.psm-wrap .psm-question-options { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 14px; }
			.psm-wrap .psm-question-answers { display: flex; flex-wrap: wrap; gap: 8px 18px; margin-top: 6px; }
			.psm-wrap .psm-question-answers label { display: inline-flex; align-items: center; gap: 5px; margin: 0; font-weight: 500; }
			.psm-wrap .psm-question-answers input[type="checkbox"] { width: auto; margin: 0; }
			.psm-wrap .psm-question-help { display: block; color: #64748b; font-size: 11px; font-weight: 400; margin-top: 4px; line-height: 1.5; }
			.psm-wrap .psm-question-section { border-top: 1px solid #e2e8f0; margin-top: 14px; padding-top: 14px; }
			@media (max-width: 782px) {
				.psm-wrap .psm-question-grid, .psm-wrap .psm-question-options { grid-template-columns: 1fr; }
			}
			.psm-icon-btn {
				display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px;
				border-radius: 6px; border: none; cursor: pointer; text-decoration: none; transition: transform .1s, opacity .15s;
			}
			.psm-icon-btn:hover { transform: translateY(-1px); opacity: 0.9; }
			.psm-btn-edit { background: #dbeafe; color: #1d4ed8; }
			.psm-btn-delete { background: #fee2e2; color: #dc2626; margin-left: 4px; }
			.psm-btn-add {
				background: linear-gradient(135deg, #059669, #10b981); color: #fff; border: none; padding: 9px 18px;
				border-radius: 7px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
				box-shadow: 0 2px 8px rgba(16,185,129,0.28);
			}
			.psm-btn-add:hover { opacity: 0.94; color: #fff; }
			/* Centered modal: pinned header, scrollable body, pinned footer.
			   Scoped to .psm-wrap because programming-skills-master ships a global .psm-modal rule. */
			.psm-wrap .psm-modal-backdrop {
				display: none; position: fixed; inset: 0; z-index: 100050; padding: 16px;
				align-items: center; justify-content: center; background: rgba(0,0,0,0.55); box-sizing: border-box;
			}
			.psm-wrap .psm-modal-backdrop.open { display: flex; }
			.psm-wrap .psm-modal {
				position: relative; inset: auto; top: auto; right: auto; bottom: auto; left: auto; z-index: auto;
				align-items: stretch; justify-content: flex-start; padding: 0; margin: 0;
				display: flex; flex-direction: column; width: 100%; max-width: 640px; max-height: 88vh;
				background: #fff; border-radius: 10px; overflow: hidden;
				box-shadow: 0 12px 32px rgba(0,0,0,0.25); animation: psmPop .16s ease-out;
			}
			.psm-wrap .psm-modal > form { display: flex; flex-direction: column; flex: 1 1 auto; min-height: 0; }
			@keyframes psmPop { from { transform: scale(0.96); opacity: 0; } to { transform: scale(1); opacity: 1; } }
			.psm-wrap .psm-modal-header {
				flex: 0 0 auto; display: flex; align-items: center; justify-content: space-between;
				padding: 14px 18px; border-bottom: 1px solid #dcdcde; background: #fff;
			}
			.psm-wrap .psm-modal-header h3 { margin: 0; color: #1d2327; font-size: 16px; font-weight: 600; }
			.psm-wrap .psm-modal-close { background: none; border: none; color: #646970; font-size: 22px; cursor: pointer; line-height: 1; }
			.psm-wrap .psm-modal-close:hover, .psm-wrap .psm-modal-close:focus { color: #d63638; }
			.psm-wrap .psm-modal-body { flex: 1 1 auto; min-height: 0; overflow-y: auto; padding: 18px; }
			.psm-wrap .psm-modal-body label { display: block; font-weight: 600; margin-bottom: 10px; color: #1e293b; }
			.psm-wrap .psm-modal-body input[type="text"], .psm-wrap .psm-modal-body input[type="number"], .psm-wrap .psm-modal-body select, .psm-wrap .psm-modal-body textarea {
				width: 100%; margin-top: 4px; border-radius: 6px; border: 1px solid #cbd5e1; padding: 6px 10px; box-sizing: border-box;
			}
			.psm-wrap .psm-modal-footer {
				flex: 0 0 auto; display: flex; align-items: center; justify-content: flex-end; gap: 10px;
				padding: 12px 18px; border-top: 1px solid #dcdcde; background: #f6f7f7;
			}
			.psm-save-status { min-height: 18px; margin-top: 8px; font-size: 12px; font-weight: 600; color: #059669; }
			.psm-save-status.err { color: #dc2626; }
			/* 3 AI Cards Grid */
			.psm-ai-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 20px; margin-top: 20px; }
			.psm-ai-card {
				background: #fff; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;
				box-shadow: 0 4px 12px rgba(15,23,42,0.06); display: flex; flex-direction: column;
			}
			.psm-ai-card-head { padding: 14px 18px; color: #fff; font-weight: 700; display: flex; justify-content: space-between; align-items: center; }
			.psm-ai-gemini .psm-ai-card-head { background: linear-gradient(135deg, #2563eb, #06b6d4); }
			.psm-ai-openai .psm-ai-card-head { background: linear-gradient(135deg, #059669, #10b981); }
			.psm-ai-custom .psm-ai-card-head { background: linear-gradient(135deg, #7c3aed, #ec4899); }
			.psm-ai-card-body { padding: 16px 18px; flex: 1; }
			.psm-ai-card-body p { margin: 0 0 11px; }
			.psm-ai-card-body label { font-weight: 600; font-size: 12px; color: #334155; display: block; margin-bottom: 3px; }
			.psm-ai-card-body input[type="text"], .psm-ai-card-body input[type="password"], .psm-ai-card-body input[type="url"], .psm-ai-card-body input[type="number"] {
				width: 100%; border-radius: 6px; border: 1px solid #cbd5e1; padding: 6px 9px;
			}
			.psm-quota-box {
				background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; padding: 9px 11px;
				font-size: 12px; margin-bottom: 12px; line-height: 1.5;
			}
			.psm-toast {
				position: relative; z-index: 100100; width: fit-content; max-width: 100%; margin: 12px 0;
				padding: 12px 18px; border-radius: 8px; color: #fff; font-weight: 600;
				box-shadow: 0 8px 24px rgba(0,0,0,0.16); display: none;
			}
			.psm-toast.ok { background: #059669; }
			.psm-toast.err { background: #dc2626; }
			#psm_import_json_card { scroll-margin-top: 48px; }
			.psm-table-card.psm-flash { animation: psmCardFlash 1.6s ease; }
			@keyframes psmCardFlash {
				0%   { box-shadow: 0 0 0 0 rgba(245,158,11,0); }
				25%  { box-shadow: 0 0 0 5px rgba(245,158,11,0.55); }
				100% { box-shadow: 0 0 0 0 rgba(245,158,11,0); }
			}
			.psm-field-error { outline: 2px solid #dc2626 !important; box-shadow: 0 0 0 3px rgba(220,38,38,0.18) !important; }
			.psm-wrap .nav-tab-wrapper { display: flex; flex-wrap: wrap; gap: 8px; border-bottom: 0; margin: 18px 0 8px; }
			.psm-wrap .nav-tab {
				border: 0; border-radius: 9px; padding: 10px 15px; color: #fff; font-weight: 700;
				box-shadow: 0 3px 10px rgba(15,23,42,0.14); transition: transform .15s, box-shadow .15s, opacity .15s;
			}
			.psm-wrap .nav-tab:nth-child(1) { background: linear-gradient(135deg, #2563eb, #06b6d4); }
			.psm-wrap .nav-tab:nth-child(2) { background: linear-gradient(135deg, #7c3aed, #ec4899); }
			.psm-wrap .nav-tab:nth-child(3) { background: linear-gradient(135deg, #059669, #84cc16); }
			.psm-wrap .nav-tab:nth-child(4) { background: linear-gradient(135deg, #ea580c, #f59e0b); }
			.psm-wrap .nav-tab:hover { color: #fff; transform: translateY(-1px); box-shadow: 0 5px 14px rgba(15,23,42,0.2); }
			.psm-wrap .nav-tab.nav-tab-active { color: #fff; outline: 3px solid rgba(255,255,255,0.85); outline-offset: -3px; transform: translateY(-1px); }
			.psm-pill:nth-child(1) { background: #e0f2fe; color: #075985; border-color: #7dd3fc; }
			.psm-pill:nth-child(2) { background: #ede9fe; color: #5b21b6; border-color: #c4b5fd; }
			.psm-pill:nth-child(3) { background: #fce7f3; color: #9d174d; border-color: #f9a8d4; }
			.psm-pill:nth-child(4) { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
			.psm-pill:nth-child(5) { background: #dcfce7; color: #166534; border-color: #86efac; }
			.psm-pill:nth-child(6) { background: #ffedd5; color: #9a3412; border-color: #fdba74; }
			.psm-pill:nth-child(7) { background: #e0e7ff; color: #3730a3; border-color: #a5b4fc; }
			.psm-pill.active { outline: 2px solid currentColor; outline-offset: 2px; }
			.psm-filter-bar {
				display: grid; grid-template-columns: minmax(220px, 1.4fr) repeat(3, minmax(150px, 1fr)) auto;
				gap: 12px; align-items: end; padding: 14px; margin-bottom: 16px; border-radius: 10px;
				background: linear-gradient(135deg, #f8fafc, #eef2ff); border: 1px solid #dbeafe;
			}
			.psm-filter-field { position: relative; display: flex; flex-direction: column; gap: 5px; margin: 0; min-width: 0; }
			.psm-filter-field > span { color: #334155; font-size: 12px; font-weight: 700; }
			.psm-filter-compact .psm-filter-field > span {
				position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
				overflow: hidden; clip: rect(0,0,0,0); white-space: nowrap; border: 0;
			}
			.psm-filter-field input, .psm-filter-field select {
				width: 100%; height: 44px; min-height: 44px; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff;
				padding: 8px 11px; box-shadow: 0 1px 2px rgba(15,23,42,0.04); transition: border-color .15s, box-shadow .15s;
			}
			.psm-filter-field input:focus, .psm-filter-field select:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.15); outline: 0; }
			.psm-filter-actions { display: flex; gap: 8px; }
			.psm-filter-actions .psm-btn { height: 44px; }
			.psm-master-list.is-loading { opacity: .58; pointer-events: none; }
			.psm-btn {
				display: inline-flex; align-items: center; justify-content: center; gap: 6px; min-height: 38px;
				padding: 8px 14px; border-radius: 8px; border: 1px solid transparent; font-weight: 700; font-size: 12px;
				text-decoration: none; cursor: pointer; transition: transform .12s, box-shadow .15s, opacity .15s;
			}
			.psm-btn:hover { transform: translateY(-1px); text-decoration: none; }
			.psm-btn-primary { background: linear-gradient(135deg, #4f46e5, #2563eb); color: #fff; box-shadow: 0 3px 9px rgba(37,99,235,0.22); }
			.psm-btn-secondary { background: #fff; color: #475569; border-color: #cbd5e1; box-shadow: 0 2px 5px rgba(15,23,42,0.06); }
			.psm-btn:disabled, .psm-btn.is-disabled { opacity: .5; cursor: not-allowed; transform: none; }
			.psm-pagination { display: flex; align-items: center; justify-content: space-between; gap: 14px; flex-wrap: wrap; margin-top: 16px; }
			.psm-pagination-info { color: #64748b; font-size: 12px; }
			.psm-pagination-info strong { color: #334155; }
			.psm-pagination-links { display: flex; align-items: center; gap: 5px; flex-wrap: wrap; }
			.psm-page-btn {
				display: inline-flex; align-items: center; justify-content: center; gap: 4px; min-width: 34px; height: 34px;
				padding: 0 9px; border-radius: 8px; border: 1px solid #cbd5e1; background: #fff; color: #475569;
				font-weight: 700; font-size: 12px; text-decoration: none; box-shadow: 0 2px 5px rgba(15,23,42,0.05);
			}
			.psm-page-btn .dashicons { font-size: 18px; width: 18px; height: 18px; }
			.psm-page-btn:hover { color: #3730a3; border-color: #a5b4fc; background: #eef2ff; text-decoration: none; }
			.psm-page-btn.is-current { color: #fff; border-color: #4f46e5; background: linear-gradient(135deg, #4f46e5, #2563eb); }
			.psm-page-btn.is-disabled { opacity: .42; cursor: not-allowed; box-shadow: none; }
			.psm-page-ellipsis { color: #94a3b8; padding: 0 3px; }
			.psm-wrap .psm-modal-title-wrap { display: flex; flex-direction: column; gap: 6px; min-width: 0; }
			.psm-question-edit-nav { align-items: center; gap: 8px; color: #64748b; font-size: 11px; font-weight: 700; }
			.psm-nav-arrow {
				display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 26px; padding: 0;
				border: 1px solid #c7d2fe; border-radius: 7px; background: #eef2ff; color: #4338ca; cursor: pointer;
			}
			.psm-nav-arrow:hover:not(:disabled) { background: #e0e7ff; }
			.psm-nav-arrow:disabled { opacity: .4; cursor: not-allowed; }
			.psm-nav-arrow .dashicons { font-size: 16px; width: 16px; height: 16px; }
			.psm-tag-picker { display: flex; gap: 8px; align-items: center; }
			.psm-tag-picker input { flex: 1 1 auto; min-width: 0; }
			.psm-tag-picker .psm-btn { flex: 0 0 auto; margin-top: 4px; }
			.psm-selected-tags, .psm-available-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
			.psm-available-tags { max-height: 120px; overflow-y: auto; padding: 7px; border: 1px dashed #cbd5e1; border-radius: 8px; background: #f8fafc; }
			.psm-tag-chip {
				display: inline-flex; align-items: center; gap: 5px; padding: 4px 7px; border-radius: 999px;
				background: #ede9fe; color: #5b21b6; border: 1px solid #c4b5fd; font-size: 11px; font-weight: 700;
			}
			.psm-tag-chip button { border: 0; background: transparent; color: inherit; cursor: pointer; font-size: 15px; line-height: 1; padding: 0; }
			.psm-tag-option {
				border: 1px solid #bae6fd; border-radius: 999px; background: #f0f9ff; color: #075985;
				padding: 4px 8px; font-size: 11px; cursor: pointer;
			}
			.psm-tag-option:hover { background: #e0f2fe; border-color: #7dd3fc; }
			.psm-wrap .psm-modal-body input[type="text"], .psm-wrap .psm-modal-body input[type="number"], .psm-wrap .psm-modal-body select, .psm-wrap .psm-modal-body textarea {
				background: #fbfdff; border-color: #bfdbfe; box-shadow: inset 0 1px 2px rgba(15,23,42,0.04); transition: border-color .15s, box-shadow .15s;
			}
			.psm-wrap .psm-modal-body input[type="text"]:focus, .psm-wrap .psm-modal-body input[type="number"]:focus, .psm-wrap .psm-modal-body select:focus, .psm-wrap .psm-modal-body textarea:focus {
				border-color: #818cf8; box-shadow: 0 0 0 3px rgba(99,102,241,0.14); outline: 0;
			}
			.psm-wrap .psm-modal.psm-json-import-modal { max-width: 780px; }
			.psm-json-import-modal .form-table th { width: 170px; padding: 12px 10px 12px 0; color: #334155; }
			.psm-json-import-modal .form-table td { padding: 12px 0; }
			.psm-json-import-modal select, .psm-json-import-modal input[type="text"], .psm-json-import-modal input[type="file"] {
				width: 100%; max-width: 100%; min-height: 40px; border: 1px solid #bfdbfe; border-radius: 8px;
				background: #fbfdff; padding: 8px 10px; box-sizing: border-box;
			}
			.psm-json-import-modal input[type="file"]::file-selector-button {
				border: 0; border-radius: 6px; background: #e0e7ff; color: #3730a3; padding: 7px 10px; margin-right: 10px; font-weight: 700; cursor: pointer;
			}
			.psm-wrap .psm-modal.psm-confirm-modal { max-width: 460px; }
			.psm-confirm-modal .psm-modal-body p { margin: 0; color: #475569; line-height: 1.6; }
			.psm-confirm-danger { background: #dc2626; color: #fff; border-color: #dc2626; }
			.psm-json-import-modal textarea {
				width: 100%; min-height: 220px; border: 1px solid #bfdbfe; border-radius: 8px;
				background: #fbfdff; padding: 10px; box-sizing: border-box;
				font-family: Consolas, Monaco, monospace; font-size: 12px; line-height: 1.5;
			}
			.psm-json-import-modal .psm-modal-footer { flex-wrap: wrap; }
			[data-tooltip] { position: relative; }
			[data-tooltip]::after {
				content: attr(data-tooltip); position: absolute; left: 50%; top: calc(100% + 7px); transform: translateX(-50%) translateY(-3px);
				z-index: 100120; width: max-content; max-width: 240px; padding: 6px 9px; border-radius: 6px;
				background: #0f172a; color: #fff; font-size: 11px; font-weight: 500; line-height: 1.4; text-align: center;
				opacity: 0; visibility: hidden; pointer-events: none; transition: opacity .12s, transform .12s; box-shadow: 0 4px 12px rgba(15,23,42,0.2);
			}
			[data-tooltip]:hover::after, [data-tooltip]:focus-visible::after { opacity: 1; visibility: visible; transform: translateX(-50%) translateY(0); }
			@media (max-width: 1100px) {
				.psm-filter-bar { grid-template-columns: repeat(2, minmax(0, 1fr)); }
				.psm-filter-actions { align-self: end; }
			}
			@media (max-width: 640px) {
				.psm-filter-bar { grid-template-columns: 1fr; }
				.psm-pagination { align-items: flex-start; flex-direction: column; }
				.psm-tag-picker { align-items: stretch; flex-direction: column; }
				.psm-tag-picker .psm-btn { margin-top: 0; }
			}
		</style>

		<div class="wrap psm-wrap">
			<div class="psm-header-banner">
				<h1>PSM AI Question Generator &amp; Importer</h1>
				<span class="psm-ver">v<?php echo esc_html( PSM_AI_VERSION ); ?></span>
			</div>

			<nav class="nav-tab-wrapper">
				<a href="?page=psm-ai-importer&tab=generate" class="nav-tab <?php echo $tab === 'generate' ? 'nav-tab-active' : ''; ?>">1. Prompt, Preview &amp; Import</a>
				<a href="?page=psm-ai-importer&tab=masters&entity=<?php echo esc_attr( $entity ); ?>" class="nav-tab <?php echo $tab === 'masters' ? 'nav-tab-active' : ''; ?>">2. Master Tables</a>
				<a href="?page=psm-ai-importer&tab=batches" class="nav-tab <?php echo $tab === 'batches' ? 'nav-tab-active' : ''; ?>">3. Batches &amp; Label Download</a>
				<a href="?page=psm-ai-importer&tab=settings" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">4. AI Providers</a>
			</nav>

			<div id="psm_toast" class="psm-toast" role="status" aria-live="polite"></div>

			<?php if ( $notice ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
			<?php endif; ?>

			<?php if ( $tab === 'settings' ) : ?>
				<!-- TAB 4: THREE SEPARATE AJAX FORMS FOR GEMINI, OPENAI, AND CUSTOM -->
				<div class="psm-ai-grid">
					<?php
					$provider_links = array(
						'gemini' => '<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;font-size:12px;">Get Key &rarr;</a>',
						'openai' => '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;font-size:12px;">Get Key &rarr;</a>',
						'custom' => '<span style="font-size:12px;opacity:0.9;">REST JSON API</span>',
					);
					foreach ( $providers_list as $pkey => $plabel ) :
						$pcfg   = PSM_API_Client::get_provider_config( $pkey );
						$pusage = PSM_API_Client::get_usage_status( $pkey );
						$is_def = ( $active_provider === $pkey );
						?>
						<div class="psm-ai-card psm-ai-<?php echo esc_attr( $pkey ); ?>">
							<div class="psm-ai-card-head">
								<span><?php echo esc_html( $plabel ); ?> <?php echo $is_def ? '<small style="background:rgba(255,255,255,0.25);padding:2px 7px;border-radius:10px;margin-left:5px;">DEFAULT</small>' : ''; ?></span>
								<?php echo $provider_links[ $pkey ]; ?>
							</div>
							<div class="psm-ai-card-body">
								<div class="psm-quota-box" id="psm_quota_box_<?php echo esc_attr( $pkey ); ?>">
									<strong>Daily Usage:</strong> <?php echo esc_html( $pusage['daily_used'] . ' / ' . $pusage['daily_limit'] ); ?> (Left: <?php echo esc_html( $pusage['daily_remaining'] ); ?>)<br>
									<strong>Next Daily Reset:</strong> <?php echo esc_html( $pusage['next_daily_reset'] ); ?><br>
									<strong>Weekly Usage:</strong> <?php echo esc_html( $pusage['weekly_used'] . ' / ' . $pusage['weekly_limit'] ); ?> (Left: <?php echo esc_html( $pusage['weekly_remaining'] ); ?>)<br>
									<strong>Next Weekly Reset:</strong> <?php echo esc_html( $pusage['next_weekly_reset'] ); ?>
								</div>

								<form class="psm-ajax-ai-form" data-provider="<?php echo esc_attr( $pkey ); ?>">
									<input type="hidden" name="provider" value="<?php echo esc_attr( $pkey ); ?>">
									<p>
										<label>API Key / Bearer Token</label>
										<?php $has_key = '' !== (string) $pcfg['api_key']; ?>
										<input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_key ? 'A key is saved - leave blank to keep it' : 'Enter ' . $plabel . ' key' ); ?>">
										<?php if ( $has_key ) : ?>
											<label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-weight:500;">
												<input type="checkbox" name="clear_api_key" value="1" style="width:auto;"> Remove the saved key
											</label>
										<?php endif; ?>
										<span style="display:block;font-size:11px;color:#64748b;margin-top:3px;">For security the saved key is never sent back to the browser, so this field clears after saving. Leave it blank to keep the saved key.</span>
									</p>
									<p>
										<label>Model Name</label>
										<input type="text" name="model" value="<?php echo esc_attr( $pcfg['model'] ); ?>">
									</p>
									<p>
										<label>Custom Endpoint URL (Optional)</label>
										<input type="url" name="endpoint_url" value="<?php echo esc_attr( $pcfg['endpoint_url'] ); ?>" placeholder="Leave blank for default endpoint">
									</p>
									<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
										<div>
											<label>Daily Limit</label>
											<input type="number" name="daily_limit" value="<?php echo esc_attr( $pcfg['daily_limit'] ); ?>" min="1">
										</div>
										<div>
											<label>Weekly Limit</label>
											<input type="number" name="weekly_limit" value="<?php echo esc_attr( $pcfg['weekly_limit'] ); ?>" min="1">
										</div>
									</div>
									<p>
										<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
											<input type="checkbox" name="set_as_default" value="1" <?php checked( $is_def ); ?>> Set as Default AI Provider
										</label>
									</p>
									<button type="submit" class="button button-primary" style="width:100%;">Save <?php echo esc_html( $plabel ); ?> Settings (AJAX)</button>
									<div class="psm-save-status" id="psm_save_status_<?php echo esc_attr( $pkey ); ?>" aria-live="polite"></div>
								</form>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

			<?php elseif ( $tab === 'batches' ) : ?>
				<!-- TAB 3: BATCHES & LABEL DOWNLOAD -->
				<div class="psm-table-card" style="margin-top: 20px;">
					<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
						<h2 style="margin:0;">Import Batches &amp; Question Relations</h2>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psm_download_labels' ), 'psm_download_labels_nonce' ) ); ?>" class="psm-btn-add">
							<span class="dashicons dashicons-download"></span> Download All Recent Labels (.csv)
						</a>
					</div>
					<table class="widefat striped">
						<thead>
							<tr>
								<th>ID</th>
								<th>Batch Code</th>
								<th>Label Name</th>
								<th>Skill</th>
								<th>AI / Source</th>
								<th>Imported / Linked</th>
								<th>Skipped</th>
								<th>Created</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $batches ) ) : ?>
								<tr><td colspan="9">No batches recorded yet.</td></tr>
							<?php else : foreach ( $batches as $b ) : ?>
								<tr id="psm-row-batch-<?php echo esc_attr( $b->id ); ?>">
									<td><?php echo esc_html( $b->id ); ?></td>
									<td><code><?php echo esc_html( $b->batch_code ); ?></code></td>
									<td><strong><?php echo esc_html( $b->label_name ); ?></strong></td>
									<td><span class="psm-badge psm-badge-type"><?php echo esc_html( $b->skill_name ?: '—' ); ?></span></td>
									<td><?php echo esc_html( $b->provider . ( $b->model ? ' (' . $b->model . ')' : '' ) ); ?></td>
									<td><?php echo esc_html( $b->total_imported . ' / ' . $b->linked_questions_count ); ?></td>
									<td><?php echo esc_html( $b->total_skipped ); ?></td>
									<td><?php echo esc_html( $this->local_time( $b->created_at ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psm_download_labels&batch_id=' . $b->id ), 'psm_download_labels_nonce' ) ); ?>" class="psm-icon-btn" style="background:#dcfce7;color:#15803d;" title="Download Label File">
											<span class="dashicons dashicons-download"></span>
										</a>
										<button type="button" class="psm-icon-btn psm-btn-edit psm-open-modal" title="Edit Batch Label"
											data-entity="batch"
											data-record='<?php echo esc_attr( wp_json_encode( $b ) ); ?>'>
											<span class="dashicons dashicons-edit"></span>
										</button>
										<button type="button" class="psm-icon-btn psm-btn-delete psm-ajax-delete" title="Delete Batch"
											data-entity="batch"
											data-id="<?php echo esc_attr( $b->id ); ?>"
											data-name="<?php echo esc_attr( $b->batch_code ); ?>">
											<span class="dashicons dashicons-trash"></span>
										</button>
									</td>
								</tr>
							<?php endforeach; endif; ?>
						</tbody>
					</table>
				</div>

			<?php elseif ( $tab === 'masters' ) : ?>
				<div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin: 16px 0;">
					<div class="psm-pill-nav" style="margin:0;">
						<?php
						$sub_entities = array(
							'domain'        => 'Domains (' . $master_counts['domain'] . ')',
							'skill_type'    => 'Skill Types (' . $master_counts['skill_type'] . ')',
							'question_type' => 'Question Types (' . $master_counts['question_type'] . ')',
							'difficulty'    => 'Difficulties (' . $master_counts['difficulty'] . ')',
							'skill'         => 'Skills (' . $master_counts['skill'] . ')',
							'tag'           => 'Tags (' . $master_counts['tag'] . ')',
							'question'      => 'Questions (' . $master_counts['question'] . ')',
						);
						foreach ( $sub_entities as $ek => $elabel ) :
							$cls = ( $entity === $ek ) ? 'psm-pill active' : 'psm-pill';
							?>
							<a href="?page=psm-ai-importer&tab=masters&entity=<?php echo esc_attr( $ek ); ?>" class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $elabel ); ?></a>
						<?php endforeach; ?>
					</div>

					<div style="display:flex; gap:8px; align-items:center;">
						<button type="button" class="psm-btn-add psm-open-modal" data-entity="<?php echo esc_attr( $entity ); ?>" data-record=""
							data-tooltip="Open the add form" title="Open the add form">
							<span class="dashicons dashicons-plus-alt2" aria-hidden="true"></span> Add New <?php echo esc_html( ucwords( str_replace( '_', ' ', $entity ) ) ); ?>
						</button>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin:0;" id="psm_seeder_form"
							data-confirm-title="Run idempotent seed?"
							data-confirm-message="This adds missing default domains, skills, and tags. Existing records are preserved."
							data-confirm-label="Run Seed">
							<?php wp_nonce_field( 'psm_seeder_nonce' ); ?>
							<input type="hidden" name="action" value="psm_run_seeder">
							<button type="submit" class="psm-btn psm-btn-secondary"
								data-tooltip="Add missing default domains, skills, and tags without changing existing records"
								title="Add missing default records; existing records are preserved">Run Idempotent Seed</button>
						</form>
					</div>
				</div>

				<div class="psm-table-card">
					<?php echo $this->render_master_list_html( $master_list_data ); ?>
				</div>

			<?php else : ?>
				<!-- TAB 1: PROMPT, PREVIEW & IMPORT -->
				<?php
				$preview_data = $is_prev ? get_transient( 'psm_preview_data_' . $user_id ) : false;
				if ( $is_prev && ! empty( $preview_data ) && ! empty( $preview_data['questions'] ) ) :
    $inspected_rows = array();
    $valid_count    = 0;
    foreach ( $preview_data['questions'] as $p_idx => $p_q ) {
        $inspected_rows[ $p_idx ] = PSM_Importer::inspect_question(
            $p_q,
            $preview_data['default_status'] ?? 'draft',
            $preview_data['default_difficulty'] ?? 1
        );
        if ( empty( $inspected_rows[ $p_idx ]['errors'] ) ) {
            $valid_count++;
        }
    }
    $total_rows = count( $preview_data['questions'] );

    $preview_tag_slugs = array();
    $preview_existing_tag_slugs = array();
    if ( ! empty( $preview_data['skill_id'] ) ) {
        $preview_tag_rows = $wpdb->get_results( "SELECT slug, skill_id, status FROM {$wpdb->prefix}psm_tags" );
        foreach ( (array) $preview_tag_rows as $preview_tag_row ) {
            $preview_slug = (string) $preview_tag_row->slug;
            $preview_existing_tag_slugs[ $preview_slug ] = true;
            if ( (int) $preview_tag_row->skill_id === (int) $preview_data['skill_id'] && 'active' === $preview_tag_row->status ) {
                $preview_tag_slugs[ $preview_slug ] = true;
            }
        }
    }
    $preview_new_tags = array();
    foreach ( $preview_data['questions'] as $p_q ) {
        foreach ( (array) ( $p_q['tags'] ?? array() ) as $tslug ) {
            $tslug = (string) $tslug;
            if ( '' !== $tslug && ! isset( $preview_tag_slugs[ $tslug ] ) && ! isset( $preview_existing_tag_slugs[ $tslug ] ) ) {
                $preview_new_tags[] = $tslug;
            }
        }
    }
    $preview_new_tags = array_values( array_unique( $preview_new_tags ) );
					?>
					<div class="psm-table-card" style="margin-top: 20px;">
						<h2>Review &amp; Check Generated Data Before Database Import</h2>
						<p>
							<strong>Skill:</strong> <?php echo esc_html( $preview_data['skill_name'] ); ?> |
							<strong>Label:</strong> <?php echo esc_html( $preview_data['label_name'] ); ?> |
							<strong>Source:</strong> <?php echo esc_html( $preview_data['provider'] ); ?> |
								<?php if ( ! empty( $preview_data['focus_tag_slug'] ) ) : ?>
									<strong>Focus Tag:</strong> <code><?php echo esc_html( $preview_data['focus_tag_slug'] ); ?></code> |
								<?php endif; ?>
								<strong>Validated:</strong>
								<span style="color:<?php echo $valid_count === $total_rows ? '#166534' : '#b45309'; ?>;font-weight:700;">
									<?php echo esc_html( $valid_count . ' / ' . $total_rows . ' rows importable' ); ?>
								</span>
								<?php if ( ! empty( $preview_new_tags ) ) : ?>
									<br><strong style="color:#b45309;">New tags (auto-created for this skill on import):</strong>
									<?php foreach ( $preview_new_tags as $new_tslug ) : ?><code><?php echo esc_html( $new_tslug ); ?></code> <?php endforeach; ?>
								<?php endif; ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'psm_confirm_import_nonce' ); ?>
							<input type="hidden" name="action" value="psm_confirm_import">

							<table class="widefat striped" style="margin-bottom: 15px;">
								<thead>
									<tr>
										<th style="width:36px;"><input type="checkbox" id="psm_select_all_rows" checked></th>
										<th>#</th>
										<th>Question</th>
										<th>Options &amp; Correct</th>
										<th>Difficulty / Type Slug</th>
										<th>Tags</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $preview_data['questions'] as $idx => $q ) :
    $inspect    = $inspected_rows[ $idx ] ?? array( 'data' => null, 'errors' => array( 'Row was not validated.' ) );
    $row_errors = $inspect['errors'];
    $row_valid  = empty( $row_errors );
    $norm       = $row_valid ? $inspect['data'] : null;
    $q_type     = $row_valid ? $norm['question_type'] : ( $q['question_type'] ?? 'four_option' );
    $q_diff     = $row_valid ? $norm['difficulty'] : ( $q['difficulty'] ?? 1 );
										$q_stem    = $row_valid ? $norm['question_text'] : ( $q['question_text'] ?? ( $q['question'] ?? '' ) );
										$q_opts    = $row_valid ? $norm['options'] : ( $q['options'] ?? array() );
										$q_correct = $row_valid ? $norm['correct_keys'] : ( $q['correct_keys'] ?? ( $q['correct_answers'] ?? array() ) );
										$q_lang    = $row_valid ? $norm['code_language'] : ( $q['code_language'] ?? '' );
										?>
										<tr>
											<td><input type="checkbox" class="psm-row-cb" name="selected_rows[]" value="<?php echo esc_attr( $idx ); ?>" <?php echo $row_valid ? 'checked' : 'disabled title="This row has validation errors and will be skipped."'; ?>></td>
											<td><?php echo esc_html( $idx + 1 ); ?></td>
											<td>
												<?php if ( ! $row_valid ) : ?>
<div style="background:#fee2e2;border-left:4px solid #dc2626;padding:6px 8px;margin-bottom:6px;border-radius:4px;">
<strong>Will be skipped:</strong> <?php echo esc_html( implode( ' ', $row_errors ) ); ?>
</div>
<?php endif; ?>
<strong><?php echo esc_html( $q_stem ); ?></strong>
												<?php if ( ! empty( $q['code_snippet'] ) ) : ?>
													<div style="font-size:11px; color:#334155; margin-top:4px;">
														<strong>Language:</strong> <code><?php echo esc_html( $q_lang ?: 'plain' ); ?></code>
													</div>
													<pre style="background:#f6f7f7; padding:6px; margin:4px 0 6px; overflow:auto;"><?php echo esc_html( $q['code_snippet'] ); ?></pre>
												<?php endif; ?>
												<?php if ( ! empty( $q['explanation'] ) ) : ?>
													<div style="color:#50575e; font-size:12px;"><em>Explanation: <?php echo $row_valid ? wp_kses_post( $norm['explanation'] ) : esc_html( $q['explanation'] ?? '' ); ?></em></div>
												<?php endif; ?>
											</td>
											<td>
												<?php foreach ( (array) $q_opts as $ok => $ov ) :
													if ( is_array( $ov ) && isset( $ov['key'] ) ) {
														$disp_k = $ov['key'];
														$disp_v = $ov['text'] ?? '';
													} else {
														$disp_k = $ok;
														$disp_v = $ov;
													}
													?>
													<div><code><?php echo esc_html( $disp_k ); ?></code>: <?php echo esc_html( $disp_v ); ?></div>
												<?php endforeach; ?>
												<div style="margin-top:4px;"><strong>Correct:</strong> <code><?php echo esc_html( implode( ', ', (array) $q_correct ) ); ?></code></div>
											</td>
											<td>Diff: <?php echo esc_html( $q_diff ); ?><br><code><?php echo esc_html( $q_type ); ?></code></td>
											<td>
												<?php foreach ( (array) ( $q['tags'] ?? array() ) as $tslug ) : ?>
													<div><code><?php echo esc_html( $tslug ); ?></code><?php if ( ! isset( $preview_tag_slugs[ (string) $tslug ] ) ) : ?> <span style="color:#b45309;font-weight:600;"><?php echo isset( $preview_existing_tag_slugs[ (string) $tslug ] ) ? '(tag unavailable for this skill)' : '(new tag - will be created on import)'; ?></span><?php endif; ?></div>
												<?php endforeach; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<?php submit_button( 'Confirm & Import Checked Rows to Custom Database', 'primary', 'confirm_import', false ); ?>
							<button type="submit" name="cancel_preview" value="1" class="button button-secondary" style="margin-left:8px;">Discard Preview</button>
						</form>
					</div>
				<?php else : ?>
					<div class="psm-table-card" style="max-width: 1040px; margin-top: 20px;">
						<div style="background:#eff6ff; border-left:4px solid #2563eb; padding:10px 14px; margin-bottom:16px; border-radius:4px;">
							<strong><?php echo esc_html( $usage_status['provider_label'] ); ?> Quota:</strong>
							Daily <?php echo esc_html( $usage_status['daily_used'] . '/' . $usage_status['daily_limit'] ); ?> (Reset: <?php echo esc_html( $usage_status['next_daily_reset'] ); ?>) |
							Weekly <?php echo esc_html( $usage_status['weekly_used'] . '/' . $usage_status['weekly_limit'] ); ?> (Reset: <?php echo esc_html( $usage_status['next_weekly_reset'] ); ?>)
							<?php if ( $usage_status['is_exhausted'] ) : ?>
								<span style="color:#dc2626; font-weight:bold;"> [LIMIT FINISHED - Switch AI in dropdown below]</span>
							<?php endif; ?>
						</div>

						<form id="psm_generate_form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'psm_generate_import_nonce' ); ?>
							<input type="hidden" name="action" value="psm_fetch_preview">

							<table class="form-table">
								<tr>
									<th><label for="ai_provider_select">AI Provider (Switch if Limit Finished)</label></th>
									<td>
										<select name="ai_provider_select" id="ai_provider_select" class="psm-full-width-control">
											<?php foreach ( $providers_list as $pkey => $plabel ) :
												$pu = PSM_API_Client::get_usage_status( $pkey );
												?>
												<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $active_provider, $pkey ); ?>>
													<?php echo esc_html( $plabel . ' (Daily left: ' . $pu['daily_remaining'] . ', Weekly left: ' . $pu['weekly_remaining'] . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>

								<tr>
									<th><label for="psm_skill_select">1. Select Skill *</label></th>
									<td>
										<select name="skill_id" id="psm_skill_select" class="regular-text" required>
											<option value="">&mdash; Select Skill &mdash;</option>
											<?php
											$grouped = array();
											foreach ( $skills as $s ) {
												$grouped[ $s->domain_name ?: 'Other' ][] = $s;
											}
											foreach ( $grouped as $dom_name => $dom_skills ) :
												?>
												<optgroup label="<?php echo esc_attr( $dom_name ); ?>">
													<?php foreach ( $dom_skills as $s ) : ?>
														<option value="<?php echo esc_attr( $s->id ); ?>" data-name="<?php echo esc_attr( $s->name ); ?>" data-slug="<?php echo esc_attr( $s->slug ); ?>">
															<?php echo esc_html( $s->name . ' [' . $s->slug . '] (' . ( $s->master_type_name ?: $s->skill_type ) . ')' ); ?>
														</option>
													<?php endforeach; ?>
												</optgroup>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>

								<tr>
									<th><label>2. Select Tags (Skill-Filtered)</label></th>
									<td>
										<div id="psm_tag_box" style="max-height: 180px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px; background: #f9f9f9; border-radius:6px;">
											<em>Select a Skill above to load its valid tags.</em>
										</div>
									</td>
								</tr>

								<tr>
									<th><label for="label_name">3. Batch Label Name</label></th>
									<td><input type="text" name="label_name" id="label_name" class="regular-text" placeholder="e.g. C# LINQ Set 01"></td>
								</tr>

								<tr>
									<th><label>4. Difficulty (ID), Question Type (Slug) &amp; Count</label></th>
									<td>
										<div class="psm-break-box">
											<div class="psm-break-item">
												<label for="difficulty"><strong>Difficulty (ID)</strong></label>
												<select name="difficulty" id="difficulty" class="psm-full-width-control">
													<option value="all">All Levels (beginner, intermediate, advanced)</option>
													<?php foreach ( $difficulties as $df ) : ?>
														<?php if ( (int) $df->id > 3 ) { continue; } ?>
														<option value="<?php echo esc_attr( $df->id ); ?>" data-slug="<?php echo esc_attr( $df->slug ); ?>">
															<?php echo esc_html( $df->id . ' - ' . $df->name . ' (' . $df->slug . ')' ); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<p class="description" style="margin:4px 0 0;">The practice app only supports difficulties 1-3.</p>
											</div>

											<div class="psm-break-item">
												<div class="psm-qtype-header">
													<strong>Question Type (Slug)</strong>
													<button type="button" class="button button-small" id="psm_select_all_qtypes">Select All</button>
												</div>
												<div class="psm-qtype-checks" id="psm_qtype_checks">
													<?php foreach ( $generation_qtypes as $qt_slug => $qt_label ) : ?>
														<label title="<?php echo esc_attr( $qt_slug ); ?>">
															<input type="checkbox" name="question_types[]" class="psm-qtype-cb" value="<?php echo esc_attr( $qt_slug ); ?>" checked>
															<code><?php echo esc_html( $qt_slug ); ?></code>
														</label>
													<?php endforeach; ?>
												</div>
												<p class="description" style="margin:4px 0 0;">single/multiple choice are both stored as <code>four_option</code>, with <code>is_multiple</code> recording two or more correct answers.</p>
											</div>

											<div class="psm-break-item">
												<label for="question_count"><strong>Count</strong></label>
												<input type="number" name="question_count" id="question_count" value="50" min="1" max="100" class="psm-full-width-control">
											</div>

											<div class="psm-break-item">
												<label for="default_status"><strong>Status</strong></label>
												<select name="default_status" id="default_status" class="psm-full-width-control">
													<option value="draft">Draft</option>
													<option value="published">Published</option>
												</select>
											</div>
										</div>
									</td>
								</tr>

								<tr>
									<th><label for="psm_code_language_master">Code Language (Master for Code Snippet - Optional)</label></th>
									<td>
										<select name="code_language_master" id="psm_code_language_master" class="regular-text">
											<?php foreach ( $languages as $lang_slug => $lang_name ) : ?>
												<option value="<?php echo esc_attr( $lang_slug ); ?>"><?php echo esc_html( $lang_name ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description">Preferred language for code snippets. The AI still chooses the best language per question; this is passed to the prompt.</p>
									</td>
								</tr>

								<tr>
									<th><label for="generation_rules">5. Current Skill &amp; Prompt Rules</label></th>
									<td>
										<textarea name="generation_rules" id="generation_rules" rows="4" class="large-text">1. Every question must be completely unique-never repeat scenarios, variable names, or reworded versions of existing questions.
2. Distractors (wrong options) must be plausible technical misconceptions, not obvious throwaways.
3. Provide a clear, concise explanation for why the correct key is accurate.
4. Assign only valid tag slugs belonging strictly to the chosen skill.</textarea>
									</td>
								</tr>

								<tr>
									<th><label for="user_prompt">6. Custom Focus Instructions (Optional)</label></th>
									<td>
										<textarea name="user_prompt" id="user_prompt" rows="6" class="large-text code" placeholder="Optional: narrow the topics, e.g. &quot;focus on async/await and exception filters&quot;. Leave blank to use the full default prompt."></textarea>
										<details style="margin-top:8px;">
											<summary style="cursor:pointer;font-weight:600;">Show the default prompt template</summary>
											<pre style="background:#f6f7f7;padding:10px;max-height:320px;overflow:auto;white-space:pre-wrap;font-size:12px;"><?php echo esc_html( $initial_prompt_text ); ?></pre>
											<p class="description">Preview for the "<?php echo esc_html( $initial_skill_name ); ?>" skill with 50 questions across all levels and types. The real skill, tags, difficulty, count and types are substituted when you fetch.</p>
										</details>
									</td>
								</tr>

								</table>

							<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
								<?php submit_button( 'Fetch & Show Data for Check Before Import', 'primary', 'submit', false ); ?>
								<button type="button" class="button button-secondary" id="psm_create_prompt_btn">Create Prompt (Copy / Download)</button>
								<button type="button" class="psm-btn psm-btn-secondary" id="psm_import_json_jump" data-tooltip="Paste JSON or preview a saved JSON file" title="Paste JSON or preview a saved JSON file">
								<span class="dashicons dashicons-upload" aria-hidden="true"></span> Import JSON
							</button>
								<div id="psm_ai_loader" style="display:none;align-items:center;gap:8px;padding:6px 12px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;color:#1e3a8a;font-weight:600;">
									<span class="spinner is-active" style="float:none;margin:0;"></span>
									<span>Generating questions via AI...</span>
								</div>
							</div>
							<div id="psm_ai_ajax_notice" style="margin-top:10px;"></div>
						</form>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $tab === 'generate' && ! $is_prev ) : ?>
						<div class="psm-modal-backdrop" id="psm_import_json_modal" role="dialog" aria-modal="true" aria-labelledby="psm_import_json_title">
				<div class="psm-modal psm-json-import-modal">
					<div class="psm-modal-header">
						<h3 id="psm_import_json_title">Import JSON</h3>
						<button type="button" class="psm-modal-close" id="psm_import_json_close" aria-label="Close JSON import">&times;</button>
					</div>
					<div class="psm-modal-body">
						<p class="description" style="margin-top:0;">
							Paste AI-generated JSON or choose a saved <code>.json</code> file. Both actions validate the data and open the preview screen;
							no questions, tags, or batches are written until you confirm the import. A batch label is created automatically.
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="psm_json_import_form">
							<?php wp_nonce_field( 'psm_import_json_file_nonce' ); ?>
							<input type="hidden" name="action" value="psm_import_json_file">
							<table class="form-table">
								<tr>
									<th><label for="psm_json_skill_select">Target Skill *</label></th>
									<td>
										<select name="skill_id" id="psm_json_skill_select" required>
											<option value="">&mdash; Select Skill &mdash;</option>
										<?php
										$json_grouped = array();
										foreach ( $skills as $s ) {
											$json_grouped[ $s->domain_name ?: 'Other' ][] = $s;
										}
										foreach ( $json_grouped as $dom_name => $dom_skills ) :
											?>
											<optgroup label="<?php echo esc_attr( $dom_name ); ?>">
												<?php foreach ( $dom_skills as $s ) : ?>
													<option value="<?php echo esc_attr( $s->id ); ?>" data-slug="<?php echo esc_attr( $s->slug ); ?>">
														<?php echo esc_html( $s->name . ' [' . $s->slug . ']' ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
							</td>
							</tr>
								<tr>
									<th><label for="psm_json_focus_tag">Tag Focus (Optional)</label></th>
									<td>
										<select name="focus_tag_id" id="psm_json_focus_tag">
											<option value="">&mdash; No tag focus &mdash;</option>
										</select>
										<p class="description">When set, every question must include this tag slug or the whole payload is rejected.</p>
								</td>
							</tr>
								<tr>
									<th><label for="psm_json_status">Import Status</label></th>
									<td>
										<select name="default_status" id="psm_json_status">
											<option value="draft">Draft</option>
											<option value="published">Published</option>
										</select>
								</td>
							</tr>
								<tr>
									<th><label for="psm_json_payload">Pasted JSON</label></th>
									<td>
										<textarea name="manual_json_payload" id="psm_json_payload" rows="12" maxlength="5242880" placeholder='Paste {"questions": [...]} or a complete schema_version 1.0 envelope here.'></textarea>
										<p class="description">Maximum 5 MB. Use <strong>Import Pasted JSON</strong> to validate this text and open the preview.</p>
									</td>
							</tr>
								<tr>
									<th><label for="psm_json_file">JSON File (Optional)</label></th>
									<td>
										<input type="file" name="json_file" id="psm_json_file" accept=".json,application/json">
										<p class="description">Maximum 5 MB. Choose <strong>Preview</strong> to validate the selected file and open the preview.</p>
									</td>
							</tr>
							</table>
							<div class="psm-modal-footer">
								<button type="button" class="psm-btn psm-btn-secondary" id="psm_import_json_cancel">Cancel</button>
								<button type="submit" class="psm-btn psm-btn-secondary" name="import_source" value="file" id="psm_json_preview"
									data-tooltip="Validate the selected JSON file and open the preview">Preview</button>
								<button type="submit" class="psm-btn psm-btn-primary" name="import_source" value="paste" id="psm_import_pasted_json"
									data-tooltip="Validate the pasted JSON and open the preview">Import Pasted JSON</button>
							</div>
						</form>
					</div>
				</div>
			</div>
		<?php endif; ?>

			<!-- PROMPT BUILDER MODAL (Copy / Download) -->
			<div class="psm-modal-backdrop" id="psm_prompt_modal" role="dialog" aria-modal="true">
				<div class="psm-modal" style="max-width: 900px;">
					<div class="psm-modal-header">
						<h3>AI Prompt (Copy or Download)</h3>
						<button type="button" class="psm-modal-close" id="psm_prompt_close" aria-label="Close">&times;</button>
					</div>
					<div class="psm-modal-body">
						<p class="description" style="margin-top:0;">
							Paste this into ChatGPT, Gemini, or Qoder. The model must return only raw JSON that matches the plugin schema,
							so you can save it as a <code>.json</code> file and import it with the Import JSON popup.
						</p>
						<div id="psm_prompt_status" style="margin:8px 0;color:#64748b;font-size:12px;"></div>
						<textarea id="psm_prompt_textarea" rows="18" readonly style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12px;white-space:pre;overflow:auto;"></textarea>
					</div>
					<div class="psm-modal-footer">
						<button type="button" class="button button-secondary" id="psm_prompt_close_footer">Close</button>
						<button type="button" class="button" id="psm_prompt_download" style="margin-left:8px;">Download .txt</button>
						<button type="button" class="button button-primary" id="psm_prompt_copy" style="margin-left:8px;">Copy Prompt</button>
					</div>
				</div>
			</div>

			<div class="psm-modal-backdrop" id="psm_confirm_modal" role="dialog" aria-modal="true" aria-labelledby="psm_confirm_title">
				<div class="psm-modal psm-confirm-modal">
					<div class="psm-modal-header">
						<h3 id="psm_confirm_title">Please confirm</h3>
						<button type="button" class="psm-modal-close" id="psm_confirm_close" aria-label="Close confirmation">&times;</button>
					</div>
					<div class="psm-modal-body"><p id="psm_confirm_message"></p></div>
					<div class="psm-modal-footer">
						<button type="button" class="psm-btn psm-btn-secondary" id="psm_confirm_cancel">Cancel</button>
						<button type="button" class="psm-btn psm-confirm-danger" id="psm_confirm_accept">Confirm</button>
					</div>
				</div>
			</div>

			<!-- AJAX MODAL POPUP FOR ADD / EDIT MASTER RECORDS (Centered H+V, ESC to Close) -->
			<div class="psm-modal-backdrop" id="psm_master_modal" role="dialog" aria-modal="true">
				<div class="psm-modal">
					<div class="psm-modal-header">
						<div class="psm-modal-title-wrap">
							<h3 id="psm_modal_title">Add / Edit Record</h3>
							<div class="psm-question-edit-nav" id="psm_question_edit_nav" style="display:none;">
								<button type="button" id="psm_question_previous" class="psm-nav-arrow" aria-label="Edit previous question" title="Previous question" data-tooltip="Previous question">
									<span class="dashicons dashicons-arrow-left-alt" aria-hidden="true"></span>
								</button>
								<span id="psm_question_position">Question</span>
								<button type="button" id="psm_question_next" class="psm-nav-arrow" aria-label="Edit next question" title="Next question" data-tooltip="Next question">
									<span class="dashicons dashicons-arrow-right-alt" aria-hidden="true"></span>
								</button>
							</div>
						</div>
						<button type="button" class="psm-modal-close" id="psm_modal_close" aria-label="Close">&times;</button>
					</div>
					<form id="psm_modal_form">
						<div class="psm-modal-body">
							<input type="hidden" name="entity" id="psm_m_entity">
							<input type="hidden" name="record_id" id="psm_m_record_id" value="0">

							<div id="psm_m_batch_fields" style="display:none;">
								<label>Batch Label Name
									<input type="text" name="label_name" id="psm_m_label_name">
								</label>
							</div>

							<div id="psm_m_question_fields" style="display:none;">
								<div class="psm-question-grid">
									<label>Skill *
										<select name="skill_id" id="psm_m_question_skill_id" required>
											<option value="">Select Skill</option>
											<?php foreach ( $skills as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>"><?php echo esc_html( $s->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Question Type *
										<select name="question_type" id="psm_m_question_type" required>
											<?php foreach ( $question_type_labels as $type_slug => $type_label ) : ?>
												<option value="<?php echo esc_attr( $type_slug ); ?>"><?php echo esc_html( $type_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Difficulty *
										<select name="difficulty" id="psm_m_question_difficulty" required>
											<?php foreach ( $difficulties as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Question Status *
										<select name="question_status" id="psm_m_question_status">
											<option value="draft">Draft</option>
											<option value="published">Published</option>
										</select>
									</label>
								</div>

								<div class="psm-question-section">
									<label>Question Text *
										<textarea name="question_text" id="psm_m_question_text" class="psm-full-width-control" rows="4" maxlength="5000" required></textarea>
									</label>
									<label>External ID (Optional)
										<input type="text" name="external_id" id="psm_m_external_id" maxlength="100" placeholder="Unique within the selected skill">
									</label>
								</div>

								<div class="psm-question-section">
									<div class="psm-question-grid">
										<label>Code Language (Optional)
											<select name="code_language" id="psm_m_code_language">
												<option value="">None / Plain Text</option>
												<?php foreach ( $languages as $language_slug => $language_name ) : ?>
													<?php if ( '' === $language_slug ) { continue; } ?>
													<option value="<?php echo esc_attr( $language_slug ); ?>"><?php echo esc_html( $language_name ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									</div>
									<label>Code Snippet (Optional)
										<textarea name="code_snippet" id="psm_m_code_snippet" class="psm-full-width-control" rows="5" maxlength="20000"></textarea>
									</label>
								</div>

								<div class="psm-question-section">
									<strong>Answer Options *</strong>
									<div class="psm-question-options">
										<label>Option A
											<input type="text" name="option_a" id="psm_m_option_a" maxlength="500" required>
										</label>
										<label>Option B
											<input type="text" name="option_b" id="psm_m_option_b" maxlength="500" required>
										</label>
										<div id="psm_m_option_c_wrap">
											<label>Option C
												<input type="text" name="option_c" id="psm_m_option_c" maxlength="500" required>
											</label>
										</div>
										<div id="psm_m_option_d_wrap">
											<label>Option D
												<input type="text" name="option_d" id="psm_m_option_d" maxlength="500" required>
											</label>
										</div>
									</div>
									<span class="psm-question-help">True / False questions use fixed True and False options automatically.</span>

									<div style="margin-top:14px;">
										<strong>Correct Answer(s) *</strong>
										<div class="psm-question-answers">
											<label><input type="checkbox" name="correct_keys[]" value="A" class="psm-correct-key"> A</label>
											<label><input type="checkbox" name="correct_keys[]" value="B" class="psm-correct-key"> B</label>
											<label><input type="checkbox" name="correct_keys[]" value="C" class="psm-correct-key" id="psm_m_correct_c"> C</label>
											<label><input type="checkbox" name="correct_keys[]" value="D" class="psm-correct-key" id="psm_m_correct_d"> D</label>
										</div>
										<span class="psm-question-help">Select one answer, or two to three answers for a multiple-answer four-option question.</span>
									</div>
								</div>

								<div class="psm-question-section">
									<label>Explanation (Optional)
										<textarea name="explanation" id="psm_m_explanation" class="psm-full-width-control" rows="4" maxlength="10000"></textarea>
									</label>
									<label>Tags (Optional)
										<div class="psm-tag-picker">
											<input type="text" id="psm_m_question_tag_input" list="psm_question_tag_suggestions" autocomplete="off" placeholder="Select an existing tag or type a new tag"
												data-tooltip="Choose a skill tag or enter a new tag name" title="Choose a skill tag or enter a new tag name">
											<button type="button" id="psm_m_question_tag_add" class="psm-btn psm-btn-secondary" data-tooltip="Add the typed tag" title="Add tag">Add tag</button>
										</div>
										<datalist id="psm_question_tag_suggestions"></datalist>
										<div class="psm-selected-tags" id="psm_m_selected_question_tags" aria-live="polite"></div>
										<div class="psm-available-tags" id="psm_m_available_question_tags"></div>
										<textarea name="question_tags" id="psm_m_question_tags" hidden></textarea>
										<span class="psm-question-help">Choose an active tag from the selected skill, or type a new tag and click Add tag. New tags are saved as <code>skill-slug-tag-name</code>.</span>
									</label>
								</div>
							</div>

							<div id="psm_m_standard_fields">
								<div id="psm_m_skill_fields" style="display:none;">
									<label>Domain
										<select name="domain_id" id="psm_m_domain_id">
											<?php foreach ( $domains as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Skill Type
										<select name="skill_type_id" id="psm_m_skill_type_id">
											<?php foreach ( $skill_types as $st ) : ?>
												<option value="<?php echo esc_attr( $st->id ); ?>"><?php echo esc_html( $st->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Parent Skill
										<select name="parent_skill_id" id="psm_m_parent_skill_id">
											<option value="0">&mdash; None &mdash;</option>
											<?php foreach ( $skills as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>"><?php echo esc_html( $s->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								</div>

								<div id="psm_m_tag_fields" style="display:none;">
									<label>Parent Skill
										<select name="skill_id" id="psm_m_skill_id">
											<?php foreach ( $skills as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>"><?php echo esc_html( $s->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								</div>

								<label>Name *
									<input type="text" name="name" id="psm_m_name">
								</label>
								<label>Slug (Entity Framework Core &rarr; efcore, C# &rarr; csharp, C# OOP &rarr; csharp-oop, Laravel &rarr; laravel)
									<input type="text" name="slug" id="psm_m_slug" placeholder="auto-generated-if-blank">
								</label>
								<label>Sort Order
									<input type="number" name="sort_order" id="psm_m_sort_order" value="10">
								</label>
								<label>Description
									<textarea name="description" id="psm_m_description" rows="2"></textarea>
								</label>
							</div>

							<div id="psm_m_standard_status">
								<label>Status
									<select name="status" id="psm_m_status">
										<option value="active">Active</option>
										<option value="inactive">Inactive</option>
										<option value="completed">Completed (Batch)</option>
									</select>
								</label>
							</div>
						</div>
						<div class="psm-modal-footer">
							<button type="button" class="button button-secondary" id="psm_modal_cancel">Cancel	</button>
							<button type="submit" class="psm-btn-add" style="margin-left:8px;">Save Record</button>
						</div>
					</form>
				</div>
			</div>
		</div>

		<script>
		(function() {
			const ajaxUrl   = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
			const adminPageUrl = <?php echo wp_json_encode( admin_url( 'admin.php' ) ); ?>;
			const ajaxNonce = <?php echo wp_json_encode( $ajax_nonce ); ?>;
			const tagsBySkill = <?php echo wp_json_encode( $tags_by_skill ); ?>;
			const skillMeta = <?php echo wp_json_encode( $skill_meta ); ?>;
			let questionFilters = <?php echo wp_json_encode( $question_filters ); ?>;
			let questionOrder = <?php echo wp_json_encode( $question_ordered_ids ); ?>;
			let masterState = <?php echo wp_json_encode( $tab === 'masters' ? array(
				'entity' => $entity,
				'page'   => $master_list_data['page'],
			) : null ); ?>;
			let masterRequestId = 0;
			let masterAbort = null;
			let questionNavLoading = false;
			let questionNavRequestId = 0;

			function showToast(msg, ok = true) {
				const t = document.getElementById('psm_toast');
				if (!t) return;
				t.textContent = msg;
				t.className = 'psm-toast ' + (ok ? 'ok' : 'err');
				t.style.display = 'block';
				clearTimeout(showToast.timer);
				showToast.timer = setTimeout(() => { t.style.display = 'none'; }, 4000);
			}

			const confirmModal = document.getElementById('psm_confirm_modal');
			let confirmResolver = null;
			function settleSoftConfirm(result) {
				if (!confirmResolver) return;
				const resolve = confirmResolver;
				confirmResolver = null;
				confirmModal?.classList.remove('open');
				resolve(result);
			}
			function softConfirm({ title = 'Please confirm', message = '', confirmLabel = 'Confirm', danger = true } = {}) {
				if (confirmResolver) settleSoftConfirm(false);
				document.getElementById('psm_confirm_title').textContent = title;
				document.getElementById('psm_confirm_message').textContent = message;
				const accept = document.getElementById('psm_confirm_accept');
				accept.textContent = confirmLabel;
				accept.className = 'psm-btn ' + (danger ? 'psm-confirm-danger' : 'psm-btn-primary');
				confirmModal?.classList.add('open');
				setTimeout(() => accept?.focus(), 30);
				return new Promise(resolve => { confirmResolver = resolve; });
			}

			function updateMasterMetadata(data) {
				Object.keys(tagsBySkill).forEach(key => delete tagsBySkill[key]);
				(data.tags || []).filter(tag => tag.status === 'active').forEach(function(tag) {
					const skillId = String(tag.skill_id);
					if (!tagsBySkill[skillId]) tagsBySkill[skillId] = [];
					tagsBySkill[skillId].push({ id: Number(tag.id), name: tag.name, label: tag.name, slug: tag.slug });
				});
				(data.skills || []).forEach(function(skill) {
					skillMeta[String(skill.id)] = { name: skill.name, slug: skill.slug };
				});
			}

			function updateMasterCounts(counts) {
				const labels = {
					domain: 'Domains', skill_type: 'Skill Types', question_type: 'Question Types',
					difficulty: 'Difficulties', skill: 'Skills', tag: 'Tags', question: 'Questions'
				};
				document.querySelectorAll('.psm-pill-nav .psm-pill').forEach(function(link) {
					const entity = new URLSearchParams(link.search).get('entity');
					if (entity && counts[entity] !== undefined) link.textContent = labels[entity] + ' (' + counts[entity] + ')';
				});
			}

			function updateMasterUrl() {
				if (!masterState) return;
				const form = document.getElementById('psm_master_filters');
				if (!form) return;
				const params = new URLSearchParams();
				params.set('page', 'psm-ai-importer');
				params.set('tab', 'masters');
				params.set('entity', masterState.entity);
				new FormData(form).forEach(function(value, key) {
					if (key === masterState.entity + '_page' || value === '') return;
					params.set(key, String(value));
				});
				if (masterState.page > 1) params.set(masterState.entity + '_page', String(masterState.page));
				history.replaceState(null, '', adminPageUrl + '?' + params.toString());
			}

			async function loadMasterList(nextState = {}, options = {}) {
				if (!masterState) return;
				const list = document.getElementById('psm_master_list');
				const form = document.getElementById('psm_master_filters');
				if (!list || !form) return;
				masterState = Object.assign({}, masterState, nextState);
				const requestId = ++masterRequestId;
				if (masterAbort) masterAbort.abort();
				masterAbort = new AbortController();
				const fd = new FormData(form);
				fd.set('action', 'psm_ajax_get_master_list');
				fd.set('nonce', ajaxNonce);
				fd.set('entity', masterState.entity);
				fd.set(masterState.entity + '_page', String(masterState.page));
				list.classList.add('is-loading');
				list.setAttribute('aria-busy', 'true');
				try {
					const response = await fetch(ajaxUrl, { method: 'POST', body: fd, signal: masterAbort.signal });
					const result = await response.json();
					if (requestId !== masterRequestId) return;
					if (!result.success) throw new Error(result.data?.message || 'Could not load the master list.');
					const current = document.getElementById('psm_master_list');
					if (current) current.outerHTML = result.data.html;
					updateMasterCounts(result.data.counts);
					updateMasterMetadata(result.data);
					if (masterState.entity === 'question') {
						questionFilters = result.data.filters;
						questionOrder = result.data.question_order;
					}
					syncQuestionTagFilter();
					updateMasterUrl();
					if (options.scroll) document.querySelector('.psm-table-card')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
				} catch (error) {
					if (error.name !== 'AbortError') showToast(error.message || 'Network error while loading records.', false);
				} finally {
					if (requestId === masterRequestId) {
						masterAbort = null;
						const current = document.getElementById('psm_master_list');
						current?.classList.remove('is-loading');
						current?.removeAttribute('aria-busy');
					}
				}
			}

			function refreshMasterList() {
				return masterState ? loadMasterList({ page: masterState.page }) : Promise.resolve();
			}

			function syncQuestionTagFilter() {
				const skillFilter = document.getElementById('psm_question_skill_filter');
				const tagFilter = document.getElementById('psm_question_tag_filter');
				if (!tagFilter) return;
				const skillId = parseInt(skillFilter?.value || '0', 10);
				Array.from(tagFilter.options).forEach(function(option) {
					if (!option.value) return;
					option.hidden = !!skillId && parseInt(option.dataset.skillId || '0', 10) !== skillId;
				});
				const selected = tagFilter.selectedOptions[0];
				if (selected && selected.value && selected.hidden) tagFilter.value = '';
			}

			let filterTimer = null;
			document.addEventListener('submit', function(e) {
				const filterForm = e.target.closest('#psm_master_filters');
				if (filterForm && masterState) {
					e.preventDefault();
					clearTimeout(filterTimer);
					masterState.page = 1;
					loadMasterList();
					return;
				}
				const seederForm = e.target.closest('#psm_seeder_form');
				if (seederForm) {
					e.preventDefault();
					softConfirm({
						title: seederForm.dataset.confirmTitle,
						message: seederForm.dataset.confirmMessage,
						confirmLabel: seederForm.dataset.confirmLabel,
						danger: false
					}).then(confirmed => { if (confirmed) seederForm.submit(); });
				}
			}, true);

			document.addEventListener('input', function(e) {
				if (!masterState || !e.target.matches('#psm_master_filters input[type="search"]')) return;
				clearTimeout(filterTimer);
				filterTimer = setTimeout(function() {
					masterState.page = 1;
					loadMasterList();
				}, 350);
			}, true);

			document.addEventListener('change', function(e) {
				if (!masterState || !e.target.closest('#psm_master_filters')) return;
				if (e.target.id === 'psm_question_skill_filter') syncQuestionTagFilter();
				clearTimeout(filterTimer);
				masterState.page = 1;
				loadMasterList();
			}, true);

			document.addEventListener('click', async function(e) {
				if (!e.target?.closest) return;
				const reset = e.target.closest('[data-psm-reset-filters]');
				if (reset && masterState) {
					e.preventDefault();
					const form = document.getElementById('psm_master_filters');
					form.querySelectorAll('input[type="search"], select').forEach(control => { control.value = ''; });
					syncQuestionTagFilter();
					masterState.page = 1;
					loadMasterList();
					return;
				}
				const pageButton = e.target.closest('.psm-page-btn[data-page]');
				if (pageButton && !pageButton.classList.contains('is-disabled') && !pageButton.classList.contains('is-current') && masterState) {
					e.preventDefault();
					masterState.page = parseInt(pageButton.dataset.page, 10) || 1;
					loadMasterList({}, { scroll: true });
					return;
				}
				if (e.target.closest('#psm_confirm_cancel') || e.target.closest('#psm_confirm_close')) {
					settleSoftConfirm(false);
					return;
				}
				if (e.target.closest('#psm_confirm_accept')) {
					settleSoftConfirm(true);
					return;
				}
				if (e.target === confirmModal) {
					settleSoftConfirm(false);
					return;
				}
				const deleteButton = e.target.closest('.psm-ajax-delete');
				if (deleteButton) {
					e.preventDefault();
					const entity = deleteButton.dataset.entity;
					const id = deleteButton.dataset.id;
					const name = deleteButton.dataset.name || 'this record';
					const confirmed = await softConfirm({
						title: 'Delete ' + entity.replace(/_/g, ' ') + '?',
						message: 'Are you sure you want to delete "' + name + ' (#' + id + ')"? This action cannot be undone.',
						confirmLabel: 'Delete'
					});
					if (!confirmed) return;
					deleteButton.disabled = true;
					const fd = new FormData();
					fd.append('action', 'psm_ajax_delete_master');
					fd.append('nonce', ajaxNonce);
					fd.append('entity', entity);
					fd.append('record_id', id);
					try {
						const response = await fetch(ajaxUrl, { method: 'POST', body: fd });
						const result = await response.json();
						if (!result.success) throw new Error(result.data?.message || 'Delete blocked.');
						showToast(result.data.message, true);
						if (masterState && masterState.entity === entity) await refreshMasterList();
						else document.getElementById('psm-row-' + entity + '-' + id)?.remove();
					} catch (error) {
						showToast(error.message || 'Network error while deleting.', false);
					} finally {
						deleteButton.disabled = false;
					}
				}
			}, true);

			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && confirmModal?.classList.contains('open')) settleSoftConfirm(false);
			});

			function escapeHtml(str) {
				const div = document.createElement('div');
				div.textContent = String(str ?? '');
				return div.innerHTML;
			}

			function setActionNotice(message, isError) {
				const box = document.getElementById('psm_ai_ajax_notice');
				if (!box) return;
				box.innerHTML = message
					? '<div class="notice notice-' + (isError ? 'error' : 'info') + ' inline"><p>' + escapeHtml(message) + '</p></div>'
					: '';
			}

			// Select All Question Types
			document.getElementById('psm_select_all_qtypes')?.addEventListener('click', function() {
				document.querySelectorAll('.psm-qtype-cb').forEach(cb => cb.checked = true);
			});

			// AJAX AI Generate Preview Form + Loader
			const genForm = document.getElementById('psm_generate_form');
			genForm?.addEventListener('submit', function(e) {
				e.preventDefault();
				const loader = document.getElementById('psm_ai_loader');
				const notice = document.getElementById('psm_ai_ajax_notice');
				const submitBtn = genForm.querySelector('button[type="submit"], input[type="submit"]');

				if (notice) notice.innerHTML = '';
				if (submitBtn) submitBtn.disabled = true;
				if (loader) loader.style.display = 'flex';

				const fd = new FormData(this);
				fd.set('action', 'psm_ajax_fetch_preview');
				fd.set('nonce', ajaxNonce);

				function escapeText(str) {
					const div = document.createElement('div');
					div.textContent = String(str ?? '');
					return div.innerHTML;
				}

				fetch(ajaxUrl, { method: 'POST', body: fd })
					.then(async r => {
						const rawText = await r.text();
						try {
							return { ok: r.ok, status: r.status, json: JSON.parse(rawText), rawText };
						} catch (err) {
							return { ok: r.ok, status: r.status, json: null, rawText };
						}
					})
					.then(({ ok, status, json, rawText }) => {
						if (json && json.success && json.data && json.data.redirect_url) {
							window.location.href = json.data.redirect_url;
							return;
						}
						const msg = json?.data?.message
							|| ('HTTP ' + status + ': ' + (rawText ? rawText.replace(/<[^>]*>/g, ' ').trim().slice(0, 300) : 'Empty response'));
						if (notice) {
							notice.innerHTML = '<div class="notice notice-error inline"><p>' + escapeText(msg) + '</p></div>';
						}
						showToast(msg, false);
					})
					.catch(err => {
						const msg = 'Network error while calling AI: ' + (err && err.message ? err.message : 'Request failed');
						if (notice) {
							notice.innerHTML = '<div class="notice notice-error inline"><p>' + escapeText(msg) + '</p></div>';
						}
						showToast(msg, false);
					})
					.finally(() => {
						if (submitBtn) submitBtn.disabled = false;
						if (loader) loader.style.display = 'none';
					});
			});

			// Skill -> Tag dynamic loader on Tab 1
			const skillSelect = document.getElementById('psm_skill_select');
			const tagBox      = document.getElementById('psm_tag_box');
			skillSelect?.addEventListener('change', function() {
				const skillId = parseInt(this.value, 10);
				const list    = tagsBySkill[skillId] || [];
				if (!skillId || list.length === 0) {
					tagBox.innerHTML = '<em>No tags found for this skill.</em>';
				} else {
					let html = '<div style="display:grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 6px;">';
					list.forEach(function(t) {
						html += '<label style="display:block;"><input type="checkbox" name="tag_ids[]" value="' + t.id + '" title="' + t.slug + '"> <code>' + t.name + '</code></label>';
					});
					html += '</div>';
					tagBox.innerHTML = html;
				}
			});

			// Create Prompt modal (Copy / Download)
			const promptModal    = document.getElementById('psm_prompt_modal');
			const promptTextarea = document.getElementById('psm_prompt_textarea');
			const promptStatus   = document.getElementById('psm_prompt_status');
			let promptFilename   = 'ai-prompt.txt';

			const openPromptModal = () => promptModal?.classList.add('open');
			const closePromptModal = () => promptModal?.classList.remove('open');

			function buildPromptFromForm(btn) {
				const form = document.getElementById('psm_generate_form');
				if (!form) return;
				const skillSel = document.getElementById('psm_skill_select');
				setActionNotice('');

				if (!skillSel || !skillSel.value) {
					const msg = 'Select a Skill in step 1 first, then click Create Prompt again.';
					setActionNotice(msg, true);
					showToast(msg, false);
					skillSel?.scrollIntoView({ behavior: 'smooth', block: 'center' });
					skillSel?.classList.add('psm-field-error');
					setTimeout(() => skillSel?.classList.remove('psm-field-error'), 2600);
					skillSel?.focus();
					return;
				}

				if (!btn) btn = document.getElementById('psm_create_prompt_btn');
				if (btn) {
					btn.disabled = true;
					btn.textContent = 'Building...';
				}

				const fd = new FormData(form);
				fd.set('action', 'psm_ajax_build_prompt');
				fd.set('nonce', ajaxNonce);

				fetch(ajaxUrl, { method: 'POST', body: fd })
					.then(r => r.json())
					.then(res => {
						if (res.success && res.data && res.data.prompt) {
							setActionNotice('');
							promptTextarea.value = res.data.prompt;
							promptFilename = res.data.filename || 'ai-prompt.txt';
							if (promptStatus) {
								promptStatus.textContent = 'Skill: ' + (res.data.skill_name || skillSel.value) + ' \u2022 ' + promptFilename;
							}
							openPromptModal();
						} else {
							const msg = res.data?.message || 'Could not build the prompt. Please try again.';
							setActionNotice(msg, true);
							showToast(msg, false);
						}
					})
					.catch(err => {
						const msg = 'Network error while building the prompt: ' + (err && err.message ? err.message : 'request failed');
						setActionNotice(msg, true);
						showToast(msg, false);
					})
					.finally(() => {
						if (btn) {
							btn.disabled = false;
							btn.textContent = 'Create Prompt (Copy / Download)';
						}
					});
			}

			document.getElementById('psm_prompt_close')?.addEventListener('click', closePromptModal);
			document.getElementById('psm_prompt_close_footer')?.addEventListener('click', closePromptModal);
			promptModal?.addEventListener('click', function(e) {
				if (e.target === promptModal) closePromptModal();
			});
			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && promptModal?.classList.contains('open')) {
					closePromptModal();
				}
			});

			document.getElementById('psm_prompt_copy')?.addEventListener('click', function() {
				const text = promptTextarea.value || '';
				if (!text) return;
				const done = () => showToast('Prompt copied to clipboard.', true);
				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(text).then(done).catch(() => {
						promptTextarea.select();
						document.execCommand('copy');
						done();
					});
				} else {
					promptTextarea.select();
					document.execCommand('copy');
					done();
				}
			});

			document.getElementById('psm_prompt_download')?.addEventListener('click', function() {
				const text = promptTextarea.value || '';
				if (!text) return;
				const blob = new Blob([text], { type: 'text/plain;charset=utf-8' });
				const url  = URL.createObjectURL(blob);
				const a    = document.createElement('a');
				a.href = url;
				a.download = promptFilename || 'ai-prompt.txt';
				document.body.appendChild(a);
				a.click();
				document.body.removeChild(a);
				URL.revokeObjectURL(url);
			});

			// Import JSON modal: open from the generator and sync the current skill/tag focus.
			const jsonImportModal = document.getElementById('psm_import_json_modal');
			const closeJsonImportModal = () => jsonImportModal?.classList.remove('open');
			function jumpToImportCard(e) {
				if (!jsonImportModal) return true;
				if (e && e.preventDefault) e.preventDefault();
				const srcSkill = document.getElementById('psm_skill_select');
				const dstSkill = document.getElementById('psm_json_skill_select');
				if (srcSkill && dstSkill && srcSkill.value) dstSkill.value = srcSkill.value;
				if (dstSkill) loadJsonFocusTags();
				const srcTags = document.querySelectorAll('#psm_tag_box input[name="tag_ids[]"]:checked');
				if (jsonFocusTag && srcTags.length === 1) jsonFocusTag.value = srcTags[0].value;
				jsonImportModal.classList.add('open');
				setTimeout(() => dstSkill?.focus(), 50);
				return false;
			}
			document.getElementById('psm_import_json_close')?.addEventListener('click', closeJsonImportModal);
			document.getElementById('psm_import_json_cancel')?.addEventListener('click', closeJsonImportModal);
			jsonImportModal?.addEventListener('click', function(e) {
				if (e.target === jsonImportModal) closeJsonImportModal();
			});
			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && jsonImportModal?.classList.contains('open')) closeJsonImportModal();
			});

			// Delegated in capture phase so plugin/theme stopPropagation cannot swallow these clicks.
			document.addEventListener('click', function(e) {
				if (!e.target || !e.target.closest) return;
				const createBtn = e.target.closest('#psm_create_prompt_btn');
				if (createBtn) {
					e.preventDefault();
					buildPromptFromForm(createBtn);
					return;
				}
				if (e.target.closest('#psm_import_json_jump')) {
					jumpToImportCard(e);
				}
			}, true);

			// Import card: populate Tag Focus from the selected skill, then sync from the main form.
			const jsonSkillSelect = document.getElementById('psm_json_skill_select');
			const jsonFocusTag    = document.getElementById('psm_json_focus_tag');

			function loadJsonFocusTags() {
				if (!jsonFocusTag) return;
				const skillId = parseInt(jsonSkillSelect ? jsonSkillSelect.value : '0', 10);
				const list    = tagsBySkill[skillId] || [];
				let html = '<option value="">&mdash; No tag focus &mdash;</option>';
				list.forEach(function(t) {
					html += '<option value="' + t.id + '">' + escapeHtml(t.label || t.name) + ' [' + escapeHtml(t.slug) + ']</option>';
				});
				jsonFocusTag.innerHTML = html;
			}

			jsonSkillSelect?.addEventListener('change', loadJsonFocusTags);
			loadJsonFocusTags();
			syncQuestionTagFilter();

			// Select all rows on Preview
			document.getElementById('psm_select_all_rows')?.addEventListener('change', function() {
				const checked = this.checked;
				document.querySelectorAll('.psm-row-cb').forEach(cb => { if (!cb.disabled) cb.checked = checked; });
			});

			// Modal Popup Logic + ESC to Close
			const modal = document.getElementById('psm_master_modal');
			const closeModal = () => modal?.classList.remove('open');
			document.getElementById('psm_modal_close')?.addEventListener('click', closeModal);
			document.getElementById('psm_modal_cancel')?.addEventListener('click', closeModal);

			modal?.addEventListener('click', function(e) {
				if (e.target === modal) {
					closeModal();
				}
			});

			document.addEventListener('keydown', function(e) {
				if ((e.key === 'Escape' || e.keyCode === 27) && modal?.classList.contains('open')) {
					closeModal();
				}
			});

			const modalForm = document.getElementById('psm_modal_form');
			const questionTypeSelect = document.getElementById('psm_m_question_type');
			const correctKeyInputs = Array.from(document.querySelectorAll('.psm-correct-key'));

			function updateQuestionType(clearFixedOptions = false) {
				if (!questionTypeSelect) return;
				const type = questionTypeSelect.value;
				const isTrueFalse = type === 'true_false';
				const showTwoOptions = type === 'two_option' || isTrueFalse;
				const wasTrueFalse = questionTypeSelect.dataset.trueFalse === '1';

				['c', 'd'].forEach(function(key) {
					const wrap = document.getElementById('psm_m_option_' + key + '_wrap');
					const input = document.getElementById('psm_m_option_' + key);
					const correct = document.getElementById('psm_m_correct_' + key);
					if (wrap) wrap.style.display = showTwoOptions ? 'none' : 'block';
					if (input) {
						input.disabled = showTwoOptions;
						input.required = !showTwoOptions;
					}
					if (correct) {
						correct.disabled = showTwoOptions;
						if (showTwoOptions) correct.checked = false;
					}
				});

				['a', 'b'].forEach(function(key) {
					const input = document.getElementById('psm_m_option_' + key);
					if (!input) return;
					input.disabled = isTrueFalse;
					input.required = !isTrueFalse;
					if (isTrueFalse) input.value = key === 'a' ? 'True' : 'False';
					else if (clearFixedOptions && wasTrueFalse && ['True', 'False'].includes(input.value)) input.value = '';
				});
				questionTypeSelect.dataset.trueFalse = isTrueFalse ? '1' : '0';
			}

			questionTypeSelect?.addEventListener('change', () => updateQuestionType(true));
			correctKeyInputs.forEach(function(input) {
				input.addEventListener('change', function() {
					if (!this.checked) return;
					const type = questionTypeSelect?.value;
					if (type !== 'four_option') {
						correctKeyInputs.forEach(other => { if (other !== this) other.checked = false; });
						return;
					}
					const selected = correctKeyInputs.filter(other => other.checked);
					if (selected.length > 3) {
						this.checked = false;
						alert('A multiple-answer question supports at most three correct answers.');
					}
				});
			});

			const questionSkillSelect = document.getElementById('psm_m_question_skill_id');
			const questionTagInput = document.getElementById('psm_m_question_tag_input');
			const questionTagList = document.getElementById('psm_m_question_tags');
			const selectedTagsBox = document.getElementById('psm_m_selected_question_tags');
			const availableTagsBox = document.getElementById('psm_m_available_question_tags');
			const tagSuggestions = document.getElementById('psm_question_tag_suggestions');
			let selectedQuestionTags = [];

			function normalizeQuestionTag(raw) {
				const skillId = parseInt(questionSkillSelect?.value || '0', 10);
				const skill = skillMeta[skillId];
				if (!skill) return '';
				let value = String(raw || '').trim();
				if (!value) return '';
				const escapedName = skill.name.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
				value = value.replace(new RegExp('^\\s*' + escapedName + '\\s*[-\\/|:]*\\s*', 'iu'), '');
				let slug = value.toLowerCase().normalize('NFKD').replace(/[\u0300-\u036f]/g, '')
					.replace(/&/g, ' and ').replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
					.replace(/c-sharp/g, 'csharp');
				const prefix = skill.slug + '-';
				while (slug.startsWith(prefix) && slug.length > prefix.length) slug = slug.slice(prefix.length);
				if (!slug || slug === skill.slug) return '';
				return prefix + slug;
			}

			function setQuestionTags(tags) {
				selectedQuestionTags = Array.from(new Set((Array.isArray(tags) ? tags : []).map(tag => String(tag).trim()).filter(Boolean)));
				if (questionTagList) questionTagList.value = selectedQuestionTags.join(', ');
				renderQuestionTagPicker();
			}

			function renderQuestionTagPicker() {
				const skillId = parseInt(questionSkillSelect?.value || '0', 10);
				const list = tagsBySkill[skillId] || [];
				if (selectedTagsBox) {
					selectedTagsBox.replaceChildren();
					selectedQuestionTags.forEach(function(slug) {
						const chip = document.createElement('span');
						chip.className = 'psm-tag-chip';
						const text = document.createElement('span');
						text.textContent = slug;
						const remove = document.createElement('button');
						remove.type = 'button';
						remove.textContent = '×';
						remove.setAttribute('aria-label', 'Remove tag ' + slug);
						remove.title = 'Remove tag';
						remove.dataset.tooltip = 'Remove tag';
						remove.addEventListener('click', function() {
							setQuestionTags(selectedQuestionTags.filter(tag => tag !== slug));
						});
						chip.append(text, remove);
						selectedTagsBox.appendChild(chip);
					});
				}
				if (tagSuggestions) {
					tagSuggestions.replaceChildren();
					list.forEach(function(tag) {
						const option = document.createElement('option');
						option.value = tag.slug;
						option.label = tag.label || tag.name;
						tagSuggestions.appendChild(option);
					});
				}
				if (availableTagsBox) {
					availableTagsBox.replaceChildren();
					if (!skillId) {
						const hint = document.createElement('span');
						hint.className = 'psm-question-help';
						hint.textContent = 'Select a skill to see its tags.';
						availableTagsBox.appendChild(hint);
						return;
					}
					if (!list.length) {
						const hint = document.createElement('span');
						hint.className = 'psm-question-help';
						hint.textContent = 'No active tags yet. Type a new tag above and click Add tag.';
						availableTagsBox.appendChild(hint);
						return;
					}
					list.filter(tag => !selectedQuestionTags.includes(tag.slug)).forEach(function(tag) {
						const button = document.createElement('button');
						button.type = 'button';
						button.className = 'psm-tag-option';
						button.textContent = tag.slug;
						button.title = 'Add ' + tag.slug;
						button.dataset.tooltip = 'Add ' + tag.slug;
						button.addEventListener('click', function() { addQuestionTag(tag.slug); });
						availableTagsBox.appendChild(button);
					});
				}
			}

			function addQuestionTag(raw) {
				const slug = normalizeQuestionTag(raw);
				if (!slug) {
					alert('Select a skill and enter a valid tag name.');
					return;
				}
				if (selectedQuestionTags.includes(slug)) {
					showToast('That tag is already selected.', false);
					return;
				}
				setQuestionTags(selectedQuestionTags.concat(slug));
				if (questionTagInput) questionTagInput.value = '';
				questionTagInput?.focus();
			}

			function setQuestionEditNav(recordId) {
				const nav = document.getElementById('psm_question_edit_nav');
				const previous = document.getElementById('psm_question_previous');
				const next = document.getElementById('psm_question_next');
				const position = document.getElementById('psm_question_position');
				const id = parseInt(recordId || '0', 10);
				const index = questionOrder.findIndex(questionId => Number(questionId) === id);
				const previousId = index > 0 ? Number(questionOrder[index - 1]) : 0;
				const nextId = index >= 0 && index < questionOrder.length - 1 ? Number(questionOrder[index + 1]) : 0;
				if (nav) nav.style.display = id > 0 ? 'inline-flex' : 'none';
				if (previous) {
					previous.disabled = questionNavLoading || !previousId;
					previous.dataset.questionId = previousId || '';
				}
				if (next) {
					next.disabled = questionNavLoading || !nextId;
					next.dataset.questionId = nextId || '';
				}
				if (position) {
					position.textContent = id > 0
						? 'Question #' + id + (index >= 0 ? ' (' + (index + 1) + ' of ' + questionOrder.length + ')' : '')
						: 'Question';
				}
			}

			function populateQuestionModal(rec) {
				document.getElementById('psm_m_question_skill_id').value = rec ? (rec.skill_id || '') : '';
				document.getElementById('psm_m_question_type').value      = rec ? (rec.question_type || 'four_option') : 'four_option';
				document.getElementById('psm_m_question_difficulty').value = rec ? (rec.difficulty || 1) : 1;
				document.getElementById('psm_m_question_status').value    = rec ? (rec.status || 'draft') : 'draft';
				document.getElementById('psm_m_question_text').value      = rec ? (rec.question_text || '') : '';
				document.getElementById('psm_m_external_id').value        = rec ? (rec.external_id || '') : '';
				document.getElementById('psm_m_code_language').value     = rec ? (rec.code_language || '') : '';
				document.getElementById('psm_m_code_snippet').value      = rec ? (rec.code_snippet || '') : '';
				document.getElementById('psm_m_explanation').value        = rec ? (rec.explanation || '') : '';
				setQuestionTags(rec && Array.isArray(rec.tag_slugs) ? rec.tag_slugs : []);

				const options = rec && rec.options && typeof rec.options === 'object' ? rec.options : {};
				['a', 'b', 'c', 'd'].forEach(function(key, index) {
					const optionKey = key.toUpperCase();
					const value = options[optionKey] ?? (Array.isArray(options) ? (options[index] || '') : '');
					document.getElementById('psm_m_option_' + key).value = value;
				});
				correctKeyInputs.forEach(function(input) {
					input.checked = !!(rec && Array.isArray(rec.correct_keys) && rec.correct_keys.includes(input.value));
				});
				updateQuestionType();
				setQuestionEditNav(rec?.id || 0);
			}

			questionSkillSelect?.addEventListener('change', function() {
				selectedQuestionTags = [];
				if (questionTagInput) questionTagInput.value = '';
				renderQuestionTagPicker();
			});
			document.getElementById('psm_m_question_tag_add')?.addEventListener('click', function() {
				addQuestionTag(questionTagInput?.value || '');
			});
			questionTagInput?.addEventListener('keydown', function(e) {
				if (e.key === 'Enter') {
					e.preventDefault();
					addQuestionTag(this.value);
				}
			});

			document.addEventListener('click', function(e) {
				const btn = e.target.closest('.psm-open-modal');
				if (!btn) return;
				e.preventDefault();
					const entity = btn.dataset.entity;
					const recRaw = btn.dataset.record;
					const rec    = recRaw ? JSON.parse(recRaw) : null;
					const isQuestion = entity === 'question';
					questionNavRequestId++;
					questionNavLoading = false;

					modalForm?.reset();
					selectedQuestionTags = [];
					if (questionTagInput) questionTagInput.value = '';
					renderQuestionTagPicker();
					setQuestionEditNav(0);
					document.getElementById('psm_m_entity').value    = entity;
					document.getElementById('psm_m_record_id').value = rec ? rec.id : 0;

					const labelEntity = entity.replace(/_/g, ' ').replace(/\b\w/g, l => l.toUpperCase());
					document.getElementById('psm_modal_title').textContent = (rec ? 'Edit ' : 'Add New ') + labelEntity;
					modal?.querySelector('.psm-modal')?.classList.toggle('psm-modal-question', isQuestion);

					document.getElementById('psm_m_skill_fields').style.display      = (entity === 'skill') ? 'block' : 'none';
					document.getElementById('psm_m_tag_fields').style.display        = (entity === 'tag') ? 'block' : 'none';
					document.getElementById('psm_m_batch_fields').style.display      = (entity === 'batch') ? 'block' : 'none';
					document.getElementById('psm_m_question_fields').style.display   = isQuestion ? 'block' : 'none';
					document.getElementById('psm_m_standard_fields').style.display   = (entity === 'batch' || isQuestion) ? 'none' : 'block';
					document.getElementById('psm_m_standard_status').style.display   = isQuestion ? 'none' : 'block';

					[
						'psm_m_batch_fields',
						'psm_m_question_fields',
						'psm_m_standard_fields',
						'psm_m_skill_fields',
						'psm_m_tag_fields',
						'psm_m_standard_status'
					].forEach(function(fieldId) {
						const section = document.getElementById(fieldId);
						if (!section) return;
						const hidden = getComputedStyle(section).display === 'none';
						section.querySelectorAll('input, select, textarea').forEach(function(control) {
							control.disabled = hidden;
						});
					});

					if (isQuestion) {
						document.getElementById('psm_m_question_skill_id').value = rec ? (rec.skill_id || '') : '';
						document.getElementById('psm_m_question_type').value      = rec ? (rec.question_type || 'four_option') : 'four_option';
						document.getElementById('psm_m_question_difficulty').value = rec ? (rec.difficulty || 1) : 1;
						document.getElementById('psm_m_question_status').value    = rec ? (rec.status || 'draft') : 'draft';
						document.getElementById('psm_m_question_text').value      = rec ? (rec.question_text || '') : '';
						document.getElementById('psm_m_external_id').value        = rec ? (rec.external_id || '') : '';
						document.getElementById('psm_m_code_language').value     = rec ? (rec.code_language || '') : '';
						document.getElementById('psm_m_code_snippet').value      = rec ? (rec.code_snippet || '') : '';
						document.getElementById('psm_m_explanation').value        = rec ? (rec.explanation || '') : '';
						setQuestionTags(rec && Array.isArray(rec.tag_slugs) ? rec.tag_slugs : []);

						const options = rec && rec.options && typeof rec.options === 'object' ? rec.options : {};
						['a', 'b', 'c', 'd'].forEach(function(key, index) {
							const optionKey = key.toUpperCase();
							const value = options[optionKey] ?? (Array.isArray(options) ? (options[index] || '') : '');
							document.getElementById('psm_m_option_' + key).value = value;
						});
						correctKeyInputs.forEach(function(input) {
							input.checked = !!(rec && Array.isArray(rec.correct_keys) && rec.correct_keys.includes(input.value));
						});
						updateQuestionType();
						setQuestionEditNav(rec?.id || 0);
					} else if (entity === 'batch') {
						document.getElementById('psm_m_label_name').value = rec ? (rec.label_name || '') : '';
						document.getElementById('psm_m_status').value     = rec ? (rec.status || 'completed') : 'completed';
					} else {
						document.getElementById('psm_m_name').value        = rec ? (rec.name || '') : '';
						document.getElementById('psm_m_slug').value        = rec ? (rec.slug || '') : '';
						document.getElementById('psm_m_sort_order').value  = rec ? (rec.sort_order || 10) : 10;
						document.getElementById('psm_m_description').value = rec ? (rec.description || '') : '';
						document.getElementById('psm_m_status').value      = rec ? (rec.status || 'active') : 'active';

						if (entity === 'skill' && rec) {
							document.getElementById('psm_m_domain_id').value       = rec.domain_id || 0;
							document.getElementById('psm_m_skill_type_id').value   = rec.skill_type_id || 0;
							document.getElementById('psm_m_parent_skill_id').value = rec.parent_id || rec.parent_skill_id || 0;
						}
						if (entity === 'tag' && rec) {
							document.getElementById('psm_m_skill_id').value = rec.skill_id || 0;
						}
					}

					modal.classList.add('open');
			}, true);

			['psm_question_previous', 'psm_question_next'].forEach(function(id) {
				document.getElementById(id)?.addEventListener('click', async function() {
					if (questionNavLoading) return;
					const questionId = this.dataset.questionId;
					if (!questionId) return;
					const sourceRecordId = document.getElementById('psm_m_record_id').value;
					questionNavLoading = true;
					setQuestionEditNav(document.getElementById('psm_m_record_id').value);
					const requestId = ++questionNavRequestId;
					const fd = new FormData();
					fd.append('action', 'psm_ajax_get_question_master');
					fd.append('nonce', ajaxNonce);
					fd.append('question_id', questionId);
					Object.entries(questionFilters).forEach(function([key, value]) {
						if (value !== '' && value !== 0) fd.append(key, value);
					});
					try {
						const response = await fetch(ajaxUrl, { method: 'POST', body: fd });
						const result = await response.json();
						if (requestId !== questionNavRequestId || !modal?.classList.contains('open') || document.getElementById('psm_m_record_id').value !== sourceRecordId) return;
						if (!result.success || !result.data?.record) throw new Error(result.data?.message || 'Could not load the question.');
						document.getElementById('psm_m_record_id').value = result.data.record.id;
						document.getElementById('psm_modal_title').textContent = 'Edit Question';
						populateQuestionModal(result.data.record);
						const body = modal?.querySelector('.psm-modal-body');
						if (body) body.scrollTop = 0;
					} catch (error) {
						if (requestId === questionNavRequestId) showToast(error.message || 'Network error while loading the question.', false);
					} finally {
						if (requestId === questionNavRequestId) {
							questionNavLoading = false;
							setQuestionEditNav(document.getElementById('psm_m_record_id').value);
						}
					}
				});
			});

			// Auto-enforce fixed slugs in slug preview
			document.getElementById('psm_m_name')?.addEventListener('input', function() {
				const recId = parseInt(document.getElementById('psm_m_record_id').value, 10);
				if (recId > 0) return;
				const v = this.value.trim().toLowerCase();
				const slugEl = document.getElementById('psm_m_slug');
				if (v === 'entity framework core') slugEl.value = 'efcore';
				else if (v === 'c#') slugEl.value = 'csharp';
				else if (v === 'c# oop') slugEl.value = 'csharp-oop';
				else if (v === 'laravel') slugEl.value = 'laravel';
			});

			// Submit Modal AJAX Save
			document.getElementById('psm_modal_form')?.addEventListener('submit', function(e) {
				e.preventDefault();
				const entity = document.getElementById('psm_m_entity').value;
				if (!this.reportValidity()) return;

				if (entity === 'question') {
					const type = questionTypeSelect?.value;
					const selected = correctKeyInputs.filter(input => input.checked);
					const validCount = type === 'four_option'
						? selected.length >= 1 && selected.length <= 3
						: selected.length === 1;
					if (!validCount) {
						alert(type === 'four_option'
							? 'Select one to three correct answers.'
							: 'Select exactly one correct answer.');
						correctKeyInputs[0]?.focus();
						return;
					}
				}

				const submitBtn = this.querySelector('button[type="submit"]');
				if (submitBtn?.disabled) return;
				if (submitBtn) submitBtn.disabled = true;
				const fd = new FormData(this);
				fd.append('action', 'psm_ajax_save_master');
				fd.append('nonce', ajaxNonce);

				fetch(ajaxUrl, { method: 'POST', body: fd })
					.then(r => r.json())
					.then(res => {
						if (res.success) {
							showToast(res.data.message, true);
							closeModal();
							if (masterState && masterState.entity === entity) refreshMasterList();
							else location.reload();
						} else {
							alert(res.data?.message || 'Error saving record.');
						}
					})
					.catch(() => alert('Network error while saving.'))
					.finally(() => { if (submitBtn) submitBtn.disabled = false; });
			});

			// 3 Separate AJAX AI Forms on Tab 4
			document.querySelectorAll('.psm-ajax-ai-form').forEach(form => {
				form.addEventListener('submit', function(e) {
					e.preventDefault();
					const fd = new FormData(this);
					fd.append('action', 'psm_ajax_save_ai_provider');
					fd.append('nonce', ajaxNonce);

					fetch(ajaxUrl, { method: 'POST', body: fd })
						.then(r => r.json())
						.then(res => {
							if (res.success) {
								showToast(res.data.message, true);
								const keyInput   = this.querySelector('[name="api_key"]');
								const clearBox   = this.querySelector('[name="clear_api_key"]');
								const wasCleared = clearBox ? clearBox.checked : false;
								const typed      = keyInput ? keyInput.value.trim() : '';
								if (keyInput) {
									// Intended behavior: the saved key is never echoed back, so clear the field after saving.
									keyInput.value = '';
									keyInput.placeholder = res.data.has_key ? 'A key is saved - leave blank to keep it' : 'Enter API key';
								}
								if (clearBox) clearBox.checked = false;
								const statusEl = document.getElementById('psm_save_status_' + this.dataset.provider);
								if (statusEl) {
									statusEl.classList.remove('err');
									let suffix;
									if (wasCleared) {
										suffix = 'key removed';
									} else if (!res.data.has_key) {
										suffix = 'no key stored';
									} else if (typed === '') {
										suffix = 'saved key kept (field left blank)';
									} else {
										suffix = 'key stored (field cleared)';
									}
									statusEl.textContent = 'Saved at ' + new Date().toLocaleTimeString() + ' \u2014 ' + suffix;
								}
								const u = res.data.usage;
								const box = document.getElementById('psm_quota_box_' + u.provider);
								if (box) {
									box.innerHTML =
										'<strong>Daily Usage:</strong> ' + u.daily_used + ' / ' + u.daily_limit + ' (Left: ' + u.daily_remaining + ')<br>' +
										'<strong>Next Daily Reset:</strong> ' + u.next_daily_reset + '<br>' +
										'<strong>Weekly Usage:</strong> ' + u.weekly_used + ' / ' + u.weekly_limit + ' (Left: ' + u.weekly_remaining + ')<br>' +
										'<strong>Next Weekly Reset:</strong> ' + u.next_weekly_reset;
								}
							} else {
								const msg = res.data?.message || 'Save failed';
								showToast(msg, false);
								const statusEl = document.getElementById('psm_save_status_' + this.dataset.provider);
								if (statusEl) {
									statusEl.classList.add('err');
									statusEl.textContent = msg;
								}
							}
						})
						.catch(() => {
							showToast('Network error', false);
							const statusEl = document.getElementById('psm_save_status_' + this.dataset.provider);
							if (statusEl) {
								statusEl.classList.add('err');
								statusEl.textContent = 'Network error while saving.';
							}
						});
				});
			});
		})();
		</script>
		<?php
	}
}
