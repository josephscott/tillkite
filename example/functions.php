<?php
declare( strict_types = 1 );

// Functions that the example's handlers and templates call. index.php loads
// this file before run().

// True in development, under php -S. Stack traces show only then, because
// they reveal file paths and other details.
function development() : bool {
	$development = PHP_SAPI === 'cli-server';
	return $development;
}

// A stack trace from debug_backtrace(), in the format PHP uses for an
// exception's.
function stack_trace( array $frames ) : string {
	$lines = [];
	foreach ( $frames as $i => $frame ) {
		$where = '[internal function]';
		if ( isset( $frame['file'] ) ) {
			$where = $frame['file'] . '(' . $frame['line'] . ')';
		}
		$lines[] = '#' . $i . ' ' . $where . ': ' . ( $frame['class'] ?? '' ) . ( $frame['type'] ?? '' ) . $frame['function'] . '()';
	}
	$lines[] = '#' . count( $frames ) . ' {main}';

	$trace = implode( "\n", $lines );
	return $trace;
}

// A route, or any value in one, as PHP source, written the way routes.php
// writes it.
function route_source( mixed $value ) : string {
	if ( is_string( $value ) ) {
		$php = "'" . str_replace( "'", "\\'", $value ) . "'";
		return $php;
	}

	if ( ! is_array( $value ) ) {
		$php = var_export( $value, true );
		return $php;
	}

	$items = [];
	foreach ( $value as $key => $item ) {
		$prefix = '';
		if ( is_string( $key ) ) {
			$prefix = route_source( $key ) . ' => ';
		}
		$items[] = $prefix . route_source( $item );
	}

	$php = '[ ' . implode( ', ', $items ) . ' ]';
	return $php;
}
