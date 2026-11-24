<?php
/**
 * Base class for master-table modules.
 */

abstract class PSM_Master_Abstract {

	use PSM_UI_Components_Trait;
	use PSM_Master_List_Trait;

	protected $entity;

	public function get_entity() {
		return $this->entity;
	}

	protected function get_search_filter( $source ) {
		return sanitize_text_field( wp_unslash( $source[ $this->entity . '_search' ] ?? '' ) );
	}

	protected function get_status_filter( $source ) {
		$status = sanitize_key( $source[ $this->entity . '_status' ] ?? '' );
		return in_array( $status, array( 'active', 'inactive' ), true ) ? $status : '';
	}

	protected function run_list_query( $data, $source, $select, $from, $where, $args, $order ) {
		global $wpdb;
		$table    = $this->get_table_name();
		$per_page = $data['per_page'];
		$count_sql = 'SELECT COUNT(*) ' . $from . $where;
		$data['total_count'] = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
		$data['filtered_count'] = (int) ( empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) ) );
		$data['total_pages'] = max( 1, (int) ceil( $data['filtered_count'] / $per_page ) );
		$data['page'] = min( $data['total_pages'], max( 1, absint( $source[ $this->entity . '_page' ] ?? 1 ) ) );
		$offset = ( $data['page'] - 1 ) * $per_page;
		$query = $select . $from . $where . $order . ' LIMIT %d, %d';
		$data['rows'] = $wpdb->get_results( $wpdb->prepare( $query, array_merge( $args, array( $offset, $per_page ) ) ) );
		return $data;
	}

	protected function get_table_headers() {
		return array(
			array( 'label' => 'ID', 'width' => '65px' ),
			array( 'label' => 'Name' ),
			array( 'label' => 'Slug' ),
			array( 'label' => 'Description' ),
			array( 'label' => 'Status', 'width' => '90px' ),
			array( 'label' => 'Actions', 'width' => '110px' ),
		);
	}

	protected function render_related_filters_html( $data ) {
		return '';
	}

	protected function render_table_row( $row ) {
		$record = wp_json_encode( $row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$name   = $row->name . ' (#' . $row->id . ')';
		$html   = '<tr><td><strong>#' . esc_html( $row->id ) . '</strong></td><td>' . esc_html( $row->name ) . '</td><td><code>' . esc_html( $row->slug ) . '</code></td><td>' . esc_html( $row->description ) . '</td><td>' . esc_html( ucfirst( $row->status ) ) . '</td><td>';
		$html  .= '<button type="button" class="psm-icon-btn psm-open-modal" data-entity="' . esc_attr( $this->entity ) . '" data-record="' . esc_attr( $record ) . '" aria-label="Edit ' . esc_attr( $name ) . '" data-tooltip="Edit record"><span class="dashicons dashicons-edit-alt" aria-hidden="true"></span></button> ';
		$html  .= '<button type="button" class="psm-icon-btn psm-btn-delete psm-ajax-delete" data-entity="' . esc_attr( $this->entity ) . '" data-id="' . esc_attr( $row->id ) . '" data-name="' . esc_attr( $name ) . '" aria-label="Delete ' . esc_attr( $name ) . '" data-tooltip="Delete record"><span class="dashicons dashicons-trash" aria-hidden="true"></span></button>';
		return $html . '</td></tr>';
	}
}
