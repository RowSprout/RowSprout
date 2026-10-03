<?php

namespace RowSprout\Admin;

use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Warns on the rowsprout_page edit screen that manual edits here are not
 * permanent: the next time the owning template's groups are (re)processed,
 * this page's content is regenerated from the template and overwrites
 * whatever was changed directly on the page.
 */
final class PageEditNotice {

	public static function register(): void {
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function render(): void {
		if ( ! function_exists( 'get_current_screen' ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || PostTypes::PAGE !== $screen->id || 'post' !== $screen->base ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'This page is generated from a template. Changes made here will be overwritten the next time the template is saved and its pages are regenerated.', 'rowsprout' );
		echo '</p></div>';
	}
}
