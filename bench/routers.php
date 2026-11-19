<?php
declare( strict_types = 1 );

namespace Bench;

use FastRoute\Dispatcher;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std;
use Symfony\Component\Routing\Exception\ExceptionInterface;
use Symfony\Component\Routing\Matcher\CompiledUrlMatcher;
use Symfony\Component\Routing\Matcher\Dumper\CompiledUrlMatcherDumper;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Wilaak\Http\RadixRouter;

// Each router builds its cache file once, then:
// - load() does what each php-fpm request does: includes the cache file
//   (from OPcache) and creates the router.
// - match() matches one request, and returns the route's position in the
//   list, or -1 when nothing matches.
//
// Routes are [ method, path ] pairs with {name} parameters.
interface Router {
	public function build( array $routes, string $file ) : void;

	public function load( string $file ) : object;

	public function match( object $router, string $method, string $path ) : int;
}

// The routers make bench and make load compare, by name. Tillkite's handler
// file goes in $handlers.
function routers( string $handlers ) : array {
	$routers = [
		'Tillkite' => new Tillkite_Router( $handlers ),
		'FastRoute GroupCountBased' => new FastRoute_Router( \FastRoute\DataGenerator\GroupCountBased::class, Dispatcher\GroupCountBased::class ),
		'FastRoute MarkBased' => new FastRoute_Router( \FastRoute\DataGenerator\MarkBased::class, Dispatcher\MarkBased::class ),
		'Symfony CompiledUrlMatcher' => new Symfony_Router(),
		'RadixRouter' => new Radix_Router(),
	];
	return $routers;
}

// A request for a route: each parameter gets a value that no literal segment uses.
function request_for( array $route ) : array {
	$count = 0;
	$path = (string) \preg_replace_callback(
		'/\{\w+\}/',
		function() use ( &$count ) {
			$count++;
			return 'p' . $count;
		},
		$route[1]
	);

	$request = [ $route[0], $path ];
	return $request;
}

// The first route, the route just before the halfway point, the last route,
// and a miss that shares the last route's path, so a router has to look
// before it gives up. Each maps to the route index it must match, or -1.
function requests_for( array $routes ) : array {
	$last = \count( $routes ) - 1;
	$middle = \max( 0, \intdiv( \count( $routes ), 2 ) - 1 );
	$miss = request_for( $routes[$last] );
	$miss[1] .= '/nope';

	$requests = [
		'first' => [ request_for( $routes[0] ), 0 ],
		'middle' => [ request_for( $routes[$middle] ), $middle ],
		'last' => [ request_for( $routes[$last] ), $last ],
		'miss' => [ $miss, -1 ],
	];
	return $requests;
}

// Writes a cache file the way each router's own cache does, and sets its
// modification time in the past, so OPcache caches it right away instead of
// waiting out opcache.file_update_protection.
function write_cache( string $file, string $php ) : void {
	\file_put_contents( $file, $php );
	\touch( $file, \time() - 60 );
}

final class Tillkite_Router implements Router {
	public function __construct( private string $handlers ) {}

	public function build( array $routes, string $file ) : void {
		$entries = [];
		foreach ( $routes as [ $method, $path ] ) {
			$entries[] = [ $method, $path, 'handler.php' ];
		}

		$compiler = new \Tillkite\Compiler();
		$compiler->handlers = $this->handlers;
		$compiled = $compiler->compile( $entries );
		if ( $compiler->problems !== [] ) {
			\fwrite( \STDERR, \implode( "\n", $compiler->problems ) . "\n" );
			exit( 1 );
		}

		write_cache( $file, '<?php return ' . \var_export( $compiled, true ) . ';' );
	}

	public function load( string $file ) : object {
		$matcher = new \Tillkite\Matcher( require $file );
		return $matcher;
	}

	public function match( object $router, string $method, string $path ) : int {
		$result = $router->match( $method, $path );
		$route = -1;
		if ( $result['status'] === 200 ) {
			$route = $result['route'];
		}

		return $route;
	}
}

// FastRoute's cachedDispatcher() does the same: require the cache file and
// create the dispatcher.
final class FastRoute_Router implements Router {
	public function __construct( private string $generator, private string $dispatcher ) {}

	public function build( array $routes, string $file ) : void {
		// FastRoute 1.3's MarkBased generator increments a string, which PHP
		// 8.3 and later report as deprecated. It only happens while building.
		$level = \error_reporting( \E_ALL & ~\E_DEPRECATED );
		$collector = new RouteCollector( new Std(), new $this->generator() );
		foreach ( $routes as $index => [ $method, $path ] ) {
			$collector->addRoute( $method, $path, $index );
		}

		$data = $collector->getData();
		\error_reporting( $level );
		write_cache( $file, '<?php return ' . \var_export( $data, true ) . ';' );
	}

	public function load( string $file ) : object {
		$dispatcher = new $this->dispatcher( require $file );
		return $dispatcher;
	}

	public function match( object $router, string $method, string $path ) : int {
		$result = $router->dispatch( $method, $path );
		$route = -1;
		if ( $result[0] === Dispatcher::FOUND ) {
			$route = $result[1];
		}

		return $route;
	}
}

final class Symfony_Router implements Router {
	public function build( array $routes, string $file ) : void {
		$collection = new RouteCollection();
		foreach ( $routes as $index => [ $method, $path ] ) {
			$collection->add( 'r' . $index, new Route( $path, [ '_index' => $index ], [], [], '', [], [ $method ] ) );
		}

		$dumper = new CompiledUrlMatcherDumper( $collection );
		write_cache( $file, $dumper->dump() );
	}

	public function load( string $file ) : object {
		$matcher = new CompiledUrlMatcher( require $file, new RequestContext() );
		return $matcher;
	}

	// Symfony reports a miss by throwing, so this is the one place the
	// benchmark catches an exception.
	public function match( object $router, string $method, string $path ) : int {
		$router->getContext()->setMethod( $method );
		$route = -1;
		try {
			$route = $router->match( $path )['_index'];
		} catch ( ExceptionInterface ) {
			$route = -1;
		}

		return $route;
	}
}

// RadixRouter's documented route cache: var_export() its tree and static
// properties, then set them back on a new router.
final class Radix_Router implements Router {
	public function build( array $routes, string $file ) : void {
		$router = new RadixRouter();
		foreach ( $routes as $index => [ $method, $path ] ) {
			$router->add( $method, (string) \preg_replace( '/\{(\w+)\}/', ':$1', $path ), $index );
		}

		write_cache( $file, '<?php return ' . \var_export( [ $router->tree, $router->static ], true ) . ';' );
	}

	public function load( string $file ) : object {
		$cached = require $file;
		$router = new RadixRouter();
		$router->tree = $cached[0];
		$router->static = $cached[1];
		return $router;
	}

	public function match( object $router, string $method, string $path ) : int {
		$result = $router->lookup( $method, $path );
		$route = -1;
		if ( $result['code'] === 200 ) {
			$route = $result['handler'];
		}

		return $route;
	}
}
