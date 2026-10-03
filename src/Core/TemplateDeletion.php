<?php

namespace RowSprout\Core;

use RowSprout\Core\Template\Lifecycle\TemplateDeletionManager;

// PostTypes lives in this same namespace, no `use` needed.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateDeletion {

	public static function register(): void {
		add_action( 'wp_trash_post', [ self::class, 'handleTemplateTrashed' ] );
		add_action( 'untrash_post', [ self::class, 'handleTemplateUntrashed' ] );
		add_action( 'before_delete_post', [ self::class, 'handleTemplateDeleted' ] );
	}

	public static function handleTemplateTrashed( int $postId ): void {
		if ( get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			return;
		}

		TemplateDeletionManager::trashGeneratedPagesAndMarkDeleted( $postId );
	}

	public static function handleTemplateDeleted( int $postId ): void {
		if ( get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			return;
		}

		TemplateDeletionManager::deleteGeneratedPagesAndRows( $postId );
	}

	public static function handleTemplateUntrashed( int $postId ): void {
		if ( get_post_type( $postId ) !== PostTypes::TEMPLATE ) {
			return;
		}

		TemplateDeletionManager::restoreGeneratedPagesAndStatuses( $postId );
	}
}
