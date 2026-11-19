<?php
declare( strict_types = 1 );

// It sets an error status, and forgets to return false.
http_response_code( 403 );
echo 'forbidden';
