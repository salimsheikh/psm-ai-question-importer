=== PSM AI Question Generator & Importer ===
Contributors: psm
Tags: questions, ai, quiz, importer, json
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.7.2
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate, preview, import, and manage AI questions with domain, skill, tag, batch, provider, and backup tools.

== Description ==

PSM AI Question Generator & Importer provides a six-tab workflow:

1. Prompt, Preview & Import: build a strict JSON prompt, copy or download it, upload saved JSON in a popup, validate it, and preview every row before import.
2. Master Tables: manage domains, skill types, question types, difficulties, skills, tags, and questions with AJAX filters and pagination.
3. Batches & Label Download: review imported batches and download question labels or CSV exports.
4. AI Providers: configure independent provider cards and monitor usage without displaying saved API keys.
5. Backup & Restore: export and restore the configured `psm_*` tables as validated JSON without including WordPress options or provider credentials.
6. Seed Defaults: add missing default domains, skills, and tags, including C, C++, and C#, with an explained idempotent seed action.

Question management includes skill-specific active tags, inline creation of new tags, automatic `<skill-slug>-<tag-name>` normalization, exact filtered previous/next editing, text/skill/tag/status filters, and pagination. Domain-aware selectors limit skills to the selected domain in question, tag, and skill editors, prompt generation, and JSON import. Pagination controls are fixed squares with centered labels and icons, and the administrator-configurable rows-per-page setting appears at the top of Master Tables and is validated between 5 and 100.

The seed defaults are non-destructive and idempotent. They add missing records by slug, preserve existing names, ownership, statuses, and descriptions, may fill blank descriptions, and never change existing questions or question-tag relationships. C and C++ use the canonical slugs `c` and `cplusplus`; C# uses `csharp`.

Imports validate structure, counts, difficulties, question types, tag focus, file size, and file extension before writing. The Import JSON popup accepts pasted JSON or an optional `.json` file, with target skill, optional tag focus, and Draft/Published status. Batch labels are created automatically when omitted. Duplicate questions are not inserted again; missing tags are attached to the existing question instead.

Master modules, controller dispatch, list rendering, and reusable UI components are separated into focused classes and traits. Admin CRUD, generation, import, batches, settings, and page rendering are extracted into focused traits. Frequently read master and selector queries use a namespaced one-hour WordPress object cache with filter-aware keys; successful mutations invalidate the plugin cache generation immediately.

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

The default is 25 rows per page. Administrators can choose 5, 10, 15, 25, 50, 75, or 100 under Master Tables. Server-side validation clamps invalid values to the supported range.

= How does query caching work? =

Master lists, counts, selectors, question navigation, and common admin datasets are cached for one hour in the `psm_ai_queries` object-cache group. Question, tag, skill, master, batch, import, seed, restore, activation, and upgrade writes invalidate the plugin cache immediately. Use **Clear Plugin Cache** under **Settings** for a manual reset. Direct writes made by another plugin to the shared `psm_*` tables are not observed until the one-hour cache expires or the cache is cleared manually.

= Does uninstalling delete shared question data? =

No. Uninstall removes plugin-owned settings, usage counters, and cached previews. Shared `psm_*` master and question tables are intentionally preserved for the programming-skills-master plugin.

= Can imported JSON be reviewed before it changes the database? =

Yes. JSON uploads and pasted JSON show a preview first. No question, tag, or batch rows are written until the confirmed import step.

== Changelog ==

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
