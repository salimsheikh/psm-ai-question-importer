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

global $wpdb;

foreach ( array( 'psm_ai_api_settings', 'psm_ai_usage_stats', 'psm_ai_db_version' ) as $option ) {
	delete_option( $option );
	delete_site_option( $option );
}

foreach ( array( 'psm_preview_data_', 'psm_admin_notice_' ) as $prefix ) {
	$like = '%' . $wpdb->esc_like( $prefix ) . '%';
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
			$like
		)
	);
}
