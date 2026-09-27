<?php
/**
 * Theme functions and definitions.
 *
 * For additional information on potential customization options,
 * read the developers' documentation:
 *
 * https://developers.elementor.com/docs/hello-elementor-theme/
 *
 * @package HelloElementorChild
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.0' );

/**
 * Load child theme scripts & styles.
 *
 * @return void
 */
function hello_elementor_child_scripts_styles() {

	wp_enqueue_style(
		'hello-elementor-child-style',
		get_stylesheet_directory_uri() . '/style.css',
		[
			'hello-elementor-theme-style',
		],
		HELLO_ELEMENTOR_CHILD_VERSION
	);

}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_scripts_styles', 20 );

// SEO: meta tags, schema, sitemaps, AI layer (llms.txt, Markdown) and share images. Settings → SEO.
require_once get_stylesheet_directory() . '/includes/seo/bootstrap.php';

// Landing page copy (also feeds the SEO module's Markdown/AI version and schema).
require_once get_stylesheet_directory() . '/includes/landing/post-purchase-copy.php';

/**
 * Landing pages (templates/landing-*.php) share one stylesheet and script.
 */
function hello_elementor_child_landing_assets() {
	if ( ! is_page() || 0 !== strpos( (string) get_page_template_slug(), 'templates/landing-' ) ) {
		return;
	}

	wp_enqueue_style( 'lets-landing', get_stylesheet_directory_uri() . '/assets/landing/landing.css', array(), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_style( 'lets-landing-outfit', 'https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap', array(), null );
	wp_enqueue_script( 'lets-landing', get_stylesheet_directory_uri() . '/assets/landing/landing.js', array(), HELLO_ELEMENTOR_CHILD_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_landing_assets', 30 );
