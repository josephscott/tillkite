<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Routes each web request to a PHP file.
 *
 * Nothing is thrown. Problems with the options or the routes are collected,
 * and run() reports them with a 500.
 */
class App {
	// The PHP file that returns the routes, as an absolute path.
	public string $routes = '';

	// The directory for the compiled route cache, as an absolute path. Empty
	// means the routes are compiled on every request.
	public string $cache = '';

	// The directory that handler, before and error files are relative to, as
	// an absolute path. Empty means the directory of the routes file.
	public string $handlers = '';

	// The directory that template() names are relative to, as an absolute
	// path. Empty means the handlers directory.
	public string $templates = '';

	// The URL prefix the app is mounted under, like /app.
	public string $base = '';

	// false: URLs never end in /. true: they always do.
	public bool $trailing_slash = false;

	// Buffers the output of the files that run, so they can send headers
	// after it.
	public bool $buffer = true;

	// Sends the times of Tillkite's steps in a Server-Timing header.
	public bool $server_timing = false;

	// The file that runs before every handler.
	public string $before = '';

	// The files for 404, 405, 500 and the other 4xx codes, like
	// [ 404 => '404.php' ].
	public array $errors = [];

	// Custom HTTP methods to allow besides the standard ones, like [ 'PURGE' ].
	public array $methods = [];

	// Each option and the type it takes.
	private const array OPTIONS = [
		'routes' => 'string',
		'cache' => 'string',
		'handlers' => 'string',
		'templates' => 'string',
		'base' => 'string',
		'trailing_slash' => 'bool',
		'buffer' => 'bool',
		'server_timing' => 'bool',
		'before' => 'string',
		'errors' => 'array',
		'methods' => 'array',
	];

	// The codes the errors option takes, each with the text of its plain-text
	// response: 500, and the standard 4xx codes. error() takes the 4xx codes
	// other than 405. Compiler checks the errors option against it.
	public const array REASONS = [
		400 => 'Bad Request',
		401 => 'Unauthorized',
		402 => 'Payment Required',
		403 => 'Forbidden',
		404 => 'Not Found',
		405 => 'Method Not Allowed',
		406 => 'Not Acceptable',
		407 => 'Proxy Authentication Required',
		408 => 'Request Timeout',
		409 => 'Conflict',
		410 => 'Gone',
		411 => 'Length Required',
		412 => 'Precondition Failed',
		413 => 'Content Too Large',
		414 => 'URI Too Long',
		415 => 'Unsupported Media Type',
		416 => 'Range Not Satisfiable',
		417 => 'Expectation Failed',
		421 => 'Misdirected Request',
		422 => 'Unprocessable Content',
		423 => 'Locked',
		424 => 'Failed Dependency',
		425 => 'Too Early',
		426 => 'Upgrade Required',
		428 => 'Precondition Required',
		429 => 'Too Many Requests',
		431 => 'Request Header Fields Too Large',
		451 => 'Unavailable For Legal Reasons',
		500 => 'Internal Server Error',
	];

	// Tillkite's version. A release sets it.
	private const string VERSION = '0.0.0';

	// The errors that end a request without an exception, such as running
	// out of memory or time, or an uncaught exception with no 500 file.
	//
	// The leading \ lets PHP work out the value when it compiles the class.
	// In a namespace, E_ERROR could be Tillkite\E_ERROR, so without it PHP
	// works it out at run time, on the first new App() of every request.
	private const int FATAL_ERRORS = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR | \E_RECOVERABLE_ERROR;

	private array $problems = [];

	// The problems with how the site is set up on its server, like a cache
	// directory that doesn't exist, each described without its paths, for the
	// page that report_problems() sends in place of the site's own.
	private array $setup = [];

	// The matched route, as route() returns it.
	private ?array $route = null;

	// The request's URI and method, as uri() and method() return them, read
	// when run() or they first need them.
	private ?string $uri = null;

	private ?string $method = null;

	// The values that register() was given, by name.
	private array $registered = [];

	// The parameters of the files that are running, for the error files.
	private array $params = [];

	// True while the before-file and handler run, the only time error() does
	// anything.
	private bool $in_route_files = false;

	// The code that a before-file or handler passed to error().
	private ?int $error_code = null;

	// Set when run() starts.
	private ?Timer $timer = null;

	private bool $timing_sent = false;

	// The output buffer level from before Tillkite's own buffer, or null when
	// the files that are running aren't buffered.
	private ?int $level = null;

	// Sets the option with each key's name. An unknown key, or a value of the
	// wrong type, is a problem that run() reports.
	public function __construct( array $options = [] ) {
		foreach ( $options as $name => $value ) {
			$type = self::OPTIONS[$name] ?? null;
			if ( $type === null ) {
				$this->problems[] = 'Unknown option: ' . $name;
				continue;
			}

			if ( \get_debug_type( $value ) !== $type ) {
				$article = 'a';
				if ( $type === 'array' ) {
					$article = 'an';
				}

				$this->problems[] = 'The ' . $name . ' option must be ' . $article . ' ' . $type . ', not ' . \get_debug_type( $value );
				continue;
			}

			$this->$name = $value;
		}
	}

	// Setting a property that doesn't exist, or a private one, is a problem
	// that run() reports.
	public function __set( string $name, mixed $value ) : void {
		$this->problems[] = 'Unknown option: ' . $name;
	}

	/**
	 * Handles the web request. Returns false only under php -S, for a file
	 * that php -S should serve itself.
	 */
	public function run() : bool {
		$this->timer = new Timer();

		// PHP's messages hold file paths, and can hold passwords, and its default
		// is to print them. So only php -S, for the developer, and the command
		// line keep them. A host that locks the setting, or disables ini_set(),
		// which would be a fatal error to call, has the last word.
		if ( \PHP_SAPI !== 'cli-server' && \PHP_SAPI !== 'cli' && \function_exists( 'ini_set' ) ) {
			\ini_set( 'display_errors', '0' );
		}

		$path = $this->uri();
		$query = '';
		$mark = \strpos( $path, '?' );
		if ( $mark !== false ) {
			$query = \substr( $path, $mark );
			$path = \substr( $path, 0, $mark );
		}

		if ( \PHP_SAPI === 'cli-server' && $this->is_static_file( $path ) ) {
			return false;
		}

		$compiled = $this->compile();
		if ( $this->problems !== [] ) {
			$this->report_problems();
			return true;
		}

		\register_shutdown_function( $this->shutdown( ... ) );
		$this->respond( $compiled, $this->method(), $path, $query );
		$this->finish_timing();
		return true;
	}

	// Matches the request and runs the route's before-files and handler.
	// When matching fails, the response is a 500. A miss runs the app's
	// before-file too, and only then gets its response, so a before-file
	// that checks a login hides which paths exist.
	private function respond( array $compiled, string $method, string $path, string $query ) : void {
		$result = $this->match( $compiled, $method, $path );
		if ( $result['status'] === 200 ) {
			$this->route = $compiled['routes'][$result['route']];
			$files = $compiled['files'][$result['route']] ?? $compiled['default_files'];
			$this->run_route( $this->route[2], $files, $result['params'] );
			return;
		}

		if ( $result['status'] === 500 ) {
			\error_log( 'Tillkite: matching the request failed: ' . $result['error'] );
			$this->server_error( 'Matching the request failed: ' . $result['error'] );
			return;
		}

		// A miss runs what a route with no options of its own would.
		$files = $compiled['default_files'];
		if ( $files[0] !== '' && ! $this->run_miss_before( $files ) ) {
			return;
		}

		$this->miss( $result, $query );
	}

	// Removes base from the path, then matches the path. With base set, the
	// root URL is /app or /app/, following trailing_slash, and the other form
	// redirects to it.
	private function match( array $compiled, string $method, string $path ) : array {
		$result = [ 'status' => 404 ];
		if ( $this->base !== '' ) {
			$root = '';
			if ( $this->trailing_slash ) {
				$root = '/';
			}

			if ( $path === $this->base || $path === $this->base . '/' ) {
				if ( $path !== $this->base . $root ) {
					$result = [ 'status' => 308, 'path' => $root ];
					return $result;
				}

				$path = '/';
			} elseif ( \str_starts_with( $path, $this->base . '/' ) ) {
				$path = \substr( $path, \strlen( $this->base ) );
			} else {
				return $result;
			}
		}

		$matcher = new Matcher( $compiled );
		$result = $matcher->match( $method, $path );
		return $result;
	}

	// Runs a route's before-files and handler. Compiler has worked out the
	// before-files, up to three, and the buffer option. See
	// Compiler::compile().
	private function run_route( string $handler, array $files, array $params ) : void {
		$this->start( $files[3] ?? $this->buffer, $params );
		$next = $this->next_file( ... );
		if ( $files[0] !== '' ) {
			$this->timer->next( 'before' );
			$next = $this->next_after_before( ... );
		}

		$this->in_route_files = true;
		Runner::files( $this, $params, $files[0], $files[1], $files[2], $this->handlers . '/' . $handler, $next );
		$this->in_route_files = false;
		if ( $this->error_code !== null ) {
			$this->show_error( $this->error_code );
			return;
		}

		$this->finish();
	}

	// Runs a miss's before-files, with no handler after them. They're always
	// buffered, because what they print is thrown away before the response.
	// Returns true when they all ran. When one returned false or set a
	// redirect or error status, what they printed is sent instead, and when
	// one called error(), its page is.
	private function run_miss_before( array $files ) : bool {
		$this->start( true, [] );
		$this->timer->next( 'before' );
		$ran = false;
		$this->in_route_files = true;
		Runner::files( $this, [], $files[0], $files[1], $files[2], '', function( string $step ) use ( &$ran ) : bool {
			if ( $step === 'handler' ) {
				$ran = \http_response_code() < 300;
				return false;
			}

			$next = $this->next_after_before( $step );
			return $next;
		} );
		$this->in_route_files = false;
		if ( $this->error_code !== null ) {
			$this->show_error( $this->error_code );
			return false;
		}

		if ( ! $ran ) {
			$this->finish();
			return $ran;
		}

		$this->discard_output();
		return $ran;
	}

	// The response when no route matches: a 308 redirect, or the 404 or 405
	// page. A Location that starts with // or /\ would go to another site, so
	// that request gets a 404 instead. The path and query come from the
	// request, so anything in them that isn't printable ASCII, which a web
	// server may pass on, is percent-encoded, and the header holds only what
	// a URL can. A tab, which browsers drop, can't make /<tab>/ into //.
	private function miss( array $result, string $query ) : void {
		$status = $result['status'];
		if ( $status === 308 ) {
			$location = $this->base . $result['path'];
			if ( ! \str_starts_with( $location, '//' ) && ! \str_starts_with( $location, '/\\' ) ) {
				\http_response_code( 308 );
				\header( 'Location: ' . \preg_replace_callback( '/[^\x21-\x7E]/', static fn ( array $byte ) : string => \rawurlencode( $byte[0] ), $location . $query ) );
				return;
			}
		}

		$code = 404;
		if ( $status === 405 ) {
			$code = 405;
			\header( 'Allow: ' . \implode( ', ', $result['allow'] ) );
		}

		$this->run_error( $code, [] );
	}

	// Runs the error file for a 4xx code, in a scope of its own, or sends
	// plain text. The 404 and 405 files run here when no route matches, and
	// any 4xx file runs here for error(), with the route's parameters.
	private function run_error( int $code, array $params ) : void {
		$file = $this->errors[$code] ?? '';
		if ( $file === '' ) {
			$this->plain( $code );
			return;
		}

		\http_response_code( $code );
		$this->start( $this->buffer, $params );
		Runner::files( $this, $params, '', '', '', $this->handlers . '/' . $file, fn () : bool => $this->next_file( 'error' ) );
		$this->finish();
	}

	// Shows the error page a before-file or handler asked for with error(),
	// once its file has returned. What's buffered is thrown away. Output
	// that's already been sent can't be taken back, or its status changed,
	// so then it's logged and nothing is added. A code that error() doesn't
	// take is logged, and the response is a 500.
	private function show_error( int $code ) : void {
		$this->error_code = null;
		$problem = '';
		if ( $code === 405 || $code === 500 || ! isset( self::REASONS[$code] ) ) {
			$problem = 'error() takes a 4xx code other than 405, like 404 or 403, not ' . $code;
			\error_log( 'Tillkite: ' . Escape::log( $problem ) );
		}

		if ( \headers_sent() ) {
			\error_log( 'Tillkite: error( ' . $code . ' ) came after the output had started, so nothing was added to it' );
			$this->finish();
			return;
		}

		$this->discard_output();
		if ( $problem !== '' ) {
			$this->server_error( $problem );
			return;
		}

		$this->run_error( $code, $this->params );
	}

	// Runner calls it before each before-file after the first, with 'before',
	// and before the handler or error file. It returns false once a file has
	// called error(), so no file after that one runs. Before the handler or
	// error file, it starts that step's timing. When the files aren't
	// buffered, that file's output may send the headers, so the
	// Server-Timing header goes out now.
	private function next_file( string $step ) : bool {
		$next = $this->error_code === null;
		if ( $next && $step !== 'before' ) {
			$this->timer->next( $step );
			if ( $this->level === null ) {
				$this->send_timing();
			}
		}

		return $next;
	}

	// Runner calls it in next_file()'s place after a before-file. A
	// before-file that redirects or sets an error status has answered the
	// request, even if it doesn't return false, like a login check with only
	// header( 'Location: /sign-in' ), which sets a 302. Going on would send
	// the page to the visitor it turned away. A status under 300 goes on, like
	// a Location with a 201.
	private function next_after_before( string $step ) : bool {
		$next = \http_response_code() < 300 && $this->next_file( $step );
		return $next;
	}

	// Starts the buffer, if there is one, and sends exceptions that the files
	// don't catch to the 500 file, if there is one.
	private function start( bool $buffer, array $params ) : void {
		$this->params = $params;
		$this->level = null;
		if ( $buffer ) {
			$this->level = \ob_get_level();
			\ob_start();
		}

		if ( isset( $this->errors[500] ) ) {
			\set_exception_handler( $this->run_500( ... ) );
		}
	}

	// Runs the 500 file, as the handler for exceptions that the files don't
	// catch, or from server_error(). Output that's still buffered is thrown
	// away. Output that's already been sent stays, so the error is logged and
	// the 500 file's output is added.
	private function run_500( \Throwable $error ) : void {
		$this->in_route_files = false;
		$this->discard_output();
		if ( \headers_sent() ) {
			\error_log( 'Tillkite: uncaught ' . Escape::log( (string) $error ) );
		} else {
			\http_response_code( 500 );
		}

		// The 500 file isn't buffered, so the Server-Timing header goes out
		// before it runs.
		$this->timer->next( 'error' );
		$this->send_timing();
		Runner::error_file( $this, $this->params, $error, $this->handlers . '/' . $this->errors[500] );
	}

	// A 500 that isn't an uncaught exception: the 500 file gets an exception
	// that describes it, which Tillkite creates but never throws. With a
	// broken configuration, the 500 file runs only if it's there to run.
	private function server_error( string $message ) : void {
		$file = $this->errors[500] ?? null;
		if ( \is_string( $file ) && $file !== '' && $this->is_absolute( $this->handlers ) && \is_file( $this->handlers . '/' . $file ) ) {
			$this->run_500( new \RuntimeException( $message ) );
			return;
		}

		$this->plain( 500 );
	}

	// Ends the timing, and sends what's buffered. A file may have ended
	// Tillkite's buffer itself, or left buffers of its own, so this ends
	// whatever is above the level from before Tillkite's.
	private function finish() : void {
		$this->finish_timing();
		if ( $this->level === null ) {
			return;
		}

		while ( \ob_get_level() > $this->level ) {
			\ob_end_flush();
		}
	}

	// Throws away what's buffered, ending Tillkite's buffer and any above it.
	private function discard_output() : void {
		if ( $this->level === null ) {
			return;
		}

		while ( \ob_get_level() > $this->level ) {
			\ob_end_clean();
		}
	}

	// Returns the request's value for a name in $_SERVER, like REQUEST_URI,
	// or null.
	//
	// PHP builds $_SERVER, from everything the web server sent, when it loads
	// a file that names it, which takes about 3 µs a request under php-fpm,
	// and OPcache builds it when it loads a file that it compiled while
	// $_SERVER existed. So Tillkite's code never names it, and a site whose
	// files don't either never pays for it. Once something has built it, the
	// site may have changed it, so it's read then. Until then, php-fpm's
	// getenv() has the same values, from the request. php -S has none, and
	// its filter_input() returns null, but it builds $_SERVER.
	private static function server( string $name ) : ?string {
		if ( isset( $GLOBALS['_SERVER'] ) ) {
			$value = $GLOBALS['_SERVER'][$name] ?? null;
			return $value;
		}

		$value = \getenv( $name );
		if ( $value === false ) {
			$value = \filter_input( \INPUT_SERVER, $name ) ?? $GLOBALS['_SERVER'][$name] ?? null;
		}

		return $value;
	}

	// Returns the matched route as written in the routes file, or null if
	// nothing matched.
	public function route() : ?array {
		$route = $this->route;
		return $route;
	}

	// Returns the milliseconds for each step that ran. A step that's still
	// running reports its time so far.
	public function timings() : array {
		$timings = [];
		if ( $this->timer !== null ) {
			$timings = $this->timer->timings();
		}

		return $timings;
	}

	// Returns the request's URI as the web server sent it, with its query
	// string and before base is taken off, like '/blog?page=2'. It's
	// $_SERVER['REQUEST_URI'], read without building $_SERVER, which takes
	// about 4 µs, on every request that names it.
	public function uri() : string {
		$uri = $this->uri ??= self::server( 'REQUEST_URI' ) ?? '/';
		return $uri;
	}

	// Returns the request's method, like 'GET' or 'POST'. It's
	// $_SERVER['REQUEST_METHOD'], read without building $_SERVER.
	public function method() : string {
		$method = $this->method ??= self::server( 'REQUEST_METHOD' ) ?? 'GET';
		return $method;
	}

	/**
	 * Runs a template, a PHP file in the templates directory, and returns
	 * what it printed:
	 *
	 *     echo $tillkite->template( 'post.php', [ 'post' => $post ] );
	 *
	 * It runs in a scope of its own, like a handler's, with only $tillkite
	 * and $data, the array passed here. A template can call template() too,
	 * for a layout or a part of a page. It works anywhere, even before
	 * run(). A template that doesn't exist is a fatal error, as require
	 * makes it. While run() is timing the request, timings() has its time.
	 *
	 * The name is a .php file in the templates directory, or a directory in
	 * it, like 'posts/card.php'. Any other name, like one with a .. segment
	 * or one for a file that isn't PHP, runs nothing: it logs that, and
	 * returns ''.
	 */
	public function template( string $template, array $data = [] ) : string {
		// PHP resolves .. itself, even after a directory that doesn't exist,
		// so 'posts/../../.env' would print .env. require throws for a NUL.
		if ( ! \str_ends_with( $template, '.php' ) || \str_contains( $template, "\0" ) || ( \str_contains( $template, '..' ) && \preg_match( '{(?:^|[/\\\\])\.\.(?:[/\\\\]|$)}', $template ) === 1 ) ) {
			\error_log( "Tillkite: template() takes a .php file in the templates directory, so it ran nothing for '" . Escape::log( $template ) . "', and returned ''" );
			$output = '';
			return $output;
		}

		$dir = $this->templates;
		if ( $dir === '' ) {
			$dir = $this->handlers;
		}

		// Before run(), the handlers directory may not be worked out yet.
		if ( $dir === '' ) {
			$dir = \dirname( $this->routes );
		}

		// While run() is timing the request, the template's own time is a step
		// named after it, like template:layout.php, which takes that time out
		// of the step that ran the template. A template that runs another
		// gets the time back when that one returns.
		$timer = $this->timer;
		$previous = '';
		if ( $timer !== null && $timer->running() ) {
			$previous = $timer->next( 'template:' . $template );
		}

		// If the template throws, what it printed is thrown away, with any
		// buffers it left, and the exception goes on to the caller. Nothing
		// is caught. Buffers it left when it returns are its output too.
		$output = '';
		$level = \ob_get_level();
		\ob_start();
		try {
			Runner::template( $this, $data, $dir . '/' . $template );
			while ( \ob_get_level() > $level + 1 ) {
				\ob_end_flush();
			}

			if ( \ob_get_level() > $level ) {
				$output = (string) \ob_get_clean();
			}
		} finally {
			while ( \ob_get_level() > $level ) {
				\ob_end_clean();
			}

			if ( $timer !== null && $previous !== '' ) {
				$timer->next( $previous );
			}
		}

		return $output;
	}

	/**
	 * Makes a value, like a database connection, available to the
	 * before-files, handlers, error files and templates, which get it with
	 * get():
	 *
	 *     $tillkite->register( 'db', $db );
	 *
	 * Registering a name again replaces its value. It works anywhere, so a
	 * before-file can register a value for the handler and templates, like
	 * the visitor's account.
	 */
	public function register( string $name, mixed $value ) : void {
		$this->registered[$name] = $value;
	}

	/**
	 * Returns the value registered as $name:
	 *
	 *     $db = $tillkite->get( 'db' );
	 *
	 * When nothing is registered as $name, it logs that, and returns null.
	 */
	public function get( string $name ) : mixed {
		$value = $this->registered[$name] ?? null;
		if ( $value === null && ! \array_key_exists( $name, $this->registered ) ) {
			\error_log( "Tillkite: nothing is registered as '" . Escape::log( $name ) . "', so get() returned null" );
		}

		return $value;
	}

	/**
	 * Returns the real path of a file in a directory, for a name that comes
	 * from a request, like a parameter, or null when no file by that name is
	 * inside the directory:
	 *
	 *     $file = $tillkite->file( __DIR__ . '/../docs', $params['path'] . '.md' );
	 *     if ( $file === null ) {
	 *         return $tillkite->error( 404 );
	 *     }
	 *
	 *     readfile( $file );
	 *
	 * The name is the file's, or its path from the directory, like
	 * 'guide/install.md'. Whatever it holds, the path that comes back is a
	 * file inside the directory, with every symlink followed: null is the
	 * answer for a name with an empty, . or .. segment, between / or \, so
	 * one that starts with a / or has a stream wrapper's ://, for one with a
	 * null byte, for a directory, and for a symlink, or a file under one, that
	 * leads outside the directory. Open the path it returns, not the name, so
	 * what's opened is what was checked. It works anywhere, even before run().
	 *
	 * PHP remembers what realpath() found, for realpath_cache_ttl seconds,
	 * 120 unless php.ini says otherwise, so a file or a directory that was
	 * swapped for a symlink since PHP last looked would pass as what it was.
	 * So each part of the name is forgotten first, and realpath() looks again.
	 *
	 * The directory is an absolute path, built from __DIR__, like the options.
	 * Another is logged, and gets null.
	 */
	public function file( string $directory, string $name ) : ?string {
		$file = null;
		if ( ! $this->is_absolute( $directory ) ) {
			\error_log( "Tillkite: file() takes its directory as an absolute path, built from __DIR__, so it returned null for '" . Escape::log( $directory ) . "'" );
			return $file;
		}

		// realpath() throws for a null byte.
		if ( \str_contains( $directory . $name, "\0" ) ) {
			return $file;
		}

		$root = \realpath( $directory );
		if ( $root === false ) {
			return $file;
		}

		$root = \rtrim( $root, '/\\' );
		$path = $root;
		foreach ( \explode( '/', \strtr( $name, '\\', '/' ) ) as $part ) {
			if ( $part === '' || $part === '.' || $part === '..' ) {
				return $file;
			}

			$path .= \DIRECTORY_SEPARATOR . $part;
			\clearstatcache( true, $path );
		}

		$real = \realpath( $path );
		if ( $real !== false && \is_file( $real ) && \str_starts_with( $real, $root . \DIRECTORY_SEPARATOR ) ) {
			$file = $real;
		}

		return $file;
	}

	/**
	 * Shows the error page for a 4xx code other than 405, like 404 or 403,
	 * from a before-file or handler:
	 *
	 *     return $tillkite->error( 404 );
	 *
	 * Once the file that called it returns, no file after it runs. What the
	 * files have buffered is thrown away, and the file for that code in the
	 * errors option runs, with the same $params, or a plain-text response is
	 * sent. It returns false, so returning it also stops a before-file the
	 * usual way. Only the first call counts, and a call from anywhere else,
	 * like an error file, does nothing but log a warning.
	 */
	public function error( int $code ) : false {
		if ( ! $this->in_route_files ) {
			\error_log( 'Tillkite: error() works only in a before-file or handler, so error( ' . $code . ' ) did nothing' );
			return false;
		}

		$this->error_code ??= $code;
		return false;
	}

	// Under php -S, an existing file inside the document root, other than
	// the script that called run(), is left for php -S to serve. realpath()
	// resolves .. and symlinks first, so nothing outside the document root
	// gets through.
	//
	// A PHP file isn't left for it, by the path's name or the file's, since
	// php -S would run it, without the before-files. It's routed like a path
	// for no file, so the script that called run() is the only PHP a request
	// runs, as with the nginx configuration the README gives.
	//
	// The script is the file at the bottom of the call stack. The first
	// included file is a file from auto_prepend_file when that's set, and
	// SCRIPT_FILENAME is the requested file when it exists.
	//
	// realpath() would throw on a null byte, so a path with one is routed.
	private function is_static_file( string $path ) : bool {
		$static = false;
		$path = \rawurldecode( $path );
		$root = \realpath( self::server( 'DOCUMENT_ROOT' ) ?? '' );
		if ( $root === false || \str_contains( $path, "\0" ) ) {
			return $static;
		}

		$frames = \debug_backtrace( \DEBUG_BACKTRACE_IGNORE_ARGS );
		$script = \end( $frames )['file'] ?? '';
		$file = \realpath( $root . $path );
		$static = $file !== false && $file !== $script && \is_file( $file ) && \str_starts_with( $file, $root . \DIRECTORY_SEPARATOR )
			&& ! \str_ends_with( \strtolower( $path ), '.php' ) && ! \str_ends_with( \strtolower( $file ), '.php' );
		return $static;
	}

	// A file inside the document root can be asked for by its own path. A web
	// server that runs the PHP files it finds there, as php -S and most
	// Apache hosts do, then runs a handler without its before-files, like a
	// login check, and one that doesn't sends the handler's source. Either
	// sends whatever else is in the cache directory, like a database. So
	// each of these directories has to be outside it, and one that isn't is
	// a problem with the site's setup, which the 500 page describes. See
	// report_problems(). realpath() resolves symlinks first, like a release's.
	//
	// It's checked only when the routes are compiled, which a site with a
	// cache does once, since routes with problems are never cached. The
	// command line has no document root.
	//
	// The document root comes from $_SERVER when something has built it, as
	// run() has under php -S, or from getenv(), and never from
	// filter_input(), server()'s last resort, which builds $_SERVER. A web
	// server that passes no DOCUMENT_ROOT would have PHP build it here, and
	// OPcache build it again each time it loads a file it compiled after
	// that, on every request. Nothing is checked there.
	private function check_document_root() : void {
		$root = $GLOBALS['_SERVER']['DOCUMENT_ROOT'] ?? \getenv( 'DOCUMENT_ROOT' );
		if ( ! \is_string( $root ) || $root === '' ) {
			return;
		}

		$root = \realpath( $root );
		if ( $root === false ) {
			return;
		}

		$inside = \rtrim( $root, '/\\' ) . \DIRECTORY_SEPARATOR;
		foreach ( [ 'handlers', 'templates', 'cache' ] as $name ) {
			$path = false;
			if ( $this->$name !== '' ) {
				$path = \realpath( $this->$name );
			}

			if ( $path !== false && \str_starts_with( $path . \DIRECTORY_SEPARATOR, $inside ) ) {
				$this->setup_problem( 'The ' . $name . ' directory is inside the document root, where a request can get or run its files directly, without the before-files. Move it outside the document root, and point the ' . $name . ' option at it', $path . ' is inside ' . $root );
			}
		}
	}

	// Reads the compiled routes from the cache, or loads the routes file and
	// compiles it. Every problem found goes into $problems.
	private function compile() : array {
		$compiled = [];
		if ( $this->base !== '' && ( ! \str_starts_with( $this->base, '/' ) || \str_ends_with( $this->base, '/' ) ) ) {
			$this->problems[] = 'The base option must start with / and not end with /, like /app: ' . $this->base;
		}

		if ( $this->routes === '' ) {
			$this->problems[] = 'The routes option is required.';
			return $compiled;
		}

		if ( $this->handlers === '' ) {
			$this->handlers = \dirname( $this->routes );
		}

		$paths = [ 'routes', 'handlers' ];
		if ( $this->cache !== '' ) {
			$paths[] = 'cache';
		}

		if ( $this->templates !== '' ) {
			$paths[] = 'templates';
		}

		foreach ( $paths as $name ) {
			if ( ! $this->is_absolute( $this->$name ) ) {
				$this->problems[] = 'The ' . $name . ' option must be an absolute path, built from __DIR__: ' . $this->$name;
				return $compiled;
			}
		}

		if ( ! \is_file( $this->routes ) ) {
			$this->problems[] = "The routes file doesn't exist: " . $this->routes;
			return $compiled;
		}

		$cache = null;
		if ( $this->cache !== '' ) {
			// Every setting that changes the compiled routes or the problems found,
			// and Tillkite's code. Encoding only the arrays keeps this quick.
			$settings = self::VERSION . "\n" . $this->handlers . "\n" . $this->before . "\n" . (int) $this->trailing_slash . \json_encode( [ $this->methods, $this->errors ] );
			if ( \PHP_SAPI === 'cli-server' ) {
				$settings .= $this->source_times();
			}

			$cache = new Cache( $this->cache, $this->routes, $settings );
			$cached = $cache->read();
			if ( $cached !== null ) {
				return $cached;
			}

			$cache->clear_routes();

			// A directory the cache can't be used in would have the routes
			// compiled on every request.
			$problem = $cache->setup_problem();
			if ( $problem !== '' ) {
				$this->setup_problem( $problem, $this->cache );
			}
		}

		$this->check_document_root();

		// With a cache, the files that the routes file loads are noted, so a
		// change to one is noticed. Runner has to be loaded first, to not be one.
		$loaded = [];
		if ( $cache !== null ) {
			\class_exists( Runner::class );
			$loaded = \get_included_files();
		}

		$routes = Runner::routes( $this->routes );
		$included = [];
		if ( $cache !== null ) {
			$included = \array_diff( \get_included_files(), $loaded, [ \realpath( $this->routes ) ] );
		}

		if ( ! \is_array( $routes ) ) {
			$this->problems[] = 'The routes file must return an array of routes, not ' . \get_debug_type( $routes );
			return $compiled;
		}

		$compiler = new Compiler();
		$compiler->handlers = $this->handlers;
		$compiler->trailing_slash = $this->trailing_slash;
		$compiler->methods = $this->methods;
		$compiler->errors = $this->errors;
		if ( $this->before !== '' ) {
			$compiler->before = $this->before;
		}

		$compiled = $compiler->compile( $routes );
		\array_push( $this->problems, ...$compiler->problems );
		if ( $cache !== null && $this->problems === [] ) {
			$compiled = $this->write_cache( $cache, $compiled, $included );
		}

		return $compiled;
	}

	// Under php -S, Tillkite's own files may be changing, as they do while
	// someone works on Tillkite. So the route cache's key has their
	// modification times and sizes too, which costs a stat for each.
	// dist/tillkite.php is one file, and src/ has one for each class.
	private function source_times() : string {
		$files = [ __FILE__ ];
		if ( ( new \ReflectionClass( Compiler::class ) )->getFileName() !== __FILE__ ) {
			$files = \glob( __DIR__ . '/*.php' ) ?: [];
		}

		$times = '';
		foreach ( $files as $file ) {
			$times .= "\n" . \filemtime( $file ) . ' ' . \filesize( $file );
		}

		return $times;
	}

	// Routes with problems are never cached, so they're reported until
	// they're fixed. Once written, the cache file is read back, so this
	// request matches with OPcache's copy of the regexes, as later ones
	// will (see Compiler::check_copy()). Routes from a file that has only just
	// changed aren't written yet, so later requests compile them until OPcache
	// is sure to have the new file (see Cache::stamps()).
	private function write_cache( Cache $cache, array $compiled, array $included ) : array {
		$stamps = $cache->stamps( $included, (int) \ini_get( 'opcache.revalidate_freq' ) );
		if ( $stamps === null ) {
			return $compiled;
		}

		$compiled['includes'] = $stamps;
		$error = $cache->write( $compiled );
		if ( $error !== '' ) {
			\error_log( "Tillkite: the route cache can't be written, so the routes are compiled on every request: " . Escape::log( $error ) );
			return $compiled;
		}

		$compiled = $cache->read() ?? $compiled;
		return $compiled;
	}

	// A path from the root, like /var/www, or a Windows drive, like C:\www.
	private function is_absolute( string $path ) : bool {
		$absolute = \str_starts_with( $path, '/' ) || \preg_match( '{^[A-Za-z]:[/\\\\]}', $path ) === 1;
		return $absolute;
	}

	// Adds a problem with how the site is set up on its server. The
	// description says what's wrong and what to do about it, without a
	// path, and $detail has the paths, for the developer.
	private function setup_problem( string $description, string $detail ) : void {
		$this->problems[] = $description . ': ' . $detail;
		$this->setup[] = $description;
	}

	// Under php -S, the response lists the problems, for the developer.
	// Anywhere else, they go to the error log, and the 500 file gets them in
	// $error.
	//
	// A problem with the site's setup is one that whoever put the site on its
	// server has to fix before it's safe to run, and may meet before they
	// know where its error log is. So anywhere else, the response describes
	// each of those, and the site's 500 file, whose page is for visitors,
	// doesn't run. Every visitor gets that page until it's fixed, so it has
	// no paths, which stay in the error log.
	private function report_problems() : void {
		$message = "Tillkite found problems with its configuration:\n\n- " . \implode( "\n- ", $this->problems ) . "\n";
		if ( \PHP_SAPI === 'cli-server' ) {
			$this->plain( 500, $message );
			return;
		}

		foreach ( $this->problems as $problem ) {
			\error_log( 'Tillkite: ' . Escape::log( $problem ) );
		}

		if ( $this->setup !== [] ) {
			$this->plain( 500, "500 Internal Server Error\n\nThis site isn't set up safely, so Tillkite won't run it:\n\n- " . \implode( ".\n- ", $this->setup ) . ".\n\nThe error log has the details.\n" );
			return;
		}

		$this->server_error( $message );
	}

	// A plain-text response: $text, or the code and its reason.
	private function plain( int $code, string $text = '' ) : void {
		if ( $text === '' ) {
			$text = $code . ' ' . self::REASONS[$code] . "\n";
		}

		$this->finish_timing();
		\http_response_code( $code );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		echo $text;
	}

	// Ends the timing, once the response is finished, and sends the
	// Server-Timing header if it hasn't gone out.
	private function finish_timing() : void {
		$this->timer->stop();
		$this->send_timing();
	}

	// Sends the Server-Timing header, with the steps that have finished, if
	// server_timing is on, and it can still go out. It goes out only once.
	private function send_timing() : void {
		if ( ! $this->server_timing || $this->timing_sent || \headers_sent() ) {
			return;
		}

		$this->timing_sent = true;
		\header( 'Server-Timing: ' . $this->timer->header(), false );
	}

	// Runs at the end of every request that's routed. When a file called
	// exit, or a fatal error ended the request, it ends the timing and sends
	// the Server-Timing header, if the headers haven't gone out.
	//
	// A fatal error ends the request without reaching the 500 file. What's
	// still buffered is replaced with one line of text. When PHP displays
	// errors, which takes php -S, a host that keeps run() from turning them
	// off, or a file that turns them back on, its message is in that output,
	// so the output stays and only the status changes. Running out of memory,
	// PHP throws the buffers away itself, and with display_errors on, it has
	// sent its message, and a 200, before this runs.
	private function shutdown() : void {
		$this->finish_timing();
		$error = \error_get_last();
		if ( $error === null || ( $error['type'] & self::FATAL_ERRORS ) === 0 || \headers_sent() ) {
			return;
		}

		$display = \strtolower( (string) \ini_get( 'display_errors' ) );
		if ( ! \in_array( $display, [ '', '0', 'off', 'no', 'false' ], true ) ) {
			\http_response_code( 500 );
			return;
		}

		$this->discard_output();
		$this->plain( 500 );
	}
}
