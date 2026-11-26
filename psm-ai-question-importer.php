<?php
/**
 * Plugin Name: PSM AI Question Generator & Importer
 * Description: Generates structured MCQ/coding questions via AI APIs using Domain/Skill/Tag/Question/QuestionType/Difficulty masters, AJAX modal CRUD, 3 independent AI provider cards, batch tracking, preview-before-import, and label export.
 * Version: 1.7.7
 * Author: PSM
 * Text Domain: psm-ai-question-importer
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

define( 'PSM_AI_VERSION', '1.7.7' );
define( 'PSM_AI_DB_VERSION', '1.3.0' );
define( 'PSM_AI_TABLE_PREFIX', $wpdb->prefix . 'psm_' );
define( 'PSM_AI_DEFAULT_PER_PAGE', 25 );
define( 'PSM_AI_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PSM_AI_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-languages.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-cache.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-database.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-seeder.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-api-client.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-importer.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-ui-components.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-master-list.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-admin-settings.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-admin-master-crud.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-admin-generation.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-admin-import.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-admin-batches.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/traits/trait-psm-admin-page.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-abstract.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-domain.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-skill-type.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-question-type.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-difficulty.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-skill.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-tag.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/masters/class-psm-master-question.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-master-controller.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-backup-restore.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-admin-ui.php';
require_once PSM_AI_PLUGIN_DIR . 'includes/class-psm-ai-question-importer.php';

register_activation_hook( __FILE__, array( 'PSM_AI_Question_Importer', 'activate' ) );
add_action( 'plugins_loaded', array( 'PSM_AI_Question_Importer', 'maybe_upgrade' ), 5 );
add_action( 'init', array( 'PSM_AI_Question_Importer', 'init' ) );
