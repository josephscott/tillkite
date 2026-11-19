<?php
declare( strict_types = 1 );

namespace Tillkite;

/**
 * Escapes data for where it goes in a page: html() between tags, html_attr()
 * in an attribute's value, url() for a link, js() in JavaScript and css() in
 * CSS. Each returns the escaped text, for a template to print:
 *
 *     use Tillkite\Escape as Esc;
 *     <a href="<?= Esc::url( $link ) ?>"><?= Esc::html( $name ) ?></a>
 *
 * The page is UTF-8 unless set_encoding() names another encoding. Invalid
 * UTF-8 becomes U+FFFD. In another encoding, what isn't valid becomes
 * mbstring's substitute character. Nothing is thrown.
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

	// Every character outside ASCII is escaped, as some, like U+00A0, are
	// whitespace in JavaScript. JSON_HEX_QUOT means a string never holds a
	// ", so each pair of them is one string. JSON_UNESCAPED_SLASHES means no
	// \/, which the JS table would break. JSON_PARTIAL_OUTPUT_ON_ERROR means
	// json_encode() never returns false: what it can't encode becomes null
	// or 0.
	private const int JSON = \JSON_HEX_TAG | \JSON_HEX_AMP | \JSON_HEX_APOS | \JSON_HEX_QUOT | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR;

	// The ASCII characters that json_encode() leaves as they are in a
	// string, but letters and digits. With these escaped too, a string holds
	// only letters, digits and escapes, so its contents can't become code,
	// even inside quotes written around it.
	private const array JS = [
		' ' => '\\u0020', '!' => '\\u0021', '#' => '\\u0023', '$' => '\\u0024', '%' => '\\u0025',
		'(' => '\\u0028', ')' => '\\u0029', '*' => '\\u002A', '+' => '\\u002B', ',' => '\\u002C',
		'-' => '\\u002D', '.' => '\\u002E', '/' => '\\u002F', ':' => '\\u003A', ';' => '\\u003B',
		'=' => '\\u003D', '?' => '\\u003F', '@' => '\\u0040', '[' => '\\u005B', ']' => '\\u005D',
		'^' => '\\u005E', '_' => '\\u005F', '`' => '\\u0060', '{' => '\\u007B', '|' => '\\u007C',
		'}' => '\\u007D', '~' => '\\u007E', "\x7F" => '\\u007F',
	];

	// Every ASCII character but letters and digits, as a CSS escape with six
	// hex digits, so a letter or digit after it is never read as part of it.
	private const array CSS = [
		"\x00" => '\\000000', "\x01" => '\\000001', "\x02" => '\\000002', "\x03" => '\\000003', "\x04" => '\\000004',
		"\x05" => '\\000005', "\x06" => '\\000006', "\x07" => '\\000007', "\x08" => '\\000008', "\x09" => '\\000009',
		"\x0A" => '\\00000A', "\x0B" => '\\00000B', "\x0C" => '\\00000C', "\x0D" => '\\00000D', "\x0E" => '\\00000E',
		"\x0F" => '\\00000F', "\x10" => '\\000010', "\x11" => '\\000011', "\x12" => '\\000012', "\x13" => '\\000013',
		"\x14" => '\\000014', "\x15" => '\\000015', "\x16" => '\\000016', "\x17" => '\\000017', "\x18" => '\\000018',
		"\x19" => '\\000019', "\x1A" => '\\00001A', "\x1B" => '\\00001B', "\x1C" => '\\00001C', "\x1D" => '\\00001D',
		"\x1E" => '\\00001E', "\x1F" => '\\00001F', ' ' => '\\000020', '!' => '\\000021', '"' => '\\000022',
		'#' => '\\000023', '$' => '\\000024', '%' => '\\000025', '&' => '\\000026', "\x27" => '\\000027',
		'(' => '\\000028', ')' => '\\000029', '*' => '\\00002A', '+' => '\\00002B', ',' => '\\00002C',
		'-' => '\\00002D', '.' => '\\00002E', '/' => '\\00002F', ':' => '\\00003A', ';' => '\\00003B',
		'<' => '\\00003C', '=' => '\\00003D', '>' => '\\00003E', '?' => '\\00003F', '@' => '\\000040',
		'[' => '\\00005B', "\x5C" => '\\00005C', ']' => '\\00005D', '^' => '\\00005E', '_' => '\\00005F',
		'`' => '\\000060', '{' => '\\00007B', '|' => '\\00007C', '}' => '\\00007D', '~' => '\\00007E',
		"\x7F" => '\\00007F',
	];

	// Every character outside ASCII, for mb_encode_numericentity().
	private const array NON_ASCII = [ 0x80, 0x10FFFF, 0, 0x1FFFFF ];

	// The encodings set_encoding() takes, by their names in the WHATWG
	// Encoding Standard, which browsers follow, and mbstring's names for the
	// encodings browsers read them as, or the closest: a browser reads a page
	// that says iso-8859-1 as windows-1252, and gbk as gb18030. mbstring
	// reads some characters differently from browsers, mostly in big5 and
	// euc-jp, which changes how they look, never what the page does. In
	// each, a byte under 0x80 that isn't part of a longer character is that
	// ASCII character.
	private const array ENCODINGS = [
		'utf-8' => 'UTF-8',
		'iso-8859-1' => 'Windows-1252',
		'iso-8859-2' => 'ISO-8859-2',
		'iso-8859-5' => 'ISO-8859-5',
		'iso-8859-7' => 'ISO-8859-7',
		'iso-8859-15' => 'ISO-8859-15',
		'windows-1251' => 'Windows-1251',
		'windows-1252' => 'Windows-1252',
		'windows-1254' => 'Windows-1254',
		'ibm866' => 'CP866',
		'koi8-r' => 'KOI8-R',
		'koi8-u' => 'KOI8-U',
		'shift_jis' => 'CP932',
		'euc-jp' => 'eucJP-win',
		'euc-kr' => 'UHC',
		'gbk' => 'GB18030',
		'gb18030' => 'GB18030',
		'big5' => 'CP950',
	];

	// The page's encoding, by mbstring's name for it.
	private static string $encoding = 'UTF-8';

	// Sets the page's encoding, which the text each method takes is in, by
	// its name in the WHATWG Encoding Standard, in any case, like Shift_JIS.
	// Another encoding than UTF-8 needs the mbstring extension. Its text is
	// converted to UTF-8 to be escaped, and the output is all ASCII, with
	// every other character escaped, so it reads the same in any of these
	// encodings, and no byte of a longer character can take in an escape's
	// first byte, like the \ that's the second byte of some in Shift_JIS. An
	// encoding it doesn't take is logged, and the encoding stays as it was.
	public static function set_encoding( string $encoding ) : bool {
		$set = false;
		$name = self::ENCODINGS[\strtolower( $encoding )] ?? '';
		if ( $name === '' ) {
			\error_log( "Tillkite: Escape::set_encoding() doesn't take " . self::log( $encoding ) . ", so the encoding didn't change" );
			return $set;
		}

		if ( $name !== 'UTF-8' && ! \function_exists( 'mb_convert_encoding' ) ) {
			\error_log( 'Tillkite: Escape::set_encoding( ' . self::log( $encoding ) . " ) needs the mbstring extension, so the encoding didn't change" );
			return $set;
		}

		self::$encoding = $name;
		$set = true;
		return $set;
	}

	// For text between tags: & < > " ' as entities. It's safe for plain text
	// in a quoted attribute too, but html_attr() is safe without quotes.
	public static function html( string|int|float|\Stringable|null $value ) : string {
		// UTF-8 is escaped here, without a call to escape_html(), which would
		// take a quarter of html()'s time.
		$text = (string) $value;
		if ( self::$encoding !== 'UTF-8' ) {
			$html = self::escape_html( self::utf8( $text ) );
		} elseif ( \strlen( $text ) >= self::HTML_LONG && \preg_match( '//u', $text ) === 1 ) {
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
		if ( self::$encoding !== 'UTF-8' ) {
			$attr = \strtr( self::html( $text ), self::ATTR );
		} elseif ( \strlen( $text ) >= self::ATTR_LONG && \preg_match( '//u', $text ) === 1 ) {
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
	// end, or a tab or newline inside, and after the URL is converted to
	// UTF-8, which can take bytes out. Then it's escaped as html_attr() does.
	public static function url( string $url, array $schemes = [ 'http', 'https', 'mailto', 'tel' ] ) : string {
		if ( self::$encoding !== 'UTF-8' ) {
			$url = self::utf8( $url );
		}

		$url = \str_replace( [ "\t", "\n", "\r" ], '', \trim( $url, "\x00..\x20" ) );
		if ( \preg_match( '/^([a-zA-Z][a-zA-Z0-9+.\-]*):/', $url, $match ) === 1 && ! \in_array( \strtolower( $match[1] ), $schemes, true ) ) {
			$url = 'about:invalid';
		}

		if ( self::$encoding !== 'UTF-8' ) {
			$escaped = \strtr( self::escape_html( $url ), self::ATTR );
		} elseif ( \strlen( $url ) >= self::ATTR_LONG && \preg_match( '//u', $url ) === 1 ) {
			$escaped = \strtr( $url, self::HTML_ATTR );
		} else {
			$escaped = \strtr( \htmlspecialchars( $url, self::HTML, 'UTF-8' ), self::ATTR );
		}

		return $escaped;
	}

	// For JavaScript: any value as a JavaScript value, quotes included,
	// which is JSON too, and all ASCII. In a string, every character but
	// letters and digits is escaped, so it's safe in <script>, and inside
	// quotes someone wrote around it, where it's an error, not code. In an
	// attribute, like onclick, escape it again with html_attr(). What JSON
	// can't hold, like INF, is logged, and becomes 0 or null. In another
	// encoding than UTF-8, an object's strings need to be UTF-8 already.
	public static function js( mixed $value ) : string {
		if ( self::$encoding !== 'UTF-8' ) {
			$value = self::utf8_value( $value, 0 );
		}

		$json = (string) \json_encode( $value, self::JSON );
		if ( \json_last_error() !== \JSON_ERROR_NONE ) {
			\error_log( 'Tillkite: Escape::js() could not encode all of its value: ' . \json_last_error_msg() );
		}

		// A string's output is all one string, which strtr() takes as it is.
		// Otherwise, with JSON_HEX_QUOT, every " starts or ends a string, so
		// every other piece between them is a string's contents, which
		// explode() finds in less time than a regex.
		if ( \is_string( $value ) ) {
			$js = \strtr( $json, self::JS );
		} else {
			$pieces = \explode( '"', $json );
			$count = \count( $pieces );
			for ( $i = 1; $i < $count; $i += 2 ) {
				$pieces[$i] = \strtr( $pieces[$i], self::JS );
			}

			$js = \implode( '"', $pieces );
		}

		return $js;
	}

	// For text in CSS, in a string or as a name, like a font's or a class's:
	// every ASCII character but letters and digits as an escape, like
	// \000020, and a digit at the start, since a name can't start with one.
	// An escape takes in one whitespace character after it, so one at the
	// end gets a space of its own, and a space written after it stays. A
	// number, colour or URL isn't text, so check it instead: escaping turns
	// #fff into a name.
	public static function css( string $value ) : string {
		if ( self::$encoding !== 'UTF-8' ) {
			$value = self::utf8( $value );
		} elseif ( \preg_match( '//u', $value ) !== 1 ) {
			$value = \htmlspecialchars_decode( \htmlspecialchars( $value, \ENT_NOQUOTES | \ENT_SUBSTITUTE, 'UTF-8' ), \ENT_NOQUOTES );
		}

		$css = \strtr( $value, self::CSS );
		if ( self::$encoding !== 'UTF-8' ) {
			$css = (string) \preg_replace_callback( '/[^\x00-\x7F]/u', static fn ( array $char ) : string => \sprintf( '\\%06X', (int) \mb_ord( $char[0], 'UTF-8' ) ), $css );
		}

		// The digits are 0x30 to 0x39, so \00003 and the digit is its escape.
		if ( \strspn( $css, '0123456789', 0, 1 ) === 1 ) {
			$css = '\\00003' . $css;
		}

		// Every \ in the output starts an escape of seven characters, so it
		// ends with one when the seventh character from the end is a \. A
		// regex would read the whole output.
		if ( \substr( $css, -7, 1 ) === '\\' ) {
			$css .= ' ';
		}

		return $css;
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
	// paths and regexes read as they are, and set_encoding() doesn't change
	// it.
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
	private static function escape_html( string $utf8 ) : string {
		$html = \htmlspecialchars( $utf8, self::HTML, 'UTF-8' );
		$html = \mb_encode_numericentity( (string) \preg_replace( '/[\x{80}-\x{9F}]/u', "\xEF\xBF\xBD", $html ), self::NON_ASCII, 'UTF-8' );
		return $html;
	}

	// Text from the page's encoding, in UTF-8. What isn't valid in it becomes
	// mbstring's substitute character, ? unless mbstring.substitute_character
	// says otherwise.
	private static function utf8( string $text ) : string {
		$utf8 = (string) \mb_convert_encoding( $text, 'UTF-8', self::$encoding );
		return $utf8;
	}

	// A value for js(), with each string and key in it in UTF-8, through its
	// arrays as deep as json_encode() goes, 512, so a recursive array ends.
	// mb_convert_encoding() takes an array, but drops an object in one, with
	// a warning, so an object stays as it is.
	private static function utf8_value( mixed $value, int $depth ) : mixed {
		$utf8 = $value;
		if ( \is_string( $value ) ) {
			$utf8 = self::utf8( $value );
		} elseif ( \is_array( $value ) && $depth < 512 ) {
			$utf8 = [];
			foreach ( $value as $key => $item ) {
				if ( \is_string( $key ) ) {
					$key = self::utf8( $key );
				}

				$utf8[$key] = self::utf8_value( $item, $depth + 1 );
			}
		}

		return $utf8;
	}
}
