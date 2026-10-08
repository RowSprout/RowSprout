<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/RowSprout/.github/main/profile/rowsprout-logo-horizontaal-donker.svg">
    <img src="https://raw.githubusercontent.com/RowSprout/.github/main/profile/rowsprout-logo-horizontaal-licht.svg" alt="RowSprout" width="320">
  </picture>
</p>

Generate SEO landing pages at scale from reusable templates and structured data groups — e.g. one template that produces a page per service × city combination, each with its own title, copy, links and images.

## How it works

- **Templates** (`rowsprout_template`) are the master design: a page you build once in the WordPress block editor or a page builder (the Save action is a panel in the block editor's sidebar), plus a set of **properties** (the fields every generated page will have — a title, a URL/href, a text block, an image, a list, etc.).
- **Groups** are the data: one group = one row of values for every property, and one group = one generated page. Add ten groups to a template and save with "Create & update pages" and you get ten real, independent WordPress pages.
- **Generated pages** (`rowsprout_page`) are full native WordPress posts — their own permalink, SEO meta, featured image, and a copy of the template's design with each property's placeholder resolved to that group's value.
- **Placeholder tokens** (`@code_<property-code>_<template-id>@`) can be used directly in the template's title, content, or URL pattern and get replaced per group when a page is generated — the same mechanism widgets and Dynamic Tags below use under the hood.
- **Child templates**: a template can have a parent template (the "Parent" field), inheriting its parent's shared properties/groups (matched by group ID) so a page can pull in the same title/URL/fields as its parent's corresponding page. Empty fields are filled from the parent's group when the page is generated; the child's own value always wins. Templates nest one level deep (`SavePost::limitPostDepth()` drops a parent that is itself a child, or on a template that has children).

## Field types

Title and Href/Slug are required and unique per template (locked to one each). Beyond those: Textfield, Textarea, URL, Email and Number. RowSprout Pro adds Checkbox, Date, Date+time, Item-list, Icon, Thumbnail, Select, Rich text, Color, Phone and File (registered through the `rowsprout_field_types` filter).

## Admin UI

- The classic `rowsprout_template` edit screen has three tabs: **General** (title/href pattern), **Properties** (add/edit/reorder the fields every group will fill in), and **Groups** (the actual data rows — one per page to generate).
- The Publish box adds a **Save action**: *Save template only* (just save, don't touch existing pages) or *Create & update pages* (queue every page of the template for generation, new groups included). RowSprout Pro's Smart Generate regenerates only the pages affected by what changed.
- **RowSprout → Templates** is the normal WordPress list of templates (add, edit, trash); its **Status** column shows each template's generation status (colored dot, label, how many pages are up to date), and the **Home** page explains how the plugin works and lists the most recently changed templates. RowSprout Pro turns the Status column into a live-updating bar and adds a per-group status overview and one-click generation to that list.
- **RowSprout → Import / Export** downloads templates as a JSON file (post, properties, groups, save action, featured image URL and the rest of the post meta, such as page-builder data and SEO settings; a child template always comes with its parent; a parent comes on its own) and imports such a file as new **draft** templates, with every placeholder token rewritten to the new template ids. Generated pages and media are not included. The Templates list has an **Export** row action and bulk action. **Import sample data** on the same page imports `sample-data/rowsprout-sample-templates.json`: two example templates (city breaks and food tours in European cities, with accented names) as drafts. The list follows the admin language filter of a multilingual plugin. RowSprout Pro adds language versions (WPML), Thumbnail images, optional image download, updating templates that already exist on the site, and exporting a selection of properties and groups, through the transfer hooks.
- **RowSprout → Settings** lets you set the URL base generated pages live under (site root, or a custom prefix) and whether generated pages can be edited by hand.

## Block editor integration

- **Block bindings** (WordPress 6.5+; the field picker needs 6.9+): bind a core block's attribute — Paragraph/Heading/List item text, Button text and link, Image URL/alt, Post Date — to a property in the block's *Attributes* panel ("RowSprout property"). Resolved when the page renders, formatted per type: Textarea keeps its line breaks and Email becomes a `mailto:` link (RowSprout Pro formats its own types, e.g. Item-list one item per line and dates in the site's date format). An empty property keeps the block's own text as a fallback.
- **Blocks** (no "RowSprout" prefix — the inserter category says it): **Grid** in "RowSprout pages" (the generated pages of one template as a list or cards, any post type; same output as the Elementor Grid widget); RowSprout Pro adds an **Item List** block. No Textarea block: a Paragraph bound to a Textarea property keeps its line breaks.
- **"Insert RowSprout property"** in the rich-text toolbar of text blocks: inserts a property's placeholder token inline (same list and link options as the Elementor Text Editor button).
- The **Save action** is a sidebar panel and appears in the publish panel instead of Visibility/Publish date; saving is paused while a field in the RowSprout template box is invalid (e.g. a duplicate URL).

## Background page generation

Saving a template's groups queues rows in a dedicated database table (pending → scheduled/in-process → completed/failed/stale). A recurring background task (Action Scheduler, every minute) works through the queue and generates/updates each page asynchronously, so saving a template with hundreds of groups never blocks the admin UI.

## Third-party integrations

### Elementor

- **Widgets** in the "RowSprout" group: Textarea (a chosen text-block field), Heading (a chosen text field wrapped in an h1–h6) and Button (a native-looking Elementor button whose link comes from a URL or Email property — `mailto:` — with optional label from a Text field property; hidden on a page whose link property is empty). In the "RowSprout pages" group: Grid (every generated page of one template in a card/list layout).
- **Text Editor toolbar button**: while editing a template, Elementor's Text Editor widget gets an "Insert RowSprout property" button that inserts a property's placeholder token at the cursor, inline with the surrounding text. Every single-line property type is listed (not Textarea or Item-list — their line breaks would be lost in the Text Editor; use the Textarea widget, or Pro's Item List widget, for those); email and URL properties can also be inserted as a (`mailto:`) link. The token is resolved when the page is generated, like any hand-typed token.
- The Elementor editor gets its own **"RowSprout template" tab**, reusing the same Properties/Groups UI as the classic screen without leaving the page builder. Elementor's General Settings panel also gains a **Preview group** picker with a "Refresh preview" button, letting you choose which group's data the widgets/tags preview while designing — purely an editor convenience with zero effect on real generated pages.

### WPBakery Page Builder

Also works with builds bundled with themes. A template built with WPBakery opens in the classic editor with the RowSprout box, so saving works as on the classic screen.

- **Enabled for templates** unless WPBakery's Role Manager has its own post-type list; then a notice on the template screen links to the Role Manager.
- **Tokens in element settings** (headings, button texts, links): replaced and stored the way WPBakery itself writes them (`"` as ` `` `, link fields url-encoded), so values with quotes or brackets don't break the page.
- **"Insert RowSprout property"** in WPBakery's text editors and the classic editor (same button as in Elementor's Text Editor).
- **Element** "Grid" (category *RowSprout pages*), same output as the block and the Elementor widget.

### Other plugins

- **RankMath**: excludes templates (but not generated pages) from the XML sitemap, forces noindex on templates, and copies SEO/social/robots meta fields to each generated page (their robots setting comes from the template, not from RowSprout).
- **WP Rocket**: hides the "Purge Cache" row action on templates, which are never served/cached directly.
- **WPML**: the free plugin exposes the hook points WPML/Pro need to detect and cascade translations; full multi-language support (translate a template once and all of its pages are generated in that language, with per-group field translation and linked translations) is a Pro feature.

## Free plan and RowSprout Pro

This plugin is fully functional and free, with no usage limits and no license involved. RowSprout Pro is a separate add-on, sold outside WordPress.org at [rowsprout.com](https://rowsprout.com), that adds Smart Generate (change detection), a live template status overview with one-click generation, extra field types, extra Elementor widgets and dynamic tags, WPML support, per-group planning, and an MCP server for AI-agent-driven management.

## Installation

1. Upload the `rowsprout` folder to `/wp-content/plugins/`.
2. Activate "RowSprout" through the Plugins menu.
3. Create your first template, add properties and groups, and save with "Create & update pages".

## Support

For support, contact RowSprout at [rowsprout.com](https://rowsprout.com).
