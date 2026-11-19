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

function parse_html( string $body ) : Dom\HTMLDocument {
	$document = Dom\HTMLDocument::createFromString( '<!doctype html><body>' . $body, LIBXML_NOERROR );
	return $document;
}

// The text a browser gets from the value: each invalid byte as U+FFFD.
function shown( string $value ) : string {
	$shown = (string) json_decode( (string) json_encode( $value, JSON_INVALID_UTF8_SUBSTITUTE ) );
	return $shown;
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
