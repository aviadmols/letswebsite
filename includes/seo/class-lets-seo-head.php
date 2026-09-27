<?php
/**
 * Let's SEO — <title>, meta tags, canonical, robots, Open Graph and Twitter cards.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lets_SEO_Head {

	public static function init() {
		add_filter( 'pre_get_document_title', array( __CLASS__, 'document_title' ), 99 );
		add_filter( 'wp_robots', array( __CLASS__, 'robots' ), 99 );
		add_action( 'wp_head', array( __CLASS__, 'output' ), 1 );
		add_action( 'template_redirect', array( __CLASS__, 'redirect_attachment' ), 1 );

		// Our canonical replaces core's; the rest is noise that only leaks versions.
		remove_action( 'wp_head', 'rel_canonical' );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head' );
		add_filter( 'the_generator', '__return_empty_string' );

		// Hello Elementor prints its own description tag, which would be a second one.
		add_action(
			'after_setup_theme',
			function () {
				remove_action( 'wp_head', 'hello_elementor_add_description_meta_tag' );
			},
			20
		);
	}

	/**
	 * @return string
	 */
	public static function document_title() {
		return Lets_SEO_Context::current()['title'];
	}

	/**
	 * @param array<string,bool|string> $robots Robots directives from core.
	 * @return array<string,bool|string>
	 */
	public static function robots( $robots ) {
		$ctx = Lets_SEO_Context::current();

		if ( $ctx['noindex'] ) {
			unset( $robots['index'], $robots['max-snippet'], $robots['max-image-preview'], $robots['max-video-preview'] );
			$robots['noindex'] = true;
		} else {
			$robots['index']             = true;
			$robots['max-snippet']       = '-1';
			$robots['max-image-preview'] = 'large';
			$robots['max-video-preview'] = '-1';
		}

		if ( $ctx['nofollow'] ) {
			unset( $robots['follow'] );
			$robots['nofollow'] = true;
		} elseif ( empty( $robots['nofollow'] ) ) {
			$robots['follow'] = true;
		}

		return $robots;
	}

	public static function output() {
		$ctx  = Lets_SEO_Context::current();
		$tags = array();

		if ( '' !== $ctx['description'] ) {
			$tags[] = self::meta( 'name', 'description', $ctx['description'] );
		}
		if ( '' !== $ctx['canonical'] && ! $ctx['noindex'] ) {
			$tags[] = '<link rel="canonical" href="' . esc_url( $ctx['canonical'] ) . '" />';
		}

		foreach ( self::verification() as $name => $value ) {
			$tags[] = self::meta( 'name', $name, $value );
		}

		$tags = array_merge( $tags, self::open_graph( $ctx ), self::twitter( $ctx ) );

		echo "\n<!-- Let's SEO -->\n" . implode( "\n", $tags ) . "\n<!-- / Let's SEO -->\n"; // phpcs:ignore WordPress.Security.EscapeOutput -- each tag escaped in meta().
	}

	/**
	 * @param array<string,mixed> $ctx Context.
	 * @return string[]
	 */
	protected static function open_graph( array $ctx ) {
		$url  = '' !== $ctx['canonical'] ? $ctx['canonical'] : home_url( add_query_arg( array() ) );
		$tags = array(
			self::meta( 'property', 'og:locale', get_locale() ),
			self::meta( 'property', 'og:type', $ctx['og_type'] ),
			self::meta( 'property', 'og:title', $ctx['og_title'] ),
		);

		if ( '' !== $ctx['og_desc'] ) {
			$tags[] = self::meta( 'property', 'og:description', $ctx['og_desc'] );
		}

		$tags[] = self::meta( 'property', 'og:url', $url );
		$tags[] = self::meta( 'property', 'og:site_name', Lets_SEO::site_name() );

		if ( $ctx['image'] ) {
			$image  = $ctx['image'];
			$tags[] = self::meta( 'property', 'og:image', $image['url'] );
			if ( 0 === strpos( $image['url'], 'https://' ) ) {
				$tags[] = self::meta( 'property', 'og:image:secure_url', $image['url'] );
			}
			if ( $image['width'] && $image['height'] ) {
				$tags[] = self::meta( 'property', 'og:image:width', (string) $image['width'] );
				$tags[] = self::meta( 'property', 'og:image:height', (string) $image['height'] );
			}
			if ( '' !== $image['type'] ) {
				$tags[] = self::meta( 'property', 'og:image:type', $image['type'] );
			}
			$tags[] = self::meta( 'property', 'og:image:alt', '' !== $image['alt'] ? $image['alt'] : $ctx['og_title'] );
		}

		$post = $ctx['post'];

		if ( 'article' === $ctx['og_type'] && $post instanceof WP_Post ) {
			$tags[] = self::meta( 'property', 'article:published_time', get_post_time( 'c', true, $post ) );
			$tags[] = self::meta( 'property', 'article:modified_time', get_post_modified_time( 'c', true, $post ) );
			$tags[] = self::meta( 'property', 'og:updated_time', get_post_modified_time( 'c', true, $post ) );

			$section = Lets_SEO_Context::primary_term( $post );
			if ( $section ) {
				$tags[] = self::meta( 'property', 'article:section', Lets_SEO::decode( $section->name ) );
			}

			$post_tags = get_the_tags( $post->ID );
			if ( is_array( $post_tags ) ) {
				foreach ( array_slice( $post_tags, 0, 6 ) as $tag ) {
					$tags[] = self::meta( 'property', 'article:tag', Lets_SEO::decode( $tag->name ) );
				}
			}
		}

		if ( 'product' === $ctx['og_type'] && $post instanceof WP_Post && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( $post->ID );
			if ( $product && '' !== $product->get_price() ) {
				$tags[] = self::meta( 'property', 'product:price:amount', wc_format_decimal( $product->get_price(), wc_get_price_decimals() ) );
				$tags[] = self::meta( 'property', 'product:price:currency', get_woocommerce_currency() );
				$tags[] = self::meta( 'property', 'product:availability', $product->is_in_stock() ? 'in stock' : 'out of stock' );
			}
		}

		return $tags;
	}

	/**
	 * @param array<string,mixed> $ctx Context.
	 * @return string[]
	 */
	protected static function twitter( array $ctx ) {
		$tags = array(
			self::meta( 'name', 'twitter:card', $ctx['image'] ? 'summary_large_image' : 'summary' ),
			self::meta( 'name', 'twitter:title', $ctx['og_title'] ),
		);

		if ( '' !== $ctx['og_desc'] ) {
			$tags[] = self::meta( 'name', 'twitter:description', $ctx['og_desc'] );
		}
		if ( $ctx['image'] ) {
			$tags[] = self::meta( 'name', 'twitter:image', $ctx['image']['url'] );
			$tags[] = self::meta( 'name', 'twitter:image:alt', '' !== $ctx['image']['alt'] ? $ctx['image']['alt'] : $ctx['og_title'] );
		}

		$handle = ltrim( trim( (string) Lets_SEO::get( 'twitter_site' ) ), '@' );
		if ( '' !== $handle ) {
			$tags[] = self::meta( 'name', 'twitter:site', '@' . $handle );
		}

		// Slack, Discord and X show these two pairs under the link preview.
		$post = $ctx['post'];
		if ( 'article' === $ctx['og_type'] && $post instanceof WP_Post ) {
			$author  = get_the_author_meta( 'display_name', $post->post_author );
			$minutes = Lets_SEO::reading_minutes( Lets_SEO::word_count( Lets_SEO::plain_text( Lets_SEO::rendered_content( $post ) ) ) );

			if ( '' !== $author ) {
				$tags[] = self::meta( 'name', 'twitter:label1', 'נכתב על ידי' );
				$tags[] = self::meta( 'name', 'twitter:data1', $author );
			}
			$tags[] = self::meta( 'name', 'twitter:label2', 'זמן קריאה' );
			$tags[] = self::meta( 'name', 'twitter:data2', 1 === $minutes ? 'דקה אחת' : sprintf( '%d דקות', $minutes ) );
		}

		return $tags;
	}

	/**
	 * @return array<string,string>
	 */
	protected static function verification() {
		$codes = array(
			'google-site-verification' => Lets_SEO::get( 'verify_google' ),
			'msvalidate.01'            => Lets_SEO::get( 'verify_bing' ),
			'facebook-domain-verification' => Lets_SEO::get( 'verify_facebook' ),
		);

		return array_filter( array_map( 'trim', array_map( 'strval', $codes ) ) );
	}

	/**
	 * Attachment pages are thin duplicate content: send them to the post they
	 * belong to, or to the file itself.
	 */
	public static function redirect_attachment() {
		if ( ! is_attachment() || ! Lets_SEO::get( 'redirect_attachments' ) ) {
			return;
		}

		$attachment = get_queried_object();
		$parent     = $attachment instanceof WP_Post && $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		$target     = $parent && 'publish' === $parent->post_status ? get_permalink( $parent ) : wp_get_attachment_url( $attachment->ID );

		if ( $target ) {
			wp_safe_redirect( $target, 301 );
			exit;
		}
	}

	/**
	 * @param string $attr  'name' or 'property'.
	 * @param string $key   Tag name.
	 * @param string $value Content.
	 * @return string
	 */
	protected static function meta( $attr, $key, $value ) {
		return sprintf( '<meta %s="%s" content="%s" />', $attr, esc_attr( $key ), esc_attr( $value ) );
	}
}
