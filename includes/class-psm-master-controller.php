<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table suffixes come from the fixed master map; all query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.

class PSM_Master_Controller {

	private $masters;

	public function __construct() {
		$this->masters = array(
			'domain'        => new PSM_Master_Domain(),
			'skill_type'    => new PSM_Master_Skill_Type(),
			'question_type' => new PSM_Master_Question_Type(),
			'difficulty'    => new PSM_Master_Difficulty(),
			'skill'         => new PSM_Master_Skill(),
			'tag'           => new PSM_Master_Tag(),
			'question'      => new PSM_Master_Question(),
		);
		add_action( 'wp_ajax_psm_ajax_get_master_list', array( $this, 'ajax_get_master_list' ) );
		add_action( 'wp_ajax_psm_ajax_save_per_page', array( $this, 'ajax_save_per_page' ) );
	}

	public function get_master( $entity ) {
		return isset( $this->masters[ $entity ] ) ? $this->masters[ $entity ] : null;
	}

	public function get_question_master() {
		return $this->masters['question'];
	}

	public function get_counts() {
		global $wpdb;
		$tables = array(
			'domain'        => 'domains',
			'skill_type'    => 'skill_types',
			'question_type' => 'question_types',
			'difficulty'    => 'difficulties',
			'skill'         => 'skills',
			'tag'           => 'tags',
			'question'      => 'questions',
		);
		return PSM_Cache::get_or_create(
			'psm_master_counts',
			array( 'tables' => $tables ),
			static function () use ( $wpdb, $tables ) {
				$counts = array();
				foreach ( $tables as $entity => $suffix ) {
					$counts[ $entity ] = (int) PSM_Cache::get_var( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . $suffix, array(), false );
				}
				return $counts;
			},
			PSM_Cache::EXPIRATION
		);
	}

	public function get_list_data( $entity, $source ) {
		$master = $this->get_master( $entity );
		return $master ? $master->get_list_data( $source ) : null;
	}

	public function render_list_html( $data ) {
		$master = $this->get_master( $data['entity'] );
		return $master ? $master->render_list_html( $data ) : '';
	}

	public function ajax_get_master_list() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}
		$entity = sanitize_key( $_POST['entity'] ?? '' );
		$master = $this->get_master( $entity );
		if ( ! $master ) {
			wp_send_json_error( array( 'message' => 'Invalid master entity.' ) );
		}
		$data = $master->get_list_data( $_POST );
		wp_send_json_success(
			array(
				'html'           => $master->render_list_html( $data ),
				'counts'         => $this->get_counts(),
				'filters'        => $data['filters'],
				'page'           => $data['page'],
				'total_pages'    => $data['total_pages'],
				'question_order' => $data['question_order'],
				'tags'           => $data['tags'],
				'skills'         => $data['skills'],
			)
		);
	}

	public function ajax_save_per_page() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}
		$per_page = absint( $_POST['per_page'] ?? PSM_AI_DEFAULT_PER_PAGE );
		$per_page = min( 100, max( 5, $per_page ) );
		update_option( 'psm_ai_master_per_page', $per_page, false );
		wp_send_json_success(
			array(
				'per_page' => $per_page,
				'message'  => 'Pagination setting saved.',
			)
		);
	}
}
