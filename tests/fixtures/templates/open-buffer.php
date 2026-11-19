<?php
declare( strict_types = 1 );

// Leaves a buffer of its own, which is still its output.
echo 'Hello, ';
ob_start();
echo $data['name'];
