<?php
/**
 * Contact-form handler for the static Coastal Friends site.
 *
 * The pages' Elementor forms still post to /wp-admin/admin-ajax.php with
 * action=elementor_pro_forms_send_form. This script emails the submission and
 * answers in the JSON format Elementor's form script expects, so the forms
 * behave exactly as before. Needs PHP mail() (available on Hostinger).
 */

// ---- Settings -------------------------------------------------------------
$cf_to      = 'info@coastalfriends.org';            // Where submissions are sent.
$cf_subject = 'New message from coastalfriends.org'; // Email subject prefix.
$cf_ok      = 'Your submission was successful.';
$cf_fail    = 'Your submission failed because of an error.';
$cf_require = 'This field is required.';
// ---------------------------------------------------------------------------

header( 'Content-Type: application/json; charset=UTF-8' );
header( 'X-Robots-Tag: noindex' );

function cf_reply( $success, $message, $errors = array() ) {
	// Same shape as Elementor Pro's reply; its script reads data.data.popup / data.data.redirect_url.
	$data = array( 'message' => $message, 'data' => new stdClass() );
	if ( $errors ) {
		$data['errors'] = $errors;
	}
	echo json_encode( array( 'success' => $success, 'data' => $data ) );
	exit;
}

if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || 'elementor_pro_forms_send_form' !== ( $_POST['action'] ?? '' ) ) {
	http_response_code( 400 );
	cf_reply( false, $cf_fail );
}

$labels = array(
	'name'          => 'Name',
	'field_29c4a28' => 'Subject',
	'field_6f77cfe' => 'Phone number',
	'message'       => 'Message',
	'email'         => 'Email',
	'field_c9e2ae1' => 'Phone number',
	'field_80324d9' => 'Job role',
	'field_2e82403' => 'Privacy policy',
);
$fields = isset( $_POST['form_fields'] ) && is_array( $_POST['form_fields'] ) ? $_POST['form_fields'] : array();
$clean  = array();
foreach ( $fields as $key => $value ) {
	$key = preg_replace( '/[^a-z0-9_]/i', '', (string) $key );
	if ( is_array( $value ) ) {
		$value = implode( ', ', $value );
	}
	$clean[ $key ] = trim( strip_tags( (string) $value ) );
}

// Same required fields as the original form (subject and number).
$errors = array();
foreach ( array( 'field_29c4a28', 'field_6f77cfe' ) as $required ) {
	if ( array_key_exists( $required, $clean ) && '' === $clean[ $required ] ) {
		$errors[ $required ] = $cf_require;
	}
}
if ( $errors ) {
	cf_reply( false, $cf_fail, $errors );
}
if ( ! $clean || strlen( implode( '', $clean ) ) > 20000 ) {
	cf_reply( false, $cf_fail );
}

// Spam protection: more than 3 links -> dropped (bot is told "success"); rate limits per visitor and per day.
if ( preg_match_all( '#(https?://|www\.)#i', implode( ' ', $clean ) ) > 3 ) {
	cf_reply( true, $cf_ok );
}
$ip    = (string) ( $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '' );
$store = sys_get_temp_dir() . '/cf-form-' . md5( __FILE__ ) . '.json';
$log   = is_readable( $store ) ? (array) json_decode( (string) file_get_contents( $store ), true ) : array();
$now   = time();
$log   = array_filter( $log, static function ( $e ) use ( $now ) { return $now - (int) $e['t'] < 86400; } );
$mine  = array_filter( $log, static function ( $e ) use ( $ip, $now ) { return $e['ip'] === md5( $ip ) && $now - (int) $e['t'] < 600; } );
if ( count( $mine ) >= 5 || count( $log ) >= 100 ) {
	cf_reply( false, 'Too many messages were sent. Please try again later.' );
}
$log[] = array( 'ip' => md5( $ip ), 't' => $now );
@file_put_contents( $store, json_encode( array_values( $log ) ), LOCK_EX );

$page = trim( strip_tags( (string) ( $_POST['referer_title'] ?? '' ) ) );
$body = "New form submission" . ( $page ? " from the \"$page\" page" : '' ) . ":\n\n";
foreach ( $clean as $key => $value ) {
	$body .= ( $labels[ $key ] ?? $key ) . ': ' . $value . "\n";
}
$body .= "\n---\nSent: " . gmdate( 'Y-m-d H:i' ) . " UTC\nIP: " . ( $_SERVER['REMOTE_ADDR'] ?? '' ) . "\n";

$host    = preg_replace( '/[^a-z0-9.-]/i', '', preg_replace( '/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'localhost' ) );
$host    = preg_replace( '/^www\./i', '', $host );
$subject = $cf_subject . ( isset( $clean['field_29c4a28'] ) && '' !== $clean['field_29c4a28'] ? ': ' . $clean['field_29c4a28'] : '' );
$subject = '=?UTF-8?B?' . base64_encode( str_replace( array( "\r", "\n" ), ' ', $subject ) ) . '?=';
$headers = array(
	'From: Coastal Friends Website <no-reply@' . $host . '>',
	'Content-Type: text/plain; charset=UTF-8',
);
if ( ! empty( $clean['email'] ) && filter_var( $clean['email'], FILTER_VALIDATE_EMAIL ) ) {
	$headers[] = 'Reply-To: ' . $clean['email'];
}

if ( @mail( $cf_to, $subject, $body, implode( "\r\n", $headers ) ) ) {
	cf_reply( true, $cf_ok );
}
cf_reply( false, $cf_fail );
