=== PSM AI Question Generator & Importer ===
Contributors: psm
Tags: questions, ai, quiz, importer, json
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.8
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate, preview, import, and manage AI questions with domain, skill, tag, batch, provider, and backup tools.

== Description ==

PSM AI Question Generator & Importer provides a seven-tab workflow:

1. Prompt, Preview & Import: build a strict JSON prompt, copy or download it, upload saved JSON in a popup, validate it, and preview every row before import.
2. Masters: manage Questions first by default, followed by Question Types, Tags, Skills, Skill Types, Domains, and Difficulties, with AJAX filters and pagination.
3. Batches: review imported batches with AJAX pagination and download question labels or CSV exports.
4. AI Providers: configure independent provider cards and monitor usage without displaying saved API keys.
5. Settings: configure the global rows-per-page value for all master tables and Batches, save cache duration, and clear cached query results through AJAX with top-right notices.
6. Backup & Restore: export and restore the configured `psm_*` tables as validated JSON without including WordPress options or provider credentials.
7. Seed Defaults: add missing default domains, skills, and tags, including C, C++, C#, and Microsoft Azure, with an explained idempotent seed action.

Question management includes skill-specific active tags, inline creation of new tags, automatic `<skill-slug>-<tag-name>` normalization, exact filtered previous/next editing, text/skill/tag/status filters, and pagination. Domain-aware selectors limit skills to the selected domain in question, tag, and skill editors, prompt generation, and JSON import. Pagination controls are fixed squares with centered labels and icons; range information stays on the left and controls on the right. The shared page-size setting is configured once under Settings and is validated between 5 and 100. The centered plus-only Add button sits at the right of the master entity tabs. Master tables fit the available width without page-level horizontal scrolling.

Difficulty appears in the Questions list as a compact, rounded, multicolor number badge: gray 1 (Beginner), orange 2 (Intermediate), and red 3 (Advanced), with the difficulty name available on hover. Delete confirmations show the record ID once and use a red Delete button and a consistently styled dark-gray Cancel button. Deletion checks record existence and blocks dependent records such as skills used by questions, tags, child skills, or batches. Batch and question relation cleanup uses transactions. Clickable links and enabled buttons display a pointer cursor.

Temporarily missing skill and tag masters are shown with their referenced IDs in the Questions list and filters. These placeholders are non-database options: they do not create master rows or change question data. Questions with unresolved tag links keep those links when edited, and the synthetic placeholders are never submitted as new tag slugs.

The seed defaults are non-destructive. They add missing records by slug, preserve existing names, ownership, statuses, and curated descriptions, fill blank master/tag descriptions, and replace only deterministic generated tag descriptions with topic-based text. Existing questions and question-tag relationships are never changed. Parent-skill relationships use the existing shared schema, which has no separate relationship-description field. C++ variants, including `C++`, `C ++`, `C Plus Plus`, `CPP`, and `cplush`, normalize to `cplusplus`; C and C# use `c` and `csharp`.

Microsoft Azure is seeded under Programming with the `microsoft-azure` skill slug, six certification tags (AZ-900, AZ-104, AZ-204, AZ-305, AZ-400, and AI-200), and nineteen learning-topic tags, including Azure Fundamentals, Azure Storage, Blob Storage, and Azure AI. Selecting AZ-900, AZ-104, AZ-305, AZ-400, or AI-200 adds the corresponding certification name and objective-area guidance to generated prompts. Topic and certification tags remain available in the Questions tag filter.

Imports validate structure, counts, difficulties, question types, tag focus, file size, and file extension before writing. The Import JSON popup accepts pasted JSON or an optional `.json` file, with target skill, optional tag focus, and Draft/Published status. File and paste source detection remains compatible with existing JSON files, legacy root arrays, and `questions` envelopes. Batch labels are created automatically when omitted. Duplicate questions are not inserted again; missing tags are attached to the existing question instead. JSON prompts require the downloadable filename `psm-[skill-slug]-[yyyy-mm-dd-HH-MM-SS].json`. Beginner prompts require simple, clear English for first-time learners, short sentences, no complicated vocabulary, foundational questions, and a short, concrete example in every explanation; the downloadable prompt applies the same rule to beginner items in mixed-level generation.

Master modules, controller dispatch, list rendering, reusable UI components, and a shared button component are separated into focused classes and traits. Admin CRUD, generation, import, batches, settings, and page rendering are extracted into focused traits. All shared-table reads pass through a common cache-first database reader. Frequently read master and selector queries use a namespaced WordPress object cache with filter-aware keys and an administrator-configurable duration of 5 to 1440 minutes (60 minutes by default); successful mutations invalidate the plugin cache generation immediately. Duplicate checks, write validation, transactional imports and seeding, exports, restore validation, and schema inspection always use fresh reads.

Shared-table writes pass through a common non-caching `PSM_Database` boundary with a fixed table allowlist, validated backup column identifiers, prepared values, and existing transaction and cache-invalidation behavior. Direct server-side AI provider adapters remain intentional for WordPress 5.8 compatibility; provider credentials never reach the browser.

== Installation ==

1. Copy the `psm-ai-question-importer` directory to `wp-content/plugins/`.
2. Activate the plugin through the WordPress Plugins screen.
3. Open **PSM AI Importer** in the admin menu.
4. Review the seeded masters under **Master Tables**.
5. Configure an AI provider under **AI Providers**, or use the JSON import workflow without a provider.

Database upgrades and master seeding are non-destructive. Existing `psm_*` master and question data is not rewritten by normal seeding.

== Frequently Asked Questions ==

= Are API keys displayed after saving? =

No. Saved API credentials are never rendered back into provider fields. Leave the field blank to keep the existing key, enter a new value to replace it, or use the clear control to remove it.

= How are tags assigned to questions? =

The question editor shows active tags owned by the selected skill. A new typed tag is normalized to the skill slug prefix, for example `C#` plus `Release Check` becomes `csharp-release-check`. Matching existing tags are reused. Inactive tags and tags owned by another skill are rejected.

= What happens when JSON contains duplicate questions? =

The existing question is updated with missing valid tags instead of creating a duplicate question. Tag ownership and active-status checks still apply.

= What wildcard characters work in the Tags filter? =

Use `*` for any sequence of characters and `?` for one character. Searches match tag names or slugs, such as `csharp-*` or `*oop`.

= What does Backup & Restore include? =

It includes the configured prefix's domain, skill type, question type, difficulty, skill, tag, question, question-tag, batch, and batch-question tables. Files are portable JSON, not executable SQL. Restore validates the format, tables, columns, upload, nonce, and administrator capability; uses transactions; and supports merge or complete-backup replace modes. Provider credentials and WordPress options are excluded.

= How is pagination configured? =

Open **Settings** and choose 5, 10, 15, 25, 50, 75, or 100 rows per page. The default is 25. One global setting applies to every master table and the Batches page. Server-side validation clamps invalid values to the supported range.

= How does query caching work? =

Master lists, counts, selectors, question navigation, batches, and common admin datasets use the `psm_ai_queries` object-cache group. The duration is configurable under **Settings** from 5 to 1440 minutes and defaults to 60 minutes. Every shared-table read uses the common `PSM_Cache` reader, which checks the cache before querying and prepares supplied arguments. Keys include the query, arguments, output format, site ID, configured `psm_*` table prefix, and normalized context. Successful empty and null results are cached; failed queries are not. Question, tag, skill, master, batch, import, seed, restore, activation, and upgrade writes invalidate the plugin cache immediately. Saving a new duration clears old entries. Use **Clear Cache** under **Settings** for a manual AJAX reset. Duplicate checks, write validation, import and seed transactions, backup exports, restore validation, and schema inspection deliberately bypass caching. Reads from `wp_options` are always fresh, and provider credentials are never handled by the shared table reader. Direct writes made by another plugin to the shared `psm_*` tables are not observed until the configured cache duration expires or the cache is cleared manually.

= How is Microsoft Azure seeded? =

Run **Seed Defaults → Run Idempotent Seed**. It adds the Microsoft Azure skill under Programming, six certification tags, and nineteen learning-topic tags only when their canonical slugs are missing. Existing data is preserved. The five named certification preparations add prompt guidance, while all Azure topic and certification tags can be used in the Questions filter.

= Does uninstalling delete shared question data? =

No. Uninstall removes plugin-owned settings, usage counters, and cached previews. Shared `psm_*` master and question tables are intentionally preserved for the programming-skills-master plugin.

= Can imported JSON be reviewed before it changes the database? =

Yes. JSON uploads and pasted JSON show a preview first. No question, tag, or batch rows are written until the confirmed import step.

== Changelog ==

= 1.7.8 =
* Fixed JSON file/paste source detection so valid uploads no longer produce a misleading "Paste JSON code before importing" error, while preserving legacy JSON formats and validation.
* Converted Settings cache-duration save and Clear Cache to AJAX with fixed top-right success/error notices and a validated 5–1440 minute duration setting.
* Converted Batches pagination to AJAX and refreshed batch rows after edits or deletion without reloading the page.
* Required the downloadable JSON filename `psm-[skill-slug]-[yyyy-mm-dd-HH-MM-SS].json` and strengthened beginner questions with clear first-time-learner English, short sentences, and simple vocabulary.
* Added temporary, non-database missing skill/tag options to Questions filters and preserved unresolved tag links when editing questions.
* Updated the release documentation without changing the database schema.

= 1.7.7 =
* Added server-side pagination to Batches and moved the shared rows-per-page setting into a dedicated Settings tab with a Clear Cache action.
* Made Questions the first and default master, reordered the remaining masters, standardized pointer cursors, and unified Cancel, Filter, Reset, and Delete button styling.
* Prevented duplicate IDs in delete confirmations, added fresh record-existence checks, expanded skill dependency checks to batches, and made batch relation deletion transactional.
* Replaced the Questions difficulty text with compact gray/orange/red numbered badges and hover tooltips.
* Seeded Microsoft Azure under Programming, six certification tags, and nineteen learning-topic tags without overwriting existing data.
* Added named Azure certification guidance to generation prompts and required simple English plus concrete examples for beginner questions, including downloadable prompts.
* Updated the release documentation without changing the database schema.

= 1.7.6 =
* Centralized plugin writes, deletes, transaction controls, restore upserts, migration updates, and uninstall option cleanup behind the non-caching `PSM_Database` boundary.
* Added fixed shared-table allowlisting and backup column-identifier validation while preserving prepared values, transactions, return semantics, and generation-wide cache invalidation.
* Documented intentional direct AI provider adapters required by WordPress 5.8 support; credentials remain server-side and are never displayed or backed up.
* Verified real `wpdb` writes and rollback on PHP 7.4 and 8.3 with unchanged shared-table counts, and cleared the reported Plugin Check database and provider findings.

= 1.7.5 =
* Centralized all shared-table reads behind cache-first `PSM_Cache::get_row()`, `get_results()`, `get_var()`, and `get_col()` wrappers with prepared arguments and query-aware keys.
* Preserved fresh reads for duplicate checks, write validation, transactional imports and seeding, backup exports, restore validation, and schema inspection; options-table reads are never cached.
* Prevented failed database reads from being cached and retained generation-wide invalidation after successful mutations.

= 1.7.4 =
* Routed custom admin buttons through the shared button component and restored the master Edit pencil with the valid Dashicons class.
* Placed pagination range information left and controls right, with the grouped Rows Per Page dropdown and Save button below.
* Added topic-based descriptions for all seed tags, filled blank master descriptions, and replaced deterministic generated tag descriptions without overwriting curated text or changing shared junction schemas.
* Expanded C++ slug normalization so spaced, hyphenated, underscored, CPP, CPlusPlus, and legacy cplush variants always produce cplusplus.

= 1.7.3 =
* Centered the plus icon in a compact Add button and moved the button to the far right of the master entity tabs.
* Moved Rows Per Page and Save Pagination into a filter-styled footer below each master table, preserving AJAX refreshes.
* Constrained master tables and surrounding containers to the available width to remove horizontal page scrolling without hiding or clipping content.

= 1.7.2 =
* Moved rows-per-page settings to the top of Master Tables and aligned a plus-icon-only Add control to the right.
* Kept desktop master filters on one line without changing field heights.
* Moved Run Idempotent Seed to a dedicated Seed Defaults tab with non-destructive guidance and confirmation.
* Added C and C++ skills and skill-prefixed topic tags using canonical `c` and `cplusplus` slugs; retained C# as `csharp` and accepted `cplush` as a C++ alias.
* Preserved canonical C-family slugs during tag normalization and JSON envelope validation.
* Removed duplicate native title tooltips where custom tooltips are shown.

= 1.7.1 =
* Restored clearly styled master-table edit icons and moved custom tooltips above their controls.
* Centered numeric pagination labels independently of navigation icons.
* Changed master text search to AJAX after three seconds of inactivity, with a three-character minimum for non-empty searches.
* Cancelled pending debounced searches when filters reset or change.

= 1.7.0 =
* Added shared create/get/set/remember cache helpers with normalized, blog- and table-prefix-aware keys and a one-hour default lifetime.
* Cached master counts, filtered lists, selectors, question records/navigation, generation lookups, JSON import lookups, and common admin datasets.
* Invalidated the complete plugin cache generation after successful master, question, tag, skill, batch, import, seed, restore, activation, and upgrade writes.
* Added a nonce- and capability-protected Clear Plugin Cache control with cache prefix, group, lifetime, and generation details under Settings.
* Reduced class-psm-admin-ui.php to bootstrap and hook orchestration by extracting settings, master CRUD, generation, import, batch, and page-rendering traits.
* Kept duplicate checks, write validation, exports, and schema reads fresh for correctness.

= 1.6.0 =
* Split master implementations, controller dispatch, list rendering, and common UI components into focused classes and traits.
* Moved admin CSS and JavaScript into versioned external assets.
* Centered dropdown text and made every pagination control a centered 36-by-36 square.
* Fixed the Questions table Skill column and restored master query results.
* Limited skill choices to the selected domain in filters, question/tag/skill editors, prompt generation, and JSON import, with matching server-side validation.
* Fixed valid pasted JSON being unslashed twice during preview.
* Added Backup & Restore for the configured psm table prefix with validated JSON uploads, merge/replace modes, and transactional writes.
* Centralized the table prefix and default page size in the plugin root and added a validated rows-per-page setting.
* Applied WordPress escaping, capability, nonce, and coding-standard hardening. Shared tables remain untouched by uninstall.

= 1.5.0 =
* Added AJAX filtering and pagination to every master table without page reloads.
* Added related filters for domains, skill types, question types, difficulties, skills, tags, and questions.
* Replaced browser delete confirmations and the seed confirmation with centered soft popups.
* Added a descriptive tooltip to Run Idempotent Seed.
* Fixed question previous/next navigation to follow the exact filtered order, including non-contiguous IDs.
* Renamed multiple-answer display to Multi choice.
* Simplified JSON import into one popup with skill, optional tag focus, import status, pasted JSON, optional file upload, and preview.
* Removed the visible batch label requirement; import batches are labeled automatically when omitted.
* Removed stray closing-tag output, hid compact filter labels, standardized filter heights, and moved notifications below the tabs.
* Changed master tab buttons to rectangular shapes with distinct hover and selected colors.

= 1.4.0 =
* Added skill-specific tag suggestions and new-tag creation in question add/edit.
* Enforced skill-slug tag prefixes while reusing matching existing tags.
* Added previous and next question navigation that follows the active filtered list.
* Added question text, skill, tag, and status filters.
* Added wildcard tag name/slug search and skill filtering.
* Added range summaries and icon-based First, Previous, Next, and Last pagination for questions and tags.
* Added colorful tabs, refreshed buttons and fields, responsive layouts, and hover/focus tooltips.
* Converted Import JSON File into an accessible modal with synchronized skill and tag focus.
* Prevented inactive modal fields from submitting conflicting master values.
* Added versioned `readme.txt` and `readme.md` documentation.

= 1.3.4 =
* Added question master add, edit, list, and delete workflows.
* Added validated question options, correct answers, explanations, external IDs, and tag links.
* Added transactional question saves and dependency-safe deletion.
* Added missing-tag attachment for duplicate JSON questions.

== Upgrade Notice ==

= 1.7.7 =
Adds Batches pagination, global pagination and cache controls under Settings, safer deletion, difficulty badges, Microsoft Azure seed data, and beginner/Azure prompt guidance. No database schema or settings migration is required. Existing data and provider credentials are preserved; run Seed Defaults once to add missing Azure taxonomy records.

= 1.7.5 =
Routes shared-table reads through the common cache-first reader while keeping correctness-sensitive checks fresh. No database schema change, seed, restore, or settings migration is required.

= 1.7.4 =
Centralizes custom admin buttons, restores master Edit pencils, refines the bottom pagination layout, completes seed descriptions, and guarantees the C++ slug. No database schema change is required; run Seed Defaults once to fill missing or generated descriptions while preserving curated text.

= 1.7.3 =
Moves the plus-only Add button into the right side of the master tabs, places filter-styled pagination settings below each table, and removes horizontal page overflow without changing data. No database schema change, seed, or restore is required.

= 1.7.2 =
Refines the master toolbar and single-line filters, moves seeding to Seed Defaults, and adds C/C++ skills and topic tags while preserving existing data. No database schema change is required.

= 1.7.1 =
Fixes master edit icons, tooltip placement, pagination alignment, and debounced search timing. No database schema change is required.

= 1.7.0 =
Adds one-hour namespaced query caching, automatic invalidation after plugin mutations, a manual cache-clear control, and smaller admin workflow traits. No database schema change is required.

= 1.6.0 =
Adds modular master classes and shared UI components, domain-aware skill selectors, square pagination, configurable page size, pasted-JSON validation fixes, and JSON Backup & Restore. No database schema change is required; create a backup before using Replace-all restore.

= 1.5.0 =
Adds AJAX master filters and pagination, centered soft confirmations, exact filtered question navigation, and the simplified paste/upload import popup. No database schema change is required.

= 1.4.0 =
Adds filtered question/tag management, skill-scoped tags, edit navigation, pagination, and the JSON import popup. No database schema change is required.
