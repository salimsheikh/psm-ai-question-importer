<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Custom table identifiers use fixed constants; all query values are prepared.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads are cached with PSM_Cache or intentionally fresh for writes, validation, exports, and schema checks.

/**
 * Validates and stores generated questions.
 *
 * The database contract (question types, option keys, option text, hash) must
 * stay identical to the Programming Skills Master plugin, which reads the same
 * psm_questions / psm_skills tables from the front end.
 */
class PSM_Importer {

	const MAX_QUESTION_TEXT = 5000;
	const MAX_OPTION_TEXT   = 500;
	const MAX_EXPLANATION   = 10000;
	const MAX_CODE_LENGTH   = 20000;

	/**
	 * Internal question types understood by the practice application.
	 *
	 * @return string[]
	 */
	public static function canonical_types() {
		return array( 'four_option', 'two_option', 'true_false' );
	}

	/**
	 * Map any AI/file type slug to the internal database slug.
	 *
	 * File-level single_choice / multiple_choice both become four_option; the
	 * number of correct answers is stored in is_multiple instead.
	 */
	public static function normalize_question_type_slug( $raw_type ) {
		$slug    = sanitize_key( (string) $raw_type );
		$aliases = array(
			'single'          => 'four_option',
			'four_option'     => 'four_option',
			'single_choice'   => 'four_option',
			'multiple'        => 'four_option',
			'multiple_select' => 'four_option',
			'multiple_choice' => 'four_option',
			'two-option'      => 'two_option',
			'two_option'      => 'two_option',
			'true-false'      => 'true_false',
			'true_false'      => 'true_false',
		);

		return $aliases[ $slug ] ?? 'four_option';
	}

	/**
	 * Difficulty is stored as 1-3 because that is all the practice UI supports.
	 */
	public static function normalize_difficulty_id( $raw_diff ) {
		if ( is_numeric( $raw_diff ) ) {
			return min( 3, max( 1, absint( $raw_diff ) ) );
		}

		$map = array(
			'beginner'     => 1,
			'easy'         => 1,
			'intermediate' => 2,
			'medium'       => 2,
			'advanced'     => 3,
			'hard'         => 3,
		);

		return $map[ strtolower( trim( (string) $raw_diff ) ) ] ?? 1;
	}

	/**
	 * Canonical tag slug in the PSM_Seeder convention: "<skill-slug>-<topic>".
	 *
	 * Accepts a topic ("String"), a fully prefixed slug ("csharp-string"), or the
	 * skill name prefixed text ("C# String"); repeated skill prefixes are collapsed.
	 */
	public static function normalize_tag_slug( $raw_tag, $skill_slug, $skill_name = '' ) {
		$raw = trim( (string) $raw_tag );
		if ( '' === $raw ) {
			return '';
		}

		$skill_slug = PSM_Seeder::make_slug( (string) $skill_slug );
		$skill_name = trim( (string) $skill_name );

		if ( '' !== $skill_name ) {
			$stripped = preg_replace(
				'/^\s*' . preg_quote( $skill_name, '/' ) . '\s*[-\/|:]*\s*/iu',
				'',
				$raw
			);
			if ( is_string( $stripped ) ) {
				$raw = $stripped;
			}
		}

		$slug = sanitize_title( $raw );
		if ( '' === $slug ) {
			return '';
		}

		$slug = str_replace( 'c-sharp', 'csharp', $slug );

		if ( '' === $skill_slug ) {
			return $slug;
		}

		$prefix = $skill_slug . '-';
		while ( 0 === strpos( $slug, $prefix ) && strlen( $slug ) > strlen( $prefix ) ) {
			$slug = substr( $slug, strlen( $prefix ) );
		}
		if ( '' === $slug || $slug === $skill_slug ) {
			return '';
		}

		return $prefix . $slug;
	}

	/**
	 * Apply normalize_tag_slug() to every question in a list.
	 *
	 * @param mixed  $questions  Raw question list.
	 * @param string $skill_slug Skill slug.
	 * @param string $skill_name Optional skill display name.
	 * @return mixed
	 */
	public static function normalize_question_tags( $questions, $skill_slug, $skill_name = '' ) {
		if ( ! is_array( $questions ) ) {
			return $questions;
		}

		foreach ( $questions as $idx => $item ) {
			if ( ! is_array( $item ) || empty( $item['tags'] ) ) {
				continue;
			}
			if ( ! is_array( $item['tags'] ) ) {
				$questions[ $idx ]['tags'] = array();
				continue;
			}

			$tags = array();
			foreach ( $item['tags'] as $raw_tag ) {
				$tag_slug = self::normalize_tag_slug( $raw_tag, $skill_slug, $skill_name );
				if ( '' !== $tag_slug ) {
					$tags[] = $tag_slug;
				}
			}
			$questions[ $idx ]['tags'] = array_values( array_unique( $tags ) );
		}

		return $questions;
	}

	/**
	 * Plain text sanitizer that keeps angle brackets (List<int>, a<b).
	 *
	 * Stored text is always escaped on output, so HTML must not be stripped here.
	 */
	public static function sanitize_plain( $value, $multiline = false ) {
		$value = wp_check_invalid_utf8( (string) $value, true );
		$value = str_replace( array( "\r\n", "\r" ), "\n", $value );
		$value = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value );
		if ( ! $multiline ) {
			$value = preg_replace( '/\s+/u', ' ', (string) $value );
		}
		return trim( (string) $value );
	}

	/**
	 * Explanation sanitizer: keeps a small formatting whitelist and escapes every
	 * other angle bracket so generics stay readable.
	 */
	public static function sanitize_rich_text( $value ) {
		$value  = self::sanitize_plain( $value, true );
		$tokens = array();

		$value = preg_replace_callback(
			'#</?(?:code|strong|em|b|i|pre|br|ul|ol|li|p)\s*/?>#i',
			static function ( $matches ) use ( &$tokens ) {
				$token            = "\x01" . count( $tokens ) . "\x02";
				$tokens[ $token ] = $matches[0];
				return $token;
			},
			$value
		);

		$value = htmlspecialchars( (string) $value, ENT_NOQUOTES, 'UTF-8', false );
		$value = strtr( $value, $tokens );

		return trim( wp_kses_post( $value ) );
	}

	/**
	 * Same fingerprint the practice plugin uses, so duplicates are shared.
	 */
	public static function content_hash( $type, $text, $code, array $options, array $correct ) {
		$normalise = static function ( $value ) {
			return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', (string) $value ) ) );
		};

		ksort( $options );
		$parts = array();
		foreach ( $options as $key => $value ) {
			$parts[] = $key . '=' . $normalise( $value );
		}

		$correct = array_map( 'strval', $correct );
		sort( $correct );

		return sha1(
			implode(
				"\n",
				array(
					$type,
					$normalise( $text ),
					$normalise( $code ),
					implode( '|', $parts ),
					implode( ',', $correct ),
				)
			)
		);
	}

	private static function is_list( array $value ) {
		if ( function_exists( 'array_is_list' ) ) {
			return array_is_list( $value );
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}

	/**
	 * Normalise option input into an ordered map of keys => plain text.
	 *
	 * Accepts [{"key":"A","text":"..."}], {"A":"..."} and ["...","..."].
	 *
	 * @return array{0: array<string,string>, 1: string[]}
	 */
	public static function normalize_options( $raw_options, $type ) {
		$errors = array();

		if ( 'true_false' === $type ) {
			return array(
				array(
					'A' => 'True',
					'B' => 'False',
				),
				$errors,
			);
		}

		$required = ( 'two_option' === $type ) ? array( 'A', 'B' ) : array( 'A', 'B', 'C', 'D' );
		$items    = is_array( $raw_options ) ? $raw_options : array();

		if ( empty( $items ) ) {
			return array( array(), array( 'Missing answer options.' ) );
		}

		$normalized = array();
		$index      = 0;
		foreach ( $items as $opt_key => $opt_val ) {
			$key  = '';
			$text = '';

			if ( is_array( $opt_val ) && isset( $opt_val['key'] ) ) {
				$key  = strtoupper( sanitize_key( (string) $opt_val['key'] ) );
				$text = (string) ( $opt_val['text'] ?? '' );
			} elseif ( is_array( $opt_val ) ) {
				$key  = strtoupper( sanitize_key( (string) $opt_key ) );
				$text = (string) ( $opt_val['text'] ?? '' );
			} elseif ( self::is_list( $items ) ) {
				$key  = $required[ $index ] ?? '';
				$text = (string) $opt_val;
			} else {
				$key  = strtoupper( sanitize_key( (string) $opt_key ) );
				$text = (string) $opt_val;
			}

			++$index;
			if ( '' === $key || ! in_array( $key, $required, true ) ) {
				continue;
			}

			$text = self::sanitize_plain( $text );
			if ( '' === $text ) {
				$errors[] = "Option {$key} is empty.";
				continue;
			}
			if ( mb_strlen( $text ) > self::MAX_OPTION_TEXT ) {
				$errors[] = "Option {$key} is longer than " . self::MAX_OPTION_TEXT . ' characters.';
				continue;
			}

			$normalized[ $key ] = $text;
		}

		foreach ( $required as $key ) {
			if ( ! isset( $normalized[ $key ] ) && ! in_array( "Option {$key} is empty.", $errors, true ) ) {
				$errors[] = "Missing option {$key}.";
			}
		}

		$ordered = array();
		foreach ( $required as $key ) {
			if ( isset( $normalized[ $key ] ) ) {
				$ordered[ $key ] = $normalized[ $key ];
			}
		}

		$seen = array();
		foreach ( $ordered as $key => $text ) {
			$needle = mb_strtolower( $text );
			if ( isset( $seen[ $needle ] ) ) {
				$errors[] = "Options {$seen[ $needle ]} and {$key} contain the same text.";
			} else {
				$seen[ $needle ] = $key;
			}
		}

		return array( $ordered, $errors );
	}

	/**
	 * Resolve the correct answers to existing option keys.
	 *
	 * Accepts keys ("A"), answer text ("True"), or comma separated keys.
	 *
	 * @return array{0: string[], 1: string[]}
	 */
	public static function normalize_correct( $raw_correct, array $options ) {
		$errors = array();
		$raw    = $raw_correct;

		if ( is_string( $raw ) ) {
			$raw = preg_split( '/[,;|]/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$by_text = array();
		foreach ( $options as $key => $text ) {
			$by_text[ mb_strtolower( $text ) ] = $key;
		}

		$correct = array();
		foreach ( $raw as $value ) {
			if ( is_array( $value ) ) {
				$value = $value['key'] ?? ( $value['text'] ?? '' );
			}
			$value = trim( (string) $value );
			if ( '' === $value ) {
				continue;
			}

			$key = strtoupper( sanitize_key( $value ) );
			if ( isset( $options[ $key ] ) ) {
				$correct[] = $key;
				continue;
			}

			$text_key = $by_text[ mb_strtolower( $value ) ] ?? '';
			if ( '' !== $text_key ) {
				$correct[] = $text_key;
				continue;
			}

			$errors[] = "Correct answer \"{$value}\" does not match any option key or option text.";
		}

		$correct = array_values( array_unique( $correct ) );
		sort( $correct );

		if ( empty( $correct ) && empty( $errors ) ) {
			$errors[] = 'Missing correct answer.';
		}

		return array( $correct, $errors );
	}

	/**
	 * Validate and normalise one generated question without touching the database.
	 *
	 * @param mixed  $item                Raw question from AI/manual JSON.
	 * @param string $default_status      draft|published.
	 * @param int    $default_difficulty  Fallback difficulty.
	 * @return array{data: array|null, errors: string[]}
	 */
	public static function inspect_question( $item, $default_status = 'draft', $default_difficulty = 1 ) {
		$errors = array();

		if ( ! is_array( $item ) ) {
			return array(
				'data'   => null,
				'errors' => array( 'Invalid question item format.' ),
			);
		}

		$raw_text      = $item['question_text'] ?? ( $item['question'] ?? '' );
		$question_text = self::sanitize_plain( $raw_text, true );
		if ( '' === $question_text ) {
			$errors[] = 'Missing question text.';
		} elseif ( mb_strlen( $question_text ) > self::MAX_QUESTION_TEXT ) {
			$errors[] = 'Question text is longer than ' . self::MAX_QUESTION_TEXT . ' characters.';
		}

		$question_type = self::normalize_question_type_slug( $item['question_type'] ?? 'four_option' );

		$difficulty = array_key_exists( 'difficulty', $item ) && '' !== $item['difficulty']
			? self::normalize_difficulty_id( $item['difficulty'] )
			: self::normalize_difficulty_id( $default_difficulty );

		$code = '';
		if ( isset( $item['code_snippet'] ) && null !== $item['code_snippet'] ) {
			$code = str_replace( array( "\r\n", "\r" ), "\n", wp_check_invalid_utf8( (string) $item['code_snippet'], true ) );
			$code = rtrim( preg_replace( '/\x00/', '', $code ) );
		}
		if ( mb_strlen( $code ) > self::MAX_CODE_LENGTH ) {
			$errors[] = 'Code snippet is longer than ' . self::MAX_CODE_LENGTH . ' characters.';
		}

		$code_language = isset( $item['code_language'] ) ? sanitize_key( (string) $item['code_language'] ) : '';
		if ( ! array_key_exists( $code_language, PSM_Languages::languages() ) ) {
			$code_language = '';
		}
		if ( '' === $code ) {
			$code_language = '';
		}

		$explanation = self::sanitize_rich_text( $item['explanation'] ?? '' );
		if ( mb_strlen( $explanation ) > self::MAX_EXPLANATION ) {
			$errors[] = 'Explanation is longer than ' . self::MAX_EXPLANATION . ' characters.';
		}

		list( $options, $option_errors ) = self::normalize_options( $item['options'] ?? array(), $question_type );
		$errors                          = array_merge( $errors, $option_errors );

		$raw_correct                      = $item['correct_keys'] ?? ( $item['correct_answers'] ?? array() );
		list( $correct, $correct_errors ) = self::normalize_correct( $raw_correct, $options );
		$errors                           = array_merge( $errors, $correct_errors );

		$answer_mode = strtolower( trim( (string) ( $item['answer_mode'] ?? '' ) ) );
		$is_multiple = ( 'four_option' === $question_type && count( $correct ) > 1 ) ? 1 : 0;

		if ( 'multiple' === $answer_mode && 'four_option' === $question_type ) {
			$is_multiple = 1;
		}
		if ( 'multiple' === $answer_mode && count( $correct ) < 2 ) {
			$errors[] = 'A multiple-answer question needs at least two correct answers.';
		}
		if ( 'four_option' === $question_type ) {
			if ( ! $is_multiple && 1 !== count( $correct ) ) {
				$errors[] = 'A single-choice question must have exactly one correct answer.';
			}
			if ( count( $correct ) > 3 ) {
				$errors[] = 'A multiple-answer question supports at most three correct answers.';
			}
		} elseif ( 1 !== count( $correct ) ) {
			$errors[] = 'This question type requires exactly one correct answer.';
		}

		$status = in_array( $default_status, array( 'draft', 'published' ), true ) ? $default_status : 'draft';

		$external_id = isset( $item['external_id'] )
			? sanitize_text_field( mb_substr( (string) $item['external_id'], 0, 100 ) )
			: '';

		if ( ! empty( $errors ) ) {
			return array(
				'data'   => null,
				'errors' => $errors,
			);
		}

		return array(
			'data'   => array(
				'question_text' => $question_text,
				'question_type' => $question_type,
				'difficulty'    => $difficulty,
				'code_snippet'  => $code,
				'code_language' => $code_language,
				'explanation'   => $explanation,
				'options'       => $options,
				'correct_keys'  => $correct,
				'is_multiple'   => $is_multiple,
				'status'        => $status,
				'external_id'   => $external_id,
				'content_hash'  => self::content_hash( $question_type, $question_text, $code, $options, $correct ),
			),
			'errors' => array(),
		);
	}

	public static function import_questions( $questions, $skill_id, $forced_tag_ids = array(), $default_status = 'draft', $batch_meta = array() ) {
		global $wpdb;

		$skills_table          = PSM_AI_TABLE_PREFIX . 'skills';
		$tags_table            = PSM_AI_TABLE_PREFIX . 'tags';
		$questions_table       = PSM_AI_TABLE_PREFIX . 'questions';
		$question_tags_table   = PSM_AI_TABLE_PREFIX . 'question_tags';
		$batches_table         = PSM_AI_TABLE_PREFIX . 'batches';
		$batch_questions_table = PSM_AI_TABLE_PREFIX . 'batch_questions';

		$skill_id = absint( $skill_id );
		$skill    = PSM_Cache::get_row( $wpdb->prepare( "SELECT * FROM $skills_table WHERE id = %d", $skill_id ), array(), false );

		if ( ! $skill ) {
			return new WP_Error( 'invalid_skill', 'Target Skill ID does not exist.' );
		}

		// Load ONLY tags belonging to this exact Skill ID (Backend Ownership Enforcement)
		$valid_skill_tags = array_column(
			PSM_Cache::get_results(
				$wpdb->prepare( "SELECT id, slug FROM $tags_table WHERE skill_id = %d AND status = 'active'", $skill_id ),
				array(),
				false
			),
			null,
			'id'
		);

		$slug_to_tag_id = array();
		$valid_tag_ids  = array();
		foreach ( $valid_skill_tags as $tid => $trow ) {
			$slug_to_tag_id[ $trow->slug ] = (int) $tid;
			$valid_tag_ids[]               = (int) $tid;
		}

		$sanitized_forced_tags = array();
		foreach ( (array) $forced_tag_ids as $ftid ) {
			$ftid = absint( $ftid );
			if ( in_array( $ftid, $valid_tag_ids, true ) ) {
				$sanitized_forced_tags[] = $ftid;
			}
		}

		// Shared tables store UTC, matching the practice plugin.
		$now     = current_time( 'mysql', true );
		$user_id = get_current_user_id();

		$batch_code = ! empty( $batch_meta['batch_code'] )
			? sanitize_text_field( $batch_meta['batch_code'] )
			: 'BATCH-' . strtoupper( wp_generate_password( 8, false, false ) );
		$label_name = ! empty( $batch_meta['label_name'] )
			? sanitize_text_field( $batch_meta['label_name'] )
			: $skill->name . ' - ' . wp_date( 'Y-m-d H:i' );

		$batch_ok = PSM_Database::insert(
			$batches_table,
			array(
				'batch_code'      => $batch_code,
				'label_name'      => $label_name,
				'skill_id'        => $skill_id,
				'provider'        => sanitize_text_field( $batch_meta['provider'] ?? 'manual' ),
				'model'           => sanitize_text_field( $batch_meta['model'] ?? '' ),
				'prompt_text'     => sanitize_textarea_field( $batch_meta['prompt_text'] ?? '' ),
				'rules_text'      => sanitize_textarea_field( $batch_meta['rules_text'] ?? '' ),
				'total_generated' => count( (array) $questions ),
				'total_imported'  => 0,
				'total_skipped'   => 0,
				'status'          => 'completed',
				'created_by'      => $user_id,
				'created_at'      => $now,
				'updated_at'      => $now,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s', '%d', '%s', '%s' )
		);

		$batch_id = $batch_ok ? (int) $wpdb->insert_id : 0;

		$result = array(
			'batch_id'          => $batch_id,
			'batch_code'        => $batch_code,
			'inserted'          => 0,
			'skipped_duplicate' => 0,
			'skipped_invalid'   => 0,
			'created_tags'      => array(),
			'rejected_tags'     => array(),
			'messages'          => array(),
		);

		if ( ! $batch_ok ) {
			$result['messages'][] = 'Batch record could not be created: ' . $wpdb->last_error;
		}

		$default_difficulty = self::normalize_difficulty_id( $batch_meta['default_difficulty'] ?? 1 );

		$order_num = 1;
		foreach ( (array) $questions as $idx => $item ) {
			$row_label = 'Row #' . ( $idx + 1 );
			$inspect   = self::inspect_question( $item, $default_status, $default_difficulty );

			if ( empty( $inspect['data'] ) ) {
				++$result['skipped_invalid'];
				$result['messages'][] = $row_label . ': ' . implode( ' ', $inspect['errors'] );
				continue;
			}

			$data         = $inspect['data'];
			$content_hash = $data['content_hash'];

			$duplicate_question_id = (int) PSM_Cache::get_var(
				$wpdb->prepare(
					"SELECT id FROM $questions_table WHERE skill_id = %d AND content_hash = %s LIMIT 1",
					$skill_id,
					$content_hash
				),
				array(),
				false
			);

			if ( ! $duplicate_question_id && ! empty( $data['external_id'] ) ) {
				$duplicate_question_id = (int) PSM_Cache::get_var(
					$wpdb->prepare(
						"SELECT id FROM $questions_table WHERE skill_id = %d AND external_id = %s LIMIT 1",
						$skill_id,
						$data['external_id']
					),
					array(),
					false
				);
			}

			$item_tag_slugs = array();
			if ( ! empty( $item['tags'] ) && is_array( $item['tags'] ) ) {
				foreach ( $item['tags'] as $raw_tag ) {
					$tag_slug = self::normalize_tag_slug( $raw_tag, $skill->slug, $skill->name );
					if ( '' !== $tag_slug ) {
						$item_tag_slugs[] = $tag_slug;
					}
				}
			}
			$item_tag_slugs = array_values( array_unique( $item_tag_slugs ) );

			if ( $duplicate_question_id ) {
				++$result['skipped_duplicate'];
				$question_id = $duplicate_question_id;
			} else {
				$inserted = PSM_Database::insert(
					$questions_table,
					array(
						'skill_id'      => $skill_id,
						'difficulty'    => $data['difficulty'],
						'question_type' => $data['question_type'],
						'question_text' => $data['question_text'],
						'code_snippet'  => $data['code_snippet'],
						'code_language' => $data['code_language'],
						'image_id'      => 0,
						'options'       => wp_json_encode( $data['options'] ),
						'correct_keys'  => wp_json_encode( $data['correct_keys'] ),
						'is_multiple'   => $data['is_multiple'],
						'explanation'   => $data['explanation'],
						'status'        => $data['status'],
						'created_by'    => $user_id,
						'created_at'    => $now,
						'updated_at'    => $now,
						'external_id'   => $data['external_id'],
						'content_hash'  => $content_hash,
					),
					array( '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' )
				);

				if ( ! $inserted ) {
					++$result['skipped_invalid'];
					$result['messages'][] = $row_label . ': Database insert error: ' . $wpdb->last_error;
					continue;
				}

				$question_id = (int) $wpdb->insert_id;
			}

			$resolved_tag_ids = $sanitized_forced_tags;

			foreach ( $item_tag_slugs as $tag_slug ) {
				$tag_id = isset( $slug_to_tag_id[ $tag_slug ] ) ? (int) $slug_to_tag_id[ $tag_slug ] : 0;

				if ( ! $tag_id ) {
					$tag_id = self::resolve_or_create_tag( $tag_slug, $skill, $tags_table, $now, $result['created_tags'] );
					if ( $tag_id ) {
						$slug_to_tag_id[ $tag_slug ] = $tag_id;
					} else {
						$result['rejected_tags'][] = $tag_slug;
					}
				}

				if ( $tag_id ) {
					$resolved_tag_ids[] = $tag_id;
				}
			}
			$resolved_tag_ids = array_values( array_unique( array_map( 'intval', $resolved_tag_ids ) ) );

			foreach ( $resolved_tag_ids as $tid ) {
				PSM_Database::replace(
					$question_tags_table,
					array(
						'question_id' => $question_id,
						'tag_id'      => $tid,
					),
					array( '%d', '%d' )
				);
			}

			if ( $duplicate_question_id ) {
				continue;
			}

			if ( $batch_id > 0 ) {
				PSM_Database::replace(
					$batch_questions_table,
					array(
						'batch_id'      => $batch_id,
						'question_id'   => $question_id,
						'import_order'  => $order_num++,
						'import_status' => 'imported',
						'created_at'    => $now,
					),
					array( '%d', '%d', '%d', '%s', '%s' )
				);
			}

			++$result['inserted'];
		}

		if ( $batch_id > 0 ) {
			$total_skipped = $result['skipped_duplicate'] + $result['skipped_invalid'];
			PSM_Database::update(
				$batches_table,
				array(
					'total_imported' => $result['inserted'],
					'total_skipped'  => $total_skipped,
					'updated_at'     => $now,
				),
				array( 'id' => $batch_id ),
				array( '%d', '%d', '%s' ),
				array( '%d' )
			);
		}

		$result['created_tags']  = array_values( array_unique( $result['created_tags'] ) );
		$result['rejected_tags'] = array_values( array_unique( $result['rejected_tags'] ) );
		PSM_Cache::delete_all();
		return $result;
	}

	/**
	 * Return the id of an existing skill-owned tag, or create it on demand.
	 *
	 * New tags keep the PSM_Seeder "<skill-slug>-<topic>" convention. A slug
	 * already owned by another skill or an inactive tag is never reassigned or reused.
	 *
	 * @param string   $tag_slug Normalized tag slug.
	 * @param object   $skill    Skill row (id, name, slug).
	 * @param string   $tags_table Tags table name.
	 * @param string   $now      UTC timestamp.
	 * @param string[] $created  Collector for newly created slugs.
	 */
	public static function resolve_or_create_tag( $tag_slug, $skill, $tags_table, $now, &$created ) {
		global $wpdb;

		$tag_slug = sanitize_title( (string) $tag_slug );
		if ( '' === $tag_slug ) {
			return 0;
		}

		$existing = PSM_Cache::get_row( $wpdb->prepare( "SELECT id, skill_id, status FROM $tags_table WHERE slug = %s LIMIT 1", $tag_slug ), array(), false );
		if ( $existing ) {
			return ( (int) $existing->skill_id === (int) $skill->id && 'active' === $existing->status ) ? (int) $existing->id : 0;
		}

		$topic  = (string) $tag_slug;
		$prefix = $skill->slug . '-';
		if ( '' !== (string) $skill->slug && 0 === strpos( $topic, $prefix ) ) {
			$topic = substr( $topic, strlen( $prefix ) );
		}
		$name = ucwords( trim( str_replace( array( '-', '_' ), ' ', $topic ) ) );
		if ( '' === $name ) {
			$name = $tag_slug;
		}

		$max_sort = (int) PSM_Cache::get_var( $wpdb->prepare( "SELECT MAX(sort_order) FROM $tags_table WHERE skill_id = %d", (int) $skill->id ), array(), false );

		$ok = PSM_Database::insert(
			$tags_table,
			array(
				'skill_id'    => (int) $skill->id,
				'name'        => $name,
				'slug'        => $tag_slug,
				'description' => 'Auto-created from imported questions for ' . $skill->name . '.',
				'sort_order'  => $max_sort + 10,
				'status'      => 'active',
				'created_at'  => $now,
				'updated_at'  => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
		);

		if ( ! $ok ) {
			// Unique slug race: another request may have just inserted it.
			$existing = PSM_Cache::get_row( $wpdb->prepare( "SELECT id, skill_id, status FROM $tags_table WHERE slug = %s LIMIT 1", $tag_slug ), array(), false );
			return ( $existing && (int) $existing->skill_id === (int) $skill->id && 'active' === $existing->status ) ? (int) $existing->id : 0;
		}

		$created[] = $tag_slug;
		return (int) $wpdb->insert_id;
	}
}
