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
	public array $problems = [];

	public function compile( array $routes ) : array {
		$this->problems = [];

		$compiled = [
			'routes' => [],
		];

		if ( ! \array_is_list( $routes ) ) {
			$this->problems[] = 'The routes file must return a list of routes, without keys.';
			return $compiled;
		}

		return $compiled;
	}
}
