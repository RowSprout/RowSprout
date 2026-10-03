<?php

namespace RowSprout\Admin;

use RowSprout\Core\PostTypes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class MenuRegistrar {

	public static function registerAdminMenu(): void {
		add_menu_page(
			__( 'RowSprout', 'rowsprout' ),
			__( 'RowSprout', 'rowsprout' ),
			'manage_options',
			'rowsprout_home',
			[ Menu::class, 'renderHomePage' ],
			'dashicons-admin-home'
		);

		add_submenu_page(
			'rowsprout_home',
			__( 'Home', 'rowsprout' ),
			__( 'Home', 'rowsprout' ),
			'manage_options',
			'rowsprout_home'
		);

		add_submenu_page(
			'rowsprout_home',
			__( 'Templates', 'rowsprout' ),
			__( 'Templates', 'rowsprout' ),
			'edit_posts',
			'edit.php?post_type=' . PostTypes::TEMPLATE
		);

		add_submenu_page(
			'rowsprout_home',
			__( 'Pages', 'rowsprout' ),
			__( 'Pages', 'rowsprout' ),
			'edit_posts',
			'edit.php?post_type=' . PostTypes::PAGE
		);

		add_submenu_page(
			'rowsprout_home',
			__( 'Settings', 'rowsprout' ),
			__( 'Settings', 'rowsprout' ),
			'manage_options',
			'rowsprout_settings',
			[ Menu::class, 'renderSettingsPage' ]
		);

		add_submenu_page(
			'rowsprout_home',
			__( 'RowSprout Pro', 'rowsprout' ),
			__( 'RowSprout Pro', 'rowsprout' ),
			'manage_options',
			'rowsprout-upgrade',
			[ \RowSprout\Admin\UpgradeMenu::class, 'renderPage' ]
		);
	}

	/**
	 * WP builds the submenu in whatever order each plugin's admin_menu
	 * callback happens to run (base and Pro items interleave depending on
	 * plugin load order and hook priority), which doesn't match the order
	 * we actually want to show. Runs last (priority 999, after Pro's own
	 * admin_menu registrations) and re-sorts by slug; anything not in the
	 * list — e.g. a future Pro addition — keeps its place at the end
	 * instead of disappearing.
	 */
	public static function reorderAdminSubmenu(): void {
		global $submenu;

		$parentSlug = 'rowsprout_home';
		if ( empty( $submenu[ $parentSlug ] ) || ! is_array( $submenu[ $parentSlug ] ) ) {
			return;
		}

		$desiredOrder = [
			'rowsprout_home',                  // Home
			'edit.php?post_type=' . PostTypes::TEMPLATE,  // Templates
			'edit.php?post_type=' . PostTypes::PAGE,       // Pages
			'rowsprout-batch-scheduling',        // Throttling (Pro)
			'rowsprout_settings',                        // Settings
			'rowsprout-license',                 // License (Pro)
			'rowsprout-upgrade',                 // RowSprout Pro (info page; Pro removes it)
		];

		$items   = $submenu[ $parentSlug ];
		$ordered = [];

		foreach ( $desiredOrder as $slug ) {
			foreach ( $items as $key => $item ) {
				if ( isset( $item[2] ) && $item[2] === $slug ) {
					$ordered[] = $item;
					unset( $items[ $key ] );
				}
			}
		}

		foreach ( $items as $item ) {
			$ordered[] = $item;
		}

		$submenu[ $parentSlug ] = $ordered;
	}
}
