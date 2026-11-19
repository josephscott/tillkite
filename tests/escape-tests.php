<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// What a terminal or an editor could take as a command, a new entry, or
// text in another order, comes out as an escape. The rest stays as it is.
it( 'gives text for a log line with no control or direction characters', function( mixed $value, string $log ) {
	expect( Esc::log( $value ) )->toBe( $log );
} )->with( [
	'text, a path and a regex' => [ 'café posts/{id:\d+}.php', 'café posts/{id:\d+}.php' ],
	'a newline that starts a forged entry' => [ "x\n[06-Nov-2026] admin", 'x\n[06-Nov-2026] admin' ],
	'an ESC sequence' => [ "\e[2J", '\033[2J' ],
	'a C1 control, a bidi override and a line separator' => [ "\u{9B}2J \u{202E}fdp.exe \u{2028}", '\u{009B}2J \u{202E}fdp.exe \u{2028}' ],
	'invalid UTF-8' => [ "\x9B\xFF\xC3\xA9", '\233\377\303\251' ],
	'a number' => [ 42, '42' ],
] );
