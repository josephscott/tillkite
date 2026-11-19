<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Escapes data for where it goes in a page: html() between tags, html_attr()
 * in an attribute's value and url() for a link. Each returns the escaped
 * text, for a template to print:
 *
 *     use Tillkite\Escape as Esc;
 *     <a href="<?= Esc::url( $link ) ?>"><?= Esc::html( $name ) ?></a>
 *
 * The page is UTF-8. Invalid UTF-8 becomes U+FFFD. Nothing is thrown.
 */
final class Escape {
	private const int HTML = \ENT_QUOTES | \ENT_SUBSTITUTE | \ENT_HTML401;

	// What htmlspecialchars() changes in valid UTF-8, for strtr(), which
	// takes far less time for each byte. But checking that text is valid
	// UTF-8 takes about as long as htmlspecialchars() takes for 16 bytes, so
	// strtr() is used only for text at least this long: HTML_LONG bytes for
	// html(), and ATTR_LONG for html_attr() and url(), which save a second
	// strtr() too. Invalid UTF-8 needs htmlspecialchars(), which replaces it
	// with U+FFFD.
	private const array HTML_CHARS = [ '&' => '&amp;', '"' => '&quot;', "'" => '&#039;', '<' => '&lt;', '>' => '&gt;' ];

	private const int HTML_LONG = 48;

	private const int ATTR_LONG = 24;

	// What ends an unquoted attribute value, whitespace, and what old
	// browsers took as its quotes or its start, the backtick and =. HTML
	// already escapes the rest that matter: & < > " '.
	private const array ATTR = [ "\t" => '&#9;', "\n" => '&#10;', "\f" => '&#12;', "\r" => '&#13;', ' ' => '&#32;', '=' => '&#61;', '`' => '&#96;' ];

	// HTML_CHARS and ATTR in one table, for one strtr() over valid UTF-8.
	private const array HTML_ATTR = self::HTML_CHARS + self::ATTR;

	// For text between tags: & < > " ' as entities. It's safe for plain text
	// in a quoted attribute too, but html_attr() is safe without quotes.
	public static function html( string|int|float|\Stringable|null $value ) : string {
		$text = (string) $value;
		if ( \strlen( $text ) >= self::HTML_LONG && \preg_match( '//u', $text ) === 1 ) {
			$html = \strtr( $text, self::HTML_CHARS );
		} else {
			$html = \htmlspecialchars( $text, self::HTML, 'UTF-8' );
		}

		return $html;
	}

	// For an attribute's value that's plain text, quoted or not: html(), and
	// whitespace, = and the backtick as entities. An empty value without
	// quotes lets the parser take what comes after it as the value, so quote
	// an attribute that can be empty. A URL, an event handler or a style
	// attribute needs url(), html_attr( js() ) or css() instead.
	public static function html_attr( string|int|float|\Stringable|null $value ) : string {
		$text = (string) $value;
		if ( \strlen( $text ) >= self::ATTR_LONG && \preg_match( '//u', $text ) === 1 ) {
			$attr = \strtr( $text, self::HTML_ATTR );
		} else {
			$attr = \strtr( \htmlspecialchars( $text, self::HTML, 'UTF-8' ), self::ATTR );
		}

		return $attr;
	}

	// For a URL in an attribute, like href or src. A URL with a scheme that
	// isn't in $schemes, which are in lowercase, becomes about:invalid, so
	// javascript: and data: URLs can't run. The scheme is checked as a
	// browser reads it, without the control characters and spaces at either
	// end, or a tab or newline inside. Then it's escaped as html_attr() does.
	public static function url( string $url, array $schemes = [ 'http', 'https', 'mailto', 'tel' ] ) : string {
		$url = \str_replace( [ "\t", "\n", "\r" ], '', \trim( $url, "\x00..\x20" ) );
		if ( \preg_match( '/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match ) === 1 && ! \in_array( \strtolower( $match[1] ), $schemes, true ) ) {
			$url = 'about:invalid';
		}

		if ( \strlen( $url ) >= self::ATTR_LONG && \preg_match( '//u', $url ) === 1 ) {
			$escaped = \strtr( $url, self::HTML_ATTR );
		} else {
			$escaped = \strtr( \htmlspecialchars( $url, self::HTML, 'UTF-8' ), self::ATTR );
		}

		return $escaped;
	}

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
