<?php
declare( strict_types = 1 );

namespace Tillkite;

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
