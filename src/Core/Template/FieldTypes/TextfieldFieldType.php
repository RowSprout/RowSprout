<?php

namespace RowSprout\Core\Template\FieldTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TextfieldFieldType extends BaseFieldType {

	public function getType(): string {
		return 'textfield';
	}

	protected function label(): string {
		return __( 'Text field', 'rowsprout' );
	}

	protected function description(): string {
		return __( 'Suitable for short free text or a single word.', 'rowsprout' );
	}

	protected function defaultCode(): string {
		return 'text_field';
	}

	public function getUiKind(): string {
		return 'text';
	}
}
