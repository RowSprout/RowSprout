=== RowSprout – Landing Pages at Scale ===
Contributors: rowsprout
Tags: landing pages, programmatic seo, page generator, bulk pages, elementor
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 3.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Design one page, fill a table with your data, and RowSprout creates a real WordPress page for every row. Landing pages at scale, no code.

== Description ==

Many sites need dozens or hundreds of pages that share one layout but differ in the details: a page per service in every city you work in, per product variant, per location, per specialism. Building and maintaining those by hand is slow, and duplicating pages means every later change has to be made again on each copy.

RowSprout turns that into one template and one table.

= An example =

A plumbing company works in 30 towns. With RowSprout it designs one "Plumber in [town]" page, adds a row for every town (the town's name, a short introduction, a phone number, a contact email address) and saves. RowSprout creates 30 pages, each with its own title, URL such as `/plumber-amsterdam/`, text and details. When the company changes the design or the opening hours later, it changes the template once and every page follows.

= How it works =

1. **Build a template.** Design the page once in the block editor, Elementor or WPBakery Page Builder, like any other page.
2. **Define its properties.** These are the fields every page will have: a title, a URL, a piece of text, a link, an email address and so on. Place them in your design with a widget, a block or a placeholder.
3. **Add your data.** Every row (a "group") is one page. Type the values in the table right below the editor.
4. **Save.** RowSprout generates the pages in the background. They are real WordPress pages with their own URL, title, content and featured image.

= Features =

* **Real pages, not virtual ones.** Every generated page is a normal WordPress post with its own permalink, so search engines, caching plugins and your theme treat it like any other page.
* **Your own URL pattern.** Build each page's address from its properties, for example `/plumber-amsterdam/`, `/plumber-utrecht/` and so on, under the site root or a prefix of your choice. RowSprout never generates two pages at the same URL, also across templates and, without a prefix, against your other pages and posts: such a page waits until you give it a free URL.
* **Every language and alphabet.** Accents, Greek, Cyrillic, Arabic, Hebrew, Chinese, Japanese and emoji are fully supported in your rows, titles, content and URLs. Addresses follow WordPress's own rules: *Zürich* becomes `/plumber-zurich/`, while other alphabets stay as they are (`/plumber-αθήνα/`).
* **Pages stay in sync.** Change the design or a row, save, and every page of the template is regenerated from it, so no page is ever out of date.
* **Status at a glance.** The Templates screen shows per template how many of its pages are up to date.
* **Background generation.** Pages are created in a queue (Action Scheduler), so saving a template with hundreds of rows never slows down your admin.
* **Placeholders anywhere.** Use a property in the page title, the URL, the content or a link, and it is replaced per page.
* **Field types:** Title, URL slug, Text, Textarea, URL, Email and Number.
* **Import and export.** Download templates with their properties, rows and design as a file and add them to another site, for example from a staging site to the live one.
* **Grid of pages.** Show all pages of a template as a list or as cards on any page, for example an overview of every town you serve.
* **Child templates.** Give a template a parent template and it reuses the parent's rows: its pages inherit the title, URL and fields of the matching row, for example a "Roof repair in [town]" page for every town that has a "Roofer in [town]" page. Templates nest one level deep.
* **As many templates as you need**, managed from the Templates screen.

= Works with the editor you already use =

* **Block editor:** bind a Paragraph, Heading, List item, Button or Image block to a property with Block Bindings, insert placeholders from the text toolbar, and use the Grid block.
* **Elementor:** Heading, Textarea, Button and Grid widgets, an "Insert RowSprout property" button in the Text Editor, and a "RowSprout template" tab to manage properties and rows without leaving Elementor.
* **WPBakery Page Builder** (including builds bundled with themes): a Grid element and the same insert button in its text editors.

= Integrations =

* **Rank Math:** generated pages get the template's SEO, social and robots settings with each page's own values; the template itself is kept out of the sitemap and set to noindex.
* **Yoast SEO, SEOPress and The SEO Framework:** generated pages get the template's SEO title and meta description, and the other settings these plugins store with a page, with each page's own values; templates are kept out of their sitemaps and set to noindex.
* **Without an SEO plugin**, templates are kept out of WordPress' own sitemap and set to noindex as well.

= RowSprout Pro =

[RowSprout Pro](https://rowsprout.com) is a separate add-on, sold outside WordPress.org, for sites that grow beyond a single set of pages. It adds:

* **Smart Generate:** a save only regenerates the pages affected by what you changed, instead of every page of the template.
* **Planning:** choose per row when its page is published.
* **WPML:** translate a template once and all of its pages are generated in that language.
* **Extra field types:** Checkbox, Date, Date and time, Item list, Icon, Thumbnail, Select, Rich text, Color, Phone and File.
* **Extra Elementor elements:** Date, Item List, Thumbnail and Sibling Links widgets, plus RowSprout Page Field and Page Link dynamic tags.
* **Extra block editor element:** an Item List block.
* **Extra WPBakery element:** an Item List element.
* **Status overview** on the Templates screen: a live-updating status bar per template, a per-row overview of every page, and generating all pages of a template in one click.
* **Throttling** of batch size and processing hours on busy sites.
* **WP-CLI and MCP commands** to manage templates and pages from scripts or AI agents.

= Privacy =

The free plugin does not connect to any external service, does not track you and does not load anything from outside your site.

= Development =

RowSprout is open source. The code, releases and issue tracker are on GitHub: [github.com/RowSprout/RowSprout](https://github.com/RowSprout/RowSprout).

== Installation ==

1. In your WordPress admin go to Plugins → Add Plugin, search for "RowSprout" and click Install, then Activate. Or upload the `rowsprout` folder to `/wp-content/plugins/` and activate it on the Plugins screen.
2. Open the new **RowSprout** menu and create your first template.
3. Design the page, add your properties on the Properties tab and your rows on the Groups tab.
4. Save with **Create & update pages**. Your pages usually appear within a minute or two.

Optional: under RowSprout → Settings choose whether generated pages live at the site root or under a prefix such as `/locations/`.

== Frequently Asked Questions ==

= Do I need Elementor? =

No. RowSprout works with the WordPress block editor, Elementor and WPBakery Page Builder. Use whichever you already build your site with.

= Are the generated pages good for SEO? =

They are ordinary WordPress pages with their own URL, title, content and featured image, so SEO plugins, sitemaps and caching treat them like any page you made by hand. Make sure every row has genuinely useful, distinct content: search engines value pages that answer a real question, not near-identical copies.

= Does RowSprout work in my language? =

Yes, in every language and alphabet. Accents, Greek, Cyrillic, Arabic, Hebrew, Chinese, Japanese and emoji arrive on your pages exactly as you type them. In URLs, accents are simplified the way WordPress does it (with German and Danish spelling on sites in those languages), and letters of other alphabets stay as they are. More in the documentation: [Languages and special characters](https://docs.rowsprout.com/getting-started/languages/).

= What happens when I change the template? =

Save with "Create & update pages" and every page of the template is regenerated with your changes. "Save template only" stores your changes without touching the pages yet; they are marked as outdated until you generate them. (RowSprout Pro's Smart Generate only regenerates the pages affected by what you changed.)

= Can I edit a generated page directly? =

Generated pages are locked by default, because they are overwritten the next time their template generates them: change the template for everything or the row for one page. If you do want to edit generated pages by hand, unlock them under RowSprout → Settings, and keep in mind that the next regeneration replaces those edits.

= What happens when I remove a row or the template? =

Removing a row removes its page. Moving a template to the trash moves its pages to the trash too; restoring the template restores them.

= Does RowSprout slow down my site? =

No. Pages are generated in the background, and visitors get normal WordPress pages. Nothing is built on the fly when someone opens a page.

= Does this plugin connect to external services? =

No. The free plugin works entirely on your own site. RowSprout Pro, a separate plugin, contacts RowSprout's license server to activate a license.

== Screenshots ==

1. Every group becomes one page. Fill in its values right below the editor.
2. Properties are the fields every page has, each with its own placeholder token.
3. Design the template once in the block editor and place the tokens where the values belong.
4. The Templates list shows how many pages each template has and whether they are up to date.
5. A generated page: a real WordPress page with its own URL, title and content.

== Changelog ==

= 3.2 =
* New: RowSprout → Import / Export. Download templates (with their properties, groups and page-builder layout) as a file and import them on another site as drafts. The Templates list gets an Export action too.
* New: sample data. One click on the Import / Export page adds two example templates (city breaks and food tours in European cities) to try RowSprout with.
* New: a page whose URL is already in use on the site is not generated: by another template's page or, without a URL prefix, by a regular page, post or another plugin's content. The template is still saved and its other pages are generated; a notice lists the URLs and what has them. Before, one of the two pages could no longer be reached, without any warning.
* Change: a child template whose parent has no pages yet is now saved; its pages are generated once the parent has its own. Before, the save was refused.
* Change: a template without groups now shows "No groups" as its status, instead of "No pages generated yet".
* Fix: generated pages now use the theme's page template instead of its blog-post template, so block themes such as Twenty Twenty-Five no longer show an empty "Written by … in …" line above them. A template you chose yourself still wins.
* Fix: a bulk action (Move to Trash, Restore, Delete Permanently, Empty Trash, or Undo after a bulk trash) on a parent template selected together with its child templates no longer ends in an error.

= 3.1 =
* Initial public release.

== Upgrade Notice ==

= 3.2 =
Import and export templates between sites, sample data to try RowSprout with, no more pages at a URL that is already in use, and fixes for block themes and bulk actions.
