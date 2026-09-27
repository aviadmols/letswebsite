<?php
/**
 * Let's SEO — breadcrumbs.
 *
 * One trail feeds both the BreadcrumbList schema and the visible
 * [lets_breadcrumbs] shortcode (drop it into an Elementor Shortcode widget),
 * so what visitors see and what search engines read never disagree.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Breadcrumbs {

	public static function init() {
		add_shortcode( 'lets_breadcrumbs', array( __CLASS__, 'shortcode' ) );
	}

	/**
	 * @return array<int,array{name:string,url:string}>
	 */
	public static function trail() {
		static $trail = null;

		if ( null !== $trail ) {
			return $trail;
		}

		$trail = array(
			array(
				'name' => 'ראשי',
				'url'  => home_url( '/' ),
			),
		);

		if ( is_front_page() ) {
			return $trail;
		}

		$ctx = Lets_SEO_Context::current();

		if ( $ctx['post'] instanceof WP_Post ) {
			$post = $ctx['post'];

			if ( 'post' === $post->post_type ) {
				$blog = (int) get_option( 'page_for_posts' );
				if ( $blog && $blog !== $post->ID ) {
					$trail[] = self::crumb_for_post( get_post( $blog ) );
				}
			} elseif ( 'product' === $post->post_type && function_exists( 'wc_get_page_id' ) ) {
				$shop = get_post( wc_get_page_id( 'shop' ) );
				if ( $shop instanceof WP_Post && $shop->ID !== $post->ID ) {
					$trail[] = self::crumb_for_post( $shop );
				}
			} elseif ( 'page' !== $post->post_type ) {
				$type = get_post_type_object( $post->post_type );
				$link = get_post_type_archive_link( $post->post_type );
				if ( $type && $link ) {
					$trail[] = array(
						'name' => $type->labels->name,
						'url'  => $link,
					);
				}
			}

			if ( is_post_type_hierarchical( $post->post_type ) ) {
				foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor ) {
					$trail[] = self::crumb_for_post( get_post( $ancestor ) );
				}
			} else {
				$term = Lets_SEO_Context::primary_term( $post );
				if ( $term ) {
					$trail = array_merge( $trail, self::term_crumbs( $term ) );
				}
			}

			$trail[] = self::crumb_for_post( $post );
		} elseif ( $ctx['term'] instanceof WP_Term ) {
			$trail = array_merge( $trail, self::term_crumbs( $ctx['term'] ) );
		} elseif ( '' !== $ctx['name'] ) {
			$trail[] = array(
				'name' => $ctx['name'],
				'url'  => $ctx['canonical'],
			);
		}

		// The same page twice in a row happens when the blog page is also an ancestor.
		$clean = array();
		foreach ( $trail as $crumb ) {
			$last = end( $clean );
			if ( ! $last || $last['url'] !== $crumb['url'] ) {
				$clean[] = $crumb;
			}
		}

		$trail = $clean;

		return $trail;
	}

	/**
	 * @param WP_Post|null $post Post.
	 * @return array{name:string,url:string}
	 */
	protected static function crumb_for_post( $post ) {
		return array(
			'name' => $post ? Lets_SEO::decode( get_the_title( $post ) ) : '',
			'url'  => $post ? get_permalink( $post ) : '',
		);
	}

	/**
	 * A term with all of its parents, outermost first.
	 *
	 * @param WP_Term $term Term.
	 * @return array<int,array{name:string,url:string}>
	 */
	protected static function term_crumbs( $term ) {
		$crumbs = array();
		$ids    = array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) );
		$ids[]  = $term->term_id;

		foreach ( $ids as $id ) {
			$t    = get_term( $id, $term->taxonomy );
			$link = $t instanceof WP_Term ? get_term_link( $t ) : '';
			if ( $t instanceof WP_Term && is_string( $link ) ) {
				$crumbs[] = array(
					'name' => Lets_SEO::decode( $t->name ),
					'url'  => $link,
				);
			}
		}

		return $crumbs;
	}

	/**
	 * [lets_breadcrumbs separator="/"]
	 *
	 * @param array<string,string>|string $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts  = shortcode_atts( array( 'separator' => '/' ), $atts, 'lets_breadcrumbs' );
		$trail = self::trail();

		if ( count( $trail ) < 2 ) {
			return '';
		}

		$items = array();
		$last  = count( $trail ) - 1;

		foreach ( $trail as $i => $crumb ) {
			$items[] = $i === $last
				? '<span aria-current="page">' . esc_html( $crumb['name'] ) . '</span>'
				: '<a href="' . esc_url( $crumb['url'] ) . '">' . esc_html( $crumb['name'] ) . '</a>';
		}

		$sep = '<span class="lets-breadcrumbs__sep" aria-hidden="true">' . esc_html( $atts['separator'] ) . '</span>';

		return '<nav class="lets-breadcrumbs" aria-label="פירורי לחם">' . implode( ' ' . $sep . ' ', $items ) . '</nav>';
	}
}
