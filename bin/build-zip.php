<?php
/**
 * Builds the WordPress.org release zip: build/rowsprout-<version>.zip with a
 * top-level rowsprout/ folder, every file of the working tree except what
 * .distignore excludes.
 *
 * Usage: php bin/build-zip.php
 *
 * It packs the working tree, not a git ref, so it warns when the tree has
 * uncommitted or untracked files — a release should be built from a commit.
 * It refuses to build when the three version numbers (plugin header,
 * ROWSPROUT_VERSION, readme.txt "Stable tag") disagree.
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root = dirname( __DIR__ );

$main = (string) file_get_contents( $root . '/rowsprout.php' );
preg_match( '/^\s*\*\s*Version:\s*(\S+)/m', $main, $header );
preg_match( "/define\(\s*'ROWSPROUT_VERSION',\s*'([^']+)'/", $main, $constant );
preg_match( '/^Stable tag:\s*(\S+)/m', (string) file_get_contents( $root . '/readme.txt' ), $stable );

$versions = [
	'Version header' => $header[1] ?? null,
	'ROWSPROUT_VERSION'  => $constant[1] ?? null,
	'Stable tag'     => $stable[1] ?? null,
];
if ( in_array( null, $versions, true ) || count( array_unique( $versions ) ) !== 1 ) {
	fwrite( STDERR, "Version mismatch:\n" );
	foreach ( $versions as $label => $value ) {
		fwrite( STDERR, sprintf( "  %-15s %s\n", $label, $value ?? '(not found)' ) );
	}
	exit( 1 );
}
$version = $versions['Version header'];

$status = shell_exec( 'git -C ' . escapeshellarg( $root ) . ' status --porcelain 2>&1' );
if ( is_string( $status ) && trim( $status ) !== '' ) {
	fwrite( STDERR, "Warning: the working tree has uncommitted or untracked files; this zip is not a committed state.\n" );
}

$patterns = [];
foreach ( file( $root . '/.distignore', FILE_IGNORE_NEW_LINES ) as $line ) {
	$line = trim( $line );
	if ( $line !== '' && $line[0] !== '#' ) {
		$patterns[] = $line;
	}
}

/**
 * @param string   $path     Path relative to the plugin root, "/"-separated.
 * @param bool     $isDir
 * @param string[] $patterns
 */
function rowsprout_build_is_excluded( string $path, bool $isDir, array $patterns ): bool {
	foreach ( $patterns as $pattern ) {
		$dirOnly = substr( $pattern, -1 ) === '/';
		$pattern = rtrim( $pattern, '/' );
		if ( $dirOnly && ! $isDir ) {
			continue;
		}

		if ( $pattern[0] === '/' || strpos( $pattern, '/' ) !== false ) {
			if ( fnmatch( ltrim( $pattern, '/' ), $path, FNM_PATHNAME ) ) {
				return true;
			}
		} elseif ( fnmatch( $pattern, basename( $path ) ) ) {
			return true;
		}
	}

	return false;
}

/**
 * @param string[] $patterns
 * @return string[] Relative file paths.
 */
function rowsprout_build_collect( string $root, string $relative, array $patterns ): array {
	$files = [];
	$dir   = $relative === '' ? $root : $root . '/' . $relative;

	foreach ( scandir( $dir ) as $name ) {
		if ( $name === '.' || $name === '..' ) {
			continue;
		}

		$path  = $relative === '' ? $name : $relative . '/' . $name;
		$isDir = is_dir( $root . '/' . $path );
		if ( rowsprout_build_is_excluded( $path, $isDir, $patterns ) ) {
			continue;
		}

		if ( $isDir ) {
			$files = array_merge( $files, rowsprout_build_collect( $root, $path, $patterns ) );
		} else {
			$files[] = $path;
		}
	}

	return $files;
}

$files = rowsprout_build_collect( $root, '', $patterns );

$buildDir = $root . '/build';
if ( ! is_dir( $buildDir ) && ! mkdir( $buildDir ) ) {
	fwrite( STDERR, "Cannot create {$buildDir}\n" );
	exit( 1 );
}

$zipPath = $buildDir . '/rowsprout-' . $version . '.zip';
if ( file_exists( $zipPath ) ) {
	unlink( $zipPath );
}

$zip = new ZipArchive();
if ( $zip->open( $zipPath, ZipArchive::CREATE ) !== true ) {
	fwrite( STDERR, "Cannot create {$zipPath}\n" );
	exit( 1 );
}
foreach ( $files as $file ) {
	$zip->addFile( $root . '/' . $file, 'rowsprout/' . $file );
}
$zip->close();

printf( "Built %s (%d files)\n", $zipPath, count( $files ) );
