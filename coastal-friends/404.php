<?php
/**
 * 404 template.
 *
 * @package Coastal_Friends
 */

get_header();
?>
<main id="content" class="site-main">
	<div class="page-header">
		<h1 class="entry-title"><?php esc_html_e( 'The page can&rsquo;t be found.', 'coastal-friends' ); ?></h1>
	</div>
	<div class="page-content">
		<p><?php esc_html_e( 'It looks like nothing was found at this location.', 'coastal-friends' ); ?></p>
		<p><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php esc_html_e( 'Back to home', 'coastal-friends' ); ?></a></p>
	</div>
</main>
<?php
get_footer();
