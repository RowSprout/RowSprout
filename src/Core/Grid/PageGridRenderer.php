<?php

namespace RowSprout\Core\Grid;

use RowSprout\Core\PostTypes;
use RowSprout\Infrastructure\Database\GroupsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The list of one template's generated pages, shared by the Elementor Grid
 * widget (ThirdParty\Elementor\Widgets\GridWidget) and the Grid block
 * (Blocks\PageGridBlock) so both show the same pages with the same markup.
 * Moved out of GridWidget unchanged; see the comments below for why the
 * query and the title handling are the way they are.
 *
 * Settings keep the Elementor control names: rowsprout_page (template id),
 * layout_type (list|cart), show_thumb (yes), thumb_size, show_title_type
 * (parent|child), sort_order (''|asc|desc).
 */
final class PageGridRenderer {

	/**
	 * @param array<string, mixed> $settings
	 * @param string               $gridStyle Optional inline style for the grid element (the block sets its columns here; Elementor uses its own selectors).
	 */
	public static function render( array $settings, string $gridStyle = '' ): string {
		$selectedId = absint( $settings['rowsprout_page'] ?? 0 );

		if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpml_current_language is WPML's own filter, not one this plugin defines.
			$currentLang          = apply_filters( 'wpml_current_language', null );
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- wpml_object_id is WPML's own filter, not one this plugin defines.
			$selectedIdTranslated = apply_filters( 'wpml_object_id', $selectedId, PostTypes::TEMPLATE, true, $currentLang );
			if ( $selectedIdTranslated ) {
				$selectedId = (int) $selectedIdTranslated;
			}
		}

		if ( ! $selectedId ) {
			return '<p>' . esc_html__( 'Select a page.', 'rowsprout' ) . '</p>';
		}

		$layoutType = $settings['layout_type'] ?? 'list';
		$thumbSize  = ! empty( $settings['thumb_size'] ) ? $settings['thumb_size'] : 'thumbnail';
		$showThumb  = ! empty( $settings['show_thumb'] ) ? $settings['show_thumb'] : 'no';

		$overview = self::getOverview( $selectedId, $settings );

		$html = '<div class="rowsprout-page-grid"' . ( $gridStyle !== '' ? ' style="' . esc_attr( $gridStyle ) . '"' : '' ) . '>';

		foreach ( $overview as $item ) {
			if ( ( $settings['show_title_type'] ?? 'parent' ) === 'parent' ) {
				if ( wp_get_post_parent_id( $item['rowsprout_page_id'] ) ) {
					$titleHtml = '<span class="rowsprout-page-title rowsprout-page-title-no-child">' . esc_html( $item['parent_title'] ) . '</span>';
				} else {
					$titleHtml = '<span class="rowsprout-page-title rowsprout-page-title-parent">' . esc_html( $item['title_page'] ) . '</span>';
				}
			} else {
				if ( wp_get_post_parent_id( $item['rowsprout_page_id'] ) ) {
					$titleHtml = '<span class="rowsprout-page-title rowsprout-page-title-child">' . esc_html( $item['title_page'] ) . '</span>';
				} else {
					$titleHtml = '<span class="rowsprout-page-title rowsprout-page-title-no-parent"></span>';
				}
			}

			$html .= '<div class="rowsprout-page-item">';
			$html .= '<div class="rowsprout-page-item-inner">';

			if ( $layoutType === 'cart' ) {
				$html .= '<a href="' . esc_url( $item['href'] ) . '" class="rowsprout-page-link">';

				if ( $showThumb === 'yes' ) {
					$html .= '<div class="rowsprout-page-thumb">';
					$html .= '<img class="thumbnail" src="' . esc_url( (string) wp_get_attachment_image_url( $item['thumb_id'], $thumbSize ) ) . '" alt="" />';
					$html .= '</div>';
				}
			}

			if ( $layoutType === 'list' ) {
				$html .= '<a href="' . esc_url( $item['href'] ) . '" class="rowsprout-page-link">';
				$html .= '<span class="rowsprout-page-title-wrapper">' . $titleHtml . '</span>';
				$html .= '</a>';
			} else {
				$html .= '<div class="rowsprout-page-title-wrapper">' . $titleHtml . '</div>';
				$html .= '</a>';
			}

			$html .= '</div>';
			$html .= '</div>';
		}

		$html .= '</div>';

		return $html;
	}

	/**
	 * @param array<string, mixed> $settings
	 * @return array<int, array<string, mixed>>
	 */
	private static function getOverview( int $selectedId, array $settings ): array {
		global $wpdb;
		$table = GroupsTable::name();

		// rowsprout_page_id > 0 is the actual "has a live, generated page"
		// signal — status alone isn't reliable: a group planned for a refresh
		// (Pro's Planning tab) has status 'scheduled', so requiring
		// status = 'completed' would incorrectly hide pages that are live
		// right now and simply awaiting their planned regeneration.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- $table is the plugin's own custom table name (GroupsTable::name()), never request input.
		$groupsRaw = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $table is the plugin's own custom table name (GroupsTable::name()), never request input.
				"SELECT * FROM {$table} WHERE rowsprout_template_id = %d AND rowsprout_page_id > 0",
				$selectedId
			),
			ARRAY_A
		);

		$overview = [];

		foreach ( (array) $groupsRaw as $group ) {
			$rowsproutPageId = (int) $group['rowsprout_page_id'];
			$name        = esc_html( get_the_title( $rowsproutPageId ) );
			$href        = get_permalink( $rowsproutPageId );
			$thumbId     = get_post_thumbnail_id( $rowsproutPageId );
			$parentId    = wp_get_post_parent_id( $rowsproutPageId );
			$parentTitle = $parentId ? get_the_title( $parentId ) : $name;

			$overview[] = [
				'parent_title'  => $parentTitle ?: '',
				'title_page'    => $name,
				'href'          => $href ?: '#',
				'thumb_id'      => $thumbId ?: '',
				'rowsprout_page_id' => $rowsproutPageId,
			];
		}

		return self::sortOverview( $overview, $settings );
	}

	/**
	 * @param array<int, array<string, mixed>> $overview
	 * @param array<string, mixed>             $settings
	 * @return array<int, array<string, mixed>>
	 */
	private static function sortOverview( array $overview, array $settings ): array {
		$sortOrder     = ! empty( $settings['sort_order'] ) ? $settings['sort_order'] : '';
		$showTitleType = ! empty( $settings['show_title_type'] ) ? $settings['show_title_type'] : 'parent';

		if ( $sortOrder !== 'asc' && $sortOrder !== 'desc' ) {
			return $overview;
		}

		// The site language's alphabetical order (Ärzte next to apotheek, not
		// after zahnarzt); without the intl extension, compare case- and
		// accent-insensitively. remove_accents() leaves other scripts alone
		// and strcasecmp() only folds ASCII, hence mb_strtolower() (Αθήνα
		// next to αθήνα).
		$collator = class_exists( '\Collator' ) ? new \Collator( get_locale() ) : null;
		$fold     = static function ( string $text ): string {
			$text = remove_accents( $text );
			return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		};
		$compare  = static function ( string $x, string $y ) use ( $collator, $fold ): int {
			if ( $collator instanceof \Collator ) {
				$result = $collator->compare( $x, $y );
				if ( is_int( $result ) ) {
					return $result;
				}
			}
			return strcmp( $fold( $x ), $fold( $y ) );
		};

		usort( $overview, static function ( $a, $b ) use ( $sortOrder, $showTitleType, $compare ) {
			$field  = $showTitleType === 'parent' ? 'parent_title' : 'title_page';
			$result = $compare( (string) ( $a[ $field ] ?? '' ), (string) ( $b[ $field ] ?? '' ) );

			return $sortOrder === 'asc' ? $result : -$result;
		} );

		return $overview;
	}
}
