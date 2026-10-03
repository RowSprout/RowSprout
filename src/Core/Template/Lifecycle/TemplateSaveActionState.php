<?php

namespace RowSprout\Core\Template\Lifecycle;

use RowSprout\Core\PostMetaKeys;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a rowsprout_template's CURRENTLY stored save action allows
 * generation to actually run — the same "disable_schedule" registry
 * SavePost itself builds from (including any 3rd-party action added via the
 * rowsprout_template_save_actions filter, e.g. RowSprout Pro's
 * "Schedule page updates"), duplicated here in a small, dependency-free
 * form specifically so the parent→child cascade (TemplateSyncMarker) can
 * check it without depending back on SavePost — which already depends on
 * TemplateSyncMarker, so the reverse dependency would be circular.
 */
final class TemplateSaveActionState {

	/**
	 * A child explicitly left on "Save template only" must stay that way
	 * even when its parent's own save would otherwise promote it straight
	 * to 'pending'/'scheduled' — the child's own choice not to generate
	 * right now takes precedence over the parent's cascade. See
	 * TemplateSyncMarker::syncFromChanges().
	 */
	public static function isGenerating( int $templateId ): bool {
		return self::actionGenerates( (string) get_post_meta( $templateId, PostMetaKeys::SAVE_ACTION, true ) );
	}

	/**
	 * Whether saving with $action generates pages right away (as opposed to
	 * only saving the template and marking groups stale — "Save template
	 * only", and Pro's "Schedule page updates", whose timing is chosen per
	 * group on the Planning tab instead).
	 */
	public static function actionGenerates( string $action ): bool {
		$actions = self::registeredActions();

		if ( ! isset( $actions[ $action ] ) ) {
			// Same default SavePost::readTemplateSaveAction() falls back to
			// when the stored value is missing or unrecognized.
			return true;
		}

		return empty( $actions[ $action ]['disable_schedule'] );
	}

	/**
	 * @return array<string, array{disable_schedule: bool}>
	 */
	private static function registeredActions(): array {
		$fallback = [
			'update_pages'  => [ 'disable_schedule' => false ],
			'save_template' => [ 'disable_schedule' => true ],
		];

		$actions = apply_filters( 'rowsprout_template_save_actions', $fallback );
		if ( ! is_array( $actions ) ) {
			return $fallback;
		}

		$normalized = [];
		foreach ( $actions as $key => $config ) {
			$key = sanitize_key( (string) $key );
			if ( $key === '' || ! is_array( $config ) ) {
				continue;
			}
			$normalized[ $key ] = [ 'disable_schedule' => ! empty( $config['disable_schedule'] ) ];
		}

		if ( empty( $normalized['update_pages'] ) ) {
			$normalized['update_pages'] = [ 'disable_schedule' => false ];
		}

		return $normalized;
	}
}
