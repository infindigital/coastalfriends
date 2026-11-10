<?php
/**
 * Renders an original page from snapshots/ for its URL (see cf_template_include).
 *
 * @package Coastal_Friends
 */

$cf_set = cf_current_set();
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php
wp_body_open();
require get_template_directory() . '/snapshots/' . basename( $cf_set['file'] ) . '.php';
wp_footer();
?>
</body>
</html>
