<?php
declare( strict_types = 1 );

// Pest loads this file before running the tests. It must be named Pest.php.

// With TILLKITE_DIST=1, the tests run against dist/tillkite.php instead of
// src/. It's loaded before any class is used, so the autoloader never loads
// src/, and the php -S servers load it first with auto_prepend_file.
function dist_file() : string {
	$file = '';
	if ( getenv( 'TILLKITE_DIST' ) === '1' ) {
		$file = dirname( __DIR__ ) . '/dist/tillkite.php';
	}

	return $file;
}

if ( dist_file() !== '' ) {
	require dist_file();
}

// A compiler whose handlers directory is tests/fixtures/handlers, which has
// page.php, before.php, 404.php, 405.php and 500.php.
function make_compiler( array $settings = [] ) : Tillkite\Compiler {
	$compiler = new Tillkite\Compiler();
	$compiler->handlers = __DIR__ . '/fixtures/handlers';
	foreach ( $settings as $name => $value ) {
		$compiler->$name = $value;
	}

	return $compiler;
}

// Compiles routes that are expected to have no problems.
function compile_routes( array $routes, array $settings = [] ) : array {
	$compiler = make_compiler( $settings );
	$compiled = $compiler->compile( $routes );
	expect( $compiler->problems )->toBe( [] );
	return $compiled;
}

function route_problems( array $routes, array $settings = [] ) : array {
	$compiler = make_compiler( $settings );
	$compiler->compile( $routes );
	$problems = $compiler->problems;
	return $problems;
}

function make_matcher( array $routes, array $settings = [] ) : Tillkite\Matcher {
	$matcher = new Tillkite\Matcher( compile_routes( $routes, $settings ) );
	return $matcher;
}

// Starts php -S on tests/fixtures/site the first time it's called, and stops
// it when the tests end. Returns its port and the log file that gets its
// output and errors.
function site() : array {
	$site = serve( __DIR__ . '/fixtures/site/public' );
	return $site;
}

// The same for the example site, which starts with no route cache. Its test
// checks that it writes one, and a cache left in example/var by a copy of
// the repository at another path, which the example doesn't remove, since
// it's for another routes file, would be counted too.
function example() : array {
	static $cleared = false;
	if ( ! $cleared ) {
		array_map( unlink( ... ), glob( dirname( __DIR__ ) . '/example/var/tillkite-*.php' ) ?: [] );
		$cleared = true;
	}

	// The secret that hooks/github.php checks signatures with, which php -S
	// gets from this process's environment.
	putenv( 'GITHUB_WEBHOOK_SECRET=example-secret' );
	$example = serve( dirname( __DIR__ ) . '/example/public' );
	return $example;
}

// Starts php -S on a document root, with its index.php as the router, once
// for each document root. PHP's own output buffer is off, as it is by
// default, so only Tillkite's buffering decides when headers go out.
// OPcache is on, as it is in production. With $dist false, it doesn't load
// dist/tillkite.php first, for a site that requires its own copy, whose
// classes would be declared twice.
function serve( string $public, bool $dist = true ) : array {
	static $servers = [];
	if ( isset( $servers[$public] ) ) {
		return $servers[$public];
	}

	$log = tempnam( sys_get_temp_dir(), 'tillkite-site-' );
	$port = free_port();

	$output = [ 'file', $log, 'a' ];
	$prepend = '';
	if ( $dist ) {
		$prepend = dist_file();
	}

	$command = [ PHP_BINARY, '-d', 'auto_prepend_file=' . $prepend, '-d', 'opcache.enable_cli=1', '-d', 'output_buffering=0', '-d', 'display_errors=0', '-d', 'log_errors=1', '-d', 'error_log=' . $log, '-S', '127.0.0.1:' . $port, '-t', $public, $public . '/index.php' ];
	$process = proc_open( $command, [ [ 'file', '/dev/null', 'r' ], $output, $output ], $pipes );
	register_shutdown_function( function() use ( $process, $log ) : void {
		proc_terminate( $process );
		unlink( $log );
	} );

	for ( $i = 0; $i < 300 && ! str_contains( (string) file_get_contents( $log ), 'started' ); $i++ ) {
		usleep( 10_000 );
	}

	$servers[$public] = [ 'port' => $port, 'log' => $log ];
	return $servers[$public];
}

// A TCP port on 127.0.0.1 that nothing is listening on.
function free_port() : int {
	$socket = stream_socket_server( 'tcp://127.0.0.1:0' );
	$port = (int) substr( (string) strrchr( (string) stream_socket_get_name( $socket, false ), ':' ), 1 );
	fclose( $socket );
	return $port;
}

// Starts nginx and php-fpm on tests/fixtures/fpm the first time it's
// called, with the nginx configuration the README gives and OPcache on, and
// stops them when the tests end. Returns nginx's port. With TILLKITE_DIST=1,
// php-fpm loads dist/tillkite.php first. The php-fpm tests run only with
// TILLKITE_FPM=1, which make fpm sets in Docker, where both
// are installed, and need nginx and php-fpm on the PATH. With $starter, the
// port is another server's, for a site that new makes, whose init.php fails
// for a request with an X-Fail header, and which fpm_site() gives the path of.
function fpm( bool $starter = false ) : int {
	static $port = 0;
	static $starter_port = 0;
	if ( $port !== 0 ) {
		$requested = $port;
		if ( $starter ) {
			$requested = $starter_port;
		}

		return $requested;
	}

	$dir = temp_dir();
	$site = fpm_site( $dir . '/site' );
	shell_exec( escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( dirname( __DIR__ ) . '/bin/tillkite' ) . ' new ' . escapeshellarg( $site ) );
	file_put_contents( $site . '/init.php', "if ( getenv( 'HTTP_X_FAIL' ) !== false ) {\n\tthrow new RuntimeException( 'the password is hunter2' );\n}\n", FILE_APPEND );
	$starter_port = free_port();
	$public = __DIR__ . '/fixtures/fpm/public';
	$fpm_port = free_port();
	$port = free_port();
	preg_match( '/--conf-path=(\S+)/', (string) shell_exec( 'nginx -V 2>&1' ), $conf );
	$fastcgi = dirname( $conf[1] ?? '/etc/nginx/nginx.conf' ) . '/fastcgi.conf';
	file_put_contents( $dir . '/php-fpm.conf', <<<CONF
		[global]
		pid = {$dir}/php-fpm.pid
		error_log = {$dir}/php-fpm.log
		daemonize = no

		[tests]
		listen = 127.0.0.1:{$fpm_port}
		pm = static
		pm.max_children = 1
		php_admin_value[error_log] = {$dir}/php-errors.log
		php_admin_flag[log_errors] = on
		php_value[display_errors] = on
		php_admin_value[opcache.revalidate_freq] = 0

		CONF );
	file_put_contents( $dir . '/nginx.conf', <<<CONF
		worker_processes 1;
		pid {$dir}/nginx.pid;
		error_log {$dir}/nginx-error.log;
		events {
		}
		http {
			access_log off;
			client_body_temp_path {$dir}/nginx-client;
			proxy_temp_path {$dir}/nginx-proxy;
			fastcgi_temp_path {$dir}/nginx-fastcgi;
			uwsgi_temp_path {$dir}/nginx-uwsgi;
			scgi_temp_path {$dir}/nginx-scgi;
			server {
				listen 127.0.0.1:{$port};
				root {$public};

				location / {
					try_files \$uri /index.php\$is_args\$args;
				}

				location ~* \.php\$ {
					rewrite ^ /index.php last;
				}

				location = /index.php {
					include {$fastcgi};
					fastcgi_pass 127.0.0.1:{$fpm_port};
				}

				# Without fastcgi.conf, so PHP gets no DOCUMENT_ROOT.
				location = /no-document-root {
					fastcgi_param SCRIPT_FILENAME {$public}/index.php;
					fastcgi_param REQUEST_METHOD \$request_method;
					fastcgi_param REQUEST_URI \$request_uri;
					fastcgi_pass 127.0.0.1:{$fpm_port};
				}
			}
			server {
				listen 127.0.0.1:{$starter_port};
				root {$site}/public;

				location / {
					try_files \$uri /index.php\$is_args\$args;
				}

				location ~* \.php\$ {
					rewrite ^ /index.php last;
				}

				location = /index.php {
					include {$fastcgi};
					fastcgi_pass 127.0.0.1:{$fpm_port};
				}
			}
		}

		CONF );

	$output = [ 'file', $dir . '/servers.log', 'a' ];
	$servers = [
		proc_open( [ 'php-fpm', '-F', '-y', $dir . '/php-fpm.conf', '-d', 'opcache.enable=1', '-d', 'auto_prepend_file=' . dist_file() ], [ [ 'file', '/dev/null', 'r' ], $output, $output ], $pipes ),
		proc_open( [ 'nginx', '-p', $dir, '-c', $dir . '/nginx.conf', '-e', $dir . '/nginx-error.log', '-g', 'daemon off;' ], [ [ 'file', '/dev/null', 'r' ], $output, $output ], $pipes ),
	];
	register_shutdown_function( function() use ( $servers ) : void {
		foreach ( $servers as $server ) {
			proc_terminate( $server );
			proc_close( $server );
		}
	} );

	// Waits up to five seconds for a page from php-fpm through nginx.
	$ready = false;
	for ( $i = 0; $i < 500 && ! $ready; $i++ ) {
		usleep( 10_000 );
		set_error_handler( static fn () : bool => true );
		$socket = stream_socket_client( 'tcp://127.0.0.1:' . $port, $errno, $error, 0.1 );
		restore_error_handler();
		if ( $socket !== false ) {
			fclose( $socket );
			$ready = http( $port, 'GET', '/users/1' )['status'] === 200;
		}
	}

	expect( $ready )->toBeTrue( "nginx and php-fpm didn't start:\n" . file_get_contents( $dir . '/servers.log' ) );
	$requested = $port;
	if ( $starter ) {
		$requested = $starter_port;
	}

	return $requested;
}

// Starts Apache with mod_php, once, on the php-fpm tests' site, whose
// public/.htaccess sends every request to index.php, and returns its port. It
// runs as the user the tests run as, with a configuration of its own, and
// one process, so the requests share one PHP, as the php-fpm tests' do.
// make apache runs these in Docker.
function apache() : int {
	static $port = 0;
	if ( $port !== 0 ) {
		return $port;
	}

	$dir = temp_dir();
	$public = __DIR__ . '/fixtures/fpm/public';
	$modules = '/usr/lib/apache2/modules';
	$port = free_port();
	// Apache turns away an empty value.
	$prepend = '';
	if ( dist_file() !== '' ) {
		$prepend = 'php_admin_value auto_prepend_file ' . dist_file();
	}

	file_put_contents( $dir . '/httpd.conf', <<<CONF
		ServerName localhost
		Listen 127.0.0.1:{$port}
		PidFile {$dir}/httpd.pid
		DefaultRuntimeDir {$dir}
		ErrorLog {$dir}/apache-error.log
		LoadModule mpm_prefork_module {$modules}/mod_mpm_prefork.so
		LoadModule authz_core_module {$modules}/mod_authz_core.so
		LoadModule dir_module {$modules}/mod_dir.so
		LoadModule rewrite_module {$modules}/mod_rewrite.so
		LoadModule php_module {$modules}/libphp.so
		StartServers 1
		MinSpareServers 1
		MaxSpareServers 1
		MaxRequestWorkers 1
		DocumentRoot {$public}
		DirectoryIndex index.php
		<Directory {$public}>
			AllowOverride All
			Require all granted
		</Directory>
		<FilesMatch \.php$>
			SetHandler application/x-httpd-php
		</FilesMatch>
		php_admin_value error_log {$dir}/php-errors.log
		php_admin_flag log_errors on
		php_value display_errors on
		{$prepend}

		CONF );

	// Apache stops by signalling its whole process group, so it gets one of
	// its own, or it would stop the tests too.
	$output = [ 'file', $dir . '/server.log', 'a' ];
	$server = proc_open( [ 'setsid', 'apache2', '-f', $dir . '/httpd.conf', '-DFOREGROUND' ], [ [ 'file', '/dev/null', 'r' ], $output, $output ], $pipes );
	register_shutdown_function( function() use ( $server ) : void {
		proc_terminate( $server );
		proc_close( $server );
	} );

	// Waits up to five seconds for a page from Apache.
	$ready = false;
	for ( $i = 0; $i < 500 && ! $ready; $i++ ) {
		usleep( 10_000 );
		set_error_handler( static fn () : bool => true );
		$socket = stream_socket_client( 'tcp://127.0.0.1:' . $port, $errno, $error, 0.1 );
		restore_error_handler();
		if ( $socket !== false ) {
			fclose( $socket );
			$ready = http( $port, 'GET', '/users/1' )['status'] === 200;
		}
	}

	$errors = '';
	if ( is_file( $dir . '/apache-error.log' ) ) {
		$errors = (string) file_get_contents( $dir . '/apache-error.log' );
	}

	expect( $ready )->toBeTrue( "Apache didn't start:\n" . file_get_contents( $dir . '/server.log' ) . $errors );
	return $port;
}

// The path of the site that new makes, for fpm( true ), once $set has said.
function fpm_site( string $set = '' ) : string {
	static $site = '';
	if ( $set !== '' ) {
		$site = $set;
	}

	return $site;
}

// Sends a request to the test site. $options become App's options, and $set
// is set on it as properties.
function request( string $method, string $target, array $options = [], array $set = [] ) : array {
	$headers = [];
	if ( $options !== [] ) {
		$headers['X-Options'] = json_encode( $options );
	}

	if ( $set !== [] ) {
		$headers['X-Set'] = json_encode( $set );
	}

	$response = http( site()['port'], $method, $target, $headers );
	return $response;
}

// Sends a raw HTTP/1.0 request, so the request target goes out exactly as
// written. Header names come back in lowercase.
function http( int $port, string $method, string $target, array $headers = [], string $body = '' ) : array {
	$client = stream_socket_client( 'tcp://127.0.0.1:' . $port );
	$request = $method . ' ' . $target . " HTTP/1.0\r\nHost: localhost\r\nContent-Length: " . strlen( $body ) . "\r\n";
	foreach ( $headers as $name => $value ) {
		$request .= $name . ': ' . $value . "\r\n";
	}

	fwrite( $client, $request . "\r\n" . $body );
	$response = (string) stream_get_contents( $client );
	fclose( $client );

	[ $head, $body ] = explode( "\r\n\r\n", $response, 2 ) + [ '', '' ];
	$lines = explode( "\r\n", $head );
	$headers = [];
	foreach ( array_slice( $lines, 1 ) as $line ) {
		[ $name, $value ] = explode( ':', $line, 2 );
		$headers[strtolower( $name )] = trim( $value );
	}

	$result = [ 'status' => (int) explode( ' ', $lines[0] )[1], 'headers' => $headers, 'body' => $body ];
	return $result;
}

// Makes an empty directory, which is removed with everything in it when the
// tests end.
function temp_dir() : string {
	$dir = sys_get_temp_dir() . '/tillkite-test-' . uniqid();
	mkdir( $dir );
	register_shutdown_function( remove_dir( ... ), $dir );
	return $dir;
}

function remove_dir( string $dir ) : void {
	foreach ( array_diff( (array) scandir( $dir ), [ '.', '..' ] ) as $name ) {
		$path = $dir . '/' . $name;
		if ( is_dir( $path ) && ! is_link( $path ) ) {
			remove_dir( $path );
		} else {
			unlink( $path );
		}
	}

	rmdir( $dir );
}

// Writes a routes file with one route, to text.php, and sets its
// modification time.
function write_routes( string $file, string $path, int $mtime ) : void {
	file_put_contents( $file, "<?php return [ [ 'GET', '" . $path . "', 'text.php' ] ];\n" );
	touch( $file, $mtime );
}

// What error_log() wrote while $run ran.
function logged( callable $run ) : string {
	$file = (string) tempnam( sys_get_temp_dir(), 'tillkite-log-' );
	$previous = ini_set( 'error_log', $file );
	$run();
	ini_set( 'error_log', (string) $previous );
	$log = (string) file_get_contents( $file );
	unlink( $file );
	return $log;
}
