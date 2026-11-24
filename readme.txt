=== PSM AI Question Generator & Importer ===
Contributors: psm
Tags: questions, ai, quiz, importer, json
Requires at least: 5.8
Tested up to: 7.1.5
Requires PHP: 7.4
Stable tag: 1.4.0
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate structured AI questions, review them before import, and manage question masters, skills, tags, batches, and AI provider settings in WordPress admin.

== Description ==

PSM AI Question Generator & Importer provides a four-tab workflow:

1. Prompt, Preview & Import: build a strict JSON prompt, copy or download it, upload saved JSON in a popup, validate it, and preview every row before import.
2. Master Tables: manage domains, skill types, question types, difficulties, skills, tags, and questions.
3. Batches & Label Download: review imported batches and download question labels or CSV exports.
4. AI Providers: configure independent provider cards and monitor usage without displaying saved API keys.

Question management includes skill-specific active tags, inline creation of new tags, automatic `<skill-slug>-<tag-name>` normalization, previous/next editing, text/skill/tag/status filters, and pagination. Tag management includes wildcard name or slug searches and skill filters.

Imports validate structure, counts, difficulties, question types, tag focus, file size, and file extension before writing. Duplicate questions are not inserted again; missing tags are attached to the existing question instead.

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

= Does uninstalling delete shared question data? =

No. Uninstall removes plugin-owned settings, usage counters, and cached previews. Shared `psm_*` master and question tables are intentionally preserved for the programming-skills-master plugin.

= Can imported JSON be reviewed before it changes the database? =

Yes. JSON uploads show a preview first. No question, tag, or batch rows are written until the confirmed import step.

== Changelog ==

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

= 1.4.0 =
Adds filtered question/tag management, skill-scoped tags, edit navigation, pagination, and the JSON import popup. No database schema change is required.
