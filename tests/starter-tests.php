<?php
declare( strict_types = 1 );

// Runs PHP with $args from the $cwd directory, and returns its exit code and
// output.
function run_php( array $args, string $cwd ) : array {
	$process = proc_open( [ PHP_BINARY, ...$args ], [ [ 'file', '/dev/null', 'r' ], [ 'pipe', 'w' ], [ 'pipe', 'w' ] ], $pipes, $cwd );
	$output = (string) stream_get_contents( $pipes[1] );
	$errors = (string) stream_get_contents( $pipes[2] );
	$result = [ 'code' => proc_close( $process ), 'output' => $output, 'errors' => $errors ];
	return $result;
}

test( 'vendor/bin/tillkite new makes a site that answers its routes', function() : void {
	// With a umask of 0, nothing it makes is writable by every user.
	$dir = temp_dir();
	$umask = umask( 0 );
	$made = run_php( [ dirname( __DIR__ ) . '/bin/tillkite', 'new', 'my-site' ], $dir );
	umask( $umask );
	expect( $made['code'] )->toBe( 0 );
	expect( [ fileperms( $dir . '/my-site/var' ) & 0o777, fileperms( $dir . '/my-site/handlers/home.php' ) & 0o777 ] )->toBe( [ 0o775, 0o664 ] );
	expect( $made['output'] )->toContain( "  cd my-site\n  php -S localhost:8000 -t public public/index.php\n" );

	// A route whose handler throws, for the 500 page.
	$site = $dir . '/my-site';
	file_put_contents( $site . '/routes.php', str_replace( "'hello.php' ],", "'hello.php' ],\n\t[ 'GET', '/boom', 'boom.php' ],", (string) file_get_contents( $site . '/routes.php' ) ) );
	file_put_contents( $site . '/handlers/boom.php', "<?php throw new LogicException( 'Boom' );\n" );

	$port = serve( $site . '/public' )['port'];
	$home = http( $port, 'GET', '/' );
	expect( $home['status'] )->toBe( 200 );
	expect( $home['body'] )->toContain( '<title>It works</title>' );
	expect( http( $port, 'GET', '/hello/Ada%20%3Cb%3E' )['body'] )->toContain( '<h1>Hello, Ada &lt;b&gt;</h1>' );
	expect( http( $port, 'GET', '/style.css' )['body'] )->toContain( 'color-scheme' );

	$missing = http( $port, 'GET', '/nope' );
	expect( $missing['status'] )->toBe( 404 );
	expect( $missing['body'] )->toContain( '<code>/nope</code>' );
	expect( $missing['headers'] )->toMatchArray( [
		'x-content-type-options' => 'nosniff',
		'referrer-policy' => 'strict-origin-when-cross-origin',
		'content-security-policy' => "frame-ancestors 'none'",
	] );

	$post = http( $port, 'POST', '/' );
	expect( $post['status'] )->toBe( 405 );
	expect( $post['body'] )->toContain( 'take POST requests' );

	$boom = http( $port, 'GET', '/boom' );
	expect( $boom['status'] )->toBe( 500 );
	expect( $boom['body'] )->toContain( 'LogicException: Boom' );

	expect( glob( $site . '/var/tillkite-*.php' ) )->toHaveCount( 1 );
} );

test( 'the front controller that new makes sets the production settings before init.php can fail', function() : void {
	$dir = temp_dir();
	expect( run_php( [ dirname( __DIR__ ) . '/bin/tillkite', 'new', 'my-site' ], $dir )['code'] )->toBe( 0 );

	// An init.php that shows the settings, then fails like a lost database
	// connection. With display_errors on, its message would follow them.
	$names = [ 'display_errors', 'log_errors', 'zend.exception_ignore_args', 'session.cookie_httponly', 'session.cookie_samesite', 'session.cookie_secure', 'session.use_strict_mode' ];
	file_put_contents( $dir . '/my-site/init.php', '<?php echo json_encode( array_map( ini_get( ... ), ' . var_export( $names, true ) . " ) );\nthrow new RuntimeException( 'the password is hunter2' );\n" );
	$run = run_php( [ '-d', 'display_errors=1', '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=0', 'public/index.php' ], $dir . '/my-site' );

	expect( $run['output'] )->toBe( '["0","1","1","1","Lax","1","1"]' );
} );

test( 'new writes nothing when any file it would make is there', function() : void {
	$dir = temp_dir();
	mkdir( $dir . '/handlers' );
	file_put_contents( $dir . '/routes.php', 'mine' );
	file_put_contents( $dir . '/handlers/home.php', 'mine' );
	mkdir( $dir . '/mine' );

	// A symlink to nothing, which new would write through, and one in place
	// of a directory, which would send its files somewhere else.
	symlink( $dir . '/nowhere', $dir . '/init.php' );
	symlink( $dir . '/mine', $dir . '/var' );

	$made = run_php( [ dirname( __DIR__ ) . '/bin/tillkite', 'new', $dir ], $dir );
	expect( $made['code'] )->toBe( 1 );
	expect( $made['errors'] )->toBe( 'These files are already in ' . $dir . ", so nothing was written:\n\n  init.php\n  routes.php\n  handlers/home.php\n  var\n" );
	expect( scandir( $dir ) )->toBe( [ '.', '..', 'handlers', 'init.php', 'mine', 'routes.php', 'var' ] );
	expect( scandir( $dir . '/mine' ) )->toBe( [ '.', '..' ] );
	expect( scandir( $dir . '/handlers' ) )->toBe( [ '.', '..', 'home.php' ] );
	expect( file_get_contents( $dir . '/routes.php' ) )->toBe( 'mine' );
} );

test( 'new quotes the nginx root, gives a path nginx cannot take a stand-in, and puts ./ before a name cd would take as an option', function() : void {
	$dir = temp_dir();
	$quoted = run_php( [ dirname( __DIR__ ) . '/bin/tillkite', 'new', 'a b;#"' ], $dir );
	expect( $quoted['output'] )->toContain( '      root "' . addcslashes( realpath( $dir ) . '/a b;#"/public', '"\\' ) . "\";\n" );

	// nginx has no escape for $, which starts a variable.
	$dollar = run_php( [ dirname( __DIR__ ) . '/bin/tillkite', 'new', 'x$host' ], $dir );
	expect( $dollar['output'] )->toContain( "      root /path/to/public;\n" );
	expect( $dollar['output'] )->not->toContain( 'x$host/public' );

	// cd takes a name that starts with - as an option.
	expect( run_php( [ dirname( __DIR__ ) . '/bin/tillkite', 'new', '-P' ], $dir )['output'] )->toContain( "\n  cd ./-P\n" );
} );
