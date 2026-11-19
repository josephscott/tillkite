<?php
declare( strict_types = 1 );

echo 'partial';
ob_start();
echo 'more';
throw new RuntimeException( 'Boom' );
