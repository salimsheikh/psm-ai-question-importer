<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Identifiers and placeholder lists are built dynamically from fixed tables and prepared filter arrays.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.

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
		$question_id = absint( $question_id );
		$record      = PSM_Cache::get_or_create(
			'psm_master_question_record',
			array(
				'entity'      => $this->entity,
				'table'       => $this->get_table_name(),
				'question_id' => $question_id,
			),
			static function () use ( $wpdb, $question_id ) {
				$question = PSM_Cache::get_row(
					$wpdb->prepare(
						'SELECT q.*, s.name AS skill_name, s.domain_id, d.name AS difficulty_name FROM ' . PSM_AI_TABLE_PREFIX . 'questions q'
						. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = q.skill_id'
						. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'difficulties d ON d.id = q.difficulty WHERE q.id = %d',
						$question_id
					),
					array(),
					false
				);
				if ( ! $question ) {
					return false;
				}
				$tag_rows = PSM_Cache::get_col(
					$wpdb->prepare(
						'SELECT t.slug FROM ' . PSM_AI_TABLE_PREFIX . 'question_tags qt'
						. ' INNER JOIN ' . PSM_AI_TABLE_PREFIX . 'tags t ON t.id = qt.tag_id'
						. ' WHERE qt.question_id = %d ORDER BY t.sort_order ASC, t.name ASC',
						$question_id
					),
					array(),
					false
				);
				return array(
					'question' => $question,
					'tag_rows' => $tag_rows,
				);
			},
			PSM_Cache::EXPIRATION
		);
		if ( ! $record ) {
			return null;
		}
		$question = $record['question'];
		$options  = json_decode( $question->options, true );
		$correct  = json_decode( $question->correct_keys, true );
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
			'tag_slugs'     => array_map( 'strval', $record['tag_rows'] ),
		);
	}

	public function get_question_neighbor_ids( $question, $filters ) {
		global $wpdb;
		list( $where, $args ) = $this->get_filter_sql( $filters );
		$table                = $this->get_table_name();
		$neighbor_ids         = PSM_Cache::get_or_create(
			'psm_master_question_neighbor_ids',
			array(
				'entity'      => $this->entity,
				'table'       => $table,
				'question_id' => absint( $question->id ),
				'updated_at'  => $question->updated_at,
				'filters'     => $filters,
				'args'        => $args,
			),
			static function () use ( $wpdb, $table, $where, $args, $question ) {
				$previous = PSM_Cache::get_var(
					$wpdb->prepare(
						'SELECT q.id FROM ' . $table . ' q ' . $where
						. ' AND (q.updated_at > %s OR (q.updated_at = %s AND q.id > %d)) ORDER BY q.updated_at DESC, q.id DESC LIMIT 1',
						array_merge( $args, array( $question->updated_at, $question->updated_at, $question->id ) )
					),
					array(),
					false
				);
				$next     = PSM_Cache::get_var(
					$wpdb->prepare(
						'SELECT q.id FROM ' . $table . ' q ' . $where
						. ' AND (q.updated_at < %s OR (q.updated_at = %s AND q.id < %d)) ORDER BY q.updated_at ASC, q.id ASC LIMIT 1',
						array_merge( $args, array( $question->updated_at, $question->updated_at, $question->id ) )
					),
					array(),
					false
				);
				return array(
					'previous' => $previous,
					'next'     => $next,
				);
			},
			PSM_Cache::EXPIRATION
		);
		return array(
			'previous' => (int) $neighbor_ids['previous'],
			'next'     => (int) $neighbor_ids['next'],
		);
	}

	protected function get_filters( $source ) {
		return $this->get_question_filters( $source );
	}

	protected function build_list_query( $data, $source ) {
		global $wpdb;
		list( $where, $args )   = $this->get_filter_sql( $data['filters'] );
		$table                  = $this->get_table_name();
		$count_sql              = 'SELECT COUNT(*) FROM ' . $table . ' q ' . $where;
		$total_context          = array(
			'entity' => $this->entity,
			'table'  => $table,
		);
		$list_context           = array_merge(
			$total_context,
			array(
				'filters' => $data['filters'],
				'args'    => $args,
			)
		);
		$data['total_count']    = (int) PSM_Cache::get_or_create(
			'psm_master_list_total_count',
			$total_context,
			static function () use ( $wpdb, $table ) {
				return PSM_Cache::get_var( 'SELECT COUNT(*) FROM ' . $table, array(), false );
			},
			PSM_Cache::EXPIRATION
		);
		$data['filtered_count'] = (int) PSM_Cache::get_or_create(
			'psm_master_list_filtered_count',
			$list_context,
			static function () use ( $wpdb, $count_sql, $args ) {
				return empty( $args ) ? PSM_Cache::get_var( $count_sql, array(), false ) : PSM_Cache::get_var( $wpdb->prepare( $count_sql, $args ), array(), false );
			},
			PSM_Cache::EXPIRATION
		);
		$data['total_pages']    = max( 1, (int) ceil( $data['filtered_count'] / $data['per_page'] ) );
		$data['page']           = min( $data['total_pages'], max( 1, absint( $source['question_page'] ?? 1 ) ) );
		$offset                 = ( $data['page'] - 1 ) * $data['per_page'];
		$order_sql              = 'SELECT q.id FROM ' . $table . ' q ' . $where . ' ORDER BY q.updated_at DESC, q.id DESC';
		$question_order         = PSM_Cache::get_or_create(
			'psm_master_question_order',
			$list_context,
			static function () use ( $wpdb, $order_sql, $args ) {
				return empty( $args ) ? PSM_Cache::get_col( $order_sql, array(), false ) : PSM_Cache::get_col( $wpdb->prepare( $order_sql, $args ), array(), false );
			},
			PSM_Cache::EXPIRATION
		);
		$data['question_order'] = array_map( 'intval', $question_order );
		$positions              = array_flip( $data['question_order'] );
		$query                  = 'SELECT q.*, s.name AS skill_name, s.domain_id, d.name AS difficulty_name FROM ' . $table . ' q'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = q.skill_id'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'difficulties d ON d.id = q.difficulty '
			. $where . ' ORDER BY q.updated_at DESC, q.id DESC LIMIT %d, %d';
		$page_context           = array_merge(
			$list_context,
			array(
				'page'     => $data['page'],
				'per_page' => $data['per_page'],
			)
		);
		$data['rows']           = PSM_Cache::get_or_create(
			'psm_master_list_rows',
			$page_context,
			static function () use ( $wpdb, $query, $args, $offset, $data ) {
				return PSM_Cache::get_results( $wpdb->prepare( $query, array_merge( $args, array( $offset, $data['per_page'] ) ) ), array(), false );
			},
			PSM_Cache::EXPIRATION
		);
		$data['rows']           = array_map(
			static function ( $row ) {
				return clone $row;
			},
			$data['rows']
		);

		$question_ids = array();
		foreach ( $data['rows'] as $row ) {
			$question_ids[] = (int) $row->id;
		}
		$tags_context     = array_merge(
			$page_context,
			array( 'question_ids' => $question_ids )
		);
		$tag_rows         = PSM_Cache::get_or_create(
			'psm_master_question_page_tag_slugs',
			$tags_context,
			static function () use ( $wpdb, $question_ids ) {
				if ( empty( $question_ids ) ) {
					return array();
				}
				$placeholders = implode( ',', array_fill( 0, count( $question_ids ), '%d' ) );
				return PSM_Cache::get_results(
					$wpdb->prepare(
						'SELECT qt.question_id, t.slug FROM ' . PSM_AI_TABLE_PREFIX . 'question_tags qt'
						. ' INNER JOIN ' . PSM_AI_TABLE_PREFIX . 'tags t ON t.id = qt.tag_id'
						. ' WHERE qt.question_id IN (' . $placeholders . ') ORDER BY t.sort_order ASC, t.name ASC',
						$question_ids
					),
					array(),
					false
				);
			},
			PSM_Cache::EXPIRATION
		);
		$tags_by_question = array();
		foreach ( $tag_rows as $tag_row ) {
			$tags_by_question[ (int) $tag_row->question_id ][] = $tag_row->slug;
		}
		foreach ( $data['rows'] as $row ) {
			$options           = json_decode( $row->options, true );
			$correct           = json_decode( $row->correct_keys, true );
			$row->options      = is_array( $options ) ? $options : array();
			$row->correct_keys = is_array( $correct ) ? array_values( $correct ) : array();
			$row->tag_slugs    = $tags_by_question[ (int) $row->id ] ?? array();
			$position          = $positions[ (int) $row->id ] ?? 0;
			$row->previous_id  = $position > 0 ? (int) $data['question_order'][ $position - 1 ] : 0;
			$row->next_id      = isset( $data['question_order'][ $position + 1 ] ) ? (int) $data['question_order'][ $position + 1 ] : 0;
		}
		return $data;
	}

	protected function render_related_filters_html( $data ) {
		$skill_options = array(
			array(
				'value' => '',
				'label' => 'All skills',
			),
		);
		foreach ( $data['skills'] as $skill ) {
			$skill_options[] = array(
				'value' => $skill->id,
				'label' => $skill->name,
				'attrs' => array( 'data-domain-id' => $skill->domain_id ),
			);
		}
		$tag_options = array(
			array(
				'value' => '',
				'label' => 'All tags',
			),
		);
		foreach ( $data['tags'] as $tag ) {
			$tag_options[] = array(
				'value' => $tag->id,
				'label' => $tag->name . ' [' . $tag->slug . ']',
				'attrs' => array( 'data-skill-id' => $tag->skill_id ),
			);
		}
		$domain_options = array(
			array(
				'value' => '',
				'label' => 'All domains',
			),
		);
		foreach ( $data['domains'] as $domain ) {
			$domain_options[] = array(
				'value' => $domain->id,
				'label' => $domain->name,
			);
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
					array(
						'value' => '',
						'label' => 'All statuses',
					),
					array(
						'value' => 'draft',
						'label' => 'Draft',
					),
					array(
						'value' => 'published',
						'label' => 'Published',
					),
				),
				'tooltip'  => 'Filter by draft or published status',
			)
		) . '</label>';
		return $html;
	}

	protected function get_table_headers() {
		return array(
			array(
				'label' => 'ID',
				'width' => '65px',
			),
			array( 'label' => 'Question' ),
			array( 'label' => 'Skill' ),
			array( 'label' => 'Type' ),
			array(
				'label' => 'Difficulty',
				'width' => '90px',
			),
			array( 'label' => 'Correct' ),
			array( 'label' => 'Tags' ),
			array(
				'label' => 'Status',
				'width' => '90px',
			),
			array(
				'label' => 'Updated',
				'width' => '140px',
			),
			array(
				'label' => 'Actions',
				'width' => '110px',
			),
		);
	}

	protected function render_table_row( $row ) {
		$plain   = preg_replace( '/\s+/u', ' ', $row->question_text );
		$excerpt = mb_substr( $plain, 0, 180 );
		if ( mb_strlen( $plain ) > 180 ) {
			$excerpt .= '…';
		}
		$record      = array(
			'id'            => $row->id,
			'skill_id'      => $row->skill_id,
			'domain_id'     => $row->domain_id,
			'difficulty'    => $row->difficulty,
			'question_type' => $row->question_type,
			'question_text' => $row->question_text,
			'code_snippet'  => $row->code_snippet,
			'code_language' => $row->code_language,
			'options'       => $row->options,
			'correct_keys'  => $row->correct_keys,
			'is_multiple'   => $row->is_multiple,
			'explanation'   => $row->explanation,
			'status'        => $row->status,
			'external_id'   => $row->external_id,
			'tag_slugs'     => $row->tag_slugs,
			'previous_id'   => (int) $row->previous_id,
			'next_id'       => (int) $row->next_id,
		);
		$type_labels = array(
			'four_option' => 'Four Options (Single / Multiple)',
			'two_option'  => 'Two Options',
			'true_false'  => 'True / False',
		);
		$badge            = 'published' === $row->status ? 'psm-badge-active' : 'psm-badge-draft';
		$difficulty_id    = (int) $row->difficulty;
		$difficulty_name  = $row->difficulty_name ? $row->difficulty_name : 'Unknown difficulty';
		$difficulty_class = in_array( $difficulty_id, array( 1, 2, 3 ), true ) ? 'psm-difficulty-' . $difficulty_id : 'psm-difficulty-unknown';
		$difficulty_html  = '<span class="psm-difficulty-badge ' . esc_attr( $difficulty_class ) . '" data-tooltip="' . esc_attr( $difficulty_name ) . '" aria-label="Difficulty ' . esc_attr( $difficulty_id ) . ': ' . esc_attr( $difficulty_name ) . '">' . esc_html( $difficulty_id ) . '</span>';
		$html             = '<tr id="psm-row-question-' . esc_attr( $row->id ) . '"><td><strong>#' . esc_html( $row->id ) . '</strong></td><td><strong>' . esc_html( $excerpt ) . '</strong></td><td><span class="psm-badge psm-badge-domain">' . esc_html( $row->skill_name ? $row->skill_name : '—' ) . '</span></td><td><span class="psm-badge psm-badge-type">' . esc_html( $type_labels[ $row->question_type ] ?? $row->question_type ) . '</span></td><td>' . $difficulty_html . '</td><td><code>' . esc_html( implode( ', ', $row->correct_keys ) ? implode( ', ', $row->correct_keys ) : '—' ) . '</code>';
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
		$html   .= '</td><td><span class="psm-badge ' . esc_attr( $badge ) . '">' . esc_html( ucfirst( $row->status ) ) . '</span></td><td>' . esc_html( $updated ? wp_date( 'Y-m-d H:i', $updated ) : $row->updated_at ) . '</td><td>';
		$html   .= $this->psm_button_html(
			'',
			'psm-icon-btn psm-btn-edit psm-open-modal',
			array(
				'data-tooltip' => 'Edit question',
				'data-entity'  => 'question',
				'data-record'  => (string) wp_json_encode( $record, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ),
			),
			'dashicons-edit',
			''
		);
		$html   .= $this->psm_button_html(
			'',
			'psm-icon-btn psm-btn-delete psm-ajax-delete',
			array(
				'data-tooltip' => 'Delete question',
				'data-entity'  => 'question',
				'data-id'      => $row->id,
				'data-name'    => $excerpt,
			),
			'dashicons-trash',
			''
		) . '</td></tr>';
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
		$args  = array();
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
