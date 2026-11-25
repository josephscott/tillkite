<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// The route that matched, from $tillkite->route(), which each page's handler
// runs with template() under its heading. A page that no route matched, like
// the 404 page for /nope, has none, so this shows nothing.
$route = $tillkite->route();
if ( $route === null ) {
	return;
}
?>
<figure class="route"><figcaption>The route for this page, in routes.php</figcaption><pre><code><?= Esc::html( route_source( $route ) ) ?></code></pre></figure>
