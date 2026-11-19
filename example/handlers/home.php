<?php
declare( strict_types = 1 );

echo $tillkite->template( 'layout.php', [
	'title' => 'Hello, ' . $visitor,
	'content' => <<<'HTML'
		<p class="lead">Every page here is a PHP file that Tillkite routes to, and shows the route it matched. Try each one:</p>
		<dl>
		<div>
		<dt><a href="/users/new">/users/new</a> and <a href="/users/42">/users/42</a></dt>
		<dd>A static route, and a dynamic one whose id has to be digits.</dd>
		</div>
		<div>
		<dt><a href="/blog">/blog</a>, <a href="/blog/2026">/blog/2026</a>, <a href="/blog/2026/10">/blog/2026/10</a> and <a href="/blog/2026/10/this-is-the-slug">/blog/2026/10/this-is-the-slug</a></dt>
		<dd>One route, where each part after <code>/blog</code> is optional. A post that doesn't exist, like <a href="/blog/2026/10/no-such-post">/blog/2026/10/no-such-post</a>, shows the 404 page with <code>error()</code>. The posts come from <code>get()</code>, which returns what <code>index.php</code> gave <code>register()</code>.</dd>
		</div>
		<div>
		<dt><a href="/docs/guide/install">/docs/guide/install</a>, <a href="/docs/guide/routes">/docs/guide/routes</a> and <a href="/docs/guide/nope">/docs/guide/nope</a></dt>
		<dd>A parameter that takes the rest of the path, which names a file in <code>docs/</code>. The handler finds it with <code>file()</code>, which returns <code>null</code> for a name that isn't a file inside the directory, so a page that doesn't exist is the 404 page.</dd>
		</div>
		<div>
		<dt><a href="/contact">/contact</a></dt>
		<dd>One route for GET and POST.</dd>
		</div>
		<div>
		<dt><a href="/users/42/">/users/42/</a></dt>
		<dd>Redirects to the URL without the trailing slash.</dd>
		</div>
		<div>
		<dt><a href="/nope">/nope</a> and <a href="/boom">/boom</a></dt>
		<dd>The 404 page, and the 500 page for an exception.</dd>
		</div>
		</dl>
		<p>The handlers are in directories where that keeps them in order: <code>handlers/users/</code> holds the files for one part of the site, and <code>errors/</code> holds the error pages. <code>routes.php</code> names each file by its path inside <code>handlers/</code>, like <code>users/show.php</code>. A file's path has nothing to do with its URL.</p>
		<p>Each page's handler puts its content in the layout, <code>templates/layout.php</code>, with <code>template()</code>. The layout runs the parts that are the same on every page the same way, <code>head.php</code>, <code>header.php</code> and <code>footer.php</code>, and the header runs <code>nav.php</code>. A template has a scope of its own, with only <code>$tillkite</code> and the <code>$data</code> it was given.</p>
		<p>In development, under <code>php -S</code>, each page has a Stack trace button that shows how PHP got to it. On the 500 page, it shows where the exception was thrown.</p>
		<p>In development, every response has a <code>Server-Timing</code> header with the time each step took. The bottom of each page shows it. It's off everywhere else, because timings every visitor can see can give information away.</p>
		HTML,
] );
