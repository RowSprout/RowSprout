<?php

namespace RowSprout\Admin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Purely informational "what does RowSprout Pro add" page — no license
 * activation, no external request, no gating of anything in this plugin.
 * Replaces the old License menu: the free plugin has no license concept
 * and no usage limits at all. RowSprout Pro removes this menu item once it
 * is active.
 */
final class UpgradeMenu {

	private const PRO_URL = 'https://rowsprout.com';

	public static function renderPage(): void {
		$sections = [
			[
				'title' => __( 'Working with many pages', 'rowsprout' ),
				'items' => [
					__( 'Smart Generate: a save only regenerates the pages affected by what you changed.', 'rowsprout' ),
					__( 'A live-updating status bar on the Templates screen, a per-group status overview, and generating all pages of a template in one click.', 'rowsprout' ),
					__( 'Per-group planning: choose when each page is generated.', 'rowsprout' ),
					__( 'Throttling: limit the batch size and the hours in which pages are processed.', 'rowsprout' ),
				],
			],
			[
				'title' => __( 'Multiple languages', 'rowsprout' ),
				'items' => [
					__( 'WPML: translate a template once and all of its pages are generated in that language.', 'rowsprout' ),
					__( 'Import and export keep a template\'s language versions together and link them again on the other site.', 'rowsprout' ),
				],
			],
			[
				'title' => __( 'More field types and elements', 'rowsprout' ),
				'items' => [
					__( 'Extra field types: Checkbox, Date, Date and time, Item list, Icon, Phone, Rich text, Thumbnail, Select, Color and File.', 'rowsprout' ),
					__( 'Elementor: Date, Item List, Thumbnail and Sibling Links widgets, plus RowSprout Page Field and Page Link dynamic tags.', 'rowsprout' ),
					__( 'Block editor: an Item List block.', 'rowsprout' ),
					__( 'WPBakery: an Item List element.', 'rowsprout' ),
				],
			],
			[
				'title' => __( 'Automation', 'rowsprout' ),
				'items' => [
					__( 'An MCP server and WP-CLI commands to manage templates and pages from scripts or AI agents.', 'rowsprout' ),
					__( 'Import that updates templates already on the site (for example from staging to live) and downloads missing images.', 'rowsprout' ),
					__( 'Export only the properties and groups you choose.', 'rowsprout' ),
				],
			],
		];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'RowSprout Pro', 'rowsprout' ); ?></h1>

			<div class="card" style="max-width: 720px;">
				<p>
					<?php esc_html_e( 'RowSprout works fully on its own: there are no limits on templates, groups or pages, and nothing in this plugin requires Pro.', 'rowsprout' ); ?>
				</p>
				<p>
					<?php esc_html_e( 'RowSprout Pro is a separate add-on, not distributed through WordPress.org and adds:', 'rowsprout' ); ?>
				</p>

				<?php foreach ( $sections as $section ) : ?>
					<h2 class="title"><?php echo esc_html( $section['title'] ); ?></h2>
					<ul style="list-style: disc; margin-left: 20px;">
						<?php foreach ( $section['items'] as $item ) : ?>
							<li><?php echo esc_html( $item ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endforeach; ?>

				<p>
					<a class="button button-primary" href="<?php echo esc_url( self::PRO_URL ); ?>" target="_blank" rel="noopener noreferrer">
						<?php esc_html_e( 'Learn more on rowsprout.com', 'rowsprout' ); ?>
						<span class="screen-reader-text"><?php esc_html_e( '(opens in a new tab)', 'rowsprout' ); ?></span>
					</a>
				</p>
			</div>
		</div>
		<?php
	}
}
