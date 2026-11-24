<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request data is explicitly parsed or sanitized by the workflow that consumes it.

trait PSM_Admin_Master_CRUD_Trait {

	/**
	 * AJAX: Add or Edit any Master entity from Modal Popup
	 */
	public function ajax_save_master() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;
		$entity    = sanitize_key( wp_unslash( $_POST['entity'] ?? '' ) );
		$record_id = absint( $_POST['record_id'] ?? 0 );
		$now       = current_time( 'mysql', true );

		if ( 'question' === $entity ) {
			$this->save_question_master( $record_id, $now );
		}

		$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		$raw_slug    = sanitize_text_field( wp_unslash( $_POST['slug'] ?? '' ) );
		$description = sanitize_textarea_field( wp_unslash( $_POST['description'] ?? '' ) );
		$sort_order  = absint( $_POST['sort_order'] ?? 10 );
		$status      = sanitize_key( wp_unslash( $_POST['status'] ?? 'active' ) );
		$status      = in_array( $status, array( 'active', 'inactive' ), true ) ? $status : 'active';

		if ( 'batch' === $entity ) {
			$label_name = sanitize_text_field( wp_unslash( $_POST['label_name'] ?? '' ) );
			$b_status   = sanitize_key( wp_unslash( $_POST['status'] ?? 'completed' ) );
			$b_status   = in_array( $b_status, array( 'completed', 'processing', 'failed' ), true ) ? $b_status : 'completed';
			if ( ! $record_id || empty( $label_name ) ) {
				wp_send_json_error( array( 'message' => 'Batch ID and Label Name are required.' ) );
			}
			$updated = $wpdb->update(
				PSM_AI_TABLE_PREFIX . 'batches',
				array(
					'label_name' => $label_name,
					'status'     => $b_status,
					'updated_at' => $now,
				),
				array( 'id' => $record_id )
			);
			if ( false === $updated || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not update the batch.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Batch updated.' ) );
		}

		if ( empty( $name ) ) {
			wp_send_json_error( array( 'message' => 'Name is required.' ) );
		}

		$slug = ( 'question_type' === $entity )
			? sanitize_key( $raw_slug ? $raw_slug : $name )
			: PSM_Seeder::make_slug( $raw_slug, $name );

		if ( empty( $slug ) ) {
			wp_send_json_error( array( 'message' => 'Valid slug is required.' ) );
		}

		$table_map = array(
			'domain'        => PSM_AI_TABLE_PREFIX . 'domains',
			'skill_type'    => PSM_AI_TABLE_PREFIX . 'skill_types',
			'question_type' => PSM_AI_TABLE_PREFIX . 'question_types',
			'difficulty'    => PSM_AI_TABLE_PREFIX . 'difficulties',
			'skill'         => PSM_AI_TABLE_PREFIX . 'skills',
			'tag'           => PSM_AI_TABLE_PREFIX . 'tags',
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

		if ( 'domain' === $entity ) {
			$data['icon'] = sanitize_text_field( wp_unslash( $_POST['icon'] ?? 'dashicons-category' ) );
		} elseif ( 'skill' === $entity ) {
			$domain_id = absint( $_POST['domain_id'] ?? 0 );
			$parent_id = absint( $_POST['parent_skill_id'] ?? 0 );
			$type_id   = absint( $_POST['skill_type_id'] ?? 0 );
			$domain    = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . PSM_AI_TABLE_PREFIX . 'domains WHERE id = %d', $domain_id ) );
			if ( ! $domain ) {
				wp_send_json_error( array( 'message' => 'Select a valid Domain for the Skill.' ) );
			}
			$type_name = 'Subject';
			if ( $type_id ) {
				$skill_type = $wpdb->get_row( $wpdb->prepare( 'SELECT name FROM ' . PSM_AI_TABLE_PREFIX . 'skill_types WHERE id = %d', $type_id ) );
				if ( ! $skill_type ) {
					wp_send_json_error( array( 'message' => 'Select a valid Skill Type.' ) );
				}
				$type_name = (string) $skill_type->name;
			}

			if ( $record_id && $parent_id === $record_id ) {
				wp_send_json_error( array( 'message' => 'A skill cannot be its own parent.' ) );
			}
			if ( $parent_id ) {
				$parent = $wpdb->get_row( $wpdb->prepare( 'SELECT id, domain_id FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE id = %d', $parent_id ) );
				if ( ! $parent || (int) $parent->domain_id !== $domain_id ) {
					wp_send_json_error( array( 'message' => 'The parent Skill must belong to the selected Domain.' ) );
				}
			}

			$data['domain_id']       = $domain_id;
			$data['parent_id']       = $parent_id;
			$data['parent_skill_id'] = $parent_id ? $parent_id : null;
			$data['skill_type_id']   = $type_id;
			$data['skill_type']      = $type_name;
		} elseif ( 'tag' === $entity ) {
			$skill_id  = absint( $_POST['skill_id'] ?? 0 );
			$domain_id = absint( $_POST['tag_domain_id'] ?? 0 );
			$skill     = $wpdb->get_row( $wpdb->prepare( 'SELECT id, domain_id, slug, name FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE id = %d', $skill_id ) );
			if ( ! $skill ) {
				wp_send_json_error( array( 'message' => 'Select a valid parent Skill for the Tag.' ) );
			}
			if ( ! $domain_id ) {
				wp_send_json_error( array( 'message' => 'Select a Domain for the Tag.' ) );
			}
			if ( (int) $skill->domain_id !== $domain_id ) {
				wp_send_json_error( array( 'message' => 'The parent Skill does not belong to the selected Domain.' ) );
			}
			$slug = PSM_Importer::normalize_tag_slug( $raw_slug ? $raw_slug : $name, $skill->slug, $skill->name );
			if ( '' === $slug ) {
				wp_send_json_error( array( 'message' => 'Valid tag name is required.' ) );
			}
			if ( $slug !== $data['slug'] ) {
				$dup_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table WHERE slug = %s AND id != %d", $slug, $record_id ) );
				if ( $dup_id ) {
					wp_send_json_error( array( 'message' => "Slug '{$slug}' already exists. Slugs must be unique." ) );
				}
				$data['slug'] = $slug;
			}
			$data['skill_id'] = $skill_id;
		}

		if ( $record_id > 0 ) {
			$saved = $wpdb->update( $table, $data, array( 'id' => $record_id ) );
			if ( false === $saved || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not update the record.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => ucfirst( str_replace( '_', ' ', $entity ) ) . ' updated successfully.' ) );
		} else {
			$data['created_at'] = $now;
			$saved             = $wpdb->insert( $table, $data );
			if ( false === $saved || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not add the record.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => ucfirst( str_replace( '_', ' ', $entity ) ) . ' added successfully.' ) );
		}
	}

	private function save_question_master( $record_id, $now ) {
		global $wpdb;

		$skill_id  = absint( $_POST['skill_id'] ?? 0 );
		$domain_id = absint( $_POST['question_domain_id'] ?? 0 );
		$skill     = $skill_id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE id = %d', $skill_id ) ) : null;
		if ( ! $skill ) {
			wp_send_json_error( array( 'message' => 'Select a valid Skill for the question.' ) );
		}
		if ( ! $domain_id ) {
			wp_send_json_error( array( 'message' => 'Select a Domain for the question.' ) );
		}
		if ( (int) $skill->domain_id !== $domain_id ) {
			wp_send_json_error( array( 'message' => 'The selected Skill does not belong to the selected Domain.' ) );
		}

		$existing = null;
		if ( $record_id ) {
			$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . PSM_AI_TABLE_PREFIX . 'questions WHERE id = %d', $record_id ) );
			if ( ! $existing ) {
				wp_send_json_error( array( 'message' => 'Question not found.' ) );
			}
		}

		$status      = sanitize_key( wp_unslash( $_POST['question_status'] ?? 'draft' ) );
		$difficulty  = PSM_Importer::normalize_difficulty_id( wp_unslash( $_POST['difficulty'] ?? 1 ) );
		$raw_correct = array_map( 'sanitize_key', (array) wp_unslash( $_POST['correct_keys'] ?? array() ) );
		$item        = array(
			'question_text' => wp_unslash( $_POST['question_text'] ?? '' ),
			'question_type' => wp_unslash( $_POST['question_type'] ?? 'four_option' ),
			'difficulty'    => $difficulty,
			'code_snippet'  => wp_unslash( $_POST['code_snippet'] ?? '' ),
			'code_language' => wp_unslash( $_POST['code_language'] ?? '' ),
			'options'       => array(
				'A' => wp_unslash( $_POST['option_a'] ?? '' ),
				'B' => wp_unslash( $_POST['option_b'] ?? '' ),
				'C' => wp_unslash( $_POST['option_c'] ?? '' ),
				'D' => wp_unslash( $_POST['option_d'] ?? '' ),
			),
			'correct_keys'  => $raw_correct,
			'explanation'   => wp_unslash( $_POST['explanation'] ?? '' ),
			'status'        => $status,
			'external_id'   => wp_unslash( $_POST['external_id'] ?? '' ),
		);
		$inspected   = PSM_Importer::inspect_question( $item, $status, $difficulty );
		if ( empty( $inspected['data'] ) ) {
			wp_send_json_error(
				array(
					'message' => implode( ' ', $inspected['errors'] ),
					'errors'  => $inspected['errors'],
				)
			);
		}
		$data = $inspected['data'];

		$questions_table = PSM_AI_TABLE_PREFIX . 'questions';
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

		$tags_table          = PSM_AI_TABLE_PREFIX . 'tags';
		$question_tags_table = PSM_AI_TABLE_PREFIX . 'question_tags';
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

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not start the question transaction.' ) );
		}

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
			$saved                = $wpdb->insert( $questions_table, $stored );
		}
		if ( false === $saved ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not save the question.' ) );
		}

		$question_id = $existing ? $record_id : (int) $wpdb->insert_id;
		if ( false === $wpdb->delete( $question_tags_table, array( 'question_id' => $question_id ), array( '%d' ) ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not update question tags.' ) );
		}
		foreach ( array_values( array_unique( $tag_ids ) ) as $tag_id ) {
			if ( ! $wpdb->insert(
				$question_tags_table,
				array(
					'question_id' => $question_id,
					'tag_id'      => $tag_id,
				),
				array( '%d', '%d' )
			) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not link question tags.' ) );
			}
		}

		if ( false === $wpdb->query( 'COMMIT' ) ) {
			$wpdb->query( 'ROLLBACK' );
			wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not commit the question.' ) );
		}

		PSM_Cache::delete_all();
		$message = $existing ? 'Question updated successfully.' : 'Question added successfully.';
		if ( ! empty( $created_tags ) ) {
			$message .= ' Created tag(s): ' . implode( ', ', $created_tags ) . '.';
		}
		wp_send_json_success(
			array(
				'message'     => $message,
				'question_id' => $question_id,
			)
		);
	}

	public function ajax_get_question_master() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		global $wpdb;
		$question_id = absint( $_POST['question_id'] ?? 0 );
		$question    = $wpdb->get_row(
			$wpdb->prepare( 'SELECT id, updated_at FROM ' . PSM_AI_TABLE_PREFIX . 'questions WHERE id = %d', $question_id )
		);
		if ( ! $question ) {
			wp_send_json_error( array( 'message' => 'Question not found.' ) );
		}

		$question_master = $this->master_controller->get_question_master();
		$filters         = $question_master->get_question_filters( $_POST );
		$neighbors       = $question_master->get_question_neighbor_ids( $question, $filters );
		$record          = $question_master->get_question_record( $question_id );
		wp_send_json_success(
			array(
				'record'      => $record,
				'previous_id' => $neighbors['previous'],
				'next_id'     => $neighbors['next'],
			)
		);
	}

	private function get_question_filters( $source ) {
		return $this->master_controller->get_question_master()->get_question_filters( $source );
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
			$questions_table = PSM_AI_TABLE_PREFIX . 'questions';
			$exists          = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $questions_table WHERE id = %d", $record_id ) );
			if ( ! $exists ) {
				wp_send_json_error( array( 'message' => 'Question not found.' ) );
			}

			$wpdb->query( 'START TRANSACTION' );
			$unlinked_tags    = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'question_tags', array( 'question_id' => $record_id ), array( '%d' ) );
			$unlinked_batches = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'batch_questions', array( 'question_id' => $record_id ), array( '%d' ) );
			$deleted          = $wpdb->delete( $questions_table, array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $unlinked_tags || false === $unlinked_batches || false === $deleted || $wpdb->last_error ) {
				$wpdb->query( 'ROLLBACK' );
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the question.' ) );
			}
			if ( false === $wpdb->query( 'COMMIT' ) ) {
				$wpdb->query( 'ROLLBACK' );
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not commit the deletion.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Question and its tag and batch links deleted.' ) );
		}

		if ( 'skill' === $entity ) {
			$q_count     = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'questions WHERE skill_id = %d', $record_id )
			);
			$t_count     = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'tags WHERE skill_id = %d', $record_id )
			);
			$child_count = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE parent_id = %d OR parent_skill_id = %d',
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
				wp_send_json_error(
					array(
						'message' => 'Cannot delete Skill because it is linked to ' . implode( ', ', $reasons ) . '. Remove or reassign them first.',
					)
				);
			}

			$deleted = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'skills', array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $deleted || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the skill.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Skill deleted successfully.' ) );
		}

		if ( 'tag' === $entity ) {
			$q_count = (int) $wpdb->get_var(
				$wpdb->prepare( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'question_tags WHERE tag_id = %d', $record_id )
			);
			if ( $q_count > 0 ) {
				wp_send_json_error(
					array(
						'message' => "Cannot delete Tag because {$q_count} question(s) are assigned to it. Unlink those questions first.",
					)
				);
			}

			$deleted = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'tags', array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $deleted || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the tag.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Tag deleted successfully.' ) );
		}

		if ( 'domain' === $entity ) {
			$s_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE domain_id = %d', $record_id ) );
			if ( $s_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Domain because {$s_count} skill(s) belong to it." ) );
			}
			$deleted = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'domains', array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $deleted || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the domain.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Domain deleted successfully.' ) );
		}

		if ( 'skill_type' === $entity ) {
			$s_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'skills WHERE skill_type_id = %d', $record_id ) );
			if ( $s_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Skill Type because {$s_count} skill(s) use it." ) );
			}
			$deleted = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'skill_types', array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $deleted || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the skill type.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Skill Type deleted successfully.' ) );
		}

		if ( 'question_type' === $entity ) {
			$slug    = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT slug FROM ' . PSM_AI_TABLE_PREFIX . 'question_types WHERE id = %d', $record_id ) );
			$q_count = $slug ? (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'questions WHERE question_type = %s', $slug ) ) : 0;
			if ( $q_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Question Type because {$q_count} question(s) use slug '{$slug}'." ) );
			}
			$deleted = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'question_types', array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $deleted || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the question type.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Question Type deleted successfully.' ) );
		}

		if ( 'difficulty' === $entity ) {
			$q_count = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'questions WHERE difficulty = %d', $record_id ) );
			if ( $q_count > 0 ) {
				wp_send_json_error( array( 'message' => "Cannot delete Difficulty because {$q_count} question(s) reference ID {$record_id}." ) );
			}
			$deleted = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'difficulties', array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $deleted || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the difficulty.' ) );
			}
			PSM_Cache::delete_all();
			wp_send_json_success( array( 'message' => 'Difficulty deleted successfully.' ) );
		}

		if ( 'batch' === $entity ) {
			$unlinked = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'batch_questions', array( 'batch_id' => $record_id ), array( '%d' ) );
			$deleted  = $wpdb->delete( PSM_AI_TABLE_PREFIX . 'batches', array( 'id' => $record_id ), array( '%d' ) );
			if ( false === $unlinked || false === $deleted || $wpdb->last_error ) {
				wp_send_json_error( array( 'message' => $wpdb->last_error ? $wpdb->last_error : 'Could not delete the batch.' ) );
			}
			PSM_Cache::delete_all();
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
		set_transient(
			'psm_admin_notice_' . get_current_user_id(),
			array(
				'type' => 'success',
				'text' => sprintf(
					'Idempotent seed completed with descriptions & fixed slugs: %d new domains, %d new skills, %d new tags added. Existing records preserved.',
					$summary['domains'],
					$summary['skills'],
					$summary['tags']
				),
			),
			60
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'psm-ai-importer',
					'tab'  => 'masters',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
