<?php
/**
 * Template for single posts and any page without a static design (e.g. news posts).
 *
 * @package Coastal_Friends
 */

get_header();

while ( have_posts() ) :
	the_post();
	?>
<main id="content" <?php post_class( 'site-main' ); ?>>
	<div class="page-header">
		<?php the_title( '<h1 class="entry-title">', '</h1>' ); ?>
	</div>
	<div class="page-content">
		<?php the_content(); ?>
		<?php wp_link_pages(); ?>
	</div>
	<?php
	if ( comments_open() || get_comments_number() ) {
		comments_template();
	}
	?>
</main>
	<?php
endwhile;

get_footer();
