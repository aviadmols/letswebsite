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

define( 'HELLO_ELEMENTOR_CHILD_VERSION', '2.0.1' );

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

// The LETS menu in wp-admin: overview + site settings; other modules add their pages under it.
require_once get_stylesheet_directory() . '/includes/admin/class-lets-admin.php';
Lets_Admin::init();

// AI Bridge: tokens for Claude / Cursor to read and edit the database (LETS → AI Bridge).
require_once get_stylesheet_directory() . '/includes/ai-bridge/bootstrap.php';

// SEO: meta tags, schema, sitemaps, AI layer (llms.txt, Markdown) and share images. LETS → SEO.
require_once get_stylesheet_directory() . '/includes/seo/bootstrap.php';

// Landing pages: copy from JSON with export/import on the edit screen; also feeds the SEO module.
require_once get_stylesheet_directory() . '/includes/landing/class-lets-landing-copy.php';
require_once get_stylesheet_directory() . '/includes/landing/post-purchase-copy.php';
Lets_Landing_Copy::init();

// Signup / contact popup on every page (opens from any link to #signup) + leads in wp-admin.
require_once get_stylesheet_directory() . '/includes/leads/class-lets-leads.php';
Lets_Leads::init();

/**
 * Landing pages (templates/landing-*.php) share one stylesheet and script.
 */
function hello_elementor_child_landing_assets() {
	if ( ! is_page() || 0 !== strpos( (string) get_page_template_slug(), 'templates/landing-' ) ) {
		return;
	}

	wp_enqueue_style( 'lets-landing', get_stylesheet_directory_uri() . '/assets/landing/landing.css', array( 'lets-fonts' ), HELLO_ELEMENTOR_CHILD_VERSION );
	wp_enqueue_style( 'lets-landing-outfit', 'https://fonts.googleapis.com/css2?family=Outfit:wght@500;600;700&display=swap', array(), null );
	wp_enqueue_script( 'lets-landing', get_stylesheet_directory_uri() . '/assets/landing/landing.js', array(), HELLO_ELEMENTOR_CHILD_VERSION, true );
}
add_action( 'wp_enqueue_scripts', 'hello_elementor_child_landing_assets', 30 );

/**
 * Landing pages: mark <html> as scripted before paint, so reveal-on-scroll
 * hides content only when the script that shows it again is running.
 */
function hello_elementor_child_landing_js_flag() {
	if ( is_page() && 0 === strpos( (string) get_page_template_slug(), 'templates/landing-' ) ) {
		echo "<script>document.documentElement.classList.add('lp-js');</script>\n";
	}
}
add_action( 'wp_head', 'hello_elementor_child_landing_js_flag', 1 );
