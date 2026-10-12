<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * A template's stored groups as a table: an ID column, then Title, Href and
 * the other properties (GroupCsvTemplate), headed with their labels; one
 * line per group (GroupCsvValue). GroupCsvImporter reads the same columns
 * back, so a downloaded file can be edited and imported again.
 *
 * For a child template the rows are its parent's groups: the ID is the
 * parent group's (the same as in the parent's file), Title and Href are the
 * parent's, and the other cells are the child's own values (empty means
 * inherited).
 */
final class GroupCsvExporter {

	/**
	 * Header of the group id column. Not translated, so a file imports on a
	 * site in another language too.
	 */
	public const ID_HEADER = 'ID';

	/**
	 * @return array<int, array<int, string>> The header row, then one row per group.
	 */
	public static function rows( int $templateId ): array {
		$template = GroupCsvTemplate::load( $templateId );

		$header = [ self::ID_HEADER ];
		foreach ( $template->columns as $column ) {
			$header[] = (string) ( $column['label'] ?? $column['key'] );
		}

		$rows = [ $header ];
		foreach ( $template->rows as $row ) {
			$line = [ (string) $row['export_id'] ];
			foreach ( $template->columns as $column ) {
				if ( ! empty( $column['match_only'] ) ) {
					$line[] = (string) ( $column['type'] === 'title' ? $row['title'] : $row['href'] );
					continue;
				}
				$line[] = GroupCsvValue::toCell( $column, (string) ( $row['fields'][ $column['key'] ] ?? '' ) );
			}
			$rows[] = $line;
		}

		return $rows;
	}

	public static function fileName( \WP_Post $post, string $format ): string {
		$name = sanitize_title( $post->post_title !== '' ? $post->post_title : 'template-' . $post->ID );

		return sanitize_file_name( $name . '-groups-' . wp_date( 'Y-m-d' ) . '.' . GroupCsvFormat::get( $format )['extension'] );
	}

	/**
	 * @param array<string, mixed> $group
	 */
	public static function fieldValue( array $group, string $key ): string {
		$field = $group['fields'][ $key ] ?? null;
		$value = is_array( $field ) ? ( $field['value'] ?? '' ) : $field;

		return is_scalar( $value ) ? (string) $value : '';
	}
}
