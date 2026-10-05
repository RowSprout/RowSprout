<?php

namespace RowSprout\ThirdParty;

use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class WpRocket {

	public static function register(): void {
		add_filter( 'page_row_actions', [ self::class, 'removePurgeCacheAction' ], PHP_INT_MAX, 2 );
	}

	/**
	 * Removes the 'Purge Cache' action from WP Rocket for the 'rowsprout_template' post type.
	 *
	 * @param array    $actions
	 * @param \WP_Post $post
	 * @return array
	 */
	public static function removePurgeCacheAction( array $actions, \WP_Post $post ): array {
		if ( $post->post_type === PostTypes::TEMPLATE ) {
			unset( $actions['rocket_purge'] );
		}
		return $actions;
	}
}
