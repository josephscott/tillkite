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

	// The directory that handler, before and error files are relative to, as
	// an absolute path. Empty means the directory of the routes file.
	public string $handlers = '';

	// false: URLs never end in /. true: they always do.
	public bool $trailing_slash = false;

	// Buffers the output of the files that run, so they can send headers
	// after it.
	public bool $buffer = true;

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
		'handlers' => 'string',
		'trailing_slash' => 'bool',
		'buffer' => 'bool',
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

	private array $problems = [];

	// The parameters of the files that are running, for the error files.
	private array $params = [];

	// True while the before-file and handler run, the only time error() does
	// anything.
	private bool $in_route_files = false;

	// The code that a before-file or handler passed to error().
	private ?int $error_code = null;

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
	 * Handles the web request.
	 */
	public function run() : bool {
		// PHP's messages hold file paths, and can hold passwords, and its default
		// is to print them. So only php -S, for the developer, and the command
		// line keep them. A host that locks the setting, or disables ini_set(),
		// which would be a fatal error to call, has the last word.
		if ( \PHP_SAPI !== 'cli-server' && \PHP_SAPI !== 'cli' && \function_exists( 'ini_set' ) ) {
			\ini_set( 'display_errors', '0' );
		}

		$compiled = $this->compile();
		if ( $this->problems !== [] ) {
			$this->report_problems();
			return true;
		}

		$path = self::server( 'REQUEST_URI' ) ?? '/';
		$query = '';
		$mark = \strpos( $path, '?' );
		if ( $mark !== false ) {
			$query = \substr( $path, $mark );
			$path = \substr( $path, 0, $mark );
		}

		$this->respond( $compiled, self::server( 'REQUEST_METHOD' ) ?? 'GET', $path, $query );
		return true;
	}

	// Matches the request and runs the before-file and the route's handler.
	// When matching fails, the response is a 500. A miss runs the before-file
	// too, and only then gets its response, so a before-file that checks a
	// login hides which paths exist.
	private function respond( array $compiled, string $method, string $path, string $query ) : void {
		$before = '';
		if ( $this->before !== '' ) {
			$before = $this->handlers . '/' . $this->before;
		}

		$matcher = new Matcher( $compiled );
		$result = $matcher->match( $method, $path );
		if ( $result['status'] === 200 ) {
			$this->run_route( $before, $this->handlers . '/' . $compiled['routes'][$result['route']][2], $result['params'] );
			return;
		}

		if ( $result['status'] === 500 ) {
			\error_log( 'Tillkite: matching the request failed: ' . $result['error'] );
			$this->server_error( 'Matching the request failed: ' . $result['error'] );
			return;
		}

		if ( $before !== '' && ! $this->run_miss_before( $before ) ) {
			return;
		}

		$this->miss( $result, $query );
	}

	// Runs the before-file and the handler.
	private function run_route( string $before, string $handler, array $params ) : void {
		$this->start( $this->buffer, $params );
		$next = $this->next_file( ... );
		if ( $before !== '' ) {
			$next = $this->next_after_before( ... );
		}

		$this->in_route_files = true;
		Runner::files( $this, $params, $before, $handler, $next );
		$this->in_route_files = false;
		if ( $this->error_code !== null ) {
			$this->show_error( $this->error_code );
			return;
		}

		$this->finish();
	}

	// Runs a miss's before-file, with no handler after it. It's always
	// buffered, because what it prints is thrown away before the response.
	// Returns true when it ran through. When it returned false or set a
	// redirect or error status, what it printed is sent instead, and when it
	// called error(), its page is.
	private function run_miss_before( string $before ) : bool {
		$this->start( true, [] );
		$ran = false;
		$this->in_route_files = true;
		Runner::files( $this, [], $before, '', function() use ( &$ran ) : bool {
			$ran = \http_response_code() < 300;
			return false;
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
			$location = $result['path'];
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
		Runner::files( $this, $params, '', $this->handlers . '/' . $file, $this->next_file( ... ) );
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

	// Runner calls it before the handler or error file. It returns false once
	// a file has called error(), so no file after that one runs.
	private function next_file() : bool {
		$next = $this->error_code === null;
		return $next;
	}

	// Runner calls it in next_file()'s place after a before-file. A
	// before-file that redirects or sets an error status has answered the
	// request, even if it doesn't return false, like a login check with only
	// header( 'Location: /sign-in' ), which sets a 302. Going on would send
	// the page to the visitor it turned away. A status under 300 goes on, like
	// a Location with a 201.
	private function next_after_before() : bool {
		$next = \http_response_code() < 300 && $this->next_file();
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

	// Sends what's buffered. A file may have ended Tillkite's buffer itself,
	// or left buffers of its own, so this ends whatever is above the level
	// from before Tillkite's.
	private function finish() : void {
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

	// Loads the routes file and compiles it. Every problem found goes into
	// $problems.
	private function compile() : array {
		$compiled = [];
		if ( $this->routes === '' ) {
			$this->problems[] = 'The routes option is required.';
			return $compiled;
		}

		if ( $this->handlers === '' ) {
			$this->handlers = \dirname( $this->routes );
		}

		foreach ( [ 'routes', 'handlers' ] as $name ) {
			if ( ! $this->is_absolute( $this->$name ) ) {
				$this->problems[] = 'The ' . $name . ' option must be an absolute path, built from __DIR__: ' . $this->$name;
				return $compiled;
			}
		}

		if ( ! \is_file( $this->routes ) ) {
			$this->problems[] = "The routes file doesn't exist: " . $this->routes;
			return $compiled;
		}

		$routes = Runner::routes( $this->routes );
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
		return $compiled;
	}

	// A path from the root, like /var/www, or a Windows drive, like C:\www.
	private function is_absolute( string $path ) : bool {
		$absolute = \str_starts_with( $path, '/' ) || \preg_match( '{^[A-Za-z]:[/\\\\]}', $path ) === 1;
		return $absolute;
	}

	// Under php -S, the response lists the problems, for the developer.
	// Anywhere else, they go to the error log, and the response is the 500
	// file's page, or a plain 500, without them.
	private function report_problems() : void {
		$message = "Tillkite found problems with its configuration:\n\n- " . \implode( "\n- ", $this->problems ) . "\n";
		if ( \PHP_SAPI === 'cli-server' ) {
			$this->plain( 500, $message );
			return;
		}

		foreach ( $this->problems as $problem ) {
			\error_log( 'Tillkite: ' . Escape::log( $problem ) );
		}

		$this->server_error( $message );
	}

	// A plain-text response: $text, or the code and its reason.
	private function plain( int $code, string $text = '' ) : void {
		if ( $text === '' ) {
			$text = $code . ' ' . self::REASONS[$code] . "\n";
		}

		\http_response_code( $code );
		\header( 'Content-Type: text/plain; charset=utf-8' );
		echo $text;
	}
}
