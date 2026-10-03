<?php

namespace RowSprout\Support;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Autoloader {

	/**
	 * @var string
	 */
	private $prefix;

	/**
	 * @var string
	 */
	private $baseDir;

	public function __construct( string $prefix, string $baseDir ) {
		$this->prefix  = trim( $prefix, '\\' ) . '\\';
		$this->baseDir = rtrim( $baseDir, DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR;
	}

	public function register(): void {
		spl_autoload_register( [ $this, 'load' ] );
	}

	private function load( string $className ): void {
		if ( strpos( $className, $this->prefix ) !== 0 ) {
			return;
		}

		$relativeClass = substr( $className, strlen( $this->prefix ) );
		$relativePath  = str_replace( '\\', DIRECTORY_SEPARATOR, $relativeClass ) . '.php';
		$file          = $this->baseDir . $relativePath;

		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
}
