<?php
/**
 * Fallback template: blog index, archives and search results.
 *
 * @package Coastal_Friends
 */

get_header();
?>
<main id="content" class="site-main">
	<?php if ( is_archive() || is_search() || is_home() ) : ?>
		<div class="page-header">
			<h1 class="entry-title">
				<?php
				if ( is_search() ) {
					/* translators: %s: search query. */
					printf( esc_html__( 'Search results for: %s', 'coastal-friends' ), esc_html( get_search_query() ) );
				} elseif ( is_home() ) {
					single_post_title();
				} else {
					the_archive_title();
				}
				?>
			</h1>
		</div>
	<?php endif; ?>

	<div class="page-content">
		<?php if ( have_posts() ) : ?>
			<?php while ( have_posts() ) : the_post(); ?>
				<article id="post-<?php the_ID(); ?>" <?php post_class( 'post' ); ?>>
					<h2 class="entry-title"><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
					<?php if ( has_post_thumbnail() ) : ?>
						<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'large' ); ?></a>
					<?php endif; ?>
					<?php the_excerpt(); ?>
				</article>
			<?php endwhile; ?>
			<?php the_posts_pagination(); ?>
		<?php else : ?>
			<p><?php esc_html_e( 'Nothing found.', 'coastal-friends' ); ?></p>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
