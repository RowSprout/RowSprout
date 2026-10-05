<?php

namespace RowSprout\Core\Template;

use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The properties an editor may offer to insert as an inline
 * @code_<code>_<templateId>@ token — shared by the Elementor Text Editor
 * button (ThirdParty\Elementor\TextEditorTokenButton) and the block editor's
 * rich-text button (Admin\TemplateBlockEditor), so both list the same.
 *
 * Only the free plugin's own single-line types. Values may contain quotes and
 * backslashes; that is safe because tokens are replaced markup-aware
 * (Core\Page\MarkupTokenReplacer, and JSON meta decoded first). Textarea and
 * item-list are deliberately left out: an inline token
 * lands in HTML as-is and neither editor adds nl2br()/wpautop() at render, so
 * their line breaks would collapse to spaces. The Textarea / Item List
 * widgets and blocks render those correctly.
 */
final class InsertableTokenFields {

	/**
	 * @var array<int, string>
	 */
	public const TYPES = [
		'title',
		'href',
		'textfield',
		'url',
		'email',
		'number',
		'checkbox',
		'date',
		'datetime-local',
		'icon',
	];

	/**
	 * Same token format as the Properties tab (PropertyTableRenderer): the
	 * template's own post id, which TemplateDataReader::getCodeIds() uses as
	 * the code_id when the page is generated.
	 *
	 * @return array<int, array{label: string, type: string, typeLabel: string, token: string}>
	 */
	public static function forTemplate( int $templateId ): array {
		$fields      = [];
		$definitions = TemplateMeta::getFieldTypeDefinitions();

		foreach ( (array) ( TemplateMeta::get( $templateId )['field_types'] ?? [] ) as $fieldType ) {
			if ( ! is_array( $fieldType ) ) {
				continue;
			}

			$type = (string) ( $fieldType['type'] ?? '' );
			$code = sanitize_key( (string) ( $fieldType['code'] ?? '' ) );
			if ( $code === '' || ! in_array( $type, self::TYPES, true ) ) {
				continue;
			}

			$fields[] = [
				'label'     => (string) ( $fieldType['label'] ?? $code ),
				'type'      => $type,
				'typeLabel' => (string) ( $definitions[ $type ]['label'] ?? $type ),
				'token'     => '@code_' . $code . '_' . $templateId . '@',
			];
		}

		return $fields;
	}
}
