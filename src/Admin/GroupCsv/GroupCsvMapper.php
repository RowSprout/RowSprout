<?php

namespace RowSprout\Admin\GroupCsv;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The column choices the import wizard suggests (assets/js/group-csv.js:
 * rememberedTarget(), suggestTarget(), buildMapping()), for an import
 * without the wizard such as WP-CLI or MCP: per column what the last import
 * put that column name into (GroupCsvSection::MAPPING_META) while that
 * target still exists and is free, else the ID column by its header, else a
 * property by its label, code or key; an unknown column is not imported.
 * Keep it in step with the wizard.
 */
final class GroupCsvMapper {

	/**
	 * One target per column: '' (not imported), 'id' or 'prop:<key>'.
	 *
	 * @param array<int, string>|null $header The file's header row (null: none).
	 * @return array<int, string>
	 */
	public static function suggest( int $templateId, ?array $header, int $width ): array {
		$mapping = array_fill( 0, max( 0, $width ), '' );
		if ( $header === null ) {
			return $mapping;
		}

		$columns    = GroupCsvTemplate::load( $templateId )->columns;
		$remembered = [];
		foreach ( GroupCsvSection::rememberedMapping( $templateId ) as $name => $target ) {
			$remembered[ self::normalize( (string) $name ) ] = $target;
		}

		$used = [];
		for ( $index = 0; $index < $width; $index++ ) {
			$name   = self::normalize( (string) ( $header[ $index ] ?? '' ) );
			$target = self::remembered( $name, $remembered, $columns, $used );
			if ( $target === null ) {
				$target = self::byName( $name, $columns, $used );
			}
			if ( $target !== '' ) {
				$used[ $target ] = true;
			}
			$mapping[ $index ] = $target;
		}

		return $mapping;
	}

	/**
	 * A column name as the wizard compares it: whitespace collapsed, lowercase.
	 */
	public static function normalize( string $name ): string {
		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $name ) ) );
	}

	/**
	 * @param array<string, string>            $remembered
	 * @param array<int, array<string, mixed>> $columns
	 * @param array<string, bool>              $used
	 */
	private static function remembered( string $name, array $remembered, array $columns, array $used ): ?string {
		if ( $name === '' || ! array_key_exists( $name, $remembered ) ) {
			return null;
		}
		$target = $remembered[ $name ];
		if ( $target === '' ) {
			return '';
		}
		if ( isset( $used[ $target ] ) ) {
			return null;
		}
		if ( $target === GroupCsvImporter::TARGET_ID ) {
			return $target;
		}
		foreach ( $columns as $column ) {
			if ( GroupCsvImporter::TARGET_PROPERTY . $column['key'] === $target ) {
				return $target;
			}
		}

		return null;
	}

	/**
	 * @param array<int, array<string, mixed>> $columns
	 * @param array<string, bool>              $used
	 */
	private static function byName( string $name, array $columns, array $used ): string {
		if ( $name === '' ) {
			return '';
		}
		if ( $name === self::normalize( GroupCsvExporter::ID_HEADER ) && ! isset( $used[ GroupCsvImporter::TARGET_ID ] ) ) {
			return GroupCsvImporter::TARGET_ID;
		}
		foreach ( $columns as $column ) {
			$target = GroupCsvImporter::TARGET_PROPERTY . $column['key'];
			$names  = [ self::normalize( (string) $column['label'] ), (string) $column['code'], (string) $column['key'], '@code_' . $column['code'] ];
			if ( in_array( $name, $names, true ) && ! isset( $used[ $target ] ) ) {
				return $target;
			}
		}

		return '';
	}
}
