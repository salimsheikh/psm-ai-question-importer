<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; query values are prepared.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request data is explicitly parsed or sanitized by the workflow that consumes it.

trait PSM_Admin_Settings_Trait {

	/**
	 * AJAX: Save individual AI provider settings card (Gemini, OpenAI, or Custom)
	 */
	public function ajax_save_ai_provider() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$provider    = sanitize_key( wp_unslash( $_POST['provider'] ?? '' ) );
		$valid_provs = PSM_API_Client::get_providers();
		if ( ! isset( $valid_provs[ $provider ] ) ) {
			wp_send_json_error( array( 'message' => 'Invalid AI provider.' ) );
		}

		$model        = sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) );
		$endpoint     = esc_url_raw( wp_unslash( $_POST['endpoint_url'] ?? '' ) );
		$daily_limit  = max( 1, absint( $_POST['daily_limit'] ?? 200 ) );
		$weekly_limit = max( 1, absint( $_POST['weekly_limit'] ?? 1000 ) );
		$set_default  = ! empty( $_POST['set_as_default'] );

		$settings     = get_option( 'psm_ai_api_settings', array() );
		$existing_key = (string) ( $settings['providers'][ $provider ]['api_key'] ?? '' );
		$posted_key   = sanitize_text_field( wp_unslash( $_POST['api_key'] ?? '' ) );

		if ( ! empty( $_POST['clear_api_key'] ) ) {
			$api_key = '';
		} elseif ( '' !== $posted_key ) {
			$api_key = $posted_key;
		} else {
			// A blank field means "keep the stored key"; it is never echoed back.
			$api_key = $existing_key;
		}

		$settings['providers'][ $provider ] = array(
			'api_key'      => $api_key,
			'model'        => $model ? $model : ( PSM_API_Client::get_default_models()[ $provider ] ?? '' ),
			'endpoint_url' => $endpoint,
		);
		$settings['limits'][ $provider ]    = array(
			'daily_limit'  => $daily_limit,
			'weekly_limit' => $weekly_limit,
		);

		if ( $set_default || empty( $settings['provider'] ) ) {
			$settings['provider'] = $provider;
		}

		// The legacy single-key format is no longer read; drop it when present.
		unset( $settings['api_key'] );

		update_option( 'psm_ai_api_settings', $settings, false );
		$usage = PSM_API_Client::get_usage_status( $provider );

		wp_send_json_success(
			array(
				'message' => $valid_provs[ $provider ] . ' settings saved successfully.',
				'usage'   => $usage,
				'default' => $settings['provider'],
				'has_key' => '' !== $api_key,
			)
		);
	}

	public function ajax_save_cache_settings() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$minutes = absint( $_POST['cache_duration'] ?? PSM_Cache::DEFAULT_DURATION_MINS );
		if ( $minutes < PSM_Cache::MIN_DURATION_MINS || $minutes > PSM_Cache::MAX_DURATION_MINS ) {
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: 1: minimum minutes, 2: maximum minutes. */
						__( 'Cache duration must be between %1$d and %2$d minutes.', 'psm-ai-question-importer' ),
						PSM_Cache::MIN_DURATION_MINS,
						PSM_Cache::MAX_DURATION_MINS
					),
				)
			);
		}

		update_option( PSM_Cache::DURATION_OPTION, $minutes, false );
		$generation = PSM_Cache::delete_all();

		wp_send_json_success(
			array(
				'message'    => __( 'Cache settings saved. Existing query cache was refreshed.', 'psm-ai-question-importer' ),
				'duration'       => $minutes,
				'duration_label' => human_time_diff( 0, PSM_Cache::get_expiration() ),
				'expiration'     => PSM_Cache::get_expiration(),
				'generation' => $generation,
			)
		);
	}

	public function ajax_clear_cache() {
		check_ajax_referer( 'psm_ajax_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ) );
		}

		$generation = PSM_Cache::delete_all();
		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: cache generation number. */
					__( 'Plugin query cache cleared. New cache generation: %d.', 'psm-ai-question-importer' ),
					$generation
				),
				'generation' => $generation,
			)
		);
	}

	public function handle_clear_cache() {
		check_admin_referer( 'psm_clear_cache' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'psm-ai-question-importer' ) );
		}

		$generation = PSM_Cache::delete_all();
		set_transient(
			'psm_admin_notice_' . get_current_user_id(),
			array(
				'type' => 'success',
				'text' => sprintf(
					/* translators: %d: cache generation number. */
					__( 'Plugin query cache cleared. New cache generation: %d.', 'psm-ai-question-importer' ),
					$generation
				),
			),
			60
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'psm-ai-importer',
					'tab'  => 'settings',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render_cache_card_html() {
		$duration = PSM_Cache::get_duration_minutes();
		ob_start();
		?>
		<div class="psm-table-card" style="margin-top:20px;">
			<h3><?php esc_html_e( 'Query Cache', 'psm-ai-question-importer' ); ?></h3>
			<p class="description">
				<?php esc_html_e( 'Master lists, selector data, and question navigation use the configured cache duration. Question, tag, and skill changes invalidate the whole plugin query cache automatically.', 'psm-ai-question-importer' ); ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Key prefix:', 'psm-ai-question-importer' ); ?></strong> <code><?php echo esc_html( PSM_Cache::KEY_PREFIX ); ?></code><br>
				<strong><?php esc_html_e( 'Cache group:', 'psm-ai-question-importer' ); ?></strong> <code><?php echo esc_html( PSM_Cache::GROUP ); ?></code><br>
				<strong><?php esc_html_e( 'Duration:', 'psm-ai-question-importer' ); ?></strong> <span id="psm_cache_duration_display"><?php echo esc_html( human_time_diff( 0, PSM_Cache::get_expiration() ) ); ?></span><br>
				<strong><?php esc_html_e( 'Current generation:', 'psm-ai-question-importer' ); ?></strong> <span id="psm_cache_generation"><?php echo esc_html( PSM_Cache::get_generation() ); ?></span>
			</p>
			<form id="psm_cache_settings_form" novalidate>
				<label class="psm-filter-field psm-cache-duration-field" for="psm_cache_duration">
					<span><?php esc_html_e( 'Cache duration (minutes)', 'psm-ai-question-importer' ); ?></span>
					<input type="number" id="psm_cache_duration" name="cache_duration" value="<?php echo esc_attr( $duration ); ?>" min="<?php echo esc_attr( PSM_Cache::MIN_DURATION_MINS ); ?>" max="<?php echo esc_attr( PSM_Cache::MAX_DURATION_MINS ); ?>" step="1" required>
				</label>
				<p class="description"><?php esc_html_e( 'Allowed range: 5 to 1440 minutes. Saving refreshes existing cached query results.', 'psm-ai-question-importer' ); ?></p>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
				echo $this->psm_button_html(
					__( 'Save Cache Settings', 'psm-ai-question-importer' ),
					'psm-btn-primary',
					array(
						'type'        => 'submit',
						'id'          => 'psm_save_cache_settings',
						'data-tooltip' => 'Save the cache duration without reloading the page',
					)
				);
				?>
			</form>
			<hr>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psm_clear_cache_form"
				data-psm-confirm
				data-confirm-title="<?php echo esc_attr__( 'Clear plugin query cache?', 'psm-ai-question-importer' ); ?>"
				data-confirm-message="<?php echo esc_attr__( 'This invalidates all cached master and question query results for this site. It does not delete database records or provider settings.', 'psm-ai-question-importer' ); ?>"
				data-confirm-label="<?php echo esc_attr__( 'Clear Cache', 'psm-ai-question-importer' ); ?>">
				<?php wp_nonce_field( 'psm_clear_cache' ); ?>
				<input type="hidden" name="action" value="psm_clear_cache">
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
				echo $this->psm_button_html(
					__( 'Clear Cache', 'psm-ai-question-importer' ),
					'psm-btn-primary',
					array(
						'type'        => 'submit',
						'id'          => 'psm_clear_cache',
						'data-tooltip' => 'Delete all plugin query cache entries',
					),
					'dashicons-trash'
				);
				?>
			</form>
		</div>
		<?php
		return ob_get_clean();
	}
}
