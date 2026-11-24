<?php

class PSM_Master_Question extends PSM_Master_Abstract {

	public function __construct() {
		$this->entity = 'question';
	}

	public function get_question_filters( $source ) {
		return array(
			'search'    => $this->get_search_filter( $source ),
			'domain_id' => absint( $source['question_domain'] ?? $source['domain_id'] ?? 0 ),
			'skill_id'  => absint( $source['question_skill'] ?? $source['skill_id'] ?? 0 ),
			'tag_id'    => absint( $source['question_tag'] ?? $source['tag_id'] ?? 0 ),
			'status'    => in_array( $source['question_status'] ?? $source['status'] ?? '', array( 'draft', 'published' ), true ) ? ( $source['question_status'] ?? $source['status'] ) : '',
		);
	}

	public function get_question_record( $question_id ) {
		global $wpdb;
		$question = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT q.*, s.name AS skill_name, s.domain_id, d.name AS difficulty_name FROM ' . PSM_AI_TABLE_PREFIX . 'questions q'
				. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = q.skill_id'
				. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'difficulties d ON d.id = q.difficulty WHERE q.id = %d',
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
				'SELECT t.slug FROM ' . PSM_AI_TABLE_PREFIX . 'question_tags qt'
				. ' INNER JOIN ' . PSM_AI_TABLE_PREFIX . 'tags t ON t.id = qt.tag_id'
				. ' WHERE qt.question_id = %d ORDER BY t.sort_order ASC, t.name ASC',
				$question_id
			)
		);
		return array(
			'id'            => (int) $question->id,
			'skill_id'      => (int) $question->skill_id,
			'domain_id'     => (int) $question->domain_id,
			'difficulty'    => (int) $question->difficulty,
			'question_type' => $question->question_type,
			'question_text' => $question->question_text,
			'code_snippet'  => $question->code_snippet,
			'code_language' => $question->code_language,
			'options'       => is_array( $options ) ? $options : array(),
			'correct_keys'  => is_array( $correct ) ? array_values( $correct ) : array(),
			'is_multiple'   => (int) $question->is_multiple,
			'explanation'   => $question->explanation,
			'status'        => $question->status,
			'external_id'   => $question->external_id,
			'tag_slugs'     => array_map( 'strval', $tag_rows ),
		);
	}

	public function get_question_neighbor_ids( $question, $filters ) {
		global $wpdb;
		list( $where, $args ) = $this->get_filter_sql( $filters );
		$table = $this->get_table_name();
		$previous = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT q.id FROM ' . $table . ' q ' . $where
				. ' AND (q.updated_at > %s OR (q.updated_at = %s AND q.id > %d)) ORDER BY q.updated_at DESC, q.id DESC LIMIT 1',
				array_merge( $args, array( $question->updated_at, $question->updated_at, $question->id ) )
			)
		);
		$next = (int) $wpdb->get_var(
			$wpdb->prepare(
				'SELECT q.id FROM ' . $table . ' q ' . $where
				. ' AND (q.updated_at < %s OR (q.updated_at = %s AND q.id < %d)) ORDER BY q.updated_at ASC, q.id ASC LIMIT 1',
				array_merge( $args, array( $question->updated_at, $question->updated_at, $question->id ) )
			)
		);
		return array( 'previous' => $previous, 'next' => $next );
	}

	protected function get_filters( $source ) {
		return $this->get_question_filters( $source );
	}

	protected function build_list_query( $data, $source ) {
		global $wpdb;
		list( $where, $args ) = $this->get_filter_sql( $data['filters'] );
		$table = $this->get_table_name();
		$count_sql = 'SELECT COUNT(*) FROM ' . $table . ' q ' . $where;
		$data['total_count'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		$data['filtered_count'] = (int) ( empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );
		$data['total_pages'] = max( 1, (int) ceil( $data['filtered_count'] / $data['per_page'] ) );
		$data['page'] = min( $data['total_pages'], max( 1, absint( $source['question_page'] ?? 1 ) ) );
		$offset = ( $data['page'] - 1 ) * $data['per_page'];
		$order_sql = 'SELECT q.id FROM ' . $table . ' q ' . $where . ' ORDER BY q.updated_at DESC, q.id DESC';
		$data['question_order'] = array_map( 'intval', empty( $args ) ? $wpdb->get_col( $order_sql ) : $wpdb->get_col( $wpdb->prepare( $order_sql, $args ) ) );
		$positions = array_flip( $data['question_order'] );
		$query = 'SELECT q.*, s.name AS skill_name, s.domain_id, d.name AS difficulty_name FROM ' . $table . ' q'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = q.skill_id'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'difficulties d ON d.id = q.difficulty '
			. $where . ' ORDER BY q.updated_at DESC, q.id DESC LIMIT %d, %d';
		$data['rows'] = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $args, array( $offset, $data['per_page'] ) ) ) );

		$question_ids = array();
		foreach ( $data['rows'] as $row ) {
			$question_ids[] = (int) $row->id;
		}
		$tags_by_question = array();
		if ( ! empty( $question_ids ) ) {
			$placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
			$tag_rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT qt.question_id, t.slug FROM ' . PSM_AI_TABLE_PREFIX . 'question_tags qt'
					. ' INNER JOIN ' . PSM_AI_TABLE_PREFIX . 'tags t ON t.id = qt.tag_id'
					. ' WHERE qt.question_id IN (' . $placeholders . ') ORDER BY t.sort_order ASC, t.name ASC',
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
	}

	protected function render_related_filters_html( $data ) {
		$skill_options = array( array( 'value' => '', 'label' => 'All skills' ) );
		foreach ( $data['skills'] as $skill ) {
			$skill_options[] = array(
				'value' => $skill->id,
				'label' => $skill->name,
				'attrs' => array( 'data-domain-id' => $skill->domain_id ),
			);
		}
		$tag_options = array( array( 'value' => '', 'label' => 'All tags' ) );
		foreach ( $data['tags'] as $tag ) {
			$tag_options[] = array(
				'value' => $tag->id,
				'label' => $tag->name . ' [' . $tag->slug . ']',
				'attrs' => array( 'data-skill-id' => $tag->skill_id ),
			);
		}
		$domain_options = array( array( 'value' => '', 'label' => 'All domains' ) );
		foreach ( $data['domains'] as $domain ) {
			$domain_options[] = array( 'value' => $domain->id, 'label' => $domain->name );
		}
		$html  = '<label class="psm-filter-field"><span>Domain</span>' . $this->psm_select_html(
			array(
				'name'     => 'question_domain',
				'id'       => 'psm_question_domain_filter',
				'selected' => $data['filters']['domain_id'],
				'options'  => $domain_options,
				'tooltip'  => 'Filter skills by domain',
			)
		) . '</label>';
		$html .= '<label class="psm-filter-field"><span>Skill</span>' . $this->psm_select_html(
			array(
				'name'     => 'question_skill',
				'id'       => 'psm_question_skill_filter',
				'selected' => $data['filters']['skill_id'],
				'options'  => $skill_options,
				'tooltip'  => 'Filter by skill',
			)
		) . '</label>';
		$html .= '<label class="psm-filter-field"><span>Tag</span>' . $this->psm_select_html(
			array(
				'name'     => 'question_tag',
				'id'       => 'psm_question_tag_filter',
				'selected' => $data['filters']['tag_id'],
				'options'  => $tag_options,
				'tooltip'  => 'Filter by a tag owned by the selected skill',
			)
		) . '</label>';
		$html .= '<label class="psm-filter-field"><span>Status</span>' . $this->psm_select_html(
			array(
				'name'     => 'question_status',
				'selected' => $data['filters']['status'],
				'options'  => array(
					array( 'value' => '', 'label' => 'All statuses' ),
					array( 'value' => 'draft', 'label' => 'Draft' ),
					array( 'value' => 'published', 'label' => 'Published' ),
				),
				'tooltip'  => 'Filter by draft or published status',
			)
		) . '</label>';
		return $html;
	}

	protected function get_table_headers() {
		return array(
			array( 'label' => 'ID', 'width' => '65px' ),
			array( 'label' => 'Question' ),
			array( 'label' => 'Skill' ),
			array( 'label' => 'Type' ),
			array( 'label' => 'Difficulty' ),
			array( 'label' => 'Correct' ),
			array( 'label' => 'Tags' ),
			array( 'label' => 'Status', 'width' => '90px' ),
			array( 'label' => 'Updated', 'width' => '140px' ),
			array( 'label' => 'Actions', 'width' => '110px' ),
		);
	}

	protected function render_table_row( $row ) {
		$plain = preg_replace( '/\s+/u', ' ', $row->question_text );
		$excerpt = mb_substr( $plain, 0, 180 );
		if ( mb_strlen( $plain ) > 180 ) {
			$excerpt .= '…';
		}
		$record = array(
			'id' => $row->id,
			'skill_id' => $row->skill_id,
			'domain_id' => $row->domain_id,
			'difficulty' => $row->difficulty,
			'question_type' => $row->question_type,
			'question_text' => $row->question_text,
			'code_snippet' => $row->code_snippet,
			'code_language' => $row->code_language,
			'options' => $row->options,
			'correct_keys' => $row->correct_keys,
			'is_multiple' => $row->is_multiple,
			'explanation' => $row->explanation,
			'status' => $row->status,
			'external_id' => $row->external_id,
			'tag_slugs' => $row->tag_slugs,
			'previous_id' => (int) $row->previous_id,
			'next_id' => (int) $row->next_id,
		);
		$type_labels = array( 'four_option' => 'Four Options (Single / Multiple)', 'two_option' => 'Two Options', 'true_false' => 'True / False' );
		$badge = 'published' === $row->status ? 'psm-badge-active' : 'psm-badge-draft';
		$html = '<tr id="psm-row-question-' . esc_attr( $row->id ) . '"><td><strong>#' . esc_html( $row->id ) . '</strong></td><td><strong>' . esc_html( $excerpt ) . '</strong></td><td><span class="psm-badge psm-badge-domain">' . esc_html( $row->skill_name ?: '—' ) . '</span></td><td><span class="psm-badge psm-badge-type">' . esc_html( $type_labels[ $row->question_type ] ?? $row->question_type ) . '</span></td><td>' . esc_html( $row->difficulty_name ?: $row->difficulty ) . '</td><td><code>' . esc_html( implode( ', ', $row->correct_keys ) ?: '—' ) . '</code>';
		if ( $row->is_multiple ) {
			$html .= '<br><small>Multi choice</small>';
		}
		$html .= '</td><td>';
		if ( empty( $row->tag_slugs ) ) {
			$html .= '—';
		} else {
			foreach ( $row->tag_slugs as $slug ) {
				$html .= '<span class="psm-badge psm-badge-type">' . esc_html( $slug ) . '</span> ';
			}
		}
		$updated = strtotime( $row->updated_at . ' UTC' );
		$html .= '</td><td><span class="psm-badge ' . esc_attr( $badge ) . '">' . esc_html( ucfirst( $row->status ) ) . '</span></td><td>' . esc_html( $updated ? wp_date( 'Y-m-d H:i', $updated ) : $row->updated_at ) . '</td><td>';
		$html .= '<button type="button" class="psm-icon-btn psm-btn-edit psm-open-modal" title="Edit question" data-tooltip="Edit question" data-entity="question" data-record="' . esc_attr( wp_json_encode( $record, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) ) . '"><span class="dashicons dashicons-edit" aria-hidden="true"></span></button>';
		$html .= '<button type="button" class="psm-icon-btn psm-btn-delete psm-ajax-delete" title="Delete question" data-tooltip="Delete question" data-entity="question" data-id="' . esc_attr( $row->id ) . '" data-name="' . esc_attr( $excerpt ) . '"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button></td></tr>';
		return $html;
	}

	protected function get_search_placeholder() {
		return 'Search question text';
	}

	protected function get_item_label() {
		return 'questions';
	}

	protected function get_table_name() {
		return PSM_AI_TABLE_PREFIX . 'questions';
	}

	private function get_filter_sql( $filters ) {
		global $wpdb;
		$where = ' WHERE 1=1';
		$args = array();
		if ( '' !== $filters['search'] ) {
			$where .= ' AND q.question_text LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
		}
		if ( $filters['domain_id'] > 0 ) {
			$where .= ' AND EXISTS (SELECT 1 FROM ' . PSM_AI_TABLE_PREFIX . 'skills sf WHERE sf.id = q.skill_id AND sf.domain_id = %d)';
			$args[] = $filters['domain_id'];
		}
		if ( $filters['skill_id'] > 0 ) {
			$where .= ' AND q.skill_id = %d';
			$args[] = $filters['skill_id'];
		}
		if ( $filters['tag_id'] > 0 ) {
			$where .= ' AND EXISTS (SELECT 1 FROM ' . PSM_AI_TABLE_PREFIX . 'question_tags qtf WHERE qtf.question_id = q.id AND qtf.tag_id = %d)';
			$args[] = $filters['tag_id'];
		}
		if ( '' !== $filters['status'] ) {
			$where .= ' AND q.status = %s';
			$args[] = $filters['status'];
		}
		return array( $where, $args );
	}
}
