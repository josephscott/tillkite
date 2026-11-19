<?php
declare( strict_types = 1 );

// file() works without run(), so these tests call it in the test process.

// A docs directory with a file, a file in a directory in it, and symlinks:
// to a file inside it, and to a file and a directory outside it. Returns the
// directory above docs, by its real path, which is what file() answers with.
function file_setup() : string {
	$dir = (string) realpath( temp_dir() );
	mkdir( $dir . '/docs/guide', 0o777, true );
	mkdir( $dir . '/outside' );
	file_put_contents( $dir . '/docs/index.md', 'index' );
	file_put_contents( $dir . '/docs/guide/install.md', 'install' );
	file_put_contents( $dir . '/outside/install.md', 'secret' );
	symlink( $dir . '/docs/index.md', $dir . '/docs/home.md' );
	symlink( $dir . '/outside/install.md', $dir . '/docs/leak.md' );
	symlink( $dir . '/outside', $dir . '/docs/out' );
	return $dir;
}

it( 'returns the real path of a file inside the directory, and null for any other name', function( string $name, ?string $file ) {
	$dir = file_setup();
	if ( $file !== null ) {
		$file = $dir . '/' . $file;
	}

	expect( ( new Tillkite\App() )->file( $dir . '/docs', $name ) )->toBe( $file );
} )->with( [
	'a file in a directory in it' => [ 'guide/install.md', 'docs/guide/install.md' ],
	'a symlink to a file inside it' => [ 'home.md', 'docs/index.md' ],
	'no such file' => [ 'nope.md', null ],
	'a directory' => [ 'guide', null ],
	'a .. segment, even one that stays inside' => [ 'guide/../index.md', null ],
	'a .. segment after a backslash' => [ 'guide\\..\\index.md', null ],
	'a name that starts with a slash' => [ '/index.md', null ],
	'a null byte' => [ "index.md\0", null ],
	'a symlink to a file outside it' => [ 'leak.md', null ],
	'a file under a symlink to a directory outside it' => [ 'out/install.md', null ],
] );

// PHP's own rename() and symlink() would make it forget what realpath()
// found, so the swap is made the way another process would make it.
it( 'notices a directory swapped for a symlink since PHP last looked', function() {
	$dir = file_setup();
	$tillkite = new Tillkite\App();

	expect( $tillkite->file( $dir . '/docs', 'guide/install.md' ) )->toBe( $dir . '/docs/guide/install.md' );
	exec( 'mv ' . escapeshellarg( $dir . '/docs/guide' ) . ' ' . escapeshellarg( $dir . '/docs/moved' ) . ' && ln -s ' . escapeshellarg( $dir . '/outside' ) . ' ' . escapeshellarg( $dir . '/docs/guide' ) );
	expect( $tillkite->file( $dir . '/docs', 'guide/install.md' ) )->toBeNull();
} );

it( 'takes only an absolute directory, and logs another', function() {
	$log = logged( function() {
		expect( ( new Tillkite\App() )->file( 'tests', 'Pest.php' ) )->toBeNull();
	} );

	expect( $log )->toContain( "Tillkite: file() takes its directory as an absolute path, built from __DIR__, so it returned null for 'tests'" );
} );
