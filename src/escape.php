<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Escapes data for where it goes. Each method returns the escaped text.
 * Nothing is thrown.
 */
final class Escape {
	// For text in a log line, like PHP's error log, which someone reads in a
	// terminal or an editor. Control characters, which a terminal can take as
	// commands, like ESC, and which can start a line that looks like another
	// entry, become C-style escapes, like \n and \033. In valid UTF-8, so do
	// the C1 controls, U+0080 to U+009F, which some terminals take as
	// commands too, and the characters that make an editor show text in
	// another order or on another line, U+061C, U+200E, U+200F, U+2028 to
	// U+202E and U+2066 to U+2069, as \u{202E}. Text that isn't valid UTF-8
	// has each byte from 0x80 escaped, like \233. A \ stays as it is, so
	// paths and regexes read as they are.
	public static function log( string|int|float|\Stringable|null $value ) : string {
		$log = \addcslashes( (string) $value, "\0..\37\177" );
		if ( \preg_match( '//u', $log ) !== 1 ) {
			$log = \addcslashes( $log, "\200..\377" );
		} else {
			$log = (string) \preg_replace_callback( '/[\x{80}-\x{9F}\x{61C}\x{200E}\x{200F}\x{2028}-\x{202E}\x{2066}-\x{2069}]/u', static fn ( array $char ) : string => \sprintf( '\u{%04X}', self::code_point( $char[0] ) ), $log );
		}

		return $log;
	}

	// The code point of a character of two or three bytes in valid UTF-8,
	// without mbstring, which UTF-8 doesn't need.
	private static function code_point( string $char ) : int {
		$first = \ord( $char[0] );
		$code = ( ( $first & 0x1F ) << 6 ) | ( \ord( $char[1] ) & 0x3F );
		if ( $first >= 0xE0 ) {
			$code = ( ( $first & 0x0F ) << 12 ) | ( ( \ord( $char[1] ) & 0x3F ) << 6 ) | ( \ord( $char[2] ) & 0x3F );
		}

		return $code;
	}

	// UTF-8 text as HTML. For a page in another encoding, each character
	// outside ASCII becomes a numeric character reference, but U+0080 to
	// U+009F become U+FFFD, since HTML reads &#128; to &#159; as
	// windows-1252's characters, so no reference can be one of them.
}
