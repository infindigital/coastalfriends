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
