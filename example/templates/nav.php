<?php
declare( strict_types = 1 );

// The nav, which the header runs with template(). It marks Admin on every
// page in the /admin group, from $tillkite->group(), even the 403 or 404
// page for a path under /admin that no route matches.
$admin = '';
if ( $tillkite->group() === '/admin' ) {
	$admin = ' aria-current="true"';
}
?>
<nav><a href="/blog">Blog</a><a href="/docs/guide/install">Docs</a><a href="/contact">Contact</a><a href="/admin"<?= $admin ?>>Admin</a></nav>
