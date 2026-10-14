<?php
/**
 * Run `php -l` on every plugin PHP file (excluding vendor/ and build/).
 *
 * Usage: php bin/lint.php
 *
 * @package Radius
 */

if ( PHP_SAPI !== 'cli' ) {
	exit( 1 );
}

$root     = dirname( __DIR__ );
$skip     = array( 'vendor', 'build', 'node_modules', '.git' );
$failures = 0;
$checked  = 0;

$iterator = new RecursiveIteratorIterator(
	new RecursiveCallbackFilterIterator(
		new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
		static function ( $file ) use ( $skip ) {
			return ! ( $file->isDir() && in_array( $file->getFilename(), $skip, true ) );
		}
	)
);

foreach ( $iterator as $file ) {
	if ( 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	++$checked;
	$output = array();
	$status = 0;
	exec( escapeshellarg( PHP_BINARY ) . ' -l ' . escapeshellarg( $file->getPathname() ) . ' 2>&1', $output, $status );
	if ( 0 !== $status ) {
		++$failures;
		echo implode( PHP_EOL, $output ), PHP_EOL;
	}
}

echo sprintf( 'Linted %d PHP file(s) with PHP %s: %d error(s).', $checked, PHP_VERSION, $failures ), PHP_EOL;
exit( $failures > 0 ? 1 : 0 );
