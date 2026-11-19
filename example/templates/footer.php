<?php
declare( strict_types = 1 );

// The footer at the bottom of every page, which the layout runs with
// template(). It shows the page's Server-Timing header, which index.php
// turns on only in development, with a row for each
// template the page ran, this one too. Browsers give the header to JavaScript
// on the same origin, in a secure context: HTTPS, or http://localhost.
// Another origin would also need a Timing-Allow-Origin header.
?>
<footer>
<h2>Server-Timing</h2>
<p>How long Tillkite took to answer this request, in milliseconds, from the response's <code>Server-Timing</code> header. Your browser's developer tools show it too. Each bar compares a step with the longest one.</p>
<ul id="server-timing" hidden></ul>
<p id="server-timing-missing">Not available. The header is sent only in development, under <code>php -S</code>, and showing it needs JavaScript, and HTTPS or localhost.</p>
</footer>
<script>
// What each of Tillkite's steps measures.
const steps = {
	'tillkite-total': 'Everything Tillkite did, from the start of run() to the end of the response.',
	'tillkite-route': 'Loading the route cache, and finding the route that matches the URL.',
	'tillkite-before': 'Running the before-files, which run ahead of the handler.',
	'tillkite-handler': 'Running the handler, the PHP file that built this page, without the templates it ran.',
	'tillkite-error': 'Running the error page, without the templates it ran.',
};

// A template's metric has its name as the description.
const about = ( metric ) => {
	if ( metric.name === 'tillkite-template' ) {
		return 'Running the template templates/' + metric.description + ', without the templates it ran.';
	}

	return steps[metric.name] ?? metric.description;
};

const metrics = performance.getEntriesByType( 'navigation' )[0]?.serverTiming ?? [];
if ( metrics.length > 0 ) {
	const add = ( parent, tag, text ) => parent.appendChild( Object.assign( document.createElement( tag ), { textContent: text } ) );
	const longest = Math.max( 0.001, ...metrics.map( ( metric ) => metric.duration ) );
	const list = document.getElementById( 'server-timing' );
	for ( const metric of metrics ) {
		const item = add( list, 'li', '' );
		add( item, 'code', metric.name );
		add( item, 'span', metric.duration.toFixed( 3 ) + ' ms' );
		add( item, 'p', about( metric ) );
		const bar = add( item, 'span', '' );
		bar.className = 'bar';
		bar.style.width = ( metric.duration / longest * 100 ) + '%';
	}
	list.hidden = false;
	document.getElementById( 'server-timing-missing' ).hidden = true;
}
</script>
