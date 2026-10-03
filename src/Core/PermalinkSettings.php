<?php

namespace RowSprout\Core;

// PostTypes lives in this same namespace, no `use` needed.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The user-configurable URL base for generated rowsprout_page posts. Empty
 * string means "no base" — RemoveCptBase strips the post type's own default
 * slug entirely. A non-empty value is used as the CPT's rewrite slug
 * directly (see PostTypes::registerPostTypes()), so WP's native rewrite
 * handles it and RemoveCptBase stays out of the way.
 */
final class PermalinkSettings {

	public const OPTION = 'rowsprout_permalink_base';

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'handleSave' ] );
	}

	public static function getBase(): string {
		return (string) get_option( self::OPTION, '' );
	}

	public static function handleSave(): void {
		if ( ! isset( $_POST['rowsprout_permalink_nonce'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['rowsprout_permalink_nonce'] ) ), 'rowsprout_permalink_action' ) ) {
			wp_die( 'Security check failed' );
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Insufficient permissions' );
		}

		$mode    = isset( $_POST['rowsprout_permalink_mode'] ) ? sanitize_text_field( wp_unslash( $_POST['rowsprout_permalink_mode'] ) ) : 'none';
		$newBase = '';

		if ( $mode === 'custom' && isset( $_POST['rowsprout_permalink_base'] ) ) {
			$newBase = sanitize_title( wp_unslash( $_POST['rowsprout_permalink_base'] ) );
		}

		if ( $newBase === self::getBase() ) {
			return;
		}

		update_option( self::OPTION, $newBase );

		// The post types were already registered earlier in this request
		// (on 'init') using the old base — re-register them now so the
		// rewrite rules flush below reflects the new one.
		unregister_post_type( PostTypes::PAGE );
		unregister_post_type( PostTypes::TEMPLATE );
		PostTypes::registerPostTypes();
		flush_rewrite_rules();

		add_action( 'admin_notices', function () use ( $newBase ) {
			echo '<div class="notice notice-success"><p>';
			if ( $newBase === '' ) {
				echo esc_html__( 'Page URL base removed. Generated pages now live directly at the site root.', 'rowsprout' );
			} else {
				echo esc_html( sprintf(
					/* translators: %s: the configured URL base slug */
					__( 'Page URL base set to "%s".', 'rowsprout' ),
					$newBase
				) );
			}
			echo '</p></div>';
		} );
	}
}
