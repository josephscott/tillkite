<?php
declare( strict_types = 1 );

namespace Tillkite;

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

	public array $methods = [];

	public string $handlers = '';

	public ?string $before = null;

	public array $problems = [];

	private bool $check_files = true;

	public function compile( array $routes ) : array {
		$this->problems = [];

		$compiled = [
			'routes' => [],
		];

		$allowed = $this->check_settings();
		if ( ! \array_is_list( $routes ) ) {
			$this->problems[] = 'The routes file must return a list of routes, without keys.';
			return $compiled;
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
