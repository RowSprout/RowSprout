# RowSprout (free plugin) — guide for AI agents and developers

Generates SEO landing pages at scale: one **template** (built in the block editor, Elementor or WPBakery) plus a table of **groups** (rows of values) becomes one generated page per group. This is the **core** plugin. Its add-on `../rowsprout-pro` (read its `AGENTS.md` too) adds planning, WPML, MCP/WP-CLI, extra field types, extra Elementor elements, Smart Generate and a live status overview on the Templates list (the free plugin has its own simple Status column, `Admin\TemplateStatusColumn`; Pro renders its cell through `rowsprout_render_template_status_cell`). Tests and diagnostics live in a separate, private development plugin (`rowsprout-support`).

Keep this file short and true: change it in the same change that alters what it describes, and point at files instead of copying details. Where this file and the code disagree, the code wins — then fix this file.

## Mental model

- **Template** = `rowsprout_template` post (hierarchical, nests one level: `SavePost::limitPostDepth` on `wp_insert_post_data` drops a parent that is itself a child, or on a template that has children). Its config lives in postmeta `_rowsprout_page_template_json` (`Core/TemplateMeta.php`, normalised by `Core/Template/ConfigNormalizer.php`): `rowsprout_page_href` (URL pattern — an internal config key), `field_types[]` ("properties" = the columns: key/type/label/code), `groups[]` (the rows), `child_extra[]`, `config_updated_at`.
- **Group** = `{ id, parent_id, fields: { <key>: { type, value, code, id } } }`. `id` is the group's guid and is only unique **within its template** (a WPML translation starts with the same ids), so every lookup is scoped by template id.
- **Group row** = one row per group in table `{prefix}rowsprout_page_groups` (`Infrastructure/Database/GroupsTable.php`; all SQL goes through `Core/Groups/GroupTableGateway.php`): `rowsprout_template_id`, `guid`, `parent_guid`, `rowsprout_page_id` (0 = no page yet), `status`, `generated_at`, and `scheduled_at` (added by Pro, see `supportsScheduling()`). Unique on (template, guid).
- **Generated page** = `rowsprout_page` post, always updated **in place** (same post id). Identified by postmeta `_rowsprout_page_source_template_id` + `_rowsprout_page_source_group_id`; `PageUpserter` falls back to that pair when a row lost its `rowsprout_page_id`. Generated pages are locked from editing/deleting by the capability defaults in `Core/PostTypes.php` (`do_not_allow` on the `edit_/delete_{others,published,private}_posts` caps); the Settings page can unlock them (`Core/PageEditing`, option `rowsprout_unlock_pages`), which drops those keys; `rowsprout_post_type_capabilities` can still adjust the result.
- **Placeholders**: template title/content/href may contain `@code_<code>_<templateId>@` tokens (`Core/Page/GroupPlaceholderTokenResolver.php`); WPML sibling template ids resolve as aliases. Title/href use the plain replacement (`PageBuilder::applyGroupPlaceholderTokens`); content and meta go through `applyGroupPlaceholderTokensToMarkup` → `Core/Page/MarkupTokenReplacer.php`, which replaces inside decoded block attributes and escapes values that land in HTML attributes (text stays raw, so Pro's Rich text still renders as HTML). JSON meta such as `_elementor_data` is decoded first (`PageMetaReplicator::replaceTokensInString`).
- Generated slugs deliberately skip WP's slug-uniqueness check (`HrefUniquenessValidator` is the backstop). Never re-add `wp_unique_post_slug` — the docblock in `PageUpserter` explains the infinite recursion that caused.

## Lifecycle of a page

1. Admin saves a template (classic screen, block editor, or the Elementor "RowSprout template" tab — the block editor saves through REST first and posts the metabox right after; `Admin/TemplateBlockEditor` makes `handleSave` skip the REST request (header `X-RowSprout-Block-Editor`), carries a title/content change over to the metabox request, supplies the save action from its sidebar panel as hidden metabox fields, and refreshes `dp_config_version` + shows the save notices through `POST /rowsprout/v1/templates/<id>/after-save`, since the page never reloads) → `Core/SavePost::handleSave` on `save_post_rowsprout_template`. It reads request fields only after verifying the form's own nonce (`rowsprout_template_nonce`, rendered by `TemplateTabsRenderer`) and `edit_post`; without them (WPML save-back, WPBakery, quick edit, API) it runs on the stored values. Elementor's Update/Publish goes through `TemplateSaveActionControl::toSaveInput()` → `SavePost::saveWithInput()` instead of writing to `$_POST`. Then: href-uniqueness check, optimistic-concurrency check on `config_updated_at` (stale forms don't overwrite API writes), `PayloadConfigBuilder`, `TemplateMeta::save`, then `handlePostSaveQueue`.
2. The **save action** (`_rowsprout_page_save_action`; registry `SavePost::getTemplateSaveActions`, filter `rowsprout_template_save_actions`) decides what happens. `update_pages` generates: only changed/new groups become `pending` (`TemplateSyncMarker::queueChangedGroups`; the "Regenerate all pages" checkbox does a full `rowsprout_queue_groups`). An action with `disable_schedule = true` (`save_template`, Pro's `schedule_pages`) generates nothing: affected groups become `stale` (`markStaleFromSmallAdjustments`). `TemplateSaveActionState::actionGenerates()` answers "does this action generate?" for the parent→child cascade and for WPML completion.
3. Which groups a save marks: `Lifecycle/TemplateSyncMarker` marks every group of the template and of its child templates. Change detection (only the affected groups) is RowSprout Pro's Smart Generate, plugged in through the filters `rowsprout_template_changed_groups` and `rowsprout_child_template_changed_parent_groups`; the free plugin has no diffing or change tracking of its own.
4. **Queue tick**: recurring Action Scheduler action `rowsprout_process_groups_queue` (every minute, group `rowsprout_page`) → `Core/Scheduler/QueueProcessor::processQueue`: promote due `scheduled` rows to `pending` → stop if outside the processing window (filter `rowsprout_is_within_processing_window`) or if any row is `in-process` → take up to N rows (filter `rowsprout_groups_queue_limit`, default 40) via `GroupTableGateway::getPendingGroupsForProcessing` (roots before children; a child waits until its parent group has a page; never-generated first, then oldest `generated_at`; new pages are not capped) → mark `in-process` and enqueue async action `rowsprout_process_single_group`.
5. `processSingleGroup` → `Core/Page/PageBuilder::createById` (context: `PageBuildContextResolver`; child inheritance via filter `rowsprout_resolve_child_template_context`, served by `Core/ChildTemplates/ChildTemplateInheritance`; upsert; meta copy; thumbnail) → row `completed` + `generated_at`, or `failed` (`handleFailedExecution` also marks failed).
6. Hourly `rowsprout_cleanup_hook` deletes old finished Action Scheduler actions of group `rowsprout_page`.

### Row statuses (`GroupTableGateway::STATUS_*`)

| Status | Meaning |
|---|---|
| `stale` | outdated or not queued yet (saved under "Save template only", new group, cleared plan) |
| `pending` | queued; the next tick picks it up |
| `scheduled` | Pro: planned; `scheduled_at` is when it becomes `pending` |
| `in-process` | handed to Action Scheduler |
| `completed` / `failed` | last result |
| `waiting` | accepted by `QueueManager::queue()`, but nothing in this repo sets it |
| `deleted-<status>` | template trashed (`TemplateDeletionStatus`); restoring strips the prefix |

## Invariants that are easy to break

1. **A `scheduled` (planned) group is never re-marked implicitly.** Implicit paths (template saves, parent cascades, WPML completion, first-time bootstrap) pass `$protectScheduled` / `$preserveScheduled` / `$exceptScheduled` (`TemplateLifecycleGroupTableGateway::markStale*`, `rowsprout_queue_groups`, `QueueManager::queue`, `GroupTableGateway::set*ByPostId`). Only an explicit "generate now" action may override a plan.
2. Use `QueueManager::syncGroupStructure( $id, false )` (no pruning) for any trigger whose read of the config is not authoritative (WPML completion): pruning deletes pages.
3. A child group is never processed before its parent group's page exists, and cannot be planned before its parent.
4. Group ids are unique only per template; template-scope every lookup.
5. The free plugin must work without Pro: guard Pro-owned columns with `supportsScheduling()` / `supportsGeneratedAt()` (schema changes only apply on `admin_init`).
6. The free plugin enforces no usage limit anywhere (see "No usage limits" below); Pro's licence decides only which Pro features load.
7. `assets/*/groups-metabox.min.*` are what production loads (unminified only when `WP_DEBUG` is true, `GroupsMetaBoxAssets::useUnminifiedAssets`). There is no build script in this repo: after editing the source JS/CSS, regenerate the `.min` file or production keeps serving old code — and a stale `.min` is invisible on a `WP_DEBUG` dev site.
8. **A property keeps its stored code.** Placeholders on pages use it, so a save never re-derives it from the label (`PayloadConfigBuilder::storedCodes`). Only a new property gets one: `SavePayloadSanitizer::generateCodeFromTitle()` (`remove_accents()` for the site locale, `[a-z0-9_]`, the type's `default_code` when nothing is left, as with a label in another script) plus `uniqueCode()`. `sanitizeCode()`/`removeAccents()` in `groups-metabox.js` mirror it, and `normalizeSlug()` there mirrors `HrefFieldType::sanitize()` (`sanitize_title()`, stored decoded so other scripts stay readable); change them together.
9. **Slash what you store.** `update_post_meta()`, `wp_insert_post()` and `wp_update_post()` unslash their input, so pass user text through `wp_slash()` or backslashes (paths, CSS escapes like `\f00c`) are lost; `update_option()` does not unslash.

## Code map (`src/`)

| Path | Responsibility |
|---|---|
| `Plugin.php`, `Support/Autoloader.php` | bootstrap; own PSR-4-style loader (Composer only manages `vendor/`: Action Scheduler) |
| `Core/SavePost.php`, `Core/Template/Lifecycle/*` | save flow, change tracking, stale/queue marking, trash/restore/delete |
| `Core/Groups/*` | `GroupTableGateway` (every query on the group table), repository/row store/deletion |
| `Core/Scheduler*`, `Compat/functions/scheduler.php` | queue registration (`QueueManager`), tick (`QueueProcessor`), cleanup; helper `rowsprout_queue_groups()` |
| `Core/Page/*` | building one page: `PageBuilder`, context/tokens (`MarkupTokenReplacer` for markup), upsert, meta replication, generated field meta |
| `Blocks/BlockRegistry.php` + `assets/js/blocks.js` | dynamic block `rowsprout/page-grid` ("Grid", category `rowsprout-pages`, any post type); editor preview via ServerSideRender. The Item List block (`rowsprout/item-list`, category `rowsprout`) belongs to Pro, with the Item-list type. `Core/Grid/PageGridRenderer` is the Grid markup shared with the Elementor `GridWidget` — keep their output identical |
| `Core/BlockBindings/PropertyBindingSource.php` + `assets/js/block-editor-bindings.js` | Block Bindings source `rowsprout/property`: core blocks take an attribute from a property, resolved at RENDER time (not a token) through `PageFieldResolver`; `format()` is shared by the page and the editor preview. `Core/Template/InsertableTokenFields` is the shared list for the inline-token buttons (Elementor Text Editor + block editor rich text, `assets/js/block-editor-token-button.js`) |
| `Core/Template/*` | config normalising, `FieldTypes/` (one class per type, `FieldTypeManager`, filter `rowsprout_field_types`; the free types are Title, Href, Textfield, Textarea, URL, Email and Number — Checkbox, Date, Date/Time, Item-list and Icon are Pro types, formatted by Pro through `rowsprout_property_binding_{url,html,date}` and `rowsprout_placeholder_field_aliases`), `Lookup/TemplateDataReader` |
| `Core/PostTypes.php`, `PermalinkSettings.php`, `RemoveCptBase.php` | the two post types and the (optionally base-less) URL scheme |
| `Admin/*` | menus (`MenuRegistrar` orders the submenu by slug), `TemplateBlockEditor` + `assets/js/template-block-editor.js` (templates in the block editor: save flow, "Save action" sidebar panel, notices — see its docblock), the Templates list is WordPress's own `edit.php?post_type=rowsprout_template` (menu item in `MenuRegistrar`; Home shows the latest templates), `ChildTemplateAdminUx` (Parent dropdown limited to valid parents, trash/restore row actions and bulk checkboxes follow parent/child pairs), `Metaboxes/ParentPayloadAjaxHandler` + `ParentSyncPayloadRenderer`/`ParentSyncDataBuilder`/`InheritedGroupOverlay` (the Parent dropdown's live sync of the Properties/Groups tabs), `UpgradeMenu` (purely informational "what does Pro add" page — no license, no gating), `Metaboxes/` (`TemplateTabsRenderer` renders the General/Properties/Groups tabs plus tabs added by filter — the same renderer feeds the classic screen and the Elementor modal) |
| `ThirdParty/Elementor/*` | widgets Textarea/Heading/Button/Grid (the Item List and Date widgets are Pro's, with their field types; widget titles carry no "RowSprout" prefix — the panel group already says it; never change a `get_name()` without a content migration, Elementor matches stored content by it. `ButtonWidget` reuses Elementor's `Button_Trait` and overrides `get_settings_for_display()` to inject the resolved link — see its docblock), the widget categories `rowsprout_page` ("RowSprout": current page's own data) and `rowsprout_pages` ("RowSprout pages": Grid, any post type), `TemplateEditorTab` (modal, printed on `elementor/editor/footer`; its nav button is added by `assets/js/elementor-template-tab.js` — never re-echo buffered Elementor markup, WP.org review flags it), `TemplateSaveActionControl`, `PageFieldResolver`, `TextEditorTokenButton` (TinyMCE button in the Text Editor widget that inserts `@code_…@` tokens; its docblock explains the priority-20 filters and which types are allowed). The Thumbnail and Sibling Links widgets and the Page Field / Page Link dynamic tags are Pro (`../rowsprout-pro/src/ThirdParty/Elementor`); `PageFieldResolver` stays here because they use it |
| `ThirdParty/RankMath.php`, `WpRocket.php` | integration glue |
| `ThirdParty/WPBakery/WPBakeryIntegration.php` | WPBakery (incl. theme-bundled builds such as Salient's `js_composer_salient`, which ignores `vc_set_default_editor_post_types()` — hence the `vc_check_post_type_validation` filter, which respects an explicit Role Manager choice): templates enabled, the shortcode-attribute encoder for `MarkupTokenReplacer` (filter `rowsprout_shortcode_attribute_encoder`: `"`→` `` `, `[`/`]`→`` `{` ``/`` `}` ``, url-encoded tokens in link fields), element `rowsprout_page_grid` (the `rowsprout_item_list` element is Pro's). Saves go through `wp_update_post()` (backend form, frontend `vc_save`), so `handleSave` runs unchanged. The classic-screen TinyMCE token button (`TextEditorTokenButton::onClassicEditScreen`) also serves WPBakery's text editors |

## Extension points (how Pro plugs in)

The free plugin only offers generic hooks; everything user-facing that is Pro lives in Pro. Consumers are in `../rowsprout-pro`.

| Hook | Fired in | Pro consumer |
|---|---|---|
| filter `rowsprout_template_save_actions` | `SavePost`, `PublishBoxRenderer`, `TemplateSaveActionState` | `TemplateScheduling` (adds `schedule_pages`) |
| filter `rowsprout_template_tabs` | `TemplateTabsRenderer` | `GroupPlanning` (Planning tab) |
| actions `rowsprout_render_template_save_action_fields`, `rowsprout_elementor_save_action_controls` | publish box, Elementor panel | `TemplateScheduling` (hint) |
| filters `rowsprout_is_within_processing_window`, `rowsprout_groups_queue_limit`, `rowsprout_groups_queue_interval` | queue | `TemplateScheduling` (window), `BatchScheduling` (limit) |
| filter `rowsprout_resolve_child_template_context` | `PageBuildContextResolver` | this plugin's `Core/ChildTemplates/ChildTemplateInheritance` |
| filter `rowsprout_group_placeholder_alias_code_ids` | `PageBuildContextResolver` | `WpmlPlaceholderAliasIds` |
| `rowsprout_page_defaults_before_upsert`, actions `rowsprout_page_before_upsert` / `_after_upsert` | `PageBuilder` | `WpmlLanguageContext`, `WpmlPageLanguage` |
| filter `rowsprout_field_types` | `FieldTypeManager` | `ProFieldTypes` |
| filter `rowsprout_placeholder_value` (`$value, $fieldType, ['key','code','group','template_id']`) | `GroupPlaceholderTokenResolver::resolve()` — the text a property's placeholder becomes everywhere (content, title, URL pattern, meta); attributes/block JSON are escaped by `MarkupTokenReplacer`, running text is not | none yet (meant for add-on types) |
| filter `rowsprout_group_field_input_html` (`null, ['name','value','type','kind','key','row','options','placeholder','locked']`) + JS (`wp.hooks`) filter `rowsprout.groupFieldInputHtml` and action `rowsprout.groupFieldsRendered` | `GroupFieldInputRenderer` (groups drawn on load) and `groups-metabox.js` (groups/properties added in the browser; the action fires on load, new group, new property and parent sync) — an add-on type's own input in the groups table; it must post under `name`; the parent sync's value snapshot (`readFieldValue`/`writeFieldValue`) reads and restores each property through that named control, so hidden-input widgets keep their value | none yet (covered by rowsprout-support `test-field-type-extension.php` + `tests/ui/ui-test-field-input-hooks.js`) |
| filter `rowsprout_post_type_capabilities`, action `rowsprout_settings_page_after_permalink` | `PostTypes`, `Menu` | none (Pro only migrates its old page-unlocking option) |
| filter `rowsprout_url_candidate_path` | `RemoveCptBase` | `WpmlLanguagePrefix` |
| action `rowsprout_groups_queued` | `SavePost`, Pro `GroupPlanningService` | none |
| filters `rowsprout_skip_handle_save`, `rowsprout_global_change_meta_keys(_prefixes)`, `rowsprout_excluded_meta_keys`, `rowsprout_reconciled_meta_key_prefixes`, `rowsprout_page_delete_post_meta_keys` | save flow, trackers, `PageMetaReplicator` (which template meta is copied onto pages), `Helpers` | free-plugin integrations (Elementor, RankMath) |
| filters `rowsprout_get_groups`, `_get_group_by_id`, `_get_child_extra(_by_id)`, `_get_code_ids`; action `rowsprout_page_delete_wpml_translations` | `TemplateDataReader`, `GroupDeletionService` | none in this repo |
| filter `rowsprout_handle_template_save_action` (+ save-action `handler_callback`) | `SavePost::handleCustomTemplateSaveAction` | none — generic save-action API, kept |

## Storage

- **Table** `{prefix}rowsprout_page_groups`, schema version option `rowsprout_db_schema_version` (`Core/Database`, migrates on `admin_init`).
- **Options**: `rowsprout_permalink_base` (`rowsprout_free_backlog` is obsolete; the doctor reports it as a leftover). Pro adds `rowsprout_license` (same option, moved there), `rowsprout_processing_window_enabled|_start|_end`, `rowsprout_queue_batch_size`. This plugin also has `rowsprout_unlock_pages` (Settings → generated page editing; Pro's old `rowsprout_pro_unlock_pages` is migrated into it).
- **Post meta**: on templates `_rowsprout_page_template_json`, `_rowsprout_page_save_action`; on pages `_rowsprout_page_source_template_id`, `_rowsprout_page_source_group_id`, `_rowsprout_page_field_*` (incl. `_rowsprout_page_field_id_<id>`).
- **Transients**: `rowsprout_{href_duplicate,href_token_missing,parent_not_generated,config_conflict}_notice_<userId>`. Save handlers cannot show notices before the redirect, so they are shown on the next page load.
- **Action Scheduler hooks** (group `rowsprout_page`): `rowsprout_process_groups_queue`, `rowsprout_process_single_group`, `rowsprout_cleanup_hook`. Pro adds `rowsprout_license_revalidate`. There is no uninstall routine: when you remove a feature that scheduled actions or stored options, unschedule/delete them on every site by hand.
- Every identifier above is stored data (post types, postmeta, options, the table and its columns, Action Scheduler hooks, block and Elementor widget names): renaming one needs a data migration for existing sites.

## No usage limits (no license concept at all)

WordPress.org's Guideline 5 (Trialware) prohibits limiting a built-in feature behind a licence, a quota or "any other kind of intended restriction" — including a restriction that only exists in the UI. This plugin therefore has no licence concept and limits nothing; licensing, tiers and updates live in `rowsprout-pro`, which is not hosted on WordPress.org.

- Never add a count check, redirect, hidden button or trash-on-save that stops a template or page from being created.
- The Templates list and "Add New" stay plain WordPress. A post type with `show_ui` gets a list screen from WordPress, so hiding or redirecting it counts as an active restriction (unlike a plugin whose post type has no list screen at all).
- Never leave free code that only works once Pro is active (an endpoint, class or filter consumer that only Pro provides). Pro only *adds* things on top: status column and row actions on the list, Smart Generate change detection, planning, WPML, field types, Elementor extras, throttling, MCP/CLI, updates.
- `Admin\UpgradeMenu` is a static, purely informational "what does Pro add" page — no form, no external request — which the guideline explicitly allows ("point out which features are available through a separated plugin").

## Updates

The self-hosted update checker (`PluginUpdateChecker`, talks to `license.rowsprout.com`) lives in `rowsprout-pro`'s own `Updates/` now, not here — a self-hosted updater is a hard-blocking WordPress.org Plugin Check finding, and this free plugin is meant to be WP.org-hosted, so it must never register one. See `../rowsprout-pro/AGENTS.md`'s own "Updates" section. This plugin no longer has any license concept to lend it — `License\Database` is Pro's own now too.

## Working here

- Code must stay PHP 7.4-compatible (`Requires PHP: 7.4`).
- Third-party versions (WPML, Elementor, Action Scheduler, object caching, PHP) differ between sites, so a green run on one site does not prove another: verify anything touching WPML, queue concurrency, caching or PHP-version behaviour on a site that runs those versions. `wp rowsprout-support doctor --only=environment` reports them.
- **Language**: code, comments, docs and UI msgids are English; text domains are `rowsprout` (free) and `rowsprout-pro` (Pro). There are no `nl` .po/.mo files yet.
- Comments explain *why* (constraints, incidents), not what. Some files may have CRLF line endings; normalise before multi-line edits.
- **Release zip**: `php bin/build-zip.php` → `build/rowsprout-<version>.zip` (working tree minus `.distignore`; refuses to build when the header version, `ROWSPROUT_VERSION` and readme's `Stable tag` disagree, warns on a dirty tree). Regenerate `languages/rowsprout.pot` with `wp i18n make-pot . languages/rowsprout.pot --domain=rowsprout --exclude=vendor,node_modules` when strings change.
- `AGENTS.md`, `CLAUDE.md` and the `rowsprout-support` development plugin must never end up in a release package.

## When you add, change or remove a feature, also check

1. `README.md` of both plugins.
2. The feature list in `Admin/UpgradeMenu.php` (customer-facing) and the licence comparison table in Pro's own `Admin/LicenseMenu.php` (customer-facing tier rows).
3. Pro's MCP text: `Features/Mcp/JsonRpc/ToolRegistry.php` descriptions and `AbilityRegistrar::CATEGORY_DESCRIPTION`; the docblocks in `Cli/Commands.php` (they become `wp help` text); the help tab (`Admin/Overview/HelpTab.php`).
4. Hooks and helpers you orphan: remove them (the last cleanup did exactly this) or say so in the hook table, and fix docblocks that name the removed feature.
5. Leftovers on existing sites: scheduled Action Scheduler actions, options, postmeta.
6. Tests in `rowsprout-support`: extend them and run them.
7. Both editors: the classic metabox and the Elementor modal render through the same `TemplateTabsRenderer`.
8. `.min` assets (invariant 7) and, for UI, an actual look in a browser — jsdom only proves the JS logic.

## Known leftovers (harmless; drop when convenient)

- The `waiting` status is accepted but never set (see the status table).
- Diagnosing a site: `wp rowsprout-support doctor` (read-only, from the development plugin). Run it before and after any deploy or queue-related change.
