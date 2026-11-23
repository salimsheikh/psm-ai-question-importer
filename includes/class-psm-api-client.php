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
			'gemini' => 'gemini-3.8-flash',
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
			'model'        => $prov_cfg['model'] ?? ( self::get_default_models()[ $provider ] ?? 'gemini-3.8-flash' ),
			'endpoint_url' => $prov_cfg['endpoint_url'] ?? '',
			'daily_limit'  => isset( $limits['daily_limit'] ) ? max( 1, absint( $limits['daily_limit'] ) ) : 200,
			'weekly_limit' => isset( $limits['weekly_limit'] ) ? max( 1, absint( $limits['weekly_limit'] ) ) : 1000,
		);
	}

	public static function get_usage_status( $provider = '' ) {
		$settings = get_option( 'psm_ai_api_settings', array() );
		$provider = $provider ?: ( ! empty( $settings['provider'] ) ? $settings['provider'] : 'gemini' );
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
		if ( $days_until_mon === 0 ) {
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
		$allowed_tags   = is_array( $params['allowed_tag_slugs'] ?? null ) ? $params['allowed_tag_slugs'] : array();
		$selected_tags  = is_array( $params['tag_slugs'] ?? null ) ? $params['tag_slugs'] : array();

		$lines   = array();
		$lines[] = sprintf(
			'You are a senior software engineer, technical interviewer and programming instructor. Create %d high-quality multiple-choice questions (MCQs) for the skill "%s".',
			$count,
			$skill_name
		);
		$lines[] = '';
		$lines[] = 'CONTEXT';
		$lines[] = '- Skill: ' . $skill_name . ( $skill_slug ? ' (slug: ' . $skill_slug . ')' : '' );

		$d_str = strtolower( (string) $difficulty_raw );
		if ( $d_str === '1' || $d_str === 'beginner' ) {
			$lines[] = '- Difficulty: beginner (fundamental concepts, basic syntax and common beginner mistakes). Every question must have "difficulty": "beginner".';
		} elseif ( $d_str === '2' || $d_str === 'intermediate' ) {
			$lines[] = '- Difficulty: intermediate (practical application, edge cases, idiomatic patterns and debugging). Every question must have "difficulty": "intermediate".';
		} elseif ( $d_str === '3' || $d_str === 'advanced' ) {
			$lines[] = '- Difficulty: advanced (internals, performance trade-offs, concurrency/architecture and subtle pitfalls). Every question must have "difficulty": "advanced".';
		} else {
			$lines[] = '- Difficulty: all levels. Spread the questions evenly across "beginner", "intermediate" and "advanced" and set each questions "difficulty" to the level it belongs to.';
		}

		if ( $diff_label !== '' ) {
			$lines[] = '- Difficulty Reference: ' . $diff_label;
		}
		if ( ! empty( $selected_tags ) ) {
			$lines[] = '- Selected Tags: ' . implode( ', ', $selected_tags );
		}
		if ( ! empty( $allowed_tags ) ) {
			$lines[] = '- Allowed Tag Slugs (STRICT - only use valid tag slugs belonging strictly to the chosen skill): ' . implode( ', ', $allowed_tags );
		}
		if ( $user_prompt !== '' ) {
			$lines[] = '- Focus / Topic Instructions: ' . $user_prompt;
		}

		$lines[] = '';
		$lines[] = 'QUESTION TYPES AND DISTRIBUTION';
		$dist = self::calculate_distribution( $count, $types );
		foreach ( $dist as $t_slug => $t_cnt ) {
			if ( $t_slug === 'single_choice' || $t_slug === 'four_option' ) {
				$lines[] = sprintf( '- %s: %d questions, exactly four options (keys A, B, C, D) and exactly one correct answer; "answer_mode": "single".', $t_slug, $t_cnt );
			} elseif ( $t_slug === 'multiple_choice' || $t_slug === 'multiple_select' ) {
				$lines[] = sprintf( '- %s: %d questions, exactly four options (keys A, B, C, D) and two or three correct answers; "answer_mode": "multiple".', $t_slug, $t_cnt );
			} elseif ( $t_slug === 'two_option' ) {
				$lines[] = sprintf( '- two_option: %d questions, exactly two options (keys A, B) and exactly one correct answer; "answer_mode": "single".', $t_cnt );
			} elseif ( $t_slug === 'true_false' ) {
				$lines[] = sprintf( '- true_false: %d questions, exactly two options: A = "True" and B = "False", and exactly one correct answer; "answer_mode": "single".', $t_cnt );
			} else {
				$lines[] = sprintf( '- %s: %d questions.', $t_slug, $t_cnt );
			}
		}

		$lines[] = '';
		$lines[] = 'CURRENT SKILL & PROMPT RULES';
		if ( $rules_text !== '' ) {
			$lines[] = $rules_text;
		} else {
			$lines[] = '1. Every question must be completely unique-never repeat scenarios, variable names, or reworded versions of existing questions.';
			$lines[] = '2. Distractors (wrong options) must be plausible technical misconceptions, not obvious throwaways.';
			$lines[] = '3. Provide a clear, concise explanation for why the correct key is accurate.';
			$lines[] = '4. Assign only valid tag slugs belonging strictly to the chosen skill.';
		}

		$lines[] = '';
		$lines[] = 'RULES';
		$lines[] = '3. "question_type" must be one of: single_choice, multiple_choice, two_option, true_false, and each question must follow the option and answer rules listed above.';
		$lines[] = '4. "options" is a list of objects with "key" and "text". "correct_answers" is a list of option keys, and every key must exist in "options".';
		$lines[] = '5. "difficulty" must be exactly one of: beginner, intermediate, advanced (lowercase).';
		$lines[] = '6. Every question needs a clear "explanation" (1 to 5 sentences) that says why the correct answer is right and, where useful, why the others are wrong. or make bulleted and with example.';
		$lines[] = '7. Write "question" and every option "text" as plain text. Do not use Markdown or HTML formatting. Angle brackets such as List<int> are fine as plain text.';
		$lines[] = '8. To include code, put it in "code_snippet" as one string with line breaks written as \n, and set "code_language" to one of: php, csharp, java, python, javascript, typescript, sql, html, css, json, bash. Use null for "code_snippet" when there is no code.';
		$lines[] = '9. Do not create duplicate or nearly identical questions. Cover different concepts, and vary the position of the correct answer.';
		$lines[] = '10. Make wrong options plausible but clearly wrong. Do not use "All of the above" or "None of the above".';
		$lines[] = '11. Be technically accurate. Do not invent APIs, behaviour or facts. If you are unsure about a detail, ask a different question.';
		$lines[] = '12. The JSON must be valid: escape double quotes inside strings, do not add comments, and do not leave trailing commas.';

		return implode( "\n", $lines );
	}

	public static function generate_questions( $params, $override_provider = '' ) {
		$settings = get_option( 'psm_ai_api_settings', array() );
		$provider = $override_provider ?: ( ! empty( $settings['provider'] ) ? $settings['provider'] : 'gemini' );

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

		if ( empty( $api_key ) && $provider !== 'custom' ) {
			return new WP_Error( 'missing_api_key', "API Key for {$usage['provider_label']} is not configured. Save it in Tab 4." );
		}

		$full_prompt = self::build_structured_prompt( $params );

		if ( $provider === 'gemini' ) {
			if ( empty( $endpoint ) ) {
				$endpoint = sprintf(
					'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s',
					rawurlencode( $model ),
					rawurlencode( $api_key )
				);
			}
			$body = array(
				'contents' => array(
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
		} elseif ( $provider === 'openai' ) {
			if ( empty( $endpoint ) ) {
				$endpoint = 'https://api.openai.com/v1/chat/completions';
			}
			$body = array(
				'model'           => $model ?: 'gpt-4o-mini',
				'response_format' => array( 'type' => 'json_object' ),
				'messages'        => array(
					array( 'role' => 'system', 'content' => 'You are an expert assessment author. Output strictly valid JSON matching the requested schema.' ),
					array( 'role' => 'user', 'content' => $full_prompt ),
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
			$body = array(
				'prompt'        => $full_prompt,
				'skill_slug'    => $params['skill_slug'],
				'tag_slugs'     => $params['tag_slugs'],
				'difficulty'    => $params['difficulty'],
				'question_type' => $params['question_type'] ?? '',
				'count'         => $params['count'],
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
			if ( $code === 503 ) {
				return new WP_Error(
					'api_http_503',
					"{$usage['provider_label']} returned HTTP 503 (Model temporarily overloaded after {$max_attempts} attempts). Try again shortly, reduce question count, or switch AI provider from the dropdown."
				);
			}
			return new WP_Error( 'api_http_error', "{$usage['provider_label']} returned HTTP {$code}: " . substr( $raw_body, 0, 400 ) );
		}

		$units = max( 1, absint( $params['count'] ?? 1 ) );
		self::record_usage( $provider, $units );

		$questions = self::extract_json_questions( $raw_body, $provider );
		if ( is_wp_error( $questions ) ) {
			return $questions;
		}

		return array(
			'provider'  => $provider,
			'model'     => $model,
			'questions' => $questions,
		);
	}

	private static function build_structured_prompt( $params ) {
		$base_prompt = self::build_prompt_text( $params );

		return $base_prompt
			. "
OUTPUT FORMAT (STRICT JSON):
"
			. 'Return a JSON object with a single root key "questions" containing an array of objects. Each object has: "question", "question_type", "difficulty", "options" (array of {"key":"A","text":"..."}), "correct_answers" (array of option keys), "explanation" (clear explanation with example when helpful), "code_snippet" (string with \n or null), "code_language" (php|csharp|java|python|javascript|typescript|sql|html|css|json|bash or empty/null), and "tags" (array of valid tag slugs for the skill).';
	}

	private static function extract_json_questions( $raw_body, $provider ) {
		$decoded = json_decode( $raw_body, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_api_json', 'API response was not valid JSON.' );
		}

		$content_json = $raw_body;
		if ( $provider === 'gemini' && isset( $decoded['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$content_json = $decoded['candidates'][0]['content']['parts'][0]['text'];
		} elseif ( $provider === 'openai' && isset( $decoded['choices'][0]['message']['content'] ) ) {
			$content_json = $decoded['choices'][0]['message']['content'];
		}

		$content_json = preg_replace( '/^```(?:json)?\s*|\s*```$/m', '', trim( $content_json ) );
		$parsed       = json_decode( $content_json, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'invalid_payload_json', 'Could not parse generated JSON payload: ' . json_last_error_msg() );
		}

		if ( isset( $parsed['questions'] ) && is_array( $parsed['questions'] ) ) {
			return $parsed['questions'];
		}
		if ( is_array( $parsed ) && ( isset( $parsed[0]['question_text'] ) || isset( $parsed[0]['question'] ) ) ) {
			return $parsed;
		}
		if ( is_array( $parsed ) && ( isset( $parsed['question_text'] ) || isset( $parsed['question'] ) ) ) {
			return array( $parsed );
		}

		return new WP_Error( 'missing_questions_array', 'JSON payload did not contain a valid "questions" array.' );
	}
}
