<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; all query values are prepared.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch; render_page only reads display filters.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw JSON, uploads, and question fields are explicitly parsed or sanitized by their dedicated validators.

class PSM_Admin_UI {

	use PSM_UI_Components_Trait;
	use PSM_Admin_Settings_Trait;
	use PSM_Admin_Master_CRUD_Trait;
	use PSM_Admin_Generation_Trait;
	use PSM_Admin_Import_Trait;
	use PSM_Admin_Batches_Trait;
	use PSM_Admin_Page_Trait;

	private $master_controller;
	private $backup_restore;

	public function __construct() {
		$this->master_controller = new PSM_Master_Controller();
		$this->backup_restore    = new PSM_Backup_Restore();

		add_action( 'admin_menu', array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_psm_fetch_preview', array( $this, 'handle_fetch_preview' ) );
		add_action( 'admin_post_psm_confirm_import', array( $this, 'handle_confirm_import' ) );
		add_action( 'admin_post_psm_run_seeder', array( $this, 'handle_run_seeder' ) );
		add_action( 'admin_post_psm_download_labels', array( $this, 'handle_download_labels' ) );
		add_action( 'admin_post_psm_import_json_file', array( $this, 'handle_import_json_file' ) );
		add_action( 'admin_post_psm_clear_cache', array( $this, 'handle_clear_cache' ) );

		// AJAX endpoints for Master CRUD (Domains, Skill Types, Question Types, Difficulties, Skills, Tags, Questions, Batches)
		add_action( 'wp_ajax_psm_ajax_save_master', array( $this, 'ajax_save_master' ) );
		add_action( 'wp_ajax_psm_ajax_delete_master', array( $this, 'ajax_delete_master' ) );
		add_action( 'wp_ajax_psm_ajax_get_question_master', array( $this, 'ajax_get_question_master' ) );

		// AJAX endpoint for saving individual AI provider forms (Gemini / OpenAI / Custom)
		add_action( 'wp_ajax_psm_ajax_save_ai_provider', array( $this, 'ajax_save_ai_provider' ) );
		add_action( 'wp_ajax_psm_ajax_fetch_preview', array( $this, 'ajax_fetch_preview' ) );
		add_action( 'wp_ajax_psm_ajax_build_prompt', array( $this, 'ajax_build_prompt' ) );
	}

	public function enqueue_assets( $hook_suffix ) {
		if ( 'toplevel_page_psm-ai-importer' !== $hook_suffix ) {
			return;
		}
		wp_enqueue_style( 'psm-ai-admin', PSM_AI_PLUGIN_URL . 'assets/css/admin.css', array(), PSM_AI_VERSION );
		wp_enqueue_script( 'psm-ai-admin', PSM_AI_PLUGIN_URL . 'assets/js/admin.js', array(), PSM_AI_VERSION, true );
	}

	public function register_menu() {
		add_menu_page(
			'PSM AI Importer',
			'PSM AI Importer',
			'manage_options',
			'psm-ai-importer',
			array( $this, 'render_page' ),
			'dashicons-database-import',
			26
		);
	}

}
