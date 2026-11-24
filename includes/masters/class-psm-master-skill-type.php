<?php

class PSM_Master_Skill_Type extends PSM_Master_Abstract {

	public function __construct() {
		$this->entity = 'skill_type';
	}

	protected function get_filters( $source ) {
		return array(
			'search'    => $this->get_search_filter( $source ),
			'domain_id' => absint( $source['skill_type_domain'] ?? 0 ),
		);
	}

	protected function build_list_query( $data, $source ) {
		global $wpdb;
		$table = $this->get_table_name();
		$where = ' WHERE 1=1';
		$args  = array();
		if ( '' !== $data['filters']['search'] ) {
			$where .= ' AND m.name LIKE %s';
			$args[] = '%' . $wpdb->esc_like( $data['filters']['search'] ) . '%';
		}
		if ( $data['filters']['domain_id'] > 0 ) {
			$where .= ' AND EXISTS (SELECT 1 FROM ' . PSM_AI_TABLE_PREFIX . 'skills s WHERE s.skill_type_id = m.id AND s.domain_id = %d)';
			$args[] = $data['filters']['domain_id'];
		}
		return $this->run_list_query( $data, $source, 'SELECT m.* ', 'FROM ' . $table . ' m ', $where, $args, ' ORDER BY m.sort_order ASC, m.name ASC' );
	}

	protected function render_related_filters_html( $data ) {
		$options = array(
			array(
				'value' => '',
				'label' => 'All domains',
			),
		);
		foreach ( $data['domains'] as $domain ) {
			$options[] = array(
				'value' => $domain->id,
				'label' => $domain->name,
			);
		}
		return '<label class="psm-filter-field"><span>Domain</span>' . $this->psm_select_html(
			array(
				'name'     => 'skill_type_domain',
				'selected' => $data['filters']['domain_id'],
				'options'  => $options,
				'tooltip'  => 'Show skill types used in this domain',
			)
		) . '</label>';
	}

	protected function get_search_placeholder() {
		return 'Search skill type name';
	}

	protected function get_item_label() {
		return 'skill types';
	}

	protected function get_table_name() {
		return PSM_AI_TABLE_PREFIX . 'skill_types';
	}
}
