<?php
/**
 * Imports the original Coastal Friends posts as real WordPress posts.
 *
 * Runs when the theme is activated, and again from Tools → Coastal Friends
 * Import. Each of the 36 original posts (inc/import/posts.json) is created
 * with its original content, date, excerpt, categories, tags and featured
 * image, unless a post with the same slug already exists. Nothing existing is
 * changed. After that they are normal posts: edit or delete them like any other.
 *
 * @package Coastal_Friends
 */

defined( 'ABSPATH' ) || exit;

/**
 * Import data.
 *
 * @return array
 */
function cf_import_data() {
	$file = get_template_directory() . '/inc/import/posts.json';
	return file_exists( $file ) ? (array) json_decode( file_get_contents( $file ), true ) : array(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
}

/**
 * Get or create a term with the original slug.
 *
 * @param array  $term     slug, name, description.
 * @param string $taxonomy Taxonomy.
 * @return int Term id (0 on failure).
 */
function cf_import_term( $term, $taxonomy ) {
	$found = get_term_by( 'slug', $term['slug'], $taxonomy );
	if ( $found ) {
		return (int) $found->term_id;
	}
	$made = wp_insert_term( $term['name'], $taxonomy, array( 'slug' => $term['slug'], 'description' => $term['description'] ) );
	if ( is_wp_error( $made ) ) {
		$id = $made->get_error_data( 'term_exists' );
		return $id ? (int) $id : 0;
	}
	return (int) $made['term_id'];
}

/**
 * Create the featured-image attachment from the theme's bundled file.
 *
 * @param array $f       file, alt, width, height.
 * @param int   $post_id Parent post.
 * @return int Attachment id (0 on failure).
 */
function cf_import_image( $f, $post_id ) {
	$rel = ltrim( (string) $f['file'], '/' );
	if ( 0 !== strpos( $rel, 'uploads/' ) ) {
		return 0;
	}
	$src = get_template_directory() . '/assets/' . $rel;
	$up  = wp_get_upload_dir();
	if ( ! empty( $up['error'] ) ) {
		return 0;
	}
	$sub  = substr( $rel, strlen( 'uploads/' ) );
	$dest = $up['basedir'] . '/' . $sub;
	if ( ! file_exists( $dest ) ) {
		if ( ! file_exists( $src ) ) {
			return 0;
		}
		wp_mkdir_p( dirname( $dest ) );
		if ( ! copy( $src, $dest ) ) {
			return 0;
		}
	}
	$existing = attachment_url_to_postid( $up['baseurl'] . '/' . $sub );
	if ( $existing ) {
		return $existing;
	}
	$type = wp_check_filetype( $dest );
	$id   = wp_insert_attachment(
		array(
			'post_mime_type' => $type['type'] ? $type['type'] : 'image/jpeg',
			'post_title'     => sanitize_text_field( preg_replace( '/\.[^.]+$/', '', basename( $sub ) ) ),
			'post_status'    => 'inherit',
			'guid'           => $up['baseurl'] . '/' . $sub,
		),
		$dest,
		$post_id,
		true
	);
	if ( is_wp_error( $id ) || ! $id ) {
		return 0;
	}
	$size = function_exists( 'wp_getimagesize' ) ? wp_getimagesize( $dest ) : @getimagesize( $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	wp_update_attachment_metadata(
		$id,
		array(
			'width'  => $size ? (int) $size[0] : (int) $f['width'],
			'height' => $size ? (int) $size[1] : (int) $f['height'],
			'file'   => $sub,
			'sizes'  => array(),
		)
	);
	if ( '' !== $f['alt'] ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $f['alt'] ) );
	}
	return (int) $id;
}

/**
 * Run the import. Safe to run again: existing slugs are skipped.
 *
 * @return array{created:int,skipped:int,failed:int}
 */
function cf_import_posts() {
	$data = cf_import_data();
	$res  = array( 'created' => 0, 'skipped' => 0, 'failed' => 0 );
	if ( empty( $data['posts'] ) ) {
		return $res;
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$cats = array();
	foreach ( $data['terms']['categories'] as $t ) {
		$cats[ $t['slug'] ] = cf_import_term( $t, 'category' );
	}
	$tags = array();
	foreach ( $data['terms']['tags'] as $t ) {
		$tags[ $t['slug'] ] = cf_import_term( $t, 'post_tag' );
	}
	$fallback_author = get_current_user_id();
	if ( ! $fallback_author ) {
		$admins          = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
		$fallback_author = $admins ? (int) $admins[0] : 1;
	}

	// The original content contains the gallery/lightbox markup exactly as the site rendered it.
	kses_remove_filters();
	foreach ( $data['posts'] as $p ) {
		if ( get_page_by_path( $p['slug'], OBJECT, 'post' ) ) {
			$res['skipped']++;
			continue;
		}
		$author = $p['author'] ? get_user_by( 'slug', $p['author'] ) : false;
		$id     = wp_insert_post(
			array(
				'post_type'         => 'post',
				'post_status'       => 'publish',
				'post_title'        => $p['title'],
				'post_name'         => $p['slug'],
				'post_content'      => $p['content'],
				'post_excerpt'      => $p['excerpt'],
				'post_date'         => str_replace( 'T', ' ', $p['date'] ),
				'post_date_gmt'     => str_replace( 'T', ' ', $p['date_gmt'] ),
				'post_author'       => $author ? $author->ID : $fallback_author,
				'comment_status'    => $p['comment_status'],
				'post_category'     => array_values( array_filter( array_map( static function ( $s ) use ( $cats ) { return isset( $cats[ $s ] ) ? $cats[ $s ] : 0; }, $p['categories'] ) ) ),
			),
			true
		);
		if ( is_wp_error( $id ) || ! $id ) {
			$res['failed']++;
			continue;
		}
		$tag_ids = array_values( array_filter( array_map( static function ( $s ) use ( $tags ) { return isset( $tags[ $s ] ) ? $tags[ $s ] : 0; }, $p['tags'] ) ) );
		if ( $tag_ids ) {
			wp_set_post_terms( $id, $tag_ids, 'post_tag' );
		}
		update_post_meta( $id, '_cf_src_id', (int) $p['src_id'] );
		if ( ! empty( $p['featured'] ) ) {
			$att = cf_import_image( $p['featured'], $id );
			if ( $att ) {
				set_post_thumbnail( $id, $att );
				update_post_meta( $id, '_cf_thumb_id', $att );
				update_post_meta( $id, '_cf_card_img', wp_slash( $p['card_img'] ) );
				update_post_meta( $id, '_cf_single_img', wp_slash( $p['single_img'] ) );
			}
		}
		$res['created']++;
	}
	kses_init_filters();
	update_option( 'cf_import_result', $res + array( 'time' => time() ), false );
	return $res;
}

/**
 * Import automatically when the theme is activated.
 */
function cf_import_on_activate() {
	if ( get_option( 'cf_import_result' ) ) {
		return; // Only the first activation imports; use Tools → Coastal Friends Import to add missing posts later.
	}
	if ( current_user_can( 'edit_posts' ) || ! is_admin() ) {
		cf_import_posts();
		set_transient( 'cf_import_notice', 1, 600 );
	}
}
add_action( 'after_switch_theme', 'cf_import_on_activate' );

/**
 * One-time notice after activation.
 */
function cf_import_notice() {
	if ( ! get_transient( 'cf_import_notice' ) || ! current_user_can( 'edit_posts' ) ) {
		return;
	}
	delete_transient( 'cf_import_notice' );
	$r = get_option( 'cf_import_result' );
	if ( ! $r ) {
		return;
	}
	printf(
		'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: created, 2: skipped. */
				__( 'Coastal Friends: %1$d original posts were added to Posts (%2$d already existed and were left as they are).', 'coastal-friends' ),
				$r['created'],
				$r['skipped']
			)
		)
	);
}
add_action( 'admin_notices', 'cf_import_notice' );

/**
 * Tools → Coastal Friends Import.
 */
function cf_import_menu() {
	add_management_page( __( 'Coastal Friends Import', 'coastal-friends' ), __( 'Coastal Friends Import', 'coastal-friends' ), 'edit_others_posts', 'cf-import', 'cf_import_page' );
}
add_action( 'admin_menu', 'cf_import_menu' );

/**
 * Admin page with a button to run the import again.
 */
function cf_import_page() {
	$ran = null;
	if ( isset( $_POST['cf_import'] ) && check_admin_referer( 'cf_import' ) && current_user_can( 'edit_others_posts' ) ) {
		$ran = cf_import_posts();
	}
	$data  = cf_import_data();
	$total = isset( $data['posts'] ) ? count( $data['posts'] ) : 0;
	$have  = 0;
	foreach ( (array) ( $data['posts'] ?? array() ) as $p ) {
		if ( get_page_by_path( $p['slug'], OBJECT, 'post' ) ) {
			$have++;
		}
	}
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'Coastal Friends Import', 'coastal-friends' ); ?></h1>
		<?php if ( $ran ) : ?>
			<div class="notice notice-success"><p>
				<?php
				/* translators: 1: created, 2: skipped, 3: failed. */
				echo esc_html( sprintf( __( 'Done: %1$d added, %2$d already there, %3$d failed.', 'coastal-friends' ), $ran['created'], $ran['skipped'], $ran['failed'] ) );
				?>
			</p></div>
		<?php endif; ?>
		<p>
			<?php
			/* translators: 1: posts present, 2: total. */
			echo esc_html( sprintf( __( '%1$d of the %2$d original posts are in WordPress.', 'coastal-friends' ), $have, $total ) );
			?>
		</p>
		<p><?php esc_html_e( 'This adds any original post that is missing (for example after you deleted one by mistake). Posts that already exist are never changed.', 'coastal-friends' ); ?></p>
		<form method="post">
			<?php wp_nonce_field( 'cf_import' ); ?>
			<button class="button button-primary" name="cf_import" value="1"><?php esc_html_e( 'Add missing original posts', 'coastal-friends' ); ?></button>
		</form>
	</div>
	<?php
}
