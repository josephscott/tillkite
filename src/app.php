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
			$this->start( $this->buffer );
			Runner::files( $this, $result['params'], $before, $this->handlers . '/' . $compiled['routes'][$result['route']][2] );
			$this->finish();
			return;
		}

		if ( $result['status'] === 500 ) {
			\error_log( 'Tillkite: matching the request failed: ' . $result['error'] );
			$this->plain( 500 );
			return;
		}

		if ( $before !== '' && ! $this->run_miss_before( $before ) ) {
			return;
		}

		$this->miss( $result, $query );
	}

	// Runs a miss's before-file, with no handler after it. It's always
	// buffered, because what it prints is thrown away before the response.
	// Returns true when it ran through. When it returned false, what it
	// printed is sent instead.
	private function run_miss_before( string $before ) : bool {
		$this->start( true );
		$ran = Runner::files( $this, [], $before, '' );
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

		$this->run_error( $code );
	}

	// Runs the error file for a 4xx code, in a scope of its own, or sends
	// plain text.
	private function run_error( int $code ) : void {
		$file = $this->errors[$code] ?? '';
		if ( $file === '' ) {
			$this->plain( $code );
			return;
		}

		\http_response_code( $code );
		$this->start( $this->buffer );
		Runner::files( $this, [], '', $this->handlers . '/' . $file );
		$this->finish();
	}

	// Starts the buffer, if there is one.
	private function start( bool $buffer ) : void {
		$this->level = null;
		if ( $buffer ) {
			$this->level = \ob_get_level();
			\ob_start();
		}
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
	// Anywhere else, they go to the error log, and the response is a plain
	// 500, without them.
	private function report_problems() : void {
		$message = "Tillkite found problems with its configuration:\n\n- " . \implode( "\n- ", $this->problems ) . "\n";
		if ( \PHP_SAPI === 'cli-server' ) {
			$this->plain( 500, $message );
			return;
		}

		foreach ( $this->problems as $problem ) {
			\error_log( 'Tillkite: ' . Escape::log( $problem ) );
		}

		$this->plain( 500 );
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
