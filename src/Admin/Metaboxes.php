<?php

namespace RowSprout\Admin;

use RowSprout\Admin\Metaboxes\GroupsMetaBoxRenderer;
use RowSprout\Admin\Metaboxes\PublishBoxRenderer;
use RowSprout\Admin\Metaboxes\TitleHrefMetaBoxRenderer;
use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Metaboxes {

	public static function register(): void {
		add_action( 'add_meta_boxes', [ self::class, 'addMetaBoxes' ] );
		add_action( 'add_meta_boxes_' . PostTypes::TEMPLATE, [ self::class, 'removeTemplateFormatMetaBox' ], 100 );
		add_action( 'post_submitbox_misc_actions', [ self::class, 'renderSmallAdjustmentsInPublish' ] );
	}

	public static function removeTemplateFormatMetaBox(): void {
		remove_meta_box( 'formatdiv', PostTypes::TEMPLATE, 'normal' );
		remove_meta_box( 'formatdiv', PostTypes::TEMPLATE, 'side' );
	}

	public static function addMetaBoxes(): void {
		global $post;

		self::removeTemplateFormatMetaBox();

		add_meta_box(
			'rowsprout_page_template_box',
			__( 'RowSprout template', 'rowsprout' ),
			[ self::class, 'renderTemplateMetaBox' ],
			PostTypes::TEMPLATE,
			'normal',
			'default'
		);
	}

	public static function renderTemplateMetaBox( \WP_Post $post ): void {
		\RowSprout\Admin\Metaboxes\TemplateTabsRenderer::render( $post );
	}

	public static function renderGroupsMetaBox( \WP_Post $post ): void {
		GroupsMetaBoxRenderer::render( $post );
	}

	public static function renderTitleHrefMetaBox( \WP_Post $post ): void {
		TitleHrefMetaBoxRenderer::render( $post );
	}

	public static function renderSmallAdjustmentsInPublish(): void {
		global $post;

		if ( ! $post instanceof \WP_Post || $post->post_type !== PostTypes::TEMPLATE ) {
			return;
		}

		// Hides the core "Published on: [date]" row (WordPress's own
		// touch_time() output, rendered earlier in the same Publish box —
		// there's no action/filter to remove it outright, only CSS). A
		// template's own publish date has no bearing on anything this
		// plugin does with it, and editing it invites confusion with the
		// Save action/schedule controls right below it.
		//
		// A dedicated, freshly-registered handle -- not wp_add_inline_style()
		// on WordPress core's own 'wp-admin' handle: that handle's own
		// stylesheet is already printed (in <head>) long before
		// post_submitbox_misc_actions fires (mid-body, inside the Publish
		// metabox), so inline CSS attached to it here never gets output.
		wp_register_style( 'rowsprout-publish-box-adjustments', false, [], ROWSPROUT_VERSION );
		wp_enqueue_style( 'rowsprout-publish-box-adjustments' );
		wp_add_inline_style( 'rowsprout-publish-box-adjustments', '#misc-publishing-actions .misc-pub-curtime{display:none;}' );

		PublishBoxRenderer::renderSmallAdjustments();
	}
}




