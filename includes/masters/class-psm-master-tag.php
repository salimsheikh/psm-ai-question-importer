<?php

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
		$from = 'FROM ' . PSM_AI_TABLE_PREFIX . 'tags t'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = t.skill_id'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'question_tags qt ON qt.tag_id = t.id ';
		$count_sql = 'SELECT COUNT(DISTINCT t.id) ' . $from . $where;
		$data['total_count'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $this->get_table_name() );
		$data['filtered_count'] = (int) ( empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );
		$data['total_pages'] = max( 1, (int) ceil( $data['filtered_count'] / $data['per_page'] ) );
		$data['page'] = min( $data['total_pages'], max( 1, absint( $source['tag_page'] ?? 1 ) ) );
		$offset = ( $data['page'] - 1 ) * $data['per_page'];
		$query = 'SELECT t.*, s.name AS skill_name, s.domain_id, COUNT(qt.question_id) AS question_count ' . $from . $where . ' GROUP BY t.id ORDER BY t.sort_order ASC, t.name ASC LIMIT %d, %d';
		$data['rows'] = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $args, array( $offset, $data['per_page'] ) ) ) );
	}

	protected function render_related_filters_html( $data ) {
		$domain_options = array( array( 'value' => '', 'label' => 'All domains' ) );
		foreach ( $data['domains'] as $domain ) {
			$domain_options[] = array( 'value' => $domain->id, 'label' => $domain->name );
		}
		$options = array( array( 'value' => '', 'label' => 'All skills' ) );
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
			array( 'label' => 'ID', 'width' => '65px' ),
			array( 'label' => 'Name' ),
			array( 'label' => 'Slug' ),
			array( 'label' => 'Skill' ),
			array( 'label' => 'Description' ),
			array( 'label' => 'Status', 'width' => '90px' ),
			array( 'label' => 'Actions', 'width' => '110px' ),
		);
	}

	protected function render_table_row( $row ) {
		$record = wp_json_encode( $row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$name   = $row->name . ' (#' . $row->id . ')';
		$html   = '<tr><td><strong>#' . esc_html( $row->id ) . '</strong></td><td>' . esc_html( $row->name ) . '</td><td><code>' . esc_html( $row->slug ) . '</code></td><td>' . esc_html( $row->skill_name ) . '</td><td>' . esc_html( $row->description ) . '</td><td>' . esc_html( ucfirst( $row->status ) ) . '</td><td>';
		$html  .= '<button type="button" class="psm-icon-btn psm-open-modal" data-entity="tag" data-record="' . esc_attr( $record ) . '" aria-label="Edit ' . esc_attr( $name ) . '" data-tooltip="Edit record"><span class="dashicons dashicons-edit-alt" aria-hidden="true"></span></button> ';
		$html  .= '<button type="button" class="psm-icon-btn psm-btn-delete psm-ajax-delete" data-entity="tag" data-id="' . esc_attr( $row->id ) . '" data-name="' . esc_attr( $name ) . '" aria-label="Delete ' . esc_attr( $name ) . '" data-tooltip="Delete record"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
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
