<?php

class PSM_Master_Skill extends PSM_Master_Abstract {

	public function __construct() {
		$this->entity = 'skill';
	}

	protected function get_filters( $source ) {
		return array(
			'search'        => $this->get_search_filter( $source ),
			'domain_id'     => absint( $source['skill_domain'] ?? 0 ),
			'skill_type_id' => absint( $source['skill_type'] ?? 0 ),
			'status'        => $this->get_status_filter( $source ),
		);
	}

	protected function build_list_query( $data, $source ) {
		global $wpdb;
		$where = ' WHERE 1=1';
		$args  = array();
		if ( '' !== $data['filters']['search'] ) {
			$where .= ' AND s.name LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $data['filters']['search'] ) . '%';
		}
		if ( $data['filters']['domain_id'] > 0 ) {
			$where .= ' AND s.domain_id = %d';
			$args[] = $data['filters']['domain_id'];
		}
		if ( $data['filters']['skill_type_id'] > 0 ) {
			$where .= ' AND s.skill_type_id = %d';
			$args[] = $data['filters']['skill_type_id'];
		}
		if ( '' !== $data['filters']['status'] ) {
			$where .= ' AND s.status = %s';
			$args[] = $data['filters']['status'];
		}
		$from = 'FROM ' . PSM_AI_TABLE_PREFIX . 'skills s'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'domains d ON s.domain_id = d.id'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skill_types st ON s.skill_type_id = st.id'
			. ' LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills p ON s.parent_id = p.id ';
		return $this->run_list_query( $data, $source, 'SELECT s.*, d.name AS domain_name, st.name AS master_type_name, p.name AS parent_skill_name ', $from, $where, $args, ' ORDER BY d.sort_order ASC, s.sort_order ASC, s.name ASC' );
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
		$type_options = array(
			array(
				'value' => '',
				'label' => 'All skill types',
			),
		);
		foreach ( $data['skill_types'] as $type ) {
			$type_options[] = array(
				'value' => $type->id,
				'label' => $type->name,
			);
		}
		$html  = '<label class="psm-filter-field"><span>Domain</span>' . $this->psm_select_html(
			array(
				'name'     => 'skill_domain',
				'selected' => $data['filters']['domain_id'],
				'options'  => $domain_options,
				'tooltip'  => 'Filter skills by domain',
			)
		) . '</label>';
		$html .= '<label class="psm-filter-field"><span>Skill Type</span>' . $this->psm_select_html(
			array(
				'name'     => 'skill_type',
				'selected' => $data['filters']['skill_type_id'],
				'options'  => $type_options,
				'tooltip'  => 'Filter skills by skill type',
			)
		) . '</label>';
		$html .= '<label class="psm-filter-field"><span>Status</span>' . $this->psm_select_html(
			array(
				'name'     => 'skill_status',
				'selected' => $data['filters']['status'],
				'options'  => array(
					array(
						'value' => '',
						'label' => 'All statuses',
					),
					array(
						'value' => 'active',
						'label' => 'Active',
					),
					array(
						'value' => 'inactive',
						'label' => 'Inactive',
					),
				),
				'tooltip'  => 'Filter skills by status',
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
			array( 'label' => 'Domain' ),
			array( 'label' => 'Skill Type' ),
			array( 'label' => 'Parent Skill' ),
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
		$html   = '<tr><td><strong>#' . esc_html( $row->id ) . '</strong></td><td>' . esc_html( $row->name ) . '</td><td><code>' . esc_html( $row->slug ) . '</code></td><td>' . esc_html( $row->domain_name ) . '</td><td>' . esc_html( $row->master_type_name ) . '</td><td>' . esc_html( $row->parent_skill_name ) . '</td><td>' . esc_html( $row->description ) . '</td><td>' . esc_html( ucfirst( $row->status ) ) . '</td><td>';
		$html  .= $this->psm_button_html(
			'',
			'psm-icon-btn psm-btn-edit psm-open-modal',
			array(
				'data-entity'  => 'skill',
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
				'data-entity'  => 'skill',
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
		return 'Search skill name';
	}

	protected function get_item_label() {
		return 'skills';
	}

	protected function get_table_name() {
		return PSM_AI_TABLE_PREFIX . 'skills';
	}
}
