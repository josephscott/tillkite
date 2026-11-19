<?php
declare( strict_types = 1 );

// make load: an HTTP load test. nginx and php-fpm serve the GitHub API's
// routes on http://127.0.0.1:8419, and oha sends requests for 30 seconds
// for each router and request, after a 5-second warm-up run that isn't
// counted. Each response must have the expected status and body before
// anything is timed.
//
// It needs nginx, php-fpm and oha. make load runs it in Docker, which has
// all three. Run it directly, as php bench/load.php, to use your own.
// Everything they write goes in a new temporary directory, which is removed
// when the run ends well, and kept, with the logs, when it fails. Both
// servers stop when it ends.
//
// LOAD_SECONDS, LOAD_CONNECTIONS and LOAD_WORKERS (php-fpm's) change the
// defaults.

namespace Bench;

// For the command line only, so a checkout under a web server's document
// root can't run it for a visitor.
if ( \PHP_SAPI !== 'cli' ) {
	\http_response_code( 404 );
	exit;
}

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/routers.php';

const PORT = 8419;

// The seconds of each warm-up run, which isn't counted.
const WARM_UP = 5;

// Writes the message to stderr and stops with a failing exit code.
function fail( string $message ) : never {
	\fwrite( \STDERR, $message . "\n" );
	exit( 1 );
}

// The full path of a program on the PATH, or ''.
function find_program( string $name ) : string {
	$path = \trim( (string) \shell_exec( 'command -v ' . \escapeshellarg( $name ) . ' 2>/dev/null' ) );
	return $path;
}

function setting( string $name, int $default ) : int {
	$value = (int) \getenv( $name );
	if ( $value > 0 ) {
		return $value;
	}

	return $default;
}

// Sends one request and returns its status, headers and body.
function fetch( string $method, string $path, string $router, array $headers = [] ) : array {
	$lines = [ 'X-Bench-Router: ' . $router ];
	foreach ( $headers as $name => $value ) {
		$lines[] = $name . ': ' . $value;
	}

	$context = \stream_context_create( [ 'http' => [ 'method' => $method, 'header' => $lines, 'ignore_errors' => true ] ] );
	$body = (string) \file_get_contents( 'http://127.0.0.1:' . PORT . $path, false, $context );
	$response_headers = \http_get_last_response_headers() ?? [];
	$status = (int) \explode( ' ', $response_headers[0] ?? '' )[1];

	$response = [ 'status' => $status, 'headers' => $response_headers, 'body' => $body ];
	return $response;
}

// Runs oha and returns its JSON summary.
function oha( string $oha, string $method, string $path, string $router, int $seconds, int $connections ) : array {
	$command = [ $oha, '--no-tui', '--output-format', 'json', '-z', $seconds . 's', '-c', (string) $connections, '-m', $method, '-H', 'X-Bench-Router: ' . $router, 'http://127.0.0.1:' . PORT . $path ];
	$json = (string) \shell_exec( \implode( ' ', \array_map( 'escapeshellarg', $command ) ) );
	$result = \json_decode( $json, true );
	if ( ! \is_array( $result ) ) {
		fail( 'oha gave no results for ' . $router . ' ' . $method . ' ' . $path . ":\n" . $json );
	}

	return $result;
}

// The median of the tillkite- metric's durations in the Server-Timing header
// over sequential requests, in microseconds.
function median_timing( string $method, string $path, string $router, string $metric, int $count ) : float {
	$durations = [];
	for ( $i = 0; $i < $count; $i++ ) {
		$headers = \implode( "\n", fetch( $method, $path, $router, [ 'X-Bench-Timing' => '1' ] )['headers'] );
		if ( \preg_match( '/tillkite-' . $metric . ';dur=([\d.]+)/', $headers, $match ) === 1 ) {
			$durations[] = (float) $match[1] * 1000;
		}
	}

	\sort( $durations );
	$median = $durations[\intdiv( \count( $durations ), 2 )] ?? 0.0;
	return $median;
}

$nginx = find_program( 'nginx' );
$oha = find_program( 'oha' );
$fpm = (string) \getenv( 'PHP_FPM' );
if ( $fpm === '' ) {
	$fpm = find_program( 'php-fpm' );
}

// Homebrew's PHP puts php-fpm in sbin, next to the php binary's bin.
if ( $fpm === '' && \is_file( \dirname( \PHP_BINARY, 2 ) . '/sbin/php-fpm' ) ) {
	$fpm = \dirname( \PHP_BINARY, 2 ) . '/sbin/php-fpm';
}

if ( $nginx === '' || $oha === '' || $fpm === '' ) {
	fail( "bench/load.php needs nginx, php-fpm and oha. make load runs it in Docker, which has all three.\nSet PHP_FPM to php-fpm's path if it isn't on the PATH." );
}

$seconds = setting( 'LOAD_SECONDS', 30 );
$connections = setting( 'LOAD_CONNECTIONS', 32 );
$workers = setting( 'LOAD_WORKERS', 8 );

// A new directory for the configuration, caches, socket and logs, which only
// this user can use. php-fpm runs the PHP in it, so under a shared /tmp, a
// name that another user could make first would let them choose that PHP.
// mkdir() fails when the name is taken. nginx won't use a Unix socket whose
// path is longer than about 100 bytes.
$dir = \sys_get_temp_dir() . '/tillkite-load-' . \bin2hex( \random_bytes( 8 ) );
if ( \strlen( $dir . '/php-fpm.sock' ) > 100 ) {
	fail( 'The php-fpm socket path is too long for nginx: ' . $dir . "/php-fpm.sock\nUse a shorter temporary directory, like: TMPDIR=/tmp make load" );
}

if ( ! \mkdir( $dir, 0o700 ) ) {
	fail( "Couldn't make " . $dir );
}

\mkdir( $dir . '/cache', 0o755 );

// A document root with nothing in it. Tillkite refuses a site whose handlers
// or cache are inside it.
\mkdir( $dir . '/public', 0o755 );

// The GitHub API's routes, for each router's cache and for Tillkite App's
// routes file. Every route runs the same handler.
$github = require __DIR__ . '/routes/github.php';
$entries = [];
foreach ( $github as [ $method, $path ] ) {
	$entries[] = [ $method, $path, 'handler.php' ];
}

\file_put_contents( $dir . '/routes.php', '<?php return ' . \var_export( $entries, true ) . ";\n" );
\file_put_contents( $dir . '/handler.php', "<?php\necho \"ok\\n\";\n" );
\touch( $dir . '/routes.php', \time() - 60 );
\touch( $dir . '/handler.php', \time() - 60 );

$routers = routers( $dir );
foreach ( $routers as $name => $router ) {
	$router->build( $github, $dir . '/' . \md5( $name ) . '.php' );
}

// Composer's class loader with every installed class in an authoritative
// classmap, as composer install --classmap-authoritative makes it, but
// without the files that vendor/autoload.php includes on every request, 21
// of them, for this project's development packages, which no site has, and
// which build $_SERVER. load-front.php includes the files each router's own
// package needs. The classmap is a constant array, which OPcache keeps
// without copying it.
\file_put_contents( $dir . '/autoload.php', \sprintf(
	"<?php\nrequire %s;\n\$loader = new \\Composer\\Autoload\\ClassLoader();\n\$loader->addClassMap( %s );\n\$loader->setClassMapAuthoritative( true );\n\$loader->register( true );\n",
	\var_export( \dirname( __DIR__ ) . '/vendor/composer/ClassLoader.php', true ),
	\var_export( require \dirname( __DIR__ ) . '/vendor/composer/autoload_classmap.php', true )
) );
\touch( $dir . '/autoload.php', \time() - 60 );

$front = __DIR__ . '/load-front.php';
$port = PORT;
\file_put_contents( $dir . '/php-fpm.conf', <<<CONF
	[global]
	pid = {$dir}/php-fpm.pid
	error_log = {$dir}/php-fpm.log
	daemonize = no

	[bench]
	listen = {$dir}/php-fpm.sock
	pm = static
	pm.max_children = {$workers}
	php_admin_value[error_log] = {$dir}/php-errors.log
	php_admin_flag[log_errors] = on
	php_admin_flag[display_errors] = off

	CONF );

// nginx's fastcgi.conf passes DOCUMENT_ROOT, so this does, and Tillkite has
// one to check its directories against, as it does for a site.
\file_put_contents( $dir . '/nginx.conf', <<<CONF
	worker_processes 2;
	pid {$dir}/nginx.pid;
	error_log {$dir}/nginx-error.log;
	events {
		worker_connections 1024;
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
			location / {
				fastcgi_param DOCUMENT_ROOT {$dir}/public;
				fastcgi_param SCRIPT_FILENAME {$front};
				fastcgi_param REQUEST_METHOD \$request_method;
				fastcgi_param REQUEST_URI \$request_uri;
				fastcgi_param QUERY_STRING \$query_string;
				fastcgi_param SERVER_PROTOCOL \$server_protocol;
				fastcgi_param BENCH_DIR {$dir};
				fastcgi_pass unix:{$dir}/php-fpm.sock;
			}
		}
	}

	CONF );

$quiet = [ [ 'file', '/dev/null', 'r' ], [ 'file', $dir . '/servers.log', 'a' ], [ 'file', $dir . '/servers.log', 'a' ] ];
$servers = [
	\proc_open( [ $fpm, '-F', '-y', $dir . '/php-fpm.conf', '-d', 'opcache.enable=1' ], $quiet, $pipes ),
	\proc_open( [ $nginx, '-p', $dir, '-c', $dir . '/nginx.conf', '-e', $dir . '/nginx-error.log', '-g', 'daemon off;' ], $quiet, $pipes ),
];
\register_shutdown_function( function() use ( $servers ) : void {
	foreach ( $servers as $server ) {
		if ( \is_resource( $server ) ) {
			\proc_terminate( $server );
			\proc_close( $server );
		}
	}
} );

// Waits up to five seconds for both to answer.
$ready = false;
for ( $i = 0; $i < 500 && ! $ready; $i++ ) {
	\usleep( 10_000 );
	$socket = \stream_socket_client( 'tcp://127.0.0.1:' . PORT, $errno, $error, 0.1 );
	// is_file() is false for a socket.
	if ( $socket !== false && \file_exists( $dir . '/php-fpm.sock' ) ) {
		\fclose( $socket );
		$ready = fetch( 'GET', '/', 'PHP alone' )['body'] === "ok\n";
	}
}

if ( ! $ready ) {
	fail( "nginx and php-fpm didn't start. Their logs are in " . $dir );
}

$requests = requests_for( $github );
$runs = [ [ 'PHP alone', 'GET', '/', 200, "ok\n" ] ];
// Tillkite is the first router, and its dist file row comes right after it.
$names = \array_keys( $routers );
\array_splice( $names, 1, 0, [ 'Tillkite, dist file' ] );
foreach ( [ 'Tillkite App', 'Tillkite App, dist file', ...$names ] as $name ) {
	foreach ( [ 'last', 'miss' ] as $label ) {
		[ [ $method, $path ], $route ] = $requests[$label];
		$status = 200;
		$body = "ok\n";
		if ( $route === -1 ) {
			$status = 404;
			$body = "404 Not Found\n";
		}

		$runs[] = [ $name, $method, $path, $status, $body ];
	}
}

foreach ( $runs as [ $name, $method, $path, $status, $body ] ) {
	$response = fetch( $method, $path, $name );
	if ( $response['status'] !== $status || $response['body'] !== $body ) {
		fail( \sprintf( '%s: %s %s got %d %s, expected %d %s', $name, $method, $path, $response['status'], \json_encode( $response['body'] ), $status, \json_encode( $body ) ) );
	}
}

\preg_match( '/^PHP (\S+)/', (string) \shell_exec( \escapeshellarg( $fpm ) . ' -v' ), $version );
\printf(
	"nginx and PHP %s php-fpm (%d workers, OPcache on), oha with %d connections for %d seconds each\nThe GitHub API's routes (%s). Requests per second: higher is better. Latency in ms.\n\n%-27s %-6s %12s %8s %8s\n",
	$version[1] ?? '?',
	$workers,
	$connections,
	$seconds,
	\number_format( \count( $github ) ),
	'',
	'',
	'Requests/s',
	'p50',
	'p99'
);

foreach ( $runs as [ $name, $method, $path, $status ] ) {
	oha( $oha, $method, $path, $name, WARM_UP, $connections );
	$result = oha( $oha, $method, $path, $name, $seconds, $connections );
	$codes = $result['statusCodeDistribution'] ?? [];
	if ( \array_keys( $codes ) !== [ (string) $status ] && \array_keys( $codes ) !== [ $status ] ) {
		fail( \sprintf( '%s: %s %s got statuses %s, expected only %d', $name, $method, $path, \json_encode( $codes ), $status ) );
	}

	$label = 'last';
	if ( $status === 404 ) {
		$label = 'miss';
	}

	if ( $name === 'PHP alone' ) {
		$label = '';
	}

	\printf(
		"%-27s %-6s %12s %8.3f %8.3f\n",
		$name,
		$label,
		\number_format( $result['summary']['requestsPerSec'] ),
		$result['latencyPercentiles']['p50'] * 1000,
		$result['latencyPercentiles']['p99'] * 1000
	);
}

// Tillkite's own share of each request, from its Server-Timing header.
\printf( "\nTillkite App's own time per request, from its Server-Timing header\nMedian of 1,000 requests, in µs, to the nearest µs\n%-33s %6s %6s\n", '', 'total', 'route' );
foreach ( [ 'Tillkite App', 'Tillkite App, dist file' ] as $name ) {
	foreach ( [ 'last', 'miss' ] as $label ) {
		[ [ $method, $path ] ] = $requests[$label];
		\printf( "%-26s %-6s %6.0f %6.0f\n", $name, $label, median_timing( $method, $path, $name, 'total', 1000 ), median_timing( $method, $path, $name, 'route', 1000 ) );
	}
}

// The run ended well, so nothing in the directory is needed.
\shell_exec( 'rm -rf ' . \escapeshellarg( $dir ) );
