# PSM AI Question Generator & Importer

**Version:** 1.4.0  
**Requires:** WordPress 5.8+ and PHP 7.4+  
**License:** GPL-2.0-or-later

Generate structured AI questions, review them before import, and manage question masters, skills, tags, batches, and AI provider settings from WordPress admin.

## Features

- Four admin tabs: Prompt/Preview/Import, Master Tables, Batches/Label Download, and AI Providers.
- Strict JSON prompt builder with copy and download actions.
- JSON upload popup with schema validation, tag-focus checks, and preview-before-import.
- Question master with add, edit, list, and delete.
- Skill-specific active-tag suggestions and inline new-tag creation.
- Automatic tag slugs using `<skill-slug>-<tag-name>`, with existing-tag reuse.
- Previous/next question navigation that follows the current filtered list.
- Question filters for text, skill, tag, and status.
- Tag wildcard search by name or slug and a skill filter.
- Left-side range information and icon-based First/Previous/Next/Last pagination.
- Colorful tabs, refreshed controls, responsive layouts, and hover/focus tooltips.
- Independent AI provider settings and usage counters.
- Saved API keys are never echoed back into the UI.
- Duplicate imports attach missing valid tags to the existing question instead of inserting another question.
- Non-destructive database upgrades and master seeding.
- Uninstall preserves shared `psm_*` master and question tables.

## Installation

1. Copy `psm-ai-question-importer` into `wp-content/plugins/`.
2. Activate **PSM AI Question Generator & Importer**.
3. Open **PSM AI Importer** in the WordPress admin menu.
4. Review the seeded records under **Master Tables**.
5. Configure an AI provider under **AI Providers**, or upload saved JSON through **Prompt, Preview & Import**.

## Question and Tag Workflow

1. Open **Master Tables** and select **Questions**.
2. Add a question or choose **Edit** on an existing row.
3. Select a skill to load its active tags.
4. Pick existing tags, or type a new tag and choose **Add tag**.
5. Save the question. New tags are normalized to the selected skill slug, such as `html-release-check`.
6. Use the modal’s previous/next arrows to edit adjacent questions in the filtered list.
7. Use the question filters to narrow the list by text, skill, tag, or status.

The **Tags** tab supports `*` for any sequence and `?` for one character. Examples include `csharp-*` and `*oop`.

## JSON Import Workflow

1. Select a skill and optional tag focus in **Prompt, Preview & Import**.
2. Create the prompt and copy or download it.
3. Save the AI output as a `.json` file.
4. Choose **Import JSON File** to open the upload popup.
5. Select the JSON file and continue to the preview.
6. Review validation results and confirm the import.

Uploads are limited to 5 MB and validated before import. Missing tags are created only for the selected skill. Inactive tags and tags owned by another skill are rejected. Duplicate questions reuse the existing row and receive missing valid tags.

## Security and Data Handling

- AJAX requests use nonces and administrator capability checks.
- Provider credentials remain server-side and are never displayed after saving.
- Leaving an API-key field blank preserves the stored key.
- Import processing uses prepared database queries and validates uploaded files.
- Question/tag saves run in database transactions.
- Uninstall removes only plugin-owned settings, counters, and cached previews. Shared `psm_*` tables remain intact.

## Changelog

### 1.4.0

- Added skill-scoped tag suggestions and new-tag creation to question add/edit.
- Enforced `<skill-slug>-<tag-name>` tag slugs and matching-tag reuse.
- Added filtered previous/next question editing.
- Added question text, skill, tag, and status filters.
- Added wildcard tag name/slug searches and skill filters.
- Added range summaries and icon pagination for questions and tags.
- Added colorful tabs, refreshed fields/buttons, responsive layouts, and tooltips.
- Converted JSON upload into an accessible modal synchronized with generator skill/tag focus.
- Excluded inactive modal fields from submission to prevent conflicting master values.
- Added `readme.txt` and `readme.md` release documentation.

### 1.3.4

- Added the question master CRUD workflow.
- Added validated options, correct answers, explanations, external IDs, and tag links.
- Added transactional saves and dependency-safe deletion.
- Added missing-tag attachment for duplicate JSON questions.

## Upgrade Notes

Version 1.4.0 does not require a database schema change. Existing questions, tags, batches, settings, and provider credentials are preserved.
