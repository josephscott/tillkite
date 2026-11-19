<?php
declare( strict_types = 1 );

// The route turns the before-file off, so $visitor isn't set here, and
// nothing has checked who sent the request. A webhook checks that itself:
// GitHub signs each delivery's body with the secret the webhook was set up
// with, in the X-Hub-Signature-256 header, and only a request signed with
// the same secret gets through. hash_equals() takes the same time whatever
// the signatures hold, so how long it takes doesn't give one away.
//
// The secret comes from the environment, so it isn't in the code or the
// repository. make serve sets GITHUB_WEBHOOK_SECRET to example-secret, and
// under php-fpm, the pool's env[GITHUB_WEBHOOK_SECRET] sets it. Without
// one, every request is turned away, since anyone can sign with an empty
// secret. To send a signed request:
//
//   body='{"zen":"Keep it logically awesome."}'
//   signature=$( printf %s "$body" | openssl dgst -sha256 -hmac example-secret | cut -d ' ' -f 2 )
//   curl -X POST -H "X-Hub-Signature-256: sha256=$signature" -d "$body" http://localhost:8417/hooks/github
header( 'Content-Type: text/plain; charset=utf-8' );
$secret = (string) getenv( 'GITHUB_WEBHOOK_SECRET' );
$signature = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
$body = (string) file_get_contents( 'php://input' );
if ( $secret === '' || ! is_string( $signature ) || ! hash_equals( 'sha256=' . hash_hmac( 'sha256', $body, $secret ), $signature ) ) {
	http_response_code( 401 );
	echo "The signature doesn't match the body.\n";
	return;
}

echo "Received, with a valid signature. The before-file didn't run.\n";
