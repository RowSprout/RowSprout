<?php

namespace RowSprout\Admin;

use RowSprout\Core\PostTypes;
use RowSprout\Core\Template\TemplateStatusResolver;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * "Status" column on the Templates list screen: the same colored dot and
 * label as the Home page card (TemplateStatusResolver::resolve()), plus how
 * many of the template's pages are up to date. Rendered once per page load.
 *
 * An add-on can render the cell itself through the
 * rowsprout_render_template_status_cell action (RowSprout Pro shows a
 * segmented bar that refreshes while pages are generating).
 */
final class TemplateStatusColumn {

	public const COLUMN_KEY = 'dp_status';

	public static function register(): void {
		add_filter( 'manage_edit-' . PostTypes::TEMPLATE . '_columns', [ self::class, 'addColumn' ] );
		add_action( 'manage_' . PostTypes::TEMPLATE . '_posts_custom_column', [ self::class, 'renderColumn' ], 10, 2 );
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public static function addColumn( array $columns ): array {
		$withStatus = [];
		foreach ( $columns as $key => $label ) {
			if ( $key === 'title' ) {
				$withStatus[ self::COLUMN_KEY ] = __( 'Status', 'rowsprout' );
			}
			$withStatus[ $key ] = $label;
		}

		return $withStatus;
	}

	public static function renderColumn( string $column, int $postId ): void {
		if ( $column !== self::COLUMN_KEY ) {
			return;
		}

		if ( has_action( 'rowsprout_render_template_status_cell' ) ) {
			/**
			 * Renders the Status cell instead of the default below.
			 *
			 * @param int $postId The template.
			 */
			do_action( 'rowsprout_render_template_status_cell', $postId );
			return;
		}

		$status    = TemplateStatusResolver::resolve( $postId );
		$breakdown = TemplateStatusResolver::resolveBreakdown( $postId );
		$total     = $breakdown['total'];
		$upToDate  = $breakdown['counts'][ TemplateStatusResolver::STATUS_UP_TO_DATE ] ?? 0;

		$details = [];
		foreach ( $breakdown['counts'] as $bucket => $count ) {
			if ( $count > 0 ) {
				$details[] = $count . ' ' . TemplateStatusResolver::getLabel( $bucket );
			}
		}

		echo '<span title="' . esc_attr( implode( ', ', $details ) ) . '">';
		echo '<span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:' . esc_attr( TemplateStatusResolver::getColor( $status ) ) . ';vertical-align:middle;margin-right:6px;"></span>';
		echo esc_html( TemplateStatusResolver::getLabel( $status ) );
		if ( $total > 0 ) {
			echo '<br><span style="font-size:12px;color:#50575e;">';
			if ( $upToDate === $total ) {
				/* translators: %d: number of pages of the template */
				echo esc_html( sprintf( _n( '%d page', '%d pages', $total, 'rowsprout' ), $total ) );
			} else {
				/* translators: 1: number of pages that are up to date, 2: total number of pages of the template */
				echo esc_html( sprintf( __( '%1$d of %2$d up to date', 'rowsprout' ), $upToDate, $total ) );
			}
			echo '</span>';
		}
		echo '</span>';
	}
}
