<?php
declare( strict_types = 1 );

describe( 'problems', function() {
	it( 'reports a routes list with keys', function() {
		$compiler = new Tillkite\Compiler();
		$compiler->compile( [ 'home' => [ 'GET', '/', 'page.php' ] ] );

		expect( $compiler->problems )->toBe( [ 'The routes file must return a list of routes, without keys.' ] );
	} );
} );
