<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Makes a new site, for `vendor/bin/tillkite new my-site`: init.php, for what
 * the front controller and any scripts share, a front controller, routes,
 * a layout template and a home page. It never overwrites a file.
 *
 * Only the command line uses it, so Composer's autoloader loads it only then.
 *
 * @internal
 */
final class Starter {
	// Runs the command in $argv and returns its exit code. $loader is
	// Composer's autoloader, which the site's init.php requires.
	public static function main( array $argv, string $loader ) : int {
		$command = $argv[0] ?? 'tillkite';

		$usage = 'Usage: ' . $command . " new [directory]\n\n"
			. "Makes a Tillkite site in the directory, or the current one: init.php,\n"
			. "for what the web and scripts share, a front controller in public/,\n"
			. "routes, a layout template and a home page. It never overwrites a\n"
			. "file.\n";

		$args = \array_slice( $argv, 1 );
		if ( $args === [] || $args === [ 'help' ] || $args === [ '--help' ] || $args === [ '-h' ] ) {
			echo $usage;
			return 0;
		}

		if ( $args[0] !== 'new' || \count( $args ) > 2 ) {
			\fwrite( \STDERR, $usage );
			return 1;
		}

		$code = self::make_site( $args[1] ?? '.', $loader );
		return $code;
	}

	private static function make_site( string $dir, string $loader ) : int {
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
		foreach ( \array_keys( $dirs ) as $path ) {
			if ( \is_link( $dir . '/' . $path ) ) {
				$conflicts[] = $path;
			}
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
		$root = self::write( $dir, $files, $loader );
		\umask( $umask );
		\restore_error_handler();
		if ( $root === '' ) {
			\fwrite( \STDERR, 'Could not make the site in ' . $dir . ': ' . $error . "\n" );
			return 1;
		}

		$where = $dir;
		if ( $dir === '.' ) {
			$where = 'this directory';
		}

		echo 'Made a Tillkite site in ' . $where . ":\n\n  " . \implode( "\n  ", \array_keys( $files ) ) . "\n";
		return 0;
	}

	// Writes the files, and returns the site's directory as an absolute
	// path, or '' if a file couldn't be written. The site's init.php
	// requires Composer's autoloader by its path from the site's directory,
	// so the site still works when it moves along with vendor/.
	private static function write( string $dir, array $files, string $loader ) : string {
		$root = '';
		if ( \is_dir( $dir ) || \mkdir( $dir, 0o777, true ) ) {
			$root = (string) \realpath( $dir );
		}

		$from = \realpath( $loader );
		if ( $root === '' || $from === false ) {
			return '';
		}

		$autoload = "\n// Composer's autoloader, which loads Tillkite for public/index.php, and\n// the packages that scripts use.\nrequire " . self::relative( $root, $from ) . ";\n\n";
		$files['init.php'] = \str_replace( "{autoload}\n", $autoload, $files['init.php'] );
		foreach ( $files as $path => $contents ) {
			$parent = \dirname( $root . '/' . $path );
			if ( ! ( \is_dir( $parent ) || \mkdir( $parent, 0o777, true ) ) || ! self::create( $root . '/' . $path, $contents ) ) {
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

	// The site's files, by their paths in it. {autoload} in init.php is
	// replaced with what loads Tillkite.
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

				$tillkite = new Tillkite\App( [
					'routes' => __DIR__ . '/../routes.php',
					'handlers' => __DIR__ . '/../handlers',
					'templates' => __DIR__ . '/../templates',

					// The compiled routes go here. The web server must be able to
					// write to it.
					'cache' => __DIR__ . '/../var',
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
				];

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
						HTML,
				] );

				TEMPLATE,
			'templates/layout.php' => <<<'TEMPLATE'
				<?php
				declare( strict_types = 1 );

				use Tillkite\Escape as Esc;

				// The page layout, which every handler runs with template(). In it,
				// $data['title'] is plain text, and $data['content'] is HTML that's
				// already escaped. The icon is empty, so browsers don't ask for
				// /favicon.ico, which would get a 404.
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
