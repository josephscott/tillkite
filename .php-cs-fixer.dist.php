<?php
declare( strict_types = 1 );

$fixer_config = new JosephScott\PHPCSFixer_Config( __DIR__ );

// In namespaced code, a leading \ on PHP's own functions and constants, like
// \strlen() and \PHP_SAPI. Without it, PHP looks for Tillkite\strlen() first,
// on every request, can't compile calls like strlen() to faster opcodes, and
// works out class constants like E_ERROR | E_PARSE on every request. Code in
// the global namespace, like the tests, doesn't need it.
$fixer_config->rules['native_function_invocation'] = [
	'include' => [ '@internal' ],
	'scope' => 'namespaced',
	'strict' => true,
];
$fixer_config->rules['native_constant_invocation'] = [
	'scope' => 'namespaced',
];

$config = $fixer_config->get_config();

// dist/tillkite.php, which make dist builds from files that are fixed.
$finder = $fixer_config->finder;
if ( $finder instanceof PhpCsFixer\Finder ) {
	// The shared config makes each top-level directory a root of its own, so
	// excluding the dist directory alone misses the files directly in it.
	$finder->notPath( '#^(dist/)?tillkite\.php$#' );
}

return $config;
