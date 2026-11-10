<?php
/**
 * Coastal Friends theme functions.
 *
 * The page designs come from the live Elementor site and are stored as static
 * templates (front-page.php, page-{slug}.php). Every stylesheet, script, font
 * and image they need is bundled in assets/, and inc/page-assets.json lists
 * which assets each page loads, in the original order.
 *
 * @package Coastal_Friends
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CF_VERSION', '2.1.0' );

require get_template_directory() . '/inc/forms.php';

/**
 * Theme setup.
 */
function cf_setup() {
	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'automatic-feed-links' );
	add_theme_support( 'custom-logo' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'script', 'style' ) );
	register_nav_menus( array( 'primary' => __( 'Primary', 'coastal-friends' ) ) );
}
add_action( 'after_setup_theme', 'cf_setup' );

/**
 * URL of a bundled asset. $path is relative to assets/ (mirrors wp-content/).
 *
 * Media-library images (uploads/YYYY/MM/...) are taken from the site's own
 * uploads folder when the file exists there (e.g. on coastalfriends.org), so
 * the theme can be installed without its bundled copy of the images.
 *
 * @param string $path Relative asset path.
 * @return string
 */
function cf_assets_url( $path = '' ) {
	$path = ltrim( $path, '/' );
	if ( 0 === strpos( $path, 'uploads/' ) && 0 !== strpos( $path, 'uploads/elementor/' ) ) {
		static $uploads = null;
		if ( null === $uploads ) {
			$uploads = wp_get_upload_dir();
		}
		$rel = substr( $path, strlen( 'uploads/' ) );
		if ( empty( $uploads['error'] ) && file_exists( $uploads['basedir'] . '/' . $rel ) ) {
			return $uploads['baseurl'] . '/' . $rel;
		}
	}
	return get_template_directory_uri() . '/assets/' . $path;
}

/**
 * Escape a URL for use inside JSON stored in an HTML attribute (Elementor data-settings).
 *
 * @param string $url URL.
 * @return string
 */
function cf_json_url( $url ) {
	return esc_attr( str_replace( '/', '\/', esc_url_raw( $url ) ) );
}

/**
 * Asset manifest generated from the original pages.
 *
 * @return array
 */
function cf_asset_data() {
	static $data = null;
	if ( null === $data ) {
		$json = file_get_contents( get_template_directory() . '/inc/page-assets.json' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( $json, true );
	}
	return $data;
}

/**
 * Path of the current request without slashes ('' for the home page).
 *
 * @return string
 */
function cf_request_path() {
	global $wp;
	return isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';
}

/**
 * Snapshot key for the current request, or null when WordPress should render
 * normally (new posts/pages, search, previews, feeds, admin ...).
 *
 * @return string|null
 */
function cf_page_key() {
	static $key = false;
	if ( false !== $key && did_action( 'wp' ) ) {
		return $key;
	}
	$data   = cf_asset_data();
	$path   = cf_request_path();
	$result = null;
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$query_override = array_intersect( array_keys( $_GET ), array( 's', 'p', 'page_id', 'preview', 'feed', 'attachment_id', 'cat', 'tag', 'author' ) );
	if ( 0 !== strpos( $path, '__' ) && array_key_exists( $path, $data['pages'] ) && ! is_admin() && ! is_feed() && ! is_preview() && ! is_search() && empty( $query_override ) ) {
		$result = $path;
	} elseif ( is_404() && isset( $data['pages']['__404'] ) ) {
		$result = '__404'; // The original site's "page not found" design.
	}
	if ( did_action( 'wp' ) ) {
		$key = $result;
	}
	return $result;
}

/**
 * Original URLs are served from snapshots, so they must never 404 or be
 * redirected by WordPress (e.g. on a fresh install where the posts don't exist).
 */
function cf_router_status() {
	global $wp_query;
	$key = cf_page_key();
	if ( null === $key || '__404' === $key ) {
		return;
	}
	if ( $wp_query->is_404() ) {
		$wp_query->is_404 = false;
		status_header( 200 );
	}
}
add_action( 'wp', 'cf_router_status', PHP_INT_MAX );

/**
 * Stop canonical/"guess" redirects for snapshot URLs.
 *
 * @param string|false $redirect Redirect target.
 * @return string|false
 */
function cf_no_redirect( $redirect ) {
	return cf_is_snapshot_url() ? false : $redirect;
}

/**
 * Is the request one of the original site's URLs (not the 404 page)?
 *
 * @return bool
 */
function cf_is_snapshot_url() {
	$key = cf_page_key();
	return null !== $key && '__404' !== $key;
}
add_filter( 'redirect_canonical', 'cf_no_redirect', PHP_INT_MAX );
add_filter(
	'do_redirect_guess_404_permalink',
	static function ( $do ) {
		return cf_is_snapshot_url() ? false : $do;
	},
	PHP_INT_MAX
);

/**
 * Serve the snapshot template for original URLs (beats Elementor's templates too).
 *
 * @param string $template Template chosen by WordPress.
 * @return string
 */
function cf_template_include( $template ) {
	if ( null === cf_page_key() ) {
		return $template;
	}
	$located = locate_template( 'snapshot.php' );
	return $located ? $located : $template;
}
add_filter( 'template_include', 'cf_template_include', PHP_INT_MAX );

/**
 * Asset/meta set for the current request.
 *
 * @return array
 */
function cf_current_set() {
	$data = cf_asset_data();
	$key  = cf_page_key();
	return null === $key ? $data['base'] : $data['pages'][ $key ];
}

/**
 * Use the original page title for snapshot URLs.
 *
 * @param string $title Title.
 * @return string
 */
function cf_document_title( $title ) {
	$set = cf_current_set();
	return null === cf_page_key() ? $title : $set['title'];
}
add_filter( 'pre_get_document_title', 'cf_document_title', PHP_INT_MAX );

/**
 * Fill runtime placeholders in the inline Elementor config scripts.
 *
 * @param string $code Inline script from the manifest.
 * @return string
 */
function cf_fill_inline( $code ) {
	$json = static function ( $url ) {
		return str_replace( '/', '\/', esc_url_raw( $url ) );
	};
	return strtr(
		$code,
		array(
			'{{ASSETS}}'       => $json( cf_assets_url() ),
			'{{ADMIN}}'        => $json( admin_url() ),
			'{{REST}}'         => $json( rest_url() ),
			'{{HOME}}'         => $json( home_url( '/' ) ),
			'{{HOME_NOSLASH}}' => $json( untrailingslashit( home_url() ) ),
			'{{ASSETS_RAW}}'   => esc_url_raw( cf_assets_url() ),
			'{{HOME_RAW}}'     => esc_url_raw( home_url( '/' ) ),
			'{{PRO_NONCE}}'    => wp_create_nonce( 'elementor-pro-frontend' ),
		)
	);
}

/**
 * Enqueue the bundled assets for the current page, in the original order.
 *
 * Handles keep the names Elementor uses, so if the Elementor plugins are still
 * active their copies are replaced instead of being loaded twice.
 */
function cf_enqueue_assets() {
	$set = cf_current_set();

	if ( null !== cf_page_key() ) {
		// Core styles the original page did not load (they would change the layout).
		$listed = wp_list_pluck( $set['styles'], 0 );
		foreach ( array( 'wp-block-library', 'wp-block-library-theme', 'classic-theme-styles', 'global-styles' ) as $core ) {
			if ( ! in_array( $core, $listed, true ) ) {
				wp_dequeue_style( $core );
			}
		}
	}

	$prev = array();
	foreach ( $set['styles'] as $style ) {
		list( $handle, $path, $ver, $media ) = $style;
		if ( null === $path ) { // Original global-styles CSS (inc/global-styles/<hash>.css).
			$css = file_get_contents( get_template_directory() . '/inc/global-styles/' . basename( $ver ) . '.css' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			wp_register_style( $handle, false, $prev ); // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion
			wp_add_inline_style( $handle, $css );
			wp_enqueue_style( $handle );
			$prev = array( $handle );
			continue;
		}
		wp_deregister_style( $handle );
		wp_register_style( $handle, cf_assets_url( $path ), $prev, $ver, $media );
		wp_enqueue_style( $handle );
		$prev = array( $handle );
	}
	wp_enqueue_style( 'coastal-friends', get_stylesheet_uri(), $prev, CF_VERSION );

	$deps = array();
	foreach ( $set['scripts'] as $script ) {
		list( $handle, $path, $ver ) = $script;
		if ( null === $path ) { // WordPress core script, e.g. jquery.
			wp_enqueue_script( $handle );
		} else {
			wp_deregister_script( $handle );
			wp_register_script( $handle, cf_assets_url( $path ), $deps, $ver, true );
			wp_enqueue_script( $handle );
		}
		$deps[] = $handle;
	}
	foreach ( $set['inline_before'] as $handle => $code ) {
		wp_add_inline_script( $handle, cf_fill_inline( $code ), 'before' );
	}
}
add_action( 'wp_enqueue_scripts', 'cf_enqueue_assets', 100 );

/**
 * Elementor's background lazy-load helpers (the plugin prints them itself when active).
 */
function cf_lazyload_style() {
	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		get_template_part( 'template-parts/lazyload-style' );
	}
}
add_action( 'wp_head', 'cf_lazyload_style', 20 );

/**
 * Footer half of the lazy-load helper.
 */
function cf_lazyload_script() {
	if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
		get_template_part( 'template-parts/lazyload-script' );
	}
}
add_action( 'wp_footer', 'cf_lazyload_script', 5 );

/**
 * Body classes: the original page's exact classes for snapshot URLs, plus the
 * Elementor kit classes for normal views. Logged-in/admin-bar classes are kept.
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function cf_body_class( $classes ) {
	$set = cf_current_set();
	if ( null === cf_page_key() ) {
		return array_values( array_unique( array_merge( $classes, $set['body_class'] ) ) );
	}
	$keep = array_intersect( $classes, array( 'logged-in', 'admin-bar', 'no-customize-support', 'customize-support' ) );
	return array_values( array_unique( array_merge( $set['body_class'], $keep ) ) );
}
add_filter( 'body_class', 'cf_body_class', PHP_INT_MAX );

/**
 * Extra original tags: module preloads in <head>; import map, script-module
 * data and block-supports CSS after the footer scripts.
 */
function cf_print_head_extra() {
	foreach ( cf_current_set()['head_extra'] as $tag ) {
		echo cf_fill_inline( $tag ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-bundled markup.
	}
}
add_action( 'wp_head', 'cf_print_head_extra', 30 );

/**
 * Footer half of cf_print_head_extra().
 */
function cf_print_footer_extra() {
	foreach ( cf_current_set()['footer_extra'] as $tag ) {
		echo cf_fill_inline( $tag ) . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- theme-bundled markup.
	}
}
add_action( 'wp_footer', 'cf_print_footer_extra', 30 );

/**
 * Is the given site path the page being viewed?
 *
 * @param string $path Path such as '/' or '/about-us/'.
 * @return bool
 */
function cf_is_current( $path ) {
	return trim( $path, '/' ) === cf_request_path();
}

/**
 * Header menu helpers: reproduce WordPress/Elementor "current item" markup.
 *
 * @param string $path Menu item path.
 */
function cf_menu_item_class( $path ) {
	if ( cf_is_current( $path ) ) {
		echo ' current-menu-item current_page_item';
	}
}

/**
 * Print aria-current on the active menu link.
 *
 * @param string $path Menu item path.
 */
function cf_aria_current( $path ) {
	if ( cf_is_current( $path ) ) {
		echo ' aria-current="page"';
	}
}

/**
 * Print Elementor's active class on the active menu link.
 *
 * @param string $path Menu item path.
 */
function cf_menu_link_class( $path ) {
	if ( cf_is_current( $path ) ) {
		echo ' elementor-item-active';
	}
}

/**
 * Customizer: Appearance → Customize → Google Analytics.
 *
 * @param WP_Customize_Manager $wp_customize Customizer instance.
 */
function cf_customize_analytics( $wp_customize ) {
	$wp_customize->add_section(
		'cf_analytics',
		array(
			'title'       => __( 'Google Analytics', 'coastal-friends' ),
			'description' => __( 'Paste your GA4 Measurement ID (looks like G-XXXXXXXXXX). Leave empty to turn tracking off.', 'coastal-friends' ),
			'priority'    => 160,
		)
	);
	$wp_customize->add_setting(
		'cf_ga_measurement_id',
		array(
			'type'              => 'theme_mod',
			'default'           => '',
			'sanitize_callback' => 'cf_sanitize_ga_id',
		)
	);
	$wp_customize->add_control(
		'cf_ga_measurement_id',
		array(
			'label'       => __( 'Measurement ID', 'coastal-friends' ),
			'section'     => 'cf_analytics',
			'type'        => 'text',
			'input_attrs' => array( 'placeholder' => 'G-XXXXXXXXXX' ),
		)
	);
	$wp_customize->add_setting(
		'cf_ga_skip_admins',
		array(
			'type'              => 'theme_mod',
			'default'           => true,
			'sanitize_callback' => 'wp_validate_boolean',
		)
	);
	$wp_customize->add_control(
		'cf_ga_skip_admins',
		array(
			'label'   => __( "Don't track logged-in administrators", 'coastal-friends' ),
			'section' => 'cf_analytics',
			'type'    => 'checkbox',
		)
	);
}
add_action( 'customize_register', 'cf_customize_analytics' );

/**
 * Accept only a GA4 Measurement ID (G-XXXXXXXX); anything else is cleared.
 *
 * @param string $value Raw input.
 * @return string
 */
function cf_sanitize_ga_id( $value ) {
	$value = strtoupper( trim( (string) $value ) );
	return preg_match( '/^G-[A-Z0-9]{4,20}$/', $value ) ? $value : '';
}

/**
 * Print the Google Analytics (gtag.js) snippet in <head> when an ID is set.
 */
function cf_print_analytics() {
	$id = cf_sanitize_ga_id( get_theme_mod( 'cf_ga_measurement_id', '' ) );
	if ( '' === $id || is_customize_preview() ) {
		return;
	}
	if ( get_theme_mod( 'cf_ga_skip_admins', true ) && current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo esc_attr( $id ); ?>"></script>
<script>
	window.dataLayer = window.dataLayer || [];
	function gtag(){dataLayer.push(arguments);}
	gtag('js', new Date());
	gtag('config', '<?php echo esc_js( $id ); ?>');
</script>
	<?php
}
add_action( 'wp_head', 'cf_print_analytics', 1 );
