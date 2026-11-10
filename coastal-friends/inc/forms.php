<?php
/**
 * Contact forms without Elementor Pro.
 *
 * The page snapshots contain the original Elementor forms, which post to
 * admin-ajax.php with action=elementor_pro_forms_send_form. When PRO Elements
 * / Elementor Pro is not active, the theme answers that request itself: it
 * emails the submission with wp_mail() and replies in the JSON format the
 * form script expects. Recipient: Appearance → Customize → Contact form.
 *
 * @package Coastal_Friends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Register the handler only when Elementor Pro / PRO Elements isn't handling forms.
 */
function cf_forms_init() {
	if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
		return;
	}
	add_action( 'wp_ajax_elementor_pro_forms_send_form', 'cf_forms_send' );
	add_action( 'wp_ajax_nopriv_elementor_pro_forms_send_form', 'cf_forms_send' );
}
add_action( 'init', 'cf_forms_init', 20 );

/**
 * Reply in Elementor's format and stop.
 *
 * @param bool   $success Whether it was sent.
 * @param string $message Message shown under the form.
 * @param array  $errors  Field id => error text.
 */
function cf_forms_reply( $success, $message, $errors = array() ) {
	$data = array( 'message' => $message );
	if ( $errors ) {
		$data['errors'] = $errors;
	}
	wp_send_json( array( 'success' => $success, 'data' => $data ) );
}

/**
 * Handle a submission.
 */
function cf_forms_send() {
	$fail = __( 'Your submission failed because of an error.', 'coastal-friends' );
	// phpcs:disable WordPress.Security.NonceVerification.Missing -- public contact form, same as the original (no nonce).
	$raw = isset( $_POST['form_fields'] ) && is_array( $_POST['form_fields'] ) ? wp_unslash( $_POST['form_fields'] ) : array();
	$page = isset( $_POST['referer_title'] ) ? sanitize_text_field( wp_unslash( $_POST['referer_title'] ) ) : '';
	// phpcs:enable

	$labels = array(
		'name'          => __( 'Name', 'coastal-friends' ),
		'field_29c4a28' => __( 'Subject', 'coastal-friends' ),
		'field_6f77cfe' => __( 'Phone number', 'coastal-friends' ),
		'message'       => __( 'Message', 'coastal-friends' ),
		'email'         => __( 'Email', 'coastal-friends' ),
	);
	$fields = array();
	foreach ( $raw as $key => $value ) {
		$key = sanitize_key( $key );
		$value = is_array( $value ) ? implode( ', ', array_map( 'sanitize_text_field', $value ) ) : sanitize_textarea_field( $value );
		$fields[ $key ] = trim( $value );
	}

	// Same required fields as the original forms (subject and number).
	$errors = array();
	foreach ( array( 'field_29c4a28', 'field_6f77cfe' ) as $required ) {
		if ( array_key_exists( $required, $fields ) && '' === $fields[ $required ] ) {
			$errors[ $required ] = __( 'This field is required.', 'coastal-friends' );
		}
	}
	if ( $errors ) {
		cf_forms_reply( false, $fail, $errors );
	}
	if ( ! $fields || strlen( implode( '', $fields ) ) > 20000 ) {
		cf_forms_reply( false, $fail );
	}

	/* translators: %s: page title. */
	$body = $page ? sprintf( __( 'New form submission from the "%s" page:', 'coastal-friends' ), $page ) : __( 'New form submission:', 'coastal-friends' );
	$body .= "\n\n";
	foreach ( $fields as $key => $value ) {
		$body .= ( isset( $labels[ $key ] ) ? $labels[ $key ] : $key ) . ': ' . $value . "\n";
	}
	$subject = __( 'New message from', 'coastal-friends' ) . ' ' . wp_parse_url( home_url(), PHP_URL_HOST );
	if ( ! empty( $fields['field_29c4a28'] ) ) {
		$subject .= ': ' . $fields['field_29c4a28'];
	}
	$headers = array();
	if ( ! empty( $fields['email'] ) && is_email( $fields['email'] ) ) {
		$headers[] = 'Reply-To: ' . $fields['email'];
	}

	if ( wp_mail( cf_forms_recipient(), $subject, $body, $headers ) ) {
		cf_forms_reply( true, __( 'Your submission was successful.', 'coastal-friends' ) );
	}
	cf_forms_reply( false, $fail );
}

/**
 * Where submissions go.
 *
 * @return string
 */
function cf_forms_recipient() {
	$to = get_theme_mod( 'cf_form_email', '' );
	return is_email( $to ) ? $to : 'info@coastalfriends.org';
}

/**
 * Customizer: Appearance → Customize → Contact form.
 *
 * @param WP_Customize_Manager $wp_customize Customizer instance.
 */
function cf_forms_customize( $wp_customize ) {
	$wp_customize->add_section(
		'cf_forms',
		array(
			'title'       => __( 'Contact form', 'coastal-friends' ),
			'description' => __( 'Email address that receives the Contact Us form messages (used when PRO Elements is not installed).', 'coastal-friends' ),
			'priority'    => 161,
		)
	);
	$wp_customize->add_setting(
		'cf_form_email',
		array(
			'type'              => 'theme_mod',
			'default'           => 'info@coastalfriends.org',
			'sanitize_callback' => 'sanitize_email',
		)
	);
	$wp_customize->add_control(
		'cf_form_email',
		array(
			'label'   => __( 'Send messages to', 'coastal-friends' ),
			'section' => 'cf_forms',
			'type'    => 'email',
		)
	);
}
add_action( 'customize_register', 'cf_forms_customize' );
