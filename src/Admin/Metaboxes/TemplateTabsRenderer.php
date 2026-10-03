<?php

namespace RowSprout\Admin\Metaboxes;

use RowSprout\Core\SavePost;
use RowSprout\Core\TemplateMeta;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateTabsRenderer {

	/**
	 * Tabs an add-on (e.g. RowSprout Pro) contributes via the
	 * rowsprout_template_tabs filter, normalized and validated so a
	 * malformed entry is skipped instead of breaking the whole editor.
	 *
	 * @return array<int, array{id:string,label:string,render:callable,hidden:bool}>
	 */
	private static function collectExtraTabs( \WP_Post $post ): array {
		/**
		 * Filters the extra tabs shown after the built-in General / Properties
		 * / Groups tabs — in both the classic-editor metabox and the Elementor
		 * "RowSprout template" modal, which render this same markup.
		 *
		 * Each entry: [ 'id' => 'dp-tab-…', 'label' => 'Shown on the tab',
		 * 'render' => callable( \WP_Post $post ): void, 'hidden' => bool
		 * (optional: start with the tab button hidden — the add-on's own
		 * script can reveal it later) ].
		 *
		 * @param array<int, array<string, mixed>> $tabs
		 * @param \WP_Post                         $post
		 */
		$tabs = apply_filters( 'rowsprout_template_tabs', [], $post );
		if ( ! is_array( $tabs ) ) {
			return [];
		}

		$normalized = [];
		$seenIds    = [ 'dp-tab-general' => true, 'dp-tab-properties' => true, 'dp-tab-groups' => true ];

		foreach ( $tabs as $tab ) {
			if ( ! is_array( $tab ) || ! isset( $tab['id'], $tab['label'], $tab['render'] ) || ! is_callable( $tab['render'] ) ) {
				continue;
			}

			$id = sanitize_html_class( (string) $tab['id'] );
			if ( $id === '' || isset( $seenIds[ $id ] ) ) {
				continue;
			}

			$seenIds[ $id ] = true;
			$normalized[]   = [
				'id'     => $id,
				'label'  => (string) $tab['label'],
				'render' => $tab['render'],
				'hidden' => ! empty( $tab['hidden'] ),
			];
		}

		return $normalized;
	}

	public static function render( \WP_Post $post ): void {
		// Read at render time (i.e. whenever this classic-editor screen or
		// Elementor modal loads), submitted back unchanged on save — see
		// SavePost::handleSave()'s version check for what a mismatch means.
		$configVersion = (string) ( TemplateMeta::get( $post->ID )['config_updated_at'] ?? '' );
		$extraTabs     = self::collectExtraTabs( $post );
		?>
		<input type="hidden" name="dp_config_version" value="<?php echo esc_attr( $configVersion ); ?>" />
		<?php
		SavePost::renderNonceField();
		// A dedicated, freshly-registered handle -- not wp_add_inline_style()
		// on WordPress core's own 'wp-admin' handle: that handle's own
		// stylesheet is already printed (in <head>) long before a metabox
		// callback like this one runs, so inline CSS attached to it here
		// never gets output at all. Same pattern as the script registration
		// below (which does work, since scripts print in the footer).
		wp_register_style( 'rowsprout-template-tabs', false, [], ROWSPROUT_VERSION );
		wp_enqueue_style( 'rowsprout-template-tabs' );
		wp_add_inline_style( 'rowsprout-template-tabs', '
			.dp-template-tabs {
				display: flex;
				gap: 16px;
				align-items: flex-start;
			}
			.dp-template-tabs__nav {
				width: 180px;
				min-width: 180px;
				border-right: 1px solid #dcdcde;
				padding-right: 12px;
			}
			.dp-template-tabs__nav button {
				display: block;
				width: 100%;
				text-align: left;
				padding: 8px 10px;
				border: 0;
				border-left: 3px solid transparent;
				background: transparent;
				cursor: pointer;
				font-weight: 600;
				color: #1d2327;
			}
			.dp-template-tabs__nav button[hidden] {
				display: none;
			}
			.dp-template-tabs__nav button.is-active {
				border-left-color: #2271b1;
				background: #f0f6fc;
				color: #0a4b78;
			}
			.dp-template-tabs__content {
				flex: 1;
				min-width: 0;
			}
			.dp-template-tab-panel {
				display: none;
			}
			.dp-template-tab-panel.is-active {
				display: block;
			}
		' );
		?>
		<div class="dp-template-tabs" data-dp-template-tabs>
			<div class="dp-template-tabs__nav" role="tablist" aria-orientation="vertical">
				<button type="button" class="is-active" role="tab" aria-selected="true" data-tab-target="dp-tab-general"><?php esc_html_e( 'General', 'rowsprout' ); ?></button>
				<button type="button" role="tab" aria-selected="false" data-tab-target="dp-tab-properties"><?php esc_html_e( 'Properties', 'rowsprout' ); ?></button>
				<button type="button" role="tab" aria-selected="false" data-tab-target="dp-tab-groups"><?php esc_html_e( 'Groups', 'rowsprout' ); ?></button>
				<?php foreach ( $extraTabs as $extraTab ) : ?>
					<button type="button" role="tab" aria-selected="false" data-tab-target="<?php echo esc_attr( $extraTab['id'] ); ?>"<?php echo $extraTab['hidden'] ? ' hidden' : ''; ?>><?php echo esc_html( $extraTab['label'] ); ?></button>
				<?php endforeach; ?>
			</div>
			<div class="dp-template-tabs__content">
				<div id="dp-tab-general" class="dp-template-tab-panel is-active" role="tabpanel">
					<?php TitleHrefMetaBoxRenderer::render( $post ); ?>
				</div>
				<div id="dp-tab-properties" class="dp-template-tab-panel" role="tabpanel">
					<?php GroupsMetaBoxRenderer::renderPropertiesTab( $post ); ?>
				</div>
				<div id="dp-tab-groups" class="dp-template-tab-panel" role="tabpanel">
					<?php GroupsMetaBoxRenderer::renderGroupsTab( $post ); ?>
				</div>
				<?php foreach ( $extraTabs as $extraTab ) : ?>
					<div id="<?php echo esc_attr( $extraTab['id'] ); ?>" class="dp-template-tab-panel" role="tabpanel">
						<?php call_user_func( $extraTab['render'], $post ); ?>
					</div>
				<?php endforeach; ?>
			</div>
		</div>
		<?php
		GroupsMetaBoxRenderer::renderScripts( $post );

		wp_register_script( 'rowsprout-template-tabs', false, [ 'jquery' ], ROWSPROUT_VERSION, true );
		wp_enqueue_script( 'rowsprout-template-tabs' );
		wp_add_inline_script( 'rowsprout-template-tabs', "
			jQuery(function(\$) {
				var \$root = \$('[data-dp-template-tabs]');
				if (!\$root.length) {
					return;
				}

				\$root.on('click', 'button[data-tab-target]', function() {
					var targetId = String(\$(this).data('tab-target') || '');
					if (!targetId) {
						return;
					}

					\$root.find('.dp-template-tabs__nav button').removeClass('is-active').attr('aria-selected', 'false');
					\$(this).addClass('is-active').attr('aria-selected', 'true');

					\$root.find('.dp-template-tab-panel').removeClass('is-active');
					\$root.find('#' + targetId).addClass('is-active');
				});
			});
		" );
	}
}
