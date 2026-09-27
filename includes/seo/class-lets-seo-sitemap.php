<?php
/**
 * Let's SEO — tunes WordPress' built-in XML sitemaps (/wp-sitemap.xml).
 *
 * Pages marked noindex are left out, every URL carries its lastmod, archives
 * that are noindexed site-wide are dropped, and the sitemap URLs Yoast used
 * redirect here so Search Console keeps working after the switch.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Sitemap {

	public static function init() {
		add_filter( 'wp_sitemaps_enabled', '__return_true' );
		add_filter( 'wp_sitemaps_add_provider', array( __CLASS__, 'providers' ), 10, 2 );
		add_filter( 'wp_sitemaps_post_types', array( __CLASS__, 'post_types' ) );
		add_filter( 'wp_sitemaps_taxonomies', array( __CLASS__, 'taxonomies' ) );
		add_filter( 'wp_sitemaps_posts_query_args', array( __CLASS__, 'posts_query_args' ) );
		add_filter( 'wp_sitemaps_taxonomies_query_args', array( __CLASS__, 'terms_query_args' ) );
		add_filter( 'wp_sitemaps_posts_entry', array( __CLASS__, 'post_entry' ), 10, 2 );
		add_filter( 'wp_sitemaps_posts_show_on_front_entry', array( __CLASS__, 'front_entry' ) );
		add_action( 'parse_request', array( __CLASS__, 'redirect_old_sitemaps' ), 0 );
	}

	/**
	 * @param WP_Sitemaps_Provider|false $provider Provider.
	 * @param string                     $name     Provider name.
	 * @return WP_Sitemaps_Provider|false
	 */
	public static function providers( $provider, $name ) {
		if ( 'users' === $name && Lets_SEO::get( 'noindex_author' ) ) {
			return false;
		}
		return $provider;
	}

	/**
	 * @param array<string,WP_Post_Type> $types Post types.
	 * @return array<string,WP_Post_Type>
	 */
	public static function post_types( $types ) {
		return array_intersect_key( $types, array_flip( Lets_SEO::post_types() ) );
	}

	/**
	 * @param array<string,WP_Taxonomy> $taxonomies Taxonomies.
	 * @return array<string,WP_Taxonomy>
	 */
	public static function taxonomies( $taxonomies ) {
		unset( $taxonomies['post_format'] );

		if ( Lets_SEO::get( 'noindex_tag' ) ) {
			unset( $taxonomies['post_tag'] );
		}

		return $taxonomies;
	}

	/**
	 * @param array<string,mixed> $args WP_Query args.
	 * @return array<string,mixed>
	 */
	public static function posts_query_args( $args ) {
		$args['has_password'] = false;
		$args['meta_query']   = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => Lets_SEO::META_PREFIX . 'noindex',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Lets_SEO::META_PREFIX . 'noindex',
				'value'   => '1',
				'compare' => '!=',
			),
		);

		return $args;
	}

	/**
	 * @param array<string,mixed> $args WP_Term_Query args.
	 * @return array<string,mixed>
	 */
	public static function terms_query_args( $args ) {
		$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
			'relation' => 'OR',
			array(
				'key'     => Lets_SEO::META_PREFIX . 'noindex',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'     => Lets_SEO::META_PREFIX . 'noindex',
				'value'   => '1',
				'compare' => '!=',
			),
		);

		return $args;
	}

	/**
	 * @param array<string,string> $entry Sitemap entry.
	 * @param WP_Post              $post  Post.
	 * @return array<string,string>
	 */
	public static function post_entry( $entry, $post ) {
		$canonical = trim( (string) Lets_SEO::post_meta( $post->ID, 'canonical' ) );

		// A page that names another URL as canonical should not be listed as itself.
		if ( '' !== $canonical && untrailingslashit( $canonical ) !== untrailingslashit( get_permalink( $post ) ) ) {
			$entry['loc'] = $canonical;
		}

		$entry['lastmod'] = get_post_modified_time( 'c', true, $post );

		return $entry;
	}

	/**
	 * @param array<string,string> $entry Home page entry.
	 * @return array<string,string>
	 */
	public static function front_entry( $entry ) {
		$latest = get_lastpostmodified( 'GMT' );

		if ( $latest ) {
			$entry['lastmod'] = gmdate( 'c', strtotime( $latest ) );
		}

		return $entry;
	}

	/**
	 * /sitemap_index.xml and /sitemap.xml (Yoast, Rank Math) → /wp-sitemap.xml.
	 *
	 * @param WP $wp Request.
	 */
	public static function redirect_old_sitemaps( $wp ) {
		if ( in_array( $wp->request, array( 'sitemap_index.xml', 'sitemap.xml' ), true ) ) {
			wp_safe_redirect( home_url( '/wp-sitemap.xml' ), 301 );
			exit;
		}
	}
}
