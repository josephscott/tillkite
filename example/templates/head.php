<?php
declare( strict_types = 1 );

use Tillkite\Escape as Esc;

// The page's <head>, which header.php runs with template(), with the page's
// title, as plain text. The icon is inline, so browsers don't ask for
// /favicon.ico, which would run the 404 page on every visit.
?>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title><?= Esc::html( $data['title'] ) ?> · Tillkite example</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Cpath d='M16 1 27 11 16 25 5 11Z' fill='%231d4ed8'/%3E%3C/svg%3E">
<link rel="stylesheet" href="/style.css">
</head>
