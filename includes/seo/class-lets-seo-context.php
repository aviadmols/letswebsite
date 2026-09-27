<?php
/**
 * Let's SEO — what the current request is, and the SEO values that follow from it.
 *
 * Head tags, schema and the share image all read from here, so a title or a
 * canonical is decided in one place and every output agrees with it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Context {

	/**
	 * @return array<string,mixed>
	 */
	public static function current() {
		static $context = null;

		if ( null === $context ) {
			$context = self::build();
		}

		return $context;
	}

	/**
	 * @return array<string,mixed>
	 */
	protected static function build() {
		$ctx = array(
			'kind'        => 'other',
			'post'        => null,
			'term'        => null,
			'title'       => '',
			'name'        => '',
			'description' => '',
			'canonical'   => '',
			'noindex'     => false,
			'nofollow'    => false,
			'og_type'     => 'website',
			'og_title'    => '',
			'og_desc'     => '',
			'image'       => null,
			'page_type'   => 'WebPage',
		);

		$paged = max( 1, (int) get_query_var( 'paged' ), (int) get_query_var( 'page' ) );
		$page  = $paged > 1 ? sprintf( 'עמוד %d', $paged ) : '';

		$front_id = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;
		$blog_id  = (int) get_option( 'page_for_posts' );

		if ( is_front_page() && ! $front_id ) {
			// Latest-posts home page.
			$ctx['kind']        = 'home';
			$ctx['name']        = Lets_SEO::site_name();
			$ctx['title']       = self::home_title( '', $page );
			$ctx['description'] = self::home_description( '' );
			$ctx['canonical']   = self::paged_url( home_url( '/' ), $paged );
		} elseif ( is_singular() || ( is_home() && $blog_id ) ) {
			$post = is_home() ? get_post( $blog_id ) : get_queried_object();

			if ( $post instanceof WP_Post ) {
				$ctx = self::for_post( $ctx, $post, $page, $paged, $front_id === $post->ID );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();

			if ( $term instanceof WP_Term ) {
				$ctx = self::for_term( $ctx, $term, $page, $paged );
			}
		} elseif ( is_post_type_archive() ) {
			$type = get_queried_object();
			$name = $type && isset( $type->labels->name ) ? $type->labels->name : post_type_archive_title( '', false );

			$ctx['kind']        = 'archive';
			$ctx['page_type']   = 'CollectionPage';
			$ctx['name']        = $name;
			$ctx['title']       = Lets_SEO::replace_vars( Lets_SEO::get( 'title_archive' ), array( 'title' => $name, 'page' => $page ) );
			$ctx['description'] = $type && ! empty( $type->description ) ? Lets_SEO::cut( $type->description ) : '';
			$ctx['canonical']   = self::paged_url( get_post_type_archive_link( get_query_var( 'post_type' ) ), $paged );

			// A WooCommerce shop page carries its own SEO fields.
			if ( function_exists( 'is_shop' ) && is_shop() && function_exists( 'wc_get_page_id' ) ) {
				$shop = get_post( wc_get_page_id( 'shop' ) );
				if ( $shop instanceof WP_Post ) {
					$ctx = self::for_post( $ctx, $shop, $page, $paged, false );
					$ctx['kind']      = 'archive';
					$ctx['page_type'] = 'CollectionPage';
					$ctx['og_type']   = 'website';
				}
			}
		} elseif ( is_author() ) {
			$user = get_queried_object();

			$ctx['kind']        = 'author';
			$ctx['page_type']   = 'ProfilePage';
			$ctx['name']        = $user ? $user->display_name : '';
			$ctx['title']       = Lets_SEO::replace_vars( Lets_SEO::get( 'title_archive' ), array( 'title' => $ctx['name'], 'page' => $page ) );
			$ctx['description'] = $user ? Lets_SEO::cut( get_the_author_meta( 'description', $user->ID ) ) : '';
			$ctx['canonical']   = $user ? self::paged_url( get_author_posts_url( $user->ID ), $paged ) : '';
			$ctx['noindex']     = (bool) Lets_SEO::get( 'noindex_author' );
			$ctx['og_type']     = 'profile';
		} elseif ( is_date() ) {
			$ctx['kind']      = 'date';
			$ctx['page_type'] = 'CollectionPage';
			$ctx['name']      = wp_strip_all_tags( get_the_archive_title() );
			$ctx['title']     = Lets_SEO::replace_vars( Lets_SEO::get( 'title_archive' ), array( 'title' => $ctx['name'], 'page' => $page ) );
			$ctx['noindex']   = (bool) Lets_SEO::get( 'noindex_date' );
		} elseif ( is_search() ) {
			$ctx['kind']      = 'search';
			$ctx['page_type'] = 'SearchResultsPage';
			$ctx['name']      = sprintf( 'תוצאות חיפוש: %s', get_search_query( false ) );
			$ctx['title']     = Lets_SEO::replace_vars( '{title} {sep} {site}', array( 'title' => $ctx['name'], 'page' => $page ) );
			$ctx['noindex']   = true;
		} elseif ( is_404() ) {
			$ctx['kind']    = '404';
			$ctx['name']    = 'העמוד לא נמצא';
			$ctx['title']   = Lets_SEO::replace_vars( '{title} {sep} {site}', array( 'title' => $ctx['name'] ) );
			$ctx['noindex'] = true;
		}

		if ( '' === $ctx['title'] ) {
			$ctx['title'] = Lets_SEO::site_name();
		}
		if ( '' === $ctx['og_title'] ) {
			$ctx['og_title'] = '' !== $ctx['name'] && 'home' !== $ctx['kind'] ? $ctx['name'] : $ctx['title'];
		}
		if ( '' === $ctx['og_desc'] ) {
			$ctx['og_desc'] = $ctx['description'];
		}
		if ( null === $ctx['image'] ) {
			$ctx['image'] = self::fallback_image( $ctx );
		}

		/**
		 * Last chance to adjust any SEO value for the current request.
		 *
		 * @param array $ctx Context.
		 */
		return apply_filters( 'lets_seo_context', $ctx );
	}

	/**
	 * @param array<string,mixed> $ctx      Context so far.
	 * @param WP_Post             $post     Post.
	 * @param string              $page     "עמוד N" or ''.
	 * @param int                 $paged    Page number.
	 * @param bool                $is_front Whether this is the static front page.
	 * @return array<string,mixed>
	 */
	protected static function for_post( array $ctx, $post, $page, $paged, $is_front ) {
		$id    = $post->ID;
		$title = Lets_SEO::decode( get_the_title( $post ) );

		$ctx['kind']    = $is_front ? 'front' : 'singular';
		$ctx['post']    = $post;
		$ctx['name']    = $title;
		$ctx['og_type'] = 'post' === $post->post_type ? 'article' : ( 'product' === $post->post_type ? 'product' : 'website' );

		$custom_title = trim( (string) Lets_SEO::post_meta( $id, 'title' ) );
		$vars         = array(
			'title'    => $title,
			'page'     => $page,
			'category' => self::primary_term_name( $post ),
		);

		if ( '' !== $custom_title ) {
			$ctx['title'] = Lets_SEO::replace_vars( $custom_title, $vars );
		} elseif ( $is_front ) {
			$ctx['title'] = self::home_title( $title, $page );
		} else {
			$ctx['title'] = Lets_SEO::replace_vars( Lets_SEO::get( 'title_single' ), $vars );
		}

		$ctx['description'] = self::post_description( $post, $is_front );

		$canonical        = trim( (string) Lets_SEO::post_meta( $id, 'canonical' ) );
		$ctx['canonical'] = '' !== $canonical ? $canonical : self::paged_url( get_permalink( $post ), $paged, true );

		$ctx['noindex']  = Lets_SEO::post_is_hidden( $post );
		$ctx['nofollow'] = (bool) Lets_SEO::post_meta( $id, 'nofollow' );

		$og_title        = trim( (string) Lets_SEO::post_meta( $id, 'og_title' ) );
		$og_desc         = trim( (string) Lets_SEO::post_meta( $id, 'og_description' ) );
		$ctx['og_title'] = '' !== $og_title ? $og_title : ( $is_front ? $ctx['title'] : $title );
		$ctx['og_desc']  = '' !== $og_desc ? $og_desc : $ctx['description'];
		$ctx['image']    = self::post_image( $post, $ctx['og_title'] );

		$ctx['page_type'] = self::page_type( $post, $is_front );

		return $ctx;
	}

	/**
	 * @param array<string,mixed> $ctx   Context so far.
	 * @param WP_Term             $term  Term.
	 * @param string              $page  "עמוד N" or ''.
	 * @param int                 $paged Page number.
	 * @return array<string,mixed>
	 */
	protected static function for_term( array $ctx, $term, $page, $paged ) {
		$name         = Lets_SEO::decode( $term->name );
		$custom_title = trim( (string) Lets_SEO::term_meta( $term->term_id, 'title' ) );
		$custom_desc  = trim( (string) Lets_SEO::term_meta( $term->term_id, 'description' ) );
		$vars         = array(
			'title' => $name,
			'page'  => $page,
		);

		$ctx['kind']        = 'term';
		$ctx['term']        = $term;
		$ctx['page_type']   = 'CollectionPage';
		$ctx['name']        = $name;
		$ctx['title']       = Lets_SEO::replace_vars( '' !== $custom_title ? $custom_title : Lets_SEO::get( 'title_archive' ), $vars );
		$ctx['description'] = '' !== $custom_desc ? $custom_desc : Lets_SEO::cut( Lets_SEO::plain_text( $term->description ) );
		$ctx['canonical']   = self::paged_url( get_term_link( $term ), $paged );
		$ctx['noindex']     = (bool) Lets_SEO::term_meta( $term->term_id, 'noindex' )
			|| ( 'post_tag' === $term->taxonomy && Lets_SEO::get( 'noindex_tag' ) );

		$share = Lets_SEO_Share_Image::for_term( $term );
		if ( $share ) {
			$ctx['image'] = $share;
		}

		return $ctx;
	}

	/**
	 * @param string $name Page title when the front page is a static page.
	 * @param string $page "עמוד N" or ''.
	 * @return string
	 */
	protected static function home_title( $name, $page ) {
		$template = trim( (string) Lets_SEO::get( 'home_title' ) );

		if ( '' === $template ) {
			$template = '' !== Lets_SEO::decode( get_bloginfo( 'description' ) ) ? '{site} {sep} {tagline}' : '{site}';
		}

		return Lets_SEO::replace_vars( $template, array( 'title' => $name, 'page' => $page ) );
	}

	/**
	 * @param string $fallback Used when no home description is set.
	 * @return string
	 */
	protected static function home_description( $fallback ) {
		$desc = trim( (string) Lets_SEO::get( 'home_description' ) );

		if ( '' === $desc ) {
			$desc = '' !== $fallback ? $fallback : Lets_SEO::decode( get_bloginfo( 'description' ) );
		}

		return $desc;
	}

	/**
	 * Description: the field, then the excerpt, then the AI summary, then the start of the body.
	 *
	 * @param WP_Post $post     Post.
	 * @param bool    $is_front Static front page.
	 * @return string
	 */
	public static function post_description( $post, $is_front = false ) {
		$desc = trim( (string) Lets_SEO::post_meta( $post->ID, 'description' ) );

		if ( '' === $desc && $is_front ) {
			$desc = trim( (string) Lets_SEO::get( 'home_description' ) );
		}
		if ( '' === $desc && has_excerpt( $post ) ) {
			$desc = Lets_SEO::cut( Lets_SEO::plain_text( $post->post_excerpt ) );
		}
		if ( '' === $desc ) {
			$desc = Lets_SEO::cut( trim( (string) Lets_SEO::post_meta( $post->ID, 'ai_summary' ) ) );
		}
		if ( '' === $desc && 'publish' === $post->post_status ) {
			// Body paragraphs read better than a text that starts with the page's own heading.
			$html = Lets_SEO::rendered_content( $post );
			$text = preg_match_all( '#<p[\s>].*?</p>#is', $html, $m ) ? Lets_SEO::plain_text( implode( ' ', $m[0] ) ) : '';
			$desc = Lets_SEO::cut( '' !== $text ? $text : Lets_SEO::plain_text( $html ) );
		}

		return $desc;
	}

	/**
	 * Share image: the one picked by hand, then the generated one, then the
	 * featured image, then the site default.
	 *
	 * @param WP_Post $post     Post.
	 * @param string  $og_title Title to print on a generated image.
	 * @return array<string,mixed>|null
	 */
	protected static function post_image( $post, $og_title ) {
		$picked = Lets_SEO::image( (int) Lets_SEO::post_meta( $post->ID, 'og_image' ) );
		if ( $picked ) {
			return $picked;
		}

		$generated = Lets_SEO_Share_Image::for_post( $post, $og_title );
		if ( $generated ) {
			return $generated;
		}

		$featured = Lets_SEO::image( (int) get_post_thumbnail_id( $post ) );
		if ( $featured ) {
			if ( '' === $featured['alt'] ) {
				$featured['alt'] = $og_title;
			}
			return $featured;
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $ctx Context.
	 * @return array<string,mixed>|null
	 */
	protected static function fallback_image( array $ctx ) {
		$image = Lets_SEO::image( (int) Lets_SEO::get( 'default_image' ) );

		if ( ! $image && in_array( $ctx['kind'], array( 'home', 'archive', 'author', 'date' ), true ) ) {
			$image = Lets_SEO_Share_Image::for_site( $ctx['name'] );
		}
		if ( ! $image ) {
			$image = Lets_SEO::image( (int) Lets_SEO::get( 'org_logo' ) );
		}
		if ( $image && '' === $image['alt'] ) {
			$image['alt'] = Lets_SEO::site_name();
		}

		return $image;
	}

	/**
	 * @param WP_Post $post     Post.
	 * @param bool    $is_front Static front page.
	 * @return string|string[] schema.org WebPage type.
	 */
	protected static function page_type( $post, $is_front ) {
		$type = (string) Lets_SEO::post_meta( $post->ID, 'schema_type' );
		$page = in_array( $type, array( 'AboutPage', 'ContactPage', 'CollectionPage', 'ItemPage', 'CheckoutPage' ), true ) ? $type : 'WebPage';

		if ( $is_front ) {
			$page = 'WebPage';
		}

		$faq = Lets_SEO::post_meta( $post->ID, 'faq' );
		if ( is_array( $faq ) && ! empty( $faq ) ) {
			return array( $page, 'FAQPage' );
		}

		return $page;
	}

	/**
	 * The category shown in breadcrumbs and {category}: the one marked primary, else the first.
	 *
	 * @param WP_Post $post Post.
	 * @return WP_Term|null
	 */
	public static function primary_term( $post ) {
		$taxonomy = 'product' === $post->post_type ? 'product_cat' : 'category';

		if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return null;
		}

		$primary = (int) Lets_SEO::post_meta( $post->ID, 'primary_term' );
		if ( $primary ) {
			$term = get_term( $primary, $taxonomy );
			if ( $term instanceof WP_Term && has_term( $term->term_id, $taxonomy, $post ) ) {
				return $term;
			}
		}

		$terms = get_the_terms( $post, $taxonomy );

		return is_array( $terms ) && ! empty( $terms ) ? $terms[0] : null;
	}

	/**
	 * @param WP_Post $post Post.
	 * @return string
	 */
	protected static function primary_term_name( $post ) {
		$term = self::primary_term( $post );
		return $term ? Lets_SEO::decode( $term->name ) : '';
	}

	/**
	 * @param string|WP_Error $url      Base URL.
	 * @param int             $paged    Page number.
	 * @param bool            $singular Whether this is a post split with <!--nextpage-->.
	 * @return string
	 */
	protected static function paged_url( $url, $paged, $singular = false ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}

		if ( $paged < 2 ) {
			return $url;
		}

		global $wp_rewrite;

		if ( ! $wp_rewrite->using_permalinks() ) {
			return add_query_arg( $singular ? 'page' : 'paged', $paged, $url );
		}

		$base = $singular ? '' : $wp_rewrite->pagination_base . '/';

		return user_trailingslashit( trailingslashit( $url ) . $base . $paged );
	}
}
