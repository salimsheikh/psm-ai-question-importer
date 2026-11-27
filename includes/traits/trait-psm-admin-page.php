<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.
// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- State-changing entry points verify nonces before dispatch.
// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Request data is explicitly parsed or sanitized by the workflow that consumes it.

trait PSM_Admin_Page_Trait {

	public function render_page() {
		global $wpdb;
		$user_id = get_current_user_id();
		$tab     = sanitize_key( $_GET['tab'] ?? 'generate' );
		$entity  = sanitize_key( $_GET['entity'] ?? 'question' );
		$is_prev = isset( $_GET['preview'] ) && '1' === $_GET['preview'];

		$notice = get_transient( 'psm_admin_notice_' . $user_id );
		if ( $notice ) {
			delete_transient( 'psm_admin_notice_' . $user_id );
		}

		$domains = PSM_Cache::get_or_create(
			'admin_domains',
			array(),
			static function () use ( $wpdb ) {
				return PSM_Cache::get_results( 'SELECT * FROM ' . PSM_AI_TABLE_PREFIX . 'domains ORDER BY sort_order ASC, name ASC', array(), false );
			}
		);
		$skill_types    = PSM_Seeder::get_skill_types();
		$question_types = PSM_Seeder::get_question_types();
		$difficulties   = PSM_Seeder::get_difficulties();
		$languages      = PSM_Languages::languages();

		$skills = PSM_Cache::get_or_create(
			'admin_skills',
			array(),
			static function () use ( $wpdb ) {
				return PSM_Cache::get_results(
					'SELECT s.*, d.name AS domain_name, st.name AS master_type_name, p.name AS parent_skill_name
					 FROM ' . PSM_AI_TABLE_PREFIX . 'skills s
					 LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'domains d ON s.domain_id = d.id
					 LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skill_types st ON s.skill_type_id = st.id
					 LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills p ON s.parent_id = p.id
					 ORDER BY d.sort_order ASC, s.sort_order ASC, s.name ASC',
					array(),
					false
				);
			}
		);

		$tags_all = PSM_Cache::get_or_create(
			'admin_tags',
			array(),
			static function () use ( $wpdb ) {
				return PSM_Cache::get_results(
					'SELECT
						t.*,
						s.name AS skill_name,
						COUNT(qt.question_id) AS question_count
					FROM ' . PSM_AI_TABLE_PREFIX . 'tags t
					LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON s.id = t.skill_id
					LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'question_tags qt ON qt.tag_id = t.id
					GROUP BY t.id
					ORDER BY t.sort_order ASC, t.name ASC',
					array(),
					false
				);
			}
		);

		$per_page          = $this->psm_get_per_page();
		$batch_total       = (int) PSM_Cache::get_or_create(
			'admin_batches_count',
			array( 'table' => PSM_AI_TABLE_PREFIX . 'batches' ),
			static function () {
				return PSM_Cache::get_var( 'SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'batches', array(), false );
			},
			PSM_Cache::EXPIRATION
		);
		$batch_total_pages = max( 1, (int) ceil( $batch_total / $per_page ) );
		$batch_page        = min( $batch_total_pages, max( 1, absint( $_GET['batch_page'] ?? 1 ) ) );
		$batch_offset      = ( $batch_page - 1 ) * $per_page;
		$batches           = PSM_Cache::get_or_create(
			'admin_batches',
			array(
				'page'     => $batch_page,
				'per_page' => $per_page,
			),
			static function () use ( $wpdb, $batch_offset, $per_page ) {
				return PSM_Cache::get_results(
					$wpdb->prepare(
						'SELECT b.*, s.name AS skill_name,
						        (SELECT COUNT(*) FROM ' . PSM_AI_TABLE_PREFIX . 'batch_questions bq WHERE bq.batch_id = b.id) AS linked_questions_count
						 FROM ' . PSM_AI_TABLE_PREFIX . 'batches b
						 LEFT JOIN ' . PSM_AI_TABLE_PREFIX . 'skills s ON b.skill_id = s.id
						 ORDER BY b.id DESC LIMIT %d OFFSET %d',
						$per_page,
						$batch_offset
					),
					array(),
					false
				);
			},
			PSM_Cache::EXPIRATION
		);

		$settings        = get_option( 'psm_ai_api_settings', array() );
		$active_provider = ! empty( $settings['provider'] ) ? $settings['provider'] : 'gemini';
		$providers_list  = PSM_API_Client::get_providers();
		$usage_status    = PSM_API_Client::get_usage_status( $active_provider );

		$tags_by_skill = array();
		foreach ( $tags_all as $t ) {
			if ( 'active' === $t->status ) {
				$tags_by_skill[ (int) $t->skill_id ][] = array(
					'id'    => (int) $t->id,
					'name'  => $t->name,
					'label' => $t->name . ( $t->question_count > 0 ? " ({$t->question_count})" : '' ),
					'slug'  => $t->slug,
				);
			}
		}
		$skill_meta = array();
		foreach ( $skills as $skill_row ) {
			$skill_meta[ (int) $skill_row->id ] = array(
				'name'          => $skill_row->name,
				'slug'          => $skill_row->slug,
				'domain_id'     => (int) $skill_row->domain_id,
				'skill_type_id' => (int) $skill_row->skill_type_id,
				'parent_id'     => (int) $skill_row->parent_id,
			);
		}

		$question_type_labels = array(
			'four_option' => 'Four Options (Single / Multiple)',
			'two_option'  => 'Two Options',
			'true_false'  => 'True / False',
		);
		$master_counts        = $this->master_controller->get_counts();
		$master_list_data     = array();
		if ( 'masters' === $tab ) {
			$master_list_data = $this->master_controller->get_list_data( $entity, $_GET );
		}
		$question_filters     = ( 'masters' === $tab && 'question' === $entity )
			? ( $master_list_data['filters'] ?? $this->get_question_filters( array() ) )
			: $this->get_question_filters( $_GET );
		$question_ordered_ids = $master_list_data['question_order'] ?? array();

		$master_qtype_labels = array();
		foreach ( $question_types as $qt_row ) {
			if ( 'active' === $qt_row->status ) {
				$master_qtype_labels[ $qt_row->slug ] = $qt_row->name;
			}
		}

		$supported_generation_types = array(
			'single_choice'   => 'Single Choice',
			'multiple_choice' => 'Multiple Choice',
			'two_option'      => 'Two Options',
			'true_false'      => 'True / False',
		);
		$generation_qtypes          = array();
		foreach ( $supported_generation_types as $qt_slug => $qt_fallback ) {
			if ( isset( $master_qtype_labels[ $qt_slug ] ) ) {
				$generation_qtypes[ $qt_slug ] = $master_qtype_labels[ $qt_slug ];
			}
		}
		if ( empty( $generation_qtypes ) ) {
			$generation_qtypes = $supported_generation_types;
		}

		$default_qtypes_selected = array_keys( $generation_qtypes );
		$initial_skill_name      = 'Git';
		$initial_skill_slug      = 'git';
		foreach ( $skills as $sk_item ) {
			if ( 'git' === $sk_item->slug ) {
				$initial_skill_name = $sk_item->name;
				$initial_skill_slug = $sk_item->slug;
				break;
			}
		}
		$initial_prompt_text = PSM_API_Client::build_prompt_text(
			array(
				'skill_name'      => $initial_skill_name,
				'skill_slug'      => $initial_skill_slug,
				'difficulty'      => 'all',
				'difficulty_slug' => 'all',
				'question_types'  => $default_qtypes_selected,
				'count'           => 50,
			)
		);

		$ajax_nonce = wp_create_nonce( 'psm_ajax_nonce' );
		wp_localize_script(
			'psm-ai-admin',
			'psmAdmin',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'adminPageUrl'    => admin_url( 'admin.php' ),
				'ajaxNonce'       => $ajax_nonce,
				'tagsBySkill'     => $tags_by_skill,
				'skillMeta'       => $skill_meta,
				'questionFilters' => $question_filters,
				'questionOrder'   => $question_ordered_ids,
				'masterState'     => 'masters' === $tab ? array(
					'entity' => $entity,
					'page'   => $master_list_data['page'],
				) : null,
			)
		);
		?>


		<div class="wrap psm-wrap">
			<div class="psm-header-banner">
				<h1>PSM AI Question Generator &amp; Importer</h1>
				<span class="psm-ver">v<?php echo esc_html( PSM_AI_VERSION ); ?></span>
			</div>

			<?php
			$tabs = array(
				'generate' => array(
					'label' => 'Prompt & Import',
					'url'   => add_query_arg(
						array(
							'page' => 'psm-ai-importer',
							'tab'  => 'generate',
						),
						admin_url( 'admin.php' )
					),
				),
				'masters'  => array(
					'label' => 'Masters',
					'url'   => add_query_arg(
						array(
							'page'   => 'psm-ai-importer',
							'tab'    => 'masters',
							'entity' => $entity,
						),
						admin_url( 'admin.php' )
					),
				),
				'batches'  => array(
					'label' => 'Batches',
					'url'   => add_query_arg(
						array(
							'page' => 'psm-ai-importer',
							'tab'  => 'batches',
						),
						admin_url( 'admin.php' )
					),
				),
				'providers' => array(
					'label' => 'AI Providers',
					'url'   => add_query_arg(
						array(
							'page' => 'psm-ai-importer',
							'tab'  => 'providers',
						),
						admin_url( 'admin.php' )
					),
				),
				'settings'  => array(
					'label' => 'Settings',
					'url'   => add_query_arg(
						array(
							'page' => 'psm-ai-importer',
							'tab'  => 'settings',
						),
						admin_url( 'admin.php' )
					),
				),
				'backup'    => array(
					'label' => 'Backup',
					'url'   => add_query_arg(
						array(
							'page' => 'psm-ai-importer',
							'tab'  => 'backup',
						),
						admin_url( 'admin.php' )
					),
				),
				'seed'     => array(
					'label' => 'Seed',
					'url'   => add_query_arg(
						array(
							'page' => 'psm-ai-importer',
							'tab'  => 'seed',
						),
						admin_url( 'admin.php' )
					),
				),
			);
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_tabs_html() escapes every URL, class, and label.
			echo $this->psm_tabs_html( $tabs, $tab );
			?>

			<div id="psm_toast" class="psm-toast" role="status" aria-live="polite"></div>

			<?php if ( $notice ) : ?>
				<?php
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_notice_html() escapes the notice type and message.
				echo $this->psm_notice_html( $notice );
				?>
			<?php endif; ?>

			<?php if ( 'backup' === $tab ) : ?>
				<?php $this->backup_restore->render(); ?>
			<?php elseif ( 'providers' === $tab ) : ?>
				<!-- AI PROVIDERS: THREE SEPARATE AJAX FORMS FOR GEMINI, OPENAI, AND CUSTOM -->
				<div class="psm-ai-grid">
					<?php
					$provider_links = array(
						'gemini' => '<a href="https://aistudio.google.com/apikey" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;font-size:12px;">Get Key &rarr;</a>',
						'openai' => '<a href="https://platform.openai.com/api-keys" target="_blank" rel="noopener" style="color:#fff;text-decoration:underline;font-size:12px;">Get Key &rarr;</a>',
						'custom' => '<span style="font-size:12px;opacity:0.9;">REST JSON API</span>',
					);
					foreach ( $providers_list as $pkey => $plabel ) :
						$pcfg   = PSM_API_Client::get_provider_config( $pkey );
						$pusage = PSM_API_Client::get_usage_status( $pkey );
						$is_def = ( $active_provider === $pkey );
						?>
						<div class="psm-ai-card psm-ai-<?php echo esc_attr( $pkey ); ?>">
							<div class="psm-ai-card-head">
								<span><?php echo esc_html( $plabel ); ?> <?php echo $is_def ? '<small style="background:rgba(255,255,255,0.25);padding:2px 7px;border-radius:10px;margin-left:5px;">DEFAULT</small>' : ''; ?></span>
								<?php echo wp_kses_post( $provider_links[ $pkey ] ); ?>
							</div>
							<div class="psm-ai-card-body">
								<div class="psm-quota-box" id="psm_quota_box_<?php echo esc_attr( $pkey ); ?>">
									<strong>Daily Usage:</strong> <?php echo esc_html( $pusage['daily_used'] . ' / ' . $pusage['daily_limit'] ); ?> (Left: <?php echo esc_html( $pusage['daily_remaining'] ); ?>)<br>
									<strong>Next Daily Reset:</strong> <?php echo esc_html( $pusage['next_daily_reset'] ); ?><br>
									<strong>Weekly Usage:</strong> <?php echo esc_html( $pusage['weekly_used'] . ' / ' . $pusage['weekly_limit'] ); ?> (Left: <?php echo esc_html( $pusage['weekly_remaining'] ); ?>)<br>
									<strong>Next Weekly Reset:</strong> <?php echo esc_html( $pusage['next_weekly_reset'] ); ?>
								</div>

								<form class="psm-ajax-ai-form" data-provider="<?php echo esc_attr( $pkey ); ?>">
									<input type="hidden" name="provider" value="<?php echo esc_attr( $pkey ); ?>">
									<p>
										<label>API Key / Bearer Token</label>
										<?php $has_key = '' !== (string) $pcfg['api_key']; ?>
										<input type="password" name="api_key" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_key ? 'A key is saved - leave blank to keep it' : 'Enter ' . $plabel . ' key' ); ?>">
										<?php if ( $has_key ) : ?>
											<label style="display:inline-flex;align-items:center;gap:6px;margin-top:6px;font-weight:500;">
												<input type="checkbox" name="clear_api_key" value="1" style="width:auto;"> Remove the saved key
											</label>
										<?php endif; ?>
										<span style="display:block;font-size:11px;color:#64748b;margin-top:3px;">For security the saved key is never sent back to the browser, so this field clears after saving. Leave it blank to keep the saved key.</span>
									</p>
									<p>
										<label>Model Name</label>
										<input type="text" name="model" value="<?php echo esc_attr( $pcfg['model'] ); ?>">
									</p>
									<p>
										<label>Custom Endpoint URL (Optional)</label>
										<input type="url" name="endpoint_url" value="<?php echo esc_attr( $pcfg['endpoint_url'] ); ?>" placeholder="Leave blank for default endpoint">
									</p>
									<div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:10px;">
										<div>
											<label>Daily Limit</label>
											<input type="number" name="daily_limit" value="<?php echo esc_attr( $pcfg['daily_limit'] ); ?>" min="1">
										</div>
										<div>
											<label>Weekly Limit</label>
											<input type="number" name="weekly_limit" value="<?php echo esc_attr( $pcfg['weekly_limit'] ); ?>" min="1">
										</div>
									</div>
									<p>
										<label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
											<input type="checkbox" name="set_as_default" value="1" <?php checked( $is_def ); ?>> Set as Default AI Provider
										</label>
									</p>
									<?php
									$save_provider_button_html = $this->psm_button_html(
										'Save ' . $plabel . ' Settings (AJAX)',
										'button button-primary',
										array(
											'type'  => 'submit',
											'style' => 'width:100%;',
										),
										'',
										''
									);
									// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
									echo $save_provider_button_html;
									?>
									<div class="psm-save-status" id="psm_save_status_<?php echo esc_attr( $pkey ); ?>" aria-live="polite"></div>
								</form>
							</div>
						</div>
					<?php endforeach; ?>
				</div>

			<?php elseif ( 'settings' === $tab ) : ?>
				<div class="psm-table-card psm-settings-card">
					<h2><?php esc_html_e( 'Pagination', 'psm-ai-question-importer' ); ?></h2>
					<p class="description"><?php esc_html_e( 'Set the number of rows shown on every master table and the Batches page.', 'psm-ai-question-importer' ); ?></p>
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_per_page_setting_html() escapes all dynamic output.
					echo $this->render_per_page_setting_html();
					?>
				</div>
				<?php
				$cache_card_html = $this->render_cache_card_html();
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_cache_card_html() escapes all dynamic output.
				echo $cache_card_html;
				?>

			<?php elseif ( 'batches' === $tab ) : ?>
				<!-- TAB 3: BATCHES & LABEL DOWNLOAD -->
				<div class="psm-table-card" style="margin-top: 20px;">
					<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:14px;">
						<h2 style="margin:0;">Import Batches &amp; Question Relations</h2>
						<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psm_download_labels' ), 'psm_download_labels_nonce' ) ); ?>" class="psm-btn-add">
							<span class="dashicons dashicons-download"></span> Download All Recent Labels (.csv)
						</a>
					</div>
					<table class="widefat striped psm-batch-table">
						<thead>
							<tr>
								<th>ID</th>
								<th>Batch Code</th>
								<th>Label Name</th>
								<th>Skill</th>
								<th>AI / Source</th>
								<th>Imported / Linked</th>
								<th>Skipped</th>
								<th>Created</th>
								<th>Actions</th>
							</tr>
						</thead>
						<tbody>
							<?php if ( empty( $batches ) ) : ?>
								<tr><td colspan="9">No batches recorded yet.</td></tr>
								<?php
							else :
								foreach ( $batches as $b ) :
									?>
								<tr id="psm-row-batch-<?php echo esc_attr( $b->id ); ?>">
									<td><?php echo esc_html( $b->id ); ?></td>
									<td><code><?php echo esc_html( $b->batch_code ); ?></code></td>
									<td><strong><?php echo esc_html( $b->label_name ); ?></strong></td>
									<td><span class="psm-badge psm-badge-type"><?php echo esc_html( $b->skill_name ? $b->skill_name : '—' ); ?></span></td>
									<td><?php echo esc_html( $b->provider . ( $b->model ? ' (' . $b->model . ')' : '' ) ); ?></td>
									<td><?php echo esc_html( $b->total_imported . ' / ' . $b->linked_questions_count ); ?></td>
									<td><?php echo esc_html( $b->total_skipped ); ?></td>
									<td><?php echo esc_html( $this->local_time( $b->created_at ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=psm_download_labels&batch_id=' . $b->id ), 'psm_download_labels_nonce' ) ); ?>" class="psm-icon-btn" style="background:#dcfce7;color:#15803d;" title="Download Label File">
											<span class="dashicons dashicons-download"></span>
										</a>
										<?php
										$edit_batch_button_html = $this->psm_button_html(
											'',
											'psm-icon-btn psm-btn-edit psm-open-modal',
											array(
												'title'       => 'Edit Batch Label',
												'data-entity' => 'batch',
												'data-record' => (string) wp_json_encode( $b ),
											),
											'dashicons-edit',
											''
										);
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
										echo $edit_batch_button_html;
										?>
										<?php
										$delete_batch_button_html = $this->psm_button_html(
											'',
											'psm-icon-btn psm-btn-delete psm-ajax-delete',
											array(
												'title'       => 'Delete Batch',
												'data-entity' => 'batch',
												'data-id'     => $b->id,
												'data-name'   => $b->batch_code,
											),
											'dashicons-trash',
											''
										);
										// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
										echo $delete_batch_button_html;
										?>
									</td>
								</tr>
															<?php
							endforeach;
endif;
							?>
						</tbody>
					</table>
					<?php
					$batch_pagination = $this->psm_pagination_html(
						$batch_page,
						$batch_total_pages,
						$batch_total,
						$per_page,
						'batch_page',
						'batches',
						add_query_arg(
							array(
								'page' => 'psm-ai-importer',
								'tab'  => 'batches',
							),
							admin_url( 'admin.php' )
						)
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_pagination_html() escapes all dynamic output.
					echo $batch_pagination;
					?>
				</div>

			<?php elseif ( 'seed' === $tab ) : ?>
				<div class="psm-table-card psm-seed-card">
					<h2>Seed Default Masters</h2>
					<p>Add missing default domains, skill types, question types, difficulties, skills, and topic tags, including C, C++, C#, and Microsoft Azure with certification and learning-topic tags.</p>
					<p>Existing records are preserved. Repeated runs add only missing records and keep existing names, ownership, statuses, questions, and tag relationships. Blank descriptions are filled, and deterministic generated tag descriptions are replaced with topic-based text; curated descriptions remain unchanged.</p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="psm_seeder_form"
						data-confirm-title="Run idempotent seed?"
						data-confirm-message="This adds missing defaults and fills blank or deterministic generated descriptions while preserving curated descriptions and existing question data."
						data-confirm-label="Run Seed">
						<?php wp_nonce_field( 'psm_seeder_nonce' ); ?>
						<input type="hidden" name="action" value="psm_run_seeder">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Run Idempotent Seed',
							'psm-btn-primary',
							array(
								'type'        => 'submit',
								'data-tooltip' => 'Add missing defaults and fill descriptions while preserving curated data',
							)
						);
						?>
					</form>
				</div>

			<?php elseif ( 'masters' === $tab ) : ?>
				<?php $entity_label = ucwords( str_replace( '_', ' ', $entity ) ); ?>
				<div class="psm-pill-nav psm-master-entity-nav">
					<?php
					$sub_entities = array(
						'question'      => 'Questions (' . $master_counts['question'] . ')',
						'question_type' => 'Question Types (' . $master_counts['question_type'] . ')',
						'tag'           => 'Tags (' . $master_counts['tag'] . ')',
						'skill'         => 'Skills (' . $master_counts['skill'] . ')',
						'skill_type'    => 'Skill Types (' . $master_counts['skill_type'] . ')',
						'domain'        => 'Domains (' . $master_counts['domain'] . ')',
						'difficulty'    => 'Difficulties (' . $master_counts['difficulty'] . ')',
					);
					foreach ( $sub_entities as $ek => $elabel ) :
						$cls = ( $entity === $ek ) ? 'psm-pill active' : 'psm-pill';
						?>
						<a href="?page=psm-ai-importer&tab=masters&entity=<?php echo esc_attr( $ek ); ?>" class="<?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $elabel ); ?></a>
					<?php endforeach; ?>
					<?php
					$add_master_button_html = $this->psm_button_html(
						'',
						'psm-btn-add psm-add-only psm-master-add psm-open-modal',
						array(
							'data-entity'  => $entity,
							'data-record'  => '',
							'aria-label'   => 'Add New ' . $entity_label,
							'data-tooltip' => 'Add New ' . $entity_label,
						),
						'dashicons-plus-alt2',
						''
					);
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
					echo $add_master_button_html;
					?>
				</div>

				<div class="psm-table-card">
					<?php
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Master renderers escape all table, filter, and pagination output.
					echo $this->master_controller->render_list_html( $master_list_data );
					?>
				</div>

			<?php else : ?>
				<!-- TAB 1: PROMPT, PREVIEW & IMPORT -->
				<?php
				$preview_data = $is_prev ? get_transient( 'psm_preview_data_' . $user_id ) : false;
				if ( $is_prev && ! empty( $preview_data ) && ! empty( $preview_data['questions'] ) ) :
					$inspected_rows = array();
					$valid_count    = 0;
					foreach ( $preview_data['questions'] as $p_idx => $p_q ) {
						$inspected_rows[ $p_idx ] = PSM_Importer::inspect_question(
							$p_q,
							$preview_data['default_status'] ?? 'draft',
							$preview_data['default_difficulty'] ?? 1
						);
						if ( empty( $inspected_rows[ $p_idx ]['errors'] ) ) {
							++$valid_count;
						}
					}
					$total_rows = count( $preview_data['questions'] );

					$preview_tag_slugs          = array();
					$preview_existing_tag_slugs = array();
					if ( ! empty( $preview_data['skill_id'] ) ) {
						$preview_tag_rows = PSM_Cache::get_or_create(
							'preview_tag_status',
							array(),
							static function () use ( $wpdb ) {
								return PSM_Cache::get_results( 'SELECT slug, skill_id, status FROM ' . PSM_AI_TABLE_PREFIX . 'tags', array(), false );
							}
						);
						foreach ( (array) $preview_tag_rows as $preview_tag_row ) {
							$preview_slug                                = (string) $preview_tag_row->slug;
							$preview_existing_tag_slugs[ $preview_slug ] = true;
							if ( (int) $preview_tag_row->skill_id === (int) $preview_data['skill_id'] && 'active' === $preview_tag_row->status ) {
								$preview_tag_slugs[ $preview_slug ] = true;
							}
						}
					}
					$preview_new_tags = array();
					foreach ( $preview_data['questions'] as $p_q ) {
						foreach ( (array) ( $p_q['tags'] ?? array() ) as $tslug ) {
							$tslug = (string) $tslug;
							if ( '' !== $tslug && ! isset( $preview_tag_slugs[ $tslug ] ) && ! isset( $preview_existing_tag_slugs[ $tslug ] ) ) {
								$preview_new_tags[] = $tslug;
							}
						}
					}
					$preview_new_tags = array_values( array_unique( $preview_new_tags ) );
					?>
					<div class="psm-table-card" style="margin-top: 20px;">
						<h2>Review &amp; Check Generated Data Before Database Import</h2>
						<p>
							<strong>Skill:</strong> <?php echo esc_html( $preview_data['skill_name'] ); ?> |
							<strong>Label:</strong> <?php echo esc_html( $preview_data['label_name'] ); ?> |
							<strong>Source:</strong> <?php echo esc_html( $preview_data['provider'] ); ?> |
								<?php if ( ! empty( $preview_data['focus_tag_slug'] ) ) : ?>
									<strong>Focus Tag:</strong> <code><?php echo esc_html( $preview_data['focus_tag_slug'] ); ?></code> |
								<?php endif; ?>
								<strong>Validated:</strong>
								<span style="color:<?php echo $valid_count === $total_rows ? '#166534' : '#b45309'; ?>;font-weight:700;">
									<?php echo esc_html( $valid_count . ' / ' . $total_rows . ' rows importable' ); ?>
								</span>
								<?php if ( ! empty( $preview_new_tags ) ) : ?>
									<br><strong style="color:#b45309;">New tags (auto-created for this skill on import):</strong>
									<?php
									foreach ( $preview_new_tags as $new_tslug ) :
										?>
										<code><?php echo esc_html( $new_tslug ); ?></code> <?php endforeach; ?>
								<?php endif; ?>
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'psm_confirm_import_nonce' ); ?>
							<input type="hidden" name="action" value="psm_confirm_import">

							<table class="widefat striped" style="margin-bottom: 15px;">
								<thead>
									<tr>
										<th style="width:36px;"><input type="checkbox" id="psm_select_all_rows" checked></th>
										<th>#</th>
										<th>Question</th>
										<th>Options &amp; Correct</th>
										<th>Difficulty / Type Slug</th>
										<th>Tags</th>
									</tr>
								</thead>
								<tbody>
									<?php
									foreach ( $preview_data['questions'] as $idx => $q ) :
										$inspect    = $inspected_rows[ $idx ] ?? array(
											'data'   => null,
											'errors' => array( 'Row was not validated.' ),
										);
										$row_errors = $inspect['errors'];
										$row_valid  = empty( $row_errors );
										$norm       = $row_valid ? $inspect['data'] : null;
										$q_type     = $row_valid ? $norm['question_type'] : ( $q['question_type'] ?? 'four_option' );
										$q_diff     = $row_valid ? $norm['difficulty'] : ( $q['difficulty'] ?? 1 );
										$q_stem     = $row_valid ? $norm['question_text'] : ( $q['question_text'] ?? ( $q['question'] ?? '' ) );
										$q_opts     = $row_valid ? $norm['options'] : ( $q['options'] ?? array() );
										$q_correct  = $row_valid ? $norm['correct_keys'] : ( $q['correct_keys'] ?? ( $q['correct_answers'] ?? array() ) );
										$q_lang     = $row_valid ? $norm['code_language'] : ( $q['code_language'] ?? '' );
										?>
										<tr>
											<td><input type="checkbox" class="psm-row-cb" name="selected_rows[]" value="<?php echo esc_attr( $idx ); ?>" <?php echo $row_valid ? 'checked' : 'disabled title="This row has validation errors and will be skipped."'; ?>></td>
											<td><?php echo esc_html( $idx + 1 ); ?></td>
											<td>
												<?php if ( ! $row_valid ) : ?>
<div style="background:#fee2e2;border-left:4px solid #dc2626;padding:6px 8px;margin-bottom:6px;border-radius:4px;">
<strong>Will be skipped:</strong> <?php echo esc_html( implode( ' ', $row_errors ) ); ?>
</div>
<?php endif; ?>
<strong><?php echo esc_html( $q_stem ); ?></strong>
												<?php if ( ! empty( $q['code_snippet'] ) ) : ?>
													<div style="font-size:11px; color:#334155; margin-top:4px;">
														<strong>Language:</strong> <code><?php echo esc_html( $q_lang ? $q_lang : 'plain' ); ?></code>
													</div>
													<pre style="background:#f6f7f7; padding:6px; margin:4px 0 6px; overflow:auto;"><?php echo esc_html( $q['code_snippet'] ); ?></pre>
												<?php endif; ?>
												<?php if ( ! empty( $q['explanation'] ) ) : ?>
													<div style="color:#50575e; font-size:12px;"><em>Explanation: <?php echo $row_valid ? wp_kses_post( $norm['explanation'] ) : esc_html( $q['explanation'] ?? '' ); ?></em></div>
												<?php endif; ?>
											</td>
											<td>
												<?php
												foreach ( (array) $q_opts as $ok => $ov ) :
													if ( is_array( $ov ) && isset( $ov['key'] ) ) {
														$disp_k = $ov['key'];
														$disp_v = $ov['text'] ?? '';
													} else {
														$disp_k = $ok;
														$disp_v = $ov;
													}
													?>
													<div><code><?php echo esc_html( $disp_k ); ?></code>: <?php echo esc_html( $disp_v ); ?></div>
												<?php endforeach; ?>
												<div style="margin-top:4px;"><strong>Correct:</strong> <code><?php echo esc_html( implode( ', ', (array) $q_correct ) ); ?></code></div>
											</td>
											<td>Diff: <?php echo esc_html( $q_diff ); ?><br><code><?php echo esc_html( $q_type ); ?></code></td>
											<td>
												<?php foreach ( (array) ( $q['tags'] ?? array() ) as $tslug ) : ?>
													<div><code><?php echo esc_html( $tslug ); ?></code>
													<?php
													if ( ! isset( $preview_tag_slugs[ (string) $tslug ] ) ) :
														?>
														<span style="color:#b45309;font-weight:600;"><?php echo isset( $preview_existing_tag_slugs[ (string) $tslug ] ) ? '(tag unavailable for this skill)' : '(new tag - will be created on import)'; ?></span><?php endif; ?></div>
												<?php endforeach; ?>
											</td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>

							<?php submit_button( 'Confirm & Import Checked Rows to Custom Database', 'primary', 'confirm_import', false ); ?>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
							echo $this->psm_button_html(
								'Discard Preview',
								'button button-secondary',
								array(
									'type'  => 'submit',
									'name'  => 'cancel_preview',
									'value' => '1',
									'style' => 'margin-left:8px;',
								),
								'',
								''
							);
							?>
						</form>
					</div>
				<?php else : ?>
					<div class="psm-table-card" style="max-width: 1040px; margin-top: 20px;">
						<div style="background:#eff6ff; border-left:4px solid #2563eb; padding:10px 14px; margin-bottom:16px; border-radius:4px;">
							<strong><?php echo esc_html( $usage_status['provider_label'] ); ?> Quota:</strong>
							Daily <?php echo esc_html( $usage_status['daily_used'] . '/' . $usage_status['daily_limit'] ); ?> (Reset: <?php echo esc_html( $usage_status['next_daily_reset'] ); ?>) |
							Weekly <?php echo esc_html( $usage_status['weekly_used'] . '/' . $usage_status['weekly_limit'] ); ?> (Reset: <?php echo esc_html( $usage_status['next_weekly_reset'] ); ?>)
							<?php if ( $usage_status['is_exhausted'] ) : ?>
								<span style="color:#dc2626; font-weight:bold;"> [LIMIT FINISHED - Switch AI in dropdown below]</span>
							<?php endif; ?>
						</div>

						<form id="psm_generate_form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'psm_generate_import_nonce' ); ?>
							<input type="hidden" name="action" value="psm_fetch_preview">

							<table class="form-table">
								<tr>
									<th><label for="ai_provider_select">AI Provider (Switch if Limit Finished)</label></th>
									<td>
										<select name="ai_provider_select" id="ai_provider_select" class="psm-full-width-control">
											<?php
											foreach ( $providers_list as $pkey => $plabel ) :
												$pu = PSM_API_Client::get_usage_status( $pkey );
												?>
												<option value="<?php echo esc_attr( $pkey ); ?>" <?php selected( $active_provider, $pkey ); ?>>
													<?php echo esc_html( $plabel . ' (Daily left: ' . $pu['daily_remaining'] . ', Weekly left: ' . $pu['weekly_remaining'] . ')' ); ?>
												</option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>

								<tr>
									<th><label for="psm_generate_domain">1. Select Domain *</label></th>
									<td>
										<select name="domain_id" id="psm_generate_domain" class="regular-text" required>
											<option value="">&mdash; Select Domain &mdash;</option>
											<?php foreach ( $domains as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>

								<tr>
									<th><label for="psm_skill_select">2. Select Skill *</label></th>
									<td>
										<select name="skill_id" id="psm_skill_select" class="regular-text" required>
											<option value="">&mdash; Select Skill &mdash;</option>
											<?php
											$grouped = array();
											foreach ( $skills as $s ) {
												$grouped[ $s->domain_name ? $s->domain_name : 'Other' ][] = $s;
											}
											foreach ( $grouped as $dom_name => $dom_skills ) :
												?>
												<optgroup label="<?php echo esc_attr( $dom_name ); ?>">
													<?php foreach ( $dom_skills as $s ) : ?>
														<option value="<?php echo esc_attr( $s->id ); ?>" data-domain-id="<?php echo esc_attr( $s->domain_id ); ?>" data-name="<?php echo esc_attr( $s->name ); ?>" data-slug="<?php echo esc_attr( $s->slug ); ?>">
															<?php echo esc_html( $s->name . ' [' . $s->slug . '] (' . ( $s->master_type_name ? $s->master_type_name : $s->skill_type ) . ')' ); ?>
														</option>
													<?php endforeach; ?>
												</optgroup>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>

								<tr>
									<th><label>3. Select Tags (Skill-Filtered)</label></th>
									<td>
										<div id="psm_tag_box" style="max-height: 180px; overflow-y: auto; border: 1px solid #ccd0d4; padding: 10px; background: #f9f9f9; border-radius:6px;">
											<em>Select a Skill above to load its valid tags.</em>
										</div>
									</td>
								</tr>

								<tr>
									<th><label for="label_name">4. Batch Label Name</label></th>
									<td><input type="text" name="label_name" id="label_name" class="regular-text" placeholder="e.g. C# LINQ Set 01"></td>
								</tr>

								<tr>
									<th><label>5. Difficulty (ID), Question Type (Slug) &amp; Count</label></th>
									<td>
										<div class="psm-break-box">
											<div class="psm-break-item">
												<label for="difficulty"><strong>Difficulty (ID)</strong></label>
												<select name="difficulty" id="difficulty" class="psm-full-width-control">
													<option value="all">All Levels (beginner, intermediate, advanced)</option>
													<?php foreach ( $difficulties as $df ) : ?>
														<?php
														if ( (int) $df->id > 3 ) {
															continue; }
														?>
														<option value="<?php echo esc_attr( $df->id ); ?>" data-slug="<?php echo esc_attr( $df->slug ); ?>">
															<?php echo esc_html( $df->id . ' - ' . $df->name . ' (' . $df->slug . ')' ); ?>
														</option>
													<?php endforeach; ?>
												</select>
												<p class="description" style="margin:4px 0 0;">The practice app only supports difficulties 1-3.</p>
											</div>

											<div class="psm-break-item">
												<div class="psm-qtype-header">
													<strong>Question Type (Slug)</strong>
													<?php
													// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
													echo $this->psm_button_html(
														'Select All',
														'button button-small',
														array( 'id' => 'psm_select_all_qtypes' ),
														'',
														''
													);
													?>
												</div>
												<div class="psm-qtype-checks" id="psm_qtype_checks">
													<?php foreach ( $generation_qtypes as $qt_slug => $qt_label ) : ?>
														<label title="<?php echo esc_attr( $qt_slug ); ?>">
															<input type="checkbox" name="question_types[]" class="psm-qtype-cb" value="<?php echo esc_attr( $qt_slug ); ?>" checked>
															<code><?php echo esc_html( $qt_slug ); ?></code>
														</label>
													<?php endforeach; ?>
												</div>
												<p class="description" style="margin:4px 0 0;">single/multiple choice are both stored as <code>four_option</code>, with <code>is_multiple</code> recording two or more correct answers.</p>
											</div>

											<div class="psm-break-item">
												<label for="question_count"><strong>Count</strong></label>
												<input type="number" name="question_count" id="question_count" value="50" min="1" max="100" class="psm-full-width-control">
											</div>

											<div class="psm-break-item">
												<label for="default_status"><strong>Status</strong></label>
												<select name="default_status" id="default_status" class="psm-full-width-control">
													<option value="draft">Draft</option>
													<option value="published">Published</option>
												</select>
											</div>
										</div>
									</td>
								</tr>

								<tr>
									<th><label for="psm_code_language_master">6. Code Language (Master for Code Snippet - Optional)</label></th>
									<td>
										<select name="code_language_master" id="psm_code_language_master" class="regular-text">
											<?php foreach ( $languages as $lang_slug => $lang_name ) : ?>
												<option value="<?php echo esc_attr( $lang_slug ); ?>"><?php echo esc_html( $lang_name ); ?></option>
											<?php endforeach; ?>
										</select>
										<p class="description">Preferred language for code snippets. The AI still chooses the best language per question; this is passed to the prompt.</p>
									</td>
								</tr>

								<tr>
									<th><label for="generation_rules">7. Current Skill &amp; Prompt Rules</label></th>
									<td>
										<textarea name="generation_rules" id="generation_rules" rows="4" class="large-text">1. Every question must be completely unique-never repeat scenarios, variable names, or reworded versions of existing questions.
2. Distractors (wrong options) must be plausible technical misconceptions, not obvious throwaways.
3. Provide a clear, concise explanation for why the correct key is accurate.
4. Assign only valid tag slugs belonging strictly to the chosen skill.</textarea>
									</td>
								</tr>

								<tr>
									<th><label for="user_prompt">8. Custom Focus Instructions (Optional)</label></th>
									<td>
										<textarea name="user_prompt" id="user_prompt" rows="6" class="large-text code" placeholder="Optional: narrow the topics, e.g. &quot;focus on async/await and exception filters&quot;. Leave blank to use the full default prompt."></textarea>
										<details style="margin-top:8px;">
											<summary style="cursor:pointer;font-weight:600;">Show the default prompt template</summary>
											<pre style="background:#f6f7f7;padding:10px;max-height:320px;overflow:auto;white-space:pre-wrap;font-size:12px;"><?php echo esc_html( $initial_prompt_text ); ?></pre>
											<p class="description">Preview for the "<?php echo esc_html( $initial_skill_name ); ?>" skill with 50 questions across all levels and types. The real skill, tags, difficulty, count and types are substituted when you fetch.</p>
										</details>
									</td>
								</tr>

								</table>

							<div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
								<?php submit_button( 'Fetch & Show Data for Check Before Import', 'primary', 'submit', false ); ?>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
								echo $this->psm_button_html(
									'Create Prompt (Copy / Download)',
									'button button-secondary',
									array( 'id' => 'psm_create_prompt_btn' ),
									'',
									''
								);
								?>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
								echo $this->psm_button_html(
									'Import JSON',
									'psm-btn-secondary',
									array(
										'id'          => 'psm_import_json_jump',
										'data-tooltip' => 'Paste JSON or preview a saved JSON file',
									),
									'dashicons-upload'
								);
								?>
								<div id="psm_ai_loader" style="display:none;align-items:center;gap:8px;padding:6px 12px;background:#eef2ff;border:1px solid #c7d2fe;border-radius:6px;color:#1e3a8a;font-weight:600;">
									<span class="spinner is-active" style="float:none;margin:0;"></span>
									<span>Generating questions via AI...</span>
								</div>
							</div>
							<div id="psm_ai_ajax_notice" style="margin-top:10px;"></div>
						</form>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( 'generate' === $tab && ! $is_prev ) : ?>
						<div class="psm-modal-backdrop" id="psm_import_json_modal" role="dialog" aria-modal="true" aria-labelledby="psm_import_json_title">
				<div class="psm-modal psm-json-import-modal">
					<div class="psm-modal-header">
						<h3 id="psm_import_json_title">Import JSON</h3>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'×',
							'psm-modal-close',
							array(
								'id'         => 'psm_import_json_close',
								'aria-label' => 'Close JSON import',
							),
							'',
							''
						);
						?>
					</div>
					<div class="psm-modal-body">
						<p class="description" style="margin-top:0;">
							Paste AI-generated JSON or choose a saved <code>.json</code> file. Both actions validate the data and open the preview screen;
							no questions, tags, or batches are written until you confirm the import. A batch label is created automatically.
						</p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" id="psm_json_import_form">
							<?php wp_nonce_field( 'psm_import_json_file_nonce' ); ?>
							<input type="hidden" name="action" value="psm_import_json_file">
							<table class="form-table">
								<tr>
									<th><label for="psm_json_domain">Target Domain *</label></th>
									<td>
										<select name="domain_id" id="psm_json_domain" required>
											<option value="">&mdash; Select Domain &mdash;</option>
											<?php foreach ( $domains as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</td>
								</tr>
								<tr>
									<th><label for="psm_json_skill_select">Target Skill *</label></th>
									<td>
										<select name="skill_id" id="psm_json_skill_select" required>
											<option value="">&mdash; Select Skill &mdash;</option>
										<?php
										$json_grouped = array();
										foreach ( $skills as $s ) {
											$json_grouped[ $s->domain_name ? $s->domain_name : 'Other' ][] = $s;
										}
										foreach ( $json_grouped as $dom_name => $dom_skills ) :
											?>
											<optgroup label="<?php echo esc_attr( $dom_name ); ?>">
												<?php foreach ( $dom_skills as $s ) : ?>
													<option value="<?php echo esc_attr( $s->id ); ?>" data-domain-id="<?php echo esc_attr( $s->domain_id ); ?>" data-slug="<?php echo esc_attr( $s->slug ); ?>">
														<?php echo esc_html( $s->name . ' [' . $s->slug . ']' ); ?>
												</option>
											<?php endforeach; ?>
										</optgroup>
									<?php endforeach; ?>
								</select>
							</td>
							</tr>
								<tr>
									<th><label for="psm_json_focus_tag">Tag Focus (Optional)</label></th>
									<td>
										<select name="focus_tag_id" id="psm_json_focus_tag">
											<option value="">&mdash; No tag focus &mdash;</option>
										</select>
										<p class="description">When set, every question must include this tag slug or the whole payload is rejected.</p>
								</td>
							</tr>
								<tr>
									<th><label for="psm_json_status">Import Status</label></th>
									<td>
										<select name="default_status" id="psm_json_status">
											<option value="draft">Draft</option>
											<option value="published">Published</option>
										</select>
								</td>
							</tr>
								<tr>
									<th><label for="psm_json_payload">Pasted JSON</label></th>
									<td>
										<textarea name="manual_json_payload" id="psm_json_payload" rows="12" maxlength="5242880" placeholder='Paste {"questions": [...]} or a complete schema_version 1.0 envelope here.'></textarea>
										<p class="description">Maximum 5 MB. Use <strong>Import Pasted JSON</strong> to validate this text and open the preview.</p>
									</td>
							</tr>
								<tr>
									<th><label for="psm_json_file">JSON File (Optional)</label></th>
									<td>
										<input type="file" name="json_file" id="psm_json_file" accept=".json,application/json">
										<p class="description">Maximum 5 MB. Choose <strong>Preview</strong> to validate the selected file and open the preview.</p>
									</td>
							</tr>
							</table>
							<div class="psm-modal-footer">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
								echo $this->psm_button_html(
									'Cancel',
									'psm-btn-cancel',
									array( 'id' => 'psm_import_json_cancel' )
								);
								?>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
								echo $this->psm_button_html(
									'Preview',
									'psm-btn-secondary',
									array(
										'type'        => 'submit',
										'name'        => 'import_source',
										'value'       => 'file',
										'id'          => 'psm_json_preview',
										'data-tooltip' => 'Validate the selected JSON file and open the preview',
									)
								);
								?>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
								echo $this->psm_button_html(
									'Import Pasted JSON',
									'psm-btn-primary',
									array(
										'type'        => 'submit',
										'name'        => 'import_source',
										'value'       => 'paste',
										'id'          => 'psm_import_pasted_json',
										'data-tooltip' => 'Validate the pasted JSON and open the preview',
									)
								);
								?>
							</div>
						</form>
					</div>
				</div>
			</div>
		<?php endif; ?>

			<!-- PROMPT BUILDER MODAL (Copy / Download) -->
			<div class="psm-modal-backdrop" id="psm_prompt_modal" role="dialog" aria-modal="true">
				<div class="psm-modal" style="max-width: 900px;">
					<div class="psm-modal-header">
						<h3>AI Prompt (Copy or Download)</h3>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'×',
							'psm-modal-close',
							array(
								'id'         => 'psm_prompt_close',
								'aria-label' => 'Close',
							),
							'',
							''
						);
						?>
					</div>
					<div class="psm-modal-body">
						<p class="description" style="margin-top:0;">
							Paste this into ChatGPT, Gemini, or Qoder. The model must return only raw JSON that matches the plugin schema,
							so you can save it as a <code>.json</code> file and import it with the Import JSON popup.
						</p>
						<div id="psm_prompt_status" style="margin:8px 0;color:#64748b;font-size:12px;"></div>
						<textarea id="psm_prompt_textarea" rows="18" readonly style="width:100%;font-family:Consolas,Monaco,monospace;font-size:12px;white-space:pre;overflow:auto;"></textarea>
					</div>
					<div class="psm-modal-footer">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Close',
							'button button-secondary',
							array( 'id' => 'psm_prompt_close_footer' ),
							'',
							''
						);
						?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Download .txt',
							'button',
							array(
								'id'    => 'psm_prompt_download',
								'style' => 'margin-left:8px;',
							),
							'',
							''
						);
						?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Copy Prompt',
							'button button-primary',
							array(
								'id'    => 'psm_prompt_copy',
								'style' => 'margin-left:8px;',
							),
							'',
							''
						);
						?>
					</div>
				</div>
			</div>

			<div class="psm-modal-backdrop" id="psm_confirm_modal" role="dialog" aria-modal="true" aria-labelledby="psm_confirm_title">
				<div class="psm-modal psm-confirm-modal">
					<div class="psm-modal-header">
						<h3 id="psm_confirm_title">Please confirm</h3>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'×',
							'psm-modal-close',
							array(
								'id'         => 'psm_confirm_close',
								'aria-label' => 'Close confirmation',
							),
							'',
							''
						);
						?>
					</div>
					<div class="psm-modal-body"><p id="psm_confirm_message"></p></div>
					<div class="psm-modal-footer">
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Cancel',
							'psm-btn-cancel',
							array( 'id' => 'psm_confirm_cancel' )
						);
						?>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'Confirm',
							'psm-confirm-danger',
							array( 'id' => 'psm_confirm_accept' )
						);
						?>
					</div>
				</div>
			</div>

			<!-- AJAX MODAL POPUP FOR ADD / EDIT MASTER RECORDS (Centered H+V, ESC to Close) -->
			<div class="psm-modal-backdrop" id="psm_master_modal" role="dialog" aria-modal="true">
				<div class="psm-modal">
					<div class="psm-modal-header">
						<div class="psm-modal-title-wrap">
							<h3 id="psm_modal_title">Add / Edit Record</h3>
							<div class="psm-question-edit-nav" id="psm_question_edit_nav" style="display:none;">
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
								echo $this->psm_button_html(
									'',
									'psm-nav-arrow',
									array(
										'id'          => 'psm_question_previous',
										'aria-label'  => 'Edit previous question',
										'data-tooltip' => 'Previous question',
									),
									'dashicons-arrow-left-alt',
									''
								);
								?>
								<span id="psm_question_position">Question</span>
								<?php
								// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
								echo $this->psm_button_html(
									'',
									'psm-nav-arrow',
									array(
										'id'          => 'psm_question_next',
										'aria-label'  => 'Edit next question',
										'data-tooltip' => 'Next question',
									),
									'dashicons-arrow-right-alt',
									''
								);
								?>
							</div>
						</div>
						<?php
						// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
						echo $this->psm_button_html(
							'×',
							'psm-modal-close',
							array(
								'id'         => 'psm_modal_close',
								'aria-label' => 'Close',
							),
							'',
							''
						);
						?>
					</div>
					<form id="psm_modal_form">
						<div class="psm-modal-body">
							<input type="hidden" name="entity" id="psm_m_entity">
							<input type="hidden" name="record_id" id="psm_m_record_id" value="0">

							<div id="psm_m_batch_fields" style="display:none;">
								<label>Batch Label Name
									<input type="text" name="label_name" id="psm_m_label_name">
								</label>
							</div>

							<div id="psm_m_question_fields" style="display:none;">
								<div class="psm-question-grid">
									<label>Domain *
										<select name="question_domain_id" id="psm_m_question_domain_id" required>
											<option value="">Select Domain</option>
											<?php foreach ( $domains as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Skill *
										<select name="skill_id" id="psm_m_question_skill_id" required>
											<option value="">Select Skill</option>
											<?php foreach ( $skills as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>" data-domain-id="<?php echo esc_attr( $s->domain_id ); ?>"><?php echo esc_html( $s->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Question Type *
										<select name="question_type" id="psm_m_question_type" required>
											<?php foreach ( $question_type_labels as $type_slug => $type_label ) : ?>
												<option value="<?php echo esc_attr( $type_slug ); ?>"><?php echo esc_html( $type_label ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Difficulty *
										<select name="difficulty" id="psm_m_question_difficulty" required>
											<?php foreach ( $difficulties as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Question Status *
										<select name="question_status" id="psm_m_question_status">
											<option value="draft">Draft</option>
											<option value="published">Published</option>
										</select>
									</label>
								</div>

								<div class="psm-question-section">
									<label>Question Text *
										<textarea name="question_text" id="psm_m_question_text" class="psm-full-width-control" rows="4" maxlength="5000" required></textarea>
									</label>
									<label>External ID (Optional)
										<input type="text" name="external_id" id="psm_m_external_id" maxlength="100" placeholder="Unique within the selected skill">
									</label>
								</div>

								<div class="psm-question-section">
									<div class="psm-question-grid">
										<label>Code Language (Optional)
											<select name="code_language" id="psm_m_code_language">
												<option value="">None / Plain Text</option>
												<?php foreach ( $languages as $language_slug => $language_name ) : ?>
													<?php
													if ( '' === $language_slug ) {
														continue; }
													?>
													<option value="<?php echo esc_attr( $language_slug ); ?>"><?php echo esc_html( $language_name ); ?></option>
												<?php endforeach; ?>
											</select>
										</label>
									</div>
									<label>Code Snippet (Optional)
										<textarea name="code_snippet" id="psm_m_code_snippet" class="psm-full-width-control" rows="5" maxlength="20000"></textarea>
									</label>
								</div>

								<div class="psm-question-section">
									<strong>Answer Options *</strong>
									<div class="psm-question-options">
										<label>Option A
											<input type="text" name="option_a" id="psm_m_option_a" maxlength="500" required>
										</label>
										<label>Option B
											<input type="text" name="option_b" id="psm_m_option_b" maxlength="500" required>
										</label>
										<div id="psm_m_option_c_wrap">
											<label>Option C
												<input type="text" name="option_c" id="psm_m_option_c" maxlength="500" required>
											</label>
										</div>
										<div id="psm_m_option_d_wrap">
											<label>Option D
												<input type="text" name="option_d" id="psm_m_option_d" maxlength="500" required>
											</label>
										</div>
									</div>
									<span class="psm-question-help">True / False questions use fixed True and False options automatically.</span>

									<div style="margin-top:14px;">
										<strong>Correct Answer(s) *</strong>
										<div class="psm-question-answers">
											<label><input type="checkbox" name="correct_keys[]" value="A" class="psm-correct-key"> A</label>
											<label><input type="checkbox" name="correct_keys[]" value="B" class="psm-correct-key"> B</label>
											<label><input type="checkbox" name="correct_keys[]" value="C" class="psm-correct-key" id="psm_m_correct_c"> C</label>
											<label><input type="checkbox" name="correct_keys[]" value="D" class="psm-correct-key" id="psm_m_correct_d"> D</label>
										</div>
										<span class="psm-question-help">Select one answer, or two to three answers for a multiple-answer four-option question.</span>
									</div>
								</div>

								<div class="psm-question-section">
									<label>Explanation (Optional)
										<textarea name="explanation" id="psm_m_explanation" class="psm-full-width-control" rows="4" maxlength="10000"></textarea>
									</label>
									<label>Tags (Optional)
										<div class="psm-tag-picker">
											<input type="text" id="psm_m_question_tag_input" list="psm_question_tag_suggestions" autocomplete="off" placeholder="Select an existing tag or type a new tag"
												data-tooltip="Choose a skill tag or enter a new tag name">
											<?php
											// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
											echo $this->psm_button_html(
												'Add tag',
												'psm-btn-secondary',
												array(
													'id'          => 'psm_m_question_tag_add',
													'data-tooltip' => 'Add the typed tag',
												)
											);
											?>
										</div>
										<datalist id="psm_question_tag_suggestions"></datalist>
										<div class="psm-selected-tags" id="psm_m_selected_question_tags" aria-live="polite"></div>
										<div class="psm-available-tags" id="psm_m_available_question_tags"></div>
										<textarea name="question_tags" id="psm_m_question_tags" hidden></textarea>
										<span class="psm-question-help">Choose an active tag from the selected skill, or type a new tag and click Add tag. New tags are saved as <code>skill-slug-tag-name</code>.</span>
									</label>
								</div>
							</div>

							<div id="psm_m_standard_fields">
								<div id="psm_m_skill_fields" style="display:none;">
									<label>Domain
										<select name="domain_id" id="psm_m_domain_id">
											<?php foreach ( $domains as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Skill Type
										<select name="skill_type_id" id="psm_m_skill_type_id">
											<?php foreach ( $skill_types as $st ) : ?>
												<option value="<?php echo esc_attr( $st->id ); ?>"><?php echo esc_html( $st->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Parent Skill
										<select name="parent_skill_id" id="psm_m_parent_skill_id">
											<option value="0">&mdash; None &mdash;</option>
											<?php foreach ( $skills as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>" data-domain-id="<?php echo esc_attr( $s->domain_id ); ?>"><?php echo esc_html( $s->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								</div>

								<div id="psm_m_tag_fields" style="display:none;">
									<label>Domain
										<select name="tag_domain_id" id="psm_m_tag_domain_id">
											<option value="">Select Domain</option>
											<?php foreach ( $domains as $d ) : ?>
												<option value="<?php echo esc_attr( $d->id ); ?>"><?php echo esc_html( $d->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
									<label>Parent Skill
										<select name="skill_id" id="psm_m_skill_id">
											<option value="">Select Skill</option>
											<?php foreach ( $skills as $s ) : ?>
												<option value="<?php echo esc_attr( $s->id ); ?>" data-domain-id="<?php echo esc_attr( $s->domain_id ); ?>"><?php echo esc_html( $s->name ); ?></option>
											<?php endforeach; ?>
										</select>
									</label>
								</div>

								<label>Name *
									<input type="text" name="name" id="psm_m_name">
								</label>
								<label>Slug (Entity Framework Core &rarr; efcore, C# &rarr; csharp, C# OOP &rarr; csharp-oop, Laravel &rarr; laravel)
									<input type="text" name="slug" id="psm_m_slug" placeholder="auto-generated-if-blank">
								</label>
								<label>Sort Order
									<input type="number" name="sort_order" id="psm_m_sort_order" value="10">
								</label>
								<label>Description
									<textarea name="description" id="psm_m_description" rows="2"></textarea>
								</label>
							</div>

							<div id="psm_m_standard_status">
								<label>Status
									<select name="status" id="psm_m_status">
										<option value="active">Active</option>
										<option value="inactive">Inactive</option>
										<option value="completed">Completed (Batch)</option>
									</select>
								</label>
							</div>
						</div>
						<div class="psm-modal-footer">
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
							echo $this->psm_button_html(
								'Cancel',
								'psm-btn-cancel',
								array( 'id' => 'psm_modal_cancel' ),
								'',
								''
							);
							?>
							<?php
							// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- psm_button_html() escapes its output.
							echo $this->psm_button_html(
								'Save Record',
								'psm-btn-add',
								array(
									'type'  => 'submit',
									'style' => 'margin-left:8px;',
								),
								'',
								''
							);
							?>
						</div>
					</form>
				</div>
			</div>
		</div>


		<?php
	}
}
