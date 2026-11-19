<?php
declare( strict_types = 1 );

// A status under 300 doesn't answer the request, even with a Location.
header( 'Location: /new', true, 201 );
