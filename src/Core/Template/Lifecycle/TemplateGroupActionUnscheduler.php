<?php

namespace RowSprout\Core\Template\Lifecycle;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateGroupActionUnscheduler {

	/**
	 * @param array<int, array<string, mixed>> $rows
	 */
	public static function unscheduleTemplateGroupActions( int $templateId, array $rows ): void {
		if ( ! function_exists( 'as_unschedule_action' ) || ! function_exists( 'as_next_scheduled_action' ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$guid = isset( $row['guid'] ) ? sanitize_text_field( (string) ( $row['guid'] ?? '' ) ) : '';
			if ( $guid === '' ) {
				continue;
			}

			$args = [ $guid, $templateId ];
			while ( as_next_scheduled_action( 'rowsprout_process_single_group', $args, 'rowsprout_page' ) ) {
				as_unschedule_action( 'rowsprout_process_single_group', $args, 'rowsprout_page' );
			}
		}
	}
}
