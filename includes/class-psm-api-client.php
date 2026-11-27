<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_API_Client {

	public static function get_providers() {
		return array(
			'gemini' => 'Google Gemini',
			'openai' => 'OpenAI',
			'custom' => 'Custom REST Endpoint',
		);
	}

	public static function get_default_models() {
		return array(
			'gemini' => 'gemini-2.5-flash',
			'openai' => 'gpt-4o-mini',
			'custom' => 'custom-model',
		);
	}

	public static function get_provider_config( $provider ) {
		$settings = get_option( 'psm_ai_api_settings', array() );
		$prov_cfg = $settings['providers'][ $provider ] ?? array();
		$limits   = $settings['limits'][ $provider ] ?? array();

		return array(
			'api_key'      => $prov_cfg['api_key'] ?? ( ( $settings['provider'] ?? '' ) === $provider ? ( $settings['api_key'] ?? '' ) : '' ),
			'model'        => $prov_cfg['model'] ?? ( self::get_default_models()[ $provider ] ?? 'gemini-2.5-flash' ),
			'endpoint_url' => $prov_cfg['endpoint_url'] ?? '',
			'daily_limit'  => isset( $limits['daily_limit'] ) ? max( 1, absint( $limits['daily_limit'] ) ) : 200,
			'weekly_limit' => isset( $limits['weekly_limit'] ) ? max( 1, absint( $limits['weekly_limit'] ) ) : 1000,
		);
	}

	public static function get_usage_status( $provider = '' ) {
		$settings = get_option( 'psm_ai_api_settings', array() );
		$provider = $provider ? $provider : ( ! empty( $settings['provider'] ) ? $settings['provider'] : 'gemini' );
		$cfg      = self::get_provider_config( $provider );

		$tz       = wp_timezone();
		$now_dt   = new DateTimeImmutable( 'now', $tz );
		$day_key  = $now_dt->format( 'Y-m-d' );
		$week_key = $now_dt->format( 'o-\WW' );

		$usage_all   = get_option( 'psm_ai_usage_stats', array() );
		$p_usage     = $usage_all[ $provider ] ?? array();
		$daily_used  = ( ( $p_usage['day_key'] ?? '' ) === $day_key ) ? absint( $p_usage['daily_used'] ?? 0 ) : 0;
		$weekly_used = ( ( $p_usage['week_key'] ?? '' ) === $week_key ) ? absint( $p_usage['weekly_used'] ?? 0 ) : 0;

		$next_daily_dt  = $now_dt->modify( '+1 day' )->setTime( 0, 0, 0 );
		$days_until_mon = ( 8 - (int) $now_dt->format( 'N' ) ) % 7;
		if ( 0 === $days_until_mon ) {
			$days_until_mon = 7;
		}
		$next_weekly_dt = $now_dt->modify( '+' . $days_until_mon . ' days' )->setTime( 0, 0, 0 );

		$daily_exhausted  = ( $daily_used >= $cfg['daily_limit'] );
		$weekly_exhausted = ( $weekly_used >= $cfg['weekly_limit'] );

		return array(
			'provider'          => $provider,
			'provider_label'    => self::get_providers()[ $provider ] ?? strtoupper( $provider ),
			'daily_limit'       => $cfg['daily_limit'],
			'daily_used'        => $daily_used,
			'daily_remaining'   => max( 0, $cfg['daily_limit'] - $daily_used ),
			'weekly_limit'      => $cfg['weekly_limit'],
			'weekly_used'       => $weekly_used,
			'weekly_remaining'  => max( 0, $cfg['weekly_limit'] - $weekly_used ),
			'next_daily_reset'  => $next_daily_dt->format( 'Y-m-d H:i:s T' ),
			'next_weekly_reset' => $next_weekly_dt->format( 'Y-m-d H:i:s T' ),
			'is_exhausted'      => ( $daily_exhausted || $weekly_exhausted ),
			'exhaust_reason'    => $daily_exhausted ? 'Daily limit reached' : ( $weekly_exhausted ? 'Weekly limit reached' : '' ),
		);
	}

	public static function record_usage( $provider, $units = 1 ) {
		$units    = max( 1, absint( $units ) );
		$tz       = wp_timezone();
		$now_dt   = new DateTimeImmutable( 'now', $tz );
		$day_key  = $now_dt->format( 'Y-m-d' );
		$week_key = $now_dt->format( 'o-\WW' );

		$usage_all   = get_option( 'psm_ai_usage_stats', array() );
		$p_usage     = $usage_all[ $provider ] ?? array();
		$daily_used  = ( ( $p_usage['day_key'] ?? '' ) === $day_key ) ? absint( $p_usage['daily_used'] ?? 0 ) : 0;
		$weekly_used = ( ( $p_usage['week_key'] ?? '' ) === $week_key ) ? absint( $p_usage['weekly_used'] ?? 0 ) : 0;

		$usage_all[ $provider ] = array(
			'day_key'      => $day_key,
			'daily_used'   => $daily_used + $units,
			'week_key'     => $week_key,
			'weekly_used'  => $weekly_used + $units,
			'last_used_at' => $now_dt->format( 'Y-m-d H:i:s' ),
		);

		update_option( 'psm_ai_usage_stats', $usage_all );
	}

	public static function calculate_distribution( $total, $types ) {
		$types = array_values( array_unique( array_filter( (array) $types ) ) );
		if ( empty( $types ) ) {
			$types = array( 'single_choice' );
		}
		$n    = count( $types );
		$base = intdiv( max( 1, (int) $total ), $n );
		$rem  = max( 1, (int) $total ) % $n;
		$out  = array();

		foreach ( $types as $idx => $type ) {
			$out[ $type ] = $base + ( $idx < $rem ? 1 : 0 );
		}

		return $out;
	}

	private static function get_azure_certification_focus( $tag_slugs ) {
		$certifications = array(
			'az-900' => 'Azure Fundamentals',
			'az-104' => 'Azure Administrator',
			'az-305' => 'Azure Solutions Architect',
			'az-400' => 'DevOps Engineer',
			'ai-200' => 'Azure AI Cloud Developer',
		);
		$selected       = array();
		foreach ( (array) $tag_slugs as $tag_slug ) {
			$tag_slug = sanitize_title( (string) $tag_slug );
			$prefix   = 'microsoft-azure-';
			if ( 0 !== strpos( $tag_slug, $prefix ) ) {
				continue;
			}
			$code = substr( $tag_slug, strlen( $prefix ) );
			if ( isset( $certifications[ $code ] ) ) {
				$selected[] = strtoupper( $code ) . ' (' . $certifications[ $code ] . ')';
			}
		}
		return array_values( array_unique( $selected ) );
	}

	public static function build_prompt_text( $params ) {
		$skill_name     = trim( (string) ( $params['skill_name'] ?? 'Git' ) );
		$skill_slug     = trim( (string) ( $params['skill_slug'] ?? 'git' ) );
		$difficulty_raw = $params['difficulty_slug'] ?? ( $params['difficulty'] ?? 'all' );
		$diff_label     = trim( (string) ( $params['difficulty_label'] ?? '' ) );
		$count          = max( 1, (int) ( $params['count'] ?? 50 ) );
		$types          = ! empty( $params['question_types'] ) && is_array( $params['question_types'] )
			? array_values( array_unique( $params['question_types'] ) )
			: ( ! empty( $params['question_type'] ) ? array( (string) $params['question_type'] ) : array( 'single_choice', 'multiple_choice', 'two_option', 'true_false' ) );
		$rules_text     = trim( (string) ( $params['rules'] ?? '' ) );
		$user_prompt    = trim( (string) ( $params['user_prompt'] ?? '' ) );
		$code_language  = strtolower( trim( (string) ( $params['code_language'] ?? '' ) ) );
		$allowed_tags   = is_array( $params['allowed_tag_slugs'] ?? null ) ? $params['allowed_tag_slugs'] : array();
		$selected_tags  = is_array( $params['tag_slugs'] ?? null ) ? $params['tag_slugs'] : array();
		$tag_labels     = is_array( $params['tag_labels'] ?? null ) ? $params['tag_labels'] : array();
		$filename_slug  = PSM_Seeder::make_slug( $skill_slug, $skill_name );
		if ( '' === $filename_slug ) {
			$filename_slug = 'skill';
		}
		$json_filename  = 'psm-' . $filename_slug . '-' . wp_date( 'Y-m-d-H-i-s' ) . '.json';

		$lines   = array();
		$lines[] = sprintf(
			'You are a senior software engineer, technical interviewer and programming instructor. Create %d high-quality multiple-choice questions (MCQs) for the skill "%s".',
			$count,
			$skill_name
		);
		$lines[] = '';
		$lines[] = 'CONTEXT';
		$lines[] = '- Skill: ' . $skill_name . ( $skill_slug ? ' (slug: ' . $skill_slug . ')' : '' );

		$d_str = strtolower( trim( (string) $difficulty_raw ) );
		if ( ctype_digit( $d_str ) ) {
			$d_num = (int) $d_str;
			$d_str = ( $d_num <= 1 ) ? 'beginner' : ( ( $d_num >= 3 ) ? 'advanced' : 'intermediate' );
		}
		if ( 'beg' === $d_str || 'easy' === $d_str ) {
			$d_str = 'beginner';
		} elseif ( 'medium' === $d_str || 'mid' === $d_str ) {
			$d_str = 'intermediate';
		} elseif ( 'hard' === $d_str || 'expert' === $d_str ) {
			$d_str = 'advanced';
		}
		if ( 'beginner' === $d_str ) {
			$lines[] = '- Difficulty: beginner (fundamental concepts, basic syntax and common beginner mistakes). Use very basic, simple English suitable for someone new to the subject, ask straightforward foundational questions, and include a short, concrete example in every explanation. Every question must have "difficulty": "beginner".';
		} elseif ( 'intermediate' === $d_str ) {
			$lines[] = '- Difficulty: intermediate (practical application, edge cases, idiomatic patterns and debugging). Every question must have "difficulty": "intermediate".';
		} elseif ( 'advanced' === $d_str ) {
			$lines[] = '- Difficulty: advanced (internals, performance trade-offs, concurrency/architecture and subtle pitfalls). Every question must have "difficulty": "advanced".';
		} else {
			$lines[] = '- Difficulty: all levels. Spread the questions evenly across "beginner", "intermediate" and "advanced" and set each question\'s "difficulty" to the level it belongs to.';
		}

		if ( '' !== $diff_label ) {
			$lines[] = '- Difficulty Reference: ' . $diff_label;
		}
		if ( ! empty( $selected_tags ) ) {
			$lines[] = '- Selected Tags: ' . implode( ', ', $selected_tags );

			$selected_labels = array();
			foreach ( $selected_tags as $selected_slug ) {
				$label = trim( (string) ( $tag_labels[ $selected_slug ] ?? '' ) );
				if ( '' === $label ) {
					$derived = preg_replace( '/^' . preg_quote( $skill_slug . '-', '/' ) . '/', '', (string) $selected_slug );
					$label   = ucwords( str_replace( '-', ' ', (string) $derived ) );
				}
				$selected_labels[] = $label;
			}

			$lines[] = '- STRICT TAG FOCUS: Every question must be specifically about ' . implode( ' or ', $selected_labels ) . ' - not generic ' . $skill_name . ' topics such as other unrelated features or syntax.';
			if ( count( $selected_tags ) === 1 ) {
				$lines[] = '- Every question\'s "tags" array MUST include "' . $selected_tags[0] . '".';
			} else {
				$lines[] = '- Every question must focus on one of the selected topics and include that topic\'s selected tag slug in "tags".';
			}
		}
		if ( 'microsoft-azure' === $skill_slug ) {
			$azure_certifications = self::get_azure_certification_focus( $selected_tags );
			if ( ! empty( $azure_certifications ) ) {
				$lines[] = '- Azure Certification Preparation: ' . implode( '; ', $azure_certifications ) . '. Focus each question on the relevant certification objective area using accurate, exam-style scenarios; do not claim to reproduce live exam questions.';
			}
		}
		if ( ! empty( $allowed_tags ) ) {
			$lines[] = '- Allowed Tag Slugs (STRICT - only use valid tag slugs belonging strictly to the chosen skill): ' . implode( ', ', $allowed_tags );
		}
		if ( '' !== $user_prompt ) {
			$lines[] = '- Focus / Topic Instructions: ' . $user_prompt;
		}
		if ( '' !== $code_language ) {
			$lines[] = '- Code Language Preference: whenever a question includes code, write "code_snippet" in ' . $code_language . ' and set "code_language": "' . $code_language . '".';
		}

		$lines[] = '';
		$lines[] = 'QUESTION TYPES AND DISTRIBUTION';
		$dist    = self::calculate_distribution( $count, $types );
		foreach ( $dist as $t_slug => $t_cnt ) {
			if ( (int) $t_cnt <= 0 ) {
				continue;
			}
			if ( 'single_choice' === $t_slug || 'four_option' === $t_slug ) {
				$lines[] = sprintf( '- %s: %d questions, exactly four options (keys A, B, C, D) and exactly one correct answer; "answer_mode": "single".', $t_slug, $t_cnt );
			} elseif ( 'multiple_choice' === $t_slug || 'multiple_select' === $t_slug ) {
				$lines[] = sprintf( '- %s: %d questions, exactly four options (keys A, B, C, D) and two or three correct answers; "answer_mode": "multiple".', $t_slug, $t_cnt );
			} elseif ( 'two_option' === $t_slug ) {
				$lines[] = sprintf( '- two_option: %d questions, exactly two options (keys A, B) and exactly one correct answer; "answer_mode": "single".', $t_cnt );
			} elseif ( 'true_false' === $t_slug ) {
				$lines[] = sprintf( '- true_false: %d questions, exactly two options: A = "True" and B = "False", and exactly one correct answer; "answer_mode": "single".', $t_cnt );
			} else {
				$lines[] = sprintf( '- %s: %d questions.', $t_slug, $t_cnt );
			}
		}

		$lines[] = '';
		$lines[] = 'CURRENT SKILL & PROMPT RULES';
		if ( '' !== $rules_text ) {
			$lines[] = $rules_text;
		} else {
			$lines[] = '1. Every question must be completely unique-never repeat scenarios, variable names, or reworded versions of existing questions.';
			$lines[] = '2. Distractors (wrong options) must be plausible technical misconceptions, not obvious throwaways.';
			$lines[] = '3. Provide a clear, concise explanation for why the correct key is accurate.';
			$lines[] = '4. Assign only valid tag slugs belonging strictly to the chosen skill.';
		}

		$lines[] = '';
		$lines[] = 'RULES';
		$lines[] = '1. "question_type" must be one of: single_choice, multiple_choice, two_option, true_false, and each question must follow the option and answer rules listed above.';
		$lines[] = '2. "options" is a list of objects with "key" and "text". "correct_answers" is a list of option keys, and every key must exist in "options".';
		$lines[] = '3. "difficulty" must be exactly one of: beginner, intermediate, advanced (lowercase).';
		$lines[] = '4. Every question needs a clear "explanation" (1 to 5 sentences) that says why the correct answer is right and, where useful, why the others are wrong. Every beginner question must be simple and easy to understand, use simple English suitable for someone learning the topic for the first time, avoid complicated vocabulary and unnecessarily long sentences, and include a short, concrete example. Questions must be clear and suitable for first-time learners. Bulleted points are welcome.';
		$lines[] = '5. Write "question" and every option "text" as plain text. Do not use Markdown or HTML formatting. Angle brackets such as List<int> are fine as plain text.';
		$lines[] = '6. To include code, put it in "code_snippet" as one string with line breaks written as \n, and set "code_language" to one of: php, csharp, java, python, javascript, typescript, sql, html, css, json, bash. Use null for "code_snippet" when there is no code.';
		$lines[] = '7. Do not create duplicate or nearly identical questions. Cover different concepts, and vary the position of the correct answer.';
		$lines[] = '8. Make wrong options plausible but clearly wrong. Do not use "All of the above" or "None of the above".';
		$lines[] = '9. Be technically accurate. Do not invent APIs, behaviour or facts. If you are unsure about a detail, ask a different question.';
		$lines[] = '10. The JSON must be valid: escape double quotes inside strings, do not add comments, and do not leave trailing commas.';

		$lines[] = '';
		$lines[] = 'OUTPUT FILE';
		$lines[] = 'Return your entire answer as one valid, raw JSON document that the user can download directly as a .json file. Use the exact filename format psm-[skill-slug]-[yyyy-mm-dd-HH-MM-SS].json; for this request, save it as ' . $json_filename . '. Do not wrap it in Markdown fences and do not add any text before or after the JSON, so the saved file imports without edits.';

		return implode( "\n", $lines );
	}

	/**
	 * Build a copy/paste-ready AI prompt that requests the exact import schema.
	 *
	 * @param array $params skill_name, skill_slug, count, difficulty, question_types,
	 *                      status, tags_all, tags_selected, user_prompt, rules,
	 *                      code_language, prefix.
	 */
	public static function build_export_prompt( $params ) {
		$skill_name = trim( (string) ( $params['skill_name'] ?? '' ) );
		$skill_slug = PSM_Seeder::make_slug( (string) ( $params['skill_slug'] ?? '' ) );
		$count      = max( 1, min( 100, (int) ( $params['count'] ?? 50 ) ) );

		$supported_types = array( 'single_choice', 'multiple_choice', 'two_option', 'true_false' );
		$types           = array_values(
			array_intersect(
				array_map( 'sanitize_key', (array) ( $params['question_types'] ?? array() ) ),
				$supported_types
			)
		);
		if ( empty( $types ) ) {
			$types = $supported_types;
		}
		$types = array_values( array_unique( $types ) );

		$difficulty_raw = strtolower( trim( (string) ( $params['difficulty'] ?? 'all' ) ) );
		if ( ctype_digit( $difficulty_raw ) ) {
			$difficulty_raw = array(
				1 => 'beginner',
				2 => 'intermediate',
				3 => 'advanced',
			)[ min( 3, max( 1, (int) $difficulty_raw ) ) ];
		}
		$difficulty = in_array( $difficulty_raw, array( 'beginner', 'intermediate', 'advanced' ), true ) ? $difficulty_raw : 'all';

		$status = strtolower( trim( (string) ( $params['status'] ?? 'draft' ) ) );
		$status = in_array( $status, array( 'draft', 'published' ), true ) ? $status : 'draft';

		$code_language = sanitize_key( (string) ( $params['code_language'] ?? '' ) );
		if ( ! array_key_exists( $code_language, PSM_Languages::languages() ) ) {
			$code_language = '';
		}

		$user_prompt = trim( (string) ( $params['user_prompt'] ?? '' ) );
		$rules       = trim( (string) ( $params['rules'] ?? '' ) );

		$tags_all = array();
		foreach ( (array) ( $params['tags_all'] ?? array() ) as $tag ) {
			$tag = sanitize_title( (string) $tag );
			if ( '' !== $tag ) {
				$tags_all[] = $tag;
			}
		}
		$tags_all = array_values( array_unique( $tags_all ) );

		$tags_selected = array();
		foreach ( (array) ( $params['tags_selected'] ?? array() ) as $tag ) {
			$tag = sanitize_title( (string) $tag );
			if ( '' !== $tag && in_array( $tag, $tags_all, true ) ) {
				$tags_selected[] = $tag;
			}
		}
		$tags_selected = array_values( array_unique( $tags_selected ) );

		$prefix = strtoupper( preg_replace( '/[^A-Z0-9]+/i', '-', (string) ( $params['prefix'] ?? $skill_slug ) ) );
		$prefix = trim( $prefix, '-' );
		if ( '' === $prefix ) {
			$prefix = strtoupper( $skill_slug );
		}

		$language  = '' !== $code_language ? $code_language : 'plain text';
		$framework = $skill_name;
		$json_filename = 'psm-' . ( '' !== $skill_slug ? $skill_slug : 'skill' ) . '-' . wp_date( 'Y-m-d-H-i-s' ) . '.json';

		$tag_labels = array();
		foreach ( (array) ( $params['tag_labels'] ?? array() ) as $label_slug => $label_text ) {
			$label_slug = sanitize_title( (string) $label_slug );
			if ( '' !== $label_slug ) {
				$tag_labels[ $label_slug ] = sanitize_text_field( (string) $label_text );
			}
		}

		$focus_labels = array();
		foreach ( $tags_selected as $selected_slug ) {
			$derived        = preg_replace( '/^' . preg_quote( $skill_slug . '-', '/' ) . '/', '', $selected_slug );
			$focus_labels[] = '' !== ( $tag_labels[ $selected_slug ] ?? '' )
				? $tag_labels[ $selected_slug ]
				: ucwords( str_replace( '-', ' ', (string) $derived ) );
		}
		$primary_topic = ! empty( $focus_labels ) ? $focus_labels[0] : $skill_name;

		$sample_tags = ! empty( $tags_selected ) ? $tags_selected : ( ! empty( $tags_all ) ? array( $tags_all[0] ) : array() );

		$tag_list = implode( ', ', array_slice( $tags_all, 0, 150 ) );
		if ( '' === $tag_list ) {
			$tag_list = '(no tag slugs are defined for this skill; leave "tags" empty if no topic applies)';
		} elseif ( count( $tags_all ) > 150 ) {
			$tag_list .= ', ... (truncated; use only slugs from this list)';
		}

		$qtypes_str  = implode( ', ', $types );
		$sample_type = $types[0];
		$sample_mode = ( 'multiple_choice' === $sample_type ) ? 'multiple' : 'single';

		if ( 'true_false' === $sample_type ) {
			$sample_options = array(
				array(
					'key'  => 'A',
					'text' => 'True',
				),
				array(
					'key'  => 'B',
					'text' => 'False',
				),
			);
			$sample_correct = array( 'A' );
		} elseif ( 'two_option' === $sample_type ) {
			$sample_options = array(
				array(
					'key'  => 'A',
					'text' => 'First plausible statement',
				),
				array(
					'key'  => 'B',
					'text' => 'Second plausible statement',
				),
			);
			$sample_correct = array( 'B' );
		} else {
			$sample_options = array(
				array(
					'key'  => 'A',
					'text' => 'First plausible option',
				),
				array(
					'key'  => 'B',
					'text' => 'Second plausible option',
				),
				array(
					'key'  => 'C',
					'text' => 'Third plausible option',
				),
				array(
					'key'  => 'D',
					'text' => 'Fourth plausible option',
				),
			);
			$sample_correct = ( 'multiple' === $sample_mode ) ? array( 'A', 'C' ) : array( 'B' );
		}

		$padded_count = str_pad( (string) $count, max( 3, strlen( (string) $count ) ), '0', STR_PAD_LEFT );
		$last_id      = $prefix . '-' . $padded_count;

		$sample = array(
			'schema_version' => '1.0',
			'skill'          => array(
				'name' => $skill_name,
				'slug' => $skill_slug,
			),
			'generation'     => array(
				'difficulty'      => $difficulty,
				'language'        => $language,
				'framework'       => $framework,
				'topic'           => $primary_topic,
				'total_questions' => $count,
			),
			'questions'      => array(
				array(
					'external_id'     => $prefix . '-001',
					'question'        => 'Plain text question strictly about ' . $primary_topic . '?',
					'difficulty'      => ( 'all' === $difficulty ) ? 'beginner' : $difficulty,
					'question_type'   => $sample_type,
					'answer_mode'     => $sample_mode,
					'tags'            => $sample_tags,
					'options'         => $sample_options,
					'correct_answers' => $sample_correct,
					'explanation'     => 'Explain why ' . implode( ' and ', $sample_correct ) . ' is correct and why the other options are wrong.',
					'code_snippet'    => null,
					'code_language'   => null,
				),
			),
		);

		$sample_json = wp_json_encode( $sample, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );

		$lines   = array();
		$lines[] = 'Generate structured assessment questions for the WordPress plugin "PSM AI Question Generator & Importer" (psm-ai-importer).';
		$lines[] = '';
		$lines[] = 'OUTPUT FORMAT (ONE DOWNLOADABLE JSON FILE):';
		$lines[] = '- Your ENTIRE response must be one valid, raw JSON document that the user can download/save directly as a .json file. Use the exact filename format psm-[skill-slug]-[yyyy-mm-dd-HH-MM-SS].json; for this request, save it as ' . $json_filename . '.';
		$lines[] = '- Return only the JSON file content: no Markdown ```json fences, no "Here is the JSON" preamble, no explanations, no links, and nothing after the closing brace.';
		$lines[] = '- The saved file must be importable as-is: valid JSON syntax, double quotes escaped with \", no comments, and no trailing commas.';
		$lines[] = '- If the response is too long for one reply, continue only with the remaining JSON inside the same file - never start a new file or add prose.';
		$lines[] = '';
		$lines[] = 'STRICT SKILL & TAG FOCUS:';
		$lines[] = '- Skill Name: "' . $skill_name . '"';
		$lines[] = '- Skill Slug: "' . $skill_slug . '"';
		if ( ! empty( $tags_selected ) ) {
			$lines[] = '- Selected Tag Focus: ' . implode(
				', ',
				array_map(
					static function ( $slug ) {
						return '"' . $slug . '"';
					},
					$tags_selected
				)
			) . ' (topic: ' . implode( ', ', $focus_labels ) . ')';
			$lines[] = '- CRITICAL: Every single question MUST be specifically about ' . implode(
				' or ',
				array_map(
					static function ( $label ) {
						return '"' . $label . '"';
					},
					$focus_labels
				)
			) . ' for skill "' . $skill_name . '". Do not drift into unrelated topics.';
			if ( count( $tags_selected ) === 1 ) {
				$lines[] = '- Every question\'s "tags" array MUST include "' . $tags_selected[0] . '".';
			} else {
				$lines[] = '- Every question must be about exactly one of the selected topics above, and its "tags" array MUST include that topic\'s selected slug.';
			}
		} else {
			$lines[] = '- Selected Tag Focus: (none selected - cover the skill broadly across valid topics)';
		}
		if ( 'microsoft-azure' === $skill_slug ) {
			$azure_certifications = self::get_azure_certification_focus( $tags_selected );
			if ( ! empty( $azure_certifications ) ) {
				$lines[] = '- Azure Certification Preparation: ' . implode( '; ', $azure_certifications ) . '. Focus each question on the relevant certification objective area using accurate, exam-style scenarios; do not claim to reproduce live exam questions.';
			}
		}
		$lines[] = '- Every tag in "tags" must be skill-prefixed using "' . $skill_slug . '-" without repeating the prefix (e.g., "' . ( $sample_tags[0] ?? $skill_slug . '-topic' ) . '", never "' . $skill_slug . '-' . $skill_slug . '-...").';
		$lines[] = '- Never produce double-prefixed or unprefixed slugs.';
		$lines[] = '- Allowed tag slugs (prefer values from this list): ' . $tag_list;
		$lines[] = '- If no listed tag fits a question, you may use a new topic slug in the form "' . $skill_slug . '-<topic>"; new tags are created automatically on import.';
		$lines[] = '';
		$lines[] = 'COUNT, DIFFICULTY & QUESTION TYPE RULES:';
		$lines[] = '- Total count: ' . $count . ' (default 50). "generation.total_questions" MUST equal ' . $count . ' and "questions" MUST have exactly ' . $count . ' items.';
		$lines[] = '- Unique external_id: "' . $prefix . '-001" through "' . $last_id . '" - sequential, zero duplicates.';
		$lines[] = '- Difficulty setting: "' . $difficulty . '" (one of: "all", "beginner", "intermediate", "advanced").';
		if ( 'all' === $difficulty ) {
			$lines[] = '  - Because the setting is "all", vary each item\'s "difficulty" across "beginner", "intermediate", and "advanced".';
		} else {
			$lines[] = '  - Every item\'s "difficulty" MUST be exactly "' . $difficulty . '".';
		}
		if ( 'all' === $difficulty || 'beginner' === $difficulty ) {
			$lines[] = '  - Every beginner item must be simple and easy to understand, use simple English suitable for someone learning the topic for the first time, ask a clear straightforward foundational question, avoid complicated vocabulary and unnecessarily long sentences, and include a short, concrete example in its "explanation".';
		}
		$lines[] = '- Question types allowed: ' . $qtypes_str . ' (from: "single_choice", "multiple_choice", "two_option", "true_false").';
		if ( count( $types ) === 4 ) {
			$lines[] = '  - Because "all" types were chosen, mix the four types across the set.';
		} else {
			$lines[] = '  - Every item\'s "question_type" MUST be one of the selected types above.';
		}
		$lines[] = '  - For "single_choice": 4 options (A-D), 1 correct answer, "answer_mode": "single".';
		$lines[] = '  - For "multiple_choice": 4 options (A-D), two or three correct answers, "answer_mode": "multiple".';
		$lines[] = '  - For "two_option": 2 options (A-B), 1 correct answer, "answer_mode": "single".';
		$lines[] = '  - For "true_false": 2 options ("True" and "False"), 1 correct answer, "answer_mode": "single".';
		$lines[] = '- Keep "question" and every option "text" as plain text (no HTML or Markdown; List<int> and Dictionary<string, int> are fine in plain text).';
		$lines[] = '- Put code in "code_snippet" as a single string with \n line breaks and set "code_language" (php, csharp, java, python, javascript, typescript, sql, html, css, json, bash) or null when there is no code.';
		$lines[] = '- Provide a 1 to 3 sentence "explanation" for each question. Never use "All of the above" or "None of the above".';
		$lines[] = '- Post/question status context: "' . $status . '" (applied at import time; do not add extra schema fields for it).';
		$lines[] = '';
		$lines[] = 'CONTENT & QUALITY RULES:';
		$lines[] = '- Every question must be unique; never repeat scenarios, variable names, or reworded versions of existing questions.';
		$lines[] = '- Distractors must be plausible technical misconceptions. Never use "All of the above" or "None of the above".';
		$lines[] = '- Ensure 100% technical accuracy; do not invent APIs, behaviors, or facts.';
		if ( '' !== $rules ) {
			$lines[] = '';
			$lines[] = 'ADDITIONAL CUSTOM RULES (follow strictly):';
			$lines[] = $rules;
		}
		if ( '' !== $user_prompt ) {
			$lines[] = '';
			$lines[] = 'TOPIC FOCUS:';
			$lines[] = $user_prompt;
		}
		$lines[] = '';
		$lines[] = 'JSON SCHEMA:';
		$lines[] = $sample_json;
		$lines[] = '';
		$lines[] = 'Return exactly ' . $count . ' question objects in the "questions" array. The response must be one valid downloadable .json file named ' . $json_filename . ' using the format psm-[skill-slug]-[yyyy-mm-dd-HH-MM-SS].json - no Markdown fences, no commentary - that can be imported directly without edits.';

		return implode( "\n", $lines );
	}

	public static function generate_questions( $params, $override_provider = '' ) {
		$settings = get_option( 'psm_ai_api_settings', array() );
		$provider = $override_provider ? $override_provider : ( ! empty( $settings['provider'] ) ? $settings['provider'] : 'gemini' );

		$usage = self::get_usage_status( $provider );
		if ( $usage['is_exhausted'] ) {
			return new WP_Error(
				'quota_exhausted',
				sprintf(
					'%s usage limit reached (%s). Daily resets at %s; Weekly resets at %s. Switch to another AI provider from the dropdown.',
					$usage['provider_label'],
					$usage['exhaust_reason'],
					$usage['next_daily_reset'],
					$usage['next_weekly_reset']
				)
			);
		}

		$cfg      = self::get_provider_config( $provider );
		$api_key  = trim( $cfg['api_key'] );
		$model    = trim( $cfg['model'] );
		$endpoint = trim( $cfg['endpoint_url'] );

		if ( empty( $api_key ) && 'custom' !== $provider ) {
			return new WP_Error( 'missing_api_key', "API Key for {$usage['provider_label']} is not configured. Save it in Tab 4." );
		}

		$full_prompt = self::build_structured_prompt( $params );

		if ( 'gemini' === $provider ) {
			if ( empty( $endpoint ) ) {
				$endpoint = sprintf(
					// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- WordPress 5.8 support requires the server-side provider adapter; credentials never reach the browser.
					'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent',
					rawurlencode( $model )
				);
			}
			$body    = array(
				'contents'         => array(
					array(
						'parts' => array(
							array( 'text' => $full_prompt ),
						),
					),
				),
				'generationConfig' => array(
					'responseMimeType' => 'application/json',
					'temperature'      => 0.7,
				),
			);
			$headers = array( 'Content-Type' => 'application/json' );
			if ( '' !== $api_key ) {
				$headers['x-goog-api-key'] = $api_key;
			}
		} elseif ( 'openai' === $provider ) {
			if ( empty( $endpoint ) ) {
				// phpcs:ignore PluginCheck.CodeAnalysis.AIProvider.DirectIntegration -- WordPress 5.8 support requires the server-side provider adapter; credentials never reach the browser.
				$endpoint = 'https://api.openai.com/v1/chat/completions';
			}
			$body    = array(
				'model'           => $model ? $model : 'gpt-4o-mini',
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array(
					array(
						'role'    => 'system',
						'content' => 'You are an expert assessment author. Output strictly valid JSON matching the requested schema.',
					),
					array(
						'role'    => 'user',
						'content' => $full_prompt,
					),
				),
			);
			$headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $api_key,
			);
		} else {
			if ( empty( $endpoint ) ) {
				return new WP_Error( 'missing_endpoint', 'Custom API Endpoint URL is required.' );
			}
			$body    = array(
				'prompt'        => $full_prompt,
				'skill_slug'    => $params['skill_slug'] ?? '',
				'tag_slugs'     => $params['tag_slugs'] ?? array(),
				'difficulty'    => $params['difficulty_slug'] ?? ( $params['difficulty'] ?? '' ),
				'question_type' => $params['question_type'] ?? '',
				'code_language' => $params['code_language'] ?? '',
				'count'         => $params['count'] ?? 0,
			);
			$headers = array( 'Content-Type' => 'application/json' );
			if ( ! empty( $api_key ) ) {
				$headers['Authorization'] = 'Bearer ' . $api_key;
			}
		}

		$max_attempts = 3;
		$response     = null;
		$code         = 0;
		$raw_body     = '';

		for ( $attempt = 1; $attempt <= $max_attempts; $attempt++ ) {
			$response = wp_remote_post(
				$endpoint,
				array(
					'headers' => $headers,
					'body'    => wp_json_encode( $body ),
					'timeout' => 90,
				)
			);

			if ( is_wp_error( $response ) ) {
				if ( $attempt < $max_attempts ) {
					sleep( $attempt * 2 );
					continue;
				}
				return $response;
			}

			$code     = (int) wp_remote_retrieve_response_code( $response );
			$raw_body = wp_remote_retrieve_body( $response );

			if ( in_array( $code, array( 429, 500, 502, 503, 504 ), true ) && $attempt < $max_attempts ) {
				sleep( $attempt * 2 );
				continue;
			}
			break;
		}

		if ( $code < 200 || $code >= 300 ) {
			if ( 503 === $code ) {
				return new WP_Error(
					'api_http_503',
					"{$usage['provider_label']} returned HTTP 503 (Model temporarily overloaded after {$max_attempts} attempts). Try again shortly, reduce question count, or switch AI provider from the dropdown."
				);
			}
			return new WP_Error( 'api_http_error', "{$usage['provider_label']} returned HTTP {$code}. Please check the provider settings and try again." );
		}

		$questions = self::extract_json_questions( $raw_body, $provider );
		if ( is_wp_error( $questions ) ) {
			return $questions;
		}

		$units = max( 1, absint( $params['count'] ?? 1 ) );
		self::record_usage( $provider, $units );

		return array(
			'provider'  => $provider,
			'model'     => $model,
			'questions' => $questions,
		);
	}

	private static function build_structured_prompt( $params ) {
		$base_prompt = self::build_prompt_text( $params );

		return $base_prompt
			. '
OUTPUT FORMAT (STRICT JSON):
'
			. 'Return a JSON object with a single root key "questions" containing an array of objects. Each object has: "question", "question_type", "difficulty", "options" (array of {"key":"A","text":"..."}), "correct_answers" (array of option keys), "explanation" (clear explanation with example when helpful), "code_snippet" (string with \n or null), "code_language" (php|csharp|java|python|javascript|typescript|sql|html|css|json|bash or empty/null), and "tags" (array of valid tag slugs for the skill).'
			. '
Return ONLY that raw JSON document using the exact psm-[skill-slug]-[yyyy-mm-dd-HH-MM-SS].json filename format specified above, so it can be imported without edits (no Markdown fences, no prose before or after).';
	}

	private static function extract_json_questions( $raw_body, $provider ) {
		$raw_body = (string) $raw_body;
		$envelope = json_decode( $raw_body, true );

		// Gemini and OpenAI wrap the model output inside their own envelope; gather every text part.
		$content = '';
		if ( 'gemini' === $provider ) {
			$parts = $envelope['candidates'][0]['content']['parts'] ?? array();
			if ( is_array( $parts ) ) {
				$chunks = array();
				foreach ( $parts as $part ) {
					if ( isset( $part['text'] ) && is_string( $part['text'] ) ) {
						$chunks[] = $part['text'];
					}
				}
				$content = trim( implode( '', $chunks ) );
			}
		} elseif ( 'openai' === $provider ) {
			$message = $envelope['choices'][0]['message']['content'] ?? '';
			if ( is_array( $message ) ) {
				$chunks = array();
				foreach ( $message as $piece ) {
					if ( isset( $piece['text'] ) ) {
						$chunks[] = (string) $piece['text'];
					}
				}
				$content = trim( implode( '', $chunks ) );
			} else {
				$content = trim( (string) $message );
			}
		}

		if ( '' === $content ) {
			$content = trim( $raw_body );
		}

		$parsed = self::decode_json_payload( $content );
		if ( is_wp_error( $parsed ) ) {
			return $parsed;
		}

		if ( isset( $parsed['questions'] ) && is_array( $parsed['questions'] ) ) {
			return $parsed['questions'];
		}
		if ( is_array( $parsed ) && isset( $parsed[0] ) && is_array( $parsed[0] )
			&& ( isset( $parsed[0]['question_text'] ) || isset( $parsed[0]['question'] ) ) ) {
			return $parsed;
		}
		if ( is_array( $parsed ) && ( isset( $parsed['question_text'] ) || isset( $parsed['question'] ) ) ) {
			return array( $parsed );
		}

		return new WP_Error( 'missing_questions_array', 'JSON payload did not contain a valid "questions" array.' );
	}

	/**
	 * Decodes a JSON payload, tolerating Markdown fences or prose wrapped around the JSON.
	 *
	 * @return array|WP_Error
	 */
	private static function decode_json_payload( $content ) {
		$content = trim( (string) $content );
		$content = trim( (string) preg_replace( '/^```(?:json)?\s*|\s*```$/mi', '', $content ) );

		$parsed = json_decode( $content, true );
		if ( json_last_error() === JSON_ERROR_NONE ) {
			return $parsed;
		}

		foreach ( array(
			'{' => '}',
			'[' => ']',
		) as $open => $close ) {
			$start = strpos( $content, $open );
			$end   = strrpos( $content, $close );
			if ( false === $start || false === $end || $end <= $start ) {
				continue;
			}
			$parsed = json_decode( substr( $content, $start, $end - $start + 1 ), true );
			if ( json_last_error() === JSON_ERROR_NONE ) {
				return $parsed;
			}
		}

		return new WP_Error( 'invalid_payload_json', 'Could not parse generated JSON payload: ' . json_last_error_msg() );
	}
}
