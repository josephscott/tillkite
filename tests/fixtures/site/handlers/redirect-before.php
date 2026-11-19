<?php
declare( strict_types = 1 );

// It redirects, and forgets to return false.
header( 'Location: /sign-in' );
echo 'signing in';
