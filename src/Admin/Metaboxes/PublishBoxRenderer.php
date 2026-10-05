<?php

namespace RowSprout\Admin\Metaboxes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PublishBoxRenderer {

	public static function renderSmallAdjustments(): void {
		global $post;

		$saveActions = self::getTemplateSaveActions();

		$postId = $post instanceof \WP_Post ? (int) $post->ID : 0;
		$selectedSaveAction = self::storedValue( '_rowsprout_page_save_action', $postId, 'update_pages' );
		if ( ! isset( $saveActions[ $selectedSaveAction ] ) ) {
			$selectedSaveAction = 'update_pages';
		}

		echo '<div class="misc-pub-section">';
		echo '<label for="rowsprout_page_save_action" style="display:block;margin-bottom:4px;">' . esc_html__( 'Save action', 'rowsprout' ) . '</label>';
		echo '<select id="rowsprout_page_save_action" name="rowsprout_page_save_action" style="width:100%;">';
		foreach ( $saveActions as $actionKey => $actionConfig ) {
			echo '<option value="' . esc_attr( $actionKey ) . '"' . selected( $selectedSaveAction, $actionKey, false ) . '>' . esc_html( $actionConfig['label'] ) . '</option>';
		}
		echo '</select>';
		echo '<p id="rowsprout_page_save_action_description" class="description" style="margin:8px 0 0;">' . esc_html( $saveActions[ $selectedSaveAction ]['description'] ) . '</p>';
		echo '<div id="rowsprout_page_save_action_options" style="margin-top:8px;">';
		foreach ( $saveActions as $actionKey => $actionConfig ) {
			echo '<div data-save-action-fields="' . esc_attr( $actionKey ) . '" style="display:' . ( $selectedSaveAction === $actionKey ? 'block' : 'none' ) . ';">';
			if ( is_callable( $actionConfig['render_fields_callback'] ) ) {
				call_user_func( $actionConfig['render_fields_callback'], $postId, $actionKey, $selectedSaveAction, $actionConfig );
			}
			do_action( 'rowsprout_render_template_save_action_fields', $postId, $actionKey, $selectedSaveAction, $actionConfig );
			echo '</div>';
		}
		echo '</div>';
		echo '</div>';

		wp_register_script( 'rowsprout-publish-box', false, [], ROWSPROUT_VERSION, true );
		wp_enqueue_script( 'rowsprout-publish-box' );
		wp_add_inline_script( 'rowsprout-publish-box', '(function(){'
			. 'var saveAction=document.getElementById("rowsprout_page_save_action");'
			. 'var saveActionDescription=document.getElementById("rowsprout_page_save_action_description");'
			. 'var saveActionFields=document.querySelectorAll("#rowsprout_page_save_action_options [data-save-action-fields]");'
			. 'if(!saveAction){return;}'
			. 'var saveActions=' . wp_json_encode( self::buildSaveActionsJsConfig( $saveActions ) ) . ';'
			. 'function emitSaveActionChange(key,current){try{document.dispatchEvent(new CustomEvent("rowsproutSaveActionChange",{detail:{key:key,disableSchedule:!!current.disable_schedule}}));}catch(e){}}'
			. 'function applySaveAction(){var key=saveAction.value;var current=saveActions[key]||saveActions.update_pages||{description:"",disable_schedule:false};if(saveActionDescription){saveActionDescription.textContent=current.description||"";}for(var i=0;i<saveActionFields.length;i++){var item=saveActionFields[i];item.style.display=item.getAttribute("data-save-action-fields")===key?"block":"none";}emitSaveActionChange(key,current);}'
			. 'saveAction.addEventListener("change",applySaveAction);'
			. 'applySaveAction();'
			. '})();'
		);
	}

	/**
	 * @return array<string, array{label:string,description:string,disable_schedule:bool,render_fields_callback:callable|null}>
	 */
	public static function getTemplateSaveActions(): array {
		$fallback = self::getTemplateSaveActionsFallback();
		$actions = $fallback;

		$actions = apply_filters( 'rowsprout_template_save_actions', $actions );

		if ( ! is_array( $actions ) ) {
			return $fallback;
		}

		$normalized = [];
		foreach ( $actions as $actionKey => $actionConfig ) {
			$key = sanitize_key( (string) $actionKey );
			if ( $key === '' || ! is_array( $actionConfig ) ) {
				continue;
			}

			$label = isset( $actionConfig['label'] ) ? (string) $actionConfig['label'] : '';
			$description = isset( $actionConfig['description'] ) ? (string) $actionConfig['description'] : '';
			if ( $label === '' ) {
				continue;
			}

			$normalized[ $key ] = [
				'label'                  => $label,
				'description'            => $description,
				'disable_schedule'       => ! empty( $actionConfig['disable_schedule'] ),
				'render_fields_callback' => ( isset( $actionConfig['render_fields_callback'] ) && is_callable( $actionConfig['render_fields_callback'] ) )
					? $actionConfig['render_fields_callback']
					: null,
			];
		}

		if ( empty( $normalized['update_pages'] ) ) {
			$normalized['update_pages'] = $fallback['update_pages'];
		}

		return $normalized;
	}

	/**
	 * @return array<string, array{label:string,description:string,disable_schedule:bool,render_fields_callback:callable|null}>
	 */
	private static function getTemplateSaveActionsFallback(): array {
		return [
			'save_template' => [
				'label'                  => __( 'Save template only', 'rowsprout' ),
				'description'            => __( 'Only the template is saved. Existing pages are not updated immediately.', 'rowsprout' ),
				'disable_schedule'       => true,
				'render_fields_callback' => null,
			],
			'update_pages' => [
				'label'                  => __( 'Create & update pages', 'rowsprout' ),
				'description'            => __( 'Pages are created for new groups and every existing page is regenerated immediately.', 'rowsprout' ),
				'disable_schedule'       => false,
				'render_fields_callback' => null,
			],
		];
	}

	/**
	 * @param array<string, array{label:string,description:string,disable_schedule:bool,render_fields_callback:callable|null}> $actions
	 * @return array<string, array{description:string,disable_schedule:bool}>
	 */
	private static function buildSaveActionsJsConfig( array $actions ): array {
		$config = [];
		foreach ( $actions as $actionKey => $actionConfig ) {
			$config[ $actionKey ] = [
				'description'      => $actionConfig['description'],
				'disable_schedule' => $actionConfig['disable_schedule'],
			];
		}

		return $config;
	}

	private static function storedValue( string $metaKey, int $postId, string $default ): string {
		if ( $postId <= 0 ) {
			return $default;
		}

		$stored = get_post_meta( $postId, $metaKey, true );
		if ( is_scalar( $stored ) && (string) $stored !== '' ) {
			return (string) $stored;
		}

		return $default;
	}

}
