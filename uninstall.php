<?php
/**
 * Uninstall routine.
 *
 * Only plugin-owned settings, usage counters and cached previews are removed.
 * The shared psm_* master/question tables are intentionally preserved because
 * the programming-skills-master plugin owns and consumes them.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/includes/class-psm-database.php';

// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Uninstall removes plugin-owned preview and notice rows; shared master tables are preserved.

foreach ( array( 'psm_ai_api_settings', 'psm_ai_usage_stats', 'psm_ai_db_version', 'psm_ai_master_per_page', 'psm_ai_cache_generation' ) as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

if ( function_exists( 'wp_cache_flush_group' ) ) {
	wp_cache_flush_group( 'psm_ai_queries' );
}

foreach ( array( 'psm_preview_data_', 'psm_admin_notice_' ) as $prefix ) {
	PSM_Database::delete_options_like( $prefix );
}
