<?php
declare( strict_types = 1 );

// The front controller for the App tests. A request sets App's options with
// a JSON object in an X-Options header, and properties to set after that in
// an X-Set header. routes and handlers default to ../routes.php and
// ../handlers.

require __DIR__ . '/../../../../vendor/autoload.php';

$options = json_decode( $_SERVER['HTTP_X_OPTIONS'] ?? '{}', true );
$options += [ 'routes' => dirname( __DIR__ ) . '/routes.php', 'handlers' => dirname( __DIR__ ) . '/handlers' ];

// Handlers get this subclass as $tillkite.
final class Test_App extends Tillkite\App {
	public function shout( string $text ) : string {
		$shout = strtoupper( $text );
		return $shout;
	}
}

$tillkite = new Test_App( $options );
foreach ( json_decode( $_SERVER['HTTP_X_SET'] ?? '{}', true ) as $name => $value ) {
	$tillkite->$name = $value;
}

return $tillkite->run();
