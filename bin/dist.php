<?php
declare( strict_types = 1 );

// Builds dist/tillkite.php: every class in src/ in one file, for projects
// that don't use Composer. Run it with: make dist

// For the command line only, so a checkout under a web server's document
// root can't run it for a visitor.
if ( PHP_SAPI !== 'cli' ) {
	http_response_code( 404 );
	exit;
}

$root = dirname( __DIR__ );

// Writes the message to stderr and stops with a failing exit code.
function fail( string $message ) : never {
	fwrite( STDERR, $message . "\n" );
	exit( 1 );
}

// Every class file starts with exactly this. The single file has it once.
$start = "<?php\ndeclare( strict_types = 1 );\n\nnamespace Tillkite;\n\n";

$app = (string) file_get_contents( $root . '/src/app.php' );
if ( preg_match( "/const string VERSION = '([^']+)';/", $app, $match ) !== 1 ) {
	fail( 'Could not find App::VERSION in src/app.php.' );
}

// glob() sorts them, so App, the public class, comes first.
$files = glob( $root . '/src/*.php' );
if ( $files === false || $files === [] ) {
	fail( 'Found no class files in src/.' );
}

// App::SOURCE_HASH is a hash of every file in src/, with its own value left
// out, so it doesn't depend on itself. It goes in the route cache's key, so
// any change to Tillkite's code gets a new cache. src/app.php is written
// only when it changes.
$source_hash = "/(const string SOURCE_HASH = ')[0-9a-f]*(';)/";
$sources = '';
foreach ( $files as $file ) {
	$sources .= basename( $file ) . "\n" . preg_replace( $source_hash, '$1$2', (string) file_get_contents( $file ) ) . "\n";
}

$hashed = (string) preg_replace( $source_hash, '${1}' . hash( 'xxh128', $sources ) . '$2', $app, 1, $count );
if ( $count !== 1 ) {
	fail( 'Could not find App::SOURCE_HASH in src/app.php.' );
}

if ( $hashed !== $app && file_put_contents( $root . '/src/app.php', $hashed ) === false ) {
	fail( 'Could not write src/app.php.' );
}

// The MIT license asks for its notice in every copy, and this file is often
// copied on its own.
$license = trim( (string) file_get_contents( $root . '/LICENSE' ) );

$dist = "<?php\n"
	. '// Tillkite ' . $match[1] . ", a small, fast PHP router that maps URLs to PHP files.\n"
	. "//\n"
	. "// This is every class in Tillkite's src/, in one file, for projects that\n"
	. "// don't use Composer: require it, then create a Tillkite\\App. make dist\n"
	. "// builds it, so change src/, not this file.\n"
	. "\n/*\n" . $license . "\n*/\n\n"
	. "declare( strict_types = 1 );\n\nnamespace Tillkite;\n";

foreach ( $files as $file ) {
	$source = (string) file_get_contents( $file );
	if ( ! str_starts_with( $source, $start ) ) {
		fail( 'src/' . basename( $file ) . " doesn't start the way every class file must:\n\n" . $start );
	}

	$dist .= "\n" . substr( $source, strlen( $start ) );
}

if ( ! is_dir( $root . '/dist' ) && ! mkdir( $root . '/dist' ) ) {
	fail( 'Could not create dist/.' );
}

if ( file_put_contents( $root . '/dist/tillkite.php', $dist ) === false ) {
	fail( 'Could not write dist/tillkite.php.' );
}
