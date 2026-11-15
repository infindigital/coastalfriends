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
 * Hidden anti-spam fields for the contact forms (honeypot + signed timestamp).
 *
 * @return string
 */
function cf_forms_trap_fields() {
	$t = (string) time();
	return '<div aria-hidden="true" style="position:absolute!important;left:-10000px!important;width:1px;height:1px;overflow:hidden">'
		. '<label>' . esc_html__( 'Leave this empty', 'coastal-friends' ) . '<input type="text" name="form_fields[cf_website]" value="" tabindex="-1" autocomplete="off"></label>'
		. '<input type="hidden" name="form_fields[cf_ts]" value="' . esc_attr( $t . '.' . wp_hash( 'cf_form_ts|' . $t ) ) . '">'
		. '</div>';
}

/**
 * Reply in Elementor's format and stop.
 *
 * @param bool   $success Whether it was sent.
 * @param string $message Message shown under the form.
 * @param array  $errors  Field id => error text.
 */
function cf_forms_reply( $success, $message, $errors = array() ) {
	// Same shape as Elementor Pro's reply; its script reads data.data.popup / data.data.redirect_url.
	$data = array( 'message' => $message, 'data' => new stdClass() );
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
		'field_c9e2ae1' => __( 'Phone number', 'coastal-friends' ),
		'field_80324d9' => __( 'Job role', 'coastal-friends' ),
		'field_2e82403' => __( 'Privacy policy', 'coastal-friends' ),
	);
	// --- Spam protection -------------------------------------------------------
	// Bots get the normal "success" reply, so they can't tell they were blocked.
	$ok = __( 'Your submission was successful.', 'coastal-friends' );
	// 1) Honeypot: a hidden field real visitors never see or fill.
	if ( ! empty( $raw['cf_website'] ) ) {
		cf_forms_reply( true, $ok );
	}
	// 2) Signed timestamp from the page: humans need more than 3 seconds to fill the form.
	if ( ! empty( $raw['cf_ts'] ) ) {
		$parts = explode( '.', (string) $raw['cf_ts'] );
		if ( 2 !== count( $parts ) || ! hash_equals( wp_hash( 'cf_form_ts|' . $parts[0] ), $parts[1] ) || time() - (int) $parts[0] < 3 ) {
			cf_forms_reply( true, $ok );
		}
	}
	unset( $raw['cf_website'], $raw['cf_ts'] );
	// 3) Rate limits: 5 messages per visitor per 10 minutes, 100 per day for the whole site.
	// Visitor address (Cloudflare passes the real one; it is only used to count messages).
	$ip = isset( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ? $_SERVER['HTTP_CF_CONNECTING_IP'] : ( isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	$ip = sanitize_text_field( wp_unslash( $ip ) );
	$ip_key   = 'cf_form_ip_' . md5( $ip );
	$day_key  = 'cf_form_day_' . gmdate( 'Ymd' );
	$ip_count = (int) get_transient( $ip_key );
	$day      = (int) get_transient( $day_key );
	if ( $ip_count >= 5 || $day >= 100 ) {
		cf_forms_reply( false, __( 'Too many messages were sent. Please try again later.', 'coastal-friends' ) );
	}
	// 4) Link spam: more than 3 links in a message.
	if ( preg_match_all( '#(https?://|www\.)#i', implode( ' ', array_map( 'strval', array_filter( $raw, 'is_scalar' ) ) ) ) > 3 ) {
		cf_forms_reply( true, $ok );
	}
	// ---------------------------------------------------------------------------

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

	set_transient( $ip_key, $ip_count + 1, 10 * MINUTE_IN_SECONDS );
	set_transient( $day_key, $day + 1, DAY_IN_SECONDS );
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
