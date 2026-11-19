<?php
declare( strict_types = 1 );

// A PHP file in the document root that isn't the front controller. A request
// for it gets Tillkite's 404: php -S doesn't run it, and nginx doesn't send
// it.
echo "ran without Tillkite\n";
