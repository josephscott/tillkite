<?php
// Tillkite 0.0.0, a small, fast PHP router that maps URLs to PHP files.
//
// This is every class in Tillkite's src/, in one file, for projects that
// don't use Composer: require it, then create a Tillkite\App. make dist
// builds it, so change src/, not this file.

/*
MIT License

Copyright (c) 2026 Joseph Scott

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.
*/

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

	// A hash of every file in src/, which make dist writes. It's part of the
	// route cache's key, so code that compiles the routes differently, even
	// between releases, never reads a cache that older code wrote.
	private const string SOURCE_HASH = 'b8b1803f0ebd98801c8935c8c135c3c7';

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

	// The matched route's group, or the group whose prefix an unmatched path
	// is under, as Compiler::compile() returns it in groups. Its error files
	// take the place of the app's for the codes they have.
	private ?array $group = null;

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
	// When matching fails, the response is a 500. A miss runs the
	// before-files too, and only then gets its response, so a before-file
	// that checks a login hides which paths exist.
	private function respond( array $compiled, string $method, string $path, string $query ) : void {
		$result = $this->match( $compiled, $method, $path );
		if ( $result['status'] === 200 ) {
			$this->route = $compiled['routes'][$result['route']];
			$files = $compiled['files'][$result['route']] ?? $compiled['default_files'];
			if ( $files[4] !== null ) {
				$this->group = $compiled['groups'][$files[4]];
			}

			$this->run_route( $this->route[2], $files, $result['params'] );
			return;
		}

		if ( $result['status'] === 500 ) {
			\error_log( 'Tillkite: matching the request failed: ' . $result['error'] );
			$this->server_error( 'Matching the request failed: ' . $result['error'] );
			return;
		}

		// A miss runs what a route in its group with no options of its own
		// would, or the app's before-file.
		$files = $compiled['default_files'];
		if ( isset( $result['group'] ) ) {
			$this->group = $compiled['groups'][$result['group']];
			$files = $this->group['files'];
		}

		$params = $result['params'] ?? [];
		if ( $files[0] !== '' && ! $this->run_miss_before( $files, $params ) ) {
			return;
		}

		$this->miss( $result, $params, $query );
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
	// before-files, up to three: the app's, its group's and its own, and the
	// buffer option. See Compiler::compile().
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
	private function run_miss_before( array $files, array $params ) : bool {
		$this->start( true, $params );
		$this->timer->next( 'before' );
		$ran = false;
		$this->in_route_files = true;
		Runner::files( $this, $params, $files[0], $files[1], $files[2], '', function( string $step ) use ( &$ran ) : bool {
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
	private function miss( array $result, array $params, string $query ) : void {
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

		$this->run_error( $code, $params );
	}

	// Runs the error file for a 4xx code, in a scope of its own, or sends
	// plain text. The 404 and 405 files run here when no route matches, with
	// the parameters of the prefix the path is under, and any 4xx file runs
	// here for error(), with the route's parameters.
	private function run_error( int $code, array $params ) : void {
		$file = $this->group['errors'][$code] ?? $this->errors[$code] ?? '';
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

		if ( isset( $this->group['errors'][500] ) || isset( $this->errors[500] ) ) {
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
		Runner::error_file( $this, $this->params, $error, $this->handlers . '/' . ( $this->group['errors'][500] ?? $this->errors[500] ) );
	}

	// A 500 that isn't an uncaught exception: the 500 file gets an exception
	// that describes it, which Tillkite creates but never throws. With a
	// broken configuration, the 500 file runs only if it's there to run.
	private function server_error( string $message ) : void {
		$file = $this->group['errors'][500] ?? $this->errors[500] ?? null;
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

	// Returns the matched route as written in the routes file, with its
	// group's prefix joined to its pattern, or null if nothing matched.
	public function route() : ?array {
		$route = $this->route;
		return $route;
	}

	// Returns the prefix of the matched route's group, as written in the
	// routes file, like '/admin', or null if it isn't in one. When no route
	// matched, it's the prefix of the group the path is under.
	public function group() : ?string {
		$prefix = $this->group['prefix'] ?? null;
		return $prefix;
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
			$settings = self::VERSION . ' ' . self::SOURCE_HASH . "\n" . $this->handlers . "\n" . $this->before . "\n" . (int) $this->trailing_slash . \json_encode( [ $this->methods, $this->errors ] );
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

/**
 * Reads and writes the route cache: the compiled routes, as a PHP file that
 * returns them, so OPcache keeps them in shared memory.
 *
 * @internal
 */
final class Cache {
	private string $file;

	// The routes file's owner, who the cache directory can belong to.
	private int|false $owner;

	/**
	 * The cache file's name is a hash of everything the compiled routes, and
	 * the problems found compiling them, depend on: the routes file's path,
	 * modification time, change time and size, the PCRE version, which decides
	 * what compiles, and $settings, which holds everything else as a string,
	 * including Tillkite's version. The change time is there because a deploy
	 * can keep a file's modification time, like rsync -t, and an edit can keep
	 * its size, but nothing can set the change time.
	 *
	 * The caller has just checked that the routes file exists, so PHP's stat
	 * cache answers these without another stat. The routes file's owner is
	 * read from it too, for owner_problem().
	 */
	public function __construct( private string $directory, private string $routes, string $settings ) {
		$key = $routes . "\n" . \filemtime( $routes ) . ' ' . \filectime( $routes ) . ' ' . \filesize( $routes ) . "\n" . $settings . "\n" . \PCRE_VERSION;
		$this->file = $directory . '/tillkite-' . \hash( 'xxh128', $key ) . '.php';
		$this->owner = \fileowner( $routes );
	}

	// Returns the compiled routes, or null when the cache file isn't there
	// yet, a file that the routes file loaded has changed, or its directory is
	// open to every user or belongs to another one, where the file could be
	// anyone's PHP, so it isn't run. That check costs a stat on every request.
	// include warns about a missing file, so a temporary handler takes the
	// warning, and checking for the file first would cost another stat.
	public function read() : ?array {
		if ( $this->directory_problem() !== '' ) {
			$compiled = null;
			return $compiled;
		}

		\set_error_handler( static fn () : bool => true );
		$compiled = include $this->file;
		\restore_error_handler();
		if ( ! \is_array( $compiled ) || ( ! empty( $compiled['includes'] ) && $this->changed( $compiled['includes'] ) ) ) {
			$compiled = null;
		}

		return $compiled;
	}

	/**
	 * Returns what read() checks for each of the files that the routes file
	 * loaded, by path, or null when one has changed too recently to cache what
	 * came from it. OPcache may have given the routes file its old copy, for
	 * up to $wait seconds, its opcache.revalidate_freq, and caching that under
	 * the new file's stamp would keep the old routes until the next change.
	 */
	public function stamps( array $files, int $wait ) : ?array {
		$stamps = [];
		$now = \time();
		foreach ( $files as $file ) {
			if ( $now - \max( (int) \filemtime( $file ), (int) \filectime( $file ) ) <= $wait ) {
				$stamps = null;
				return $stamps;
			}

			$stamps[$file] = $this->stamp( $file );
		}

		return $stamps;
	}

	// Removes the routes file from OPcache before it's compiled. OPcache may
	// not have noticed the change yet, and the routes it has would be cached
	// under the new file's name.
	public function clear_routes() : void {
		$this->forget( $this->routes );
	}

	/**
	 * Writes the cache file, and returns '' or the reason it couldn't.
	 *
	 * It goes to a temporary file first, and is renamed into place, so a
	 * request never includes half a file. Its modification time is set a
	 * minute in the past, so OPcache caches it right away instead of waiting
	 * out opcache.file_update_protection. Then the routes file's older cache
	 * files are removed.
	 *
	 * The web server runs the cache file, so anyone who can put a file in its
	 * place can run code as the web server. So it isn't written in a
	 * directory that every user can write to, even with the sticky bit, which
	 * still lets them make the file first, or in one that belongs to another
	 * user, read() doesn't run a file from one, and the file is 0644, whatever
	 * the umask.
	 */
	public function write( array $compiled ) : string {
		$error = $this->directory_problem();
		if ( $error !== '' ) {
			return $error;
		}

		\set_error_handler( function( int $number, string $message ) use ( &$error ) : bool {
			if ( $error === '' ) {
				$error = $message;
			}

			return true;
		} );

		$temp = $this->file . '.' . \uniqid( '', true ) . '.tmp';
		$php = $this->header() . 'return ' . \var_export( $compiled, true ) . ";\n";
		$written = \file_put_contents( $temp, $php ) !== false && \chmod( $temp, 0o644 ) && \touch( $temp, \time() - 60 ) && \rename( $temp, $this->file );
		if ( $written ) {
			// A changed file that the routes file loaded gives the cache file the
			// same name, and OPcache may have its old copy. Another request may
			// be removing the same files, so warnings from here don't matter.
			$this->forget( $this->file );
			$this->remove_older();
			$error = '';
		} else {
			if ( \is_file( $temp ) ) {
				\unlink( $temp );
			}

			if ( $error === '' ) {
				$error = "couldn't write " . $this->file;
			}
		}

		\restore_error_handler();
		return $error;
	}

	// Whether any of the files that the routes file loaded has changed, or is
	// gone. Each one that has comes out of OPcache, which may not have noticed,
	// and never notices an edit that keeps the modification time. The file may
	// be anything, so one that's gone has to be checked for, or filemtime()
	// would warn.
	private function changed( array $stamps ) : bool {
		$changed = false;
		foreach ( $stamps as $file => $stamp ) {
			if ( ! \is_file( $file ) || $this->stamp( $file ) !== $stamp ) {
				$this->forget( $file );
				$changed = true;
			}
		}

		return $changed;
	}

	// Stats a file once. PHP's stat cache answers the other two.
	private function stamp( string $file ) : string {
		$stamp = \filemtime( $file ) . ' ' . \filectime( $file ) . ' ' . \filesize( $file );
		return $stamp;
	}

	// Removes a file from OPcache, so the next include reads it from disk.
	private function forget( string $file ) : void {
		if ( \function_exists( 'opcache_invalidate' ) ) {
			\opcache_invalidate( $file, true );
		}
	}

	// Why a file in the cache directory could be someone else's PHP, with the
	// directory's path, or '' when it couldn't. Every user can write to the
	// directory, or its owner is one that owner_problem() doesn't trust.
	//
	// A directory that isn't there yet has no problem, and writing to it
	// reports that. fileperms() stats the directory, and PHP's stat cache
	// answers owner_problem().
	private function directory_problem() : string {
		$problem = '';
		$mode = \fileperms( $this->directory );
		if ( $mode === false ) {
			return $problem;
		}

		if ( ( $mode & 0o002 ) !== 0 ) {
			$problem = $this->directory . ' is writable by every user, who could put PHP in it for the web server to run, so it is not used. Make it writable only by the web server';
			return $problem;
		}

		$problem = $this->owner_problem();
		if ( $problem !== '' ) {
			$problem .= ': ' . $this->directory;
		}

		return $problem;
	}

	/**
	 * Why the cache can't be used in its directory, and what to do about it,
	 * without the directory's path, or '' when it can. Each of these would
	 * have the routes compiled on every request, which takes milliseconds for
	 * a site with a few hundred routes, so App reports it as a problem with
	 * the site's setup, instead of a line in the log on every request that
	 * nobody may read: a directory that doesn't exist, one that belongs to a
	 * user it doesn't trust, which read() and write() don't use, and one the
	 * user PHP runs as can't write to.
	 *
	 * A directory that every user can write to isn't one of them, though
	 * read() and write() don't use it either: on Windows, PHP reports every
	 * directory that way, and it would stop every site with a cache there.
	 *
	 * App calls it only when it compiles the routes, so a site with a cache
	 * pays nothing for it. A write that fails for another reason, like a full
	 * disk, is still only logged, so the site goes on working.
	 */
	public function setup_problem() : string {
		$problem = '';
		if ( ! \is_dir( $this->directory ) ) {
			$problem = "The cache directory doesn't exist, so the routes would be compiled on every request. Make it, writable only by the user PHP runs as, or leave the cache option out";
			return $problem;
		}

		$problem = $this->owner_problem();
		if ( $problem === '' && ! \is_writable( $this->directory ) ) {
			$problem = "The user PHP runs as can't write to the cache directory, so the routes would be compiled on every request. Make it writable by that user, or leave the cache option out";
		}

		return $problem;
	}

	/**
	 * Why the cache directory's owner could have put PHP in it for the web
	 * server to run, and what to do about it, without the directory's path,
	 * or '' when the owner is one to trust: root, the user PHP runs as, or
	 * the routes file's owner, who can change the site's PHP anyway. Where
	 * the directory was missing and its parent let them, like one under
	 * /tmp, another user could have made it first, as 0755, with a file under
	 * the cache file's name, which anyone who can read the site's files can
	 * work out.
	 *
	 * Without the posix extension there's no telling which user PHP runs as,
	 * so only root and the routes file's owner pass, and the problem says
	 * so. On Windows, the owner is always 0. Both callers have checked that
	 * the directory is there.
	 *
	 * read() and write() don't use such a directory.
	 */
	private function owner_problem() : string {
		$problem = '';
		$owner = \fileowner( $this->directory );
		if ( $owner === 0 || $owner === $this->owner ) {
			return $problem;
		}

		$problem = "The cache directory doesn't belong to root or to the routes file's owner, and without PHP's posix extension Tillkite can't tell whether it belongs to the user PHP runs as, or to another user, who could put PHP in it for the web server to run. Load the posix extension, or make the directory's owner the routes file's owner";
		if ( \function_exists( 'posix_geteuid' ) ) {
			$problem = '';
			if ( $owner !== \posix_geteuid() ) {
				$problem = "The cache directory belongs to another user, who could put PHP in it for the web server to run. Make its owner the user PHP runs as, or the routes file's owner";
			}
		}

		return $problem;
	}

	// The first line of each cache file. It names the routes file by a hash
	// of its path, so it's safe in a comment whatever the path holds.
	private function header() : string {
		$header = '<?php // Tillkite route cache for the routes file ' . \hash( 'xxh128', $this->routes ) . "\n";
		return $header;
	}

	// Removes the other cache files for this routes file. Apps that share the
	// directory keep theirs, because their first line differs.
	private function remove_older() : void {
		$header = $this->header();
		foreach ( \scandir( $this->directory ) ?: [] as $name ) {
			$file = $this->directory . '/' . $name;
			if ( $file === $this->file || ! \str_starts_with( $name, 'tillkite-' ) || ! \str_ends_with( $name, '.php' ) ) {
				continue;
			}

			if ( \file_get_contents( $file, false, null, 0, \strlen( $header ) ) === $header ) {
				\unlink( $file );
			}
		}
	}
}

/**
 * Checks the route list and compiles it into the tables that Matcher uses.
 *
 * Nothing is thrown. Every problem found is added to $problems as a message,
 * and the caller decides what to do with them.
 *
 * @internal
 */
final class Compiler {
	public const array STANDARD_METHODS = [ 'GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'CONNECT', 'TRACE' ];

	public const string DEFAULT_REGEX = '[^/]+';

	public const array ROUTE_OPTIONS = [ 'buffer', 'before', 'before_only' ];

	public const array GROUP_KEYS = [ 'prefix', 'routes', 'buffer', 'before', 'before_only', 'errors' ];

	// A regex with up to this many routes tells them apart by the number of
	// matches instead of a MARK. See leaf_ends().
	public const int COUNT_LEAVES = 12;

	// Custom parameter regexes that can't match a /: word characters, \d, \w,
	// \. and \-, classes of those (ranges between letters and digits never
	// include /), negated classes that list /, quantifiers, | and (?:...).
	// Anything else is treated as able to match a /.
	private const string SEGMENT_ONLY = '{^(?:[A-Za-z0-9_|*+?,]|\\\\[dw.-]|\{\d+(?:,\d*)?\}|\(\?:|\)|\[(?:[A-Za-z0-9_]-[A-Za-z0-9_]|[A-Za-z0-9_.]|\\\\[dw.-]|-(?=\]))+\]|\[\^[^\]\\\\]*/[^\]\\\\]*\])+$}D';

	public bool $trailing_slash = false;

	public array $methods = [];

	public string $handlers = '';

	public ?string $before = null;

	public array $errors = [];

	public array $problems = [];

	// Every route, with each group's routes in the group's place:
	// [ 'route' => as written, 'label' => like '3' or '4.2', 'name' => its key
	// in $names, 'prefix' => its group's or '', 'chain' => the before-files
	// that run ahead of its own (see add_before()), 'buffer' => its group's
	// buffer option or null, 'group' => its group's key in $groups or null ].
	private array $entries = [];

	// Each group, in order: [ the group as compile() returns it in groups,
	// the regex for a path under its prefix, its number of segments ].
	private array $groups = [];

	// What each route's or group's problems start with, in the order of the
	// routes file: [ 'Route 4.2', the route ] or [ 'Group 4', the group
	// without its routes ]. They're exported only for a problem.
	private array $names = [];

	// The problems found, by their key in $names.
	private array $entry_problems = [];

	private bool $check_files = true;

	// What regex_error() found for each parameter regex, since most routes
	// share a few, like the default.
	private array $regex_errors = [];

	/**
	 * Returns the compiled tables:
	 *
	 * - routes:  each route as route() returns it: as written, with its
	 *            group's prefix joined to its pattern
	 * - checks:  for each route with parameters that have a regex of their
	 *            own, [ name => the regex a decoded value has to match too,
	 *            or '' for none ]. See checks().
	 * - static:  method => [ path => the match: [ 'status' => 200, ... ] ]
	 * - dynamic: method => [ regex => [ key => the match, with the parameter
	 *            names where their values go ] ], where the key is the
	 *            regex's MARK or its number of matches
	 * - any:     [ 'static' => ..., 'dynamic' => ... ] for every route of
	 *            every method, matching with or without a trailing slash
	 * - default_files: what a route runs that has no before or buffer
	 *            options and isn't in a group: the paths of its before-files
	 *            in the order they run, with '' for each one fewer than
	 *            three, then its buffer option, or null for the app's, then
	 *            its group's key in groups, or null
	 * - files:   the same for each route that runs something else, or is in
	 *            a group
	 * - groups:  for each group, [ 'prefix' => as written, 'errors' => as
	 *            written, or [], 'files' => what a miss under its prefix
	 *            runs, which is what a route in it with no options would,
	 *            'names' => the names of its prefix's parameters, 'checks' =>
	 *            their checks, as above ]
	 * - prefixes: a regex for a path under a group's prefix, whose MARK is
	 *            the first such group's key in groups, and whose matches are
	 *            the prefix's parameters, or '' when there are no groups
	 *
	 * A route for '*' is added to every method's table, and also gets a '*'
	 * table for methods that have no routes of their own.
	 *
	 * Routes are numbered by their place in the list, starting at 0, with
	 * each group's routes in the group's place.
	 */
	public function compile( array $routes ) : array {
		$this->problems = [];
		$this->entries = [];
		$this->names = [];
		$this->entry_problems = [];
		$this->groups = [];

		$compiled = [
			'routes' => [],
			'checks' => [],
			'static' => [],
			'dynamic' => [],
			'any' => [ 'static' => [], 'dynamic' => [] ],
			'default_files' => [ '', '', '', null, null ],
			'files' => [],
			'groups' => [],
			'prefixes' => '',
		];

		$allowed = $this->check_settings();
		if ( ! \array_is_list( $routes ) ) {
			$this->problems[] = 'The routes file must return a list of routes, without keys.';
			return $compiled;
		}

		// Most routes run only the app's before-file, so only the others take
		// room in the cache.
		if ( $this->before !== null ) {
			$compiled['default_files'][0] = $this->path( $this->before );
		}

		$this->list_routes( $routes );
		$parsed = [];
		foreach ( \array_keys( $this->entries ) as $index ) {
			$entry = $this->parse_route( $index, $allowed );
			if ( $entry !== null ) {
				$parsed[$index] = $entry;
				$compiled['routes'][$index] = $entry['route'];
				$checks = \array_merge( ...\array_column( $entry['variants'], 'checks' ) );
				if ( $checks !== [] ) {
					$compiled['checks'][$index] = $checks;
				}

				if ( $entry['files'] !== $compiled['default_files'] ) {
					$compiled['files'][$index] = $entry['files'];
				}
			}
		}

		// PCRE takes the first alternative that matches. A path under more
		// than one group's prefix, like /api/admin/x under /api and
		// /api/admin, is under the one with the most segments, so its
		// before-files run for misses under it whatever the order of the
		// list. Prefixes with as many segments, like /{lang} and /en, keep
		// their order. In (?|...), each alternative's matches are numbered
		// from 1.
		$alternatives = [];
		$segments = [];
		foreach ( $this->groups as $key => [ $group, $regex, $count ] ) {
			$compiled['groups'][] = $group;
			$alternatives[$key] = $regex . '(*:' . $key . ')';
			$segments[$key] = $count;
		}

		\uksort( $alternatives, static fn ( int $a, int $b ) : int => $segments[$b] <=> $segments[$a] );
		if ( $alternatives !== [] ) {
			$compiled['prefixes'] = '{^(?|' . \implode( '|', $alternatives ) . ')}D';
		}

		$blocked = [];
		$reachable = [];
		$built_items = [];
		$built_tables = [];
		foreach ( $this->table_keys( $parsed, $allowed ) as $table ) {
			$static = [];
			$items = [];
			$seen = [];
			foreach ( $parsed as $index => $entry ) {
				if ( ! $this->applies( $entry['methods'], $table ) ) {
					continue;
				}

				foreach ( $entry['variants'] as $variant ) {
					if ( $variant['regex'] === '' ) {
						$path = $variant['path'];
						if ( isset( $static[$path] ) ) {
							$blocked[$index][$static[$path]] ??= $table;
							continue;
						}

						$static[$path] = $index;
						continue;
					}

					// Best effort for dynamic routes: only an identical regex is
					// known to match the same requests.
					$regex = $variant['regex'];
					if ( isset( $seen[$regex] ) ) {
						$blocked[$index][$seen[$regex]] ??= $table;
						continue;
					}

					$seen[$regex] = $index;
					$items[] = [ $index, $variant['pieces'], $variant['names'] ];
					$reachable[$index] = true;
				}
			}

			// HEAD usually has the same routes as GET, so it reuses its regexes.
			// array_search() compares the items in less time than serialize()
			// takes to make a key of them.
			$built = \array_search( $items, $built_items, true );
			if ( $built === false ) {
				$built = \count( $built_tables );
				$built_items[] = $items;
				$built_tables[] = $this->combine( $items, false );
			}

			$compiled['static'][$table] = $static;
			$compiled['dynamic'][$table] = $built_tables[$built];
		}

		// Every route of every method, for the matcher's first check after a
		// miss. Its regexes end in /?$ instead of $, so a path ending in / also
		// finds the routes for the path without it.
		$items = [];
		$seen = [];
		foreach ( $parsed as $index => $entry ) {
			foreach ( $entry['variants'] as $variant ) {
				if ( $variant['regex'] === '' ) {
					$compiled['any']['static'][$variant['path']] ??= $index;
				} elseif ( ! isset( $seen[$variant['regex']] ) ) {
					$seen[$variant['regex']] = true;
					$items[] = [ $index, $variant['pieces'], $variant['names'] ];
				}
			}
		}

		$compiled['any']['dynamic'] = $this->combine( $items, true );

		// The matcher checks the static table first, so a static path that an
		// earlier dynamic route already matches must come out of it. Otherwise
		// the later static route would win. It has the checks too, so a path
		// whose value the dynamic route turns away once it's decoded, like
		// /files/a%2Fb for /files/{name}, stays.
		$matcher = new Matcher( [ 'dynamic' => $this->check_copies( $compiled['dynamic'] ), 'checks' => $compiled['checks'] ] );
		foreach ( $compiled['static'] as $table => $paths ) {
			foreach ( $paths as $path => $index ) {
				$found = $matcher->find_dynamic( $table, (string) $path );
				if ( $found['status'] === 200 && $found['route'] < $index ) {
					unset( $compiled['static'][$table][$path] );
					$blocked[$index][$found['route']] ??= $table;
					continue;
				}

				$reachable[$index] = true;
			}
		}

		foreach ( $parsed as $index => $entry ) {
			if ( ! isset( $reachable[$index] ) ) {
				$this->route_problem( $index, $this->blocked_message( $index, $blocked[$index], $parsed ) );
			}
		}

		\ksort( $this->entry_problems );
		foreach ( $this->entry_problems as $name => $messages ) {
			$start = $this->names[$name][0] . ' ' . $this->export( $this->names[$name][1] ) . ': ';
			foreach ( $messages as $message ) {
				$this->problems[] = $start . $message;
			}
		}

		// A static route's match is always the same, so it's stored ready to
		// return. Coming from OPcache, it costs nothing to build.
		foreach ( $compiled['static'] as $table => $paths ) {
			foreach ( $paths as $path => $index ) {
				$compiled['static'][$table][$path] = [ 'status' => 200, 'route' => $index, 'params' => [] ];
			}
		}

		return $compiled;
	}

	// Checks the settings, and returns every allowed method. Without a
	// handlers directory, no file can be found, so only that is reported, not
	// each file that's missing.
	private function check_settings() : array {
		$this->check_files = true;
		if ( ! \is_dir( $this->handlers ) ) {
			$this->problems[] = "The handlers directory doesn't exist: " . $this->handlers;
			$this->check_files = false;
		}

		if ( $this->before !== null && ! $this->file_exists( $this->before ) ) {
			$this->problems[] = "The before file doesn't exist: " . $this->path( $this->before );
		}

		foreach ( $this->error_problems( $this->errors ) as $problem ) {
			$this->problems[] = \ucfirst( $problem );
		}

		$allowed = self::STANDARD_METHODS;
		foreach ( $this->methods as $method ) {
			if ( ! \is_string( $method ) || \preg_match( '/^[A-Z][A-Z0-9_-]*$/D', $method ) !== 1 ) {
				$this->problems[] = 'Custom methods must be uppercase, like PURGE: ' . $this->export( $method );
				continue;
			}

			if ( ! \in_array( $method, $allowed, true ) ) {
				$allowed[] = $method;
			}
		}

		return $allowed;
	}

	// Checks the error files, the app's or a group's.
	private function error_problems( mixed $errors ) : array {
		if ( ! \is_array( $errors ) ) {
			return [ "errors must be an array, like [ 404 => '404.php' ]" ];
		}

		$problems = [];
		foreach ( $errors as $code => $file ) {
			if ( ! isset( App::REASONS[$code] ) ) {
				$problems[] = 'unknown error code in errors: ' . $code . '. Use 500, or a standard 4xx code like 404 or 403';
			} elseif ( ! \is_string( $file ) || $file === '' ) {
				$problems[] = 'the ' . $code . ' error file must be a file name';
			} elseif ( ! $this->file_exists( $file ) ) {
				$problems[] = 'the ' . $code . " error file doesn't exist: " . $this->path( $file );
			}
		}

		return $problems;
	}

	// Fills $entries with every route, putting each group's routes in the
	// group's place, and checks the groups. A group with problems leaves out
	// its routes, so they don't each repeat its problem.
	private function list_routes( array $routes ) : void {
		$chain = [];
		if ( $this->before !== null ) {
			$chain[] = [ $this->before, "the app's" ];
		}

		foreach ( $routes as $position => $route ) {
			$label = (string) ( $position + 1 );
			if ( ! $this->is_group( $route ) ) {
				$this->add_entry( $label, $route, '', $chain, null, null );
				continue;
			}

			$group = $this->parse_group( $label, $route, $chain );
			if ( $group === null ) {
				continue;
			}

			foreach ( $route['routes'] as $inner => $inner_route ) {
				$inner_label = $label . '.' . ( $inner + 1 );
				if ( $this->is_group( $inner_route ) ) {
					$this->names[] = [ 'Group ' . $inner_label, $this->shown_group( $inner_route ) ];
					$this->entry_problem( \array_key_last( $this->names ), "a group can't be inside another group" );
					continue;
				}

				$this->add_entry( $inner_label, $inner_route, $route['prefix'], $group['chain'], $group['buffer'], $group['key'] );
			}
		}
	}

	// A group has a prefix or routes key. A route is a list, so it has
	// neither.
	private function is_group( mixed $entry ) : bool {
		$group = \is_array( $entry ) && ( \array_key_exists( 'prefix', $entry ) || \array_key_exists( 'routes', $entry ) );
		return $group;
	}

	// A group as its problems show it, without its routes.
	private function shown_group( array $group ) : array {
		unset( $group['routes'] );
		return $group;
	}

	private function add_entry( string $label, mixed $route, string $prefix, array $chain, ?bool $buffer, ?int $group ) : void {
		$this->names[] = [ 'Route ' . $label, $route ];
		$this->entries[] = [
			'route' => $route,
			'label' => $label,
			'name' => \array_key_last( $this->names ),
			'prefix' => $prefix,
			'chain' => $chain,
			'buffer' => $buffer,
			'group' => $group,
		];
	}

	// Returns [ 'chain' => the before-files that run ahead of each route's
	// own, 'buffer' => the group's buffer option or null, 'key' => its key in
	// $groups ], or null when the group has problems.
	private function parse_group( string $label, array $group, array $chain ) : ?array {
		$this->names[] = [ 'Group ' . $label, $this->shown_group( $group ) ];
		$name = \array_key_last( $this->names );

		$errors = [];
		$prefix = $this->parse_prefix( $group['prefix'] ?? null );
		if ( \is_string( $prefix ) ) {
			$errors[] = $prefix;
		}

		if ( ! \is_array( $group['routes'] ?? null ) || ! \array_is_list( $group['routes'] ) ) {
			$errors[] = 'routes must be a list of routes';
		}

		foreach ( $group as $key => $value ) {
			$error = '';
			if ( ! \in_array( $key, self::GROUP_KEYS, true ) ) {
				$error = "unknown group key '" . $key . "'";
			} elseif ( \in_array( $key, self::ROUTE_OPTIONS, true ) ) {
				$error = $this->option_error( $key, $value );
			}

			if ( $error !== '' ) {
				$errors[] = $error;
			}
		}

		if ( isset( $group['before'], $group['before_only'] ) ) {
			$errors[] = 'use before or before_only, not both';
		}

		if ( \array_key_exists( 'errors', $group ) ) {
			\array_push( $errors, ...$this->error_problems( $group['errors'] ) );
		}

		if ( $errors === [] ) {
			$added = $this->add_before( $chain, $group, "the group's" );
			if ( \is_string( $added ) ) {
				$errors[] = $added;
			} else {
				$chain = $added;
			}
		}

		foreach ( $errors as $error ) {
			$this->entry_problem( $name, $error );
		}

		if ( $errors !== [] ) {
			return null;
		}

		$key = \count( $this->groups );
		$buffer = $group['buffer'] ?? null;
		[ $regex, $names, $segments, $checks ] = $prefix;
		$compiled = [
			'prefix' => $group['prefix'],
			'errors' => $group['errors'] ?? [],
			'files' => $this->files( $chain, $buffer, $key ),
			'names' => $names,
			'checks' => $checks,
		];
		$this->groups[] = [ $compiled, $regex, $segments ];
		$parsed = [ 'chain' => $chain, 'buffer' => $buffer, 'key' => $key ];
		return $parsed;
	}

	// Returns [ the regex for a path that the prefix starts, which is the
	// prefix, then a / or the end, the names of its parameters, its number of
	// segments, its checks ], or a problem message. Each parameter is a match.
	//
	// The prefix is joined to each of the group's patterns, which are
	// checked then. Here it only has to be a pattern on its own, without
	// optional parts, which go at the end of a pattern.
	private function parse_prefix( mixed $prefix ) : array|string {
		if ( ! \is_string( $prefix ) || ! \str_starts_with( $prefix, '/' ) || \str_ends_with( $prefix, '/' ) ) {
			return "the prefix must start with / and not end with /, like '/admin'";
		}

		$tokens = $this->tokenize( $prefix );
		if ( \is_string( $tokens ) ) {
			return $tokens;
		}

		$regex = '';
		$names = [];
		$segments = 0;
		foreach ( $tokens as $token ) {
			if ( $token['type'] === '[' || $token['type'] === ']' ) {
				return "the prefix can't have optional parts";
			}

			if ( $token['type'] === 'literal' ) {
				$regex .= \preg_quote( $this->encode( $token['text'] ) );
				$segments += \substr_count( $token['text'], '/' );
			} else {
				$regex .= '(' . $token['regex'] . ')';
				$names[] = $token['name'];
			}
		}

		$regex .= '(?:/|$)';
		return [ $regex, $names, $segments, $this->checks( $tokens ) ];
	}

	// Returns the before-files that run for one level, the group's or the
	// route's, from those of the levels above it and its own options.
	// 'before' adds a file after them, 'before_only' runs a file in their
	// place, and 'before' => false runs none. Each is [ file, whose it is ],
	// starting with [ the app's before file, "the app's" ]. Returns a problem
	// message when the level's before file runs already.
	private function add_before( array $chain, array $options, string $whose ) : array|string {
		if ( isset( $options['before_only'] ) ) {
			$chain = [ [ $options['before_only'], $whose ] ];
			return $chain;
		}

		$before = $options['before'] ?? null;
		if ( $before === false ) {
			$chain = [];
			return $chain;
		}

		if ( $before === null ) {
			return $chain;
		}

		foreach ( $chain as [ $file, $file_whose ] ) {
			if ( $this->same_file( $before, $file ) ) {
				$error = 'the before file is ' . $file_whose . ' before file too, so it would run twice';
				return $error;
			}
		}

		$chain[] = [ $before, $whose ];
		return $chain;
	}

	// Returns [ 'methods' => as parse_methods() returns them, 'variants' =>
	// as parse_pattern() returns them, 'route' => as route() returns it,
	// 'files' => what it runs, as compile() describes ], or null when the
	// route has problems.
	private function parse_route( int $index, array $allowed ) : ?array {
		$entry = $this->entries[$index];
		$route = $entry['route'];
		if ( ! \is_array( $route ) || ! \array_is_list( $route ) || \count( $route ) < 3 || \count( $route ) > 4 ) {
			$this->route_problem( $index, 'each route must be [ method, pattern, file ], with optional route options' );
			return null;
		}

		$errors = [];
		$methods = $this->parse_methods( $route[0], $allowed );
		if ( \is_string( $methods ) ) {
			$errors[] = $methods;
		}

		$pattern = 'the pattern must be a string';
		if ( \is_string( $route[1] ) && ! $this->fits_prefix( $entry['prefix'], $route[1] ) ) {
			$pattern = "in a group, the pattern must start with / or [, or be '' for the prefix by itself";
		} elseif ( \is_string( $route[1] ) ) {
			$pattern = $this->parse_pattern( $entry['prefix'] . $route[1] );
		}

		if ( \is_string( $pattern ) ) {
			$errors[] = $pattern;
		}

		$file = $route[2];
		if ( ! \is_string( $file ) || $file === '' ) {
			$errors[] = 'the file must be a file name';
		} elseif ( ! $this->file_exists( $file ) ) {
			$errors[] = "the file doesn't exist: " . $this->path( $file );
		}

		$options = $route[3] ?? [];
		if ( ! \is_array( $options ) || ( $options !== [] && \array_is_list( $options ) ) ) {
			$errors[] = "route options must be an array, like [ 'buffer' => false ]";
			$options = [];
		}

		$options_valid = true;
		foreach ( $options as $key => $value ) {
			$error = $this->option_error( $key, $value );
			if ( $error !== '' ) {
				$errors[] = $error;
				$options_valid = false;
			}
		}

		if ( isset( $options['before'], $options['before_only'] ) ) {
			$errors[] = 'use before or before_only, not both';
			$options_valid = false;
		}

		$chain = $entry['chain'];
		if ( $options_valid ) {
			$added = $this->add_before( $chain, $options, "the route's" );
			if ( \is_string( $added ) ) {
				$errors[] = $added;
			} else {
				$chain = $added;
			}
		}

		foreach ( $errors as $error ) {
			$this->route_problem( $index, $error );
		}

		if ( $errors !== [] ) {
			return null;
		}

		$route[1] = $entry['prefix'] . $route[1];
		$parsed = [
			'methods' => $methods,
			'variants' => $pattern,
			'route' => $route,
			'files' => $this->files( $chain, $options['buffer'] ?? $entry['buffer'], $entry['group'] ),
		];
		return $parsed;
	}

	// What a route runs, as compile() describes it: the paths of the
	// before-files in $chain, with '' for each one fewer than three, its
	// buffer option and its group's key in groups.
	private function files( array $chain, ?bool $buffer, ?int $group ) : array {
		$files = [ '', '', '', $buffer, $group ];
		foreach ( $chain as $position => [ $before ] ) {
			$files[$position] = $this->path( $before );
		}

		return $files;
	}

	// Returns the sorted list of methods, or a problem message. A GET route
	// also answers HEAD, so GET brings HEAD with it.
	private function parse_methods( mixed $value, array $allowed ) : array|string {
		$list = $value;
		if ( \is_string( $value ) ) {
			$list = [ $value ];
		}

		if ( ! \is_array( $list ) || $list === [] || ! \array_is_list( $list ) ) {
			return 'the method must be a string or a list of strings';
		}

		if ( $list === [ '*' ] ) {
			return $list;
		}

		$methods = [];
		foreach ( $list as $method ) {
			$error = $this->method_error( $method, $allowed );
			if ( $error !== '' ) {
				return $error;
			}

			$methods[] = $method;
			if ( $method === 'GET' ) {
				$methods[] = 'HEAD';
			}
		}

		$methods = \array_values( \array_unique( $methods ) );
		\sort( $methods );
		return $methods;
	}

	private function method_error( mixed $method, array $allowed ) : string {
		$error = '';
		if ( ! \is_string( $method ) ) {
			$error = 'the method must be a string or a list of strings';
		} elseif ( $method === '*' ) {
			$error = "'*' can't be combined with other methods";
		} elseif ( \strtoupper( $method ) !== $method ) {
			$error = "methods must be uppercase: use '" . \strtoupper( $method ) . "', not '" . $method . "'";
		} elseif ( ! \in_array( $method, $allowed, true ) ) {
			$error = "unknown method '" . $method . "' (add custom methods with the methods option)";
		}

		return $error;
	}

	private function option_error( int|string $key, mixed $value ) : string {
		$error = '';
		if ( ! \in_array( $key, self::ROUTE_OPTIONS, true ) ) {
			$error = "unknown route option '" . $key . "'";
		} elseif ( $key === 'buffer' && ! \is_bool( $value ) ) {
			$error = 'the buffer option must be true or false';
		} elseif ( $key === 'before' && $value !== false && ( ! \is_string( $value ) || $value === '' ) ) {
			$error = 'the before option must be a file name or false';
		} elseif ( $key === 'before' && \is_string( $value ) && ! $this->file_exists( $value ) ) {
			$error = "the before file doesn't exist: " . $this->path( $value );
		} elseif ( $key === 'before_only' && ( ! \is_string( $value ) || $value === '' ) ) {
			$error = "the before_only option must be a file name. For no before-file, use 'before' => false";
		} elseif ( $key === 'before_only' && ! $this->file_exists( $value ) ) {
			$error = "the before_only file doesn't exist: " . $this->path( $value );
		}

		return $error;
	}

	// In a group, a pattern goes on after the prefix: with a /, with an
	// optional part, or not at all.
	private function fits_prefix( string $prefix, string $pattern ) : bool {
		$fits = $prefix === '' || $pattern === '' || \str_starts_with( $pattern, '/' ) || \str_starts_with( $pattern, '[' );
		return $fits;
	}

	// Whether two file names, relative to handlers, are the same file.
	private function same_file( string $a, string $b ) : bool {
		$same = $a === $b;
		if ( ! $same ) {
			$real = \realpath( $this->path( $a ) );
			$same = $real !== false && $real === \realpath( $this->path( $b ) );
		}

		return $same;
	}

	// Returns the variants, or a problem message. Each
	// optional part adds a variant: /blog[/{page}] has the variants /blog and
	// /blog/{page}.
	private function parse_pattern( string $pattern ) : array|string {
		if ( ! \str_starts_with( $pattern, '/' ) ) {
			return 'the pattern must start with /';
		}

		$tokens = $this->tokenize( $pattern );
		if ( \is_string( $tokens ) ) {
			return $tokens;
		}

		$names = [];
		foreach ( $tokens as $token ) {
			if ( $token['type'] !== 'param' ) {
				continue;
			}

			if ( \in_array( $token['name'], $names, true ) ) {
				return "duplicate parameter name '" . $token['name'] . "'";
			}

			$names[] = $token['name'];
		}

		$split = $this->split_variants( $tokens );
		if ( \is_string( $split ) ) {
			return $split;
		}

		$variants = [];
		foreach ( $split as $variant_tokens ) {
			$variant = $this->compile_variant( $variant_tokens );
			$error = $this->slash_error( $variant_tokens, $variant['source'] );
			if ( $error !== '' ) {
				return $error;
			}

			$variants[] = $variant;
		}

		return $variants;
	}

	// Splits a pattern into literal, param, [ and ] tokens, or returns a
	// problem message.
	private function tokenize( string $pattern ) : array|string {
		$tokens = [];
		$literal = '';
		$length = \strlen( $pattern );
		$i = 0;
		while ( $i < $length ) {
			$char = $pattern[$i];
			if ( $char === '}' ) {
				return 'unbalanced { } in the pattern';
			}

			if ( $char !== '{' && $char !== '[' && $char !== ']' ) {
				$literal .= $char;
				$i++;
				continue;
			}

			if ( $literal !== '' ) {
				$tokens[] = [ 'type' => 'literal', 'text' => $literal ];
				$literal = '';
			}

			if ( $char !== '{' ) {
				$tokens[] = [ 'type' => $char ];
				$i++;
				continue;
			}

			$end = $this->closing_brace( $pattern, $i );
			if ( $end === -1 ) {
				return 'unbalanced { } in the pattern';
			}

			$param = $this->parse_param( \substr( $pattern, $i + 1, $end - $i - 1 ) );
			if ( \is_string( $param ) ) {
				return $param;
			}

			$tokens[] = $param;
			$i = $end + 1;
		}

		if ( $literal !== '' ) {
			$tokens[] = [ 'type' => 'literal', 'text' => $literal ];
		}

		return $tokens;
	}

	// Returns the position of the } that closes the { at $start, or -1.
	// Braces inside the parameter's regex are counted, and escaped ones skipped.
	private function closing_brace( string $pattern, int $start ) : int {
		$depth = 0;
		$length = \strlen( $pattern );
		for ( $i = $start; $i < $length; $i++ ) {
			$char = $pattern[$i];
			if ( $char === '\\' ) {
				$i++;
				continue;
			}

			if ( $char === '{' ) {
				$depth++;
			}

			if ( $char === '}' ) {
				$depth--;
			}

			if ( $depth === 0 ) {
				return $i;
			}
		}

		return -1;
	}

	// Parses the inside of {name} or {name:regex}.
	private function parse_param( string $body ) : array|string {
		$name = $body;
		$regex = self::DEFAULT_REGEX;
		$colon = \strpos( $body, ':' );
		if ( $colon !== false ) {
			$name = \substr( $body, 0, $colon );
			$regex = \substr( $body, $colon + 1 );
		}

		if ( $name === '' ) {
			return 'empty parameter name in {' . $body . '}';
		}

		if ( \preg_match( '/^[a-zA-Z_][a-zA-Z0-9_]*$/D', $name ) !== 1 ) {
			return "invalid parameter name '" . $name . "' (use letters, digits and _)";
		}

		if ( $regex === '' ) {
			return 'empty regex in {' . $body . '}';
		}

		$error = $this->regex_error( $regex );
		if ( $error !== '' ) {
			return 'the regex in {' . $body . '} ' . $error;
		}

		$param = [
			'type' => 'param',
			'name' => $name,
			'regex' => $regex,
			'source' => '{' . $body . '}',
		];
		return $param;
	}

	// Returns the token list for each variant, shortest first, or a problem
	// message. Optional parts have to be at the end, and can be nested.
	private function split_variants( array $tokens ) : array|string {
		$variants = [];
		$current = [];
		$opened = 0;
		$closed = 0;
		$previous = '';
		foreach ( $tokens as $token ) {
			$type = $token['type'];
			if ( $closed > 0 && $type !== ']' ) {
				return 'optional parts must be at the end of the pattern';
			}

			if ( ( $type === '[' || $type === ']' ) && $previous === '[' ) {
				return 'empty optional part [ ] in the pattern';
			}

			$previous = $type;
			if ( $type === '[' ) {
				$variants[] = $current;
				$opened++;
				continue;
			}

			if ( $type === ']' ) {
				$closed++;
				if ( $closed > $opened ) {
					return 'unbalanced [ ] in the pattern';
				}

				continue;
			}

			// Join literals that meet across an optional part, so /blog[/{page}]
			// gives one literal, /blog/, before {page}.
			$last = \array_key_last( $current );
			if ( $type === 'literal' && $last !== null && $current[$last]['type'] === 'literal' ) {
				$current[$last]['text'] .= $token['text'];
				continue;
			}

			$current[] = $token;
		}

		if ( $opened !== $closed ) {
			return 'unbalanced [ ] in the pattern';
		}

		$variants[] = $current;
		return $variants;
	}

	// Returns [ 'source', 'path', 'regex', 'pieces', 'names', 'checks' ]. A
	// static variant has a path and an empty regex. A dynamic one has a regex,
	// the same regex split into pieces, its parameter names and their checks.
	private function compile_variant( array $tokens ) : array {
		$source = '';
		$path = '';
		$names = [];
		foreach ( $tokens as $token ) {
			if ( $token['type'] === 'literal' ) {
				$source .= $token['text'];
				$path .= $this->encode( $token['text'] );
				continue;
			}

			$names[] = $token['name'];
			$source .= $token['source'];
		}

		$variant = [
			'source' => $source,
			'path' => $path,
			'regex' => '',
			'pieces' => [],
			'names' => $names,
			'checks' => $this->checks( $tokens ),
		];

		if ( $names === [] ) {
			return $variant;
		}

		$pieces = $this->pieces( $tokens );
		$variant['path'] = '';
		$variant['regex'] = \implode( '', \array_column( $pieces, 'regex' ) );
		$variant['pieces'] = $pieces;
		return $variant;
	}

	// The regexes that a parameter's value has to match decoded as well, by
	// its name, for Matcher::decoded(). A route's regex sees the value still
	// encoded, where %2E isn't a dot, so [^./]+ would let %2E%2E through.
	//
	// The default regex is left out, and the matcher knows a parameter
	// without a check for one: it takes one segment, so the matcher turns
	// away a value that decodes to more, which needs no regex in the cache
	// for the parameters most routes have. A regex that names %, like
	// (?:[a-z]|%[0-9A-F]{2})+, was written for the encoded form, and wouldn't
	// match the decoded value, so its check is '', for none at all.
	private function checks( array $tokens ) : array {
		$checks = [];
		foreach ( $tokens as $token ) {
			if ( $token['type'] !== 'param' || $token['regex'] === self::DEFAULT_REGEX ) {
				continue;
			}

			$checks[$token['name']] = '';
			if ( ! \str_contains( $token['regex'], '%' ) ) {
				$checks[$token['name']] = '{^(?:' . $token['regex'] . ')$}sD';
			}
		}

		return $checks;
	}

	// Splits a dynamic variant's regex at each / in its literals, so routes
	// that start the same way can share their first pieces. Each piece is:
	//
	// - regex:   its part of the regex
	// - text:    the literal text it starts with
	// - literal: true when it has no parameters
	// - fixed:   true when none of its parameters can match a /, so it
	//            matches exactly one path segment
	private function pieces( array $tokens ) : array {
		$pieces = [];
		$piece = null;
		$last = \count( $tokens ) - 1;
		foreach ( $tokens as $i => $token ) {
			if ( $token['type'] === 'param' ) {
				$piece['regex'] .= $this->param_regex( $token, $tokens[$i + 1] ?? null, $i === $last );
				$piece['literal'] = false;
				$piece['fixed'] = $piece['fixed'] && $this->segment_only( $token['regex'] );
				continue;
			}

			// A pattern starts with a literal /, so the first chunk is empty
			// before the first piece exists.
			foreach ( \explode( '/', $this->encode( $token['text'] ) ) as $n => $chunk ) {
				if ( $n > 0 ) {
					if ( $piece !== null ) {
						$pieces[] = $piece;
					}

					$piece = [ 'regex' => '/', 'text' => '/', 'literal' => true, 'fixed' => true ];
				}

				if ( $piece === null ) {
					continue;
				}

				$piece['regex'] .= \preg_quote( $chunk );
				if ( $piece['literal'] ) {
					$piece['text'] .= $chunk;
				}
			}
		}

		$pieces[] = $piece;
		return $pieces;
	}

	// The default [^/]+ is possessive when a / or the end comes next, so PCRE
	// never backtracks into it. When URLs never end in /, a last parameter
	// whose regex allows / (like a catch-all) must not match one at the end.
	private function param_regex( array $token, ?array $next, bool $is_last ) : string {
		$regex = '(' . $token['regex'] . ')';
		$closes = $next === null || ( $next['type'] === 'literal' && \str_starts_with( $next['text'], '/' ) );
		if ( $token['regex'] === self::DEFAULT_REGEX && $closes ) {
			$regex = '([^/]++)';
		}

		if ( $is_last && ! $this->trailing_slash && ! $this->segment_only( $token['regex'] ) ) {
			$regex .= '(?<!/)';
		}

		return $regex;
	}

	private function segment_only( string $regex ) : bool {
		if ( $regex === self::DEFAULT_REGEX || \preg_match( self::SEGMENT_ONLY, $regex ) === 1 ) {
			return true;
		}

		return false;
	}

	// Returns a problem message when the variant doesn't follow trailing_slash.
	private function slash_error( array $tokens, string $source ) : string {
		$error = '';
		if ( $source === '/' ) {
			return $error;
		}

		$last = $tokens[\count( $tokens ) - 1];
		$ends_with_slash = false;
		if ( $last['type'] === 'literal' && \str_ends_with( $last['text'], '/' ) ) {
			$ends_with_slash = true;
		}

		if ( ! $this->trailing_slash && $ends_with_slash ) {
			$error = "'" . $source . "' ends with /, but trailing_slash is false";
		}

		if ( $this->trailing_slash && ! $ends_with_slash && ! $this->last_segment_has_dot( $tokens ) ) {
			$error = "'" . $source . "' must end with /, because trailing_slash is true";
		}

		return $error;
	}

	// With trailing_slash on, a last segment with a literal dot, like
	// /feed.xml, can leave off the slash.
	private function last_segment_has_dot( array $tokens ) : bool {
		for ( $i = \count( $tokens ) - 1; $i >= 0; $i-- ) {
			if ( $tokens[$i]['type'] !== 'literal' ) {
				continue;
			}

			$text = $tokens[$i]['text'];
			$slash = \strrpos( $text, '/' );
			if ( $slash !== false ) {
				$text = \substr( $text, $slash + 1 );
			}

			if ( \str_contains( $text, '.' ) ) {
				return true;
			}

			if ( $slash !== false ) {
				return false;
			}
		}

		return false;
	}

	// Browsers send non-ASCII characters percent-encoded, with uppercase hex.
	private function encode( string $text ) : string {
		$encoded = (string) \preg_replace_callback(
			'/[\x80-\xFF]+/',
			fn ( $matches ) => \rawurlencode( $matches[0] ),
			$text
		);
		return $encoded;
	}

	// Joins a table's [ route index, pieces, names ] items, in route order,
	// into one regex, with routes that start with the same pieces sharing
	// them, and returns [ regex => leaves ]. $any is for the any table, whose
	// regexes only say whether something matches. When PCRE says the regex is
	// too large, splits the list in half and tries again.
	private function combine( array $items, bool $any ) : array {
		$regexes = [];
		if ( $items === [] ) {
			return $regexes;
		}

		$tree = [ 'fixed' => true, 'children' => [], 'last' => [], 'variants' => [] ];
		foreach ( $items as [ $index, $pieces, $names ] ) {
			$this->insert( $tree, $pieces, 0, [ 'status' => 200, 'route' => $index, 'params' => $names ], $any );
		}

		[ $ends, $leaves ] = $this->leaf_ends( $this->leaves( $tree ), $any );
		$position = 0;
		$combined = '{^' . $this->render( $tree, $ends, $position, ! $any ) . '}sD';

		$error = $this->compile_error( $this->check_copy( $combined ) );
		if ( $error === '' ) {
			$regexes = [ $combined => $leaves ];
			return $regexes;
		}

		// PCRE's offset is into the joined regex, not the route's pattern, so
		// it's left out. Some PCRE versions give the method's table and the any
		// table different offsets, which would report the route twice.
		if ( \count( $items ) === 1 ) {
			$this->route_problem( $items[0][0], "the pattern doesn't compile: " . \preg_replace( '/ at offset \d+$/D', '', $error ) );
			return $regexes;
		}

		$half = \intdiv( \count( $items ) + 1, 2 );
		$regexes = \array_merge(
			$this->combine( \array_slice( $items, 0, $half ), $any ),
			$this->combine( \array_slice( $items, $half ), $any )
		);
		return $regexes;
	}

	// The tree's leaves in the order PCRE tries them.
	private function leaves( array $node ) : array {
		$leaves = [];
		foreach ( $node['children'] as $child ) {
			if ( $child['piece'] === null ) {
				$leaves[] = $child['leaf'];
				continue;
			}

			\array_push( $leaves, ...$this->leaves( $child ) );
		}

		return $leaves;
	}

	// Returns [ ends, leaves ]: what ends each leaf's regex, and the leaves
	// by the key the matcher finds them with.
	//
	// A regex with few leaves pads each one with empty groups, so the number
	// of matches tells them apart, like FastRoute's GroupCountBased. That's
	// cheaper than a MARK, which PHP adds to the matches as a new string.
	// With more leaves, the padding would cost more than the MARK saves.
	private function leaf_ends( array $leaves, bool $any ) : array {
		$ends = [];
		$keys = [];
		if ( $any ) {
			$ends = \array_fill( 0, \count( $leaves ), '/?$' );
			$result = [ $ends, $keys ];
			return $result;
		}

		if ( \count( $leaves ) > self::COUNT_LEAVES ) {
			foreach ( $leaves as $position => $leaf ) {
				$ends[] = '$(*:' . $position . ')';
				$keys[$position] = $leaf;
			}

			$result = [ $ends, $keys ];
			return $result;
		}

		// Each leaf gets the smallest number of groups that's at least its
		// parameter count and more than any leaf before it, fewest first.
		$order = \array_keys( $leaves );
		\usort( $order, fn ( $a, $b ) => \count( $leaves[$a]['params'] ) <=> \count( $leaves[$b]['params'] ) );
		$groups = 0;
		foreach ( $order as $position ) {
			$params = \count( $leaves[$position]['params'] );
			$groups = \max( $params, $groups + 1 );
			$ends[$position] = '$' . \str_repeat( '()', $groups - $params );
			$keys[$groups + 1] = $leaves[$position];
		}

		\ksort( $ends );
		\ksort( $keys );
		$result = [ $ends, $keys ];
		return $result;
	}

	// Adds a route's pieces to the tree, one level per piece, ending in its
	// leaf. PCRE tries a node's children in order, so a route goes under the
	// last child with the same piece only when it can't match a path that any
	// later child matches; otherwise it starts a new child at the end.
	//
	// Only a fixed child is shared. When a shared piece can end in more than
	// one place, like /(.+), PCRE would try the later route's rest before the
	// earlier route's other ways to match it, so the later route could win.
	// Below a piece like that there's only ever one route.
	//
	// The any table only says whether something matches, so order doesn't
	// matter there, and every route shares as much as it can.
	private function insert( array &$node, array $pieces, int $depth, array $leaf, bool $any ) : void {
		$node['variants'][] = $pieces;
		if ( $depth === \count( $pieces ) ) {
			$node['children'][] = [ 'piece' => null, 'leaf' => $leaf ];
			return;
		}

		$piece = $pieces[$depth];
		$position = $node['last'][$piece['regex']] ?? null;
		if ( $position !== null && ! $any && ! $this->can_skip( $node, $position + 1, $pieces, $depth ) ) {
			$position = null;
		}

		if ( $position === null ) {
			$position = \count( $node['children'] );
			$fixed = $node['fixed'] && $piece['fixed'];
			$node['children'][] = [
				'piece' => $piece,
				'fixed' => $fixed,
				'children' => [],
				'last' => [],
				'variants' => [],
			];

			if ( $fixed || $any ) {
				$node['last'][$piece['regex']] = $position;
			}
		}

		$this->insert( $node['children'][$position], $pieces, $depth + 1, $leaf, $any );
	}

	// Whether a route can go ahead of the node's children from $start on: no
	// path may match both it and any route under them.
	private function can_skip( array $node, int $start, array $pieces, int $depth ) : bool {
		$count = \count( $node['children'] );
		for ( $i = $start; $i < $count; $i++ ) {
			$child = $node['children'][$i];

			// Everything before this level matched one segment per piece, so a
			// route that ends here matches fewer segments than this one, and a
			// child whose piece can't match the same segment holds no route
			// that can match the same path.
			if ( $node['fixed'] && ( $child['piece'] === null || $this->different_segments( $child['piece'], $pieces[$depth] ) ) ) {
				continue;
			}

			if ( $child['piece'] === null ) {
				return false;
			}

			foreach ( $child['variants'] as $other ) {
				if ( ! $this->disjoint( $other, $pieces ) ) {
					return false;
				}
			}
		}

		return true;
	}

	// Whether no path can match both lists of pieces. It only answers true
	// when it's sure: it compares the pieces in step while both match exactly
	// one segment each.
	private function disjoint( array $a, array $b ) : bool {
		$count = \min( \count( $a ), \count( $b ) );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( ! $a[$i]['fixed'] || ! $b[$i]['fixed'] ) {
				return false;
			}

			if ( $this->different_segments( $a[$i], $b[$i] ) ) {
				return true;
			}
		}

		// Each piece starts with a /. The shorter list matches exactly one
		// segment per piece, and the longer one needs more segments than that.
		if ( \count( $a ) !== \count( $b ) ) {
			return true;
		}

		return false;
	}

	// Whether two fixed pieces can't match the same segment: both are plain
	// text and differ, or the text they start with differs.
	private function different_segments( array $a, array $b ) : bool {
		if ( ! $a['fixed'] || ! $b['fixed'] ) {
			return false;
		}

		if ( $a['literal'] && $b['literal'] ) {
			if ( $a['text'] !== $b['text'] ) {
				return true;
			}

			return false;
		}

		$length = \min( \strlen( $a['text'] ), \strlen( $b['text'] ) );
		if ( \strncmp( $a['text'], $b['text'], $length ) !== 0 ) {
			return true;
		}

		return false;
	}

	// Writes a tree node as a regex. $position counts the leaves written so
	// far, and $ordered is false for the any table. Each child becomes an
	// alternative: [ 'text' => its literal text, 'rest' => the regex after
	// that, its children included, 'literal' => true when it has no
	// parameters ]. A leaf has no text and a rest of its end.
	private function render( array $node, array $ends, int &$position, bool $ordered ) : string {
		$alternatives = [];
		foreach ( $node['children'] as $child ) {
			if ( $child['piece'] === null ) {
				$alternatives[] = [ 'text' => '', 'rest' => $ends[$position], 'literal' => true ];
				$position++;
				continue;
			}

			$piece = $child['piece'];
			$rest = \substr( $piece['regex'], \strlen( \preg_quote( $piece['text'] ) ) );
			$alternatives[] = [
				'text' => $piece['text'],
				'rest' => $rest . $this->render( $child, $ends, $position, $ordered ),
				'literal' => $piece['literal'],
			];
		}

		$regex = $this->alternation( $alternatives, 0, $ordered );
		return $regex;
	}

	// Writes alternatives whose text up to $offset is already matched, with
	// the ones that go on with the same character sharing it, so PCRE tries
	// one alternative per character instead of all of them:
	// /a(?|ccounts...|pps...)|/balance... instead of /accounts...|/apps...|/balance...
	//
	// PCRE tries alternatives in order, and that order decides which route
	// wins. Moving one ahead of others is safe only when no path can match
	// both, which is the case when their text differs at $offset. An
	// alternative whose text runs out before a parameter can match anything
	// there, so nothing moves past it. A leaf, or plain text that runs out,
	// is next followed by / or the end, which no other text has there.
	private function alternation( array $alternatives, int $offset, bool $ordered ) : string {
		$groups = [];
		$open = [];
		foreach ( $alternatives as $alternative ) {
			$key = 'end';
			if ( \strlen( $alternative['text'] ) > $offset ) {
				$key = $alternative['text'][$offset];
			} elseif ( ! $alternative['literal'] ) {
				$key = 'param';
			}

			if ( $key === 'param' && $ordered ) {
				$groups[] = [ $alternative ];
				$open = [];
				continue;
			}

			if ( isset( $open[$key] ) ) {
				$groups[$open[$key]][] = $alternative;
				continue;
			}

			$open[$key] = \count( $groups );
			$groups[] = [ $alternative ];
		}

		$regexes = [];
		foreach ( $groups as $group ) {
			$regexes[] = $this->group_regex( $group, $offset, $ordered );
		}

		$regex = $regexes[0];
		if ( \count( $regexes ) > 1 ) {
			$regex = '(?|' . \implode( '|', $regexes ) . ')';
		}

		return $regex;
	}

	// A group's shared text after $offset, then its alternatives.
	private function group_regex( array $group, int $offset, bool $ordered ) : string {
		$first = $group[0]['text'];
		if ( \count( $group ) === 1 ) {
			$regex = \preg_quote( \substr( $first, $offset ) ) . $group[0]['rest'];
			return $regex;
		}

		$length = \strlen( $first ) - $offset;
		foreach ( $group as $alternative ) {
			$length = \min( $length, \strlen( $alternative['text'] ) - $offset );
			while ( $length > 0 && \strncmp( \substr( $alternative['text'], $offset ), \substr( $first, $offset ), $length ) !== 0 ) {
				$length--;
			}
		}

		if ( $length > 0 ) {
			$regex = \preg_quote( \substr( $first, $offset, $length ) ) . $this->alternation( $group, $offset + $length, $ordered );
			return $regex;
		}

		$regexes = [];
		foreach ( $group as $alternative ) {
			$regexes[] = \preg_quote( \substr( $alternative['text'], $offset ) ) . $alternative['rest'];
		}

		$regex = '(?|' . \implode( '|', $regexes ) . ')';
		return $regex;
	}

	// Returns the tables to build: each method used by a route, in the order
	// of the allowed list, then '*' if any route uses it.
	private function table_keys( array $parsed, array $allowed ) : array {
		$used = [];
		foreach ( $parsed as $entry ) {
			foreach ( $entry['methods'] as $method ) {
				$used[$method] = true;
			}
		}

		$keys = [];
		foreach ( $allowed as $method ) {
			if ( isset( $used[$method] ) ) {
				$keys[] = $method;
			}
		}

		if ( isset( $used['*'] ) ) {
			$keys[] = '*';
		}

		return $keys;
	}

	private function applies( array $methods, string $table ) : bool {
		if ( $methods === [ '*' ] || \in_array( $table, $methods, true ) ) {
			return true;
		}

		return false;
	}

	// The regex has to compile on its own, so it can't break out of the group
	// it's placed in, and it can't add capturing groups of its own. Nor can
	// it use a verb like (*ACCEPT), (*COMMIT) or (*MARK): the matcher tells
	// which route matched by its groups or its MARK, and the routes after it
	// have to stay in play. Escapes and character classes are masked first,
	// so \(* and [(*] aren't taken for verbs.
	private function regex_error( string $regex ) : string {
		if ( isset( $this->regex_errors[$regex] ) ) {
			$error = $this->regex_errors[$regex];
			return $error;
		}

		$matches = [];
		$error = $this->compile_error( '{' . $regex . '}' );
		if ( $error === '' ) {
			$error = $this->compile_error( '{(' . $regex . ')|}', $matches );
		}

		if ( $error !== '' ) {
			$error = "doesn't compile: " . $error;
		} elseif ( \count( $matches ) !== 2 ) {
			$error = 'has a capturing group; use (?:...) instead';
		} elseif ( \str_contains( (string) \preg_replace( [ '{\\\\.}s', '{\[\^?\]?[^\]]*\]}' ], 'x', $regex ), '(*' ) ) {
			$error = "has a (*...) verb, which parameters can't use";
		}

		$this->regex_errors[$regex] = $error;
		return $error;
	}

	// Checks that a regex compiles by matching it against ''. Returns '' when
	// it does, or PCRE's message when it doesn't. A temporary error handler
	// takes PCRE's warning, so it never reaches the app's own error handler.
	// With PREG_UNMATCHED_AS_NULL, $matches has an entry for every group.
	private function compile_error( string $regex, array &$matches = [] ) : string {
		$error = '';
		\set_error_handler( function( int $number, string $message ) use ( &$error ) : bool {
			$error = \str_replace( 'preg_match(): ', '', $message );
			return true;
		} );
		$result = \preg_match( $regex, '', $matches, \PREG_UNMATCHED_AS_NULL );
		\restore_error_handler();

		if ( $result === false && $error === '' ) {
			$error = \preg_last_error_msg();
		}

		return $error;
	}

	// PHP keeps each compiled regex in a cache, under its string. Once the
	// routes come from the route cache, the matcher's regexes are OPcache's
	// strings, which PHP finds in that cache by their address. If a regex had
	// been run here first, from a string built while compiling, it would stay
	// cached under a copy of that string, and every later match in this
	// process would compare the whole regex with the copy. So the checks here
	// run a copy of each regex that starts with an empty comment, (?#), which
	// matches the same way.
	private function check_copy( string $regex ) : string {
		$copy = '{(?#)' . \substr( $regex, 1 );
		return $copy;
	}

	// Explains why a route never matches. $blockers is [ earlier route index => table ].
	private function blocked_message( int $index, array $blockers, array $parsed ) : string {
		\ksort( $blockers );
		$labels = [];
		foreach ( \array_keys( $blockers ) as $blocker ) {
			$labels[] = $this->entries[$blocker]['label'];
		}

		if ( \count( $labels ) > 1 ) {
			$message = 'never matches, because routes ' . $this->join( $labels ) . ' come first and match the same requests';
			return $message;
		}

		$blocker = \array_key_first( $blockers );
		if (
			$parsed[$blocker]['methods'] === $parsed[$index]['methods']
			&& $parsed[$blocker]['route'][1] === $parsed[$index]['route'][1]
		) {
			$message = 'duplicate of route ' . $labels[0];
			return $message;
		}

		$message = 'never matches, because route ' . $labels[0] . ' comes first and matches the same requests';
		if (
			$blockers[$blocker] === 'HEAD'
			&& ! \in_array( 'GET', $parsed[$index]['methods'], true )
			&& \in_array( 'GET', $parsed[$blocker]['methods'], true )
		) {
			$message .= ' (a GET route also answers HEAD)';
		}

		return $message;
	}

	// [ '1', '2', '4.1' ] => '1, 2 and 4.1'
	private function join( array $labels ) : string {
		$last = \array_pop( $labels );
		$joined = \implode( ', ', $labels ) . ' and ' . $last;
		return $joined;
	}

	private function route_problem( int $index, string $message ) : void {
		$this->entry_problem( $this->entries[$index]['name'], $message );
	}

	// A route's regex goes into several tables, so the same problem can come
	// up more than once.
	private function entry_problem( int $name, string $message ) : void {
		if ( ! \in_array( $message, $this->entry_problems[$name] ?? [], true ) ) {
			$this->entry_problems[$name][] = $message;
		}
	}

	// The dynamic tables with check_copy() of each regex.
	private function check_copies( array $tables ) : array {
		$copies = [];
		foreach ( $tables as $table => $regexes ) {
			$copies[$table] = [];
			foreach ( $regexes as $regex => $leaves ) {
				$copies[$table][$this->check_copy( $regex )] = $leaves;
			}
		}

		return $copies;
	}

	private function file_exists( string $file ) : bool {
		if ( ! $this->check_files || \is_file( $this->path( $file ) ) ) {
			return true;
		}

		return false;
	}

	private function path( string $file ) : string {
		$path = $this->handlers . '/' . $file;
		return $path;
	}

	// Shows a route entry the way it's written in the routes file. Strings
	// aren't escaped, so a regex reads the same as it does there.
	private function export( mixed $value ) : string {
		if ( \is_string( $value ) ) {
			$exported = "'" . $value . "'";
			return $exported;
		}

		if ( \is_object( $value ) ) {
			$exported = \get_debug_type( $value );
			return $exported;
		}

		if ( ! \is_array( $value ) ) {
			$exported = \strtolower( \var_export( $value, true ) );
			return $exported;
		}

		$parts = [];
		$is_list = \array_is_list( $value );
		foreach ( $value as $key => $item ) {
			$part = $this->export( $item );
			if ( ! $is_list ) {
				$part = $this->export( $key ) . ' => ' . $part;
			}

			$parts[] = $part;
		}

		$exported = '[]';
		if ( $parts !== [] ) {
			$exported = '[ ' . \implode( ', ', $parts ) . ' ]';
		}

		return $exported;
	}
}

/**
 * Escapes data for where it goes in a page: html() between tags, html_attr()
 * in an attribute's value, url() for a link, js() in JavaScript and css() in
 * CSS. Each returns the escaped text, for a template to print:
 *
 *     use Tillkite\Escape as Esc;
 *     <a href="<?= Esc::url( $link ) ?>"><?= Esc::html( $name ) ?></a>
 *
 * The page is UTF-8 unless set_encoding() names another encoding. Invalid
 * UTF-8 becomes U+FFFD. In another encoding, what isn't valid becomes
 * mbstring's substitute character. Nothing is thrown.
 */
final class Escape {
	private const int HTML = \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML401;

	// What htmlspecialchars() changes in valid UTF-8, for strtr(), which
	// takes far less time for each byte. But checking that text is valid
	// UTF-8 takes about as long as htmlspecialchars() takes for 16 bytes, so
	// strtr() is used only for text at least this long: HTML_LONG bytes for
	// html(), and ATTR_LONG for html_attr() and url(), which save a second
	// strtr() too. Invalid UTF-8 needs htmlspecialchars(), which replaces it
	// with U+FFFD.
	private const array HTML_CHARS = [ '&' => '&amp;', '"' => '&quot;', "'" => '&#039;', '<' => '&lt;', '>' => '&gt;' ];

	private const int HTML_LONG = 48;

	private const int ATTR_LONG = 24;

	// What ends an unquoted attribute value, whitespace, and what old
	// browsers took as its quotes or its start, the backtick and =. HTML
	// already escapes the rest that matter: & < > " '.
	private const array ATTR = [ "\t" => '&#9;', "\n" => '&#10;', "\f" => '&#12;', "\r" => '&#13;', ' ' => '&#32;', '=' => '&#61;', '`' => '&#96;' ];

	// HTML_CHARS and ATTR in one table, for one strtr() over valid UTF-8.
	private const array HTML_ATTR = self::HTML_CHARS + self::ATTR;

	// Every character outside ASCII is escaped, as some, like U+00A0, are
	// whitespace in JavaScript. JSON_HEX_QUOT means a string never holds a
	// ", so each pair of them is one string. JSON_UNESCAPED_SLASHES means no
	// \/, which the JS table would break. JSON_PARTIAL_OUTPUT_ON_ERROR means
	// json_encode() never returns false: what it can't encode becomes null
	// or 0.
	private const int JSON = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR;

	// The ASCII characters that json_encode() leaves as they are in a
	// string, but letters and digits. With these escaped too, a string holds
	// only letters, digits and escapes, so its contents can't become code,
	// even inside quotes written around it.
	private const array JS = [
		' ' => '\\u0020', '!' => '\\u0021', '#' => '\\u0023', '$' => '\\u0024', '%' => '\\u0025',
		'(' => '\\u0028', ')' => '\\u0029', '*' => '\\u002A', '+' => '\\u002B', ',' => '\\u002C',
		'-' => '\\u002D', '.' => '\\u002E', '/' => '\\u002F', ':' => '\\u003A', ';' => '\\u003B',
		'=' => '\\u003D', '?' => '\\u003F', '@' => '\\u0040', '[' => '\\u005B', ']' => '\\u005D',
		'^' => '\\u005E', '_' => '\\u005F', '`' => '\\u0060', '{' => '\\u007B', '|' => '\\u007C',
		'}' => '\\u007D', '~' => '\\u007E', "\x7F" => '\\u007F',
	];

	// Every ASCII character but letters and digits, as a CSS escape with six
	// hex digits, so a letter or digit after it is never read as part of it.
	private const array CSS = [
		"\x00" => '\\000000', "\x01" => '\\000001', "\x02" => '\\000002', "\x03" => '\\000003', "\x04" => '\\000004',
		"\x05" => '\\000005', "\x06" => '\\000006', "\x07" => '\\000007', "\x08" => '\\000008', "\x09" => '\\000009',
		"\x0A" => '\\00000A', "\x0B" => '\\00000B', "\x0C" => '\\00000C', "\x0D" => '\\00000D', "\x0E" => '\\00000E',
		"\x0F" => '\\00000F', "\x10" => '\\000010', "\x11" => '\\000011', "\x12" => '\\000012', "\x13" => '\\000013',
		"\x14" => '\\000014', "\x15" => '\\000015', "\x16" => '\\000016', "\x17" => '\\000017', "\x18" => '\\000018',
		"\x19" => '\\000019', "\x1A" => '\\00001A', "\x1B" => '\\00001B', "\x1C" => '\\00001C', "\x1D" => '\\00001D',
		"\x1E" => '\\00001E', "\x1F" => '\\00001F', ' ' => '\\000020', '!' => '\\000021', '"' => '\\000022',
		'#' => '\\000023', '$' => '\\000024', '%' => '\\000025', '&' => '\\000026', "\x27" => '\\000027',
		'(' => '\\000028', ')' => '\\000029', '*' => '\\00002A', '+' => '\\00002B', ',' => '\\00002C',
		'-' => '\\00002D', '.' => '\\00002E', '/' => '\\00002F', ':' => '\\00003A', ';' => '\\00003B',
		'<' => '\\00003C', '=' => '\\00003D', '>' => '\\00003E', '?' => '\\00003F', '@' => '\\000040',
		'[' => '\\00005B', "\x5C" => '\\00005C', ']' => '\\00005D', '^' => '\\00005E', '_' => '\\00005F',
		'`' => '\\000060', '{' => '\\00007B', '|' => '\\00007C', '}' => '\\00007D', '~' => '\\00007E',
		"\x7F" => '\\00007F',
	];

	// Every character outside ASCII, for mb_encode_numericentity().
	private const array NON_ASCII = [ 0x80, 0x10FFFF, 0, 0x1FFFFF ];

	// The encodings set_encoding() takes, by their names in the WHATWG
	// Encoding Standard, which browsers follow, and mbstring's names for the
	// encodings browsers read them as, or the closest: a browser reads a page
	// that says iso-8859-1 as windows-1252, and gbk as gb18030. mbstring
	// reads some characters differently from browsers, mostly in big5 and
	// euc-jp, which changes how they look, never what the page does. In
	// each, a byte under 0x80 that isn't part of a longer character is that
	// ASCII character.
	private const array ENCODINGS = [
		'utf-8' => 'UTF-8',
		'iso-8859-1' => 'Windows-1252',
		'iso-8859-2' => 'ISO-8859-2',
		'iso-8859-5' => 'ISO-8859-5',
		'iso-8859-7' => 'ISO-8859-7',
		'iso-8859-15' => 'ISO-8859-15',
		'windows-1251' => 'Windows-1251',
		'windows-1252' => 'Windows-1252',
		'windows-1254' => 'Windows-1254',
		'ibm866' => 'CP866',
		'koi8-r' => 'KOI8-R',
		'koi8-u' => 'KOI8-U',
		'shift_jis' => 'CP932',
		'euc-jp' => 'eucJP-win',
		'euc-kr' => 'UHC',
		'gbk' => 'GB18030',
		'gb18030' => 'GB18030',
		'big5' => 'CP950',
	];

	// The page's encoding, by mbstring's name for it.
	private static string $encoding = 'UTF-8';

	// Sets the page's encoding, which the text each method takes is in, by
	// its name in the WHATWG Encoding Standard, in any case, like Shift_JIS.
	// Another encoding than UTF-8 needs the mbstring extension. Its text is
	// converted to UTF-8 to be escaped, and the output is all ASCII, with
	// every other character escaped, so it reads the same in any of these
	// encodings, and no byte of a longer character can take in an escape's
	// first byte, like the \ that's the second byte of some in Shift_JIS. An
	// encoding it doesn't take is logged, and the encoding stays as it was.
	public static function set_encoding( string $encoding ) : bool {
		$set = false;
		$name = self::ENCODINGS[\strtolower( $encoding )] ?? '';
		if ( $name === '' ) {
			\error_log( "Tillkite: Escape::set_encoding() doesn't take " . self::log( $encoding ) . ", so the encoding didn't change" );
			return $set;
		}

		if ( $name !== 'UTF-8' && ! \function_exists( 'mb_convert_encoding' ) ) {
			\error_log( 'Tillkite: Escape::set_encoding( ' . self::log( $encoding ) . " ) needs the mbstring extension, so the encoding didn't change" );
			return $set;
		}

		self::$encoding = $name;
		$set = true;
		return $set;
	}

	// For text between tags: & < > " ' as entities. It's safe for plain text
	// in a quoted attribute too, but html_attr() is safe without quotes.
	public static function html( string|int|float|\Stringable|null $value ) : string {
		// UTF-8 is escaped here, without a call to escape_html(), which would
		// take a quarter of html()'s time.
		$text = (string) $value;
		if ( self::$encoding !== 'UTF-8' ) {
			$html = self::escape_html( self::utf8( $text ) );
		} elseif ( \strlen( $text ) >= self::HTML_LONG && \preg_match( '//u', $text ) === 1 ) {
			$html = \strtr( $text, self::HTML_CHARS );
		} else {
			$html = \htmlspecialchars( $text, self::HTML, 'UTF-8' );
		}

		return $html;
	}

	// For an attribute's value that's plain text, quoted or not: html(), and
	// whitespace, = and the backtick as entities. An empty value without
	// quotes lets the parser take what comes after it as the value, so quote
	// an attribute that can be empty. A URL, an event handler or a style
	// attribute needs url(), html_attr( js() ) or css() instead.
	public static function html_attr( string|int|float|\Stringable|null $value ) : string {
		$text = (string) $value;
		if ( self::$encoding !== 'UTF-8' ) {
			$attr = \strtr( self::html( $text ), self::ATTR );
		} elseif ( \strlen( $text ) >= self::ATTR_LONG && \preg_match( '//u', $text ) === 1 ) {
			$attr = \strtr( $text, self::HTML_ATTR );
		} else {
			$attr = \strtr( \htmlspecialchars( $text, self::HTML, 'UTF-8' ), self::ATTR );
		}

		return $attr;
	}

	// For a URL in an attribute, like href or src. A URL with a scheme that
	// isn't in $schemes, which are in lowercase, becomes about:invalid, so
	// javascript: and data: URLs can't run. The scheme is checked as a
	// browser reads it, without the control characters and spaces at either
	// end, or a tab or newline inside, and after the URL is converted to
	// UTF-8, which can take bytes out. Then it's escaped as html_attr() does.
	public static function url( string $url, array $schemes = [ 'http', 'https', 'mailto', 'tel' ] ) : string {
		if ( self::$encoding !== 'UTF-8' ) {
			$url = self::utf8( $url );
		}

		$url = \str_replace( [ "\t", "\n", "\r" ], '', \trim( $url, "\x00..\x20" ) );
		if ( \preg_match( '/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match ) === 1 && ! \in_array( \strtolower( $match[1] ), $schemes, true ) ) {
			$url = 'about:invalid';
		}

		if ( self::$encoding !== 'UTF-8' ) {
			$escaped = \strtr( self::escape_html( $url ), self::ATTR );
		} elseif ( \strlen( $url ) >= self::ATTR_LONG && \preg_match( '//u', $url ) === 1 ) {
			$escaped = \strtr( $url, self::HTML_ATTR );
		} else {
			$escaped = \strtr( \htmlspecialchars( $url, self::HTML, 'UTF-8' ), self::ATTR );
		}

		return $escaped;
	}

	// For JavaScript: any value as a JavaScript value, quotes included,
	// which is JSON too, and all ASCII. In a string, every character but
	// letters and digits is escaped, so it's safe in <script>, and inside
	// quotes someone wrote around it, where it's an error, not code. In an
	// attribute, like onclick, escape it again with html_attr(). What JSON
	// can't hold, like INF, is logged, and becomes 0 or null. In another
	// encoding than UTF-8, an object's strings need to be UTF-8 already.
	public static function js( mixed $value ) : string {
		if ( self::$encoding !== 'UTF-8' ) {
			$value = self::utf8_value( $value, 0 );
		}

		$json = (string) \json_encode( $value, self::JSON );
		if ( \json_last_error() !== \JSON_ERROR_NONE ) {
			\error_log( 'Tillkite: Escape::js() could not encode all of its value: ' . \json_last_error_msg() );
		}

		// A string's output is all one string, which strtr() takes as it is.
		// Otherwise, with JSON_HEX_QUOT, every " starts or ends a string, so
		// every other piece between them is a string's contents, which
		// explode() finds in less time than a regex.
		if ( \is_string( $value ) ) {
			$js = \strtr( $json, self::JS );
		} else {
			$pieces = \explode( '"', $json );
			$count = \count( $pieces );
			for ( $i = 1; $i < $count; $i += 2 ) {
				$pieces[$i] = \strtr( $pieces[$i], self::JS );
			}

			$js = \implode( '"', $pieces );
		}

		return $js;
	}

	// For text in CSS, in a string or as a name, like a font's or a class's:
	// every ASCII character but letters and digits as an escape, like
	// \000020, and a digit at the start, since a name can't start with one.
	// An escape takes in one whitespace character after it, so one at the
	// end gets a space of its own, and a space written after it stays. A
	// number, colour or URL isn't text, so check it instead: escaping turns
	// #fff into a name.
	public static function css( string $value ) : string {
		if ( self::$encoding !== 'UTF-8' ) {
			$value = self::utf8( $value );
		} elseif ( \preg_match( '//u', $value ) !== 1 ) {
			$value = \htmlspecialchars_decode( \htmlspecialchars( $value, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8' ), \ENT_NOQUOTES );
		}

		$css = \strtr( $value, self::CSS );
		if ( self::$encoding !== 'UTF-8' ) {
			$css = (string) \preg_replace_callback( '/[^\x00-\x7F]/u', static fn ( array $char ) : string => \sprintf( '\\%06X', (int) \mb_ord( $char[0], 'UTF-8' ) ), $css );
		}

		// The digits are 0x30 to 0x39, so \00003 and the digit is its escape.
		if ( \strspn( $css, '0123456789', 0, 1 ) === 1 ) {
			$css = '\\00003' . $css;
		}

		// Every \ in the output starts an escape of seven characters, so it
		// ends with one when the seventh character from the end is a \. A
		// regex would read the whole output.
		if ( \substr( $css, -7, 1 ) === '\\' ) {
			$css .= ' ';
		}

		return $css;
	}

	// For text in a log line, like PHP's error log, which someone reads in a
	// terminal or an editor. Control characters, which a terminal can take as
	// commands, like ESC, and which can start a line that looks like another
	// entry, become C-style escapes, like \n and \033. In valid UTF-8, so do
	// the C1 controls, U+0080 to U+009F, which some terminals take as
	// commands too, and the characters that make an editor show text in
	// another order or on another line, U+061C, U+200E, U+200F, U+2028 to
	// U+202E and U+2066 to U+2069, as \u{202E}. Text that isn't valid UTF-8
	// has each byte from 0x80 escaped, like \233. A \ stays as it is, so
	// paths and regexes read as they are, and set_encoding() doesn't change
	// it.
	public static function log( string|int|float|\Stringable|null $value ) : string {
		$log = \addcslashes( (string) $value, "\0..\37\177" );
		if ( \preg_match( '//u', $log ) !== 1 ) {
			$log = \addcslashes( $log, "\200..\377" );
		} else {
			$log = (string) \preg_replace_callback( '/[\x{80}-\x{9F}\x{61C}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u', static fn ( array $char ) : string => \sprintf( '\u{%04X}', self::code_point( $char[0] ) ), $log );
		}

		return $log;
	}

	// The code point of a character of two or three bytes in valid UTF-8,
	// without mbstring, which UTF-8 doesn't need.
	private static function code_point( string $char ) : int {
		$first = \ord( $char[0] );
		$code = ( ( $first & 0x1F ) << 6 ) | ( \ord( $char[1] ) & 0x3F );
		if ( $first >= 0xE0 ) {
			$code = ( ( $first & 0x0F ) << 12 ) | ( ( \ord( $char[1] ) & 0x3F ) << 6 ) | ( \ord( $char[2] ) & 0x3F );
		}

		return $code;
	}

	// UTF-8 text as HTML. For a page in another encoding, each character
	// outside ASCII becomes a numeric character reference, but U+0080 to
	// U+009F become U+FFFD, since HTML reads &#128; to &#159; as
	// windows-1252's characters, so no reference can be one of them.
	private static function escape_html( string $utf8 ) : string {
		$html = \htmlspecialchars( $utf8, self::HTML, 'UTF-8' );
		$html = \mb_encode_numericentity( (string) \preg_replace( '/[\x{80}-\x{9F}]/u', "\xEF\xBF\xBD", $html ), self::NON_ASCII, 'UTF-8' );
		return $html;
	}

	// Text from the page's encoding, in UTF-8. What isn't valid in it becomes
	// mbstring's substitute character, ? unless mbstring.substitute_character
	// says otherwise.
	private static function utf8( string $text ) : string {
		$utf8 = (string) \mb_convert_encoding( $text, 'UTF-8', self::$encoding );
		return $utf8;
	}

	// A value for js(), with each string and key in it in UTF-8, through its
	// arrays as deep as json_encode() goes, 512, so a recursive array ends.
	// mb_convert_encoding() takes an array, but drops an object in one, with
	// a warning, so an object stays as it is.
	private static function utf8_value( mixed $value, int $depth ) : mixed {
		$utf8 = $value;
		if ( \is_string( $value ) ) {
			$utf8 = self::utf8( $value );
		} elseif ( \is_array( $value ) && $depth < 512 ) {
			$utf8 = [];
			foreach ( $value as $key => $item ) {
				if ( \is_string( $key ) ) {
					$key = self::utf8( $key );
				}

				$utf8[$key] = self::utf8_value( $item, $depth + 1 );
			}
		}

		return $utf8;
	}
}

/**
 * Finds the route that matches a request, using the tables built by Compiler.
 *
 * @internal
 */
final class Matcher {
	// One property, because each one costs time to set on every request.
	public function __construct( private array $compiled ) {}

	/**
	 * Matches a request. $path is the raw path, without the query string or
	 * base. Returns one of:
	 *
	 * - [ 'status' => 200, 'route' => 3, 'params' => [ 'id' => '42' ] ]
	 * - [ 'status' => 405, 'allow' => [ 'GET', 'HEAD' ] ]
	 * - [ 'status' => 308, 'path' => '/users' ]
	 * - [ 'status' => 404 ]
	 * - [ 'status' => 500, 'error' => 'Backtrack limit exhausted' ]
	 *
	 * A 404, 405 or 308 for a path under a group's prefix also has 'group',
	 * the group's key in groups, and 'params', its prefix's parameters, like
	 * [ 'status' => 404, 'group' => 0, 'params' => [ 'id' => '42' ] ].
	 *
	 * The usual cases, a static or dynamic route that matches, are written out
	 * here instead of calling find(), because every function call costs time.
	 * find_dynamic() is the same code.
	 */
	public function match( string $method, string $path ) : array {
		$result = $this->compiled['static'][$method][$path] ?? null;
		if ( $result !== null ) {
			return $result;
		}

		// Every method with routes has a dynamic table, even an empty one. A
		// method without uses the '*' table, if there is one.
		$table = $method;
		$regexes = $this->compiled['dynamic'][$method] ?? null;
		if ( $regexes === null ) {
			$table = '*';
			$result = $this->compiled['static']['*'][$path] ?? null;
			if ( $result !== null ) {
				return $result;
			}

			$regexes = $this->compiled['dynamic']['*'] ?? [];
		}

		foreach ( $regexes as $regex => $leaves ) {
			$matched = \preg_match( $regex, $path, $matches );
			if ( $matched === 1 ) {
				// A leaf is the match, with the parameter names where their
				// values go, found by its MARK or by the number of matches.
				$result = $leaves[$matches['MARK'] ?? \count( $matches )];
				$params = [];
				foreach ( $result['params'] as $i => $name ) {
					$params[$name] = $matches[$i + 1];
				}

				// Only a path with a % has anything to decode, only one with ..
				// can have a .. segment, which no parameter takes, and only one
				// with a \ can have one in {name}, which doesn't take it.
				$result['params'] = $params;
				if ( \strpbrk( $path, '%\\' ) !== false || \str_contains( $path, '..' ) ) {
					$result = $this->decode( $result );
					if ( $result['status'] === 404 && $this->compiled['prefixes'] !== '' ) {
						$result = $this->group( $result, $path );
					}
				}

				return $result;
			}

			if ( $matched === false ) {
				$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
				return $result;
			}
		}

		// Only a miss looks for the group it's under, so a match costs
		// nothing more.
		$result = $this->miss( $table, $path );
		if ( $result['status'] !== 500 && $this->compiled['prefixes'] !== '' ) {
			$result = $this->group( $result, $path );
		}

		return $result;
	}

	// Checks only the dynamic routes in one table, the same way match() does.
	// Compiler uses this to find static routes that an earlier dynamic route
	// hides.
	public function find_dynamic( string $table, string $path ) : array {
		$result = [ 'status' => 404 ];
		foreach ( $this->compiled['dynamic'][$table] ?? [] as $regex => $leaves ) {
			$matched = \preg_match( $regex, $path, $matches );
			if ( $matched === 1 ) {
				$result = $leaves[$matches['MARK'] ?? \count( $matches )];
				$params = [];
				foreach ( $result['params'] as $i => $name ) {
					$params[$name] = $matches[$i + 1];
				}

				$result['params'] = $params;
				$result = $this->decode( $result );
				break;
			}

			if ( $matched === false ) {
				$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
				break;
			}
		}

		return $result;
	}

	// Returns a route's match with its parameters decoded, or a 404 when one
	// fails its check, or a 500 when PCRE fails. So a route never gets a value
	// that its regex would turn away, but the routes after it aren't tried,
	// because PCRE has already chosen between them.
	private function decode( array $result ) : array {
		$decoded = $this->decoded( $result['params'], $this->compiled['checks'][$result['route']] ?? [] );
		if ( $decoded['status'] !== 200 ) {
			return $decoded;
		}

		$result['params'] = $decoded['params'];
		return $result;
	}

	// Decodes parameters, and checks each one that was encoded. One with a
	// regex of its own has to match the regex for it in $checks, unless
	// that's '', for a regex written for the encoded form. See
	// Compiler::checks(). One without has the default regex, which takes one
	// path segment, so its value can't decode to a /, which would make it
	// more than one in a file path, or have a \, which Windows reads as a /,
	// encoded or not. A browser sends a \ as a /, but nginx passes on a raw
	// one, which [^/]+ would take.
	//
	// No parameter, whatever its regex, takes a value with a .. segment,
	// between / or \ or at either end, encoded or not: in a file path it
	// leaves the directory, and a browser takes .. segments out of a URL
	// before it sends it, so only a request made by hand has one. It's the
	// check that App::template() makes of a template's name. Nor does one
	// take a null byte, which no URL has a use for: PHP's file functions throw
	// for one, and code in C that a value is handed to stops reading there.
	// It comes only as %00, since web servers turn away a request with a raw
	// one.
	// Returns [ 'status' => 200, 'params' => [ ... ] ], or a 404 or a 500.
	private function decoded( array $params, array $checks ) : array {
		foreach ( $params as $name => $value ) {
			$params[$name] = \rawurldecode( $value );
			if ( \str_contains( $params[$name], "\0" ) || ( \str_contains( $params[$name], '..' ) && \preg_match( '{(?:^|[/\\\\])\.\.(?:[/\\\\]|$)}', $params[$name] ) === 1 ) ) {
				$result = [ 'status' => 404 ];
				return $result;
			}

			$check = $checks[$name] ?? null;
			if ( $check === null && \strpbrk( $params[$name], '/\\' ) !== false ) {
				$result = [ 'status' => 404 ];
				return $result;
			}

			if ( $params[$name] === $value ) {
				continue;
			}

			if ( $check === null || $check === '' ) {
				continue;
			}

			$matched = \preg_match( $check, $params[$name] );
			if ( $matched === false ) {
				$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
				return $result;
			}

			if ( $matched === 0 ) {
				$result = [ 'status' => 404 ];
				return $result;
			}
		}

		$result = [ 'status' => 200, 'params' => $params ];
		return $result;
	}

	// Adds the group whose prefix the path is under, if there is one, to a
	// miss. When the regex fails, the miss is a 500, so a group's
	// before-files are never skipped. A prefix whose parameter fails its
	// check isn't one the path is under, as if the value weren't encoded.
	private function group( array $result, string $path ) : array {
		$matched = \preg_match( $this->compiled['prefixes'], $path, $matches );
		if ( $matched === false ) {
			$result = [ 'status' => 500, 'error' => \preg_last_error_msg() ];
			return $result;
		}

		if ( $matched === 0 ) {
			return $result;
		}

		$key = (int) $matches['MARK'];
		$params = [];
		foreach ( $this->compiled['groups'][$key]['names'] as $i => $name ) {
			$params[$name] = $matches[$i + 1];
		}

		$decoded = $this->decoded( $params, $this->compiled['groups'][$key]['checks'] );
		if ( $decoded['status'] === 500 ) {
			return $decoded;
		}

		if ( $decoded['status'] === 404 ) {
			return $result;
		}

		$result['group'] = $key;
		$result['params'] = $decoded['params'];
		return $result;
	}

	// Nothing matched. The first check, across every method and with or
	// without the trailing slash, settles most misses. Only a path that some
	// route matches needs the checks for a 405 or a 308.
	private function miss( string $table, string $path ) : array {
		$result = [ 'status' => 404 ];
		if ( ! \str_starts_with( $path, '/' ) || ! $this->anywhere( $path ) ) {
			return $result;
		}

		$result = $this->allowed( $table, $path );
		if ( $result['status'] !== 404 ) {
			return $result;
		}

		$result = $this->redirect( $table, $path );
		return $result;
	}

	// Whether any route, for any method, matches the path, or the path with
	// its trailing slash added or removed. It may say yes when none does, but
	// never no when one does. The regexes end in /?$, so matching them
	// against the path with one trailing slash covers both.
	private function anywhere( string $path ) : bool {
		$base = $path;
		if ( $path !== '/' && \str_ends_with( $path, '/' ) ) {
			$base = \substr( $path, 0, -1 );
		}

		$subject = $base . '/';
		if ( $path === '/' ) {
			$subject = $path;
		}

		if ( isset( $this->compiled['any']['static'][$base] ) || isset( $this->compiled['any']['static'][$subject] ) ) {
			return true;
		}

		foreach ( $this->compiled['any']['dynamic'] as $regex => $leaves ) {
			// A PCRE error is a yes, so the full checks report it.
			if ( \preg_match( $regex, $subject ) !== 0 ) {
				return true;
			}
		}

		return false;
	}

	// The static table is checked first. Compiler has already removed any
	// static path that an earlier dynamic route matches.
	private function find( string $table, string $path ) : array {
		$result = $this->compiled['static'][$table][$path] ?? $this->find_dynamic( $table, $path );
		return $result;
	}

	// Looks for routes with other methods that match the path, for a 405.
	private function allowed( string $table, string $path ) : array {
		$allow = [];
		foreach ( \array_keys( $this->compiled['static'] ) as $other ) {
			if ( $other === '*' || $other === $table ) {
				continue;
			}

			$found = $this->find( $other, $path );
			if ( $found['status'] === 500 ) {
				return $found;
			}

			if ( $found['status'] === 200 ) {
				$allow[] = $other;
			}
		}

		$result = [ 'status' => 404 ];
		if ( $allow !== [] ) {
			$result = [ 'status' => 405, 'allow' => $allow ];
		}

		return $result;
	}

	// Adds or removes the trailing slash, and redirects if that matches.
	private function redirect( string $table, string $path ) : array {
		$result = [ 'status' => 404 ];
		if ( $path === '/' ) {
			return $result;
		}

		$other = $path . '/';
		if ( \str_ends_with( $path, '/' ) ) {
			$other = \substr( $path, 0, -1 );
		}

		$found = $this->find( $table, $other );
		if ( $found['status'] === 500 ) {
			$result = $found;
		} elseif ( $found['status'] === 200 ) {
			$result = [ 'status' => 308, 'path' => $other ];
		}

		return $result;
	}
}

/**
 * Runs the routes file, before-files, handlers, error files and templates,
 * each in a function scope of its own.
 *
 * The files run inside these static methods, so there's no $this, and their
 * class scope is this class, which has nothing private to reach. App's
 * private members stay out of reach. Their variables start with only the
 * ones each method names. The file paths come in as extra arguments, read
 * with func_get_arg(), so they aren't variables the files can see.
 *
 * @internal
 */
final class Runner {
	// Returns what the routes file returns. Its scope starts empty.
	public static function routes() : mixed {
		$routes = require \func_get_arg( 0 );
		return $routes;
	}

	// Runs up to three before-files, in order, then the handler, in one
	// scope, with $tillkite and the route's $params. Compiler has worked out
	// which before-files a route runs, and the paths of the ones it doesn't
	// are '', after the ones it does. A before-file that returns false stops
	// the files after it. The error files run here too, as a handler with no
	// before-files, and so do the before-files when no route matches, with no
	// handler.
	//
	// The last argument is App's next_file(), which runs before each
	// before-file after the first, with 'before', and before the handler,
	// with 'handler'. It returns false once a file has called error(), or a
	// before-file has set a redirect or error status, and then the files
	// after it don't run. When no route matches, it returns false in the
	// handler's place.
	public static function files( App $tillkite, array $params ) : void {
		if ( \func_get_arg( 2 ) !== '' && ( require \func_get_arg( 2 ) ) === false ) {
			return;
		}

		if ( \func_get_arg( 3 ) !== '' && ( ! \func_get_arg( 6 )( 'before' ) || ( require \func_get_arg( 3 ) ) === false ) ) {
			return;
		}

		if ( \func_get_arg( 4 ) !== '' && ( ! \func_get_arg( 6 )( 'before' ) || ( require \func_get_arg( 4 ) ) === false ) ) {
			return;
		}

		if ( \func_get_arg( 6 )( 'handler' ) ) {
			require \func_get_arg( 5 );
		}
	}

	// Runs the 500 file, with the $params of the files that were running, and
	// the $error that stopped them.
	public static function error_file( App $tillkite, array $params, \Throwable $error ) : void {
		require \func_get_arg( 3 );
	}

	// Runs a template, with $data as App::template() was given it. Its output
	// goes to the buffer that template() started.
	public static function template( App $tillkite, array $data ) : void {
		require \func_get_arg( 2 );
	}
}

/**
 * Makes a new site, for `vendor/bin/tillkite new my-site` with Composer, and
 * `php tillkite.php new my-site` with the dist file: init.php, for what the
 * front controller and any scripts share, a front controller,
 * routes, a layout template, two pages, a before-file that sends security
 * headers, and the 404, 405 and 500 pages. It never overwrites a file.
 *
 * Only the command line uses it. Composer's autoloader loads it only then,
 * and in the dist file it's one more class.
 *
 * @internal
 */
final class Starter {
	// Runs the command in $argv and returns its exit code. $loader is the
	// file that loads Tillkite: Composer's autoloader, which the site's
	// init.php requires, or with $copy, the dist file, which is copied into
	// the site as lib/tillkite.php, for its index.php to require.
	public static function main( array $argv, string $loader, bool $copy ) : int {
		$command = $argv[0] ?? 'tillkite';
		if ( $copy ) {
			$command = 'php ' . $command;
		}

		$usage = 'Usage: ' . $command . " new [directory]\n\n"
			. "Makes a Tillkite site in the directory, or the current one: init.php,\n"
			. "for what the web and scripts share, a front controller in public/,\n"
			. "routes, a layout template, two pages, a before-file that sends\n"
			. "security headers, and the 404, 405 and 500 pages. It never\n"
			. "overwrites a file.\n";

		$args = \array_slice( $argv, 1 );
		if ( $args === [] || $args === [ 'help' ] || $args === [ '--help' ] || $args === [ '-h' ] ) {
			echo $usage;
			return 0;
		}

		if ( $args[0] !== 'new' || \count( $args ) > 2 ) {
			\fwrite( \STDERR, $usage );
			return 1;
		}

		$code = self::make_site( $args[1] ?? '.', $loader, $copy );
		return $code;
	}

	private static function make_site( string $dir, string $loader, bool $copy ) : int {
		$files = self::files();
		$conflicts = [];
		$dirs = [];
		foreach ( \array_keys( $files ) as $path ) {
			if ( \file_exists( $dir . '/' . $path ) || \is_link( $dir . '/' . $path ) ) {
				$conflicts[] = $path;
			}

			$dirs[\dirname( $path )] = true;
		}

		// A symlink in place of a directory the site uses would send its files
		// somewhere else, so it's in the way, like a file. A symlink in place
		// of a file, even to nothing, would have new write through it.
		unset( $dirs['.'] );
		if ( $copy ) {
			$dirs['lib'] = true;
		}

		foreach ( \array_keys( $dirs ) as $path ) {
			if ( \is_link( $dir . '/' . $path ) ) {
				$conflicts[] = $path;
			}
		}

		// A dist file that's already the site's lib/tillkite.php needs no copy.
		$tillkite = $dir . '/lib/tillkite.php';
		if ( $copy && ( \file_exists( $tillkite ) || \is_link( $tillkite ) ) && \realpath( $tillkite ) !== \realpath( $loader ) ) {
			$conflicts[] = 'lib/tillkite.php';
		}

		if ( $conflicts !== [] ) {
			\fwrite( \STDERR, 'These files are already in ' . $dir . ", so nothing was written:\n\n  " . \implode( "\n  ", $conflicts ) . "\n" );
			return 1;
		}

		$error = '';
		\set_error_handler( function( int $number, string $message ) use ( &$error ) : bool {
			if ( $error === '' ) {
				$error = $message;
			}

			return true;
		} );

		// Nothing it makes is writable by every user, whatever the umask, since
		// anyone who could change a handler, or put a file in var/, could run
		// code as the web server.
		$umask = \umask( \umask() | 0o002 );
		$root = self::write( $dir, $files, $loader, $copy );
		\umask( $umask );
		\restore_error_handler();
		if ( $root === '' ) {
			\fwrite( \STDERR, 'Could not make the site in ' . $dir . ': ' . $error . "\n" );
			return 1;
		}

		echo self::next_steps( $dir, $root, \array_keys( $files ), $copy );
		return 0;
	}

	// Writes the files, and returns the site's directory as an absolute
	// path, or '' if a file couldn't be written. The site's init.php
	// requires Composer's autoloader by its path from the site's directory,
	// so the site still works when it moves along with vendor/.
	private static function write( string $dir, array $files, string $loader, bool $copy ) : string {
		$root = '';
		if ( \is_dir( $dir ) || \mkdir( $dir, 0o777, true ) ) {
			$root = (string) \realpath( $dir );
		}

		$from = \realpath( $loader );
		if ( $root === '' || $from === false ) {
			return '';
		}

		// With the dist file, only the web needs Tillkite, so index.php requires
		// its copy. With Composer, init.php requires the autoloader, since
		// scripts need the other packages too.
		$autoload = "//\n";
		$load = "\n// Tillkite, which only the web needs.\nrequire __DIR__ . '/../lib/tillkite.php';\n";
		if ( ! $copy ) {
			$autoload = "\n// Composer's autoloader, which loads Tillkite for public/index.php, and\n// the packages that scripts use.\nrequire " . self::relative( $root, $from ) . ";\n\n";
			$load = '';
		}

		$files['init.php'] = \str_replace( "{autoload}\n", $autoload, $files['init.php'] );
		$files['public/index.php'] = \str_replace( '{tillkite}', $load, $files['public/index.php'] );
		foreach ( $files as $path => $contents ) {
			$parent = \dirname( $root . '/' . $path );
			if ( ! ( \is_dir( $parent ) || \mkdir( $parent, 0o777, true ) ) || ! self::create( $root . '/' . $path, $contents ) ) {
				return '';
			}
		}

		$tillkite = $root . '/lib/tillkite.php';
		if ( $copy && \realpath( $tillkite ) !== $from ) {
			if ( ! ( \is_dir( $root . '/lib' ) || \mkdir( $root . '/lib' ) ) || ! self::create( $tillkite, (string) \file_get_contents( $from ) ) ) {
				return '';
			}
		}

		return $root;
	}

	// Writes a file that isn't there yet, and returns false if anything is
	// at its path. Mode x fails for a file, or a symlink even to nothing,
	// that appeared after make_site() looked, so new never writes over a
	// file, or through a symlink, that someone put there in between.
	private static function create( string $path, string $contents ) : bool {
		$file = \fopen( $path, 'x' );
		if ( $file === false ) {
			return false;
		}

		$written = \fwrite( $file, $contents ) === \strlen( $contents );
		$created = \fclose( $file ) && $written;
		return $created;
	}

	// PHP for the path of $file from the $dir directory, starting from
	// __DIR__. Both are absolute. On Windows, a file on another drive
	// keeps its absolute path.
	private static function relative( string $dir, string $file ) : string {
		$from = \explode( \DIRECTORY_SEPARATOR, $dir );
		$to = \explode( \DIRECTORY_SEPARATOR, $file );
		if ( $from[0] !== $to[0] ) {
			$php = \var_export( $file, true );
			return $php;
		}

		$same = 0;
		while ( isset( $from[$same], $to[$same] ) && $from[$same] === $to[$same] ) {
			$same++;
		}

		$path = \str_repeat( '/..', \count( $from ) - $same ) . '/' . \implode( '/', \array_slice( $to, $same ) );
		$php = '__DIR__ . ' . \var_export( $path, true );
		return $php;
	}

	private static function next_steps( string $dir, string $root, array $paths, bool $copy ) : string {
		if ( $copy ) {
			$paths[] = 'lib/tillkite.php';
		}

		// cd takes a name that starts with - as an option, quoted or not, so
		// it gets ./ in front.
		$where = $dir;
		$cd = '';
		$arg = $dir;
		if ( \str_starts_with( $dir, '-' ) ) {
			$arg = './' . $dir;
		}

		if ( $dir === '.' ) {
			$where = 'this directory';
		} elseif ( \preg_match( '{^[\w./-]+$}D', $arg ) === 1 ) {
			$cd = '  cd ' . $arg . "\n";
		} else {
			$cd = '  cd ' . \escapeshellarg( $arg ) . "\n";
		}

		// In quotes, nginx reads a space, ; { } or # in the path as part of it,
		// and \ escapes " and \. It has no escape for $, which starts a
		// variable, and a control character can't be in a directive, so a
		// path with one gets a stand-in, and a comment that says why.
		$public = $root . \DIRECTORY_SEPARATOR . 'public';
		$nginx_root = '      root "' . \addcslashes( $public, '"\\' ) . "\";\n";
		if ( \preg_match( '/[$\x00-\x1F\x7F]/', $public ) === 1 ) {
			$nginx_root = "      # nginx reads a \$ in a path as a variable, and can't take a control\n"
				. "      # character, so give it a path to public/ without one, like a symlink.\n"
				. "      root /path/to/public;\n";
		}

		$steps = 'Made a Tillkite site in ' . $where . ":\n\n  " . \implode( "\n  ", $paths ) . "\n\n"
			. "Run it with PHP's built-in server:\n\n"
			. $cd
			. "  php -S localhost:8000 -t public public/index.php\n\n"
			. "Then open http://localhost:8000. Each page is a file in handlers/, and\n"
			. "routes.php says which URL runs which one. What public/index.php and\n"
			. "any script you add share, like a database connection, goes in\n"
			. "init.php, which they both require.\n\n"
			. "In production, point nginx at public/, and send every request that isn't\n"
			. "a real file, or is for a PHP file, to index.php through php-fpm:\n\n"
			. "  server {\n"
			. $nginx_root . "\n"
			. "      location / {\n"
			. "          try_files \$uri /index.php\$is_args\$args;\n"
			. "      }\n\n"
			. "      location ~* \\.php\$ {\n"
			. "          rewrite ^ /index.php last;\n"
			. "      }\n\n"
			. "      location = /index.php {\n"
			. "          include fastcgi.conf;\n"
			. "          fastcgi_pass unix:/run/php/php-fpm.sock;\n"
			. "      }\n"
			. "  }\n";
		return $steps;
	}

	// The site's files, by their paths in it. {autoload} in init.php and
	// {tillkite} in index.php are replaced with what loads Tillkite.
	private static function files() : array {
		$files = [
			'init.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				// Everything starts here: public/index.php for each request, and any
				// script you add, like bin/report.php, with:
				//
				//     require __DIR__ . '/../init.php';
				{autoload}
				// It sets up what they all need, in variables that the file that
				// requires it gets, like a database connection, which public/index.php
				// registers with the app, for its handlers and templates:
				//
				//     $db = new PDO( 'sqlite:' . __DIR__ . '/var/site.db' );

				TEMPLATE,
			'public/index.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				// Every request that isn't for a file in public/ comes here. Run the
				// site with PHP's built-in server:
				//
				//     php -S localhost:8000 -t public public/index.php

				// Anywhere but php -S, this is production, whatever php.ini says. It
				// comes first, to cover what goes wrong in init.php too. Errors go to
				// the log, not the page, and stack traces leave out arguments, which
				// can be passwords. Session cookies stay away from scripts and other
				// sites, and go only over HTTPS, so take out session.cookie_secure
				// for a site without it, or sign-ins won't stick.
				if ( PHP_SAPI !== 'cli-server' ) {
					if ( function_exists( 'ini_set' ) ) {
						ini_set( 'display_errors', '0' );
						ini_set( 'html_errors', '0' );
						ini_set( 'log_errors', '1' );
						ini_set( 'zend.exception_ignore_args', '1' );
						ini_set( 'session.cookie_httponly', '1' );
						ini_set( 'session.cookie_samesite', 'Lax' );
						ini_set( 'session.cookie_secure', '1' );
						ini_set( 'session.use_strict_mode', '1' );
					}

					header_remove( 'X-Powered-By' );
				}

				// What the site's scripts share too, like $db.
				require __DIR__ . '/../init.php';
				{tillkite}
				$tillkite = new Tillkite\App( [
					'routes' => __DIR__ . '/../routes.php',
					'handlers' => __DIR__ . '/../handlers',
					'templates' => __DIR__ . '/../templates',

					// The compiled routes go here. The web server must be able to
					// write to it.
					'cache' => __DIR__ . '/../var',

					// Files in handlers/.
					'before' => 'before.php',
					'errors' => [ 404 => '404.php', 405 => '405.php', 500 => '500.php' ],
				] );

				// Handlers and templates get what's registered with get(), like
				// $tillkite->get( 'db' ):
				//
				//     $tillkite->register( 'db', $db );

				return $tillkite->run();

				TEMPLATE,
			'public/style.css' => <<<'TEMPLATE'
				/* System fonts, and the browser's own light and dark colours. */
				:root { color-scheme: light dark; }
				body { max-width: 40rem; margin: 0 auto; padding: 2rem 1rem; font: 1rem/1.6 system-ui, sans-serif; }
				h1 { line-height: 1.2; }
				code, pre { font-family: ui-monospace, Menlo, Consolas, monospace; font-size: 0.875em; }
				pre { padding: 1rem; overflow-x: auto; border: 1px solid GrayText; }

				TEMPLATE,
			'routes.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				// Each route is a method, a URL pattern and the file in handlers/ that
				// answers it. Tillkite checks them in order, and {name} is a parameter.
				return [
					[ 'GET', '/', 'home.php' ],
					[ 'GET', '/hello/{name}', 'hello.php' ],
				];

				TEMPLATE,
			'handlers/before.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				// Runs before every handler, and before the 404 and 405 pages. These
				// headers stop browsers from guessing a response's type, from sending
				// the full URL to other sites, and from showing the site in a frame.
				header( 'X-Content-Type-Options: nosniff' );
				header( 'Referrer-Policy: strict-origin-when-cross-origin' );
				header( "Content-Security-Policy: frame-ancestors 'none'" );

				TEMPLATE,
			'handlers/home.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				// What a handler prints is the page. This one puts its content in the
				// layout, templates/layout.php.
				echo $tillkite->template( 'layout.php', [
					'title' => 'It works',
					'content' => <<<'HTML'
						<p>This is your new Tillkite site. Each page is a file in <code>handlers/</code>, like this one, <code>handlers/home.php</code>, and <code>routes.php</code> says which URL runs which file. The page around it is <code>templates/layout.php</code>.</p>
						<p>Try <a href="/hello/Ada">/hello/Ada</a>, whose route has a parameter.</p>
						HTML,
				] );

				TEMPLATE,
			'handlers/hello.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				use Tillkite\Escape as Esc;

				// $params has the route's parameters, already decoded. The layout
				// escapes the title, but anything else from the URL needs escaping, like
				// Esc::html() for text. Esc::html_attr() is for an attribute's value.
				echo $tillkite->template( 'layout.php', [
					'title' => 'Hello, ' . $params['name'],
					'content' => '<p>From <code>handlers/hello.php</code>, for the route <code>/hello/{name}</code>, with <code>' . Esc::html( $params['name'] ) . '</code> as the name.</p>',
				] );

				TEMPLATE,
			'handlers/404.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				use Tillkite\Escape as Esc;

				// Runs when no route matches the URL. Tillkite has already set the
				// status.
				echo $tillkite->template( 'layout.php', [
					'title' => 'Not found',
					'content' => '<p>There\'s nothing at <code>' . Esc::html( $tillkite->uri() ) . '</code>.</p>',
				] );

				TEMPLATE,
			'handlers/405.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				use Tillkite\Escape as Esc;

				// Runs when a route matches the URL but not its method. Tillkite has
				// already set the status and the Allow header.
				echo $tillkite->template( 'layout.php', [
					'title' => 'Method not allowed',
					'content' => '<p>This URL doesn\'t take ' . Esc::html( $tillkite->method() ) . ' requests.</p>',
				] );

				TEMPLATE,
			'handlers/500.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				use Tillkite\Escape as Esc;

				// Runs for an exception that a handler doesn't catch, or a problem with
				// the routes, which $error describes. It can reveal file paths and
				// other details, so the page shows it only in development, under
				// php -S. Elsewhere it goes to PHP's error log.
				$html = '<p>Something went wrong.</p>';
				if ( PHP_SAPI === 'cli-server' ) {
					$html .= '<pre>' . Esc::html( (string) $error ) . '</pre>';
				} else {
					error_log( Esc::log( (string) $error ) );
				}

				echo $tillkite->template( 'layout.php', [ 'title' => 'Server error', 'content' => $html ] );

				TEMPLATE,
			'templates/layout.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				use Tillkite\Escape as Esc;

				// The page layout, which every handler runs with template(). In it,
				// $data['title'] is plain text, and $data['content'] is HTML that's
				// already escaped. The icon is empty, so browsers don't ask for
				// /favicon.ico, which would run the 404 page.
				$title = Esc::html( $data['title'] );
				?>
				<!doctype html>
				<html lang="en">
				<head>
				<meta charset="utf-8">
				<meta name="viewport" content="width=device-width, initial-scale=1">
				<title><?= $title ?></title>
				<link rel="icon" href="data:,">
				<link rel="stylesheet" href="/style.css">
				</head>
				<body>
				<main>
				<h1><?= $title ?></h1>
				<?= $data['content'] ?>

				</main>
				</body>
				</html>

				TEMPLATE,
			'var/.gitignore' => <<<'TEMPLATE'
				*
				!.gitignore

				TEMPLATE,
		];
		return $files;
	}
}

/**
 * Times each request with hrtime(): the total, and one step at a time, from
 * route to the files that run. Each step ends when the next one starts, so
 * next() only records the time. A template's own time is a step too, named
 * after it, like template:layout.php, which App::template() starts, and
 * ends by going back to the step that ran it.
 *
 * @internal
 */
final class Timer {
	// Each step's name, and when it started, in nanoseconds, in the order
	// they started.
	private array $steps = [ 'route' ];

	private array $starts;

	// The step that's running. Keeping it costs less than reading the last
	// of $steps with end(), which takes the array by reference.
	private string $current = 'route';

	// When the timer stopped, which ends the last step and the total.
	private ?int $end = null;

	// Starts the total and the route step.
	public function __construct() {
		$this->starts = [ \hrtime( true ) ];
	}

	// Ends the current step, starts the next, and returns the name of the
	// one it ended.
	public function next( string $step ) : string {
		$ended = $this->current;
		$this->current = $step;
		$this->steps[] = $step;
		$this->starts[] = \hrtime( true );
		return $ended;
	}

	public function running() : bool {
		$running = $this->end === null;
		return $running;
	}

	// Ends the last step and the total. Calling it again changes nothing.
	public function stop() : void {
		$this->end ??= \hrtime( true );
	}

	// Milliseconds for each step that ran. A step that's still running
	// reports its time so far.
	public function timings() : array {
		$timings = [];
		foreach ( $this->steps() as $step => [ $nanoseconds ] ) {
			$timings[$step] = \round( $nanoseconds / 1e6, 3 );
		}

		return $timings;
	}

	// The Server-Timing header's value, with the steps that have finished. A
	// template's step is named after the template, which can have characters
	// a metric's name can't, like /, so its metric is tillkite-template, with
	// the template's name as its description: a quoted string, with " and \
	// escaped. Anything in the name that isn't printable ASCII, and %, is
	// percent-encoded, like %0A, so the header holds only what HTTP allows,
	// and a newline can't make PHP drop it.
	public function header() : string {
		$metrics = [];
		foreach ( $this->steps() as $step => [ $nanoseconds, $finished ] ) {
			if ( ! $finished ) {
				continue;
			}

			$name = 'tillkite-' . $step;
			if ( \str_starts_with( $step, 'template:' ) ) {
				$desc = (string) \preg_replace_callback( '/[^\x20-\x24\x26-\x7E]/', static fn ( array $byte ) : string => \sprintf( '%%%02X', \ord( $byte[0] ) ), \substr( $step, 9 ) );
				$name = 'tillkite-template;desc="' . \addcslashes( $desc, '"\\' ) . '"';
			}

			$metrics[] = $name . ';dur=' . \round( $nanoseconds / 1e6, 3 );
		}

		$header = \implode( ', ', $metrics );
		return $header;
	}

	// Each step's nanoseconds so far, and whether it has finished, starting
	// with the total. A step that ran twice, like a 404 file that threw and
	// then the 500 file, gets the sum, and has finished when its last run has.
	private function steps() : array {
		$end = $this->end ?? \hrtime( true );
		$steps = [ 'total' => [ $end - $this->starts[0], $this->end !== null ] ];
		foreach ( $this->steps as $i => $step ) {
			$finished = isset( $this->starts[$i + 1] ) || $this->end !== null;
			$nanoseconds = ( $this->starts[$i + 1] ?? $end ) - $this->starts[$i];
			$steps[$step] = [ ( $steps[$step][0] ?? 0 ) + $nanoseconds, $finished ];
		}

		return $steps;
	}
}

// Run as a command, like php tillkite.php new my-site, this file makes a new
// site, with a copy of itself. Required, it does nothing more. The CLI
// builds $_SERVER before it runs a script, and $GLOBALS reads it without
// naming it, which would make php-fpm build it for every request that
// loads this file (see App::server()).
if ( \PHP_SAPI === 'cli' && \realpath( $GLOBALS['_SERVER']['SCRIPT_FILENAME'] ?? '' ) === __FILE__ ) {
	exit( Starter::main( $GLOBALS['_SERVER']['argv'], __FILE__, true ) );
}
