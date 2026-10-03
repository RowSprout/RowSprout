<?php

namespace RowSprout\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class GroupsTable {

	public static function name(): string {
		global $wpdb;
		return $wpdb->prefix . 'rowsprout_page_groups';
	}
}
