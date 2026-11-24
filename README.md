# PSM AI Question Generator & Importer

**Version:** 1.6.0  
**Requires:** WordPress 5.8+ and PHP 7.4+  
**License:** GPL-2.0-or-later

Generate structured AI questions, review them before import, and manage question masters, skills, tags, batches, and AI provider settings from WordPress admin.

## Features

- Five admin tabs: Prompt/Preview/Import, Master Tables, Batches/Label Download, AI Providers, and Backup & Restore.
- Modular master classes, controller dispatch, shared list rendering, and reusable UI component traits.
- Versioned external admin CSS and JavaScript assets.
- AJAX filters and pagination for every master table, with related domain, status, skill-type, and skill filters.
- Domain-aware skill selectors in filters, question/tag/skill editors, prompt generation, and JSON import, with server-side ownership validation.
- Fixed-square pagination controls with centered numbers/icons and an administrator-configurable page size (5–100).
- Portable JSON Backup & Restore for the configured `psm_*` tables, with validated uploads, merge/replace modes, transactions, and no executable SQL.
- Provider credentials and WordPress options are excluded from backups.
- Strict JSON prompt builder with copy and download actions.
- Import JSON popup with pasted JSON or optional file upload, target skill, optional tag focus, import status, and preview-before-import.
- Question master with add, edit, list, and delete.
- Skill-specific active-tag suggestions and inline new-tag creation.
- Automatic tag slugs using `<skill-slug>-<tag-name>`, with existing-tag reuse.
- Previous/next question editing that follows the exact filtered order, including non-contiguous IDs.
- Question filters for text, skill, tag, and status.
- Tag wildcard search by name or slug and a skill filter.
- Centered soft confirmations for deletes and idempotent seeding.
- Left-side range information and icon-based First/Previous/Next/Last pagination.
- Colorful tabs, rectangular master buttons, responsive layouts, and hover/focus tooltips.
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
5. Configure an AI provider under **AI Providers**, or use **Import JSON** through **Prompt, Preview & Import**.

## Question and Tag Workflow

1. Open **Master Tables** and select **Questions**.
2. Add a question or choose **Edit** on an existing row.
3. Select a skill to load its active tags.
4. Pick existing tags, or type a new tag and choose **Add tag**.
5. Save the question. New tags are normalized to the selected skill slug, such as `html-release-check`.
6. Use the modal’s previous/next arrows to move through the exact filtered question order without skipping non-contiguous IDs.
7. Use the question filters to narrow the list by text, skill, tag, or status; filtering and pagination run through AJAX without reloading the page.

All master tables include AJAX search and pagination. Skill Types can be filtered by domain, Question Types and Difficulties by status, Skills by domain/type/status, and Tags by wildcard name/slug, domain, and skill. Question, tag, and skill editors limit skill choices to the selected domain.

The default page size is 25. Under **Master Tables**, administrators can select 5, 10, 15, 25, 50, 75, or 100 rows per page; the server clamps invalid saved values to the supported range.

The **Tags** tab supports `*` for any sequence and `?` for one character. Examples include `csharp-*` and `*oop`.

## JSON Import Workflow

1. Select a domain, then select a skill and optional tag focus in **Prompt, Preview & Import**.
2. Create the prompt and copy or download it.
3. Save the AI output as a `.json` file, or copy the JSON code directly.
4. Choose **Import JSON** to open the popup.
5. Confirm the target skill, optional tag focus, and Draft/Published import status.
6. Paste JSON and choose **Import Pasted JSON**, or select a `.json` file and choose **Preview**.
7. Review validation results and confirm the import. Batch labels are created automatically when omitted.

Pasted JSON and uploads are limited to 5 MB and validated before import. Both actions open the preview; no question, tag, or batch rows are written until the confirmed import step. Missing tags are created only for the selected skill. Inactive tags and tags owned by another skill are rejected. Duplicate questions reuse the existing row and receive missing valid tags.

## Backup & Restore

Open **Backup & Restore** to download a portable JSON snapshot of the configured prefix's domains, skill types, question types, difficulties, skills, tags, questions, question-tag links, batches, and batch-question links.

- Backups never include `wp_options`, provider settings, or API credentials.
- Restore accepts only supported `.json` files up to 24 MB and validates their format, version, tables, rows, and current-schema columns.
- **Merge** inserts new records and updates matching records. **Replace all** requires a complete backup, clears the included tables, and restores it in dependency order.
- Writes run in a transaction and roll back on failure. SQL contained in a backup is never executed.

## Security and Data Handling

- AJAX requests use nonces and administrator capability checks.
- Provider credentials remain server-side and are never displayed after saving.
- Leaving an API-key field blank preserves the stored key.
- Import processing uses prepared database queries and validates uploaded files.
- Question/tag saves run in database transactions.
- Uninstall removes only plugin-owned settings, counters, and cached previews. Shared `psm_*` tables remain intact.

## Changelog

### 1.6.0

- Split each master into a focused class with shared UI/list traits and controller dispatch.
- Moved admin CSS and JavaScript into versioned external assets.
- Centered dropdown text and made all pagination controls centered 36-by-36 squares.
- Fixed the Questions Skill column and restored query results for all master lists.
- Limited skills to the selected domain in filters, question/tag/skill editors, prompt generation, and JSON import, with backend ownership checks.
- Fixed valid pasted JSON being unslashed twice before validation.
- Added validated, transactional JSON Backup & Restore with merge/replace modes.
- Centralized the shared table prefix and default page size in the plugin root; added a validated page-size setting.
- Applied additional WordPress escaping, nonce, capability, and coding-standard fixes without rewriting existing master data.

### 1.5.0

- Added AJAX filtering and pagination for every master table without page reloads.
- Added related name, domain, skill-type, skill, and status filters.
- Replaced browser delete and seed confirmations with centered soft popups.
- Added a descriptive Run Idempotent Seed tooltip.
- Fixed question previous/next navigation to follow the exact filtered order, including non-contiguous IDs.
- Renamed multiple-answer display to Multi choice.
- Simplified JSON import into a skill/tag/status popup with pasted JSON and optional file upload.
- Removed the visible batch-label requirement; omitted labels are generated automatically.
- Removed stray closing-tag output, hid compact filter labels, standardized field heights, and moved notifications below the tabs.
- Changed master buttons to rectangular shapes with distinct hover and selected colors.

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

Version 1.6.0 does not require a database schema change. Existing questions, tags, batches, settings, provider credentials, and shared tables are preserved. A backup is recommended before using Replace-all restore.
