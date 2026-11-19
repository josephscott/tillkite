<?php
declare( strict_types = 1 );

// The front controller for the php-fpm tests. Nothing in this site names
// $_SERVER, so PHP builds it only if Tillkite does. It loads Tillkite's
// classes from src/ itself, as Composer's autoloader would also load the
// files of the tests' own packages. With the dist file, php-fpm has loaded
// every class first.
spl_autoload_register( static function( string $class ) : void {
	require dirname( __DIR__, 4 ) . '/src/' . strtolower( substr( $class, strlen( 'Tillkite\\' ) ) ) . '.php';
} );

// A request can name another handlers directory in an X-Handlers header,
// for the test of a site that's set up with it inside the document root.
$handlers = getenv( 'HTTP_X_HANDLERS' );
if ( $handlers === false ) {
	$handlers = dirname( __DIR__ ) . '/handlers';
}

$tillkite = new Tillkite\App( [
	'routes' => dirname( __DIR__ ) . '/routes.php',
	'handlers' => $handlers,
	'errors' => [ 404 => 'request.php', 405 => 'request.php' ],
] );

return $tillkite->run();
