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
	 * so. On Windows, the owner is always 0. directory_problem() has checked
	 * that the directory is there.
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
