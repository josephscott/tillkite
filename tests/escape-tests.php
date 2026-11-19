<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// Each test parses the output with PHP's HTML5 parser, which follows the
// HTML standard as browsers do, and checks that the value comes back as it
// was, with nothing added: no element, attribute or code of its own.

// Values that try to end where they're printed, or start something there.
const ATTACKS = [
	'x onmouseover=alert(1)',
	"\"'><script>alert(1)</script><!--",
	'a&b &amp; &#106;avascript: &lt;',
	'`back` a=b /> < >',
	"tab\there\nline\fend",
	'é ü 中 😀',
	"bad \xff byte",
	"\xE2\x80\xA8 \xE2\x80\xA9 \xEF\xBB\xBF",
	'</style></script></textarea></title>',
	'',
];

afterEach( function() {
	Esc::set_encoding( 'UTF-8' );
} );

function parse_html( string $body ) : Dom\HTMLDocument {
	$document = Dom\HTMLDocument::createFromString( '<!doctype html><body>' . $body, LIBXML_NOERROR );
	return $document;
}

// The text a browser gets from the value: each invalid byte as U+FFFD.
function shown( string $value ) : string {
	$shown = (string) json_decode( (string) json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE ) );
	return $shown;
}

// What a CSS parser makes of css() output's escapes, each of which takes in
// one whitespace character after it.
function css_decoded( string $css ) : string {
	$decoded = (string) preg_replace_callback( '/\\\\([0-9A-F]{6})[ \t\n\r\f]?/', fn ( array $hex ) : string => (string) mb_chr( (int) hexdec( $hex[1] ), 'UTF-8' ), $css );
	return $decoded;
}

it( 'gives back each value from html() as text and in a quoted attribute, and from html_attr() in an unquoted one too', function( string $method, array $places ) {
	$markup = [
		'text' => fn ( string $v ) : string => '<div>' . $v . '</div>',
		'double' => fn ( string $v ) : string => '<div title="' . $v . '"></div>',
		'single' => fn ( string $v ) : string => "<div title='" . $v . "'></div>",
		'unquoted' => fn ( string $v ) : string => '<div title=' . $v . '></div>',
	];

	foreach ( $places as $place ) {
		foreach ( ATTACKS as $value ) {
			$div = parse_html( $markup[$place]( Esc::$method( $value ) ) )->querySelector( 'div' );
			if ( $place === 'text' ) {
				expect( [ $div->textContent, $div->childElementCount ] )->toBe( [ shown( $value ), 0 ] );
			} else {
				expect( [ $div->getAttribute( 'title' ), $div->attributes->length ] )->toBe( [ shown( $value ), 1 ] );
			}
		}
	}
} )->with( [
	'html()' => [ 'html', [ 'text', 'double', 'single' ] ],
	'html_attr()' => [ 'html_attr', [ 'text', 'double', 'single', 'unquoted' ] ],
] );

it( 'takes numbers, null and Stringable objects in html() and html_attr()', function() {
	$stringable = new class() {
		public function __toString() : string {
			return 'a <b>';
		}
	};

	expect( [ Esc::html( 42 ), Esc::html( 1.5 ), Esc::html( null ), Esc::html( $stringable ) ] )->toBe( [ '42', '1.5', '', 'a &lt;b&gt;' ] );
	expect( Esc::html_attr( $stringable ) )->toBe( 'a&#32;&lt;b&gt;' );
} );

it( 'makes a URL with a scheme it does not allow about:invalid, however the scheme is hidden', function( string $url, array $schemes ) {
	expect( Esc::url( $url, $schemes ) )->toBe( 'about:invalid' );
} )->with( [
	'javascript:' => [ 'javascript:alert(1)', [ 'http', 'https', 'mailto', 'tel' ] ],
	'mixed case' => [ 'JaVaScRiPt:alert(1)', [ 'http', 'https', 'mailto', 'tel' ] ],
	'controls and spaces in front' => [ " \x00\x01\x0B\x1F javascript:alert(1)", [ 'http', 'https', 'mailto', 'tel' ] ],
	'tabs and newlines inside' => [ "jav\tas\ncr\ript:alert(1)", [ 'http', 'https', 'mailto', 'tel' ] ],
	'data:' => [ 'data:text/html,<script>alert(1)</script>', [ 'http', 'https', 'mailto', 'tel' ] ],
	'vbscript:' => [ 'vbscript:msgbox(1)', [ 'http', 'https', 'mailto', 'tel' ] ],
	'a scheme left out' => [ 'https://example.com/', [ 'ftp' ] ],
] );

it( 'gives back a URL with a scheme it allows, or none, in quoted and unquoted attributes', function( string $url, array $schemes ) {
	foreach ( [ '<a href="%s"></a>', '<a href=%s></a>' ] as $markup ) {
		$a = parse_html( sprintf( $markup, Esc::url( $url, $schemes ) ) )->querySelector( 'a' );
		expect( [ $a->getAttribute( 'href' ), $a->attributes->length ] )->toBe( [ $url, 1 ] );
	}
} )->with( [
	'https' => [ 'https://example.com/a b?x=1&y="2"&z=\'3\'#`top` <', [ 'http', 'https', 'mailto', 'tel' ] ],
	'upper case' => [ 'HTTPS://EXAMPLE.COM/', [ 'http', 'https', 'mailto', 'tel' ] ],
	'mailto' => [ 'mailto:ann@example.com?subject=Hi there', [ 'http', 'https', 'mailto', 'tel' ] ],
	'relative' => [ '/blog/2026?page=2', [ 'http', 'https', 'mailto', 'tel' ] ],
	'no scheme, as an entity is text' => [ '&#106;avascript:alert(1)', [ 'http', 'https', 'mailto', 'tel' ] ],
	'a colon after a slash' => [ './javascript:alert(1)', [ 'http', 'https', 'mailto', 'tel' ] ],
	'a scheme added' => [ 'ftp://example.com/', [ 'ftp' ] ],
] );

// A string holds only letters, digits, \ escapes and characters outside
// ASCII, so nothing in it can end the string, or end the script, even inside
// quotes written around it, and outside its strings there's only JSON's own
// punctuation. That's what makes it safe in <script> and as JSON.
it( 'gives JSON that decodes to the value, with only letters, digits and escapes in its strings', function() {
	$values = [ ...ATTACKS, "in\xC2\xA0new\xE2\x80\x80f\xE3\x80\x80in", [ 'list' => [ 1, -2.5, 1e100, PHP_INT_MAX, true, false, null, '' ], 'a "b"' => [ '</script>' => "x\xffy" ] ], [], 0, 'x' ];
	foreach ( $values as $value ) {
		$js = Esc::js( $value );
		$expected = json_decode( (string) json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE ), true );
		expect( json_decode( $js, true ) )->toBe( $expected );

		preg_match_all( '/"([^"]*)"/', $js, $strings );
		foreach ( $strings[1] as $string ) {
			expect( $string )->toMatch( '/^(?:[a-zA-Z0-9]|\\\\[a-zA-Z0-9\\\\])*$/' );
		}

		expect( (string) preg_replace( '/"[^"]*"/', '', $js ) )->toMatch( '/^[a-zA-Z0-9{}\[\]:,\-+.]*$/' );

		$document = parse_html( '<script>x = ' . $js . ';</script><p id="after"></p>' );
		expect( $document->querySelector( 'script' )->textContent )->toBe( 'x = ' . $js . ';' );
		expect( $document->getElementById( 'after' ) )->not->toBeNull();
	}
} );

it( 'encodes objects in js(), and logs what JSON cannot hold', function() {
	$object = new stdClass();
	$object->name = 'Ann <b>';
	expect( Esc::js( [ 'o' => $object ] ) )->toBe( '{"o":{"name":"Ann\\u0020\\u003Cb\\u003E"}}' );

	$log = logged( function() {
		expect( Esc::js( [ 'a' => INF, 'b' => 1 ] ) )->toBe( '{"a":0,"b":1}' );
	} );
	expect( $log )->toContain( 'Tillkite: Escape::js() could not encode all of its value: Inf and NaN cannot be JSON encoded' );
} );

// The space after it is there because an escape at the end of css()'s output
// must not take in the space written after it.
it( 'gives CSS with only letters, digits, six-digit escapes and characters outside ASCII, and no digit first, which decodes to the text', function() {
	foreach ( [ ...ATTACKS, '1abc', 'a_', '0' ] as $value ) {
		$css = Esc::css( $value );
		expect( $css )->toMatch( '/^(?![0-9])(?:[a-zA-Z0-9\x80-\xff]|\\\\[0-9A-F]{6})* ?$/' );
		expect( css_decoded( $css . ' x' ) )->toBe( shown( $value ) . ' x' );
	}
} );

// Text in another encoding goes in as bytes in that encoding. Each value ends
// with bytes that start a longer character, then " \ ', so a decoder that
// joined them would take in what follows. The output must be all ASCII,
// and give back what mbstring reads the bytes as, but in HTML, U+0080 to
// U+009F as U+FFFD, since no character reference can be one of them.
it( 'escapes text in another encoding, with output all in ASCII', function( string $encoding, string $mbstring, string $sample ) {
	expect( Esc::set_encoding( $encoding ) )->toBeTrue();
	$bytes = (string) mb_convert_encoding( '<"\'&> =` ' . $sample, $mbstring, 'UTF-8' ) . "\x81\"\x81\\\xbf'";
	$text = (string) mb_convert_encoding( $bytes, 'UTF-8', $mbstring );
	$html = (string) preg_replace( '/[\x{80}-\x{9F}]/u', "\u{FFFD}", $text );

	$outputs = [ Esc::html( $bytes ), Esc::html_attr( $bytes ), Esc::url( '/' . $bytes ), Esc::js( [ $bytes => $bytes ] ), Esc::css( $bytes ) ];
	foreach ( $outputs as $output ) {
		expect( $output )->toMatch( '/^[\x00-\x7F]*$/' );
	}

	$div = parse_html( '<div title=' . $outputs[1] . '>' . $outputs[0] . '</div><a href=' . $outputs[2] . '></a>' )->querySelector( 'div' );
	expect( [ $div->textContent, $div->getAttribute( 'title' ), $div->attributes->length ] )->toBe( [ $html, $html, 1 ] );
	expect( $div->ownerDocument->querySelector( 'a' )->getAttribute( 'href' ) )->toBe( '/' . $html );
	expect( json_decode( $outputs[3], true ) )->toBe( [ $text => $text ] );
	expect( css_decoded( $outputs[4] ) )->toBe( $text );
} )->with( [
	[ 'iso-8859-1', 'Windows-1252', 'é€“' ],
	[ 'ISO-8859-2', 'ISO-8859-2', 'ł' ],
	[ 'iso-8859-5', 'ISO-8859-5', 'Ж' ],
	[ 'iso-8859-7', 'ISO-8859-7', 'λ' ],
	[ 'iso-8859-15', 'ISO-8859-15', '€' ],
	[ 'windows-1251', 'Windows-1251', 'Ж' ],
	[ 'windows-1252', 'Windows-1252', '€“' ],
	[ 'windows-1254', 'Windows-1254', 'ğ' ],
	[ 'IBM866', 'CP866', 'Ж' ],
	[ 'koi8-r', 'KOI8-R', 'Ж' ],
	[ 'koi8-u', 'KOI8-U', 'ї' ],
	[ 'Shift_JIS', 'CP932', 'あ表ソ①' ],
	[ 'EUC-JP', 'eucJP-win', 'あ表①' ],
	[ 'EUC-KR', 'UHC', '한국' ],
	[ 'GBK', 'GB18030', '中文€' ],
	[ 'gb18030', 'GB18030', '中文😀' ],
	[ 'Big5', 'CP950', '中文' ],
] );

it( 'checks a URL\'s scheme after converting it, when mbstring takes out the bytes it cannot read', function() {
	$previous = ini_get( 'mbstring.substitute_character' );
	ini_set( 'mbstring.substitute_character', 'none' );
	Esc::set_encoding( 'EUC-JP' );
	$url = Esc::url( "java\x81script:alert(1)" );
	ini_set( 'mbstring.substitute_character', (string) $previous );

	expect( $url )->toBe( 'about:invalid' );
} );

it( 'ends an array that holds itself in another encoding, and logs it', function() {
	Esc::set_encoding( 'Shift_JIS' );
	$array = [ 'a' => "\x82\xa0" ];
	$array['self'] = &$array;
	$log = logged( function() use ( $array ) {
		expect( Esc::js( $array ) )->toStartWith( '{"a":"\\u3042","self":{"a":"\\u3042",' );
	} );

	expect( $log )->toContain( 'Tillkite: Escape::js() could not encode all of its value: Maximum stack depth exceeded' );
} );

it( 'logs an encoding it does not take, and keeps the one it had', function() {
	Esc::set_encoding( 'shift_jis' );
	$log = logged( function() {
		expect( Esc::set_encoding( 'UTF-16' ) )->toBeFalse();
	} );

	expect( $log )->toContain( "Tillkite: Escape::set_encoding() doesn't take UTF-16, so the encoding didn't change" );
	expect( Esc::html( "\x82\xa0" ) )->toBe( '&#12354;' );
} );

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
