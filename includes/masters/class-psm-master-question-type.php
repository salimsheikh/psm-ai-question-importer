<?php

class PSM_Master_Question_Type extends PSM_Master_Abstract {

	public function __construct() {
		$this->entity = 'question_type';
	}

	protected function get_filters( $source ) {
		return array(
			'search' => $this->get_search_filter( $source ),
			'status' => $this->get_status_filter( $source ),
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
		if ( '' !== $data['filters']['status'] ) {
			$where .= ' AND m.status = %s';
			$args[] = $data['filters']['status'];
		}
		$this->run_list_query( $data, $source, 'SELECT m.* ', 'FROM ' . $table . ' m ', $where, $args, ' ORDER BY m.sort_order ASC, m.name ASC' );
	}

	protected function render_related_filters_html( $data ) {
		return $this->render_status_filter( $data['filters']['status'] );
	}

	protected function render_status_filter( $status ) {
		return '<label class="psm-filter-field"><span>Status</span>' . $this->psm_select_html(
			array(
				'name'     => $this->entity . '_status',
				'selected' => $status,
				'options'  => array(
					array( 'value' => '', 'label' => 'All statuses' ),
					array( 'value' => 'active', 'label' => 'Active' ),
					array( 'value' => 'inactive', 'label' => 'Inactive' ),
				),
				'tooltip'  => 'Filter by status',
			)
		) . '</label>';
	}

	protected function get_search_placeholder() {
		return 'Search question type name';
	}

	protected function get_item_label() {
		return 'question types';
	}

	protected function get_table_name() {
		return PSM_AI_TABLE_PREFIX . 'question_types';
	}
}
