<?php
/**
 * Base class for master-table modules.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- SQL clauses are built by fixed master implementations; all filter values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.

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
		$table                  = $this->get_table_name();
		$per_page               = $data['per_page'];
		$count_sql              = 'SELECT COUNT(*) ' . $from . $where;
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
				return $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $table );
			},
			PSM_Cache::EXPIRATION
		);
		$data['filtered_count'] = (int) PSM_Cache::get_or_create(
			'psm_master_list_filtered_count',
			$list_context,
			static function () use ( $wpdb, $count_sql, $args ) {
				return empty( $args ) ? $wpdb->get_var( $count_sql ) : $wpdb->get_var( $wpdb->prepare( $count_sql, $args ) );
			},
			PSM_Cache::EXPIRATION
		);
		$data['total_pages']    = max( 1, (int) ceil( $data['filtered_count'] / $per_page ) );
		$data['page']           = min( $data['total_pages'], max( 1, absint( $source[ $this->entity . '_page' ] ?? 1 ) ) );
		$offset                 = ( $data['page'] - 1 ) * $per_page;
		$query                  = $select . $from . $where . $order . ' LIMIT %d, %d';
		$page_context           = array_merge(
			$list_context,
			array(
				'page'     => $data['page'],
				'per_page' => $per_page,
			)
		);
		$data['rows']           = PSM_Cache::get_or_create(
			'psm_master_list_rows',
			$page_context,
			static function () use ( $wpdb, $query, $args, $offset, $per_page ) {
				return $wpdb->get_results( $wpdb->prepare( $query, array_merge( $args, array( $offset, $per_page ) ) ) );
			},
			PSM_Cache::EXPIRATION
		);
		return $data;
	}

	protected function get_table_headers() {
		return array(
			array(
				'label' => 'ID',
				'width' => '65px',
			),
			array( 'label' => 'Name' ),
			array( 'label' => 'Slug' ),
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

	protected function render_related_filters_html( $data ) {
		return '';
	}

	protected function render_table_row( $row ) {
		$record = wp_json_encode( $row, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		$name   = $row->name . ' (#' . $row->id . ')';
		$html   = '<tr><td><strong>#' . esc_html( $row->id ) . '</strong></td><td>' . esc_html( $row->name ) . '</td><td><code>' . esc_html( $row->slug ) . '</code></td><td>' . esc_html( $row->description ) . '</td><td>' . esc_html( ucfirst( $row->status ) ) . '</td><td>';
		$html  .= $this->psm_button_html(
			'',
			'psm-icon-btn psm-btn-edit psm-open-modal',
			array(
				'data-entity'  => $this->entity,
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
				'data-entity'  => $this->entity,
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
}
