<?php
declare( strict_types = 1 );

// The header at the top of every page, which the layout runs with
// template(). The nav is a template of its own, which this one runs.
?>
<header>
<a class="brand" href="/"><svg class="kite" viewBox="0 0 32 32" width="26" height="26" aria-hidden="true"><path d="M16 1 27 11 16 25 5 11Z"/><path class="spars" d="M16 1v24M5 11h22"/><path class="tail" d="M16 25q-3 2.5 0 3.5t0 3.5"/></svg>Tillkite <span>example</span></a>
<?= $tillkite->template( 'nav.php' ) ?>
</header>
