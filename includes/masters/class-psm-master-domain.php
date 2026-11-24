<?php

class PSM_Master_Domain extends PSM_Master_Abstract {

	public function __construct() {
		$this->entity = 'domain';
	}

	protected function get_filters( $source ) {
		return array( 'search' => $this->get_search_filter( $source ) );
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
		$this->run_list_query( $data, $source, 'SELECT m.* ', 'FROM ' . $table . ' m ', $where, $args, ' ORDER BY m.sort_order ASC, m.name ASC' );
	}

	protected function get_search_placeholder() {
		return 'Search domain name';
	}

	protected function get_item_label() {
		return 'domains';
	}

	protected function get_table_name() {
		return PSM_AI_TABLE_PREFIX . 'domains';
	}
}
