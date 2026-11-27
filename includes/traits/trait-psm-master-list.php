<?php
/**
 * Shared master-list data and rendering workflow.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; all query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.

trait PSM_Master_List_Trait {

	public function get_list_data( $source ) {
		global $wpdb;
		$data = array(
			'entity'         => $this->entity,
			'filters'        => $this->get_filters( $source ),
			'rows'           => array(),
			'total_count'    => 0,
			'filtered_count' => 0,
			'page'           => 1,
			'total_pages'    => 1,
			'per_page'       => $this->psm_get_per_page(),
			'question_order' => array(),
			'skills'         => PSM_Cache::get_or_create(
				'psm_master_selector_skills',
				array( 'table' => PSM_AI_TABLE_PREFIX . 'skills' ),
				static function () use ( $wpdb ) {
					return PSM_Cache::get_results( 'SELECT id, name, slug, domain_id, skill_type_id, parent_id FROM ' . PSM_AI_TABLE_PREFIX . 'skills ORDER BY name ASC', array(), false );
				},
				PSM_Cache::get_expiration()
			),
			'domains'        => PSM_Cache::get_or_create(
				'psm_master_selector_domains',
				array( 'table' => PSM_AI_TABLE_PREFIX . 'domains' ),
				static function () use ( $wpdb ) {
					return PSM_Cache::get_results( 'SELECT id, name FROM ' . PSM_AI_TABLE_PREFIX . 'domains ORDER BY sort_order ASC, name ASC', array(), false );
				},
				PSM_Cache::get_expiration()
			),
			'skill_types'    => PSM_Cache::get_or_create(
				'psm_master_selector_skill_types',
				array( 'table' => PSM_AI_TABLE_PREFIX . 'skill_types' ),
				static function () use ( $wpdb ) {
					return PSM_Cache::get_results( 'SELECT id, name FROM ' . PSM_AI_TABLE_PREFIX . 'skill_types ORDER BY sort_order ASC, name ASC', array(), false );
				},
				PSM_Cache::get_expiration()
			),
			'tags'           => PSM_Cache::get_or_create(
				'psm_master_selector_tags',
				array( 'table' => PSM_AI_TABLE_PREFIX . 'tags' ),
				static function () use ( $wpdb ) {
					return PSM_Cache::get_results( 'SELECT id, name, slug, skill_id, status FROM ' . PSM_AI_TABLE_PREFIX . 'tags ORDER BY name ASC', array(), false );
				},
				PSM_Cache::get_expiration()
			),
		);
		if ( 'question' === $this->entity ) {
			$this->add_temporary_question_references( $data );
		}
		$data = $this->build_list_query( $data, $source );
		return $data;
	}

	private function add_temporary_question_references( &$data ) {
		global $wpdb;
		$missing = PSM_Cache::get_or_create(
			'psm_master_question_missing_references',
			array(
				'questions_table' => PSM_AI_TABLE_PREFIX . 'questions',
				'skills_table'    => PSM_AI_TABLE_PREFIX . 'skills',
				'tags_table'      => PSM_AI_TABLE_PREFIX . 'tags',
				'question_tags'   => PSM_AI_TABLE_PREFIX . 'question_tags',
			),
			static function () use ( $wpdb ) {
				$skills = PSM_Cache::get_results(
					'SELECT q.skill_id AS id, 0 AS domain_id
					 FROM ' . PSM_AI_TABLE_PREFIX . 'questions q
					 LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = q.skill_id
					 WHERE s.id IS NULL AND q.skill_id > 0
					 GROUP BY q.skill_id ORDER BY q.skill_id ASC',
					array(),
					false
				);
				$tags   = PSM_Cache::get_results(
					'SELECT qt.tag_id AS id
					 FROM ' . PSM_AI_TABLE_PREFIX . 'question_tags qt
					 INNER JOIN ' . PSM_AI_TABLE_PREFIX . 'questions q ON q.id = qt.question_id
					 LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'tags t ON t.id = qt.tag_id
					 WHERE t.id IS NULL AND qt.tag_id > 0
					 GROUP BY qt.tag_id ORDER BY qt.tag_id ASC',
					array(),
					false
				);
				return array(
					'skills' => $skills,
					'tags'   => $tags,
				);
			},
			PSM_Cache::get_expiration()
		);

		$skill_ids = array();
		foreach ( $data['skills'] as $skill ) {
			$skill_ids[] = (int) $skill->id;
		}
		foreach ( $missing['skills'] as $skill ) {
			$id = (int) $skill->id;
			if ( in_array( $id, $skill_ids, true ) ) {
				continue;
			}
			$data['skills'][] = (object) array(
				'id'            => $id,
				'name'          => 'Missing skill #' . $id . ' (temporary)',
				'slug'          => 'missing-skill-' . $id,
				'domain_id'     => (int) $skill->domain_id,
				'skill_type_id' => 0,
				'parent_id'     => 0,
				'temporary'     => true,
			);
			$skill_ids[]      = $id;
		}

		$tag_ids = array();
		foreach ( $data['tags'] as $tag ) {
			$tag_ids[] = (int) $tag->id;
		}
		foreach ( $missing['tags'] as $tag ) {
			$id = (int) $tag->id;
			if ( in_array( $id, $tag_ids, true ) ) {
				continue;
			}
			$data['tags'][] = (object) array(
				'id'        => $id,
				'name'      => 'Missing tag #' . $id . ' (temporary)',
				'slug'      => 'missing-tag-' . $id,
				'skill_id'  => 0,
				'status'    => 'inactive',
				'temporary' => true,
			);
			$tag_ids[]   = $id;
		}
	}

	public function render_list_html( $data ) {
		$pagination = $this->psm_pagination_html( $data['page'], $data['total_pages'], $data['filtered_count'], $data['per_page'], $this->entity . '_page', $this->get_item_label() );
		$html       = $this->render_filters_html( $data ) . $this->render_table_html( $data );
		$html      .= '<div class="psm-master-list-footer">' . $pagination . '</div>';
		return '<div class="psm-master-list" id="psm_master_list" data-entity="' . esc_attr( $this->entity ) . '">' . $html . '</div>';
	}

	public function render_filters_html( $data ) {
		$filters        = $data['filters'];
		$compact        = in_array( $this->entity, array( 'question', 'tag' ), true );
		$html           = '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '" class="psm-filter-bar' . ( $compact ? ' psm-filter-compact' : '' ) . '" id="psm_master_filters" data-entity="' . esc_attr( $this->entity ) . '">';
		$html          .= '<input type="hidden" name="page" value="psm-ai-importer"><input type="hidden" name="tab" value="masters"><input type="hidden" name="entity" value="' . esc_attr( $this->entity ) . '">';
		$html          .= '<label class="psm-filter-field psm-filter-search"><span>' . esc_html( 'tag' === $this->entity ? 'Tag name or slug' : 'Search' ) . '</span>';
		$html          .= $this->psm_text_input_html(
			array(
				'name'        => $this->entity . '_search',
				'value'       => $filters['search'],
				'type'        => 'search',
				'placeholder' => $this->get_search_placeholder(),
				'tooltip'     => 'tag' === $this->entity ? 'Use * for any characters and ? for one character' : 'Search by name',
			)
		);
		$filter_button = $this->psm_button_html(
			'Filter',
			'psm-btn-primary',
			array(
				'type'         => 'submit',
				'data-tooltip' => 'Apply filters',
			),
			'dashicons-filter'
		);
		$reset_button  = $this->psm_button_html(
			'Reset',
			'psm-btn-reset',
			array(
				'data-psm-reset-filters' => true,
				'data-tooltip'           => 'Clear all filters',
			)
		);
		$html         .= '</label>' . $this->render_related_filters_html( $data );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Related filters and shared button components escape their output.
		$html .= '<div class="psm-filter-actions">' . $filter_button . $reset_button . '</div></form>';
		return $html;
	}

	public function render_table_html( $data ) {
		$html = '<table class="widefat striped" id="psm_master_table"><thead><tr>';
		foreach ( $this->get_table_headers() as $header ) {
			$html .= '<th' . ( isset( $header['width'] ) ? ' style="width:' . esc_attr( $header['width'] ) . '"' : '' ) . '>' . esc_html( $header['label'] ) . '</th>';
		}
		$html .= '</tr></thead><tbody>';
		if ( empty( $data['rows'] ) ) {
			$html .= '<tr><td colspan="' . esc_attr( count( $this->get_table_headers() ) ) . '" class="psm-empty-state">No records match the selected filters.</td></tr>';
		} else {
			foreach ( $data['rows'] as $row ) {
				$html .= $this->render_table_row( $row );
			}
		}
		return $html . '</tbody></table>';
	}

	public function get_record_count() {
		global $wpdb;
		$table = $this->get_table_name();
		return (int) PSM_Cache::get_or_create(
			'psm_master_record_count',
			array(
				'entity' => $this->entity,
				'table'  => $table,
			),
			static function () use ( $wpdb, $table ) {
				return PSM_Cache::get_var( 'SELECT COUNT(*) FROM ' . $table, array(), false );
			},
			PSM_Cache::get_expiration()
		);
	}

	abstract protected function get_filters( $source );

	abstract protected function build_list_query( $data, $source );

	abstract protected function render_related_filters_html( $data );

	abstract protected function get_table_headers();

	abstract protected function render_table_row( $row );

	abstract protected function get_search_placeholder();

	abstract protected function get_item_label();

	abstract protected function get_table_name();
}
