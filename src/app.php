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
		$mark = \strpos( $path, '?' );
		if ( $mark !== false ) {
			$path = \substr( $path, 0, $mark );
		}

		$this->respond( $compiled, self::server( 'REQUEST_METHOD' ) ?? 'GET', $path );
		return true;
	}

	// Matches the request and runs the route's handler. When matching fails,
	// the response is a 500, and anything else that doesn't match is a 404.
	private function respond( array $compiled, string $method, string $path ) : void {
		$matcher = new Matcher( $compiled );
		$result = $matcher->match( $method, $path );
		if ( $result['status'] === 200 ) {
			Runner::handler( $this, $result['params'], $this->handlers . '/' . $compiled['routes'][$result['route']][2] );
			return;
		}

		if ( $result['status'] === 500 ) {
			\error_log( 'Tillkite: matching the request failed: ' . $result['error'] );
			$this->plain( 500 );
			return;
		}

		$this->plain( 404 );
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
