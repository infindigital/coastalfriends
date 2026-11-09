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

define( 'CF_VERSION', '1.0.0' );

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
 * @param string $path Relative asset path.
 * @return string
 */
function cf_assets_url( $path = '' ) {
	return get_template_directory_uri() . '/assets/' . ltrim( $path, '/' );
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
 * Key of the static design for the current request ('' when none applies).
 *
 * @return string
 */
function cf_page_key() {
	$data = cf_asset_data();
	if ( is_front_page() && ! is_home() ) {
		return 'home';
	}
	if ( is_page() ) {
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		if ( isset( $data['pages'][ $slug ] ) && 'home' !== $slug ) {
			return $slug;
		}
	}
	return '';
}

/**
 * Always use the theme's static template for converted pages, even when
 * Elementor is active and the page is set to an Elementor page template.
 *
 * @param string $template Template chosen by WordPress.
 * @return string
 */
function cf_template_include( $template ) {
	$key = cf_page_key();
	if ( '' === $key ) {
		return $template;
	}
	$located = locate_template( 'home' === $key ? 'front-page.php' : 'page-' . $key . '.php' );
	return $located ? $located : $template;
}
add_filter( 'template_include', 'cf_template_include', PHP_INT_MAX );

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
	$data = cf_asset_data();
	$key  = cf_page_key();
	$set  = '' === $key ? $data['base'] : $data['pages'][ $key ];

	$prev = array();
	foreach ( $set['styles'] as $style ) {
		list( $handle, $path, $ver, $media ) = $style;
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
 * Body classes the Elementor CSS relies on (kit, page and template classes).
 *
 * @param string[] $classes Body classes.
 * @return string[]
 */
function cf_body_class( $classes ) {
	$data = cf_asset_data();
	$key  = cf_page_key();
	$set  = '' === $key ? $data['base'] : $data['pages'][ $key ];
	return array_values( array_unique( array_merge( $classes, $set['body_class'] ) ) );
}
add_filter( 'body_class', 'cf_body_class' );

/**
 * Is the given site path the page being viewed?
 *
 * @param string $path Path such as '/' or '/about-us/'.
 * @return bool
 */
function cf_is_current( $path ) {
	$slug = trim( $path, '/' );
	if ( '' === $slug ) {
		return is_front_page();
	}
	return is_page( $slug );
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
