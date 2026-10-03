<?php

namespace RowSprout\Core\Template\Lifecycle;

use RowSprout\Core\Groups\GroupTableGateway;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TemplateDeletionStatus {

	public static function toDeletedStatus( string $status ): string {
		$baseStatus = self::restoreStatusFromDeletedMarker( $status );
		if ( $baseStatus === '' ) {
			$baseStatus = GroupTableGateway::STATUS_PENDING;
		}

		return 'deleted-' . $baseStatus;
	}

	public static function restoreStatusFromDeletedMarker( string $status ): string {
		$prefix = 'deleted-';
		if ( strpos( $status, $prefix ) === 0 ) {
			return substr( $status, strlen( $prefix ) );
		}

		return $status;
	}
}
