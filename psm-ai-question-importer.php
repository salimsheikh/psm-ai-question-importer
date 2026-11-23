<?php
/**
 * Plugin Name: PSM AI Question Generator & Importer
 * Description: Generates structured MCQ/coding questions via AI APIs using Domain/Skill/Tag/QuestionType/Difficulty masters, AJAX modal CRUD, 3 independent AI provider cards, batch tracking, preview-before-import, and label export.
 * Version: 1.2.6
 * Author: PSM
 * Text Domain: psm-ai-importer
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PSM_AI_VERSION', '1.2.6' );
define( 'PSM_AI_DB_VERSION', '1.3.0' );
define( 'PSM_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSM_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-languages.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-seeder.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-api-client.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-importer.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-admin-ui.php';

class PSM_AI_Question_Importer {

	public static function activate() {
		self::create_and_upgrade_tables();
		self::bootstrap_masters();
		update_option( 'psm_ai_db_version', PSM_AI_DB_VERSION, false );
	}

	public static function maybe_upgrade() {
		self::migrate_legacy_settings();

		$installed_ver = get_option( 'psm_ai_db_version', '0.0.0' );
		if ( version_compare( $installed_ver, PSM_AI_DB_VERSION, '<' ) ) {
			self::create_and_upgrade_tables();
			self::bootstrap_masters();
			update_option( 'psm_ai_db_version', PSM_AI_DB_VERSION, false );
		}
	}

	/**
	 * Older releases stored a single key at the top level. Move it into the
	 * selected provider and stop autoloading the credential-bearing option.
	 */
	private static function migrate_legacy_settings() {
		$settings = get_option( 'psm_ai_api_settings', array() );
		if ( ! is_array( $settings ) || ! array_key_exists( 'api_key', $settings ) ) {
			return;
		}

		$selected = ! empty( $settings['provider'] ) ? (string) $settings['provider'] : 'gemini';
		$legacy   = (string) $settings['api_key'];

		if ( ! isset( $settings['providers'] ) || ! is_array( $settings['providers'] ) ) {
			$settings['providers'] = array();
		}
		if ( $legacy !== '' && empty( $settings['providers'][ $selected ]['api_key'] ) ) {
			if ( ! isset( $settings['providers'][ $selected ] ) || ! is_array( $settings['providers'][ $selected ] ) ) {
				$settings['providers'][ $selected ] = array();
			}
			$settings['providers'][ $selected ]['api_key'] = $legacy;
		}

		unset( $settings['api_key'] );
		update_option( 'psm_ai_api_settings', $settings, false );
	}

	/**
	 * Master rows are always ensured from defaults. The full taxonomy seed only
	 * runs on a fresh install so existing skills and tags are never rewritten.
	 */
	private static function bootstrap_masters() {
		global $wpdb;

		PSM_Seeder::init_default_masters();

		$skill_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}psm_skills" );
		if ( 0 === $skill_count ) {
			PSM_Seeder::run_seed();
		}
	}

	public static function create_and_upgrade_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();

		// 1. Domains Table
		$sql_domains = "CREATE TABLE {$wpdb->prefix}psm_domains (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(190) NOT NULL,
			slug varchar(190) NOT NULL,
			description text,
			icon varchar(100) NOT NULL DEFAULT '',
			sort_order int NOT NULL DEFAULT '0',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status_sort (status,sort_order)
		) ENGINE=InnoDB $charset_collate;";

		// 2. Skill Types Table
		$sql_skill_types = "CREATE TABLE {$wpdb->prefix}psm_skill_types (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(120) NOT NULL,
			slug varchar(120) NOT NULL,
			description text,
			sort_order int NOT NULL DEFAULT '0',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status_sort (status,sort_order)
		) ENGINE=InnoDB $charset_collate;";

		// 3. Question Types Table (slug-referenced)
		$sql_question_types = "CREATE TABLE {$wpdb->prefix}psm_question_types (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(120) NOT NULL,
			slug varchar(60) NOT NULL,
			description text,
			sort_order int NOT NULL DEFAULT '0',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status_sort (status,sort_order)
		) ENGINE=InnoDB $charset_collate;";

		// 4. Difficulties Table (ID-referenced + slug column)
		$sql_difficulties = "CREATE TABLE {$wpdb->prefix}psm_difficulties (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			name varchar(120) NOT NULL,
			slug varchar(60) NOT NULL,
			description text,
			sort_order int NOT NULL DEFAULT '0',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY status_sort (status,sort_order)
		) ENGINE=InnoDB $charset_collate;";

		// 5. Skills Table
		$sql_skills = "CREATE TABLE {$wpdb->prefix}psm_skills (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			domain_id bigint UNSIGNED NOT NULL DEFAULT '0',
			parent_id bigint UNSIGNED NOT NULL DEFAULT '0',
			parent_skill_id bigint UNSIGNED DEFAULT NULL,
			name varchar(190) NOT NULL,
			slug varchar(190) NOT NULL,
			skill_type varchar(60) NOT NULL DEFAULT 'Subject',
			skill_type_id bigint UNSIGNED NOT NULL DEFAULT '0',
			description text,
			icon varchar(100) NOT NULL DEFAULT '',
			sort_order int NOT NULL DEFAULT '0',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY domain_id (domain_id),
			KEY skill_type_id (skill_type_id),
			KEY parent_skill_id (parent_skill_id),
			KEY status_sort (status,sort_order)
		) ENGINE=InnoDB $charset_collate;";

		// 6. Tags Table
		$sql_tags = "CREATE TABLE {$wpdb->prefix}psm_tags (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			skill_id bigint UNSIGNED NOT NULL DEFAULT '0',
			name varchar(190) NOT NULL,
			slug varchar(190) NOT NULL,
			description text,
			sort_order int NOT NULL DEFAULT '0',
			status varchar(20) NOT NULL DEFAULT 'active',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY skill_id (skill_id),
			KEY status_sort (status,sort_order)
		) ENGINE=InnoDB $charset_collate;";

		// 7. Questions Table (must match programming-skills-master Schema exactly)
		$sql_questions = "CREATE TABLE {$wpdb->prefix}psm_questions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			skill_id bigint(20) unsigned NOT NULL,
			difficulty tinyint(3) unsigned NOT NULL DEFAULT 1,
			question_type varchar(20) NOT NULL DEFAULT 'four_option',
			question_text longtext NOT NULL,
			code_snippet longtext NULL,
			code_language varchar(30) NOT NULL DEFAULT '',
			image_id bigint(20) unsigned NOT NULL DEFAULT 0,
			options longtext NOT NULL,
			correct_keys longtext NOT NULL,
			is_multiple tinyint(1) unsigned NOT NULL DEFAULT 0,
			explanation longtext NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			created_by bigint(20) unsigned NOT NULL DEFAULT 0,
			external_id varchar(100) NOT NULL DEFAULT '',
			content_hash char(40) NOT NULL DEFAULT '',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			KEY skill_status_difficulty (skill_id,status,difficulty),
			KEY status_type (status,question_type),
			KEY difficulty (difficulty),
			KEY skill_external (skill_id,external_id),
			KEY skill_hash (skill_id,content_hash)
		) ENGINE=InnoDB $charset_collate;";

		// 8. Question Tags Pivot Table
		$sql_question_tags = "CREATE TABLE {$wpdb->prefix}psm_question_tags (
			question_id bigint UNSIGNED NOT NULL,
			tag_id bigint UNSIGNED NOT NULL,
			PRIMARY KEY  (question_id,tag_id),
			KEY tag_id (tag_id)
		) ENGINE=InnoDB $charset_collate;";

		// 9. Batches Table
		$sql_batches = "CREATE TABLE {$wpdb->prefix}psm_batches (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_code varchar(60) NOT NULL,
			label_name varchar(190) NOT NULL DEFAULT '',
			skill_id bigint UNSIGNED NOT NULL DEFAULT '0',
			provider varchar(50) NOT NULL DEFAULT 'manual',
			model varchar(100) NOT NULL DEFAULT '',
			prompt_text longtext,
			rules_text longtext,
			total_generated int NOT NULL DEFAULT '0',
			total_imported int NOT NULL DEFAULT '0',
			total_skipped int NOT NULL DEFAULT '0',
			status varchar(20) NOT NULL DEFAULT 'completed',
			created_by bigint UNSIGNED NOT NULL DEFAULT '0',
			created_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_code (batch_code),
			KEY skill_id (skill_id)
		) ENGINE=InnoDB $charset_collate;";

		// 10. Batch Questions Table
		$sql_batch_questions = "CREATE TABLE {$wpdb->prefix}psm_batch_questions (
			id bigint UNSIGNED NOT NULL AUTO_INCREMENT,
			batch_id bigint UNSIGNED NOT NULL,
			question_id bigint UNSIGNED NOT NULL,
			import_order int NOT NULL DEFAULT '0',
			import_status varchar(20) NOT NULL DEFAULT 'imported',
			created_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY batch_question (batch_id,question_id),
			KEY question_id (question_id)
		) ENGINE=InnoDB $charset_collate;";

		dbDelta( $sql_domains );
		dbDelta( $sql_skill_types );
		dbDelta( $sql_question_types );
		dbDelta( $sql_difficulties );
		dbDelta( $sql_skills );
		dbDelta( $sql_tags );
		dbDelta( $sql_questions );
		dbDelta( $sql_question_tags );
		dbDelta( $sql_batches );
		dbDelta( $sql_batch_questions );
	}
}

register_activation_hook( __FILE__, array( 'PSM_AI_Question_Importer', 'activate' ) );
add_action( 'plugins_loaded', array( 'PSM_AI_Question_Importer', 'maybe_upgrade' ), 5 );

function psm_ai_init_plugin() {
	if ( is_admin() ) {
		new PSM_Admin_UI();
	}
}
add_action( 'init', 'psm_ai_init_plugin' );
