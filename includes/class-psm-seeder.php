<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class PSM_Seeder {

	/**
	 * Generates a clean slug while enforcing fixed slugs:
	 * - Entity Framework Core -> efcore
	 * - C# -> csharp
	 * - C# OOP -> csharp-oop
	 * - Laravel -> laravel
	 */
	public static function make_slug( $raw_slug, $fallback_name = '' ) {
		$source = trim( (string) ( $raw_slug !== '' ? $raw_slug : $fallback_name ) );
		$lower  = strtolower( $source );

		if ( $lower === 'entity framework core' || $lower === 'efcore' || $lower === 'ef-core' || $lower === 'entity-framework-core' ) {
			return 'efcore';
		}
		if ( $lower === 'c# oop' || $lower === 'csharp oop' || $lower === 'csharp-oop' || $lower === 'c-sharp-oop' ) {
			return 'csharp-oop';
		}
		if ( $lower === 'c#' || $lower === 'csharp' || $lower === 'c-sharp' ) {
			return 'csharp';
		}
		if ( $lower === 'laravel' || $lower === 'laravel-php' || $lower === 'larave-php' || $lower === 'larvel' ) {
			return 'laravel';
		}

		// Replace # with sharp before sanitize_title so C# never collapses to 'c'
		$replaced = str_replace( '#', 'sharp', $source );
		return sanitize_title( $replaced );
	}

	/**
	 * Enforces fixed skill slugs and tag slug corrections across existing rows.
	 */
	public static function enforce_fixed_slugs_and_tags() {
		global $wpdb;
		$skills_table = $wpdb->prefix . 'psm_skills';
		$tags_table   = $wpdb->prefix . 'psm_tags';

		$fixed_skills = array(
			'Entity Framework Core' => 'efcore',
			'C#'                    => 'csharp',
			'C# OOP'                => 'csharp-oop',
		);

		foreach ( $fixed_skills as $skill_name => $target_slug ) {
			$by_name = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $skills_table WHERE LOWER(name) = LOWER(%s) LIMIT 1", $skill_name ) );
			$by_slug = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $skills_table WHERE slug = %s LIMIT 1", $target_slug ) );

			if ( $by_name ) {
				if ( $by_slug && (int) $by_slug->id !== (int) $by_name->id ) {
					$wpdb->delete( $skills_table, array( 'id' => (int) $by_slug->id ), array( '%d' ) );
				}
				if ( $by_name->slug !== $target_slug ) {
					$wpdb->update(
						$skills_table,
						array( 'slug' => $target_slug ),
						array( 'id' => (int) $by_name->id ),
						array( '%s' ),
						array( '%d' )
					);
				}
			}
		}

		// Repair legacy C# or Laravel slugs if present
		$wpdb->query( "UPDATE $skills_table SET slug = 'csharp' WHERE name = 'C#' AND slug != 'csharp'" );
		$wpdb->query( "UPDATE $skills_table SET slug = 'laravel' WHERE LOWER(name) = 'laravel' AND slug IN ('laravel-php','larave-php','larvel')" );

		// Fix tag slug c-sharp-oop -> csharp-oop
		$bad_tag  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tags_table WHERE slug = %s LIMIT 1", 'c-sharp-oop' ) );
		$good_tag = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $tags_table WHERE slug = %s LIMIT 1", 'csharp-oop' ) );
		if ( $bad_tag ) {
			if ( $good_tag && (int) $good_tag->id !== (int) $bad_tag->id ) {
				$wpdb->delete( $tags_table, array( 'id' => (int) $bad_tag->id ), array( '%d' ) );
			} else {
				$wpdb->update(
					$tags_table,
					array( 'slug' => 'csharp-oop' ),
					array( 'id' => (int) $bad_tag->id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
	}

	public static function init_default_masters() {
		global $wpdb;
		$now = current_time( 'mysql' );

		// A. Skill Types Table
		$types_table = $wpdb->prefix . 'psm_skill_types';
		$default_types = array(
			array( 'name' => 'Programming Language', 'slug' => 'programming-language', 'desc' => 'Core programming languages (C#, PHP, Java, JavaScript, Python).' ),
			array( 'name' => 'Framework',            'slug' => 'framework',            'desc' => 'Full-stack or application frameworks (Laravel, ASP.NET Core, Angular).' ),
			array( 'name' => 'Library',              'slug' => 'library',              'desc' => 'Reusable libraries and ORMs (ReactJS, Entity Framework Core).' ),
			array( 'name' => 'Database',             'slug' => 'database',             'desc' => 'Relational and NoSQL database engines (MSSQL, MySQL, PostgreSQL).' ),
			array( 'name' => 'Developer Tool',       'slug' => 'developer-tool',       'desc' => 'Version control, CLI, containers, and build tools (Git, Docker).' ),
			array( 'name' => 'Architecture & API',   'slug' => 'architecture-api',     'desc' => 'API styles, protocols, and system integration (REST API, GraphQL).' ),
			array( 'name' => 'Concept / Paradigm',   'slug' => 'concept-paradigm',     'desc' => 'Software design paradigms and core principles (OOP, C# OOP, SOLID, Design Patterns).' ),
			array( 'name' => 'Platform',             'slug' => 'platform',             'desc' => 'Cloud, OS, or runtime platforms.' ),
			array( 'name' => 'Subject',              'slug' => 'subject',              'desc' => 'Academic or core computer science subjects.' ),
			array( 'name' => 'Subject Area',         'slug' => 'subject-area',         'desc' => 'Broad domain knowledge areas.' ),
			array( 'name' => 'Professional Skill',   'slug' => 'professional-skill',   'desc' => 'Communication, interviews, and workplace skills.' ),
			array( 'name' => 'Other',                'slug' => 'other',                'desc' => 'General or uncategorized skill type.' ),
		);

		$order = 10;
		foreach ( $default_types as $dt ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $types_table WHERE slug = %s", $dt['slug'] ) );
			if ( ! $exists ) {
				$wpdb->insert(
					$types_table,
					array(
						'name'        => $dt['name'],
						'slug'        => $dt['slug'],
						'description' => $dt['desc'],
						'sort_order'  => $order,
						'status'      => 'active',
						'created_at'  => $now,
						'updated_at'  => $now,
					),
					array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
			} else {
				$wpdb->update(
					$types_table,
					array( 'description' => $dt['desc'], 'updated_at' => $now ),
					array( 'id' => (int) $exists ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
			$order += 10;
		}

		// B. Question Types Table (used by slug)
		$qt_table = $wpdb->prefix . 'psm_question_types';
		$default_qtypes = array(
			array( 'slug' => 'single_choice',   'name' => 'Single Choice (single_choice)',     'sort' => 5,  'desc' => 'Four options (keys A, B, C, D) with one correct answer; answer_mode: single.' ),
			array( 'slug' => 'multiple_choice', 'name' => 'Multiple Choice (multiple_choice)', 'sort' => 15, 'desc' => 'Four options (keys A, B, C, D) with two or three correct answers; answer_mode: multiple.' ),
			array( 'slug' => 'four_option',     'name' => 'Four options, one answer',          'sort' => 20, 'desc' => 'Standard 4-option MCQ with a single correct key (single / four_option).' ),
			array( 'slug' => 'multiple_select', 'name' => 'Multiple Correct MCQ (multiple_select)', 'sort' => 25, 'desc' => 'Multiple-choice question with 2 or more correct answers (multiple / multiple_select).' ),
			array( 'slug' => 'two_option',      'name' => 'Two options',                       'sort' => 30, 'desc' => 'Binary choice question with two custom options (keys A, B); answer_mode: single.' ),
			array( 'slug' => 'true_false',      'name' => 'True / False',                      'sort' => 40, 'desc' => 'Boolean statement with A = True and B = False; answer_mode: single.' ),
		);
		foreach ( $default_qtypes as $qt ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $qt_table WHERE slug = %s", $qt['slug'] ) );
			if ( ! $exists ) {
				$wpdb->insert(
					$qt_table,
					array(
						'name'        => $qt['name'],
						'slug'        => $qt['slug'],
						'description' => $qt['desc'],
						'sort_order'  => $qt['sort'],
						'status'      => 'active',
						'created_at'  => $now,
						'updated_at'  => $now,
					),
					array( '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
			} else {
				$wpdb->update(
					$qt_table,
					array( 'description' => $qt['desc'], 'updated_at' => $now ),
					array( 'id' => (int) $exists ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}

		// C. Difficulties Table (ID 1=Beginner, 2=Intermediate, 3=Advanced + slug column)
		$diff_table = $wpdb->prefix . 'psm_difficulties';
		$default_diffs = array(
			array( 'id' => 1, 'name' => 'Beginner',     'slug' => 'beginner',     'sort' => 10, 'desc' => 'Foundational syntax, definitions, and core concepts.' ),
			array( 'id' => 2, 'name' => 'Intermediate', 'slug' => 'intermediate', 'sort' => 20, 'desc' => 'Practical application, debugging, and multi-step scenarios.' ),
			array( 'id' => 3, 'name' => 'Advanced',     'slug' => 'advanced',     'sort' => 30, 'desc' => 'Architecture, performance tuning, edge cases, and internals.' ),
		);
		foreach ( $default_diffs as $df ) {
			$exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $diff_table WHERE id = %d OR slug = %s", $df['id'], $df['slug'] ) );
			if ( ! $exists ) {
				$wpdb->insert(
					$diff_table,
					array(
						'id'          => $df['id'],
						'name'        => $df['name'],
						'slug'        => $df['slug'],
						'description' => $df['desc'],
						'sort_order'  => $df['sort'],
						'status'      => 'active',
						'created_at'  => $now,
						'updated_at'  => $now,
					),
					array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
			} else {
				$wpdb->update(
					$diff_table,
					array( 'description' => $df['desc'], 'updated_at' => $now ),
					array( 'id' => (int) $exists ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
		}

		self::enforce_fixed_slugs_and_tags();

		// Sync skill_type_id
		$skills_table = $wpdb->prefix . 'psm_skills';
		foreach ( $wpdb->get_results( "SELECT id, name FROM $types_table" ) as $t ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE $skills_table SET skill_type_id = %d WHERE skill_type_id = 0 AND LOWER(skill_type) = LOWER(%s)",
					(int) $t->id,
					$t->name
				)
			);
		}
	}

	public static function get_skill_types() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}psm_skill_types ORDER BY sort_order ASC, name ASC" );
	}

	public static function get_question_types() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}psm_question_types ORDER BY sort_order ASC, id ASC" );
	}

	public static function get_difficulties() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}psm_difficulties ORDER BY id ASC" );
	}

	/**
	 * Idempotent Seeder: inserts missing domains, skill types, skills, and tags matched by unique slug,
	 * and populates descriptions for each seeded skill and tag.
	 */
	public static function run_seed() {
		global $wpdb;

		self::init_default_masters();

		$domains_table = $wpdb->prefix . 'psm_domains';
		$types_table   = $wpdb->prefix . 'psm_skill_types';
		$skills_table  = $wpdb->prefix . 'psm_skills';
		$tags_table    = $wpdb->prefix . 'psm_tags';
		$now           = current_time( 'mysql' );

		$type_map = array();
		foreach ( $wpdb->get_results( "SELECT id, name FROM $types_table" ) as $tr ) {
			$type_map[ strtolower( $tr->name ) ] = (int) $tr->id;
		}

		$taxonomy = self::get_seed_taxonomy();
		$summary  = array( 'domains' => 0, 'skills' => 0, 'tags' => 0 );

		$domain_order = 10;
		foreach ( $taxonomy as $domain_data ) {
			$domain_slug = self::make_slug( $domain_data['slug'], $domain_data['name'] );
			$domain_id   = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $domains_table WHERE slug = %s", $domain_slug ) );

			if ( ! $domain_id ) {
				$wpdb->insert(
					$domains_table,
					array(
						'name'        => $domain_data['name'],
						'slug'        => $domain_slug,
						'description' => $domain_data['description'],
						'icon'        => $domain_data['icon'],
						'sort_order'  => $domain_order,
						'status'      => 'active',
						'created_at'  => $now,
						'updated_at'  => $now,
					),
					array( '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
				);
				$domain_id = $wpdb->insert_id;
				$summary['domains']++;
			} else {
				$wpdb->update(
					$domains_table,
					array( 'description' => $domain_data['description'], 'updated_at' => $now ),
					array( 'id' => (int) $domain_id ),
					array( '%s', '%s' ),
					array( '%d' )
				);
			}
			$domain_order += 10;

			$skill_order = 10;
			foreach ( $domain_data['skills'] as $skill_data ) {
				$skill_slug = self::make_slug( $skill_data['slug'], $skill_data['name'] );
				$skill_desc = $skill_data['description'] ?? ( 'Core concepts, best practices, and interview topics for ' . $skill_data['name'] . '.' );
				$parent_id  = null;

				if ( ! empty( $skill_data['parent_slug'] ) ) {
					$parent_id = $wpdb->get_var(
						$wpdb->prepare( "SELECT id FROM $skills_table WHERE slug = %s", self::make_slug( $skill_data['parent_slug'] ) )
					);
				}

				$type_key      = strtolower( $skill_data['skill_type'] );
				$skill_type_id = isset( $type_map[ $type_key ] ) ? $type_map[ $type_key ] : 0;

				$skill_id = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $skills_table WHERE slug = %s", $skill_slug ) );

				if ( ! $skill_id ) {
					$wpdb->insert(
						$skills_table,
						array(
							'domain_id'       => (int) $domain_id,
							'parent_id'       => $parent_id ? (int) $parent_id : 0,
							'parent_skill_id' => $parent_id ? (int) $parent_id : null,
							'name'            => $skill_data['name'],
							'slug'            => $skill_slug,
							'skill_type'      => $skill_data['skill_type'],
							'skill_type_id'   => $skill_type_id,
							'description'     => $skill_desc,
							'icon'            => '',
							'sort_order'      => $skill_order,
							'status'          => 'active',
							'created_at'      => $now,
							'updated_at'      => $now,
						),
						array( '%d', '%d', $parent_id ? '%d' : '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
					);
					$skill_id = $wpdb->insert_id;
					$summary['skills']++;
				} else {
					$wpdb->update(
						$skills_table,
						array(
							'description'   => $skill_desc,
							'skill_type'    => $skill_data['skill_type'],
							'skill_type_id' => $skill_type_id,
							'updated_at'    => $now,
						),
						array( 'id' => (int) $skill_id ),
						array( '%s', '%s', '%d', '%s' ),
						array( '%d' )
					);
				}
				$skill_order += 10;

				$tag_order = 10;
				$tags_list = $skill_data['tags'] ?? array();
				foreach ( $tags_list as $tag_entry ) {
					if ( is_array( $tag_entry ) ) {
						$raw_tag_slug = $tag_entry['slug'] ?? '';
						$custom_name  = $tag_entry['name'] ?? '';
						$custom_desc  = $tag_entry['description'] ?? '';
					} else {
						$raw_tag_slug = (string) $tag_entry;
						$custom_name  = '';
						$custom_desc  = '';
					}

					$tag_slug = sanitize_title( $raw_tag_slug );
					if ( $tag_slug === 'c-sharp-oop' ) {
						$tag_slug = 'csharp-oop';
					}
					if ( $tag_slug === '' ) {
						continue;
					}

					$tag_name   = $custom_name !== '' ? $custom_name : self::humanize_tag_slug( $tag_slug, $skill_slug );
					$tag_desc   = $custom_desc !== '' ? $custom_desc : ( 'Topic tag "' . $tag_name . '" (' . $tag_slug . ') under ' . $skill_data['name'] . '.' );
					$tag_exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $tags_table WHERE slug = %s", $tag_slug ) );

					if ( ! $tag_exists ) {
						$wpdb->insert(
							$tags_table,
							array(
								'skill_id'    => (int) $skill_id,
								'name'        => $tag_name,
								'slug'        => $tag_slug,
								'description' => $tag_desc,
								'sort_order'  => $tag_order,
								'status'      => 'active',
								'created_at'  => $now,
								'updated_at'  => $now,
							),
							array( '%d', '%s', '%s', '%s', '%d', '%s', '%s', '%s' )
						);
						$summary['tags']++;
					} else {
						$wpdb->update(
							$tags_table,
							array(
								'skill_id'    => (int) $skill_id,
								'description' => $tag_desc,
								'updated_at'  => $now,
							),
							array( 'id' => (int) $tag_exists ),
							array( '%d', '%s', '%s' ),
							array( '%d' )
						);
					}
					$tag_order += 10;
				}
			}
		}

		return $summary;
	}

	private static function humanize_tag_slug( $tag_slug, $skill_slug ) {
		$prefixes = array(
			$skill_slug . '-',
			'csharp-', 'javascript-', 'php-', 'python-', 'java-', 'reactjs-', 'angular-', 'laravel-',
			'aspnetcore-', 'efcore-', 'git-', 'rest-api-', 'oop-', 'html-', 'css-',
			'mssql-', 'mysql-', 'postgresql-',
			'dsa-', 'algorithms-', 'os-', 'networking-', 'software-engineering-', 'commerce-accounting-',
			'commerce-cost-accounting-', 'commerce-economics-', 'commerce-business-',
			'commerce-corporate-accounting-', 'commerce-taxation-', 'commerce-auditing-',
			'commerce-business-law-', 'management-', 'marketing-', 'hrm-', 'finance-',
			'mathematics-', 'algebra-', 'geometry-', 'statistics-', 'calculus-',
			'physics-', 'chemistry-', 'biology-', 'english-grammar-', 'english-vocabulary-',
			'english-speaking-', 'english-writing-', 'gk-', 'professional-',
		);

		$clean = $tag_slug;
		foreach ( $prefixes as $prefix ) {
			if ( strpos( $clean, $prefix ) === 0 && strlen( $clean ) > strlen( $prefix ) ) {
				$clean = substr( $clean, strlen( $prefix ) );
				break;
			}
		}

		return ucwords( str_replace( '-', ' ', $clean ) );
	}

	public static function get_seed_taxonomy() {
		return array(
			array(
				'name' => 'Programming', 'slug' => 'programming', 'icon' => 'dashicons-editor-code', 'description' => 'Programming languages, frameworks, libraries, and developer tools.',
				'skills' => array(
					array(
						'name' => 'C#', 'slug' => 'csharp', 'skill_type' => 'Programming Language', 'parent_slug' => '',
						'description' => 'Statically typed object-oriented .NET language covering types, generics, LINQ, async/await, delegates, events, and runtime memory behavior.',
						'tags' => array(
							'csharp-fundamentals','csharp-variables','csharp-data-types','csharp-string','csharp-array','csharp-list','csharp-dictionary','csharp-collections','csharp-oop','csharp-classes','csharp-objects','csharp-inheritance','csharp-polymorphism','csharp-encapsulation','csharp-abstraction','csharp-interfaces','csharp-abstract-classes','csharp-solid-principles','csharp-single-responsibility','csharp-open-closed','csharp-liskov-substitution','csharp-interface-segregation','csharp-dependency-inversion','csharp-generics','csharp-delegates','csharp-events','csharp-lambda-expressions','csharp-linq','csharp-exception-handling','csharp-async-await','csharp-task-parallelism','csharp-memory-management','csharp-garbage-collection','csharp-reflection','csharp-extension-methods','csharp-pattern-matching'
						),
					),
					array(
						'name' => 'C# OOP', 'slug' => 'csharp-oop', 'skill_type' => 'Concept / Paradigm', 'parent_slug' => 'csharp',
						'description' => 'Object-oriented design in C# including classes, structs, records, interfaces, inheritance, polymorphism, encapsulation, and SOLID principles.',
						'tags' => array(
							array( 'slug' => 'csharp-oop', 'name' => 'C# OOP', 'description' => 'Core object-oriented concepts and design patterns in C#.' ),
							'csharp-oop-classes-objects', 'csharp-oop-inheritance', 'csharp-oop-polymorphism', 'csharp-oop-encapsulation', 'csharp-oop-abstraction', 'csharp-oop-interfaces-abstract', 'csharp-oop-solid'
						),
					),
					array(
						'name' => 'Object-Oriented PHP', 'slug' => 'object-oriented-php', 'skill_type' => 'Concept / Paradigm', 'parent_slug' => 'php',
						'description' => 'Object-oriented programming in PHP focusing on classes, interfaces, abstract classes, traits, namespaces, visibility modifiers, and dependency injection.',
						'tags' => array(
							array( 'slug' => 'object-oriented-php', 'name' => 'Object-Oriented PHP', 'description' => 'Object-oriented programming fundamentals and patterns in PHP.' ),
							'php-oop-classes', 'php-oop-interfaces', 'php-oop-traits', 'php-oop-visibility', 'php-oop-inheritance-polymorphism'
						),
					),
					array(
						'name' => 'Java', 'slug' => 'java', 'skill_type' => 'Programming Language', 'parent_slug' => '',
						'description' => 'Strongly typed object-oriented language running on the JVM, covering OOP, collections, generics, streams, concurrency, exception handling, and memory model.',
						'tags' => array(
							array( 'slug' => 'java', 'name' => 'Java', 'description' => 'Core Java language syntax, JVM runtime, and standard APIs.' ),
							'java-fundamentals', 'java-oop', 'java-collections', 'java-streams', 'java-multithreading', 'java-exceptions', 'java-jvm-memory'
						),
					),
					array(
						'name' => 'JavaScript', 'slug' => 'javascript', 'skill_type' => 'Programming Language', 'parent_slug' => '',
						'description' => 'Dynamic scripting and application language for web and server environments, covering ES6+, closures, promises, async/await, prototypes, and the event loop.',
						'tags' => array(
							'javascript-fundamentals','javascript-variables','javascript-data-types','javascript-string','javascript-array','javascript-objects','javascript-functions','javascript-arrow-functions','javascript-scope','javascript-hoisting','javascript-closures','javascript-this-keyword','javascript-prototypes','javascript-es6','javascript-destructuring','javascript-spread-rest','javascript-promises','javascript-async-await','javascript-event-loop','javascript-dom','javascript-events','javascript-error-handling','javascript-modules','javascript-map-set','javascript-weakmap-weakset'
						),
					),
					array(
						'name' => 'PHP', 'slug' => 'php', 'skill_type' => 'Programming Language', 'parent_slug' => '',
						'description' => 'Server-side scripting language for web applications covering types, arrays, functions, OOP, error/exception handling, sessions, and Composer ecosystems.',
						'tags' => array(
							'php-fundamentals','php-variables','php-data-types','php-string','php-array','php-functions','php-oop','php-classes','php-objects','php-inheritance','php-interfaces','php-traits','php-namespaces','php-exception-handling','php-type-declarations','php-generators','php-sessions','php-cookies','php-file-handling','php-json','php-mysql','php-composer'
						),
					),
					array(
						'name' => 'Python', 'slug' => 'python', 'skill_type' => 'Programming Language', 'parent_slug' => '',
						'description' => 'High-level general-purpose programming language covering data structures, functions, decorators, generators, OOP, modules, file I/O, and asynchronous programming.',
						'tags' => array(
							'python-fundamentals','python-variables','python-data-types','python-string','python-list','python-tuple','python-set','python-dictionary','python-functions','python-lambda','python-oop','python-inheritance','python-decorators','python-generators','python-iterators','python-exception-handling','python-modules','python-file-handling','python-list-comprehensions','python-async-await'
						),
					),
					array(
						'name' => 'ReactJS', 'slug' => 'reactjs', 'skill_type' => 'Library', 'parent_slug' => 'javascript',
						'description' => 'Component-based UI library covering JSX, props, state, hooks, context, routing, performance memoization, and client state architecture.',
						'tags' => array(
							'reactjs-fundamentals','reactjs-components','reactjs-functional-components','reactjs-class-components','reactjs-jsx','reactjs-props','reactjs-state','reactjs-hooks','reactjs-usestate','reactjs-useeffect','reactjs-usecontext','reactjs-usereducer','reactjs-usememo','reactjs-usecallback','reactjs-useref','reactjs-custom-hooks','reactjs-component-lifecycle','reactjs-conditional-rendering','reactjs-list-rendering','reactjs-forms','reactjs-event-handling','reactjs-react-router','reactjs-context-api','reactjs-performance','reactjs-redux','reactjs-error-boundaries'
						),
					),
					array(
						'name' => 'Angular', 'slug' => 'angular', 'skill_type' => 'Framework', 'parent_slug' => 'javascript',
						'description' => 'TypeScript-based web application framework covering components, templates, directives, dependency injection, routing, reactive forms, RxJS, and signals.',
						'tags' => array(
							'angular-fundamentals','angular-components','angular-templates','angular-directives','angular-services','angular-dependency-injection','angular-routing','angular-forms','angular-reactive-forms','angular-pipes','angular-lifecycle-hooks','angular-rxjs','angular-observables','angular-http-client','angular-signals','angular-standalone-components','angular-guards','angular-interceptors','angular-state-management','angular-performance'
						),
					),
					array(
						'name' => 'Laravel', 'slug' => 'laravel', 'skill_type' => 'Framework', 'parent_slug' => 'php',
						'description' => 'PHP web framework covering routing, controllers, Eloquent ORM, migrations, middleware, service container, queues, authentication, and testing.',
						'tags' => array(
							'laravel-fundamentals','laravel-routing','laravel-controllers','laravel-models','laravel-eloquent-orm','laravel-migrations','laravel-relationships','laravel-validation','laravel-middleware','laravel-dependency-injection','laravel-service-container','laravel-service-providers','laravel-blade','laravel-authentication','laravel-authorization','laravel-queues','laravel-events','laravel-jobs','laravel-api','laravel-cache','laravel-testing'
						),
					),
					array(
						'name' => 'ASP.NET Core', 'slug' => 'aspnetcore', 'skill_type' => 'Framework', 'parent_slug' => 'csharp',
						'description' => 'Cross-platform .NET web framework covering middleware pipelines, dependency injection, routing, controllers/minimal APIs, auth/JWT, filters, and configuration.',
						'tags' => array(
							'aspnetcore-fundamentals','aspnetcore-middleware','aspnetcore-routing','aspnetcore-controllers','aspnetcore-webapi','aspnetcore-dependency-injection','aspnetcore-service-lifetime','aspnetcore-configuration','aspnetcore-logging','aspnetcore-authentication','aspnetcore-authorization','aspnetcore-jwt','aspnetcore-filters','aspnetcore-model-binding','aspnetcore-validation','aspnetcore-exception-handling','aspnetcore-minimal-api','aspnetcore-background-services','aspnetcore-cors','aspnetcore-performance'
						),
					),
					array(
						'name' => 'Entity Framework Core', 'slug' => 'efcore', 'skill_type' => 'Library', 'parent_slug' => 'csharp',
						'description' => 'Object-relational mapper (ORM) for .NET covering DbContext, LINQ queries, change tracking, AsNoTracking, migrations, loading strategies, and transactions.',
						'tags' => array(
							'efcore-fundamentals','efcore-dbcontext','efcore-dbset','efcore-code-first','efcore-database-first','efcore-migrations','efcore-relationships','efcore-navigation-properties','efcore-linq','efcore-tracking','efcore-nontracking','efcore-eager-loading','efcore-lazy-loading','efcore-explicit-loading','efcore-transactions','efcore-raw-sql','efcore-performance','efcore-concurrency'
						),
					),
					array(
						'name' => 'Git', 'slug' => 'git', 'skill_type' => 'Developer Tool', 'parent_slug' => '',
						'description' => 'Distributed version control system covering commits, branches, merge, rebase, cherry-pick, stash, reset/revert, reflog, and conflict resolution.',
						'tags' => array(
							'git-fundamentals','git-branching','git-merging','git-rebasing','git-reset-revert','git-stash','git-cherry-pick','git-reflog','git-conflict-resolution','git-workflows'
						),
					),
					array(
						'name' => 'REST API', 'slug' => 'rest-api', 'skill_type' => 'Architecture & API', 'parent_slug' => '',
						'description' => 'Architectural style for HTTP-based web APIs covering resource design, HTTP methods, status codes, authentication, versioning, pagination, and idempotency.',
						'tags' => array(
							'rest-api-fundamentals','rest-api-http-methods','rest-api-status-codes','rest-api-authentication','rest-api-versioning','rest-api-pagination','rest-api-rate-limiting','rest-api-idempotency'
						),
					),
					array(
						'name' => 'Object-Oriented Programming (OOP)', 'slug' => 'oop', 'skill_type' => 'Concept / Paradigm', 'parent_slug' => '',
						'description' => 'Core object-oriented design principles including encapsulation, abstraction, inheritance, polymorphism, composition over inheritance, and SOLID.',
						'tags' => array(
							array( 'slug' => 'oop', 'name' => 'Object-Oriented Programming (OOP)', 'description' => 'Language-agnostic object-oriented programming concepts and design.' ),
							'oop-fundamentals','oop-encapsulation','oop-inheritance','oop-polymorphism','oop-abstraction','oop-solid-principles','oop-composition-vs-inheritance','oop-design-patterns'
						),
					),
					array(
						'name' => 'HTML', 'slug' => 'html', 'skill_type' => 'Concept / Paradigm', 'parent_slug' => '',
						'description' => 'HyperText Markup Language for structuring web documents, semantic elements, forms, accessibility (ARIA), tables, and media.',
						'tags' => array(
							array( 'slug' => 'html', 'name' => 'HTML', 'description' => 'Semantic HTML markup, accessibility, and document structure.' ),
							'html-fundamentals', 'html-semantic-elements', 'html-forms-inputs', 'html-accessibility-aria', 'html-tables-media'
						),
					),
					array(
						'name' => 'CSS', 'slug' => 'css', 'skill_type' => 'Concept / Paradigm', 'parent_slug' => '',
						'description' => 'Cascading Style Sheets for layout and visual presentation including specificity, cascade, Flexbox, Grid, positioning, variables, and responsive design.',
						'tags' => array(
							array( 'slug' => 'css', 'name' => 'CSS', 'description' => 'CSS selectors, cascade, specificity, Flexbox, Grid, and responsive styling.' ),
							'css-fundamentals', 'css-specificity-cascade', 'css-flexbox', 'css-grid', 'css-positioning-box-model', 'css-responsive-animations'
						),
					),
				),
			),
			array(
				'name' => 'Database & Data Management', 'slug' => 'database-data-management', 'icon' => 'dashicons-database', 'description' => 'Relational databases, SQL querying, indexing, and optimization.',
				'skills' => array(
					array(
						'name' => 'Microsoft SQL Server', 'slug' => 'mssql', 'skill_type' => 'Database', 'parent_slug' => '',
						'description' => 'Relational database management system covering T-SQL, indexes, joins, CTEs, transactions, stored procedures, window functions, and execution plans.',
						'tags' => array(
							'mssql-fundamentals','mssql-database','mssql-tables','mssql-data-types','mssql-primary-key','mssql-foreign-key','mssql-unique-key','mssql-constraints','mssql-clustered-index','mssql-nonclustered-index','mssql-index-performance','mssql-joins','mssql-inner-join','mssql-left-join','mssql-right-join','mssql-full-outer-join','mssql-subqueries','mssql-cte','mssql-views','mssql-stored-procedures','mssql-functions','mssql-triggers','mssql-transactions','mssql-normalization','mssql-query-optimization','mssql-execution-plans','mssql-window-functions','mssql-temp-tables','mssql-variables','mssql-error-handling'
						),
					),
					array(
						'name' => 'MySQL', 'slug' => 'mysql', 'skill_type' => 'Database', 'parent_slug' => '',
						'description' => 'Open-source relational database covering InnoDB transactions, indexes, joins, subqueries, views, routines, normalization, and query tuning.',
						'tags' => array(
							'mysql-fundamentals','mysql-data-types','mysql-tables','mysql-primary-key','mysql-foreign-key','mysql-indexes','mysql-joins','mysql-subqueries','mysql-views','mysql-stored-procedures','mysql-functions','mysql-triggers','mysql-transactions','mysql-normalization','mysql-query-optimization'
						),
					),
					array(
						'name' => 'PostgreSQL', 'slug' => 'postgresql', 'skill_type' => 'Database', 'parent_slug' => '',
						'description' => 'Advanced relational database system covering MVCC transactions, indexes, joins, CTEs, functions, triggers, JSONB querying, and performance optimization.',
						'tags' => array(
							'postgresql-fundamentals','postgresql-data-types','postgresql-tables','postgresql-indexes','postgresql-joins','postgresql-views','postgresql-functions','postgresql-triggers','postgresql-transactions','postgresql-json','postgresql-query-optimization'
						),
					),
				),
			),
			array(
				'name' => 'Computer Science', 'slug' => 'computer-science', 'icon' => 'dashicons-networking', 'description' => 'Core computer science foundations and software engineering.',
				'skills' => array(
					array(
						'name' => 'Data Structures', 'slug' => 'data-structures', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Organization and storage of data including arrays, linked lists, stacks, queues, hash tables, trees, heaps, and graphs.',
						'tags' => array('dsa-arrays','dsa-linked-lists','dsa-stacks','dsa-queues','dsa-hash-tables','dsa-trees','dsa-binary-trees','dsa-binary-search-trees','dsa-heaps','dsa-graphs','dsa-hashing'),
					),
					array(
						'name' => 'Algorithms', 'slug' => 'algorithms', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Algorithm design and time/space complexity analysis covering searching, sorting, recursion, divide-and-conquer, dynamic programming, and greedy methods.',
						'tags' => array('algorithms-searching','algorithms-linear-search','algorithms-binary-search','algorithms-sorting','algorithms-bubble-sort','algorithms-merge-sort','algorithms-quick-sort','algorithms-recursion','algorithms-dynamic-programming','algorithms-greedy','algorithms-backtracking','algorithms-complexity','algorithms-big-o'),
					),
					array(
						'name' => 'Operating Systems', 'slug' => 'operating-systems', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Operating system internals covering processes, threads, CPU scheduling, synchronization, deadlocks, virtual memory, and file systems.',
						'tags' => array('os-processes','os-threads','os-memory-management','os-virtual-memory','os-deadlocks','os-scheduling','os-file-systems','os-synchronization'),
					),
					array(
						'name' => 'Computer Networks', 'slug' => 'computer-networks', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Networking protocols and architecture covering the OSI model, TCP/IP, DNS, HTTP/HTTPS, IP addressing, routing, and network security.',
						'tags' => array('networking-fundamentals','networking-osi-model','networking-tcp-ip','networking-http','networking-https','networking-dns','networking-ip-addressing','networking-routing','networking-firewalls'),
					),
					array(
						'name' => 'Software Engineering', 'slug' => 'software-engineering', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Systematic software development practices covering SDLC, design patterns, SOLID, clean architecture, testing, and agile delivery.',
						'tags' => array('software-engineering-fundamentals','software-development-lifecycle','software-design-patterns','software-solid-principles','software-clean-code','software-clean-architecture','software-unit-testing','software-integration-testing','software-agile','software-version-control'),
					),
				),
			),
			array(
				'name' => 'Commerce', 'slug' => 'commerce', 'icon' => 'dashicons-chart-line', 'description' => 'Accounting, taxation, economics, auditing, and business law.',
				'skills' => array(
					array(
						'name' => 'Financial Accounting', 'slug' => 'financial-accounting', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Recording and reporting financial transactions including accounting equation, journals, ledgers, trial balance, depreciation, and financial statements.',
						'tags' => array('commerce-accounting-fundamentals','commerce-accounting-accounting-equation','commerce-accounting-debit-credit','commerce-accounting-journal-entry','commerce-accounting-ledger','commerce-accounting-trial-balance','commerce-accounting-cash-book','commerce-accounting-bank-reconciliation','commerce-accounting-depreciation','commerce-accounting-inventory','commerce-accounting-profit-loss','commerce-accounting-balance-sheet','commerce-accounting-final-accounts','commerce-accounting-adjustments','commerce-accounting-error-correction'),
					),
					array(
						'name' => 'Cost Accounting', 'slug' => 'cost-accounting', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Cost classification, cost sheets, material and labour costing, overhead allocation, marginal costing, standard costing, and break-even analysis.',
						'tags' => array('commerce-cost-accounting-fundamentals','commerce-cost-classification','commerce-cost-sheet','commerce-material-cost','commerce-labour-cost','commerce-overheads','commerce-marginal-costing','commerce-standard-costing','commerce-budgetary-control','commerce-break-even-analysis'),
					),
					array(
						'name' => 'Economics', 'slug' => 'economics', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Microeconomics and macroeconomics covering demand, supply, elasticity, market structures, national income, inflation, and fiscal/monetary policy.',
						'tags' => array('commerce-economics-fundamentals','commerce-microeconomics','commerce-macroeconomics','commerce-demand','commerce-supply','commerce-elasticity','commerce-market-structures','commerce-national-income','commerce-inflation','commerce-monetary-policy','commerce-fiscal-policy','commerce-economic-growth'),
					),
					array(
						'name' => 'Business Studies', 'slug' => 'business-studies', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Principles of business organization, management functions, business environment, marketing, HRM, and entrepreneurship.',
						'tags' => array('commerce-business-fundamentals','commerce-management-principles','commerce-planning','commerce-organizing','commerce-staffing','commerce-directing','commerce-controlling','commerce-business-environment','commerce-marketing','commerce-human-resource-management','commerce-entrepreneurship'),
					),
					array(
						'name' => 'Corporate Accounting', 'slug' => 'corporate-accounting', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Accounting for companies including share capital, issue and forfeiture of shares, debentures, company final accounts, and amalgamations.',
						'tags' => array('commerce-corporate-accounting-fundamentals','commerce-share-capital','commerce-issue-of-shares','commerce-forfeiture-of-shares','commerce-debentures','commerce-company-final-accounts','commerce-mergers','commerce-amalgamation'),
					),
					array(
						'name' => 'Taxation', 'slug' => 'taxation', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Direct and indirect tax concepts including income tax heads, deductions, taxable income computation, GST registration, input tax credit, and returns.',
						'tags' => array('commerce-taxation-fundamentals','commerce-income-tax','commerce-gst','commerce-gst-registration','commerce-gst-input-tax-credit','commerce-gst-returns','commerce-tax-deductions','commerce-taxable-income','commerce-tax-computation'),
					),
					array(
						'name' => 'Auditing', 'slug' => 'auditing', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Independent examination of financial records covering audit types, internal controls, audit evidence, vouching, verification, and audit reporting.',
						'tags' => array('commerce-auditing-fundamentals','commerce-audit-types','commerce-internal-audit','commerce-audit-evidence','commerce-audit-report','commerce-internal-controls','commerce-vouching','commerce-verification'),
					),
					array(
						'name' => 'Business Law', 'slug' => 'business-law', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Legal rules governing commercial transactions including contract law, sale of goods, partnership, corporate law, and consumer protection.',
						'tags' => array('commerce-business-law-fundamentals','commerce-contract-law','commerce-indian-contract-act','commerce-sale-of-goods','commerce-company-law','commerce-consumer-protection','commerce-partnership-law'),
					),
				),
			),
			array(
				'name' => 'Business & Management', 'slug' => 'business-management', 'icon' => 'dashicons-businessman', 'description' => 'Management, marketing, HR, and corporate finance.',
				'skills' => array(
					array(
						'name' => 'Business Management', 'slug' => 'business-management', 'skill_type' => 'Subject Area', 'parent_slug' => '',
						'description' => 'Planning, organizing, leadership, motivation, strategic planning, decision-making, and organizational performance management.',
						'tags' => array('management-fundamentals','management-planning','management-leadership','management-motivation','management-decision-making','management-team-building','management-strategic-planning','management-performance'),
					),
					array(
						'name' => 'Marketing', 'slug' => 'marketing', 'skill_type' => 'Subject Area', 'parent_slug' => '',
						'description' => 'Market research, segmentation, positioning, branding, digital marketing, SEO, advertising, and customer acquisition.',
						'tags' => array('marketing-fundamentals','marketing-research','marketing-segmentation','marketing-branding','marketing-digital','marketing-seo','marketing-social-media','marketing-advertising','marketing-sales'),
					),
					array(
						'name' => 'Human Resource Management', 'slug' => 'human-resource-management', 'skill_type' => 'Subject Area', 'parent_slug' => '',
						'description' => 'Talent acquisition, recruitment, selection, training and development, performance appraisal, compensation, and employee relations.',
						'tags' => array('hrm-fundamentals','hrm-recruitment','hrm-selection','hrm-training','hrm-performance-appraisal','hrm-compensation','hrm-employee-relations'),
					),
					array(
						'name' => 'Finance', 'slug' => 'finance', 'skill_type' => 'Subject Area', 'parent_slug' => '',
						'description' => 'Financial planning, budgeting, capital budgeting, working capital management, risk management, investment analysis, and financial statements.',
						'tags' => array('finance-fundamentals','finance-financial-planning','finance-budgeting','finance-investment','finance-risk-management','finance-capital-budgeting','finance-working-capital','finance-financial-statements'),
					),
				),
			),
			array(
				'name' => 'Mathematics', 'slug' => 'mathematics', 'icon' => 'dashicons-calculator', 'description' => 'Quantitative aptitude, algebra, geometry, statistics, and calculus.',
				'skills' => array(
					array(
						'name' => 'Arithmetic', 'slug' => 'arithmetic', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Number system, fractions, percentages, ratios, proportions, averages, profit and loss, interest, time and work, and speed/distance.',
						'tags' => array('mathematics-number-system','mathematics-fractions','mathematics-decimals','mathematics-percentages','mathematics-ratio','mathematics-proportion','mathematics-average','mathematics-profit-loss','mathematics-simple-interest','mathematics-compound-interest','mathematics-time-work','mathematics-speed-distance'),
					),
					array(
						'name' => 'Algebra', 'slug' => 'algebra', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Algebraic expressions, linear and quadratic equations, polynomials, exponents, logarithms, and sequences.',
						'tags' => array('algebra-expressions','algebra-equations','algebra-linear-equations','algebra-quadratic-equations','algebra-polynomials','algebra-exponents','algebra-logarithms','algebra-sequences'),
					),
					array(
						'name' => 'Geometry', 'slug' => 'geometry', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Lines, angles, triangles, circles, polygons, mensuration (area and volume), and coordinate geometry.',
						'tags' => array('geometry-lines','geometry-angles','geometry-triangles','geometry-circles','geometry-polygons','geometry-area','geometry-volume','geometry-coordinate-geometry'),
					),
					array(
						'name' => 'Statistics', 'slug' => 'statistics', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Descriptive and inferential statistics including mean, median, mode, variance, standard deviation, probability, and distributions.',
						'tags' => array('statistics-data','statistics-mean','statistics-median','statistics-mode','statistics-variance','statistics-standard-deviation','statistics-probability','statistics-distributions'),
					),
					array(
						'name' => 'Calculus', 'slug' => 'calculus', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Limits, continuity, derivatives, integrals, applications of calculus, and differential equations.',
						'tags' => array('calculus-limits','calculus-continuity','calculus-differentiation','calculus-integration','calculus-applications','calculus-differential-equations'),
					),
				),
			),
			array(
				'name' => 'Science', 'slug' => 'science', 'icon' => 'dashicons-lightbulb', 'description' => 'Physics, chemistry, and biology.',
				'skills' => array(
					array(
						'name' => 'Physics', 'slug' => 'physics', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Mechanics, force and motion, laws of motion, gravitation, work and energy, thermodynamics, waves, optics, electricity, magnetism, and modern physics.',
						'tags' => array('physics-units-measurements','physics-motion','physics-force','physics-laws-of-motion','physics-gravity','physics-work-energy','physics-power','physics-heat','physics-waves','physics-light','physics-electricity','physics-magnetism','physics-modern-physics'),
					),
					array(
						'name' => 'Chemistry', 'slug' => 'chemistry', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Atomic structure, periodic trends, chemical bonding, chemical reactions, acids/bases, organic and inorganic chemistry, thermodynamics, and solutions.',
						'tags' => array('chemistry-atomic-structure','chemistry-periodic-table','chemistry-chemical-bonding','chemistry-chemical-reactions','chemistry-acids-bases','chemistry-metals-nonmetals','chemistry-organic-chemistry','chemistry-inorganic-chemistry','chemistry-thermodynamics','chemistry-electrochemistry','chemistry-solutions'),
					),
					array(
						'name' => 'Biology', 'slug' => 'biology', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Cell biology, genetics, human physiology, plant physiology, ecology, evolution, and biological systems.',
						'tags' => array('biology-cell-structure','biology-cell-division','biology-genetics','biology-human-body','biology-digestive-system','biology-respiratory-system','biology-circulatory-system','biology-nervous-system','biology-reproduction','biology-ecology','biology-evolution','biology-plant-physiology'),
					),
				),
			),
			array(
				'name' => 'English & Communication', 'slug' => 'english-communication', 'icon' => 'dashicons-translation', 'description' => 'Grammar, vocabulary, spoken English, and writing.',
				'skills' => array(
					array(
						'name' => 'English Grammar', 'slug' => 'english-grammar', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Parts of speech, tenses, subject-verb agreement, articles, prepositions, clauses, voice, narration, and conditionals.',
						'tags' => array('english-grammar-parts-of-speech','english-grammar-nouns','english-grammar-pronouns','english-grammar-verbs','english-grammar-adjectives','english-grammar-adverbs','english-grammar-tenses','english-grammar-prepositions','english-grammar-conjunctions','english-grammar-articles','english-grammar-subject-verb-agreement','english-grammar-active-passive','english-grammar-direct-indirect-speech','english-grammar-sentence-structure','english-grammar-conditionals'),
					),
					array(
						'name' => 'English Vocabulary', 'slug' => 'english-vocabulary', 'skill_type' => 'Subject', 'parent_slug' => '',
						'description' => 'Synonyms, antonyms, idioms, phrasal verbs, one-word substitutions, and commonly confused words.',
						'tags' => array('english-vocabulary-synonyms','english-vocabulary-antonyms','english-vocabulary-idioms','english-vocabulary-phrases','english-vocabulary-one-word-substitution','english-vocabulary-confusing-words','english-vocabulary-phrasal-verbs'),
					),
					array(
						'name' => 'Spoken English', 'slug' => 'spoken-english', 'skill_type' => 'Professional Skill', 'parent_slug' => '',
						'description' => 'Everyday spoken communication, introductions, interview responses, pronunciation, and conversational fluency.',
						'tags' => array('english-speaking-daily-conversation','english-speaking-introductions','english-speaking-interviews','english-speaking-pronunciation','english-speaking-fluency','english-speaking-communication'),
					),
					array(
						'name' => 'English Writing', 'slug' => 'english-writing', 'skill_type' => 'Professional Skill', 'parent_slug' => '',
						'description' => 'Sentence structure, paragraph development, essays, professional emails, punctuation, and reading comprehension.',
						'tags' => array('english-writing-sentence-construction','english-writing-paragraphs','english-writing-essays','english-writing-emails','english-writing-punctuation','english-writing-comprehension'),
					),
				),
			),
			array(
				'name' => 'General Knowledge', 'slug' => 'general-knowledge', 'icon' => 'dashicons-admin-site', 'description' => 'History, geography, polity, current affairs, and world facts.',
				'skills' => array(
					array(
						'name' => 'General Knowledge', 'slug' => 'general-knowledge', 'skill_type' => 'Subject Area', 'parent_slug' => '',
						'description' => 'History, geography, constitution and polity, international organizations, science facts, sports, awards, and current affairs.',
						'tags' => array('gk-indian-history','gk-world-history','gk-indian-geography','gk-world-geography','gk-indian-polity','gk-constitution','gk-current-affairs','gk-science','gk-sports','gk-awards','gk-important-days','gk-international-organizations'),
					),
				),
			),
			array(
				'name' => 'Professional Development', 'slug' => 'professional-development', 'icon' => 'dashicons-awards', 'description' => 'Interview readiness and workplace effectiveness.',
				'skills' => array(
					array(
						'name' => 'Interview Preparation', 'slug' => 'interview-preparation', 'skill_type' => 'Professional Skill', 'parent_slug' => '',
						'description' => 'Technical, HR, behavioral, and problem-solving interview preparation along with resume and communication readiness.',
						'tags' => array('professional-interview-fundamentals','professional-technical-interviews','professional-hr-interviews','professional-behavioral-interviews','professional-problem-solving','professional-communication','professional-resume'),
					),
					array(
						'name' => 'Workplace Skills', 'slug' => 'workplace-skills', 'skill_type' => 'Professional Skill', 'parent_slug' => '',
						'description' => 'Time management, teamwork, leadership, critical thinking, workplace communication, and conflict resolution.',
						'tags' => array('professional-time-management','professional-teamwork','professional-leadership','professional-critical-thinking','professional-workplace-communication','professional-conflict-resolution'),
					),
				),
			),
		);
	}
}
