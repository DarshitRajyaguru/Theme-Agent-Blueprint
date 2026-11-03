<?php

$plugin_root = dirname( __DIR__ );
$javascript = file_get_contents( $plugin_root . '/assets/admin.js' );
$stylesheet = file_get_contents( $plugin_root . '/assets/admin.css' );
$failures = array();

if ( false !== strpos( $javascript, 'form.action' ) ) {
	$failures[] = 'A form action property can be shadowed by the hidden input named action.';
}
if ( false === strpos( $javascript, "form.getAttribute('action')" ) ) {
	$failures[] = 'Form endpoints are not read from the explicit action attribute.';
}
if ( false === strpos( $javascript, 'showResponseNotices(request.responseText)' ) ) {
	$failures[] = 'Import responses do not remain on the current admin page.';
}
if ( false === strpos( $stylesheet, '.tab-wrap a .dashicons,.tab-wrap button .dashicons{display:contents!important}' ) ) {
	$failures[] = 'Clickable Dashicon spans are not flattened consistently.';
}

if ( $failures ) {
	fwrite( STDERR, "FAIL\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}

echo 'PASS: form endpoints, in-page import response, and clickable Dashicon styles.' . PHP_EOL;