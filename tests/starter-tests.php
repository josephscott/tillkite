<?php
declare( strict_types = 1 );

test( 'vendor/bin/tillkite new makes a site that answers its routes', function() : void {
	// With a umask of 0, nothing it makes is writable by every user.
	$dir = temp_dir();
	$umask = umask( 0 );
	$made = run_php( [ dirname( __DIR__ ) . '/bin/tillkite', 'new', 'my-site' ], $dir );
	umask( $umask );
	expect( $made['code'] )->toBe( 0 );
	expect( [ fileperms( $dir . '/my-site/var' ) & 0o777, fileperms( $dir . '/my-site/handlers/home.php' ) & 0o777 ] )->toBe( [ 0o775, 0o664 ] );
	expect( $made['output'] )->toContain( "  cd my-site\n  php -S localhost:8000 -t public public/index.php\n" );

	// A route whose handler throws, for the 500 page, and one that shows PHP's
	// settings, which php -S leaves as they are, for the developer.
	$site = $dir . '/my-site';
	file_put_contents( $site . '/routes.php', str_replace( "'hello.php' ],", "'hello.php' ],\n\t[ 'GET', '/boom', 'boom.php' ],\n\t[ 'GET', '/settings', 'settings.php' ],", (string) file_get_contents( $site . '/routes.php' ) ) );
	file_put_contents( $site . '/handlers/boom.php', "<?php throw new LogicException( 'Boom' );\n" );
	file_put_contents( $site . '/handlers/settings.php', "<?php echo (int) ini_get( 'display_errors' ), ' ', (int) ini_get( 'session.cookie_secure' );\n" );

	$port = serve( $site . '/public', true, true )['port'];
	expect( http( $port, 'GET', '/settings' )['body'] )->toBe( '1 0' );

	$home = http( $port, 'GET', '/' );
	expect( $home['status'] )->toBe( 200 );
	expect( $home['body'] )->toContain( '<title>It works</title>' )->toEndWith( "</main>\n</body>\n</html>\n" );
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

test( 'php tillkite.php new gives a site a copy of itself in lib/, or makes the site around it when it is in lib/ already', function() : void {
	$dir = temp_dir();
	mkdir( $dir . '/lib' );
	copy( dirname( __DIR__ ) . '/dist/tillkite.php', $dir . '/lib/tillkite.php' );
	$here = run_php( [ 'lib/tillkite.php', 'new' ], $dir );
	expect( $here['code'] )->toBe( 0 );
	expect( $here['output'] )->toContain( "in this directory:\n" )->toContain( "\n  lib/tillkite.php\n" );
	expect( $here['output'] )->not->toContain( 'cd ' );
	expect( $dir . '/public/index.php' )->toBeFile();

	$made = run_php( [ 'lib/tillkite.php', 'new', 'my-site' ], $dir );
	expect( $made['code'] )->toBe( 0 );
	expect( file_get_contents( $dir . '/my-site/lib/tillkite.php' ) )->toBe( file_get_contents( $dir . '/lib/tillkite.php' ) );

	// The site requires its own copy.
	$hello = http( serve( $dir . '/my-site/public', false )['port'], 'GET', '/hello/Ada' );
	expect( $hello['body'] )->toContain( '<h1>Hello, Ada</h1>' );
} );

test( 'new writes nothing when any file it would make is there', function() : void {
	$dir = temp_dir();
	mkdir( $dir . '/handlers' );
	file_put_contents( $dir . '/routes.php', 'mine' );
	file_put_contents( $dir . '/handlers/home.php', 'mine' );
	mkdir( $dir . '/mine' );
	file_put_contents( $dir . '/mine/tillkite.php', 'mine' );

	// A symlink to nothing, which new would write through, and two in place
	// of directories, which would send their files somewhere else.
	symlink( $dir . '/nowhere', $dir . '/init.php' );
	symlink( $dir . '/mine', $dir . '/var' );
	symlink( $dir . '/mine', $dir . '/lib' );

	$made = run_php( [ dirname( __DIR__ ) . '/dist/tillkite.php', 'new', $dir ], $dir );
	expect( $made['code'] )->toBe( 1 );
	expect( $made['errors'] )->toBe( 'These files are already in ' . $dir . ", so nothing was written:\n\n  init.php\n  routes.php\n  handlers/home.php\n  var\n  lib\n  lib/tillkite.php\n" );
	expect( scandir( $dir ) )->toBe( [ '.', '..', 'handlers', 'init.php', 'lib', 'mine', 'routes.php', 'var' ] );
	expect( scandir( $dir . '/mine' ) )->toBe( [ '.', '..', 'tillkite.php' ] );
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
