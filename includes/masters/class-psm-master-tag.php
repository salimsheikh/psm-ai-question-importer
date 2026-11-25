<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table identifiers use fixed constants; all filter values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.

class PSM_Master_Tag extends PSM_Master_Abstract {

	public function __construct() {
		$this->entity = 'tag';
	}

	protected function get_filters( $source ) {
		return array(
			'search'    => $this->get_search_filter( $source ),
			'domain_id' => absint( $source['tag_domain'] ?? 0 ),
			'skill_id'  => absint( $source['tag_skill'] ?? 0 ),
		);
	}

	protected function build_list_query( $data, $source ) {
		global $wpdb;
		$where = ' WHERE 1=1';
		$args  = array();
		if ( '' !== $data['filters']['search'] ) {
			$pattern = str_replace( array( '*', '?' ), array( '%', '_' ), $wpdb->esc_like( $data['filters']['search'] ) );
			$where  .= ' AND (t.name LIKE %s OR t.slug LIKE %s)';
			$args[]  = '%' . $pattern . '%';
			$args[]  = '%' . $pattern . '%';
		}
		if ( $data['filters']['domain_id'] > 0 ) {
			$where .= ' AND s.domain_id = %d';
			$args[] = $data['filters']['domain_id'];
		}
		if ( $data['filters']['skill_id'] > 0 ) {
			$where .= ' AND t.skill_id = %d';
			$args[] = $data['filters']['skill_id'];
		}
		$from                   = 'FROM ' . PSM_AI_TABLE_PREFIX . 'tags t'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = t.skill_id'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'question_tags qt ON qt.tag_id = t.id ';
		$count_sql              = 'SELECT COUNT(DISTINCT t.id) ' . $from . $where;
		$table                  = $this->get_table_name();
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
		$data['page']           = min( $data['total_pages'], max( 1, absint( $source['tag_page'] ?? 1 ) ) );
		$offset                 = ( $data['page'] - 1 ) * $data['per_page'];
		$query                  = 'SELECT t.*, s.name AS skill_name, s.domain_id, COUNT(qt.question_id) AS question_count ' . $from . $where . ' GROUP BY t.id ORDER BY t.sort_order ASC, t.name ASC LIMIT %d, %d';
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
		return $data;
	}

	protected function render_related_filters_html( $data ) {
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
		$options = array(
			array(
				'value' => '',
				'label' => 'All skills',
			),
		);
		foreach ( $data['skills'] as $skill ) {
			$options[] = array(
				'value' => $skill->id,
				'label' => $skill->name,
				'attrs' => array( 'data-domain-id' => $skill->domain_id ),
			);
		}
		$html  = '<label class="psm-filter-field"><span>Domain</span>' . $this->psm_select_html(
			array(
				'name'     => 'tag_domain',
				'id'       => 'psm_tag_domain_filter',
				'selected' => $data['filters']['domain_id'],
				'options'  => $domain_options,
				'tooltip'  => 'Filter skills by domain',
			)
		) . '</label>';
		$html .= '<label class="psm-filter-field"><span>Skill</span>' . $this->psm_select_html(
			array(
				'name'     => 'tag_skill',
				'id'       => 'psm_tag_skill_filter',
				'selected' => $data['filters']['skill_id'],
				'options'  => $options,
				'tooltip'  => 'Filter tags by their owning skill',
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
			array( 'label' => 'Name' ),
			array( 'label' => 'Slug' ),
			array( 'label' => 'Skill' ),
			array( 'label' => 'Description' ),
			array(
				'label' => 'Status',
				'width' => '90px',
			),
			array(
				'label' => 'Actions',
				'width' => '110px',
			),
		);
	}

	protected function render_table_row( $row ) {
		$record = wp_json_encode( $row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$name   = $row->name . ' (#' . $row->id . ')';
		$html   = '<tr><td><strong>#' . esc_html( $row->id ) . '</strong></td><td>' . esc_html( $row->name ) . '</td><td><code>' . esc_html( $row->slug ) . '</code></td><td>' . esc_html( $row->skill_name ) . '</td><td>' . esc_html( $row->description ) . '</td><td>' . esc_html( ucfirst( $row->status ) ) . '</td><td>';
		$html  .= $this->psm_button_html(
			'',
			'psm-icon-btn psm-btn-edit psm-open-modal',
			array(
				'data-entity'  => 'tag',
				'data-record'  => (string) $record,
				'aria-label'   => 'Edit ' . $name,
				'data-tooltip' => 'Edit record',
			),
			'dashicons-edit',
			''
		) . ' ';
		$html  .= $this->psm_button_html(
			'',
			'psm-icon-btn psm-btn-delete psm-ajax-delete',
			array(
				'data-entity'  => 'tag',
				'data-id'      => $row->id,
				'data-name'    => $name,
				'aria-label'   => 'Delete ' . $name,
				'data-tooltip' => 'Delete record',
			),
			'dashicons-trash',
			''
		);
		return $html . '</td></tr>';
	}

	protected function get_search_placeholder() {
		return 'Example: csharp-* or *oop';
	}

	protected function get_item_label() {
		return 'tags';
	}

	protected function get_table_name() {
		return PSM_AI_TABLE_PREFIX . 'tags';
	}
}
