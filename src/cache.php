<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Reads and writes the route cache: the compiled routes, as a PHP file that
 * returns them, so OPcache keeps them in shared memory.
 *
 * @internal
 */
final class Cache {
	private string $file;

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
	 * cache answers these without another stat.
	 */
	public function __construct( private string $directory, private string $routes, string $settings ) {
		$key = $routes . "\n" . \filemtime( $routes ) . ' ' . \filectime( $routes ) . ' ' . \filesize( $routes ) . "\n" . $settings . "\n" . \PCRE_VERSION;
		$this->file = $directory . '/tillkite-' . \hash( 'xxh128', $key ) . '.php';
	}

	// Returns the compiled routes, or null when the cache file isn't there
	// yet, or its directory is open to every user, where the file could be
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
		if ( ! \is_array( $compiled ) ) {
			$compiled = null;
		}

		return $compiled;
	}

	/**
	 * Writes the cache file, and returns '' or the reason it couldn't.
	 *
	 * It goes to a temporary file first, and is renamed into place, so a
	 * request never includes half a file. Its modification time is set a
	 * minute in the past, so OPcache caches it right away instead of waiting
	 * out opcache.file_update_protection.
	 *
	 * The web server runs the cache file, so anyone who can put a file in its
	 * place can run code as the web server. So it isn't written in a
	 * directory that every user can write to, even with the sticky bit, which
	 * still lets them make the file first, and the file is 0644, whatever the
	 * umask.
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

	// Why a file in the cache directory could be someone else's PHP, with the
	// directory's path, or '' when it couldn't: every user can write to the
	// directory. A directory that isn't there yet has no problem, and writing
	// to it reports that.
	private function directory_problem() : string {
		$problem = '';
		$mode = \fileperms( $this->directory );
		if ( $mode !== false && ( $mode & 0o002 ) !== 0 ) {
			$problem = $this->directory . ' is writable by every user, who could put PHP in it for the web server to run, so it is not used. Make it writable only by the web server';
		}

		return $problem;
	}

	// The first line of each cache file. It names the routes file by a hash
	// of its path, so it's safe in a comment whatever the path holds.
	private function header() : string {
		$header = '<?php // Tillkite route cache for the routes file ' . \hash( 'xxh128', $this->routes ) . "\n";
		return $header;
	}
}
